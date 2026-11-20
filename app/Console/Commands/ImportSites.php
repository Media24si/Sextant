<?php

namespace App\Console\Commands;

use App\Actions\Sites\ImportSite;
use App\Enums\ImportOutcome;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Bulk import (SPEC §10.6): one URL per line; empty lines and lines starting
 * with # are ignored.
 */
#[Signature('sextant:import {file : A text file with one URL per line}')]
#[Description('Detect and add the sites listed in a file')]
class ImportSites extends Command
{
    public function handle(ImportSite $importSite): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->components->error("Can't read {$file}.");

            return self::FAILURE;
        }

        $urls = collect(file($file, FILE_IGNORE_NEW_LINES) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
            ->unique()
            ->values();

        $results = [];

        foreach ($urls as $url) {
            $result = $importSite->handle($url);
            $results[] = $result;

            $label = match ($result->outcome) {
                ImportOutcome::Created => '<fg=green>created</>',
                ImportOutcome::NeedsReview => '<fg=yellow>needs review</>',
                ImportOutcome::Skipped => '<fg=gray>skipped</>',
            };

            $this->components->twoColumnDetail(($result->hostname ?? $url).' <fg=gray>'.$result->message.'</>', $label);
        }

        $counts = collect($results)->countBy(fn ($result) => $result->outcome->value);

        $this->newLine();
        $this->components->info(sprintf(
            '%d created, %d need review, %d skipped.',
            $counts->get(ImportOutcome::Created->value, 0),
            $counts->get(ImportOutcome::NeedsReview->value, 0),
            $counts->get(ImportOutcome::Skipped->value, 0),
        ));

        return self::SUCCESS;
    }
}
