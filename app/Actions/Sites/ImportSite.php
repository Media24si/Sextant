<?php

namespace App\Actions\Sites;

use App\Data\Import\ImportResultData;
use App\Detection\InvalidUrl;
use App\Detection\SiteDetector;
use App\Enums\Confidence;
use App\Enums\ImportOutcome;

/**
 * Bulk import of one URL (SPEC §10.6): detect, then create the site with what
 * was detected. A backend that is only suggested, or not found, is left
 * unassigned so the site shows under "Needs review". Duplicates are skipped.
 */
class ImportSite
{
    public function __construct(
        private SiteDetector $detector,
        private AddSites $addSites,
    ) {}

    public function handle(string $input): ImportResultData
    {
        $input = trim($input);

        try {
            $detection = $this->detector->detect($input);
        } catch (InvalidUrl $exception) {
            return new ImportResultData($input, null, ImportOutcome::Skipped, $exception->getMessage(), null);
        }

        if ($detection->duplicate !== null) {
            return new ImportResultData(
                $input,
                $detection->display_hostname,
                ImportOutcome::Skipped,
                $detection->duplicate->trashed ? 'Was a site and was deleted; restore it instead' : 'Already a site',
                $detection->duplicate->site_id,
            );
        }

        $front = $detection->front?->confidence === Confidence::Detected ? $detection->front->value : null;
        $backend = $detection->backend?->confidence === Confidence::Detected ? $detection->backend->value : null;
        $redirect = collect($detection->monitors)->firstWhere('checker', 'site.redirect');
        $expectedTarget = $redirect?->arguments['expected_target'] ?? null;

        $site = $this->addSites->handle((string) $detection->domain?->value->name, [[
            'hostname' => $detection->hostname,
            'scheme' => $detection->scheme,
            'port' => $detection->port,
            'path' => $detection->path,
            'front_service_id' => $front?->service_id,
            'server_id' => $backend?->server_id,
            'expected_target' => is_string($expectedTarget) ? $expectedTarget : null,
        ]], spread: true)[0];

        if ($backend !== null) {
            return new ImportResultData(
                $input,
                $detection->display_hostname,
                ImportOutcome::Created,
                implode(' → ', array_filter([$front?->name, $backend->name])),
                $site->id,
            );
        }

        $suggested = $detection->backend?->confidence === Confidence::Suggested ? $detection->backend->value?->name : null;

        return new ImportResultData(
            $input,
            $detection->display_hostname,
            ImportOutcome::NeedsReview,
            'Backend not assigned'.($suggested !== null ? " (suggested: {$suggested})" : ''),
            $site->id,
        );
    }
}
