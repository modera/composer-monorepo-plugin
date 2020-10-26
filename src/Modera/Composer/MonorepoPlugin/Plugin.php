<?php

namespace Modera\Composer\MonorepoPlugin;

use Composer\Factory;
use Composer\Composer;
use Composer\Script\Event;
use Composer\Package\Link;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Script\ScriptEvents;
use Composer\Json\JsonManipulator;
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginInterface;
use Composer\Package\PackageInterface;
use Composer\EventDispatcher\EventSubscriberInterface;

/**
 * @author    Sergei Vizel <sergei.vizel@modera.org>
 * @copyright 2016 Modera Foundation
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Installer
     */
    private $installer;

    /**
     * @var Repository
     */
    protected $repository = null;

    /**
     * @var Repository
     */
    protected $devRepository = null;

    /**
     * @var Link[]
     */
    protected $localConfig = null;

    /**
     * {@inheritDoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        $composer->getInstallationManager()->removeInstaller($this->installer);
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::PRE_UPDATE_CMD => array(
                array('preUpdateCmd', PHP_INT_MAX)
            ),
            ScriptEvents::POST_UPDATE_CMD => array(
                array('postUpdateCmd', PHP_INT_MAX)
            ),
        );
    }

    /**
     * @param Event $event
     */
    public function preUpdateCmd(Event $event)
    {
        $composer = $event->getComposer();
        $package = $composer->getPackage();

        $type = 'modera-monorepo';

        $extra = $package->getExtra();
        if (isset($extra[$type]) && isset($extra[$type]['include'])) {
            $repositoryConfig = array(
                'type' => $type,
                'include' => $extra[$type]['include'],
            );
            $composer->getRepositoryManager()->setRepositoryClass($repositoryConfig['type'], Repository::clazz());
            $repository = $composer->getRepositoryManager()->createRepository($repositoryConfig['type'], $repositoryConfig);
            $composer->getRepositoryManager()->addRepository($repository);

            $devRepositoryConfig = array(
                'type' => $type . '-dev',
                'include' => $extra[$type]['include'],
                'dev' => true,
            );
            $composer->getRepositoryManager()->setRepositoryClass($devRepositoryConfig['type'], Repository::clazz());
            $devRepository = $composer->getRepositoryManager()->createRepository($devRepositoryConfig['type'], $devRepositoryConfig);
            $composer->getRepositoryManager()->addRepository($devRepository);

            $this->localConfig = array(
                'require' => isset($extra[$type]['require']) ? $extra[$type]['require'] : array(),
                'require-dev' => isset($extra[$type]['require-dev']) ? $extra[$type]['require-dev'] : array(),
            );

            $localConfig = $this->localConfig;
            foreach ($repository->getPackages() as $localPackage) {
                /* @var PackageInterface $localPackage */
                if (!$localPackage instanceof AliasPackage) {
                    $localConfig['require'][$localPackage->getName()] = $localPackage->getPrettyVersion();
                }
            }
            foreach ($devRepository->getPackages() as $localPackage) {
                /* @var PackageInterface $localPackage */
                if (!$localPackage instanceof AliasPackage) {
                    $localConfig['require-dev'][$localPackage->getName()] = $localPackage->getPrettyVersion();
                }
            }

            $factory = new Factory();
            $localComposer = $factory->createComposer(
                $event->getIO(),
                $localConfig,
                true,
                null,
                false
            );

            // Merge repositories
            $repositories = array_merge($package->getRepositories(), array($repositoryConfig, $devRepositoryConfig));
            if (method_exists($package, 'setRepositories')) {
                $package->setRepositories($repositories);
            }

            // Merge requirements
            $localRequires = array();
            foreach ($localComposer->getPackage()->getRequires() as $name => $link) {
                $localRequires[$name] = new Link(
                    $package->getName(),
                    $name,
                    $link->getConstraint(),
                    $link->getDescription(),
                    $link->getPrettyConstraint()
                );
            }
            $package->setRequires($localRequires);

            // Merge dev requirements
            $localDevRequires = array();
            foreach ($localComposer->getPackage()->getDevRequires() as $name => $link) {
                $localDevRequires[$name] = new Link(
                    $package->getName(),
                    $name,
                    $link->getConstraint(),
                    $link->getDescription(),
                    $link->getPrettyConstraint()
                );
            }
            $package->setDevRequires($localDevRequires);

            $this->repository = $repository;
            $this->devRepository = $devRepository;
        }
    }

    /**
     * @param Event $event
     */
    public function postUpdateCmd(Event $event)
    {
        if ($this->repository) {
            $this->updateJson('require', $event, $this->repository);
        }

        if ($this->devRepository) {
            $this->updateJson('require-dev', $event, $this->devRepository);
        }
    }

    /**
     * @param $type
     * @param Event $event
     * @param Repository $repository
     */
    private function updateJson($type, Event $event, Repository $repository)
    {
        $composer = $event->getComposer();
        $package = $composer->getPackage();

        $replace = array();
        foreach ($package->getReplaces() as $key => $link) {
            /* @var Link $link */
            if (!in_array($link->getTarget(), $replace)) {
                $replace[] = $link->getTarget();
            }
        }

        $requires = $this->localConfig[$type];

        foreach ($repository->getPackages() as $localPackage) {
            if (!$localPackage instanceof AliasPackage) {
                /* @var Link $link */
                foreach ($localPackage->getRequires() as $name => $link) {
                    if (in_array($name, $replace)) {
                        continue;
                    }

                    if (isset($requires[$name])) {
                        foreach (explode('|', $link->getPrettyConstraint()) as $prettyConstraint) {
                            if (false === strpos($requires[$name], $prettyConstraint)) {
                                $requires[$name] = $requires[$name] . '|' . $prettyConstraint;
                            }
                        }
                    } else {
                        $requires[$name] = $link->getPrettyConstraint();
                    }
                }
            }
        }

        $file = Factory::getComposerFile();
        $json = new JsonFile($file);

        $contents = file_get_contents($json->getPath());

        $manipulator = new JsonManipulator($contents);

        foreach ($requires as $package => $constraint) {
            $manipulator->addLink($type, $package, $constraint);
        }

        file_put_contents($json->getPath(), $manipulator->getContents());
    }
}
