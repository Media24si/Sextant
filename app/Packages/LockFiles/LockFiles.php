<?php

namespace App\Packages\LockFiles;

/** The lock files Sextant reads, by file name (SPEC §11.9). */
final class LockFiles
{
    private const FORMATS = [
        'composer.lock' => ComposerLock::class,
        'package-lock.json' => NpmLock::class,
        'pnpm-lock.yaml' => PnpmLock::class,
    ];

    public static function for(string $file): ?LockFile
    {
        $class = self::FORMATS[$file] ?? null;

        return $class === null ? null : new $class;
    }

    /**
     * The supported lock files among `$files`, in the configured order.
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    public static function among(array $files): array
    {
        $wanted = array_filter((array) config('sextant.github.lock_files'), fn (mixed $file): bool => is_string($file) && isset(self::FORMATS[$file]));

        return array_values(array_intersect($wanted, $files));
    }
}
