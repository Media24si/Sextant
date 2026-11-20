<?php

namespace App\Console\Commands;

use App\Detection\PublicSuffixList;
use App\Net\RdapClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** Weekly: refresh the IANA RDAP bootstrap and the Public Suffix List (SPEC §14). */
#[Signature('rdap:refresh-bootstrap')]
#[Description('Download the IANA RDAP bootstrap and the Public Suffix List')]
class RefreshRdapBootstrap extends Command
{
    public function handle(RdapClient $rdap, PublicSuffixList $suffixes): int
    {
        $failed = false;

        foreach (['RDAP bootstrap' => fn () => $rdap->refreshBootstrap(), 'Public Suffix List' => fn () => $suffixes->refresh()] as $name => $refresh) {
            try {
                $refresh();
                $this->components->twoColumnDetail($name, 'updated');
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->twoColumnDetail($name, "<fg=red>kept the previous copy: {$exception->getMessage()}</>");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
