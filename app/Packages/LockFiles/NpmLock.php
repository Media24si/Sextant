<?php

namespace App\Packages\LockFiles;

use App\Enums\Ecosystem;
use App\Packages\PackageReading;

/**
 * `package-lock.json`. Versions 2 and 3 list every installed path under
 * `packages`, with the project itself under `""`; version 1 nests
 * `dependencies` and needs `package.json` for the direct ones.
 */
class NpmLock implements LockFile
{
    private const DEPENDENCY_SECTIONS = ['dependencies', 'devDependencies', 'optionalDependencies'];

    public function manifest(): ?string
    {
        return 'package.json';
    }

    public function parse(string $contents, ?string $manifest = null): array
    {
        $lock = JsonFile::decode($contents, 'package-lock.json');

        if (isset($lock['packages']) && is_array($lock['packages'])) {
            return PackageReading::merge($this->fromPackages($lock['packages']));
        }

        if (isset($lock['dependencies']) && is_array($lock['dependencies'])) {
            $direct = $manifest === null ? [] : $this->directNames(JsonFile::decode($manifest, 'package.json'));

            return PackageReading::merge($this->fromDependencies($lock['dependencies'], $direct, true));
        }

        throw new InvalidLockFile('package-lock.json has neither packages nor dependencies');
    }

    /**
     * @param  array<mixed>  $packages
     * @return list<PackageReading>
     */
    private function fromPackages(array $packages): array
    {
        $direct = is_array($packages[''] ?? null) ? $this->directNames($packages['']) : [];
        $readings = [];

        foreach ($packages as $path => $package) {
            $path = (string) $path;
            $position = strrpos($path, 'node_modules/');

            if ($position === false || ! is_array($package) || ($package['link'] ?? false) === true || ! is_string($package['version'] ?? null)) {
                continue;
            }

            $installedAs = substr($path, $position + strlen('node_modules/'));
            // An alias (`"x": "npm:real@1.2.3"`) is installed under its alias with the real name beside it.
            $name = is_string($package['name'] ?? null) ? $package['name'] : $installedAs;
            $topLevel = $path === "node_modules/{$installedAs}";

            $readings[] = new PackageReading(Ecosystem::Npm, $name, JsonFile::version($package['version']), ($package['dev'] ?? false) === true, $topLevel && isset($direct[$installedAs]));
        }

        return $readings;
    }

    /**
     * @param  array<mixed>  $dependencies
     * @param  array<string, true>  $direct
     * @return list<PackageReading>
     */
    private function fromDependencies(array $dependencies, array $direct, bool $topLevel): array
    {
        $readings = [];

        foreach ($dependencies as $name => $package) {
            if (! is_array($package) || ! is_string($package['version'] ?? null)) {
                continue;
            }

            $installedAs = (string) $name;
            $version = $package['version'];

            // v1 writes aliases as `npm:real@1.2.3`.
            if (preg_match('/^npm:(@?[^@]+)@(.+)$/', $version, $alias) === 1) {
                [$name, $version] = [$alias[1], $alias[2]];
            }

            $readings[] = new PackageReading(Ecosystem::Npm, (string) $name, JsonFile::version($version), ($package['dev'] ?? false) === true, $topLevel && isset($direct[$installedAs]));

            if (is_array($package['dependencies'] ?? null)) {
                array_push($readings, ...$this->fromDependencies($package['dependencies'], $direct, false));
            }
        }

        return $readings;
    }

    /**
     * @param  array<mixed>  $project  package.json, or the `""` entry of a v2/v3 lock
     * @return array<string, true>
     */
    private function directNames(array $project): array
    {
        $names = [];

        foreach (self::DEPENDENCY_SECTIONS as $section) {
            foreach (array_keys((array) ($project[$section] ?? [])) as $name) {
                $names[(string) $name] = true;
            }
        }

        return $names;
    }
}
