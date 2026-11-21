<?php

use App\Checks\Conditions\PortIsOpen;
use App\Checks\Conditions\UsesHttps;
use App\Checks\Domain\DomainExpirationChecker;
use App\Checks\Server\TcpChecker;
use App\Checks\Site\HttpChecker;
use App\Checks\Site\ServingPathChecker;
use App\Checks\Site\SslCertificateChecker;

/*
 * The checker registry (SPEC §4.3). Keys are `{type}.{name}`; the prefix must
 * match the class's base type (`sextant:checkers` validates this).
 *
 * SPEC: §4.3 also lists server.ping, server.versions, domain.nameservers and
 * site.redirect. Their classes arrive in Phases 7 and 8; each is added here
 * with its class, and `monitors:sync-defaults --checker=…` attaches it to
 * existing resources.
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

        'domain.expiration' => [
            'class' => DomainExpirationChecker::class,
            'queue' => 'checks-slow',
            'interval' => 1440,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['warning_days' => 30, 'error_days' => 7],
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

    ],

    // Monitors attached when a resource is created (§4.4).
    'defaults' => [

        'server' => [
            ['checker' => 'server.tcp', 'label' => 'SSH', 'arguments' => ['port' => 22], 'only_if' => PortIsOpen::class],
            ['checker' => 'server.tcp', 'label' => 'HTTP', 'arguments' => ['port' => 80], 'only_if' => PortIsOpen::class],
            ['checker' => 'server.tcp', 'label' => 'HTTPS', 'arguments' => ['port' => 443], 'only_if' => PortIsOpen::class],
        ],

        'domain' => [
            ['checker' => 'domain.expiration'],
        ],

        'site' => [
            ['checker' => 'site.http'],
            ['checker' => 'site.ssl', 'only_if' => UsesHttps::class],
            ['checker' => 'site.path'],
        ],

    ],

];
