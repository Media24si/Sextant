<?php

namespace App\Data\Detection;

use Spatie\LaravelData\Data;

/**
 * What `SiteDetector` found for a URL (SPEC §10.1). Nothing is saved.
 *
 * SPEC: `display_hostname`, `scheme` and `port` aren't in §10.1, but the page
 * shows the Unicode name and saving needs the other two. For a duplicate only
 * `duplicate` is filled; the findings are null.
 */
class SiteDetectionData extends Data
{
    public function __construct(
        public string $input,
        public string $hostname,
        public string $display_hostname,
        public string $scheme,
        public ?int $port,
        public string $path,
        public ?DuplicateSiteData $duplicate,
        public ?DomainFindingData $domain,
        public ?DnsFindingData $dns,
        public ?FrontFindingData $front,
        public ?BackendFindingData $backend,
        /** @var list<BackendCandidateData> */
        public array $backend_candidates,
        /** An address that matches nothing we know; offers a new server or external service. */
        public ?string $unknown_ip,
        public ?ProbeSummaryData $probe,
        /** e.g. `www.oglasi24.si` when the URL redirects there. */
        public ?string $offer_www,
        /** @var list<ProposedMonitorData> */
        public array $monitors,
    ) {}
}
