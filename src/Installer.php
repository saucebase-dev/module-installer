<?php

namespace Saucebase\ModuleInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use React\Promise\PromiseInterface;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * @property \Composer\PartialComposer $composer
 * @property \Composer\IO\IOInterface  $io
 */
class Installer extends LibraryInstaller
{
    const DEFAULT_ROOT = 'Modules';

    const DEFAULT_MODULE_TYPE = 'laravel-module';

    const DEFAULT_EXCLUDED_DIRS = ['.github', '.git'];

    const DEFAULT_UPDATE_STRATEGY = 'merge';

    const UPDATE_STRATEGY_MERGE = 'merge';

    const UPDATE_STRATEGY_OVERWRITE = 'overwrite';

    public function getInstallPath(PackageInterface $package)
    {
        return $this->getBaseInstallationPath().'/'.$this->getModuleName($package);
    }

    /**
     * Get the base path that the module should be installed into.
     * Defaults to Modules/ and can be overridden in the module's composer.json.
     *
     * @return string
     */
    protected function getBaseInstallationPath()
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_ROOT;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-dir'])) {
            return self::DEFAULT_ROOT;
        }

        return $extra['module-dir'];
    }

    /**
     * Get the module name, i.e. "saucebase/something-nice" will be transformed into "SomethingNice"
     *
     * @param  PackageInterface  $package  Compose Package Interface
     * @return string Module Name
     *
     * @throws ModuleInstallerException
     */
    protected function getModuleName(PackageInterface $package)
    {
        $name = $package->getPrettyName(); // e.g. "saucebase/something-nice"

        if (strpos($name, '/') === false) {
            throw new ModuleInstallerException("Invalid package name: $name");
        }

        // Take only the part after the vendor (index 1)
        [$vendor, $packageName] = explode('/', $name, 2);

        // Split by "-" and convert each segment to ucfirst
        $parts = explode('-', $packageName);

        return implode('', array_map('ucfirst', $parts));
    }

    public function supports($packageType)
    {
        return $packageType === $this->getSupportedModuleType();
    }

    protected function getSupportedModuleType()
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_MODULE_TYPE;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-type'])) {
            return self::DEFAULT_MODULE_TYPE;
        }

        return $extra['module-type'];
    }

    /**
     * Get the list of directories to exclude during installation.
     * Can be configured via the 'module-exclude-dirs' key in composer.json extra.
     *
     * @return array
     */
    protected function getExcludedDirectories()
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_EXCLUDED_DIRS;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-exclude-dirs'])) {
            return self::DEFAULT_EXCLUDED_DIRS;
        }

        return $extra['module-exclude-dirs'];
    }

    /**
     * Remove excluded directories after package installation.
     *
     * @return void
     */
    protected function removeExcludedDirectories(PackageInterface $package)
    {
        $installPath = $this->getInstallPath($package);
        $excludedDirs = $this->getExcludedDirectories();

        if (empty($excludedDirs)) {
            return;
        }

        $filesystem = new Filesystem;

        foreach ($excludedDirs as $dir) {
            $dirPath = $installPath.'/'.$dir;
            if (is_dir($dirPath)) {
                $filesystem->removeDirectory($dirPath);
                $this->io->write("  - Excluded directory: <info>$dir</info>");
            }
        }
    }

    /**
     * Get the update strategy to use when updating a module.
     * Defaults to 'merge' and can be overridden via extra['module-update-strategy'].
     *
     * @return string
     */
    protected function getUpdateStrategy(): string
    {
        if (! $this->composer || ! $this->composer->getPackage()) {
            return self::DEFAULT_UPDATE_STRATEGY;
        }

        $extra = $this->composer->getPackage()->getExtra();

        if (! $extra || empty($extra['module-update-strategy'])) {
            return self::DEFAULT_UPDATE_STRATEGY;
        }

        $strategy = $extra['module-update-strategy'];
        $allowed = [self::UPDATE_STRATEGY_MERGE, self::UPDATE_STRATEGY_OVERWRITE];

        if (! in_array($strategy, $allowed, true)) {
            $this->io->writeError(
                sprintf(
                    '  <warning>Invalid module-update-strategy "%s". Falling back to "%s".</warning>',
                    $strategy,
                    self::DEFAULT_UPDATE_STRATEGY
                )
            );

            return self::DEFAULT_UPDATE_STRATEGY;
        }

        return $strategy;
    }

    /**
     * Rename the module directory to a temp location and return the temp path.
     * Returns null if the directory does not exist.
     *
     * @param  string  $path
     * @return string|null
     */
    protected function stashModuleDir(string $path): ?string
    {
        if (! is_dir($path)) {
            return null;
        }

        $stash = sys_get_temp_dir().'/module-stash-'.uniqid('', true);
        (new SymfonyFilesystem)->rename($path, $stash);

        return $stash;
    }

    /**
     * Mirror the stash directory back into the install path so user edits win.
     *
     * @param  string  $stashPath
     * @param  string  $installPath
     * @return void
     */
    protected function restoreStash(string $stashPath, string $installPath): void
    {
        (new SymfonyFilesystem)->mirror(
            $stashPath,
            $installPath,
            null,
            ['override' => true, 'delete' => false]
        );
    }

    /**
     * Override install to remove excluded directories after installation.
     *
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): PromiseInterface|null
    {
        $promise = parent::install($repo, $package);

        return $promise->then(function () use ($package) {
            $this->removeExcludedDirectories($package);
        });
    }

    /**
     * Override update to preserve user customisations (merge strategy) or replace entirely (overwrite).
     *
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target): PromiseInterface|null
    {
        $stashPath = null;

        if ($this->getUpdateStrategy() === self::UPDATE_STRATEGY_MERGE) {
            $stashPath = $this->stashModuleDir($this->getInstallPath($initial));
        }

        $promise = parent::update($repo, $initial, $target);

        return $promise->then(
            function () use ($target, $stashPath) {
                $this->removeExcludedDirectories($target);
                if ($stashPath !== null) {
                    $this->restoreStash($stashPath, $this->getInstallPath($target));
                    (new Filesystem)->removeDirectory($stashPath);
                }
            },
            function (\Throwable $e) use ($stashPath) {
                if ($stashPath !== null) {
                    (new Filesystem)->removeDirectory($stashPath);
                }
                throw $e;
            }
        );
    }
}
