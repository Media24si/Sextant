# Sextant v1 — Agent TODO

> Derived from GOAL.md v0.3 and SPEC.md v0.2 (2026-11-20). Section refs like **§10.3** point to SPEC.md; **GOAL §5** points to GOAL.md. Stack: Laravel + Inertia + Vue 3 + TypeScript + shadcn-vue, MySQL 9.7 LTS, Redis + Horizon.

## How to work through this list

1. Read GOAL.md and SPEC.md in full before starting, and the screenshots in `docs/design/`. **If they conflict, GOAL.md wins**: log the conflict under "Spec issues found" and follow GOAL.
2. Work phase by phase. Don't start a phase until the previous phase's **Exit** box is ticked.
3. One task (or a small group of related tasks) per branch and pull request. Tick a task only when its code and tests are in, and Pint, Larastan, Pest, ESLint, `vue-tsc` and Vitest all pass.
4. Tasks marked **[HUMAN]** need access or decisions you don't have (servers, Sentry, Discord, real data). Prepare everything you can, then stop and ask.
5. No live network in the default test suite. All network I/O goes through `app/Net` wrappers with fakes (§9.0). Live tests go in `--group=live`.
6. Never invent behaviour the spec doesn't define. If something is ambiguous, pick the option closest to GOAL's principles, leave a `// SPEC:` comment, and log it below.
7. UI: match the prototype's structure, navigation and components (§13). Don't add screens or features the prototype and spec don't have.

### Non-negotiable rules (from GOAL "Principles")

- Overview first: the inventory, serving paths and versions must be right before monitoring is polished.
- Unknown is not Error: a check that couldn't run never counts towards an incident.
- A single failed check never notifies. Incidents open after N failures and resolve after M passes.
- Every non-OK effective status is backed by an incident (§6.1). No manual "resolve".
- System writes use `saveQuietly()` and never appear as inventory activity.
- Checker = code, monitor = config. A new check is a class + config entry + test, no migration.
- Detection proposes, the admin confirms. Never create a server from an external service's address.
- Sextant never holds SSH or other credentials to our servers. Version tokens are encrypted and never sent to the browser after generation.
- Readers change nothing: every mutating route is admin-only and tested (§13.2, §17).
- Store UTC, display Europe/Ljubljana. Hostnames lowercase punycode, no trailing dot.

---

## Phase 0 — Foundation (§18)

- [x] **P0.1** New app from Laravel's **Vue starter kit** (TypeScript). PHP ≥ 8.4 with `intl`, `openssl`, `curl`, `redis`, `pdo_mysql`. `APP_TIMEZONE=UTC`. Remove registration routes, pages and tests.
- [x] **P0.2** MySQL 9.7: `.env.example` for socket or TLS; document creating the user with `caching_sha2_password` (§16).
- [x] **P0.3** `users.role` (`admin`/`reader`, default `reader`); `EnsureAdmin` middleware; policy scaffold; `HandleInertiaRequests` shares `auth.user` (id, name, role) and `can.manage` (§13.2).
- [x] **P0.4** `php artisan sextant:create-admin` (name, email, password prompt).
- [x] **P0.5** Redis + Horizon with supervisors `checks` (5–20), `checks-slow` (2), `notifications` (1), `default` (2); job timeout 60 s, `retry_after` 90 s; `/horizon` admin only (§16).
- [x] **P0.6** Sentry with `SENTRY_TRACES_SAMPLE_RATE=0` and one scheduled task using `->sentryMonitor()`. Scheduler documented for `schedule:work` (§14).
- [x] **P0.7** `config/sextant.php` skeleton exactly as §15.1; `.env.example` with every variable in §15.2.
- [x] **P0.8** Install and wire `spatie/laravel-data` + TypeScript transformer (types into `resources/js/types/generated.d.ts`, run before `npm run build`), `spatie/laravel-query-builder`, Wayfinder (fall back to Ziggy if unsuitable; note the choice in `docs/spikes/versions.md`).
- [x] **P0.9** App shell per the prototype (§13.4): dark sidebar with Overview, Servers, Domains, Incidents, Activity and the admin-only group; header with a global search field placeholder, system health pill and user menu showing the role. Design tokens and fonts (§13.1). Responsive: sidebar stacks on phones.
- [x] **P0.10** Shared components (§13.4): `DataTable` (§13.5), `StatusBadge`, `StatusDot`, `SeverityBadge`, `ServingPath`, `ConfidenceBadge`, `VersionCell`, `RelativeTime`, `ConfirmDialog`, `PageHeader`, `Breadcrumbs`, `EmptyState`, `RepoLink`, form field wrappers (label, input, error) on shadcn-vue.
- [x] **P0.11** A temporary demo page using `DataTable` against a seeded table, proving server-side sort, filter chips, search and pagination via query string.
- [x] **P0.12** CI: Pint, Larastan, Pest against a **MySQL 9.7** service container, ESLint, `vue-tsc --noEmit`, Vitest.
- [x] **P0.13** Spikes, each in `docs/spikes/<name>.md`:
  - [x] MySQL 9.7: stored generated column + unique index for soft-delete uniqueness; `open_monitor_id` unique index; `RESTRICT` FK on its base column (§3.11, §3.17).
  - [x] Net_DNS2: custom resolvers, timeouts, CNAME chain, authoritative SOA query.
  - [x] TLS: verifying connection plus `spatie/ssl-certificate` fields.
  - [x] RDAP via IANA bootstrap; record the `svet24.si` response as a fixture (date-only events).
  - [x] **[HUMAN]** List every TLD Media24 uses; confirm RDAP or WHOIS parsing for each.
  - [x] **[HUMAN]** Confirm ICMP `ping` works for the php-fpm and Horizon users on the Sextant server.
  - [x] Pin package versions in `composer.json` / `package.json`; list them in `docs/spikes/versions.md`.
- [ ] **P0.14** **[HUMAN]** Deploy to the dedicated Sextant server (§16): nginx + php-fpm, MySQL 9.7, Redis, Horizon and `schedule:work` under supervisor/systemd; outbound firewall per §16.
- [x] **P0.15** **[HUMAN]** Export the prototype's artboards as PNG into `docs/design/` (Overview, Servers, Server detail, Domains, Add domain, Add server, Add site).
- [ ] **Exit P0**: deployed; an admin and a reader can log in; the reader sees no edit controls and gets 403 on a sample mutation; the demo `DataTable` works server-side; a Sentry cron check-in is visible; spike notes written.

---

## Phase 1 — Inventory and overview (§18)

### Data
- [x] **P1.1** Enforced morph map (§3).
- [x] **P1.2** `Status` enum with `rank()` and `isFailure()` (§4.2).
- [x] **P1.3** Migrations + models: `choices` (§3.4) with seeds; `servers` (soft deletes, §3.2, `versions_token` encrypted cast, hidden from serialisation); `server_addresses` (§3.3); `server_backup_location` pivot; `external_services` (soft deletes, §3.5) with the Cloudflare seed; `domains` (§3.6); `sites` (§3.7: `front_service_id`, `server_id`, `description`, `github_url`).
- [x] **P1.4** Soft-delete uniqueness via stored generated `active_*` columns on `servers.name`, `external_services.name`, `domains.name`, `sites.hostname` (§3.17).
- [x] **P1.5** Validation: IP belongs to at most one non-deleted server and not inside any external service's ranges (§3.3); domain with live sites can't be deleted; external service used by sites can't be deleted; choice in use can't be deleted; `github_url` format.
- [x] **P1.6** `activities` table and `ActivityRecorder::record(...)` (§3.13, §8.1).
- [x] **P1.7** Observers on `Server`, `ServerAddress`, `ExternalService`, `Domain`, `Site`, `User` and the backup pivot, including special types `site.path_changed`, `server.address_added/removed`, `server.backup_locations_changed`, `user.role_changed`; exclude secrets and system-maintained columns (§8.2).
- [x] **P1.8** Accessors: `$site->url`; Unicode display via `idn_to_utf8()`; `$site->servingPath()` returning front + backend.
- [x] **P1.9** laravel-data classes for every page's rows and forms; generated TypeScript types used by pages.

### Pages (admin-only actions behind `EnsureAdmin`, §13.3)
- [x] **P1.10** Servers list (§13.6): status, name + hostname, primary IP, OS, sites served, backups ("None set" in orange); version columns rendered as "not configured" until Phase 7. Filters: All, Problems, No backup location. Remove the P0.11 demo when this lands: `DemoTableController`, `pages/demo/Table.vue`, `UserRowData`, `DemoTableSeeder`, `DemoTableTest`, the `demo.table*` routes and their rows in `AuthorizationMatrixTest`.
- [x] **P1.11** Server page: header (status, hostname, IPs, provider, OS, backup locations, actions); "How traffic reaches {server}"; sites served with `ServingPath`; activity tab.
- [x] **P1.12** Add / edit server: basics with OS select from the `os` list; backup checkboxes from `backup_location`; addresses with a primary radio; versions section as a disabled placeholder until Phase 7. Delete and restore.
- [x] **P1.13** External services: list and form (name, kind, ranges, range source, CNAME suffixes, headers).
- [x] **P1.14** Domains list with expandable subdomains (§13.6): counts, expiry, registrar, status; expanded rows show description, repo link, `ServingPath`, status; Expand/Collapse all; filter. Domain page.
- [x] **P1.15** Sites list (filters: status, front service, backend, Needs review); site page; edit site (front service select, backend select, description, GitHub link, scheme, port, notes). Add site is Phase 2: until then a simple manual form.
- [x] **P1.16** Lists page (OS and backup locations: add, rename, reorder, delete when unused).
- [x] **P1.17** Users page (admin only): list, create, change role, delete; never remove the last admin.
- [x] **P1.18** Activity page with filters (category, type, resource, server, user).
- [x] **P1.19** Global search: `GET /search` (§13.8) and the `GlobalSearch` Command dialog with Ctrl/⌘ + K; versions matching is added in Phase 7.

### Tests
- [x] **P1.20** Authorization matrix test covering every admin-only route so far (403 for reader, success for admin); grow it in every later phase (§17).
- [x] **P1.21** Observers (admin edits recorded; quiet writes not; secrets never stored); validation rules; `assertInertia` for each page; Vitest for `DataTable`, `ServingPath`, `StatusBadge`, `GlobalSearch`.

- [ ] **Exit P1** **[HUMAN]**: all ~20 servers, Cloudflare and Varnish entered; a sample of domains and sites entered by hand with paths; search finds servers by IP and sites by description; a reader can browse everything and change nothing.

---

## Phase 2 — Add site, Add domain and import (§18)

### Network and matching
- [x] **P2.1** `app/Net` interfaces, real implementations and fakes: `DnsResolver`, `TcpProbe`, `TlsProbe`, `HttpProbe`, `PingProbe`, `RdapClient` (IANA bootstrap cached, weekly refresh, 12 h cache unless `fresh`), `WhoisClient` (§9.0). User agent from config.
- [x] **P2.2** `UrlNormalizer` (§10.1 step 1); registrable domain via PSL.
- [x] **P2.3** `ServerMatcher` and `ExternalServiceMatcher` (manual ranges + cached source ranges, CNAME suffixes, response headers) using `IpUtils` (§9.0).
- [x] **P2.4** `proxies:refresh` (daily) and `rdap:refresh-bootstrap` (weekly, also refreshes the PSL) (§14).

### Detection and saving
- [x] **P2.5** `SiteDetector` producing `SiteDetectionData` exactly as §10.1, with confidence rules (§10.2) and backend detection behind a front service: optional header, siblings, not found (§10.3). Probes in parallel; ≤ 15 s.
- [x] **P2.6** `POST /sites/detect` (admin, JSON) and the **Add site** page (§13.6): URL + Detect, findings with `ConfidenceBadge`, backend select ("Chosen by you" on change), serving path preview, first probe, Details (description, GitHub), checks to attach (display only until Phase 3), `www.` offer, duplicate and invalid states, "How detection works" panel, unknown-IP actions (new server / external service, prefilled).
- [x] **P2.7** `POST /sites` saving in one transaction (§10.4); redirect to the site page. Monitor attachment is stubbed with a clear hook until Phase 3.
- [x] **P2.8** **Add domain** (§10.5): `POST /domains/lookup` RDAP preview; subdomain rows prefilled by detection; one save.
- [x] **P2.9** Import page and `sextant:import urls.txt` (§10.6): created / needs review / skipped summary.
- [x] **P2.10** Re-run detection on a site (§10.7).
- [x] **P2.11** "Add site" buttons per the prototype: primary on Overview and Domains, "Add site on {domain}" in expanded domains, link from Add domain.

### Tests
- [x] **P2.12** `UrlNormalizer` (case, trailing dot, `šola.si`, ports, paths, IP literals); matchers (v4/v6 ranges, CNAME, headers); backend suggestion (one sibling, tie, none, mixed fronts, header on/off); detection feature tests (direct, Cloudflare, Varnish, new domain, unknown IP, duplicate/restore, redirect → `expected_target` + `www.` offer); import outcomes; RDAP fixtures; WHOIS fixtures (§17).

- [ ] **Exit P2** **[HUMAN]**: all ~80 sites imported; every site has a domain and a backend or is in "Needs review"; a Varnish site and a Cloudflare site produce the right findings and suggestions.

---

## Phase 3 — Monitoring engine and core checkers (§18)

### Data
- [x] **P3.1** `monitors` (§3.8), `monitor_states` (§3.9), `monitor_runs` (§3.10, no FK, `MassPrunable` at 90 days).

### Registry and contracts (§4)
- [x] **P3.2** `Checker`, `CheckResult`, `CheckCouldNotRun`, base classes `ServerChecker`, `DomainChecker`, `SiteChecker`.
- [x] **P3.3** `config/checkers.php` exactly as §4.3 and a `CheckerRegistry` service.
- [x] **P3.4** `Argument` schema builder and `Arguments` value object with typed getters that throw on unknown keys; settings resolution (§4.5); schema serialised to JSON for the UI (§4.6).
- [x] **P3.5** `sextant:checkers` validation command.

### Running (§5)
- [x] **P3.6** `monitors:dispatch` (compare-and-set claim, batches of 200, 50 s cap, Sentry cron) and a shared `MonitorClaimer`.
- [x] **P3.7** `RunMonitor` job (§5.2) and the pipeline exactly as §5.3.
- [x] **P3.8** `NetworkHealth::isHealthy()` (§5.4).
- [x] **P3.9** State machine **without incidents** (failures show as pending), stale handling, confirmation re-check (§5.1, §6.1, §6.2).
- [x] **P3.10** `ResourceStatus::refresh()` (§7); `ChangeDetector` + `detected` activities (§8.3).
- [x] **P3.11** `sextant:sweep` (statuses, stale states), pruning schedule, `monitors:run`, `monitors:sync-defaults --checker` (required) (§14).

### Checkers (§9)
- [x] **P3.12** `server.tcp` (§9.1).
- [x] **P3.13** `site.http` (§9.5).
- [x] **P3.14** `site.ssl` (§9.6), with "(edge certificate, {service})" behind a front service.
- [x] **P3.15** `site.path` (§9.7) with every situation in its table.
- [x] **P3.16** `domain.expiration` (§9.3), RDAP then WHOIS, side effects written quietly.

### Defaults and UI
- [x] **P3.17** Default monitors on create with `only_if` conditions (`PortIsOpen`, `UsesHttps`); wire Phase 2's hook; spread first runs on import; backfill command for existing resources (§4.4).
- [x] **P3.18** Monitors UI: tab on server, domain and site pages; global Monitors list (filters: checker, status, enabled, muted, pending); drawer with effective settings, state and last 100 runs; form with `MonitorArgumentsForm` (empty = inherit, inherited value as placeholder); Run now; §6.4 effects.
- [x] **P3.19** Tests: StateMachine table tests (no incidents yet), settings resolution, registry validation, `RunMonitor` end to end with `FakeChecker`, dispatch double-claim prevention, canary, `ChangeDetector`, `site.path` situations.
- [ ] **P3.20** **[HUMAN]** Before go-live: Sextant's IP in fail2ban `ignoreip` on monitored servers and allowlisted in Cloudflare/WAF (§16).

- [ ] **Exit P3** **[HUMAN]**: all default monitors run for 48 h with no stuck or duplicate jobs in Horizon; 10 results spot-checked by hand.

---

## Phase 4 — Incidents (§18)

- [x] **P4.1** `incidents` (§3.11: `RESTRICT` FK, `server_id` snapshot of the backend, generated unique `open_monitor_id`, scopes) and `incident_events` (§3.12).
- [x] **P4.2** `IncidentManager`: open (duplicate-safe), escalate, de-escalate, acknowledge, reminder, resolve; events with `notify` flags (§6.3); `status` activities.
- [x] **P4.3** Complete the state machine (§6.2); pending and recovering flags.
- [x] **P4.4** Resolution on monitor disable/delete and resource delete. No manual resolve.
- [x] **P4.5** Acknowledge (admins only) from incident pages.
- [x] **P4.6** Muting (§6.6): monitor, resource, and server plus every site whose backend it is.
- [x] **P4.7** Dependency checks, adoption and grouping by backend server (§6.5).
- [x] **P4.8** Sweep additions: reminders, mute-ended, detached-child (§6.3, §6.5, §6.6).
- [x] **P4.9** Incidents list and page (§13.6); incident tabs on resource pages including the server roll-up via `server_id`.
- [x] **P4.10** Tests: full StateMachine tables, unique open incident under two concurrent runs, adoption by backend, detached-child reminders, authorization for acknowledge.
- [ ] **Exit P4** **[HUMAN]**: firewalling a test server's port 443 from Sextant opens exactly one server incident with its sites as children; lifting the block resolves them all.

---

## Phase 5 — Notifications and self-monitoring (§18)

- [ ] **P5.1** Discord sender job on `notifications` (single worker), `RateLimited('discord')`, `?wait=true`, 429 handling, Sentry after 5 failures (§12.1).
- [ ] **P5.2** `notifications:flush` every 30 s exactly as §12.1 steps 1–6.
- [ ] **P5.3** Embed builder per §12.1, including serving paths in server groups and CVE links for `server.versions`.
- [ ] **P5.4** `sextant:heartbeat` and Sentry Crons (§12.2); only Sextant's own errors go to Sentry.
- [ ] **P5.5** Tests with `Http::fake`: `@here` rules, 10-embed split, muted suppression, grouped child suppression and folding, resolution summary, 429.
- [ ] **P5.6** **[HUMAN]** Create the Discord webhook; Sentry alert rule for missed check-ins routed to the same channel.
- [ ] **Exit P5** **[HUMAN]**: the Phase 4 outage produces one Discord message for the outage and one for the recovery; stopping Horizon produces a Sentry alert in Discord within 10 minutes.

---

## Phase 6 — Live dashboard (§18)

- [ ] **P6.1** `DashboardData` building `DashboardProps` (§13.7) with a handful of queries; types generated from laravel-data.
- [ ] **P6.2** Dashboard page per the prototype: totals; Current issues (grouped, Acknowledge for admins, recovering/acknowledged markers, serving paths on children); Recently resolved; Expiring in 60 days; Recent changes; footer with system health and pending count. Add domain / Add server / Add site buttons for admins.
- [ ] **P6.3** `usePoll(10_000, { only: [...] })` on the dashboard; polling on server, site and incident pages (§13.7).
- [ ] **P6.4** Header health pill wired to `system`.
- [ ] **P6.5** Feature test that props match the contract and ordering/grouping rules hold.
- [ ] **Exit P6**: during a scripted outage the issue appears within 10 s of opening, without a reload.

---

## Phase 7 — Software versions (§18)

- [ ] **P7.1** Migrations `server_software` (§3.14) and `minimum_versions` (§3.15); `servers.os_reported`, `versions_url`, `versions_token`, `versions_token_last4` if not already present.
- [ ] **P7.2** Collector `resources/collector/sextant-collect.sh` exactly as §11.3 (no `jq`, atomic write, missing software = `null`, package versions via `dpkg-query`); `shellcheck` in CI.
- [ ] **P7.3** `versions.php.stub` exactly as §11.4 (PHP ≥ 7.4, GET only, HTTPS only, `hash_equals` on SHA-256, 401/405/403/503 rules, `Cache-Control: no-store`).
- [ ] **P7.4** Token generation and rotation (shown once, encrypted, last4), download routes for both files, install instructions with the nginx snippet, Test connection (§11.2, §11.4, §13.3).
- [ ] **P7.5** `VersionsClient` and `VersionsFile` validation (schema 1, 256 KB cap, 10 s timeout).
- [ ] **P7.6** `VersionsRecorder` (§11.5): upserts, removals, `os_reported`, `software.*` and `server.os_changed` activities; nothing on the first reading.
- [ ] **P7.7** Version normalisation and comparison (§11.6), including kernel strings.
- [ ] **P7.8** `server.versions` checker (§9.9) and its attachment when an endpoint is configured (§4.4); Refresh claims a run.
- [ ] **P7.9** Minimum versions page (§13.6); set/change/remove claims runs of all versions monitors (§6.4); `minimum_version.*` activities.
- [ ] **P7.10** UI: version columns and "Below minimum" filter on Servers; versions panel on the server page (installed, package, minimum, read time, endpoint, masked token, downloads, Test connection, Refresh, OS reported vs set); Software vs minimum on the dashboard; servers below minimum in totals; search by version; Windows note on Add server.
- [ ] **P7.11** Tests: validation fixtures, comparison, recorder changes, checker outcomes (OK, Warning, 401/timeout/stale → Unknown with versions kept), rendered `versions.php` via `php -S` (401/405/200), the downloaded file contains the hash and never the token; live-group collector run in Ubuntu 24.04 and Debian 12 containers.
- [ ] **P7.12** **[HUMAN]** Install the collector and endpoint on two real servers, one with two PHP versions.
- [ ] **Exit P7** **[HUMAN]**: setting a minimum above a server's version turns it orange within a minute and posts a Discord warning without `@here`; upgrading clears it; stopping the cron shows the reading's age with no alert.

---

## Phase 8 — Remaining checkers (§18)

- [ ] **P8.1** `domain.nameservers` (§9.4).
- [ ] **P8.2** `site.redirect` (§9.8); detection already sets `expected_target`.
- [ ] **P8.3** `server.ping` (§9.2) with `RespondsToPing`.
- [ ] **P8.4** Unit tests for each with fakes.
- [ ] **P8.5** `monitors:sync-defaults --checker=…` for each (dry run first).
- [ ] **Exit P8**: every checker in GOAL's table runs on existing resources.

---

## Phase 9 — Hardening and launch (§18)

- [ ] **P9.1** **[HUMAN]** One week of real data; review every incident; tune thresholds and intervals.
- [ ] **P9.2** Verify each GOAL success criterion and record evidence in `docs/launch-checklist.md`:
  - [ ] Add a site from its URL in under a minute with the right domain, serving path and monitors.
  - [ ] Find how a site is served and which sites depend on a server within seconds.
  - [ ] Set a minimum after a CVE and see every affected server within a minute.
  - [ ] A real outage reaches Discord within interval × failure threshold + 1 min.
  - [ ] A single failed check never notifies; losing Sextant's own connectivity never opens incidents.
  - [ ] Sextant stopping is reported via Sentry within 10 min.
  - [ ] Certificate and domain expiries show at least 30 days ahead.
  - [ ] The dashboard answers "is anything broken?" within seconds, without refresh.
  - [ ] Readers can browse everything and change nothing.
- [ ] **P9.3** README runbook: deploying, adding a checker (class + config + test), installing the collector, rotating a token, handling a false positive.
- [ ] **P9.4** **[HUMAN]** Daily MySQL dump configured and a restore verified.
- [ ] **Exit P9**: the team uses Sextant as its source of truth and all GOAL success criteria are met.

---

## Open questions (from GOAL; use the default until decided)

- Readers acknowledging incidents → default: admins only.
- Distribution backports → default: compare upstream, show package versions, admins acknowledge.
- Backend header behind Cloudflare/Varnish (`SEXTANT_BACKEND_HEADER`) → default: off, suggest from siblings.
- Real backup location list → default: seeded placeholders, editable on the Lists page.

## Spec issues found

Log anything ambiguous or contradictory here instead of guessing silently.

Found in Phase 0 (2026-11-20):

- [x] SPEC §2 and CLAUDE.md name Inertia 2; the starter kit ships Inertia v3, which keeps `usePoll`, partial reloads and deferred props. Decision: stay on v3.
- [x] SPEC §2 and §17 name ESLint and Prettier; the starter kit uses vite-plus (`vp check` = oxlint + oxfmt, Vitest via `vp test`). Decision: keep vite-plus. `docs/**` is excluded from the formatter.
- [x] The starter kit ships Fortify email verification, 2FA and passkeys; SPEC §13.1 names only login, password reset and profile/password, and admins create users. Decision: email verification removed; 2FA and passkeys kept.
- [x] The starter kit lets any user delete their own account, which conflicts with admins managing users and "never remove the last admin" (§13.6 Users). Decision: self-deletion removed; only admins delete users (P1.17).
- [x] `APP_TIMEZONE=UTC` (§15.2) had no effect because the kit hard-codes `app.timezone`. Fixed: `config/app.php` reads `APP_TIMEZONE`.
- [x] P0.6 needs one task with `->sentryMonitor()`, but `monitors:dispatch` doesn't exist until P3.6. `horizon:snapshot` (already in §14) carries it until then; P3.6 adds it to `monitors:dispatch`.
- [x] `/horizon` must be admin only (§13.2), but Horizon lets anyone in in the `local` environment by default. Decision: admin only everywhere, including local.
- [x] The sidebar (§13.4) links to pages that arrive in Phases 1–7. Decision: those items show disabled until their route exists; counts are added with the lists in Phase 1.
- [x] The header health pill (§13.4) has no data until P6.4. Decision: it shows grey "Checks not running yet" until then, and is hidden on phones.
- [x] The prototype's Admin group has no "Lists" item; §13.4 has one. Decision: follow the spec.
- [x] "Sidebar stacks on phones" (P0.9): the prototype wraps the nav above the content; the starter kit's sidebar slides in as a sheet. Decision: keep the kit's sheet, opened from the header button.
- [x] The `Status` enum belongs to P1.2, but the shell and P0.10 components need its TypeScript type. Decision: `App\Enums\Status` (values only) added in P0.9; P1.2 adds `rank()` and `isFailure()`.
- [x] `ConfidenceBadge` and `ServingPath` (P0.10) need types that SPEC defines for Phases 1–2. Decision: `App\Enums\Confidence` (`detected`, `suggested`, `not_found`, §10.1) and `App\Data\ServingPathData` (front and backend names) added now; "Chosen by you" is a UI state (`chosen` prop), not a confidence. P1.8/P1.9 build on them.
- [x] The P0.11 demo table needs a seeded table without a throwaway migration (migrations are append-only once merged). Decision: it lists `users` (name, role, added; no email, since the users list is admin only), seeded locally with `DemoTableSeeder`. Its admin-only "ping" action is the sample mutation for the Phase 0 exit check.
- [x] Phase 1 needs domains entered by hand (Exit P1), but Add domain with the registry lookup is Phase 2 (§10.5). Decision: P1.14 adds a simple Add / edit domain form (name, notes); P2.8 replaces it. "Add site on {domain}" links come with the site form (P1.15).
- [x] The Domains list (§13.6) has expandable rows, which `DataTable` doesn't do. It uses the same server-side query (`TableQuery`, filter and paging in the query string) with its own rendering. Its filter also matches subdomains, descriptions, backend servers and front services ("e.g. web03 or cloudflare", as in the prototype).
- [x] The Sites list's "Needs review" filter (§13.6) isn't defined before import (§10.6) flags sites. Decision: until P2.9, it means "no backend server assigned".
- [x] The sidebar (§13.4) has no Sites item. The flat Sites list is reached from the Domains page ("All sites"), and back ("By domain").
- [x] §13.6 Users says "invite/create", but no mail is set up. Decision: the admin sets a first password and passes it on; the user changes it under Settings. Admins may delete themselves unless they're the last admin, and are then signed out.
- [x] The Lists page (`/settings/lists`) is readable by everyone and only admins can change it, like other lists; its sidebar link sits in the Admin group (§13.4). Choice changes aren't in §8.4, so they don't reach the change feed.
- [x] GlobalSearch (§13.4) is a "Command dialog", but shadcn-vue's Command filters on the client while §13.8 searches on the server. Decision: a Dialog with a combobox and grouped listbox (arrow keys, Enter opens the highlighted result, first by default); same behaviour, server results.
- [x] External services have no page of their own, so their search results and change-feed links go to the External services list.
- [x] `.eu` has no RDAP service and EURid's WHOIS publishes no expiry date (`docs/spikes/rdap.md`). Resolved: Media24 uses `.si`, `.com`, `.me`, `.work` and `.dev`, no `.eu` (`docs/spikes/tlds.md`).
- [ ] The Sextant server has no IPv6 (`docs/spikes/icmp.md`: "Address family not supported by protocol"). SPEC assumes Sextant can reach IPv6 addresses (`server.tcp`/`server.ping` with `-6`, §9.1, §9.2, §9.0). Until the server gets IPv6, any check that has to connect over IPv6 must throw CheckCouldNotRun ("Sextant has no IPv6 connectivity") and stay Unknown, and default monitors should target the server's IPv4 address. Confirm, or enable IPv6 on the Sextant server.
- [x] SPEC §4.2 puts `Status` in `App\Checks`; it lives in `App\Enums\Status` with the other enums. `CheckResult` and the checkers (P3.2) import it from there.
- [x] §13.3 has no route for the monitor drawer's data. Added `GET /monitors/{monitor}` (JSON, readable by everyone, like the pages it opens from).
- [x] §13.4's sidebar has no Monitors item, but §13.6 has a global Monitors page. Added "Monitors" after Domains.
- [x] §13.3 lists `POST /servers/{server}/run-checks` only; §13.6 gives the site page a "run checks" action too. Added `run-checks` for sites and domains as well.
- [x] §4.6 maps `select` to shadcn `Select`, which can't hold an empty value. `MonitorArgumentsForm` uses a native select so "Default (…)" (inherit) can be chosen, like the other forms' selects.
- [x] §6.5 keeps `parent_id` on a child after its parent resolves, but §3.11's `roots()` would then hide a child that's still failing. Decision: `roots()` = no parent, or a resolved parent. Such a child shows in root lists and counts, and can be adopted by a new outage.
- [x] §6.3 lists reminders and acknowledgements among events but §8.4 has no `incident.reminder` activity type. Decision: reminders write an event only, no change-feed entry.
- [x] §6.6 resource mutes: one `monitor.muted` / `monitor.unmuted` entry for the whole action (e.g. "web02 and 24 sites: all monitors muted until …") instead of one per monitor. Mutes are limited to 30 days.
- [x] The sweep detects "mute ended" by remembering its last run; a mute that ended while the sweep wasn't running for more than a minute or two still gets its reminder on the next run.
- [x] P1.1's morph map lists `monitor`, `incident` and `minimum_version`, whose models arrive in Phases 3, 4 and 7. `Relation::enforceMorphMap()` needs real classes, so each joins the map with its model.
- [x] The choices seeds (§3.4) and the Cloudflare row (§3.5) are reference data that production needs, but deploys run `migrate`, not `db:seed`. Decision: the create-table migrations insert them.
- [x] P1.5's "IP not inside any external service's ranges" can only check admin-entered `ranges` until `proxies:refresh` caches the published ranges (P2.3/P2.4). `IpAddressIsFree` has a `// SPEC:` note; P2.3 extends it.
- [x] §8.2 puts an observer on the `server_backup_location` pivot, but `sync()` doesn't fire model events for pivot rows. Decision: `Server::syncBackupLocations()` records `server.backup_locations_changed` itself; forms must use it instead of `sync()`.
- [x] §8.4 has no type for changing a server's primary address. Decision: recorded as `server.updated` with a `primary_address` property. Changing an address's IP is recorded as removed + added.
- [x] `site.path_changed` and `site.updated` are separate entries when one save changes both the path and other fields; the path entry carries readable paths ("Varnish → web02 (was direct → web01)").
- [x] `activities.user_id` is `nullOnDelete`, so a hard-deleted user would turn their past changes into "Sextant" changes, while GOAL says every change shows which admin made it. Decided (2026-11-20): users are soft-deleted. Deleted users can't sign in, their email is free again (`active_email` generated unique column, like §3.17), and the feed still shows their name. P1.17 deletes with `delete()`, never `forceDelete()`.

Carried over from the previous version of this list (still open in SPEC v0.2):

- [ ] Deleting a server that is still the backend of live sites isn't defined. Domains and external services block deletion when in use (§3.5, §3.6); confirm whether servers should do the same or set `sites.server_id` to null (sites then show "Backend not assigned"). Default until decided (P1.12): refused with "web02 is the backend of N sites. Move them to another server first.", like domains and external services.
- [ ] A server where no port 22/80/443 is open, ping fails and no versions endpoint is set gets no default monitors, so its status stays `unknown`. Confirm whether that's intended or whether the server page should prompt an admin to add a monitor.
- [x] §10.1 `SiteDetectionData` gains `display_hostname`, `scheme` and `port` (saving needs them) and `probe.*_status` (the prototype's coloured dots). Findings are null when `duplicate` is set. Decided in P2.5.
- [x] §10.4 says "attach default monitors with the detected arguments", but monitors don't exist until P3.1. Decision: saving fires `SiteAdded` / `DomainAdded` after commit, carrying the proposed monitors (with `path` and `expected_target`); P3's listener attaches them. Sites added before P3 lands lose a non-root `path` and `expected_target`: P3 should re-run detection for sites without monitors.
- [x] §10.1 doesn't say how probes run "in parallel". Decision: `Concurrency::run()` (process driver, `sync` in tests), falling back to one after another if worker processes can't start.
- [x] §10.3 sibling suggestion counts "sites behind the front" including ones without a backend (`3 of the 5`). A tie for most common is Not found with the tied servers as candidates.
- [x] `return_to` (Add server / external service from Add site's unknown IP) only accepts paths on this app. Add site re-runs detection from `?url=` when you come back.
- [x] §10.7 re-run detection needs a way to apply the result; §13.3 lists only `POST /sites/{site}/detect-again`. Added `PUT /sites/{site}/path` (front service and backend only). Findings Sextant isn't sure of keep the site's current values.
- [x] §10.6 doesn't say how the Import page handles ~80 URLs within a request's time. Decision: the page sends one URL per request (`POST /sites/import`) and shows progress; `sextant:import` runs them in one process.
- [x] §4.3 lists `server.ping`, `server.versions`, `domain.nameservers` and `site.redirect`, but their classes come in Phases 7–8. Decision: `config/checkers.php` registers only implemented checkers; each later phase adds its entry and runs `monitors:sync-defaults --checker=…`. Until `site.redirect` exists, detection's `expected_target` isn't proposed or stored.
- [x] §5.2 names Laravel's `RateLimited('registry')` middleware, but with `$tries = 1` a released job fails and the run is lost for a day. Decision: the runner applies the limit (10 per minute per TLD) and moves an over-limit run 1–3 minutes later.
- [x] §5.4 asks for a DNS lookup "through each configured resolver"; `DnsResolver` tries its resolvers in turn, so the canary counts DNS as one target (three TCP targets + one DNS, healthy at 2).
- [x] §4.4 doesn't say whether default monitors reach the change feed. Decision: they're written quietly (the resource's own `created` entry is the change); admin-added monitors are recorded. A new server's monitors attach in a queued job (`PortIsOpen` probes each port for up to 3 s).

## Out of scope for v1 (don't build)

Server metrics or agents, checking that backups run, automatic CVE matching, multiple probe locations, public status page, teams or per-resource permissions, applications (web or mobile), CDN-bypass origin checks, checks of external services, serving paths longer than front + backend, on-call/escalation policies, maintenance windows beyond "mute until", uptime/SLA reports, software versions on Windows (GOAL "Not in v1").
