<?php

namespace App\Actions\Sites;

use App\Data\Import\ImportResultData;
use App\Detection\InvalidUrl;
use App\Detection\SiteDetector;
use App\Enums\Confidence;
use App\Enums\ImportOutcome;
use App\Models\Application;
use App\Models\Server;
use App\Models\Site;

/**
 * Bulk import of one URL (SPEC §10.6): detect, then create the site with what
 * was detected. A backend that is only suggested, or not found, takes the
 * backend chosen for the whole import, or is left unassigned so the site shows
 * under "Needs review". Duplicates are skipped; with an application chosen,
 * an existing site joins it.
 */
class ImportSite
{
    public function __construct(
        private SiteDetector $detector,
        private AddSites $addSites,
    ) {}

    /**
     * @param  Application|null  $application  every site of this import belongs to it (SPEC §3.19)
     * @param  Server|null  $fallbackBackend  for sites whose backend isn't Detected
     */
    public function handle(string $input, ?Application $application = null, ?Server $fallbackBackend = null): ImportResultData
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
                $detection->duplicate->trashed
                    ? 'Was a site and was deleted; restore it instead'
                    : $this->joinApplication($detection->duplicate->site_id, $application),
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
            'application_id' => $application?->id,
            'front_service_id' => $front?->service_id,
            'server_id' => $backend->server_id ?? $fallbackBackend?->id,
            'expected_target' => is_string($expectedTarget) ? $expectedTarget : null,
        ]], spread: true)[0];

        if ($backend !== null || $fallbackBackend !== null) {
            return new ImportResultData(
                $input,
                $detection->display_hostname,
                ImportOutcome::Created,
                implode(' → ', array_filter([$front?->name, $backend->name ?? "{$fallbackBackend?->name} (chosen for all)"])),
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

    /**
     * Pasting an app's hostnames again groups the ones already added.
     */
    private function joinApplication(int $siteId, ?Application $application): string
    {
        if ($application === null) {
            return 'Already a site';
        }

        $site = Site::query()->findOrFail($siteId);

        if ($site->application_id === $application->id) {
            return "Already a site in {$application->name}";
        }

        $site->update(['application_id' => $application->id]);

        return "Already a site; added to {$application->name}";
    }
}
