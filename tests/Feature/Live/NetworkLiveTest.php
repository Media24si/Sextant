<?php

use App\Net\DnsResolver;
use App\Net\RdapClient;
use App\Net\Real\IanaRdapClient;
use App\Net\Real\NetDnsResolver;
use App\Net\Real\StreamTlsProbe;
use App\Net\TlsProbe;

/*
 * Real network, excluded from the default run: `php artisan test --group=live`.
 */
beforeEach(function () {
    app()->instance(DnsResolver::class, app(NetDnsResolver::class));
    app()->instance(TlsProbe::class, app(StreamTlsProbe::class));
    app()->instance(RdapClient::class, app(IanaRdapClient::class));
    Http::allowStrayRequests();
});

it('resolves svet24.si', function () {
    $answer = app(DnsResolver::class)->resolve('svet24.si');

    expect($answer->exists)->toBeTrue()->and($answer->ips())->not->toBeEmpty();
    expect(app(DnsResolver::class)->ns('svet24.si'))->not->toBeEmpty();
})->group('live');

it('reads the svet24.si certificate', function () {
    $tls = app(TlsProbe::class)->inspect('svet24.si');

    expect($tls->verified)->toBeTrue()->and($tls->certificate?->expiresAt->isFuture())->toBeTrue();
})->group('live');

it('finds the svet24.si expiry date over RDAP', function () {
    $domain = app(RdapClient::class)->domain('svet24.si', fresh: true);

    expect($domain?->found)->toBeTrue()->and($domain?->expiresAt?->isFuture())->toBeTrue();
})->group('live');
