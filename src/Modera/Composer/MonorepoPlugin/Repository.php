<?php

namespace Modera\Composer\MonorepoPlugin;

use Composer\Config;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ConfigurableRepositoryInterface;

/**
 * @author    Sergei Vizel <sergei.vizel@modera.org>
 * @copyright 2016 Modera Foundation
 */
class Repository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var ArrayLoader
     */
    private $loader;

    /**
     * @var array
     */
    private $repoConfig;

    /**
     * @return string
     */
    static public function clazz()
    {
        return get_called_class();
    }

    /**
     * @param array       $repoConfig
     * @param IOInterface $io
     * @param Config      $config
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config)
    {
        if (!isset($repoConfig['type'])) {
            throw new \RuntimeException('You must specify the `type` configuration');
        }

        if (!isset($repoConfig['include'])) {
            throw new \RuntimeException('You must specify the `include` configuration');
        }

        if (!isset($repoConfig['dev'])) {
            $repoConfig['dev'] = false;
        }

        $this->repoConfig = $repoConfig;

        $this->io = $io;

        $this->loader = new ArrayLoader(null, true);

        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    /**
     * {@inheritDoc}
     */
    protected function initialize()
    {
        parent::initialize();

        foreach ($this->getIncludeMatches() as $filePath) {
            $url = dirname($filePath);
            $composerFilePath = realpath($filePath);

            if (!file_exists($composerFilePath)) {
                continue;
            }

            $json = file_get_contents($composerFilePath);

            $package = JsonFile::parseJson($json);
            $newName = $this->repoConfig['type'] . '/' . str_replace('/', '-', $package['name']);

            if ($this->repoConfig['dev']) {
                $package['require'] = isset($package['require-dev']) ? $package['require-dev'] : array();
            } else {
                $msg = 'Registering package ';
                $msg .= '<info>' . $package['name'] . '</info>';
                $msg .= ' as ';
                $msg .= '<comment>' . $newName . '</comment>';
                $this->io->writeError($msg, true, IOInterface::NORMAL);
            }

            $package['name'] = $newName;
            $package['type'] = 'modera-monorepo';
            $package['dist'] = array(
                'type' => 'path',
                'url' => $url,
                'reference' => sha1($json),
            );

            if (!isset($package['version'])) {
                $package['version'] = '1.0.0';
            }

            $this->addPackage($this->loader->load($package));
        }
    }

    /**
     * @return string[]
     */
    private function getIncludeMatches()
    {
        return array_reduce(
            array_map(
                function ($files, $pattern) {
                    if (!$files) {
                        throw new \RuntimeException('modera-monorepo: No files matched \''.$pattern.'\'');
                    }
                    return $files;
                },
                array_map('glob', $this->repoConfig['include']),
                $this->repoConfig['include']
            ),
            'array_merge',
            array()
        );
    }
}
