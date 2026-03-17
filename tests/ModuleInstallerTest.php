<?php

declare(strict_types=1);

namespace Tests;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\PartialComposer;
use PHPUnit\Framework\TestCase;
use Saucebase\ModuleInstaller\Exceptions\ModuleInstallerException;
use Saucebase\ModuleInstaller\Installer;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Shim that avoids LibraryInstaller's heavy constructor.
 *
 * @property PartialComposer $composer
 * @property IOInterface $io
 */
final class TestableInstaller extends Installer
{
    public function __construct(?IOInterface $io = null, ?Composer $composer = null)
    {
        $this->io = $io;
        $this->composer = $composer;
    }

    public function callGetModuleName(PackageInterface $package): string
    {
        return parent::getModuleName($package);
    }

    public function callGetBaseInstallationPath(): string
    {
        return parent::getBaseInstallationPath();
    }

    public function callGetUpdateStrategy(): string
    {
        return parent::getUpdateStrategy();
    }

    public function callStashModuleDir(string $path): ?string
    {
        return parent::stashModuleDir($path);
    }

    public function callRestoreStash(string $from, string $to): void
    {
        parent::restoreStash($from, $to);
    }
}

final class ModuleInstallerTest extends TestCase
{
    public function test_supports_default_module_type(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $installer = new TestableInstaller($io, $composer);

        $this->assertTrue($installer->supports('laravel-module'));
        $this->assertFalse($installer->supports('library'));
        $this->assertFalse($installer->supports('composer-plugin'));
    }

    public function test_supports_custom_module_type_from_extra(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-type' => 'saucebase-module']);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $this->assertTrue($installer->supports('saucebase-module'));
        $this->assertFalse($installer->supports('laravel-module'));
    }

    public function test_get_install_path_uses_default_modules_dir_when_no_composer(): void
    {
        // Composer is null -> should fall back to DEFAULT_ROOT ("Modules")
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $pkg = new Package('saucebase/something-nice', '1.0.0.0', '1.0.0');

        $this->assertSame('Modules/SomethingNice', $installer->getInstallPath($pkg));
    }

    public function test_get_install_path_uses_default_when_no_module_dir_in_extra(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        // Root package present, but without extra['module-dir']
        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra([]); // nothing set
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);
        $pkg = new Package('vendor/awesome-toolkit', '1.0.0.0', '1.0.0');

        $this->assertSame('Modules/AwesomeToolkit', $installer->getInstallPath($pkg));
    }

    public function test_get_install_path_honors_extra_module_dir(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-dir' => 'CustomModules']); // custom dir
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);
        $pkg = new Package('vendor/awesome-toolkit', '1.0.0.0', '1.0.0');

        $this->assertSame('CustomModules/AwesomeToolkit', $installer->getInstallPath($pkg));
    }

    public function test_get_module_name_throws_on_invalid_pretty_name(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);
        $installer = new TestableInstaller($io, $composer);

        $bad = $this->createStub(PackageInterface::class);
        $bad->method('getPrettyName')->willReturn('invalidname'); // no slash

        $this->expectException(ModuleInstallerException::class);
        $installer->callGetModuleName($bad);
    }

    // -------------------------------------------------------------------------
    // getUpdateStrategy
    // -------------------------------------------------------------------------

    public function test_get_update_strategy_returns_merge_by_default(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $this->assertSame('merge', $installer->callGetUpdateStrategy());
    }

    public function test_get_update_strategy_returns_overwrite_when_configured(): void
    {
        $io = $this->createStub(IOInterface::class);
        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-update-strategy' => 'overwrite']);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $this->assertSame('overwrite', $installer->callGetUpdateStrategy());
    }

    public function test_get_update_strategy_falls_back_on_invalid_value_and_warns(): void
    {
        $io = $this->createMock(IOInterface::class);
        $io->expects($this->once())->method('writeError');

        $composer = $this->createStub(Composer::class);

        $root = new RootPackage('root/app', '1.0.0.0', '1.0.0');
        $root->setExtra(['module-update-strategy' => 'bogus']);
        $composer->method('getPackage')->willReturn($root);

        $installer = new TestableInstaller($io, $composer);

        $this->assertSame('merge', $installer->callGetUpdateStrategy());
    }

    // -------------------------------------------------------------------------
    // stashModuleDir
    // -------------------------------------------------------------------------

    public function test_stash_renames_dir_to_temp_location(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $source = sys_get_temp_dir().'/module-stash-test-src-'.uniqid('', true);
        mkdir($source);
        file_put_contents($source.'/custom.txt', 'hello');

        $stash = $installer->callStashModuleDir($source);

        $this->assertNotNull($stash);
        $this->assertDirectoryDoesNotExist($source);
        $this->assertDirectoryExists($stash);
        $this->assertFileExists($stash.'/custom.txt');

        // Cleanup
        (new Filesystem)->remove($stash);
    }

    public function test_stash_returns_null_when_dir_does_not_exist(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $this->assertNull($installer->callStashModuleDir('/nonexistent/path/'.uniqid('', true)));
    }

    // -------------------------------------------------------------------------
    // restoreStash
    // -------------------------------------------------------------------------

    public function test_restore_stash_copies_user_files_into_install_path(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $stash = sys_get_temp_dir().'/module-stash-test-restore-'.uniqid('', true);
        $install = sys_get_temp_dir().'/module-install-test-'.uniqid('', true);

        mkdir($stash);
        mkdir($install);
        file_put_contents($stash.'/custom.txt', 'user edit');

        $installer->callRestoreStash($stash, $install);

        $this->assertFileExists($install.'/custom.txt');
        $this->assertSame('user edit', file_get_contents($install.'/custom.txt'));

        // Cleanup
        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($install);
    }

    public function test_restore_stash_leaves_new_upstream_files_intact(): void
    {
        $io = $this->createStub(IOInterface::class);
        $installer = new TestableInstaller($io, null);

        $stash = sys_get_temp_dir().'/module-stash-test-upstream-'.uniqid('', true);
        $install = sys_get_temp_dir().'/module-install-test-upstream-'.uniqid('', true);

        mkdir($stash);
        mkdir($install);

        // Upstream added a new file during update
        file_put_contents($install.'/new-upstream.txt', 'new from upstream');
        // User had a customised file in the stash
        file_put_contents($stash.'/custom.txt', 'user edit');

        $installer->callRestoreStash($stash, $install);

        $this->assertFileExists($install.'/new-upstream.txt');
        $this->assertFileExists($install.'/custom.txt');

        // Cleanup
        $fs = new Filesystem;
        $fs->remove($stash);
        $fs->remove($install);
    }
}
