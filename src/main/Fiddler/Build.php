<?php
/**
 * Fiddler
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Fiddler;

use Fiddler\Composer\FiddlerInstalledRepository;
use Fiddler\Composer\FiddlerInstaller;
use Fiddler\Composer\EventDispatcher;
use Fiddler\Composer\AutoloadGenerator;
use Symfony\Component\Finder\Finder;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Config;
use Composer\Composer;
use Composer\Package\Package;

/**
 * Scan project for fiddler.json files, indicating components, "building" them.
 *
 * The build step is very simple and consists of generating a
 * `vendor/autoload.php` file similar to how Composer generates it.
 *
 * Prototype at Fiddler funtionality. No change detection yet.
 */
class Build
{
    private $io;

    public function __construct(IOInterface $io = null)
    {
        $this->io = $io ?: new NullIO();
    }

    public function build($rootDirectory, $optimize = false, $noDevMode = false)
    {
        $packages = $this->loadPackages($rootDirectory);

        $evm = new EventDispatcher(new Composer(), $this->io);
        $generator = new AutoloadGenerator($evm, $this->io);
        $generator->setDevMode(!$noDevMode);
        $installationManager = new InstallationManager();
        $installationManager->addInstaller(new FiddlerInstaller());

        $this->io->write('Building fiddler.json projects.');

        foreach ($packages as $packageName => $config) {
            if (strpos($packageName, 'vendor') === 0) {
                continue;
            }

            $this->io->write(' [Build] <info>' . $packageName . '</info>');

            $mainPackage = new Package($packageName, "@stable", "@stable");
            $mainPackage->setType('fiddler');
            $mainPackage->setAutoload($config['autoload']);
            $mainPackage->setDevAutoload($config['autoload-dev']);

            $localRepo = new FiddlerInstalledRepository();
            $this->resolvePackageDependencies($localRepo, $packages, $packageName);

            $composerConfig = new Config(true, $rootDirectory);
            $composerConfig->merge(array('config' => array('vendor-dir' => $config['path']. '/vendor')));
            $generator->dump(
                $composerConfig,
                $localRepo,
                $mainPackage,
                $installationManager,
                'composer',
                $optimize
            );
        }
    }

    private function resolvePackageDependencies($repository, $packages, $packageName)
    {
        $config = $packages[$packageName];

        foreach ($config['deps'] as $dependencyName) {
            if ($dependencyName === 'vendor/php' || strpos($dependencyName, 'vendor/ext-') === 0 || strpos($dependencyName, 'vendor/lib-') === 0) {
                continue;
            }

            if (!isset($packages[$dependencyName])) {
                throw new \RuntimeException("Requiring non existant package '" . $dependencyName . "' in '" . $packageName . "'.");
            }

            $dependency = $packages[$dependencyName];
            $package = new Package($dependency['path'], "@stable", "@stable");
            $package->setType('fiddler');

            if (isset($dependency['autoload']) && is_array($dependency['autoload'])) {
                $package->setAutoload($dependency['autoload']);
            }

            if (!$repository->hasPackage($package)) {
                $repository->addPackage($package);
                $this->resolvePackageDependencies($repository, $packages, $dependencyName);
            }
        }
    }

    public function loadPackages($rootDirectory)
    {
        $finder = new Finder();
        $finder->in($rootDirectory)
               ->exclude('vendor')
               ->ignoreVCS(true)
               ->useBestAdapter()
               ->name('fiddler.json');

        $packages = array();

        foreach ($finder as $file) {
            $fiddlerJson = $this->loadFiddlerJson($file->getContents(), $file->getPath());

            if ($fiddlerJson === NULL) {
                throw new \RuntimeException("Invalid " . $file->getRelativePath() . '/fiddler.json file.');
            }

            $fiddlerJson['path'] = $file->getRelativePath();

            if (!isset($fiddlerJson['autoload'])) {
                $fiddlerJson['autoload'] = array();
            }
            if (!isset($fiddlerJson['autoload-dev'])) {
                $fiddlerJson['autoload-dev'] = array();
            }
            if (!isset($fiddlerJson['deps'])) {
                $fiddlerJson['deps'] = array();
            }

            $packages[$file->getRelativePath()] = $fiddlerJson;
        }

        $installedJsonFile = $rootDirectory . '/vendor/composer/installed.json';
        if (file_exists($installedJsonFile)) {
            $installed = json_decode(file_get_contents($installedJsonFile), true);

            if ($installed === NULL) {
                throw new \RuntimeException("Invalid installed.json file at " . dirname($installedJsonFile));
            }

            foreach ($installed as $composerJson) {
                $name = $composerJson['name'];

                $fiddleredComposerJson = array(
                    'path' => 'vendor/' . $name,
                    'autoload' => array(),
                    'deps' => array(),
                );

                if (isset($composerJson['autoload'])) {
                    $fiddleredComposerJson['autoload'] = $composerJson['autoload'];
                }

                if (isset($composerJson['autoload-dev'])) {
                    $fiddleredComposerJson['autoload'] = array_merge_recursive(
                        $fiddleredComposerJson['autoload'],
                        $composerJson['autoload-dev']
                    );
                }

                if (isset($composerJson['require'])) {
                    foreach ($composerJson['require'] as $packageName => $_) {
                        $fiddleredComposerJson['deps'][] = 'vendor/' . $packageName;
                    }
                }

                $packages['vendor/' . $name] = $fiddleredComposerJson;

                if (isset($composerJson['replace'])) {
                    foreach ($composerJson['replace'] as $replaceName => $_) {
                        $packages['vendor/' . $replaceName] = $fiddleredComposerJson;
                    }
                }
            }
        }

        return $packages;
    }

    private function loadFiddlerJson($contents, $path)
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../resources/fiddler-schema.json'));
        $data = json_decode($contents);

        // Validate
        $validator = new \JsonSchema\Validator();
        $validator->check($data, $schema);

        if (!$validator->isValid()) {
            $errors = array();
            foreach ($validator->getErrors() as $error) {
                $errors[] = sprintf("[%s] %s\n", $error['property'], $error['message']);
            }
            throw new \RuntimeException(sprintf("JSON is not valid in %s\n%s", $path, implode("\n", $errors)));
        }

        return json_decode($contents, true);
    }
}
