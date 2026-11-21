<?php

namespace App\Console\Commands;

use App\Actions\Sites\ImportSite;
use App\Enums\ImportOutcome;
use App\Models\Application;
use App\Support\ImportLines;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Bulk import (SPEC §10.6): URLs or hostnames, one or more per line, or nginx
 * `server_name` lines; empty lines and # comments are ignored.
 */
#[Signature('sextant:import {file : A text file with one URL per line} {--application= : Add every site to this application, created if new}')]
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

        $urls = ImportLines::parse((string) file_get_contents($file));
        $name = trim((string) $this->option('application'));
        $application = $name === '' ? null : Application::query()->firstOrCreate(['name' => $name]);

        $results = [];

        foreach ($urls as $url) {
            $result = $importSite->handle($url, $application);
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
