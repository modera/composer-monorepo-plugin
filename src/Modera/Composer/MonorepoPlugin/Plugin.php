<?php

namespace Modera\Composer\MonorepoPlugin;

use Composer\Composer;
use Composer\Script\Event;
use Composer\Package\Link;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Script\ScriptEvents;
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Composer\Package\CompletePackage;
use Composer\Package\RootAliasPackage;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author    Sergei Vizel <sergei.vizel@modera.org>
 * @copyright 2016 Modera Foundation
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var int
     */
    protected $outputVerbosity;

    /**
     * @var bool
     */
    protected $update = false;

    /**
     * @var bool
     */
    protected $process = true;

    /**
     * @var array
     */
    protected $packageDirCache = array();

    /**
     * {@inheritdoc}
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
        $this->composer = $composer;

        $reflector = new \ReflectionObject($this->io);
        $getErrorOutputMethod = $reflector->getMethod('getErrorOutput');
        $getErrorOutputMethod->setAccessible(true);

        $this->output = $getErrorOutputMethod->invoke($this->io);
        $this->outputVerbosity = $this->output->getVerbosity();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        $response = array(
            ScriptEvents::PRE_UPDATE_CMD => array(
                array('preUpdateCmd', PHP_INT_MAX)
            ),
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => array(
                array('preDependenciesSolving', PHP_INT_MAX)
            ),
        );

        foreach (array('PRE_PACKAGE_', 'POST_PACKAGE_') as $prefix) {
            foreach (array('INSTALL', 'UPDATE', 'UNINSTALL') as $type) {
                $event = 'Composer\Installer\PackageEvents::'.$prefix.$type;
                if (defined($event)) {
                    $response[constant($event)] = array(
                        array('onPackageEvents', PHP_INT_MAX),
                    );
                }
            }
        }

        return $response;
    }

    /**
     * @param Event $event
     */
    public function preUpdateCmd(Event $event)
    {
        $this->update = true;
    }

    /**
     * @param InstallerEvent $event
     */
    public function preDependenciesSolving(InstallerEvent $event)
    {
        if (!$this->update) {
            return;
        }

        if (!$this->process) {
            return;
        }

        $this->process = false;

        $request = $event->getRequest();
        $packages = $this->getPackages($event);

        foreach ($packages as $package) {
            $isDevMode = false;
            if ($event->isDevMode()) {
                if ($package instanceof RootPackageInterface) {
                    $isDevMode = true;
                }
            }

            $data = $this->loadIncludeFiles($package);

            foreach ($data as $json) {
                $_package = $this->loadPackage($json);

                $requires = $package->getRequires();
                $_requires = $_package->getRequires();
                foreach (array_keys($package->getReplaces()) as $key) {
                    unset($_requires[$key]);
                }
                foreach ($_requires as $name => $link) {
                    $link = $requires[$name] = new Link(
                        $package->getName(),
                        $link->getTarget(),
                        $link->getConstraint(),
                        $link->getDescription(),
                        $link->getPrettyConstraint()
                    );

                    $msg = 'Adding dependency';
                    $msg .= ' <info>' . $_package->getName() . '</info>';
                    $msg .= '<comment>' . str_replace($package->getName(), '', $link) . '</comment>';

                    $this->io->writeError($msg, true, IOInterface::VERY_VERBOSE);
                    $request->install($link->getTarget(), $link->getConstraint());
                }
                $package->setRequires($requires);

                if ($isDevMode) {
                    $devRequires = $package->getDevRequires();
                    $_devRequires = $_package->getDevRequires();
                    foreach (array_keys($package->getReplaces()) as $key) {
                        unset($_devRequires[$key]);
                    }
                    foreach ($_devRequires as $name => $link) {
                        $link = $devRequires[$name] = new Link(
                            $package->getName(),
                            $link->getTarget(),
                            $link->getConstraint(),
                            $link->getDescription(),
                            $link->getPrettyConstraint()
                        );

                        $msg = 'Adding dev dependency';
                        $msg .= ' <info>' . $_package->getName() . '</info>';
                        $msg .= '<comment>' . str_replace($package->getName(), '', $link) . '</comment>';

                        $this->io->writeError($msg, true, IOInterface::VERY_VERBOSE);
                        $request->install($link->getTarget(), $link->getConstraint());
                    }
                    $package->setDevRequires($devRequires);
                }
            }
        }
    }

    /**
     * @param PackageEvent $event
     */
    public function onPackageEvents(PackageEvent $event)
    {
        $prefix = 'PRE_PACKAGE_';
        if (false !== strpos($event->getName(), 'post-package-')) {
            $prefix = 'POST_PACKAGE_';
        }

        $operation = $event->getOperation();

        $packageEvent = 'Composer\Installer\PackageEvents::'.$prefix.strtoupper($operation->getJobType());
        if (!defined($packageEvent)) {
            return;
        }

        if ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            $package = $operation->getPackage();
        }

        $operationClass = get_class($operation);
        $eventDispatcher = $this->composer->getEventDispatcher();

        $reflector = new \ReflectionObject($eventDispatcher);
        $popEventMethod = $reflector->getMethod('popEvent');
        $popEventMethod->setAccessible(true);

        $extra = $package->getExtra();
        if (isset($extra['modera-monorepo']['packages'])) {
            foreach ($extra['modera-monorepo']['packages'] as $json) {
                $_package = $this->loadPackage($json);
                if ($operation instanceof UpdateOperation) {
                    $mockOperation = new $operationClass(
                        $operation->getInitialPackage(),
                        $_package,
                        $operation->getReason()
                    );
                } else {
                    $mockOperation = new $operationClass(
                        $_package,
                        $operation->getReason()
                    );
                }

                $popEventMethod->invoke($eventDispatcher);
                $eventDispatcher->dispatchPackageEvent(
                    constant($packageEvent),
                    $event->isDevMode(),
                    $event->getPolicy(),
                    $event->getPool(),
                    $event->getInstalledRepo(),
                    $event->getRequest(),
                    $event->getOperations(),
                    $mockOperation
                );

                if ($prefix == 'PRE_PACKAGE_') {
                    $msg = '  - ';
                    if ('uninstall' == $operation->getJobType()) {
                        $msg .= 'Removing';
                    } else {
                        $msg .= ucfirst($operation->getJobType()) . 'ing';
                    }
                    $msg .= ' <info>' . $_package->getName() . '</info>';
                    $msg .= ' (<comment>' . $package->getFullPrettyVersion() . '</comment>)';

                    $this->io->writeError($msg, true, IOInterface::VERBOSE);
                }
            }
        }
    }

    /**
     * @param CompletePackage $package
     * @return bool
     */
    protected function canHandle(CompletePackage $package)
    {
        $extra = $package->getExtra();
        if (!$package->getRepository() instanceof InstalledRepositoryInterface) {
            if (isset($extra['modera-monorepo'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param InstallerEvent $event
     * @return array
     */
    protected function getPackages(InstallerEvent $event)
    {
        $packages = array();

        $root = $this->composer->getPackage();
        if ($root instanceof RootAliasPackage) {
            $root = $root->getAliasOf();
        }
        if ($this->canHandle($root)) {
            $packages[] = $root;
        }

        $cache = array();
        $pool = $event->getPool();
        $request = $event->getRequest();

        $reflector = new \ReflectionObject($pool);
        $matchMethod = $reflector->getMethod('match');
        $matchMethod->setAccessible(true);

        foreach ($request->getJobs() as $job) {
            if (in_array($job['cmd'], array('install', 'update'))) {
                if (isset($job['packageName']) && isset($job['constraint'])) {
                    for ($i = 0; $i < $pool->count(); $i++) {
                        $package = $pool->packageById($i + 1);

                        if ($package instanceof AliasPackage) {
                            continue;
                        }

                        if ($package->getName() == $job['packageName']) {
                            $match = $matchMethod->invoke($pool, $package, $job['packageName'], $job['constraint'], false);
                            if ($pool::MATCH == $match) {
                                if ($this->canHandle($package)) {
                                    if (!in_array($package, $packages)) {
                                        if (!isset($cache[$package->getName()])) {
                                            $cache[$package->getName()] = array();
                                        }
                                        if (!in_array($package->getPrettyVersion(), $cache[$package->getName()])) {
                                            $cache[$package->getName()][] = $package->getPrettyVersion();
                                            $packages[] = $package;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $packages;
    }

    /**
     * @param CompletePackage $package
     * @return mixed|string
     */
    protected function getPackageDir(CompletePackage $package)
    {
        if ($package instanceof RootPackageInterface) {
            return getcwd();
        }

        if (!isset($this->packageDirCache[$package->getName()])) {
            $this->packageDirCache[$package->getName()] = array();
        }

        if (isset($this->packageDirCache[$package->getName()][$package->getPrettyVersion()])) {
            return $this->packageDirCache[$package->getName()][$package->getPrettyVersion()];
        }

        $packageDir = tempnam(sys_get_temp_dir(), '');
        unlink($packageDir);
        mkdir($packageDir);

        $dm = $this->composer->getDownloadManager();
        $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        $dm->download($package, $packageDir);
        $this->output->setVerbosity($this->outputVerbosity);

        $this->packageDirCache[$package->getName()][$package->getPrettyVersion()] = $packageDir;

        return $packageDir;
    }

    /**
     * @param CompletePackage $package
     */
    protected function removePackageDir(CompletePackage $package)
    {
        if ($package instanceof RootPackageInterface) {
            return;
        }

        if (!isset($this->packageDirCache[$package->getName()])) {
            $this->packageDirCache[$package->getName()] = array();
        }

        $removeDir = function($dir) use(&$removeDir) {
            if (is_dir($dir)) {
                $objects = scandir($dir);
                foreach ($objects as $object) {
                    if ($object != "." && $object != "..") {
                        if (is_dir($dir . "/" . $object)) {
                            $removeDir($dir."/".$object);
                        } else {
                            unlink($dir."/".$object);
                        }
                    }
                }
                rmdir($dir);
            }
        };

        if (isset($this->packageDirCache[$package->getName()][$package->getPrettyVersion()])) {
            $removeDir($this->packageDirCache[$package->getName()][$package->getPrettyVersion()]);
            unset($this->packageDirCache[$package->getName()][$package->getPrettyVersion()]);
        }
    }

    /**
     * @param CompletePackage $package
     * @return array
     */
    protected function prepareIncludeFiles(CompletePackage $package)
    {
        $patterns = array();
        $extra = $package->getExtra();
        $packageDir = $this->getPackageDir($package);
        foreach ($extra['modera-monorepo']['include'] as $path) {
            $patterns[] = $packageDir . DIRECTORY_SEPARATOR . $path;
        }

        return array_map(
            function ($files, $pattern) {
                if (!$files) {
                    throw new \RuntimeException('modera-monorepo: No files matched \''.$pattern.'\'');
                }
                return $files;
            },
            array_map('glob', $patterns),
            $patterns
        );
    }

    /**
     * @param CompletePackage $package
     * @return array
     */
    protected function loadIncludeFiles(CompletePackage $package)
    {
        $files = $this->prepareIncludeFiles($package);
        $packageDir = $this->getPackageDir($package);

        $extra = $package->getExtra();
        $extra['modera-monorepo']['packages'] = array();

        foreach (array_reduce($files, 'array_merge', array()) as $path) {
            $msg = 'Loading';
            $msg .= ' <info>' . $package->getName() . ':' . $package->getPrettyVersion() . '</info>';
            $msg .= ' <comment>' . str_replace($packageDir, '', $path) . '</comment>';

            $this->io->writeError($msg, true, IOInterface::VERY_VERBOSE);
            $extra['modera-monorepo']['packages'][] = $this->readPackageJson($path);
        }

        $package->setExtra($extra);

        $this->removePackageDir($package);

        return $extra['modera-monorepo']['packages'];
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
