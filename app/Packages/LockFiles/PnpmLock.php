<?php

namespace App\Packages\LockFiles;

use App\Enums\Ecosystem;
use App\Packages\PackageReading;

/**
 * `pnpm-lock.yaml`, lockfile versions 5, 6 and 9, read line by line: the
 * file is machine-written with two-space indents, so no YAML parser is
 * needed. Packages are keyed `/name/1.2.3` (v5), `/name@1.2.3` (v6) or
 * `name@1.2.3` (v9), with peer suffixes such as `(react@18.2.0)` or
 * `_react@18.2.0`. Direct dependencies come from `importers` (or the
 * top-level `dependencies` of a single project). v9 drops the `dev` flag,
 * so only direct dev dependencies are known to be dev there.
 */
class PnpmLock implements LockFile
{
    private const DEPENDENCY_SECTIONS = ['dependencies' => false, 'devDependencies' => true, 'optionalDependencies' => false];

    public function manifest(): ?string
    {
        return null;
    }

    public function parse(string $contents, ?string $manifest = null): array
    {
        if (preg_match('/^lockfileVersion:\s*[\'"]?(\d+)/m', $contents, $match) !== 1) {
            throw new InvalidLockFile('pnpm-lock.yaml has no lockfileVersion');
        }

        $atSign = (int) $match[1] >= 6;
        /** @var array<string, bool> $packages "name@version" => the v5/v6 dev flag */
        $packages = [];
        /** @var array<string, bool> $direct "name@version" => dev */
        $direct = [];
        $section = null;
        $current = null;
        $dependencySection = null;
        $dependency = null;

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $text = trim($line);

            if ($text === '' || str_starts_with($text, '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line, ' '));

            if ($indent === 0) {
                $section = rtrim(explode(':', $text, 2)[0]);
                $current = $dependencySection = $dependency = null;
                // A single project lists its dependencies at the top level (v5, v6).
                $dependencySection = array_key_exists($section, self::DEPENDENCY_SECTIONS) ? $section : null;

                continue;
            }

            if ($section === 'packages') {
                if ($indent === 2) {
                    $current = $this->packageKey(rtrim($text, ':'), $atSign);

                    if ($current !== null) {
                        $packages[$current] ??= false;
                    }
                } elseif ($indent === 4 && $current !== null && preg_match('/^dev:\s*(true|false)$/', $text, $flag) === 1) {
                    $packages[$current] = $flag[1] === 'true';
                }

                continue;
            }

            // Under importers, names sit below the importer and its section: two levels deeper.
            $depth = $section === 'importers' ? 4 : 0;

            if ($section === 'importers' && $indent === 2) {
                $dependencySection = $dependency = null;
            } elseif ($section === 'importers' && $indent === 4) {
                $key = rtrim(explode(':', $text, 2)[0]);
                $dependencySection = array_key_exists($key, self::DEPENDENCY_SECTIONS) ? $key : null;
                $dependency = null;
            } elseif ($dependencySection !== null && $indent === 2 + $depth) {
                [$name, $value] = array_map(trim(...), explode(':', $text, 2)) + [1 => ''];
                $dependency = $this->unquote($name);

                // v5 writes `name: 1.2.3`; v6 and v9 nest `specifier` and `version`.
                if ($value !== '') {
                    $this->addDirect($direct, $dependency, $value, self::DEPENDENCY_SECTIONS[$dependencySection]);
                    $dependency = null;
                }
            } elseif ($dependencySection !== null && $dependency !== null && $indent === 4 + $depth && str_starts_with($text, 'version:')) {
                $this->addDirect($direct, $dependency, trim(substr($text, strlen('version:'))), self::DEPENDENCY_SECTIONS[$dependencySection]);
            }
        }

        $readings = [];

        foreach ($packages as $key => $devFlag) {
            $key = (string) $key;
            $isDirect = array_key_exists($key, $direct);
            ['name' => $name, 'version' => $version] = $this->split($key);
            // The dev flag of v5/v6, or a direct dev dependency in v9.
            $readings[] = new PackageReading(Ecosystem::Npm, $name, JsonFile::version($version), $devFlag || ($isDirect && $direct[$key]), $isDirect);
        }

        return PackageReading::merge($readings);
    }

    /**
     * "name@version" without peer suffix, or null for git, tarball and link entries.
     */
    private function packageKey(string $key, bool $atSign): ?string
    {
        $key = ltrim($this->unquote($key), '/');
        $pattern = $atSign
            ? '#^(@[^/@]+/[^@]+|[^@/][^@]*)@(\d[^(_/\s]*)#'
            : '#^(@[^/]+/[^/]+|[^@/][^/]*)/(\d[^_/()\s]*)#';

        return preg_match($pattern, $key, $match) === 1 ? "{$match[1]}@{$match[2]}" : null;
    }

    /**
     * @param  array<string, bool>  $direct
     */
    private function addDirect(array &$direct, string $name, string $version, bool $dev): void
    {
        $version = $this->unquote($version);

        if (preg_match('/^(\d[^(_\s]*)/', $version, $match) === 1) {
            $key = "{$name}@{$match[1]}";
            // Listed in dependencies anywhere: not only for development.
            $direct[$key] = ($direct[$key] ?? true) && $dev;
        }
    }

    /**
     * @return array{name: string, version: string}
     */
    private function split(string $key): array
    {
        $at = (int) strrpos($key, '@');

        return ['name' => substr($key, 0, $at), 'version' => substr($key, $at + 1)];
    }

    private function unquote(string $value): string
    {
        return trim($value, '\'"');
    }
}
