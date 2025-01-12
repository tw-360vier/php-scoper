<?php

declare(strict_types=1);

/*
 * This file is part of the humbug/php-scoper package.
 *
 * Copyright (c) 2017 Théo FIDRY <theo.fidry@gmail.com>,
 *                    Pádraic Brady <padraic.brady@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Humbug\PhpScoper;

use Humbug\PhpScoper\Patcher\SymfonyPatcher;
use InvalidArgumentException;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_unshift;
use function array_values;
use function bin2hex;
use function dirname;
use function file_exists;
use function gettype;
use function in_array;
use function is_array;
use function is_bool;
use function is_callable;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function is_string;
use function random_bytes;
use function readlink as native_readlink;
use function realpath;
use function Safe\file_get_contents;
use function Safe\sprintf;
use function trim;
use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

final class ConfigurationFactory
{
    private const PREFIX_KEYWORD = 'prefix';
    private const WHITELISTED_FILES_KEYWORD = 'files-whitelist';
    private const FINDER_KEYWORD = 'finders';
    private const PATCHERS_KEYWORD = 'patchers';
    private const WHITELIST_KEYWORD = 'whitelist';
    private const WHITELIST_GLOBAL_CONSTANTS_KEYWORD = 'whitelist-global-constants';
    private const WHITELIST_GLOBAL_CLASSES_KEYWORD = 'whitelist-global-classes';
    private const WHITELIST_GLOBAL_FUNCTIONS_KEYWORD = 'whitelist-global-functions';
    private const CLASSES_INTERNAL_SYMBOLS_KEYWORD = 'excluded-classes';
    private const FUNCTIONS_INTERNAL_SYMBOLS_KEYWORD = 'excluded-functions';
    private const CONSTANTS_INTERNAL_SYMBOLS_KEYWORD = 'excluded-constants';

    private const KEYWORDS = [
        self::PREFIX_KEYWORD,
        self::WHITELISTED_FILES_KEYWORD,
        self::FINDER_KEYWORD,
        self::PATCHERS_KEYWORD,
        self::WHITELIST_KEYWORD,
        self::WHITELIST_GLOBAL_CONSTANTS_KEYWORD,
        self::WHITELIST_GLOBAL_CLASSES_KEYWORD,
        self::WHITELIST_GLOBAL_FUNCTIONS_KEYWORD,
    ];

    private Filesystem $fileSystem;

    public function __construct(Filesystem $fileSystem)
    {
        $this->fileSystem = $fileSystem;
    }

    /**
     * @param string|null $path  Absolute path to the configuration file.
     * @param string[]    $paths List of paths to append besides the one configured
     */
    public function create(?string $path = null, array $paths = []): Configuration
    {
        if (null === $path) {
            $config = [];
        } else {
            $config = $this->loadConfigFile($path);
        }

        self::validateConfigKeys($config);

        $prefix = self::retrievePrefix($config);

        $whitelistedFiles = null === $path
            ? []
            : $this->retrieveWhitelistedFiles(
                dirname($path),
                $config,
            );

        $patchers = self::retrievePatchers($config);

        array_unshift($patchers, new SymfonyPatcher());

        $whitelist = self::retrieveWhitelist($config);

        $finders = self::retrieveFinders($config);
        $filesFromPaths = self::retrieveFilesFromPaths($paths);
        $filesWithContents = self::retrieveFilesWithContents(chain($filesFromPaths, ...$finders));

        return new Configuration(
            $path,
            $prefix,
            $filesWithContents,
            self::retrieveFilesWithContents($whitelistedFiles),
            $patchers,
            $whitelist,
            ...self::retrieveAllInternalSymbols($config),
        );
    }

    /**
     * @param string[] $paths
     */
    public function createWithPaths(Configuration $config, array $paths): Configuration
    {
        $filesWithContents = self::retrieveFilesWithContents(
            chain(
                self::retrieveFilesFromPaths(
                    array_unique($paths),
                ),
            ),
        );

        return new Configuration(
            $config->getPath(),
            $config->getPrefix(),
            array_merge(
                $config->getFilesWithContents(),
                $filesWithContents,
            ),
            $config->getWhitelistedFilesWithContents(),
            $config->getPatchers(),
            $config->getWhitelist(),
            $config->getInternalClasses(),
            $config->getInternalFunctions(),
            $config->getInternalConstants(),
        );
    }

    public function createWithPrefix(Configuration $config, string $prefix): Configuration
    {
        $prefix = self::retrievePrefix([self::PREFIX_KEYWORD => $prefix]);

        return new Configuration(
            $config->getPath(),
            $prefix,
            $config->getFilesWithContents(),
            $config->getWhitelistedFilesWithContents(),
            $config->getPatchers(),
            $config->getWhitelist(),
            $config->getInternalClasses(),
            $config->getInternalFunctions(),
            $config->getInternalConstants(),
        );
    }

    private function loadConfigFile(string $path): array
    {
        if (!$this->fileSystem->isAbsolutePath($path)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected the path of the configuration file to load to be an absolute path, got "%s" instead',
                    $path,
                ),
            );
        }

        if (!file_exists($path)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected the path of the configuration file to exists but the file "%s" could not be found',
                    $path,
                ),
            );
        }

        $isADirectoryLink = is_link($path)
            && false !== native_readlink($path)
            && is_file(native_readlink($path));

        if (!$isADirectoryLink && !is_file($path)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected the path of the configuration file to be a file but "%s" appears to be a directory.',
                    $path,
                ),
            );
        }

        $config = include $path;

        if (!is_array($config)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected configuration to be an array, found "%s" instead.',
                    gettype($config),
                ),
            );
        }

        return $config;
    }

    private static function validateConfigKeys(array $config): void
    {
        array_map(
            static fn (string $key) => self::validateConfigKey($key),
            array_keys($config),
        );
    }

    private static function validateConfigKey(string $key): void
    {
        if (in_array($key, self::KEYWORDS, true)) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Invalid configuration key value "%s" found.',
                $key,
            ),
        );
    }

    private static function retrievePrefix(array $config): string
    {
        $prefix = trim((string) ($config[self::PREFIX_KEYWORD] ?? ''));

        if ('' === $prefix) {
            return self::generateRandomPrefix();
        }

        return $prefix;
    }

    /**
     * @return callable[]
     */
    private static function retrievePatchers(array $config): array
    {
        if (!array_key_exists(self::PATCHERS_KEYWORD, $config)) {
            return [];
        }

        $patchers = $config[self::PATCHERS_KEYWORD];

        if (!is_array($patchers)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected patchers to be an array of callables, found "%s" instead.',
                    gettype($patchers),
                ),
            );
        }

        foreach ($patchers as $index => $patcher) {
            if (is_callable($patcher)) {
                continue;
            }

            throw new InvalidArgumentException(
                sprintf(
                    'Expected patchers to be an array of callables, the "%d" element is not.',
                    $index,
                ),
            );
        }

        return $patchers;
    }

    private static function retrieveWhitelist(array $config): Whitelist
    {
        if (!array_key_exists(self::WHITELIST_KEYWORD, $config)) {
            $whitelist = [];
        } else {
            $whitelist = $config[self::WHITELIST_KEYWORD];

            if (!is_array($whitelist)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Expected whitelist to be an array of strings, found "%s" instead.',
                        gettype($whitelist),
                    ),
                );
            }

            foreach ($whitelist as $index => $className) {
                if (is_string($className)) {
                    continue;
                }

                throw new InvalidArgumentException(
                    sprintf(
                        'Expected whitelist to be an array of string, the "%d" element is not.',
                        $index,
                    ),
                );
            }
        }

        if (!array_key_exists(self::WHITELIST_GLOBAL_CONSTANTS_KEYWORD, $config)) {
            $whitelistGlobalConstants = true;
        } else {
            $whitelistGlobalConstants = $config[self::WHITELIST_GLOBAL_CONSTANTS_KEYWORD];

            if (!is_bool($whitelistGlobalConstants)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Expected %s to be a boolean, found "%s" instead.',
                        self::WHITELIST_GLOBAL_CONSTANTS_KEYWORD,
                        gettype($whitelistGlobalConstants),
                    ),
                );
            }
        }

        if (!array_key_exists(self::WHITELIST_GLOBAL_CLASSES_KEYWORD, $config)) {
            $whitelistGlobalClasses = true;
        } else {
            $whitelistGlobalClasses = $config[self::WHITELIST_GLOBAL_CLASSES_KEYWORD];

            if (!is_bool($whitelistGlobalClasses)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Expected %s to be a boolean, found "%s" instead.',
                        self::WHITELIST_GLOBAL_CLASSES_KEYWORD,
                        gettype($whitelistGlobalClasses),
                    ),
                );
            }
        }

        if (!array_key_exists(self::WHITELIST_GLOBAL_FUNCTIONS_KEYWORD, $config)) {
            $whitelistGlobalFunctions = true;
        } else {
            $whitelistGlobalFunctions = $config[self::WHITELIST_GLOBAL_FUNCTIONS_KEYWORD];

            if (!is_bool($whitelistGlobalFunctions)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Expected %s to be a boolean, found "%s" instead.',
                        self::WHITELIST_GLOBAL_FUNCTIONS_KEYWORD,
                        gettype($whitelistGlobalFunctions),
                    ),
                );
            }
        }

        return Whitelist::create(
            $whitelistGlobalConstants,
            $whitelistGlobalClasses,
            $whitelistGlobalFunctions,
            ...$whitelist,
        );
    }

    /**
     * @return string[] Absolute paths
     */
    private function retrieveWhitelistedFiles(string $dirPath, array $config): array
    {
        if (!array_key_exists(self::WHITELISTED_FILES_KEYWORD, $config)) {
            return [];
        }

        $whitelistedFiles = $config[self::WHITELISTED_FILES_KEYWORD];

        if (!is_array($whitelistedFiles)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected whitelisted files to be an array of strings, found "%s" instead.',
                    gettype($whitelistedFiles),
                ),
            );
        }

        foreach ($whitelistedFiles as $index => $file) {
            if (!is_string($file)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Expected whitelisted files to be an array of string, the "%d" element is not.',
                        $index,
                    ),
                );
            }

            if (!$this->fileSystem->isAbsolutePath($file)) {
                $file = $dirPath.DIRECTORY_SEPARATOR.$file;
            }

            $whitelistedFiles[$index] = realpath($file);
        }

        return array_filter($whitelistedFiles);
    }

    /**
     * @return Finder[]
     */
    private static function retrieveFinders(array $config): array
    {
        if (!array_key_exists(self::FINDER_KEYWORD, $config)) {
            return [];
        }

        $finders = $config[self::FINDER_KEYWORD];

        if (!is_array($finders)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected finders to be an array of "%s", found "%s" instead.',
                    Finder::class,
                    gettype($finders),
                ),
            );
        }

        foreach ($finders as $index => $finder) {
            if ($finder instanceof Finder) {
                continue;
            }

            throw new InvalidArgumentException(
                sprintf(
                    'Expected finders to be an array of "%s", the "%d" element is not.',
                    Finder::class,
                    $index,
                ),
            );
        }

        return $finders;
    }

    /**
     * @param string[] $paths
     *
     * @return iterable<SplFileInfo>
     */
    private static function retrieveFilesFromPaths(array $paths): iterable
    {
        if ([] === $paths) {
            return [];
        }

        $pathsToSearch = [];
        $filesToAppend = [];

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                throw new RuntimeException(
                    sprintf(
                        'Could not find the file "%s".',
                        $path,
                    ),
                );
            }

            if (is_dir($path)) {
                $pathsToSearch[] = $path;
            } else {
                $filesToAppend[] = $path;
            }
        }

        $finder = new Finder();

        $finder->files()
            ->in($pathsToSearch)
            ->append($filesToAppend)
            ->filter(
                static fn (SplFileInfo $fileInfo) => $fileInfo->isLink() ? false : null,
            )
            ->sortByName();

        return $finder;
    }

    /**
     * @param iterable<SplFileInfo|string> $files
     *
     * @return array<string, array{string, string}> Array of tuple with the first argument being the file path and the second its contents
     */
    private static function retrieveFilesWithContents(iterable $files): array
    {
        $filesWithContents = [];

        foreach ($files as $filePathOrFileInfo) {
            $filePath = $filePathOrFileInfo instanceof SplFileInfo
                ? $filePathOrFileInfo->getRealPath()
                : realpath($filePathOrFileInfo);

            if (!$filePath) {
                throw new RuntimeException(
                    sprintf(
                        'Could not find the file "%s".',
                        (string) $filePathOrFileInfo,
                    ),
                );
            }

            if (!is_readable($filePath)) {
                throw new RuntimeException(
                    sprintf(
                        'Could not read the file "%s".',
                        $filePath,
                    ),
                );
            }

            $filesWithContents[$filePath] = [$filePath, file_get_contents($filePath)];
        }

        return $filesWithContents;
    }

    /**
     * @return array{string[], string[], string[]}
     */
    private static function retrieveAllInternalSymbols(array $config): array
    {
        return [
            self::retrieveInternalSymbols($config, self::CLASSES_INTERNAL_SYMBOLS_KEYWORD),
            self::retrieveInternalSymbols($config, self::FUNCTIONS_INTERNAL_SYMBOLS_KEYWORD),
            self::retrieveInternalSymbols($config, self::CONSTANTS_INTERNAL_SYMBOLS_KEYWORD),
        ];
    }

    /**
     * @return string[]
     */
    private static function retrieveInternalSymbols(array $config, string $key): array
    {
        if (!array_key_exists($key, $config)) {
            return [];
        }

        $symbols = $config[$key];

        if (!is_array($symbols)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Expected "%s" to be an array of strings, got "%s" instead.',
                    $key,
                    gettype($symbols),
                ),
            );
        }

        foreach ($symbols as $index => $symbol) {
            if (!is_string($symbol)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Expected "%s" to be an array of strings, got "%s" for the element with the index "%s".',
                        $key,
                        gettype($symbol),
                        $index,
                    ),
                );
            }
        }

        return array_values(array_unique($symbols, SORT_STRING));
    }

    private static function generateRandomPrefix(): string
    {
        return '_PhpScoper'.bin2hex(random_bytes(6));
    }
}
