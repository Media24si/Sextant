<?php

namespace App\Packages\LockFiles;

/** Helpers shared by the JSON lock files. */
final class JsonFile
{
    /**
     * @return array<mixed>
     *
     * @throws InvalidLockFile
     */
    public static function decode(string $contents, string $name): array
    {
        $data = json_decode($contents, true);

        if (! is_array($data)) {
            throw new InvalidLockFile("{$name} is not valid JSON");
        }

        return $data;
    }

    /** Fits the 50-character column; real versions are far shorter. */
    public static function version(string $version): string
    {
        return mb_strimwidth(trim($version), 0, 50, '…');
    }
}
