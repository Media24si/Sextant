<?php

namespace Tests;

use App\Net\Fake\FakeDnsResolver;
use App\Net\Fake\FakeHttpProbe;
use App\Net\Fake\FakePingProbe;
use App\Net\Fake\FakeRdapClient;
use App\Net\Fake\FakeTcpProbe;
use App\Net\Fake\FakeTlsProbe;
use App\Net\Fake\FakeWhoisClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * No live network in the default suite (SPEC §17): every network wrapper
     * starts as an empty fake, stray HTTP requests fail and the default disk is
     * a temporary one. Tests in the
     * `live` group swap the real ones back in.
     */
    protected function setUp(): void
    {
        parent::setUp();

        FakeDnsResolver::install();
        FakeHttpProbe::install();
        FakeTlsProbe::install();
        FakeTcpProbe::install();
        FakePingProbe::install();
        FakeRdapClient::install();
        FakeWhoisClient::install();
        Http::preventStrayRequests();
        Storage::fake();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
