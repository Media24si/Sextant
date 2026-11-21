# Sextant — Specification

> Draft v0.2 · 2026-11-20 · Implements [GOAL.md](GOAL.md) v0.3 · Build plan: [TODO.md](TODO.md)

This document defines how Sextant v1 is built. GOAL.md says what it should do and why; anything here that contradicts GOAL.md is a bug in this document.

**Changes from v0.1:** Filament is replaced by Inertia + Vue + shadcn-vue for the whole interface; MySQL 9.7 LTS; admin and reader roles; external services and serving paths (replacing `behind_proxy`); "Add site" detection; server OS and backup locations; site description and GitHub link; software versions collection, minimum versions and the `server.versions` checker; the `site.hosting` checker becomes `site.path`; new build phases.

Contents:

1. [Architecture](#1-architecture)
2. [Stack and libraries](#2-stack-and-libraries)
3. [Data model](#3-data-model)
4. [Checkers and the registry](#4-checkers-and-the-registry)
5. [Running monitors](#5-running-monitors)
6. [Monitor state and incidents](#6-monitor-state-and-incidents)
7. [Resource status](#7-resource-status)
8. [Change feed](#8-change-feed)
9. [Checker reference](#9-checker-reference)
10. [Site detection and adding things](#10-site-detection-and-adding-things)
11. [Software versions](#11-software-versions)
12. [Notifications and self-monitoring](#12-notifications-and-self-monitoring)
13. [User interface](#13-user-interface)
14. [Commands and schedule](#14-commands-and-schedule)
15. [Configuration](#15-configuration)
16. [Deployment](#16-deployment)
17. [Testing](#17-testing)
18. [Build phases](#18-build-phases)
19. [Risks and open items](#19-risks-and-open-items)

---

## 1. Architecture

```text
                ┌──────────────────────────── Sextant server ────────────────────────────┐
                │                                                                        │
 Admins  ──────►│  Laravel + Inertia (server)  ◄──►  Vue 3 + shadcn-vue (browser)        │
 Readers        │        │   pages poll every 10 s where live                            │
                │        ▼                                                               │
                │     MySQL 9.7  ◄───────────────────┐                                   │
                │        ▲                           │                                   │
                │  scheduler ─┤             queue workers (Horizon)                      │
                │  every minute: dispatch,  RunMonitor jobs, Discord sends               │
                │  sweep, flush             └──── Redis (queue, cache, locks)            │
                └────────────────────────────────────────────────────────────────────────┘
                        │ checks (TCP, ICMP, DNS, HTTP, TLS, RDAP, WHOIS)
                        │ versions: HTTPS GET + bearer token
                        ▼
   Our servers ── nginx ── /.sextant/versions.php ── reads /var/lib/sextant/versions.json
                                                     (written hourly by root cron script)
   Sites, domains, registries, external services (Cloudflare, Varnish)
                        │
   Discord (incidents)      Sentry (Sextant errors + cron heartbeats)
```

The flow:

1. **Inventory**: admins enter servers, external services, domains and sites. Sites are usually added from a URL through detection (§10), which also proposes the serving path.
2. **Monitors**: each resource gets monitors from config defaults (§4.4).
3. **Dispatch**: every minute the scheduler claims due monitors and queues one `RunMonitor` job each (§5).
4. **Run**: the job runs the checker, stores a run, updates the monitor's state, opens or resolves incidents, records detected changes, and refreshes the resource's status (§5–§8).
5. **Versions**: the `server.versions` checker fetches each server's versions file, stores the current versions and compares them with minimum versions (§11).
6. **Notify**: every 30 seconds pending incident events are grouped and sent to Discord (§12).
7. **Display**: one Inertia + Vue app shows everything; live pages poll (§13).

---

## 2. Stack and libraries

| Concern | Choice | Notes |
|---|---|---|
| Framework | Laravel, latest stable at project start | PHP ≥ 8.4 with `intl`, `openssl`, `curl`, `redis`, `pdo_mysql` |
| Starting point | Laravel's official **Vue starter kit** | Inertia, Vue 3, TypeScript, Tailwind, shadcn-vue. Provides login, password reset, profile settings and the sidebar layout. Registration is removed |
| Database | **MySQL 9.7 LTS** | Same version as the rest of our servers. All timestamps in UTC |
| Queue, cache, locks | Redis | Horizon for supervision and a job dashboard |
| UI | Inertia 2 + Vue 3 + TypeScript + **shadcn-vue** (reka-ui underneath) + Tailwind | Inertia 2 for `usePoll`, partial reloads and deferred props |
| Tables | **TanStack Table** (Vue) on the client, **spatie/laravel-query-builder** on the server | One shared server-side `DataTable` component (§13.5) |
| Typed data | **spatie/laravel-data** + **spatie/laravel-typescript-transformer** | PHP data classes generate the TypeScript types used by pages |
| Typed routes | **Laravel Wayfinder** (or Ziggy if Wayfinder is unsuitable in Phase 0) | |
| Permissions | Laravel policies and gates, `users.role` | Two roles; no permissions package |
| Errors and heartbeats | `sentry/sentry-laravel` | Includes Sentry Crons for scheduled tasks |
| HTTP | Laravel HTTP client (Guzzle) | HTTP, redirect, RDAP and versions fetches |
| TLS details | `spatie/ssl-certificate` | Certificate fields; chain validation is done separately (§9.6) |
| Registrable domain | `jeremykendall/php-domain-parser` + Public Suffix List | PSL file refreshed weekly |
| DNS | Net_DNS2 | Our own resolvers, timeouts, TTLs, direct queries to authoritative servers |
| IP ranges | `Symfony\Component\HttpFoundation\IpUtils` | CIDR matching for IPv4 and IPv6 in PHP |
| Ping | Laravel `Process` running the system `ping` | |
| Version comparison | PHP `version_compare` after normalisation (§11.6) | |
| Backend tests | Pest, Larastan, Pint | |
| Frontend checks | `vue-tsc`, ESLint, Prettier, Vitest | |

Package names and versions are confirmed and pinned in Phase 0 (§18). Where a library turns out unsuitable, its wrapper (§9.0) hides the swap.

---

## 3. Data model

Conventions:

- `bigint unsigned` auto-increment IDs and Laravel timestamps unless stated.
- Status columns use the `Status` enum values `ok`, `warning`, `error`, `unknown`, stored as `varchar(10)`.
- **System-maintained** columns are written only by Sextant, never through a form. They are saved with `saveQuietly()` so they don't produce inventory entries in the change feed (§8).
- Hostnames and domains are stored lowercase, ASCII (punycode), without a trailing dot. They are shown in Unicode with `idn_to_utf8()`.
- IPs are stored in canonical text form (`inet_ntop(inet_pton($ip))`). All range matching happens in PHP (§9.0); the database never compares addresses.

Morph map, enforced with `Relation::enforceMorphMap()`:

```php
[
    'server'           => Server::class,
    'external_service' => ExternalService::class,
    'domain'           => Domain::class,
    'site'             => Site::class,
    'monitor'          => Monitor::class,
    'incident'         => Incident::class,
    'minimum_version'  => MinimumVersion::class,
    'user'             => User::class,
]
```

### 3.1 `users`

The starter kit's table, plus:

| Column | Type | Notes |
|---|---|---|
| `role` | varchar(10) | `admin` or `reader`, default `reader` |

There is no public registration. Admins create users; the first admin is created with `php artisan sextant:create-admin`.

### 3.2 `servers` (soft deletes)

| Column | Type | Notes |
|---|---|---|
| `name` | varchar(100) | Unique among non-deleted servers (§3.17), e.g. `web01` |
| `hostname` | varchar(253) null | FQDN, often from reverse DNS |
| `provider` | varchar(100) null | Free text |
| `os_choice_id` | FK → choices, null, null on delete | The OS an admin set (§3.4) |
| `os_reported` | varchar(150) null | System-maintained: `PRETTY_NAME` from the versions file |
| `notes` | text null | |
| `versions_url` | varchar(500) null | HTTPS URL of the versions endpoint (§11) |
| `versions_token` | text null | `encrypted` cast. Never sent to the browser |
| `versions_token_last4` | char(4) null | Shown as `sxt_••••a91f` |
| `status` | varchar(10) | System-maintained, default `unknown` |
| `status_changed_at` | timestamp null | System-maintained |

Servers have no role field (GOAL decision 16).

### 3.3 `server_addresses`

| Column | Type | Notes |
|---|---|---|
| `server_id` | FK → servers, cascade | |
| `ip` | varchar(45) | Canonical form, indexed |
| `version` | tinyint | 4 or 6 |
| `is_primary` | bool | Exactly one per server; checks target the primary address unless told otherwise |

An IP may belong to at most one non-deleted server and must not fall inside any external service's ranges. Both are enforced by validation.

### 3.4 `choices` and `server_backup_location`

Admin-editable lists, so new operating systems or backup locations need no code change.

`choices`:

| Column | Type | Notes |
|---|---|---|
| `list` | varchar(30) | `os` or `backup_location` |
| `label` | varchar(100) | e.g. `Ubuntu 24.04 LTS`, `Backup server` |
| `position` | smallint | Order in forms |

Unique `(list, label)`. Seeded:

- `os`: Ubuntu 22.04 LTS, Ubuntu 24.04 LTS, Ubuntu 26.04 LTS, Debian 12, Debian 13, Windows Server 2019, Windows Server 2022, Windows Server 2025, Other.
- `backup_location`: Provider snapshots, Backup server, Offsite cloud storage, Local disk on the server. (GOAL open question: the real list.)

A choice in use can't be deleted, only renamed.

`server_backup_location` is a pivot: `server_id` (cascade), `choice_id` (restrict), primary key on both.

### 3.5 `external_services` (soft deletes)

Services in front of our servers that we don't run: Cloudflare, the Varnish server, other CDNs or proxies.

| Column | Type | Notes |
|---|---|---|
| `name` | varchar(100) | Unique among non-deleted, e.g. `Cloudflare`, `Varnish` |
| `kind` | varchar(10) | `cdn`, `proxy` or `other` |
| `ranges` | json | IPs and CIDRs entered by admins, e.g. the Varnish server's addresses |
| `ranges_source` | varchar(20) null | `cloudflare`, `fastly` or `cloudfront`: published ranges refreshed daily by `proxies:refresh` and merged with `ranges` |
| `cname_suffixes` | json null | e.g. `[".cdn.cloudflare.net"]` |
| `response_headers` | json null | Header names that reveal the service, e.g. `["cf-ray"]` |
| `notes` | text null | |

Seeded with Cloudflare (`cdn`, source `cloudflare`, suffix `.cdn.cloudflare.net`, header `cf-ray`). Admins add Varnish with its IPs. A service used by live sites can't be deleted.

### 3.6 `domains` (soft deletes)

| Column | Type | Notes |
|---|---|---|
| `name` | varchar(253) | Registrable domain, e.g. `svet24.si`. Unique among non-deleted |
| `expires_at` | date null | System-maintained by `domain.expiration` |
| `registrar` | varchar(255) null | System-maintained by `domain.expiration` |
| `notes` | text null | |
| `status`, `status_changed_at` | | System-maintained |

### 3.7 `sites` (soft deletes)

| Column | Type | Notes |
|---|---|---|
| `domain_id` | FK → domains | A domain with live sites can't be deleted |
| `application_id` | FK → applications, null, null on delete | The web app behind this hostname (§3.19). Null = none |
| `hostname` | varchar(253) | Unique among non-deleted |
| `scheme` | varchar(5) | `https` or `http` |
| `port` | smallint null | Null = the scheme's default |
| `front_service_id` | FK → external_services, null, restrict | The external service in front. Null = DNS points straight at the backend |
| `server_id` | FK → servers, null | The **backend** server. Null = not assigned |
| `description` | varchar(500) null | Short text shown under the hostname and found by search |
| `github_url` | varchar(255) null | Validated as `https://github.com/{owner}/{repo}` |
| `notes` | text null | |
| `status`, `status_changed_at` | | System-maintained |

**Serving path** = `[front_service?] → server`. v1 paths have at most these two steps (GOAL §2). `$site->url` is `scheme://hostname[:port]`; paths in URLs belong to monitor arguments, not the site.

### 3.8 `monitors` (soft deletes)

| Column | Type | Notes |
|---|---|---|
| `monitorable_type`, `monitorable_id` | morph | `server`, `domain` or `site` |
| `checker` | varchar(50) | Registry key, e.g. `site.http` (§4.3). Never a class name |
| `label` | varchar(100) null | Tells apart monitors with the same checker on one resource, e.g. `SSH` and `HTTPS` |
| `arguments` | json null | **Overrides only.** Missing keys inherit from config (§4.5) |
| `interval_minutes` | smallint null | Override; null inherits |
| `failures_before_incident` | tinyint null | Override; null inherits |
| `successes_before_resolve` | tinyint null | Override; null inherits |
| `enabled` | bool | Default true |
| `muted_until` | timestamp null | Muted while in the future (§6.6) |
| `next_run_at` | timestamp null | Null while disabled |

Indexes: `(enabled, next_run_at)`, `(monitorable_type, monitorable_id)`. `(monitorable, checker, label)` must be unique among non-deleted monitors (validation). Monitors are soft-deleted so incidents keep pointing at them.

### 3.9 `monitor_states` (one row per monitor)

| Column | Type | Notes |
|---|---|---|
| `monitor_id` | PK, FK → monitors, cascade | |
| `status` | varchar(10) | **Effective** status (§6.1), default `unknown` |
| `status_changed_at` | timestamp null | |
| `last_result_status` | varchar(10) null | **Raw** status of the most recent run |
| `message` | varchar(1000) null | Message of the most recent run |
| `data` | json null | Data of the most recent **definitive** result (ok/warning/error) |
| `duration_ms` | int null | |
| `checked_at` | timestamp null | Most recent run of any result |
| `last_definitive_at` | timestamp null | Most recent ok/warning/error result |
| `stale_at` | timestamp null | `last_definitive_at + interval × 2 + 5 min` |
| `consecutive_failures` | smallint | |
| `consecutive_successes` | smallint | |
| `consecutive_unknowns` | smallint | |

### 3.10 `monitor_runs`

| Column | Type | Notes |
|---|---|---|
| `monitor_id` | bigint | Indexed, no FK (keeps pruning cheap) |
| `status` | varchar(10) | Raw result status |
| `duration_ms` | int | |
| `message` | varchar(500) null | Null for OK runs |
| `data` | json null | Stored only for non-OK runs, unknown runs, and runs where a tracked field changed (§8.3) |
| `checked_at` | timestamp | |

No `updated_at`. Indexes `(monitor_id, checked_at)` and `(checked_at)`. Pruned after 90 days with `MassPrunable`. About 450 monitors and 190,000 runs a day today: roughly 17 million narrow rows at 90 days.

### 3.11 `incidents`

| Column | Type | Notes |
|---|---|---|
| `monitor_id` | FK → monitors, **restrict** | See the MySQL note below |
| `monitorable_type`, `monitorable_id` | morph | Copied from the monitor for history queries |
| `server_id` | bigint null | Snapshot at open time: the server itself, or the site's backend server. Drives the server page roll-up |
| `parent_id` | FK → incidents, null | Grouping under a server outage (§6.5) |
| `checker` | varchar(50) | |
| `title` | varchar(255) | `"{resource} — {checker name}[ ({label})]"`, e.g. `web01 — TCP port (SSH)` |
| `severity` | varchar(10) | Current: `warning` or `error` |
| `max_severity` | varchar(10) | Worst seen |
| `message` | varchar(1000) | Latest result message |
| `opened_at`, `last_seen_at` | timestamp | `last_seen_at` = latest non-OK result |
| `acknowledged_at` | timestamp null | |
| `acknowledged_by` | FK → users, null | |
| `resolved_at` | timestamp null | |
| `resolution` | varchar(30) null | `recovered`, `monitor_disabled`, `monitor_deleted`, `resource_deleted` |
| `occurrences` | int | Non-OK results during the incident |
| `first_result`, `last_result` | json | Status, message and data |
| `last_notified_at` | timestamp null | For reminders |
| `open_monitor_id` | bigint null, **stored generated**, unique | `IF(resolved_at IS NULL, monitor_id, NULL)` |

`open_monitor_id` lets the database guarantee **at most one open incident per monitor**; MySQL allows many NULLs in a unique index.

> **MySQL note:** a foreign key on a base column of a stored generated column can't use `CASCADE` or `SET NULL`. That's why `incidents.monitor_id` uses `RESTRICT` and monitors are soft-deleted. Phase 0 confirms this on MySQL 9.7.

Status is derived: resolved if `resolved_at` is set, acknowledged if `acknowledged_at` is set, otherwise open. Scopes: `open()`, `resolved()`, `roots()`. Indexes: `(resolved_at)`, `(server_id, opened_at)`, `(monitorable_type, monitorable_id, opened_at)`, `(parent_id)`.

### 3.12 `incident_events`

The incident's timeline and the **notification outbox** (§12.1).

| Column | Type | Notes |
|---|---|---|
| `incident_id` | FK → incidents, cascade | |
| `type` | varchar(20) | `opened`, `escalated`, `deescalated`, `acknowledged`, `reminder`, `resolved` |
| `severity` | varchar(10) | Severity at the time of the event |
| `message` | varchar(1000) | |
| `user_id` | FK → users, null | For acknowledgements |
| `notify` | bool | Whether this event should produce a notification |
| `notified_at` | timestamp null | Set when sent or suppressed |
| `suppressed_reason` | varchar(20) null | `muted` or `grouped` |
| `created_at` | timestamp | |

### 3.13 `activities`

The change feed (§8).

| Column | Type | Notes |
|---|---|---|
| `category` | varchar(10) | `status`, `inventory`, `detected` |
| `type` | varchar(50) | e.g. `site.path_changed`, `software.upgraded` (§8.4) |
| `subject_type`, `subject_id` | morph | What the entry is about |
| `server_id` | bigint null | Snapshot, same rule as incidents |
| `monitor_id` | bigint null | |
| `incident_id` | bigint null | |
| `user_id` | FK → users, null | Who did it; null = Sextant |
| `summary` | varchar(500) | Human-readable, e.g. `web02: nginx 1.24.0 → 1.26.2` |
| `properties` | json null | `{"old": …, "new": …}` and any extra detail |
| `created_at` | timestamp | |

Indexes: `(created_at)`, `(subject_type, subject_id, created_at)`, `(server_id, created_at)`. Kept indefinitely.

### 3.14 `server_software`

The current versions of each server, written by `server.versions` (§11.5). System-maintained.

| Column | Type | Notes |
|---|---|---|
| `server_id` | FK → servers, cascade | |
| `software` | varchar(20) | `os`, `kernel`, `nginx`, `php`, `mysql`, `openssl` |
| `branch` | varchar(20) | `''` for single-version software; the PHP branch, e.g. `8.3` |
| `version` | varchar(50) | Upstream version, e.g. `8.3.14`, `6.8.0-49-generic` |
| `package_version` | varchar(100) null | Distribution package version, e.g. `3.0.13-0ubuntu3.4` |
| `details` | json null | e.g. `{"cli": true, "fpm": true}`, or the OS `pretty_name` |
| `read_at` | timestamp | The collector's `generated_at` |
| `fetched_at` | timestamp | When Sextant fetched it |

Unique `(server_id, software, branch)`. Software missing from a new reading is deleted (and recorded as removed, §8.4). Indexed `(software, branch, version)` for the overview and search.

### 3.15 `minimum_versions`

| Column | Type | Notes |
|---|---|---|
| `software` | varchar(20) | `kernel`, `nginx`, `php`, `mysql`, `openssl` |
| `branch` | varchar(20) | `''`, or a PHP branch such as `8.2` |
| `min_version` | varchar(50) | Lowest safe upstream version |
| `cve_url` | varchar(255) null | Link to the CVE or advisory |
| `note` | varchar(255) null | |
| `set_by` | FK → users | |
| timestamps | | |

Unique `(software, branch)`. A PHP minimum applies to that branch only; servers without the branch are not affected.

### 3.16 Remaining tables

Laravel defaults: `sessions`, `cache`, `jobs`/`failed_jobs` (Horizon uses Redis), `password_reset_tokens`.

### 3.17 Uniqueness with soft deletes

MySQL has no partial indexes, so uniqueness among non-deleted rows uses a stored generated column with a unique index:

```php
$table->string('active_name')->nullable()
    ->storedAs('IF(deleted_at IS NULL, name, NULL)')
    ->unique();
```

Used for `servers.name`, `external_services.name`, `domains.name`, `sites.hostname` and `applications.name`.

### 3.18 Relationships

```text
Server 1─* ServerAddress
Server *─* Choice (backup locations)      Server *─1 Choice (OS)
Server 1─* Site                            (sites.server_id: backend, nullable)
ExternalService 1─* Site                   (sites.front_service_id, nullable)
Domain 1─* Site
Application 1─* Site                       (sites.application_id, nullable)
Server 1─* ServerSoftware
Server|Domain|Site 1─* Monitor             (morph)
Monitor 1─1 MonitorState
Monitor 1─* MonitorRun
Monitor 1─* Incident
Incident 1─* IncidentEvent
Incident 1─* Incident                      (parent_id)
```

### 3.19 `applications` (soft deletes)

One web application answering on several hostnames, e.g. one backend behind `backend.svet24.si`, `backend.necenzurirano.si` and `backend.reporter.si` (nginx `server_name` with several names). Each hostname stays its own site, on its own domain, with its own serving path and checks (certificates, DNS and redirects differ per hostname); the application only groups them.

| Column | Type | Notes |
|---|---|---|
| `name` | varchar(100) | Unique among non-deleted |
| `description` | varchar(500) null | |
| `github_url` | varchar(255) null | Validated as `https://github.com/{owner}/{repo}` |
| `notes` | text null | |

Applications have no monitors and no stored status; the list and page show the worst status of their sites. An application with live sites can't be deleted. Checks of the application itself (health endpoints, deployed versions) are after v1 (GOAL).

---

## 4. Checkers and the registry

### 4.1 Terms

- A **checker** is a PHP class that knows how to check one kind of thing.
- A **monitor** is a configured instance of a checker on one resource.
- The **registry** (`config/checkers.php`) lists the checkers that exist, their defaults, and which monitors new resources get.

### 4.2 Contracts

```php
namespace App\Checks;

enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Unknown = 'unknown';

    /** Ordering used when combining statuses: error > warning > unknown > ok. */
    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Unknown => 1,
            self::Warning => 2,
            self::Error => 3,
        };
    }

    public function isFailure(): bool
    {
        return $this === self::Warning || $this === self::Error;
    }
}

final readonly class CheckResult
{
    public function __construct(
        public Status $status,
        public string $message,
        public array $data = [],
    ) {}

    public static function ok(string $message, array $data = []): self { /* … */ }
    public static function warning(string $message, array $data = []): self { /* … */ }
    public static function error(string $message, array $data = []): self { /* … */ }
}

/** Thrown when the check couldn't run for reasons on our side; the result becomes Unknown. */
final class CheckCouldNotRun extends \RuntimeException {}

interface Checker
{
    public static function name(): string;

    /** @return list<Argument> Schema for the monitor form and validation. */
    public static function arguments(): array;

    /** @return array<string, string> data key => activity type, for change detection (§8.3). */
    public static function trackedFields(): array;

    public static function describeChange(string $field, mixed $old, mixed $new): string;

    public function run(Model $resource, Arguments $arguments): CheckResult;
}
```

There is a typed base class per resource type:

```php
abstract class SiteChecker implements Checker
{
    final public function run(Model $resource, Arguments $arguments): CheckResult
    {
        assert($resource instanceof Site);

        return $this->check($resource, $arguments);
    }

    abstract protected function check(Site $site, Arguments $arguments): CheckResult;

    public static function arguments(): array { return []; }
    public static function trackedFields(): array { return []; }
    public static function describeChange(string $field, mixed $old, mixed $new): string { /* generic */ }
}

// ServerChecker and DomainChecker follow the same pattern.
```

Checkers don't measure their own duration; the runner times them. They don't catch unexpected exceptions either; the runner turns those into Unknown (§5.3).

### 4.3 Registry config

Keys are `{type}.{name}`. The prefix must match the class's base type, which a test checks (§17).

```php
// config/checkers.php
return [

    'checkers' => [

        'server.tcp' => [
            'class' => \App\Checks\Server\TcpChecker::class,
            'queue' => 'checks',
            'interval' => 1,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['port' => 443, 'address' => null, 'timeout' => 5],
        ],

        'server.ping' => [
            'class' => \App\Checks\Server\PingChecker::class,
            'queue' => 'checks',
            'interval' => 5,
            'failures_before_incident' => 3,
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => ['count' => 3, 'timeout' => 5],
        ],

        'server.versions' => [
            'class' => \App\Checks\Server\VersionsChecker::class,
            'queue' => 'checks-slow',
            'interval' => 1440,
            'failures_before_incident' => 1,   // deterministic: a version is below the minimum or it isn't
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => [],
        ],

        'domain.expiration' => [
            'class' => \App\Checks\Domain\DomainExpirationChecker::class,
            'queue' => 'checks-slow',
            'interval' => 1440,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['warning_days' => 30, 'error_days' => 7],
        ],

        'domain.nameservers' => [
            'class' => \App\Checks\Domain\NameserversChecker::class,
            'queue' => 'checks',
            'interval' => 60,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['expected' => [], 'check_soa' => true],
        ],

        'site.http' => [
            'class' => \App\Checks\Site\HttpChecker::class,
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
            'class' => \App\Checks\Site\SslCertificateChecker::class,
            'queue' => 'checks',
            'interval' => 720,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'arguments' => ['warning_days' => 30, 'error_days' => 7, 'port' => 443],
        ],

        'site.path' => [
            'class' => \App\Checks\Site\ServingPathChecker::class,
            'queue' => 'checks',
            'interval' => 15,
            'failures_before_incident' => 2,
            'successes_before_resolve' => 1,
            'max_severity' => 'warning',
            'arguments' => [],
        ],

        'site.redirect' => [
            'class' => \App\Checks\Site\RedirectChecker::class,
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
            ['checker' => 'server.tcp', 'label' => 'SSH', 'arguments' => ['port' => 22], 'only_if' => \App\Checks\Conditions\PortIsOpen::class],
            ['checker' => 'server.tcp', 'label' => 'HTTP', 'arguments' => ['port' => 80], 'only_if' => \App\Checks\Conditions\PortIsOpen::class],
            ['checker' => 'server.tcp', 'label' => 'HTTPS', 'arguments' => ['port' => 443], 'only_if' => \App\Checks\Conditions\PortIsOpen::class],
            ['checker' => 'server.ping', 'only_if' => \App\Checks\Conditions\RespondsToPing::class],
            ['checker' => 'server.versions', 'only_if' => \App\Checks\Conditions\HasVersionsEndpoint::class],
        ],

        'domain' => [
            ['checker' => 'domain.expiration'],
            ['checker' => 'domain.nameservers'],
        ],

        'site' => [
            ['checker' => 'site.http'],
            ['checker' => 'site.ssl', 'only_if' => \App\Checks\Conditions\UsesHttps::class],
            ['checker' => 'site.path'],
            ['checker' => 'site.redirect'],
        ],

    ],

];
```

Registry entry fields:

| Field | Required | Meaning |
|---|---|---|
| `class` | yes | Implements `Checker` through the matching base class |
| `queue` | yes | `checks` or `checks-slow` |
| `interval` | yes | Minutes |
| `failures_before_incident` | yes | N in GOAL §7 |
| `successes_before_resolve` | yes | M in GOAL §7 |
| `max_severity` | no | Caps result severity. Default `error` |
| `requires_network` | no | Default true. Failures are discarded when Sextant's network is unhealthy (§5.4) |
| `arguments` | yes | A default for **every** argument in the class schema |

### 4.4 Default monitors

When a resource is created (Add site, a form, Add domain, an import), Sextant goes through `defaults.{type}`. For each entry:

1. If there's an `only_if` condition, evaluate it. A condition is an invokable `(Model $resource, array $arguments): bool`; for example, `PortIsOpen` tries a 3-second TCP connection to the server's primary address. If it returns false, skip the entry.
2. Create the monitor with `checker`, `label`, and the entry's `arguments` stored as overrides.
3. Set `next_run_at = now()` so the first run happens immediately.

`server.versions` is also attached when an admin later sets `versions_url` and a token on a server that has no such monitor (a `Server` observer).

`php artisan monitors:sync-defaults --checker=site.redirect [--dry-run]` adds a newly introduced default to existing resources that don't have a monitor with that checker and label. It **requires `--checker`**, so it never re-adds monitors someone deleted on purpose.

### 4.5 Resolving settings

For any setting, a monitor override wins over the registry entry:

```text
arguments  = registry.arguments  ← monitor.arguments (key by key)
interval   = monitor.interval_minutes ?? registry.interval
thresholds = monitor.* ?? registry.*
```

The resolved values are passed to the checker as an `Arguments` object with typed getters (`int()`, `string()`, `bool()`, `list()`, `nullableString()`). Asking for a key that isn't in the schema throws, which catches typos. Changing a default in config changes every monitor that hasn't overridden it, on its next run.

### 4.6 Argument schema

Each checker declares its arguments; the monitor form is generated from them (§13.6):

```php
public static function arguments(): array
{
    return [
        Argument::int('port', 'Port')->rules(['required', 'integer', 'between:1,65535']),
        Argument::select('address', 'Address', options: fn (Server $server) => $server->addresses->pluck('ip', 'ip'))
            ->nullable()->help('Empty = primary address'),
        Argument::int('timeout', 'Timeout (seconds)')->rules(['integer', 'between:1,30']),
    ];
}
```

The schema is serialised to JSON (`type`, `name`, `label`, `help`, `nullable`, `options`, `default`) and rendered by the Vue `MonitorArgumentsForm`:

| Type | Vue field (shadcn-vue) |
|---|---|
| `int` | `Input type="number"` |
| `string` | `Input` |
| `bool` | `Switch` |
| `intList`, `stringList` | `TagsInput` |
| `select` | `Select` |

Validation runs on the server with the schema's rules.

---

## 5. Running monitors

### 5.1 Dispatching

`monitors:dispatch` runs every minute (`withoutOverlapping`, Sentry Crons). It claims due monitors in batches of 200 until none are due or 50 seconds have passed:

```php
$due = Monitor::query()
    ->where('enabled', true)
    ->where('next_run_at', '<=', now())
    ->whereHas('monitorable')            // skips soft-deleted resources
    ->orderBy('next_run_at')
    ->limit(200)
    ->get(['id', 'next_run_at', 'checker', 'interval_minutes']);

foreach ($due as $monitor) {
    // Compare-and-set: only one dispatcher can claim a given run.
    $claimed = Monitor::whereKey($monitor->id)
        ->where('next_run_at', $monitor->next_run_at)
        ->update(['next_run_at' => now()->addMinutes($registry->interval($monitor))]);

    if ($claimed) {
        RunMonitor::dispatch($monitor->id)->onQueue($registry->queue($monitor));
    }
}
```

`next_run_at` moves forward **at claim time**, so a queued or slow job is never dispatched twice. "Run checks now", "Refresh versions" and dependency checks (§6.5) use the same `MonitorClaimer`, with `next_run_at` set to `now()` first.

**Confirmation re-check.** When a run produces a failure that hasn't opened an incident yet, or a success while an incident is waiting to resolve, the runner sets `next_run_at = now() + 1 minute` instead of the full interval.

**Spread for bulk imports.** `sextant:import` sets each new monitor's first `next_run_at` to a random point within its first interval (capped at 10 minutes).

### 5.2 The `RunMonitor` job

- `$tries = 1` and no automatic retries; the next scheduled run serves as the retry. `$timeout = 60` and `failOnTimeout = true`.
- Middleware: `(new WithoutOverlapping("monitor:{$id}"))->dontRelease()->expireAfter(120)`.
- `checks-slow` jobs that call registries also use a `RateLimited('registry')` limiter of 10 per minute per registry host.

### 5.3 The pipeline

```text
load monitor + resource + state      (skip if missing, disabled, or resource trashed)
resolve checker, arguments, thresholds
start timer
  try   result = checker->run(resource, arguments)
  catch CheckCouldNotRun e  → Unknown(e.message)                   // not reported
  catch Throwable e         → report(e); Unknown("Checker error: …") // to Sentry
stop timer
if result.isFailure() and requires_network and not NetworkHealth::isHealthy()
      → Unknown("Discarded: Sextant's own network check failed")
cap severity at max_severity
DB transaction:
  lock monitor_states row (FOR UPDATE)
  insert monitor_run
  ChangeDetector: compare previous definitive data with result data      (§8.3)
  StateMachine: update counters and state; open/update/resolve incident  (§6)
  ResourceStatus::refresh(resource)                                      (§7)
after commit:
  set next_run_at for a confirmation re-check if needed                  (§5.1)
  trigger dependency checks if this is a site's first failure            (§6.5)
```

A registry key that's no longer in config produces Unknown ("Checker `x` is not registered") and one Sentry report per key per day.

### 5.4 Network health (canary)

`NetworkHealth::isHealthy()` answers "can Sextant reach the internet right now?" It runs only when a check fails, and the answer is cached for 60 seconds under a lock.

- Targets come from `sextant.network_canary`: TCP 443 to `1.1.1.1`, `8.8.8.8` and `9.9.9.9`, plus a DNS lookup through each configured resolver.
- Healthy if at least 2 targets succeed within 3 seconds.
- While unhealthy, failed results become Unknown and never count towards an incident. Successful results are kept.

---

## 6. Monitor state and incidents

### 6.1 Effective status

```text
if the monitor has an open incident  → incident.severity        (warning | error)
elif last_definitive_at is null      → unknown                  (never checked)
elif stale_at < now                  → unknown                  (stale)
else                                 → ok
```

Every non-OK effective status is backed by an incident, so the dashboard, notifications and history read from one source.

UI flags: **Pending** (`last_result_status` is a failure but no open incident) and **Recovering** (incident open and `consecutive_successes > 0`).

### 6.2 State machine

```text
state.checked_at = now; state.last_result_status = result.status
state.message = result.message; state.duration_ms = duration

UNKNOWN:
    consecutive_unknowns += 1
    (failure/success counters unchanged; data unchanged; no incident changes)

OK:
    consecutive_unknowns = 0; consecutive_failures = 0; consecutive_successes += 1
    data = result.data; last_definitive_at = now; stale_at = now + interval×2 + 5min
    if open incident and consecutive_successes >= successes_before_resolve:
        resolve(incident, 'recovered')

WARNING | ERROR:
    consecutive_unknowns = 0; consecutive_successes = 0; consecutive_failures += 1
    data = result.data; last_definitive_at = now; stale_at = …
    if open incident:
        incident.last_seen_at = now; occurrences += 1; message, last_result = result
        if result.status != incident.severity: escalate or de-escalate (event)
    elif consecutive_failures >= failures_before_incident:
        open(incident, severity = result.status)

state.status = effective status (§6.1); if changed, status_changed_at = now
```

Unknown results neither extend nor break a streak: "fail, unknown, fail" counts as two consecutive failures.

### 6.3 Incident lifecycle

| Action | Effect | Event (`notify`) |
|---|---|---|
| Open | Insert the incident; the unique `open_monitor_id` rejects a duplicate | `opened` (yes) |
| Escalate (warning → error) | `severity = error`, `max_severity = error` | `escalated` (yes) |
| De-escalate (error → warning) | `severity = warning` | `deescalated` (no) |
| Acknowledge (admin) | `acknowledged_at`, `acknowledged_by`; stops reminders | `acknowledged` (yes, without `@here`) |
| Reminder (sweep) | `last_notified_at` updated | `reminder` (yes, without `@here`) |
| Resolve | `resolved_at`, `resolution` | `resolved` (yes) |

Each action also writes a `status` entry to the change feed. Incidents are resolved only by recovery (M passes), monitor disabled or deleted, or resource deleted. There is no manual "resolve" button.

**Reminders.** An open, unacknowledged, unmuted, error-severity root incident gets a `reminder` event when `last_notified_at` is more than `notifications.reminder_hours` (default 4) ago.

### 6.4 Monitor changes

| Change | Effect |
|---|---|
| Arguments or thresholds edited | `next_run_at = now()`; an open incident continues and is judged by the new settings |
| Disabled | `next_run_at = null`; open incident resolved (`monitor_disabled`); excluded from resource status |
| Enabled | `next_run_at = now()`; counters reset |
| Resource soft-deleted | Monitors skipped by the dispatcher; open incidents resolved (`resource_deleted`) |
| Resource restored | Its monitors get `next_run_at = now()` |
| Site's serving path changed | Its `site.path` monitor gets `next_run_at = now()` |
| Minimum version set, changed or removed | Every enabled `server.versions` monitor is claimed for an immediate run |

### 6.5 Server outages: dependency checks and grouping

"A site's server" below always means its **backend** server (`sites.server_id`).

1. **Dependency check.** When a site monitor records the first failure of a streak, Sextant claims an immediate run of the backend server's `server.tcp` monitors, unless they ran in the last 60 seconds.
2. **Adoption on open.**
   - When a **site** incident opens and its backend server has an open, error-severity `server.tcp` incident, the site incident's `parent_id` points to it.
   - When a **server** `server.tcp` incident opens at error severity, it adopts open root incidents of sites whose backend is that server and that opened in the last 15 minutes (`grouping.adopt_window_minutes`).
   - If several `server.tcp` incidents are open on one server, the earliest opened is the parent.
3. **After the parent resolves.** Children resolve through their own checks. A child still open 10 minutes after its parent resolved (`grouping.detach_after_minutes`) gets a `reminder` event marked to notify: "still failing after {server} recovered". It keeps its `parent_id`.

External services are never parents: Sextant doesn't check them (GOAL §1).

### 6.6 Muting

A monitor is muted while `muted_until > now()`. A muted monitor still runs, stores runs and opens and resolves incidents, sends **no notifications** (`suppressed_reason = muted`), is left out of resource status, and shows in a collapsed "Muted" group. When a mute ends while an incident is open, the sweep creates a `reminder` event: "still failing after mute ended".

"Mute until…" exists on a monitor and on a resource (all its monitors). On a server it also mutes the monitors of every site whose backend is that server, which covers maintenance.

---

## 7. Resource status

```text
resource.status = worst (by rank) of state.status
                  over the resource's enabled, unmuted monitors
                  (no such monitors → unknown)
```

- Status doesn't propagate: a server's status comes from its own monitors, a site's from its own. External services have no status.
- Written with `saveQuietly()` immediately after each run, after monitor enable/disable/mute/unmute/delete, and by the sweep every minute for all resources (staleness, expiring mutes).

---

## 8. Change feed

### 8.1 Categories

| Category | Written by | Examples |
|---|---|---|
| `status` | `IncidentManager` | Incident opened, escalated, acknowledged, resolved |
| `inventory` | Eloquent observers on admin actions | Site added, serving path changed, minimum version set |
| `detected` | `ChangeDetector` and `VersionsRecorder` after a run | DNS changed, certificate renewed, nginx upgraded |

All entries go through `ActivityRecorder::record(...)`.

### 8.2 Inventory changes

Observers on `Server`, `ServerAddress`, `ExternalService`, `Domain`, `Site`, `Monitor`, `MinimumVersion`, `User` and the `server_backup_location` pivot record `created`, `updated`, `deleted` and `restored`.

- `properties` holds old and new values of changed attributes. Timestamps, system-maintained columns and secrets (`versions_token`, passwords) are excluded; a token change is recorded as `server.versions_token_rotated` with no value.
- The causer is `auth()->id()`. Sextant's own writes use `saveQuietly()`, so they never appear as inventory changes.
- Some updates get their own `type`:

| Change | `type` |
|---|---|
| `sites.front_service_id` or `sites.server_id` changes | `site.path_changed` |
| `monitors.enabled` changes | `monitor.enabled` / `monitor.disabled` |
| `monitors.muted_until` changes | `monitor.muted` / `monitor.unmuted` |
| `users.role` changes | `user.role_changed` |

### 8.3 Detected changes

Each checker declares the data keys whose changes are worth recording (`trackedFields()`). After a **definitive** result, `ChangeDetector` compares each tracked key in the previous `monitor_states.data` with the new result data. An entry is recorded when the value differs and the previous value wasn't null; the first run never records anything. The run's `data` is stored whenever a tracked field changed.

Software versions are compared per row by `VersionsRecorder` (§11.5), not through `trackedFields()`.

### 8.4 Activity types

| Category | Types |
|---|---|
| `status` | `incident.opened`, `incident.escalated`, `incident.deescalated`, `incident.acknowledged`, `incident.resolved` |
| `inventory` | `{server,external_service,domain,site,application}.{created,updated,deleted,restored}`, `server.address_added`, `server.address_removed`, `server.backup_locations_changed`, `server.versions_token_rotated`, `site.path_changed`, `monitor.{created,updated,deleted,enabled,disabled,muted,unmuted}`, `minimum_version.{set,changed,removed}`, `user.{created,deleted,role_changed}` |
| `detected` | `site.dns_changed`, `site.front_detected_changed`, `certificate.renewed`, `certificate.issuer_changed`, `domain.renewed`, `domain.registrar_changed`, `domain.nameservers_changed`, `redirect.target_changed`, `software.upgraded`, `software.downgraded`, `software.installed`, `software.removed`, `server.os_changed` |

---

## 9. Checker reference

### 9.0 Network wrappers

All network I/O goes through small services in `app/Net` so checkers and detection can be tested with fakes:

| Service | Purpose |
|---|---|
| `DnsResolver` | `resolve(host): {cname_chain, a, aaaa}`, `ns(domain)`, `soa(domain, nameserver)`. Configured resolvers (`sextant.dns.resolvers`), 3-second timeout |
| `TcpProbe` | `connect(ip, port, timeout): {ok, ms, error}` |
| `TlsProbe` | `inspect(host, port, ip?): {verified, verify_error, certificate}` |
| `HttpProbe` | `get(url, followRedirects, maxBytes): {chain, final_url, status, headers, timings, ip}` |
| `RdapClient` | `domain(name, fresh = false)`. IANA bootstrap (cached, refreshed weekly); responses cached 12 hours unless `fresh` |
| `WhoisClient` | `query(domain)`. Server via `whois.iana.org` (`.si` → `whois.register.si`); port 43; 10-second timeout |
| `PingProbe` | `ping -c {count} -w {timeout}` (`-6` for IPv6); parses loss and average RTT |
| `VersionsClient` | `fetch(url, token): VersionsFile` (§11.4) |

Matching services (no network):

| Service | Purpose |
|---|---|
| `ServerMatcher` | IP → non-deleted server owning that address |
| `ExternalServiceMatcher` | IPs, CNAME chain and response headers → external service, using `ranges`, cached source ranges, `cname_suffixes` and `response_headers` |

Every outgoing HTTP request uses `sextant.user_agent`. Failures caused by Sextant itself (resolver timeout, rate limit) throw `CheckCouldNotRun`.

### 9.1 `server.tcp` — TCP port

- **Arguments**: `port`, `address` (null = primary), `timeout` (5 s).
- **Results**: OK `Port 443 open (12 ms)`; Error `Port 443 refused` / `Port 443 timed out after 5 s`.
- **Data**: `address`, `port`, `connect_ms`.
- The authoritative reachability check and the only parent for grouping (§6.5).

### 9.2 `server.ping` — Ping

- **Arguments**: `count` (3), `timeout` (5 s). `max_severity: warning`.
- **Results**: OK at 0% loss; Warning on partial or total loss, e.g. `100% packet loss`.
- **Data**: `address`, `loss_pct`, `rtt_avg_ms`.

### 9.3 `domain.expiration` — Domain expiration

- **Arguments**: `warning_days` (30), `error_days` (7).
- **Logic**:
  1. `RdapClient::domain($name, fresh: true)`.
  2. Take the event with `eventAction = expiration`; accept a date-only value (`2027-07-18`, as `.si` returns) or a full RFC 3339 timestamp.
  3. Use WHOIS only when the TLD has no RDAP service or the RDAP request fails. WHOIS expiry is parsed with `sextant.whois.expiry_patterns`.
  4. No expiry from either source → `CheckCouldNotRun("Could not determine expiry date")`.
- **Results**: Error for RDAP 404 (`Domain not found in registry`), a status of `client hold`, `server hold`, `redemption period` or `pending delete`, already expired, or expiry within `error_days`; Warning within `warning_days`; OK `Expires 2027-07-18 (285 days)`. RDAP 429 → `CheckCouldNotRun`.
- Days are calendar days in `Europe/Ljubljana`.
- **Data**: `expires_at`, `registered_at`, `last_changed_at`, `registrar`, `statuses`, `nameservers`, `source`.
- **Tracked**: `expires_at` → `domain.renewed`, `registrar` → `domain.registrar_changed`.
- **Side effect**: writes `domains.expires_at` and `domains.registrar` quietly.

### 9.4 `domain.nameservers` — Nameservers

- **Arguments**: `expected` (empty = the registry's NS list from RDAP, cached), `check_soa` (true).
- **Results**: Error when the NS lookup fails or returns NXDOMAIN, or no authoritative nameserver answers; Warning when the live NS set differs from expected/registry, one nameserver doesn't answer, or SOA serials differ; OK `3 nameservers, serials consistent`.
- **Data**: `nameservers_live`, `nameservers_registry`, `soa`.
- **Tracked**: `nameservers_live` → `domain.nameservers_changed`.

### 9.5 `site.http` — HTTP

- **Arguments**: `path` (`/`), `expected_status` (`[200]`), `timeout` (10 s), `slow_ms` (5000), `verify_tls` (true), `contains` (null).
- **Logic**: `GET {url}{path}` following up to 5 redirects; at most 2 MB of body read, only when `contains` is set; timings from Guzzle `on_stats`.
- **Results**: Error on DNS, connect, TLS or timeout failure, a final status not in `expected_status`, or `contains` not found; Warning above `slow_ms`; OK `200 in 184 ms`.
- **Data**: `final_url`, `status_code`, `redirects`, `total_ms`, `dns_ms`, `connect_ms`, `tls_ms`, `ttfb_ms`, `ip`, `http_version`.

### 9.6 `site.ssl` — SSL certificate

- **Arguments**: `warning_days` (30), `error_days` (7), `port` (443). Only on `https` sites.
- **Logic**: `TlsProbe::inspect()` makes a verifying connection (`verify_peer`, `verify_peer_name`, SNI = hostname), then reads details with `spatie/ssl-certificate`. If no connection can be made, `CheckCouldNotRun` (availability is `site.http`'s job).
- **Results**: Error when verification fails, expired, or within `error_days`; Warning within `warning_days`; OK `Valid until 2027-01-14 (100 days), Let's Encrypt`. When the site has a front service, the message ends with `(edge certificate, {service name})`.
- **Data**: `subject`, `issuer`, `sans`, `valid_from`, `expires_at`, `days_remaining`, `fingerprint_sha256`, `serial`, `chain_valid`, `ip`.
- **Tracked**: `fingerprint_sha256` → `certificate.renewed`, `issuer` → `certificate.issuer_changed`.

### 9.7 `site.path` — Serving path

Checks that DNS still points at the first step of the site's serving path.

- **Arguments**: none. `max_severity: warning`.
- **Logic**:
  1. `DnsResolver::resolve(hostname)`: CNAME chain plus A/AAAA.
  2. `ExternalServiceMatcher` on the IPs and CNAME chain; `ServerMatcher` on the IPs.
  3. Compare with the declared path: with a front service, every IP must belong to that service; without one, every IP must belong to the backend server.
- **Results**:

| Situation | Result |
|---|---|
| Doesn't resolve | Warning: `Hostname does not resolve` |
| Matches the path | OK: `Via Varnish to web02` / `Direct to web02` |
| Front declared, DNS points at another service | Warning: `Resolves to Cloudflare, path says Varnish` |
| Front declared, DNS points at one of our servers | Warning: `Resolves directly to web02, path says behind Cloudflare` |
| No front, DNS points at a service | Warning: `Resolves to Cloudflare, path says direct` |
| No front, DNS points at another of our servers | Warning: `Resolves to web03, backend is web02` |
| No backend assigned | Warning: `Backend not assigned` (+ ` (suggested: web02)` when detection has a suggestion, §10.3) |
| IPs match nothing known | Warning: `Resolves to unknown IP 203.0.113.7` |

- **Data**: `ips` (null while behind a front service, because CDN IPs rotate), `cname_chain`, `matched_service`, `matched_servers`.
- **Tracked**: `ips` → `site.dns_changed`, `matched_service` → `site.front_detected_changed`.

### 9.8 `site.redirect` — Redirect

- **Arguments**: `path` (`/`), `expected_target` (null), `max_redirects` (5). `max_severity: warning`.
- **Logic**: requests are sent without automatic redirects; each hop is followed manually to record the chain. Connection failures → `CheckCouldNotRun`.
- **Results**: Warning for a loop or more than `max_redirects` hops; for a final URL different from `expected_target` (scheme, host and path; query ignored); without `expected_target`, for a final host on another registrable domain; for any https → http hop. OK: `No redirect` or `Redirects to https://www.svet24.si/ as expected`.
- **Data**: `chain`, `final_url`, `final_status`.
- **Tracked**: `final_url` → `redirect.target_changed`.

### 9.9 `server.versions` — Software versions

See §11 for the collector and endpoint.

- **Arguments**: none. `max_severity: warning`, `failures_before_incident: 1`, interval 1440. Attached only to servers with `versions_url` and a token.
- **Logic**:
  1. `VersionsClient::fetch($server->versions_url, $server->versions_token)`.
  2. Validate the file (§11.3). Any HTTP error, timeout, 401, invalid JSON or unsupported schema → `CheckCouldNotRun` with a precise message (`Token rejected (401)`, `Endpoint timed out`, `Invalid versions file: …`).
  3. If `generated_at` is older than `sextant.versions.stale_hours` (26) → `CheckCouldNotRun("Collector last ran 3 days ago")`.
  4. `VersionsRecorder` writes `server_software`, `servers.os_reported` and change-feed entries (§11.5).
  5. Compare with `minimum_versions` (§11.6).
- **Results**: Warning listing every item below its minimum, e.g. `PHP 8.2.20 < 8.2.26; nginx 1.24.0 < 1.26.2`; OK `6 readings, all at or above minimum`.
- **Data**: `read_at`, `below` (list of `{software, branch, version, min_version, cve_url}`), `counts`.
- Unknown results keep the last stored versions, so the UI shows them with their age (GOAL §5).

---

## 10. Site detection and adding things

### 10.1 Add site

An admin pastes a URL on the Add site page. `POST /sites/detect` runs `App\Detection\SiteDetector` and returns a `SiteDetectionData` JSON; nothing is written. Probes run in parallel where possible, so detection takes about 15 seconds at most.

Steps:

1. **Normalise the URL** (`UrlNormalizer`): trim; add `https://` if there's no scheme; lowercase the host; strip a trailing dot; IDN → punycode (UTS #46); reject IP literals and hosts without a dot; keep a non-root path as the HTTP monitor's `path`.
2. **Duplicates**: an existing site with that hostname → return `duplicate` with a link to it. A soft-deleted one → offer to restore.
3. **Domain**: registrable domain from the Public Suffix List. Existing → reuse (with its site count). New → run `RdapClient::domain()` for a registry preview (registrar, expiry, nameservers).
4. **DNS**: CNAME chain plus A/AAAA. Not resolving → continue with a warning; backend Not found.
5. **Front**: `ExternalServiceMatcher` on IPs and CNAME chain, then on the probe's response headers.
6. **Backend**: see §10.3.
7. **Probe**: `HttpProbe` (redirects followed and recorded) and `TlsProbe` summary.
8. **Proposed monitors**: site defaults, with `site.redirect.expected_target` set from the probe's final URL when it redirects; domain defaults when the domain is new.

`SiteDetectionData`:

```ts
type Confidence = 'detected' | 'suggested' | 'not_found'

interface Finding<T> { value: T; confidence: Confidence; explanation: string }

interface SiteDetectionData {
  input: string
  hostname: string
  path: string
  duplicate: { site_id: number; serving_path: string; trashed: boolean } | null
  domain: Finding<{ name: string; existing_id: number | null; site_count: number; registry?: RegistryPreview }>
  dns: Finding<{ ips: string[]; cname_chain: string[] }>
  front: Finding<{ service_id: number; name: string } | null>      // null value + 'detected' = direct
  backend: Finding<{ server_id: number; name: string } | null>
  backend_candidates: { server_id: number; name: string; sibling_count: number }[]
  unknown_ip: string | null                                        // offers: new server, new external service
  probe: { http: string; tls: string | null; redirect: string; final_url: string | null }
  offer_www: string | null                                         // e.g. "www.oglasi24.si" when the URL redirects there
  monitors: { checker: string; label: string | null; arguments: Record<string, unknown> }[]
}
```

### 10.2 Confidence

| Finding | Detected | Suggested | Not found |
|---|---|---|---|
| Domain | Existing or new registrable domain (always) | — | — |
| DNS | Resolves | — | Doesn't resolve |
| Front | IPs/CNAME/headers match a service, or match one of our servers (= direct) | — | IPs match nothing known |
| Backend | IPs belong to exactly one of our servers; or a configured backend header names a server (§10.3) | Behind a front, from sibling sites | No basis for a suggestion |

The admin can change any finding before saving. Changing the backend marks it "chosen by you".

### 10.3 Backend detection behind a front service

When DNS points at an external service, Sextant can't see the backend.

1. **Header (optional).** If `sextant.detection.backend_header` is set (e.g. `X-Backend`) and the probe's response carries it with a value matching a server name, the backend is **Detected**. Off by default; GOAL open question.
2. **Siblings.** Among non-deleted sites on the same domain with the same `front_service_id` and a backend set, take the most common backend. A single most common server → **Suggested**, explanation `3 of the 5 svet24.si sites behind Varnish use web02`. All siblings are listed in `backend_candidates`.
3. Otherwise **Not found**: the admin picks a server.

When DNS points directly at our addresses but at more than one server, the backend is Not found and the matched servers are the candidates.

### 10.4 Saving a site

`POST /sites` with the reviewed values, in one transaction:

1. Create the domain if new (with its default monitors).
2. Create the site with `front_service_id`, `server_id`, `description`, `github_url`.
3. Optionally create the `www.` sibling with the same path.
4. Attach default monitors (§4.4) with the detected arguments.
5. Record `inventory` activities.

After commit: claim all new monitors for an immediate run and redirect to the site page, which polls until first results arrive.

Unknown IP options on the page:

- **Add as a new server**: opens Add server prefilled with the IP; detection resumes on return.
- **It's an external service**: opens the external service form prefilled with the IP.

Sextant never creates a server from an address inside an external service's ranges (validation, §3.3).

### 10.5 Add domain

For a domain with several subdomains at once: domain name → `POST /domains/lookup` (RDAP preview) → rows of hostnames, each prefilled by running detection for that hostname and editable (front, backend) → one save creating the domain, the sites and their monitors.

### 10.6 Bulk import

`php artisan sextant:import urls.txt [--application=Name]` and an Import page (a textarea). Input is parsed by `App\Support\ImportLines` (mirrored in `resources/js/lib/importLines.ts`): one or more URLs or hostnames per line, separated by spaces; an nginx `server_name a b c;` line counts as its names; `#` comments, `_`, wildcards (`*.x`, `.x`, `x.*`), regexes (`~…`) and variables (`$host`) are left out.

The Import page also takes, for the whole batch:

- **Application**: an existing one or a new name (created by the first request, found by the rest). Every created site joins it; an existing site that is skipped as a duplicate joins it too ("Already a site; added to {app}"), so pasting an app's `server_name` line groups sites added earlier.
- **Backend server**: used for sites whose backend isn't Detected (e.g. all behind Cloudflare); those are created, not left for review. A Detected backend always wins.

Each URL runs detection:

- Everything Detected → the site is created with its path.
- Backend Suggested or Not found → the site is created with `server_id = null`; `site.path` warns `Backend not assigned (suggested: web02)`; the Sites list has a "Needs review" filter (no backend).
- Duplicates are skipped and listed.

Monitors start spread out (§5.1). The import summary lists created, needs-review and skipped hostnames.

### 10.7 Other entry points

- **Server form**: name, hostname, provider, OS, backup locations, addresses, notes; optionally the versions endpoint (§11). On save, Sextant probes ping and ports and attaches default monitors.
- **External service form**: name, kind, ranges, range source, CNAME suffixes, headers.
- **Re-run detection** on a site shows the same review and applies the changes the admin confirms.

---

## 11. Software versions

### 11.1 Overview

```text
root cron (hourly) ── sextant-collect.sh ──► /var/lib/sextant/versions.json (0640, group www-data)
                                                        │ read
Sextant ── HTTPS GET + Authorization: Bearer sxt_… ──► nginx ──► versions.php (checks token hash)
```

Sextant never logs in to servers (GOAL principle). Both files are generated per server and downloaded from the server page by admins.

### 11.2 Token

- Format: `sxt_` + 32 lowercase hex characters (128 random bits).
- "Generate token" (admin) creates one, stores it encrypted in `servers.versions_token`, stores `versions_token_last4`, and shows the full token **once**. Regenerating rotates it; the old PHP file stops working until replaced.
- `versions.php` contains only `hash('sha256', $token)`, never the token. Download endpoints build the file from the stored token at request time.

### 11.3 Collector script and file format

`GET /servers/{server}/collector.sh` serves `resources/collector/sextant-collect.sh` (the same for every server).

Requirements:

- bash, no dependencies beyond coreutils; no `jq`. Runs as root from `/etc/cron.d/sextant` (hourly).
- Never fails because a program is missing: missing software is `null`.
- Writes atomically (temp file + `mv`) to `/var/lib/sextant/versions.json`, mode `0640`, group from `SEXTANT_GROUP` (default `www-data`) at the top of the script.
- Reads:
  - OS: `/etc/os-release` (`ID`, `VERSION_ID`, `PRETTY_NAME`).
  - Kernel: `uname -r` (running).
  - nginx: `nginx -v`.
  - PHP: every `/usr/bin/php[0-9].[0-9]` and `/usr/sbin/php-fpm[0-9].[0-9]`, version via `phpX.Y -r 'echo PHP_VERSION;'` (or `php-fpmX.Y -v`), grouped by branch with `cli`/`fpm` flags.
  - MySQL: `mysqld --version`.
  - OpenSSL: `openssl version`.
  - Package versions with `dpkg-query -W -f='${Version}'` when `dpkg-query` exists (`nginx`, `phpX.Y-cli`/`phpX.Y-fpm`, `mysql-server-core-*`, `openssl`).
- Checked with `shellcheck`.

`versions.json`, schema 1:

```json
{
  "schema": 1,
  "generated_at": "2026-11-20T06:00:02Z",
  "hostname": "web02",
  "os": { "id": "ubuntu", "version_id": "24.04", "pretty_name": "Ubuntu 24.04.1 LTS" },
  "kernel": { "version": "6.8.0-49-generic" },
  "nginx": { "version": "1.26.2", "package": "1.26.2-1~noble" },
  "php": [
    { "branch": "8.3", "version": "8.3.14", "cli": true, "fpm": true, "package": "8.3.14-1+ubuntu24.04.1" },
    { "branch": "8.2", "version": "8.2.20", "cli": true, "fpm": true, "package": "8.2.20-1+ubuntu24.04.1" }
  ],
  "mysql": null,
  "openssl": { "version": "3.0.13", "package": "3.0.13-0ubuntu3.4" }
}
```

### 11.4 Endpoint and client

`GET /servers/{server}/versions.php` renders `resources/collector/versions.php.stub` with the token hash and file path.

`versions.php`:

- Runs on PHP ≥ 7.4 (older servers).
- Only `GET`; other methods → 405.
- Requires HTTPS (`$_SERVER['HTTPS']`); otherwise 403.
- Reads `Authorization: Bearer …` from `HTTP_AUTHORIZATION`; compares `hash('sha256', $token)` with the embedded hash using `hash_equals`. Missing or wrong → 401 with an empty body.
- Missing file → 503 `{"error":"versions file missing"}`.
- Otherwise returns the file with `Content-Type: application/json` and `Cache-Control: no-store`. Nothing else is exposed.

The server page shows the nginx snippet to install it:

```nginx
location = /.sextant/versions.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/sextant/versions.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # adjust to the server's PHP-FPM socket
}
```

`VersionsClient::fetch()` sends `Authorization: Bearer {token}`, verifies TLS, times out after 10 s, refuses bodies over 256 KB, and parses into a `VersionsFile` data object. "Test connection" (`POST /servers/{server}/versions/test`) runs the same fetch and returns the parsed readings or the error, without storing anything.

### 11.5 Storing readings

`VersionsRecorder::record(Server, VersionsFile)` in the run's transaction:

- Upserts one `server_software` row per reading: `os` (version = `VERSION_ID`, details = `pretty_name`), `kernel`, `nginx`, `php` per branch, `mysql`, `openssl`.
- Deletes rows for software no longer present.
- Writes `servers.os_reported` quietly.
- Records `detected` activities per change: `software.upgraded` / `downgraded` (by §11.6 comparison), `installed`, `removed`, `server.os_changed`. Example summary: `web02: nginx 1.24.0 → 1.26.2`.

The first reading of a server records nothing in the feed.

When `os_reported` doesn't correspond to the admin's OS choice, both are shown on the server page; there's no alert.

### 11.6 Minimum versions and comparison

- Admins manage minimums on the Minimum versions page: software, branch (PHP only), minimum, CVE link, note.
- Normalisation before `version_compare`:
  - Kernel: drop the flavour suffix (`-generic`, `-cloud-amd64`, …) and replace `-` with `.` (`6.8.0-49-generic` → `6.8.0.49`).
  - Others: take the leading `\d+(\.\d+)*` (`3.0.13`, `8.0.40`, `1.26.2`).
- A reading is **below** when `version_compare(normalised, min, '<')`.
- Only upstream versions are compared. Package versions are shown next to them so an admin can spot a distribution backport and acknowledge the warning (GOAL open question).
- Setting, changing or removing a minimum claims an immediate run of every enabled `server.versions` monitor (§6.4).

### 11.7 Windows and unconfigured servers

Servers without `versions_url` have no `server.versions` monitor. Their version columns show "not configured". The Add server page tells admins that the collector can't run on Windows.

---

## 12. Notifications and self-monitoring

### 12.1 Discord

**Transport.** One webhook (`DISCORD_WEBHOOK_URL`), called with `?wait=true`. Sending happens on the `notifications` queue with a single worker plus `RateLimited('discord')` at 20 per minute. HTTP 429 is honoured by releasing the job after `retry_after`. Sends failing after 5 attempts go to Sentry.

**Flush.** `notifications:flush` runs every 30 seconds:

1. Select `incident_events` where `notify = true` and `notified_at` is null, created at least 20 seconds ago (`notifications.settle_seconds`).
2. Suppress events of muted monitors (`suppressed_reason = muted`).
3. Children never notify on their own while their parent is open: if the parent's `opened` event is still pending, they wait and are folded into its embed; if it was already sent, they're suppressed (`grouped`).
4. Group the remaining events by **root resource** (the incident's own resource, or for children, the parent's server). One embed per group and event kind (problem or recovery).
5. Up to 10 embeds per message. `@here` only if an embed contains an error-severity `opened` or `escalated` event, with `allowed_mentions: {"parse": ["everyone"]}`; otherwise `{"parse": []}`.
6. Mark events `notified_at = now`; set `incidents.last_notified_at`.

**Embeds.**

| Kind | Title | Colour |
|---|---|---|
| Opened / escalated, error | `🔴 svet24.si — HTTP` | `#E53935` |
| Opened / escalated, warning | `🟠 web02 — Software versions` | `#FB8C00` |
| Resolved | `✅ Resolved: svet24.si — HTTP (15 min)` | `#43A047` |
| Acknowledged | `👀 {user} acknowledged svet24.si — HTTP` | `#757575` |
| Reminder | `⏰ Still failing: svet24.si — HTTP (4 h)` | Severity colour |

- **Description**: the result message, start time in Europe/Ljubljana and duration. For a server group, the affected sites (up to 10, then "+N more"), each with its serving path. For `server.versions`, each item below minimum with its CVE link.
- A server's resolution embed summarises children: `21 of 23 sites recovered; still failing: a.si, b.si`.
- Each embed's `url` links to the incident page. Footer `Sextant`.

### 12.2 Sentry

Sentry covers only Sextant's own health (GOAL decision 2).

- **Errors**: unexpected checker exceptions, failed jobs, Discord send failures. `CheckCouldNotRun` and site problems are **not** reported.
- **Tracing** off (`SENTRY_TRACES_SAMPLE_RATE=0`).
- **Crons**: `->sentryMonitor()` on `monitors:dispatch`; `sextant:heartbeat` every 5 minutes dispatches a `Heartbeat` job onto `checks`, `checks-slow` and `notifications`, each sending its own check-in (`sextant-queue-{queue}`).
- **Alerting**: a Sentry alert for missed or failed check-ins posts to the same Discord channel. With a 2-minute margin, a dead scheduler or worker is reported within 10 minutes.

---

## 13. User interface

### 13.1 Foundation

- Built from Laravel's Vue starter kit: Inertia, Vue 3 `<script setup lang="ts">`, Tailwind, shadcn-vue components in `resources/js/components/ui`.
- **Registration is removed.** Login, password reset and profile/password settings come from the kit.
- UI language is English. All dates come from the server as ISO 8601 UTC and are formatted in the browser in `Europe/Ljubljana`; relative times ("7 minutes ago") are computed in the browser.
- Light theme by default; the kit's dark mode stays available through shadcn tokens.

**Design reference**: the "Sextant — Admin UI prototype" canvas (screens: Overview, Servers, Server detail, Domains, Add domain, Add server, Add site). Export its artboards as PNG into `docs/design/` for the coding agent. Match structure, navigation, components and colours; pixel-perfection isn't required.

Design tokens (Tailwind theme / CSS variables):

| Token | Value | Use |
|---|---|---|
| `--status-ok` | `#16A34A` | Dots, badges |
| `--status-warning` | `#EA580C` | Dots, badges, below-minimum versions |
| `--status-error` | `#DC2626` | Dots, badges |
| `--status-unknown` | `#94A3B8` | Dots, badges |
| `--sidebar` | `#0B1220` | Sidebar background |
| `--primary` | `#1D4ED8` | Primary buttons, links |
| Fonts | Inter (UI), JetBrains Mono (hostnames, IPs, versions, tokens) | |

### 13.2 Authorization

- `users.role`: `admin` or `reader`.
- `EnsureAdmin` middleware on every route that changes data (all non-GET routes except logout and the user's own profile/password), plus the download, token and test routes. Readers get 403.
- Policies mirror this for use in controllers and to build the `can` props.
- `HandleInertiaRequests` shares `auth.user` (`id`, `name`, `role`) and `can.manage` (bool). Pages hide every create, edit, delete, run, mute, acknowledge and download control when `can.manage` is false.
- `/horizon` is admin only (`Horizon::auth`).

### 13.3 Routes

All routes require login except the kit's auth routes and `/up`.

| Method and path | Page or result | Admin only |
|---|---|---|
| `GET /` | Dashboard | |
| `GET /search?q=` | JSON results for global search | |
| `GET /servers` · `/servers/{server}` | Servers list · Server page | |
| `GET /servers/create` · `/servers/{server}/edit`, `POST/PUT/DELETE` | Add / edit server | ✓ |
| `POST /servers/{server}/restore` · `/run-checks` · `/mute` | Actions | ✓ |
| `POST /servers/{server}/versions-token` | Generate or rotate token; returns it once | ✓ |
| `GET /servers/{server}/collector.sh` · `/versions.php` | Downloads | ✓ |
| `POST /servers/{server}/versions/test` · `/versions/refresh` | Test connection (JSON) · claim versions run | ✓ |
| `GET /external-services` (+ CRUD) | External services | CRUD ✓ |
| `GET /domains` · `/domains/{domain}` | Domains with subdomains · Domain page | |
| `GET /domains/create`, `POST /domains/lookup`, `POST/PUT/DELETE` | Add domain, RDAP preview | ✓ |
| `GET /sites` · `/sites/{site}` | Sites list · Site page | |
| `GET /sites/create` · `POST /sites/detect` · `POST /sites` | Add site, detection (JSON), save | ✓ |
| `GET /sites/{site}/edit`, `PUT/DELETE`, `POST /sites/{site}/detect-again` | Edit, re-run detection | ✓ |
| `GET /sites/import` · `POST /sites/import` | Bulk import | ✓ |
| `GET /applications` · `/applications/{application}` | Applications list · Application page | |
| `GET /applications/create` · `/applications/{application}/edit`, `POST/PUT/DELETE`, `POST …/restore` | Add / edit application | ✓ |
| `GET /monitors` | Monitors list | |
| `POST /monitors`, `PUT/DELETE /monitors/{monitor}`, `POST …/run`, `…/mute` | Monitor actions | ✓ |
| `GET /incidents` · `/incidents/{incident}` | Incidents | |
| `POST /incidents/{incident}/acknowledge` | Acknowledge | ✓ |
| `GET /activity` | Change feed | |
| `GET /minimum-versions` (+ CRUD) | Minimum versions | CRUD ✓ |
| `GET /settings/lists` (+ CRUD) | OS and backup location lists | CRUD ✓ |
| `GET /users` (+ CRUD) | Users and roles | ✓ |

Route names are used through Wayfinder (or Ziggy) in Vue; no hard-coded URLs.

### 13.4 Layout and shared components

`AppLayout` follows the prototype:

- **Sidebar** (dark): Sextant mark; Overview, Servers (count), Domains (count), Applications, Monitors, Incidents (open count), Activity; an "Admin" group shown only to admins: Minimum versions, External services, Lists, Users. Stacks on phones.
- **Header**: global search field (opens the Command dialog; Ctrl/⌘ + K anywhere), system health pill ("Checks running · network OK"), user menu with role.

Shared components in `resources/js/components/app`:

| Component | Purpose |
|---|---|
| `DataTable` | Server-side table (§13.5) |
| `StatusBadge`, `StatusDot` | The four statuses in the token colours |
| `SeverityBadge` | `ERROR` / `WARNING` pills |
| `ServingPath` | `host → [Front, dashed] → [backend, solid]`; "backend not assigned" dashed grey |
| `ConfidenceBadge` | Detected / Suggested · confirm / Not found / Chosen by you |
| `VersionCell` | Version in mono; orange and bold when below minimum; package version on hover |
| `GlobalSearch` | Command dialog grouped by type, status dot per result |
| `RelativeTime` | Live-updating relative time with the absolute time as title |
| `ConfirmDialog` | Delete and other destructive actions |
| `EmptyState`, `PageHeader`, `Breadcrumbs` | |
| `MonitorArgumentsForm` | Fields from a checker's argument schema (§4.6) |
| `RepoLink` | Repository icon link (no brand logo) |

### 13.5 DataTable contract

- **Server**: `spatie/laravel-query-builder` with explicit `allowedFilters`, `allowedSorts`, `defaultSort`; `paginate(50)->withQueryString()`. Rows are laravel-data objects.
- **Props**: `{ rows: { data, meta: { current_page, last_page, per_page, total } }, filters: Record<string, string>, sort: string | null }`.
- **Client**: TanStack Table with manual sorting and pagination; filter chips, a search input (300 ms debounce) and column sort update the query with `router.get(url, params, { preserveState: true, preserveScroll: true, replace: true })`.
- Wide tables scroll horizontally inside their card.

### 13.6 Pages

| Page | Contents |
|---|---|
| **Dashboard** | Totals (servers, domains, sites, servers below minimum); Current issues (grouped, Acknowledge for admins, recovering and acknowledged markers); Recently resolved; Software vs minimum; Expiring in 60 days; Recent changes; footer with system health and pending count. Polls (§13.7) |
| **Servers** | Status, name + hostname, primary IP, OS, sites served, backups ("None set" in orange), kernel, nginx, PHP (all branches), MySQL, OpenSSL (`VersionCell`). Filters: All, Problems, Below minimum, No backup location |
| **Server page** | Header (status, hostname, all IPs, provider, OS, backup locations, actions); "How traffic reaches {server}" (sites grouped by front service or direct); Sites served (with paths); Software versions (installed, package, minimum, read time, Refresh, endpoint URL, masked token, downloads, install instructions, Test connection); Monitors; Incidents and changes (own and its sites') |
| **Add / edit server** | Basics (name, hostname, provider, OS select from the `os` list); Backups stored on (checkboxes from `backup_location`); IP addresses (primary radio); Software versions setup (generate token, download two files, install commands, endpoint URL, Test connection; Windows note); Checks (what will be probed); summary panel |
| **Domains** | Expandable rows: domain, subdomain count with failing/warning counts, expiry with days, registrar, status. Expanded: nameservers and each site with description, repo link, `ServingPath`, last result, status; "Add site on {domain}" link. Search filter; Expand/Collapse all |
| **Domain page** | Registry data, monitors, sites, incidents, activity |
| **Add domain** | §10.5 |
| **Sites** | Flat list: hostname + description, domain, application, `ServingPath`, status, last HTTP time. Filters: status, front service, backend server, application, Needs review |
| **Site page** | Hostname, description, domain, application, repo link, `ServingPath`, latest results per monitor, monitors, incidents, activity; actions: edit, re-run detection, run checks, mute |
| **Add site** | URL field + Detect; "What Sextant found" (findings with `ConfidenceBadge` and explanations; backend select); Serving path preview; First probe; Details (description, GitHub repository); Checks to attach; `www.` offer; Add site. Side panel "How detection works". Duplicate and invalid states |
| **Import** | Textarea of URLs or nginx `server_name` lines; application and backend for all (§10.6) → summary of created, needs review, skipped |
| **Applications** | Name + description, hostnames, site count, worst site status. Search, Deleted filter |
| **Application page** | Name, description, repo link, notes; its hostnames with domain, `ServingPath` and status; changes of it and its sites; actions: edit, delete, add hostnames (opens Import with the application chosen) |
| **External services** | Name, kind, ranges (count, source), sites in front of |
| **Monitors** | Global list (filters: checker, status, enabled, muted, pending); monitor drawer with effective settings, state, last 100 runs; form with `MonitorArgumentsForm` |
| **Incidents** | List (filters: open / acknowledged / resolved, severity, resource type, server, checker) and incident page (timeline, children, first/last result, links) |
| **Activity** | Feed with filters: category, type, resource, server, user |
| **Minimum versions** | Software, branch, minimum, CVE link, note, servers below (count + names), set by, date |
| **Lists** | OS and backup location lists: add, rename, reorder; delete when unused |
| **Users** | Name, email, role, created; invite/create, change role, delete. An admin can't demote or delete themselves if they are the last admin |

### 13.7 Live updates

- **Dashboard**: `usePoll(10_000, { only: ['totals', 'issues', 'muted', 'pending_count', 'resolved', 'software', 'expiring', 'changes', 'system'] })`.
- **Server, site and incident pages**: poll `monitors`, `incidents` and `activity` every 10 s.
- **Lists**: no polling; a "Refresh" button.
- Acknowledge, Run checks and Refresh use `router.post()` and reload the affected props.

Dashboard props (built by `DashboardData` with a handful of queries against materialised statuses):

```ts
type Status = 'ok' | 'warning' | 'error' | 'unknown'

interface DashboardProps {
  totals: Record<'servers' | 'domains' | 'sites', StatusCounts> & { sites_unassigned: number; servers_below_minimum: number }
  issues: IssueGroup[]          // open root incidents grouped by resource, worst first, then oldest first
  muted: IssueGroup[]           // shown collapsed
  pending_count: number
  resolved: ResolvedItem[]      // root incidents resolved in the last 24 h, max 20
  software: SoftwareMinimumRow[]
  expiring: ExpiringItem[]      // certificates and domains within 60 days
  changes: ActivityItem[]       // latest 50
  system: { last_dispatch_at: string | null; stale_monitors: number; network_healthy: boolean | null }
}

interface StatusCounts { total: number; ok: number; warning: number; error: number; unknown: number }
interface IssueGroup { resource: ResourceRef; incidents: IncidentItem[]; children: IncidentItem[] }
interface IncidentItem {
  id: number; title: string; message: string; severity: 'warning' | 'error'
  opened_at: string; acknowledged_by: string | null; recovering: boolean
  serving_path: string | null; url: string
}
interface ResolvedItem { id: number; title: string; opened_at: string; resolved_at: string; children_count: number; url: string }
interface SoftwareMinimumRow { software: string; branch: string; min_version: string | null; below: { server: string; version: string }[] }
interface ExpiringItem { kind: 'certificate' | 'domain'; name: string; expires_at: string; days: number; url: string }
interface ActivityItem { id: number; category: 'status' | 'inventory' | 'detected'; summary: string; user: string | null; created_at: string; url: string | null }
interface ResourceRef { type: 'server' | 'domain' | 'site'; id: number; name: string; status: Status; url: string }
```

These types are generated from laravel-data classes, not written by hand.

### 13.8 Global search

`GET /search?q=` (min 2 characters, 250 ms debounce) returns up to 20 results grouped by type:

| Type | Matches |
|---|---|
| Server | name, hostname, any address, OS (choice or reported), any `server_software.version` (e.g. `8.2.20`) |
| External service | name |
| Domain | name |
| Site | hostname, description |
| Application | name, description |

Prefix and `LIKE '%q%'` queries are fast at this size; no search engine. Each result has its status, a one-line context (e.g. `192.0.2.12 · Ubuntu 24.04 · PHP 8.3.14, 8.2.20 · 24 sites`), and its URL. Enter opens the first result.

---

## 14. Commands and schedule

| Command | Schedule | Purpose |
|---|---|---|
| `monitors:dispatch` | every minute, `withoutOverlapping`, Sentry Crons | Claim and queue due monitors (§5.1) |
| `notifications:flush` | every 30 seconds, `withoutOverlapping` | Send pending incident events (§12.1) |
| `sextant:sweep` | every minute, `withoutOverlapping` | Refresh statuses; stale states; reminders; mute-ended and detached-child reminders |
| `sextant:heartbeat` | every 5 minutes | Queue heartbeats to Sentry Crons (§12.2) |
| `model:prune --model=MonitorRun` | daily 03:30 | 90-day retention |
| `proxies:refresh` | daily | Refresh published ranges of external services with a `ranges_source` |
| `rdap:refresh-bootstrap` | weekly | Refresh the IANA RDAP bootstrap and the Public Suffix List |
| `horizon:snapshot` | every 5 minutes | Horizon metrics |
| `typescript:transform` | build step | Generate TypeScript types from laravel-data classes |
| `sextant:create-admin` | manual | Create the first admin |
| `monitors:sync-defaults --checker=… [--dry-run]` | manual | §4.4 |
| `sextant:import {file}` | manual | Bulk detection (§10.6) |
| `monitors:run {monitor} [--no-save]` | manual | Run one monitor synchronously and print the result |
| `sextant:checkers` | manual | List the registry and validate it |

Sub-minute scheduling (`notifications:flush`) requires `schedule:work` under systemd/supervisor rather than a cron-invoked `schedule:run`.

---

## 15. Configuration

### 15.1 `config/sextant.php`

```php
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
```

`proxies:refresh` downloads the range lists, stores parsed CIDRs in cache and in `storage/app/proxy-ranges.json` (the fallback if a download fails).

### 15.2 Environment

| Variable | Notes |
|---|---|
| `APP_URL` | Used for links in Discord |
| `APP_TIMEZONE=UTC` | |
| `DB_CONNECTION=mysql`, `DB_*` | MySQL 9.7. Prefer `DB_SOCKET` on the same host; otherwise TLS (`MYSQL_ATTR_SSL_CA`) |
| `REDIS_*`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` | |
| `DISCORD_WEBHOOK_URL` | A secret: anyone holding it can post to the channel |
| `SENTRY_LARAVEL_DSN`, `SENTRY_TRACES_SAMPLE_RATE=0` | |
| `SEXTANT_BACKEND_HEADER` | Optional (§10.3) |

---

## 16. Deployment

- **Server**: its own machine outside the monitored infrastructure (GOAL constraint), preferably at a different provider.
- **Processes**: nginx + php-fpm, MySQL 9.7, Redis, Horizon and `schedule:work` under supervisor or systemd.
- **Build**: `composer install --no-dev`, `npm ci && npm run build` (runs `typescript:transform` first), `php artisan migrate --force`, config/route/view caches.
- **MySQL 9.7**: `mysql_native_password` no longer exists; create the Sextant user with `caching_sha2_password` and connect over the Unix socket or TLS.
- **Horizon supervisors**:

  | Queue | Processes |
  |---|---|
  | `checks` | Autoscale 5–20 |
  | `checks-slow` | 2 |
  | `notifications` | 1 |
  | `default` | 2 |

  Job timeout 60 s; Redis `retry_after` 90 s.
- **Packages**: `iputils-ping` (`cap_net_raw`); PHP extensions `intl`, `openssl`, `curl`, `redis`, `pdo_mysql`.
- **Firewall (outbound)**: 53/udp and 53/tcp, 80, 443, 43 (WHOIS), and any checked TCP ports.
- **On the monitored servers**:
  - Add Sextant's IP to fail2ban `ignoreip` (port 22 checks every minute).
  - Allowlist Sextant's IP in Cloudflare/WAF rules so HTTP checks aren't challenged.
  - Install the collector and endpoint (§11), with an nginx location on the server's own HTTPS hostname.
- **Backups**: daily MySQL dump. Incidents, software history and the change feed are kept; runs can be excluded.
- **Security**: Sextant requests whatever URLs and IPs admins enter, including private ranges, by design. Only signed-in in-house admins can enter them. Version tokens are encrypted at rest and never sent to the browser after generation.

---

## 17. Testing

No live network in the default suite. All network I/O goes through the `app/Net` wrappers, which have fakes. CI runs Pint, Larastan, Pest (against a **MySQL 9.7** service container), ESLint, `vue-tsc --noEmit`, Vitest, and `shellcheck` on the collector.

**Backend unit tests**
- `StateMachine`: result sequences against counters, effective status and incident actions (thresholds, unknowns inside streaks, escalation/de-escalation, recovery, stale states, `max_severity` caps).
- Argument and threshold resolution; registry validation.
- `UrlNormalizer`: case, trailing dots, IDN (`šola.si`), ports, paths, IP literals.
- `ExternalServiceMatcher` and `ServerMatcher`: IPv4/IPv6 ranges, CNAME suffixes, headers.
- Backend suggestion (§10.3): one sibling, a tie, none, mixed fronts, header detection on and off.
- RDAP parsing fixtures (`svet24.si` date-only events, `.com` RFC 3339, 404, `pending delete`); WHOIS fixtures.
- `ChangeDetector` and `VersionsRecorder`: first run records nothing; upgrades, downgrades, installs, removals; OS change.
- Version normalisation and comparison (§11.6), including kernel strings.
- `VersionsFile` validation: schema 1, missing fields, `null` software, extra fields ignored.

**Backend feature tests**
- `RunMonitor` end to end with a `FakeChecker` registered under `site.fake`.
- Dispatch compare-and-set; confirmation re-check; canary.
- Unique open incident per monitor under two concurrent runs (MySQL 9.7).
- Grouping by backend server: adoption, child suppression, resolution summary, detached-child reminders.
- Flush: `@here` rules, 10-embed split, muted suppression, 429 (`Http::fake`).
- Detection (§10): direct match, behind Cloudflare with a suggestion, behind Varnish (manual ranges), new domain, unknown IP, duplicate and restore, redirect setting `expected_target` and offering `www.`.
- Import: created, needs review, skipped.
- `server.versions`: OK, below minimum (Warning), 401 / timeout / stale file (Unknown, versions kept), minimum change triggering runs.
- Download endpoints: `versions.php` contains the token hash and never the token; collector served unchanged.
- The rendered `versions.php` served with `php -S` against a fixture file: 401 without/with a wrong token, 405 on POST, 200 with the right token.
- **Authorization matrix**: every route in §13.3 marked admin-only returns 403 for a reader and succeeds for an admin; readers' Inertia props have `can.manage = false`.
- Inertia pages: `assertInertia` on component name and key props for each page.
- Observers: admin edits record activities; `saveQuietly()` system writes don't; secrets never appear in `properties`.

**Frontend tests (Vitest)**: `DataTable` (query updates), `StatusBadge`, `ServingPath`, `ConfidenceBadge`, `VersionCell`, `GlobalSearch` keyboard handling, `MonitorArgumentsForm` field mapping.

**Collector**: `shellcheck`; a `--group=live` test runs the script in Ubuntu 24.04 and Debian 12 containers and validates the JSON against schema 1.

**Live tests** (`--group=live`, never in CI): real RDAP for `svet24.si`, TLS inspect of `svet24.si`, a DNS resolve.

---

## 18. Build phases

Each phase ends with something usable and an exit check.

### Phase 0 — Foundation

- Laravel app from the Vue starter kit; registration removed; `users.role`; `EnsureAdmin`, policies scaffold, `can` props; `sextant:create-admin`.
- MySQL 9.7 (socket or TLS, `caching_sha2_password`), Redis, Horizon, Sentry, `schedule:work`.
- laravel-data + TypeScript transformer, Wayfinder (or Ziggy), query-builder.
- App shell per the prototype: sidebar, header with search placeholder, user menu with role; design tokens and fonts.
- Shared components: `DataTable`, `StatusBadge`/`StatusDot`, `SeverityBadge`, `ServingPath`, `ConfidenceBadge`, `VersionCell`, `RelativeTime`, `ConfirmDialog`, `PageHeader`, form field wrappers.
- CI: Pint, Larastan, Pest on MySQL 9.7, ESLint, `vue-tsc`, Vitest.
- **Spikes** (`docs/spikes/`): MySQL 9.7 generated-column unique indexes and the `RESTRICT` foreign key; Net_DNS2 (resolvers, timeouts, authoritative SOA); TLS verification with `spatie/ssl-certificate`; every TLD we use (RDAP or WHOIS); ICMP on the server; pinned package versions.
- **Exit**: deployed; an admin and a reader can log in; the reader sees no edit controls and gets 403 on a sample mutation; a demo `DataTable` sorts, filters and paginates server-side; Sentry cron check-in visible; spike notes written.

### Phase 1 — Inventory and overview

- Migrations: `choices`, `servers`, `server_addresses`, `server_backup_location`, `external_services`, `domains`, `sites`, `activities`; morph map; seeds (choices, Cloudflare).
- Pages: Servers (list, page, add/edit with OS and backups), External services, Domains (expandable list, page), Sites (list, page, edit with serving path, description, GitHub link), Lists, Users.
- `ServingPath` everywhere; server page "How traffic reaches" and sites served.
- Inventory activities via observers; Activity page.
- Global search (§13.8) with Ctrl/⌘ + K.
- **Exit**: all ~20 servers, Cloudflare and Varnish entered; a sample of domains and sites entered by hand with paths; search finds servers by IP and sites by description; a reader can browse everything and change nothing.

### Phase 2 — Add site, Add domain and import

- `app/Net` wrappers (DNS, TCP, TLS, HTTP, ping, RDAP, WHOIS) with fakes; `UrlNormalizer`; PSL; `ServerMatcher`; `ExternalServiceMatcher`; `proxies:refresh`; `rdap:refresh-bootstrap`.
- `SiteDetector`, `POST /sites/detect`, Add site page, saving (§10.4) — monitors attached in Phase 3.
- Add domain with subdomains; Import page and `sextant:import`; re-run detection.
- **Exit**: all ~80 sites imported; every site has a domain and a backend or is in "Needs review"; `vestnik.svet24.si`-style and Cloudflare-style examples produce the right findings.

### Phase 3 — Monitoring engine and core checkers

- Migrations: `monitors`, `monitor_states`, `monitor_runs`.
- Registry, `Arguments`, argument schema, `sextant:checkers`.
- Dispatcher, `RunMonitor`, `NetworkHealth`, effective status (failures show as pending), `ResourceStatus`, `ChangeDetector`, `sextant:sweep`, pruning, `monitors:run`, `monitors:sync-defaults`.
- Checkers: `server.tcp`, `site.http`, `site.ssl`, `site.path`, `domain.expiration`.
- Default monitors on create; backfill for existing resources.
- Monitors UI: tabs on resource pages, global list, `MonitorArgumentsForm`, run now.
- **Exit**: all default monitors run for 48 hours with no stuck or duplicate jobs in Horizon; 10 results spot-checked by hand.

### Phase 4 — Incidents

- Migrations: `incidents`, `incident_events`.
- Full state machine; acknowledge (admins); muting (monitor, resource, server plus sites using it as backend); dependency checks, adoption and grouping by backend server.
- Incidents list and page; incident tabs on resource pages, including the server roll-up; `status` activities.
- **Exit**: firewalling a test server's port 443 from Sextant opens exactly one server incident with its sites as children; lifting the block resolves them all.

### Phase 5 — Notifications and self-monitoring

- `notifications:flush`, embeds (with serving paths), grouping, `@here` rules, reminders, rate limiting.
- `sextant:heartbeat`, Sentry Crons, Sentry alert routing to Discord.
- **Exit**: the Phase 4 outage produces one Discord message for the outage and one for the recovery; stopping Horizon produces a Sentry alert in Discord within 10 minutes.

### Phase 6 — Live dashboard

- `DashboardData`, Dashboard page and components, polling, acknowledge from the dashboard, system health in the header and footer. (Software vs minimum panel arrives in Phase 7.)
- Polling on server, site and incident pages.
- **Exit**: during a scripted outage the dashboard shows the issue within 10 seconds of the incident opening, without a reload.

### Phase 7 — Software versions

- Migrations: `server_software`, `minimum_versions`; server versions columns.
- Collector script, `versions.php` stub, token generation, downloads, install instructions, Test connection, Refresh.
- `VersionsClient`, `VersionsRecorder`, `server.versions` checker and its default attachment.
- Minimum versions page; versions columns and filters on Servers; versions panel on the server page; Software vs minimum on the dashboard; search by version; change-feed entries.
- **Exit**: collector and endpoint installed on two real servers, one with two PHP versions; setting a minimum above a server's version turns it orange within a minute and posts a Discord warning without `@here`; upgrading clears it; stopping the cron shows the reading's age with no alert.

### Phase 8 — Remaining checkers

- `domain.nameservers`, `site.redirect`, `server.ping`, each with its `only_if` rule; sync onto existing resources with `monitors:sync-defaults`.
- **Exit**: every checker in GOAL's table runs on existing resources.

### Phase 9 — Hardening and launch

- One week of real data; review every incident; tune thresholds and intervals.
- Check every GOAL success criterion against reality.
- README runbook: deploying, adding a checker (class + config + test), installing the collector, rotating a token, handling a false positive.
- Backups verified.
- **Exit**: the team uses Sextant as its source of truth and the GOAL success criteria are met.

---

## 19. Risks and open items

| Item | Mitigation |
|---|---|
| CDN blind spots: behind Cloudflare we only see the edge | Accepted for v1. Certificate messages say "edge certificate"; the backend server has its own TCP checks |
| Backend behind a front service is only suggested | Admin confirms; optional backend header (§10.3, GOAL open question) |
| Distribution backports make versions look older than they are | Package versions shown beside upstream; admins acknowledge (GOAL open question) |
| Versions endpoint exposure | HTTPS only, token hash only on the server, constant-time comparison, minimal data, token rotation |
| One probe location | The canary covers Sextant's own connectivity, not partial routes. Revisit in Phase 9 if false positives show up |
| Bot protection or fail2ban blocking Sextant | Allowlisting (§16), done before Phase 3 goes live |
| TLDs without RDAP and with hard-to-parse WHOIS | Phase 0 spike lists every TLD; per-pattern fixtures |
| `monitor_runs` growth (~17 M rows at 90 days) | Indexed for its two access paths; pruned daily |
| Discord rate limits during large outages | Grouping, 10-embed batches, a single sender, 429 handling |
| Webhook URL leaks | Env only; rotate in Discord if exposed |
| Interface scope creep (full control over the UI) | v1 screens = the prototype's screens; anything more goes to "After v1" |
| PHP and TypeScript types drifting | Types generated from laravel-data on every build; `vue-tsc` in CI |
| Readers acknowledging incidents | Admins only until decided (GOAL open question) |
