<?php

namespace Modera\Composer\MonorepoPlugin;

use Composer\Composer;
use Composer\Package\Link;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Package\CompletePackage;
use Composer\Package\RootAliasPackage;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\ComposerRepository;
use Composer\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author    Sergei Vizel <sergei.vizel@modera.org>
 * @copyright 2016 Modera Foundation
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var IOInterface $io
     */
    protected $io;

    /**
     * @var OutputInterface $output
     */
    protected $output;

    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->composer = $composer;

        $reflector = new \ReflectionObject($this->io);
        $method = $reflector->getMethod('getErrorOutput');
        $method->setAccessible(true);
        $this->output = $method->invoke($this->io);
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => 'preDependenciesSolving',
        );
    }

    /**
     * @param InstallerEvent $event
     */
    public function preDependenciesSolving(InstallerEvent $event)
    {
        $extra = array(
            'require' => array(),
            'require-dev' => array(),
        );

        $root = $this->composer->getPackage();
        if ($root instanceof RootAliasPackage) {
            $root = $root->getAliasOf();
        }
        if ($this->canHandle($root)) {
            $extra = $this->process($event, $root, getcwd(), $extra);
        }

        $verbosity = $this->output->getVerbosity();
        for ($i = 1; $i < $event->getPool()->count(); $i++) {
            $package = $event->getPool()->packageById($i);

            if ($package instanceof CompletePackage && $this->canHandle($package)) {
                $process = false;
                foreach ($event->getRequest()->getJobs() as $job) {
                    if (isset($job['packageName']) && $package->getName() == $job['packageName']) {
                        $process = true;
                    }
                }

                if ($process && $package->getRepository() instanceof ComposerRepository) {
                    $targetDir = tempnam(sys_get_temp_dir(), '');
                    unlink($targetDir);
                    mkdir($targetDir);
                    $dm = $event->getComposer()->getDownloadManager();
                    $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
                    $dm->download($package, $targetDir);
                    $this->output->setVerbosity($verbosity);

                    $extra = $this->process($event, $package, $targetDir, $extra);
                }
            }
        }

        $request = $event->getRequest();

        /* @var Link $link */
        foreach ($extra['require'] as $link) {
            $this->io->writeError(
                'Adding dependency <comment>'.$link.'</comment>',
                true,
                IOInterface::VERBOSE
            );
            $request->install($link->getTarget(), $link->getConstraint());
        }

        /* @var Link $link */
        foreach ($extra['require-dev'] as $link) {
            $this->io->writeError('Adding dev dependency <comment>'.$link.'</comment>',
                true,
                IOInterface::VERBOSE
            );
            $request->install($link->getTarget(), $link->getConstraint());
        }
    }

    /**
     * @param CompletePackage $package
     * @return bool
     */
    protected function canHandle(CompletePackage $package)
    {
        $extra = $package->getExtra();
        if (isset($extra['modera-monorepo'])) {
            return true;
        }

        return false;
    }

    /**
     * @param InstallerEvent $event
     * @param CompletePackage $package
     * @param $targetDir
     * @param array $extra
     * @return array
     */
    protected function process(InstallerEvent $event, CompletePackage $package, $targetDir, array $extra)
    {
        $root = $this->composer->getPackage();

        $isDevMode = false;
        if ($event->isDevMode()) {
            if ($root->getName() == $package->getName()) {
                $isDevMode = true;
            }
        }

        $patterns = array();
        $_extra = $package->getExtra();
        foreach ($_extra['modera-monorepo']['include'] as $path) {
            $patterns[] = $targetDir . DIRECTORY_SEPARATOR . $path;
        }

        $files = array_map(
            function ($files, $pattern) {
                if (!$files) {
                    throw new \RuntimeException('modera-monorepo: No files matched \''.$pattern.'\'');
                }
                return $files;
            },
            array_map('glob', $patterns),
            $patterns
        );

        $loadedFiles = array();
        foreach (array_reduce($files, 'array_merge', array()) as $path) {
            if (!isset($loadedFiles[$path])) {
                $loadedFiles[$path] = true;
                $this->io->writeError(
                    'Loading <comment>'.str_replace($targetDir, $package->getPrettyName().':', $path).'</comment>...',
                    true,
                    IOInterface::VERBOSE
                );

                $json = $this->readPackageJson($path);
                $_package = $this->loadPackage($json);

                $requires = $_package->getRequires();
                foreach (array_keys($package->getReplaces()) as $key) {
                    unset($requires[$key]);
                }
                foreach ($requires as $name => $link) {
                    $extra['require'][] = $link;
                }

                if ($isDevMode) {
                    $devRequires = $_package->getDevRequires();
                    foreach (array_keys($package->getReplaces()) as $key) {
                        unset($devRequires[$key]);
                    }
                    foreach ($devRequires as $name => $link) {
                        $extra['require-dev'][] = $link;
                    }
                }
            }
        }

        return $extra;
    }

    /**
     * @param $path
     * @return mixed
     */
    protected function readPackageJson($path)
    {
        $file = new JsonFile($path);
        $json = $file->read();
        if (!isset($json['version'])) {
            $json['version'] = '1.0.0';
        }

        return $json;
    }

    /**
     * @param $json
     * @return CompletePackage
     */
    protected function loadPackage($json)
    {
        $loader = new ArrayLoader();
        $package = $loader->load($json);
        if (!$package instanceof CompletePackage) {
            throw new \UnexpectedValueException('Expected instance of CompletePackage, got '.get_class($package));
        }

        return $package;
    }
}
