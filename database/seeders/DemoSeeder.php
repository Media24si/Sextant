<?php

namespace Database\Seeders;

use App\Enums\ExternalServiceKind;
use App\Enums\Software;
use App\Enums\UserRole;
use App\Models\Application;
use App\Models\Choice;
use App\Models\Domain;
use App\Models\ExternalService;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MinimumVersion;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Monitoring\DefaultMonitors;
use App\Monitoring\IncidentManager;
use App\Monitoring\MonitorClaimer;
use App\Net\Fake\FakeDnsResolver;
use App\Net\Fake\FakeHttpProbe;
use App\Net\Fake\FakePingProbe;
use App\Net\Fake\FakeRdapClient;
use App\Net\Fake\FakeTcpProbe;
use App\Net\Fake\FakeTlsProbe;
use App\Net\Fake\FakeVersionsClient;
use App\Net\Fake\FakeWhoisClient;
use App\Net\Results\RdapDomain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Fictional demo data for the README screenshots: `php artisan db:seed --class=DemoSeeder`
 * on an empty, migrated database. Nothing touches the network: every probe is a fake,
 * and the monitors run through the real pipeline over the last three days, so states,
 * incidents and the change feed are what Sextant itself would record.
 *
 * All addresses are from the documentation ranges (RFC 5737, RFC 3849).
 */
class DemoSeeder extends Seeder
{
    /** Minutes between simulated dispatch ticks; the last hour runs every minute. */
    private const TICK_MINUTES = 20;

    private CarbonImmutable $end;

    private User $admin;

    private bool $moved = false;

    private bool $muted = false;

    /** @var array<string, Server> */
    private array $servers = [];

    /** @var array<string, Site> */
    private array $sites = [];

    /**
     * Servers: addresses, open ports, whether they answer ping, and what the versions collector reports.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SERVERS = [
        'web01' => ['ip' => '203.0.113.10', 'provider' => 'Hetzner', 'os' => 'Ubuntu 24.04 LTS', 'ports' => [22, 80, 443], 'ping' => true, 'nginx' => '1.26.3', 'php' => ['8.3' => '8.3.14', '8.4' => '8.4.2'], 'mysql' => null, 'kernel' => '6.8.0-51-generic', 'pretty' => 'Ubuntu 24.04.1 LTS', 'collector' => 5],
        'web02' => ['ip' => '203.0.113.11', 'provider' => 'Hetzner', 'os' => 'Ubuntu 24.04 LTS', 'ports' => [22, 80, 443], 'ping' => true, 'nginx' => '1.26.3', 'php' => ['8.3' => '8.3.14'], 'mysql' => null, 'kernel' => '6.8.0-51-generic', 'pretty' => 'Ubuntu 24.04.1 LTS', 'collector' => 5],
        'web03' => ['ip' => '198.51.100.20', 'provider' => 'Arnes', 'os' => 'Ubuntu 22.04 LTS', 'ports' => [22, 80, 443], 'ping' => true, 'nginx' => '1.24.0', 'php' => ['8.2' => '8.2.26', '8.1' => '8.1.31'], 'mysql' => null, 'kernel' => '5.15.0-126-generic', 'pretty' => 'Ubuntu 22.04.5 LTS', 'collector' => 5],
        'db01' => ['ip' => '203.0.113.30', 'provider' => 'Hetzner', 'os' => 'Debian 12', 'ports' => [22], 'ping' => true, 'nginx' => null, 'php' => [], 'mysql' => '8.4.3', 'kernel' => '6.1.0-28-amd64', 'pretty' => 'Debian GNU/Linux 12 (bookworm)', 'collector' => 5],
        'legacy01' => ['ip' => '192.0.2.40', 'provider' => 'DigitalOcean', 'os' => 'Ubuntu 22.04 LTS', 'ports' => [22, 80, 443], 'ping' => true, 'nginx' => '1.26.2', 'php' => ['7.4' => '7.4.33'], 'mysql' => '8.0.40', 'kernel' => '5.15.0-122-generic', 'pretty' => 'Ubuntu 22.04.5 LTS', 'collector' => 3],
        'win01' => ['ip' => '198.51.100.50', 'provider' => null, 'os' => 'Windows Server 2022', 'ports' => [80, 443], 'ping' => false, 'nginx' => null, 'php' => [], 'mysql' => null, 'kernel' => null, 'pretty' => null, 'collector' => null],
    ];

    /**
     * Domains: registrar, days until expiry, nameservers.
     *
     * @var array<string, array{registrar: string, expires_in: int, ns: list<string>}>
     */
    private const DOMAINS = [
        'northwind.si' => ['registrar' => 'Domenca d.o.o.', 'expires_in' => 211, 'ns' => ['ns1.northwind-hosting.net', 'ns2.northwind-hosting.net']],
        'dnevni-glas.si' => ['registrar' => 'Domenca d.o.o.', 'expires_in' => 94, 'ns' => ['ada.ns.cloudflare.com', 'rob.ns.cloudflare.com']],
        'sportni-utrip.si' => ['registrar' => 'Neoserv d.o.o.', 'expires_in' => 302, 'ns' => ['ns1.northwind-hosting.net', 'ns2.northwind-hosting.net']],
        'receptnica.si' => ['registrar' => 'Neoserv d.o.o.', 'expires_in' => 158, 'ns' => ['ns1.northwind-hosting.net', 'ns2.northwind-hosting.net']],
        'vreme24.com' => ['registrar' => 'Gandi SAS', 'expires_in' => 47, 'ns' => ['ada.ns.cloudflare.com', 'rob.ns.cloudflare.com']],
        'kinospored.si' => ['registrar' => 'Domenca d.o.o.', 'expires_in' => 19, 'ns' => ['ns1.northwind-hosting.net', 'ns2.northwind-hosting.net']],
        'northwind-hosting.net' => ['registrar' => 'Gandi SAS', 'expires_in' => 530, 'ns' => ['ns1.northwind-hosting.net', 'ns2.northwind-hosting.net']],
    ];

    public function run(): void
    {
        $this->end = CarbonImmutable::now()->startOfMinute();
        $start = $this->end->subDays(3);

        config([
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'sextant.notifications.discord_webhook_url' => null,
        ]);
        Http::preventStrayRequests();

        $this->travel($start->subDays(18));
        $this->installFakes();
        $this->users();
        $this->inventory();

        for ($at = $start; $at->lt($this->end->subHour()); $at = $at->addMinutes(self::TICK_MINUTES)) {
            $this->tick($at);
        }

        for ($at = $this->end->subHour(); $at->lte($this->end); $at = $at->addMinute()) {
            $this->tick($at);
        }

        // Nothing was sent; don't leave a queue of Discord messages behind.
        IncidentEvent::query()->where('notify', true)->whereNull('notified_at')->update(['notified_at' => $this->end]);

        Auth::forgetUser();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    private function travel(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function users(): void
    {
        $this->admin = User::factory()->create([
            'name' => 'Ana Novak',
            'email' => 'admin@example.com',
            'role' => UserRole::Admin,
        ]);

        User::factory()->create(['name' => 'Luka Horvat', 'email' => 'luka@example.com', 'role' => UserRole::Admin]);
        User::factory()->create(['name' => 'Maja Kos', 'email' => 'reader@example.com', 'role' => UserRole::Reader]);
        User::factory()->create(['name' => 'Tim Zupan', 'email' => 'tim@example.com', 'role' => UserRole::Reader]);

        Auth::setUser($this->admin);
    }

    private function inventory(): void
    {
        $this->travel(CarbonImmutable::now()->addMinutes(5));

        foreach ([
            [Software::Nginx, ['1.26.2'], 'https://nginx.org/en/security_advisories.html', 'HTTP/3 and mp4 module fixes'],
            [Software::Php, ['8.2.26', '8.3.14'], 'https://www.php.net/ChangeLog-8.php#8.3.14', 'Older branches are below'],
        ] as [$software, $minimums, $cve, $note]) {
            $version = new MinimumVersion(['software' => $software, 'min_versions' => $minimums, 'cve_url' => $cve, 'note' => $note]);
            $version->forceFill(['set_by' => $this->admin->id])->save();
        }

        $cloudflare = ExternalService::query()->where('name', 'Cloudflare')->sole();
        $cloudflare->update(['ranges' => ['104.16.0.0/13', '172.64.0.0/13']]);

        $varnish = ExternalService::query()->create([
            'name' => 'Varnish edge',
            'kind' => ExternalServiceKind::Proxy,
            'ranges' => ['203.0.113.100', '203.0.113.101'],
            'response_headers' => ['x-varnish'],
            'notes' => 'Two Varnish caches in front of the sports sites.',
        ]);

        $this->travel(CarbonImmutable::now()->addHours(2));

        foreach (self::SERVERS as $name => $spec) {
            $this->travel(CarbonImmutable::now()->addMinutes(17));

            $server = Server::query()->create([
                'name' => $name,
                'hostname' => "{$name}.northwind-hosting.net",
                'provider' => $spec['provider'],
                'os_choice_id' => Choice::query()->where('label', $spec['os'])->value('id'),
                'notes' => match ($name) {
                    'legacy01' => 'Old PHP 7.4 apps. Migrate the archive to web02, then switch this off.',
                    'db01' => 'MySQL primary for web01 and web02.',
                    'win01' => 'IIS. Intranet only.',
                    default => null,
                },
            ]);
            $server->addresses()->create(['ip' => $spec['ip'], 'is_primary' => true]);

            if ($spec['collector'] !== null) {
                $server->forceFill([
                    'versions_url' => "https://{$name}.northwind-hosting.net/.sextant/versions.php",
                    'versions_token' => str_repeat('d', 36).'f3a9',
                    'versions_token_last4' => 'f3a9',
                ])->save();
            }

            $server->backupLocations()->sync(Choice::query()->whereIn('label', $name === 'db01' ? ['Backup server', 'Offsite cloud storage'] : ['Provider snapshots'])->pluck('id'));
            app(DefaultMonitors::class)->attach($server->fresh());
            $this->servers[$name] = $server->fresh();
        }

        $this->travel(CarbonImmutable::now()->addDay());

        $applications = [
            'dnevni-glas' => Application::query()->create(['name' => 'Dnevni glas', 'description' => 'News portal, WordPress multisite', 'github_url' => 'https://github.com/northwind-media/dnevni-glas']),
            'utrip' => Application::query()->create(['name' => 'Športni utrip', 'description' => 'Sports news and live scores', 'github_url' => 'https://github.com/northwind-media/sportni-utrip']),
            'northwind' => Application::query()->create(['name' => 'Northwind web', 'description' => 'Company site and public API', 'github_url' => 'https://github.com/northwind-media/web']),
        ];

        $domains = [];

        foreach (self::DOMAINS as $name => $spec) {
            $this->travel(CarbonImmutable::now()->addMinutes(9));
            $domains[$name] = Domain::query()->create(['name' => $name]);
            app(DefaultMonitors::class)->attach($domains[$name]);
        }

        $this->travel(CarbonImmutable::now()->addHours(3));

        foreach ($this->siteSpecs() as $hostname => $spec) {
            $this->travel(CarbonImmutable::now()->addMinutes(6));
            $site = Site::query()->create([
                'domain_id' => $domains[$spec['domain']]->id,
                'application_id' => isset($spec['app']) ? $applications[$spec['app']]->id : null,
                'hostname' => $hostname,
                'scheme' => $spec['scheme'] ?? 'https',
                'front_service_id' => match ($spec['front'] ?? null) {
                    'cloudflare' => $cloudflare->id,
                    'varnish' => $varnish->id,
                    default => null,
                },
                'server_id' => $spec['server'] === null ? null : $this->servers[$spec['server']]->id,
                'description' => $spec['description'] ?? null,
            ]);
            app(DefaultMonitors::class)->attach($site);
            $this->sites[$hostname] = $site;
        }
    }

    /**
     * @return array<string, array{domain: string, server: string|null, front?: string, app?: string, scheme?: string, description?: string, redirect?: string}>
     */
    private function siteSpecs(): array
    {
        return [
            'www.northwind.si' => ['domain' => 'northwind.si', 'server' => 'web02', 'app' => 'northwind', 'description' => 'Company site'],
            'northwind.si' => ['domain' => 'northwind.si', 'server' => 'web02', 'app' => 'northwind', 'redirect' => 'https://www.northwind.si/'],
            'api.northwind.si' => ['domain' => 'northwind.si', 'server' => 'web02', 'front' => 'cloudflare', 'app' => 'northwind', 'description' => 'Public JSON API'],
            'status.northwind.si' => ['domain' => 'northwind.si', 'server' => 'web01'],
            'www.dnevni-glas.si' => ['domain' => 'dnevni-glas.si', 'server' => 'web01', 'front' => 'cloudflare', 'app' => 'dnevni-glas'],
            'dnevni-glas.si' => ['domain' => 'dnevni-glas.si', 'server' => 'web01', 'front' => 'cloudflare', 'app' => 'dnevni-glas', 'redirect' => 'https://www.dnevni-glas.si/'],
            'm.dnevni-glas.si' => ['domain' => 'dnevni-glas.si', 'server' => 'web01', 'front' => 'cloudflare', 'app' => 'dnevni-glas'],
            'arhiv.dnevni-glas.si' => ['domain' => 'dnevni-glas.si', 'server' => 'legacy01', 'description' => 'Read-only archive, 2004–2015'],
            'www.sportni-utrip.si' => ['domain' => 'sportni-utrip.si', 'server' => 'web02', 'front' => 'varnish', 'app' => 'utrip'],
            'live.sportni-utrip.si' => ['domain' => 'sportni-utrip.si', 'server' => 'web02', 'front' => 'varnish', 'app' => 'utrip', 'description' => 'Live scores'],
            'www.receptnica.si' => ['domain' => 'receptnica.si', 'server' => 'web03'],
            'www.vreme24.com' => ['domain' => 'vreme24.com', 'server' => 'web02', 'front' => 'cloudflare'],
            'radar.vreme24.com' => ['domain' => 'vreme24.com', 'server' => null, 'front' => 'cloudflare'],
            'www.kinospored.si' => ['domain' => 'kinospored.si', 'server' => 'web03'],
            'intranet.northwind-hosting.net' => ['domain' => 'northwind-hosting.net', 'server' => 'win01', 'scheme' => 'http', 'description' => 'Staff intranet'],
        ];
    }

    /**
     * One dispatch tick: scripted inventory changes, fresh fakes for this moment, then every due monitor runs.
     */
    private function tick(CarbonImmutable $at): void
    {
        $this->travel($at);
        $this->scriptedChanges($at);
        $this->installFakes();

        app(MonitorClaimer::class)->dispatchDue(now()->addMinute());

        if ($at->equalTo($this->end->subMinutes(20))) {
            $incident = Incident::query()->open()->where('checker', 'server.versions')->first();

            if ($incident !== null) {
                app(IncidentManager::class)->acknowledge($incident, $this->admin);
            }
        }
    }

    private function scriptedChanges(CarbonImmutable $at): void
    {
        // The company site moved from web02 to web01 two days ago.
        if (! $this->moved && $at->gte($this->moveAt())) {
            $this->moved = true;
            $this->sites['www.northwind.si']->update(['server_id' => $this->servers['web01']->id]);
        }

        // Someone muted the intranet's monitors for planned IIS maintenance.
        if (! $this->muted && $at->gte($this->end->subHours(2))) {
            $this->muted = true;
            Monitor::query()
                ->where('monitorable_type', 'server')
                ->where('monitorable_id', $this->servers['win01']->id)
                ->get()
                ->each(fn (Monitor $monitor) => $monitor->update(['muted_until' => $this->end->addHours(6)]));
        }
    }

    private function moveAt(): CarbonImmutable
    {
        return $this->end->subDays(2)->setTime(9, 0);
    }

    /** A new set of fakes describing the world at the current (simulated) time. */
    private function installFakes(): void
    {
        $now = CarbonImmutable::now();
        $minutesAgo = fn (CarbonImmutable $moment): float => $moment->diffInMinutes($this->end, false);
        $ago = $minutesAgo($now);

        $dns = FakeDnsResolver::install();
        $http = FakeHttpProbe::install();
        $tls = FakeTlsProbe::install();
        $tcp = FakeTcpProbe::install()->openCanary();
        $ping = FakePingProbe::install();
        $rdap = FakeRdapClient::install();
        FakeWhoisClient::install();
        $versions = FakeVersionsClient::install();

        $dns->answer((string) config('sextant.network_canary.dns_probe_name'), ['192.0.2.1']);

        foreach (self::SERVERS as $name => $spec) {
            // legacy01 stopped answering SSH for about an hour yesterday.
            $sshDown = $name === 'legacy01' && $ago <= 26 * 60 && $ago > 25 * 60;

            foreach ($spec['ports'] as $port) {
                $sshDown && $port === 22 ? $tcp->timingOut($spec['ip'], $port) : $tcp->open($spec['ip'], $port);
            }

            if ($spec['ping']) {
                $ping->answers($spec['ip']);
            }

            if ($spec['collector'] !== null) {
                $versions->respond("https://{$name}.northwind-hosting.net/.sextant/versions.php", $this->versionsFile($name, $spec, $ago));
            }
        }

        $expirySeed = $this->end;

        foreach (self::DOMAINS as $name => $spec) {
            $rdap->record($name, new RdapDomain(
                found: true,
                server: 'rdap.'.(str_ends_with($name, '.si') ? 'register.si' : 'verisign.com'),
                expiresAt: $expirySeed->addDays($spec['expires_in'])->startOfDay(),
                registeredAt: $expirySeed->subYears(8)->subDays(crc32($name) % 300)->startOfDay(),
                registrar: $spec['registrar'],
                statuses: ['client transfer prohibited'],
                nameservers: $spec['ns'],
            ));
            $dns->nameservers($name, $spec['ns']);

            foreach ($spec['ns'] as $nameserver) {
                $dns->soaAnswer($name, $nameserver, 2026112001);
            }
        }

        foreach ($this->siteSpecs() as $hostname => $spec) {
            $server = $spec['server'];

            if ($hostname === 'www.northwind.si') {
                $server = $now->lt($this->moveAt()) ? 'web02' : 'web01';
            }

            // Sites without a front service all have a backend server.
            $ip = match ($spec['front'] ?? null) {
                'cloudflare' => '104.21.'.(crc32($hostname) % 200).'.'.(crc32($hostname) % 250 + 1),
                'varnish' => '203.0.113.100',
                default => self::SERVERS[$server ?? 'web01']['ip'],
            };
            $dns->answer($hostname, [$ip]);

            $scheme = $spec['scheme'] ?? 'https';
            $url = "{$scheme}://{$hostname}/";
            $ms = 90 + crc32($hostname.$now->format('YmdHi')) % 260;

            if ($hostname === 'live.sportni-utrip.si' && $ago <= 38) {
                $http->respond($url, 503, ['x-varnish' => '3221225'], ms: 41, ip: $ip);
            } elseif ($hostname === 'www.dnevni-glas.si' && $ago <= 9 * 60 && $ago > 7 * 60) {
                $http->respond($url, 200, ['cf-ray' => '8f2a1c'], ms: 6400 + $ms, ip: $ip);
            } elseif (isset($spec['redirect'])) {
                $http->respond($url, 200, [], ms: $ms, redirectsTo: $spec['redirect'], ip: $ip);
            } else {
                $http->respond($url, 200, [], ms: $ms, ip: $ip);
            }

            if ($scheme === 'https') {
                $expires = match ($hostname) {
                    'www.receptnica.si' => $expirySeed->addDays(12),
                    default => $expirySeed->addDays(30 + crc32($hostname) % 55),
                };
                $tls->valid($hostname, $hostname === 'api.northwind.si' || ($spec['front'] ?? null) === 'cloudflare' ? 'Google Trust Services' : "Let's Encrypt", $expires->setTime(12, 0)->toIso8601String());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function versionsFile(string $name, array $spec, float $ago): array
    {
        $php = $spec['php'];

        // web01 got the PHP 8.3 point release yesterday morning.
        if ($name === 'web01' && $ago > 30 * 60) {
            $php['8.3'] = '8.3.13';
        }

        [$distro, $versionId] = str_starts_with((string) $spec['pretty'], 'Debian') ? ['debian', '12'] : ['ubuntu', str_contains((string) $spec['pretty'], '24.04') ? '24.04' : '22.04'];
        $codename = ['24.04' => 'noble', '22.04' => 'jammy', '12' => 'bookworm'][$versionId];

        $phpReadings = [];

        foreach ($php as $branch => $version) {
            $phpReadings[] = ['branch' => (string) $branch, 'version' => $version, 'cli' => true, 'fpm' => true, 'package' => "{$version}-1+{$distro}{$versionId}.1"];
        }

        return [
            'schema' => 1,
            'collector_version' => $spec['collector'],
            'generated_at' => CarbonImmutable::now()->startOfHour()->toIso8601ZuluString(),
            'hostname' => $name,
            'os' => ['id' => $distro, 'version_id' => $versionId, 'pretty_name' => $spec['pretty']],
            'kernel' => ['version' => $spec['kernel']],
            'nginx' => $spec['nginx'] === null ? null : ['version' => $spec['nginx'], 'package' => "{$spec['nginx']}-1~{$codename}"],
            'php' => $phpReadings,
            'mysql' => $spec['mysql'] === null ? null : ['version' => $spec['mysql'], 'package' => "{$spec['mysql']}-1{$distro}{$versionId}"],
            'openssl' => ['version' => $versionId === '22.04' ? '3.0.2' : '3.0.13', 'package' => $versionId === '22.04' ? '3.0.2-0ubuntu1.18' : '3.0.13-0ubuntu3.4'],
        ];
    }
}
