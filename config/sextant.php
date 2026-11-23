<?php

return [
    'display_timezone' => 'Europe/Ljubljana',
    'user_agent' => 'Sextant/1.0 (Media24 monitoring)',

    'network_canary' => [
        'tcp' => [['1.1.1.1', 443], ['8.8.8.8', 443], ['9.9.9.9', 443]],
        'dns_probe_name' => 'one.one.one.one',
        'min_healthy' => 2,
        'timeout' => 3,
        'cache_seconds' => 60,
    ],

    'dns' => [
        'resolvers' => ['1.1.1.1', '8.8.8.8'],   // public resolvers, not the monitored infra's
        'timeout' => 3,
    ],

    'stale' => ['multiplier' => 2, 'grace_minutes' => 5],
    'runs' => ['retention_days' => 90],

    'notifications' => [
        'discord_webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'settle_seconds' => 20,
        'reminder_hours' => 4,
        'notify_acknowledgements' => true,
        'max_embeds_per_message' => 10,
        'rate_per_minute' => 20,
    ],

    'grouping' => [
        'parent_checkers' => ['server.tcp'],
        'adopt_window_minutes' => 15,
        'detach_after_minutes' => 10,
    ],

    // Published ranges for external services with a ranges_source (§3.5).
    'range_sources' => [
        'cloudflare' => ['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'],
        'fastly' => ['https://api.fastly.com/public-ip-list'],
        'cloudfront' => ['https://ip-ranges.amazonaws.com/ip-ranges.json#CLOUDFRONT'],
    ],

    'detection' => [
        'backend_header' => env('SEXTANT_BACKEND_HEADER'),   // e.g. 'X-Backend'; null = off (§10.3)
        'timeout_seconds' => 15,
    ],

    'versions' => [
        'software' => ['os', 'kernel', 'nginx', 'php', 'mysql', 'openssl'],
        'stale_hours' => 26,
        'timeout' => 10,
        'max_bytes' => 262144,
        'token_prefix' => 'sxt_',
        'default_path' => '/.sextant/versions.php',
        'file_path' => '/var/lib/sextant/versions.json',
        'endpoint_file' => '/var/www/sextant/versions.php',
        'install_link_minutes' => 30,
    ],

    // Lock files read from GitHub with read-only tokens (§11.9).
    'github' => [
        'api_url' => 'https://api.github.com',
        'api_version' => '2022-11-28',
        'timeout' => 15,
        'max_bytes' => 20 * 1024 * 1024,
        'expiry_warning_days' => 14,
        // Read from the root of the default branch, in this order.
        'lock_files' => ['composer.lock', 'package-lock.json', 'pnpm-lock.yaml'],
    ],

    'whois' => [
        'expiry_patterns' => [
            '/Registry Expiry Date:\s*(.+)/i',
            '/Expiration Date:\s*(.+)/i',
            '/expire:\s*(.+)/i',
            '/paid-till:\s*(.+)/i',
        ],
    ],
];
