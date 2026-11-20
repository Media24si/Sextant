<?php

namespace App\Console\Commands;

use App\Detection\PublishedRanges;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Daily: download the IP ranges that Cloudflare, Fastly and CloudFront publish (SPEC §14). */
#[Signature('proxies:refresh')]
#[Description('Download the published IP ranges of external services')]
class RefreshProxyRanges extends Command
{
    public function handle(PublishedRanges $ranges): int
    {
        $failed = false;

        foreach ($ranges->refresh() as $source => $result) {
            if (is_int($result)) {
                $this->components->twoColumnDetail($source, "{$result} ranges");
            } else {
                $failed = true;
                $this->components->twoColumnDetail($source, "<fg=red>kept the previous ranges: {$result}</>");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
