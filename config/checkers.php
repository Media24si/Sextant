<?php

use App\Checks\Conditions\HasVersionsEndpoint;
use App\Checks\Conditions\PortIsOpen;
use App\Checks\Conditions\RespondsToPing;
use App\Checks\Conditions\UsesHttps;
use App\Checks\Domain\DomainExpirationChecker;
use App\Checks\Domain\NameserversChecker;
use App\Checks\Repository\PackagesChecker;
use App\Checks\Server\PingChecker;
use App\Checks\Server\TcpChecker;
use App\Checks\Server\VersionsChecker;
use App\Checks\Site\HttpChecker;
use App\Checks\Site\RedirectChecker;
use App\Checks\Site\ServingPathChecker;
use App\Checks\Site\SslCertificateChecker;

/*
 * The checker registry (SPEC §4.3). Keys are `{type}.{name}`; the prefix must
 * match the class's base type (`sextant:checkers` validates this).
 */
return [

    'checkers' => [

        'server.tcp' => [
            'class' => TcpChecker::class,
            'queue' => 'checks',
            'interval' => 1,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['port' => 443, 'address' => null, 'timeout' => 5],
        ],

        'server.ping' => [
            'class' => PingChecker::class,
            'queue' => 'checks',
            'interval' => 5,
            'failures_before_incident' => 3,
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => ['count' => 3, 'timeout' => 5],
        ],

        'server.versions' => [
            'class' => VersionsChecker::class,
            'queue' => 'checks-slow',
            'interval' => 1440,
            'failures_before_incident' => 1,   // deterministic: a version is below the minimum or it isn't
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => [],
        ],

        'domain.expiration' => [
            'class' => DomainExpirationChecker::class,
            'queue' => 'checks-slow',
            'interval' => 1440,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['warning_days' => 30, 'error_days' => 7],
        ],

        'domain.nameservers' => [
            'class' => NameserversChecker::class,
            'queue' => 'checks',
            'interval' => 60,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['expected' => [], 'check_soa' => true],
        ],

        'site.http' => [
            'class' => HttpChecker::class,
            'queue' => 'checks',
            'interval' => 1,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => [
                'path' => '/', 'expected_status' => [200], 'timeout' => 10,
                'slow_ms' => 5000, 'verify_tls' => true, 'contains' => null,
            ],
        ],

        'site.ssl' => [
            'class' => SslCertificateChecker::class,
            'queue' => 'checks',
            'interval' => 720,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['warning_days' => 30, 'error_days' => 7, 'port' => 443],
        ],

        'site.path' => [
            'class' => ServingPathChecker::class,
            'queue' => 'checks',
            'interval' => 15,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => [],
        ],

        'repository.packages' => [
            'class' => PackagesChecker::class,
            'queue' => 'checks-slow',
            'interval' => 1440,
            'failures_before_incident' => 1,   // deterministic: a package is below the minimum or it isn't
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => [],
        ],

        'site.redirect' => [
            'class' => RedirectChecker::class,
            'queue' => 'checks',
            'interval' => 60,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => ['path' => '/', 'expected_target' => null, 'max_redirects' => 5],
        ],

    ],

    // Monitors attached when a resource is created (§4.4).
    'defaults' => [

        'server' => [
            ['checker' => 'server.tcp', 'label' => 'SSH', 'arguments' => ['port' => 22], 'only_if' => PortIsOpen::class],
            ['checker' => 'server.tcp', 'label' => 'HTTP', 'arguments' => ['port' => 80], 'only_if' => PortIsOpen::class],
            ['checker' => 'server.tcp', 'label' => 'HTTPS', 'arguments' => ['port' => 443], 'only_if' => PortIsOpen::class],
            ['checker' => 'server.ping', 'only_if' => RespondsToPing::class],
            ['checker' => 'server.versions', 'only_if' => HasVersionsEndpoint::class],
        ],

        'domain' => [
            ['checker' => 'domain.expiration'],
            ['checker' => 'domain.nameservers'],
        ],

        'site' => [
            ['checker' => 'site.http'],
            ['checker' => 'site.ssl', 'only_if' => UsesHttps::class],
            ['checker' => 'site.path'],
            ['checker' => 'site.redirect'],
        ],

        'repository' => [
            ['checker' => 'repository.packages'],
        ],

    ],

];
