<?php

namespace App\Packages\LockFiles;

use App\Enums\Ecosystem;
use App\Packages\PackageReading;

/** `composer.lock`: `packages` and `packages-dev`; `composer.json` says which are direct. */
class ComposerLock implements LockFile
{
    public function manifest(): ?string
    {
        return 'composer.json';
    }

    public function parse(string $contents, ?string $manifest = null): array
    {
        $lock = JsonFile::decode($contents, 'composer.lock');

        if (! isset($lock['packages']) || ! is_array($lock['packages'])) {
            throw new InvalidLockFile('composer.lock has no packages list');
        }

        $direct = [];

        if ($manifest !== null) {
            $json = JsonFile::decode($manifest, 'composer.json');

            foreach (['require', 'require-dev'] as $section) {
                foreach (array_keys((array) ($json[$section] ?? [])) as $name) {
                    $direct[strtolower((string) $name)] = true;
                }
            }
        }

        $readings = [];

        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            foreach ((array) ($lock[$section] ?? []) as $package) {
                if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) {
                    continue;
                }

                $name = strtolower($package['name']);
                $readings[] = new PackageReading(Ecosystem::Composer, $name, JsonFile::version($package['version']), $dev, isset($direct[$name]));
            }
        }

        return PackageReading::merge($readings);
    }
}
