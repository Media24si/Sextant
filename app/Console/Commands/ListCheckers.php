<?php

namespace App\Console\Commands;

use App\Checks\CheckerRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Lists the registry and validates it (SPEC §4.3, §14). */
#[Signature('sextant:checkers')]
#[Description('List the registered checkers and validate config/checkers.php')]
class ListCheckers extends Command
{
    public function handle(CheckerRegistry $registry): int
    {
        $this->table(
            ['Key', 'Name', 'Queue', 'Every', 'Fail × / Pass ×', 'Max'],
            collect($registry->entries())->map(fn (array $entry, string $key): array => [
                $key,
                $registry->name($key),
                $entry['queue'],
                $entry['interval'].' min',
                $entry['failures_before_incident'].' / '.$entry['successes_before_resolve'],
                $entry['max_severity'] ?? 'error',
            ])->values()->all(),
        );

        $errors = $registry->validate();

        if ($errors === []) {
            $this->components->info('The registry is valid.');

            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $this->components->error($error);
        }

        return self::FAILURE;
    }
}
