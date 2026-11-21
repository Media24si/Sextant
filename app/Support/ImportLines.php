<?php

namespace App\Support;

/**
 * The hostnames or URLs in pasted import text (SPEC §10.6): one or more per
 * line, separated by spaces. An nginx `server_name a b c;` line counts as its
 * names. Comments, `_`, wildcards, regexes and variables are left out.
 * Mirrored by `resources/js/lib/importLines.ts`.
 */
class ImportLines
{
    /**
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));
            $tokens = preg_split('/\s+/', rtrim($line, "; \t"), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (($tokens[0] ?? null) === 'server_name') {
                array_shift($tokens);
            }

            foreach ($tokens as $token) {
                $token = rtrim($token, ';');

                if ($token !== '' && ! self::isPattern($token)) {
                    $entries[] = $token;
                }
            }
        }

        return array_values(array_unique($entries));
    }

    private static function isPattern(string $token): bool
    {
        return $token === '_'
            || str_starts_with($token, '~')
            || str_starts_with($token, '*')
            || str_starts_with($token, '.')
            || str_starts_with($token, '$')
            || str_ends_with($token, '.*');
    }
}
