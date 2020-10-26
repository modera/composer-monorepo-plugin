<?php

namespace Modera\Composer\MonorepoPlugin;

use Composer\Package\PackageInterface;
use Composer\Installer\InstallerInterface;
use Composer\Repository\InstalledRepositoryInterface;

/**
 * @author    Sergei Vizel <sergei.vizel@modera.org>
 * @copyright 2016 Modera Foundation
 */
class Installer implements InstallerInterface
{
    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'modera-monorepo';
    }

    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
        return $package->getDistUrl();
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
    }
}
