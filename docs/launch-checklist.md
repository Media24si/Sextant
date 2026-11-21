# Launch checklist

P9.2: each GOAL success criterion, the evidence collected so far, and what still has to be checked on the production server. Tick a criterion only when its production check is done; write the date and what you saw.

Automated evidence runs in CI on every push (`composer ci:check`). Local measurements were taken on 2026-11-21 against the development copy.

## 1. Add a site from its URL in under a minute

GOAL: under a minute, with the correct domain, serving path (backend detected, or suggested and confirmed) and default monitors running.

- **Automated:** `tests/Feature/SiteDetectionTest.php` (direct, Cloudflare, Varnish with sibling suggestion, new domain, unknown IP, duplicate and restore, redirect → `expected_target` and `www.` offer); `tests/Feature/AddSiteTest.php` (save in one transaction); `tests/Feature/DefaultMonitorsTest.php` (HTTP, SSL, serving path and redirect attached and claimed at once; domain checks for a new domain).
- **Local:** detecting `svet24.si` took 1.1 s (DNS, HTTP, TLS and registry probed in parallel; the limit is 15 s).
- [ ] **Production:** add three real sites (one direct, one behind Varnish, one behind Cloudflare) with a stopwatch; each under a minute with the right path, and the site page shows first results without a reload.

## 2. Find how a site is served and which sites depend on a server within seconds

- **Automated:** `tests/Feature/SearchTest.php` (servers, IPs, hostnames, versions); `tests/Feature/ServerPagesTest.php` ("How traffic reaches" groups sites by front service); `tests/Feature/SitePagesTest.php` (serving path on the site page).
- [ ] **Production:** a developer who hasn't used Sextant finds, with Ctrl/⌘ K, the serving path of a named site and the list of sites on a named server, each in under 10 seconds.

## 3. Set a minimum after a CVE and see every affected server within a minute

- **Automated:** `tests/Feature/SoftwareVersionsTest.php` ("re-runs every versions check when a minimum is set, changed or removed", warnings per PHP branch, the Servers "Below minimum" filter, the dashboard's Software vs minimum).
- **How it works:** saving a minimum claims an immediate run of every enabled `server.versions` monitor; two `checks-slow` workers fetch each endpoint (10 s timeout at most).
- [ ] **Production:** with the collector on all servers, set a minimum above one server's nginx; it turns orange within a minute (Exit P7).

## 4. A real outage reaches Discord within interval × failure threshold + 1 minute

For `site.http` and `server.tcp` that's 1 min × 2 + 1 = **3 minutes**.

- **Automated:** `tests/Feature/StateMachineTest.php` "reaches an open incident within interval × threshold + 1 minute, dispatching on whole minutes": outage at 10:00:05, incident opens at 10:02:00, the message leaves by about 10:02:50 (20 s settle + 30 s flush). `tests/Feature/DiscordNotificationsTest.php` covers the message itself.
- **Fixed for this criterion (P9.2):** the one-minute confirmation run is now due at the start of the next minute. Before, it could slip to the dispatch tick after that, making the worst case about 3 min 50 s.
- [ ] **Production:** firewall port 443 of a test server from Sextant (Exit P4/P5) and time the Discord message from the block.

## 5. A single failed check never notifies; losing Sextant's own connectivity never opens incidents

- **Automated:** `tests/Feature/StateMachineTest.php` (one failure stays Pending; "fail, unknown, fail" counts two; a pass resets); `tests/Feature/RunMonitorTest.php` "discards failures while Sextant itself is offline" (the network canary turns failures into Unknown); `tests/Feature/DefaultMonitorsTest.php` (canary needs two of four targets).
- [ ] **Production:** briefly block Sextant's outbound traffic (or take its network down) during a quiet period; checks go Unknown, no incident opens, nothing reaches Discord.

## 6. Sextant stopping is reported through Sentry within 10 minutes

- **How it works:** `monitors:dispatch` checks in to Sentry Crons every minute; `sextant:heartbeat` puts a job on `checks`, `checks-slow` and `notifications` every 5 minutes, each checking in as `sextant-queue-{queue}` with a 2-minute margin, so a stopped scheduler or worker misses a check-in within about 7 minutes.
- **Automated:** `tests/Feature/DiscordNotificationsTest.php` "queues a heartbeat on each watched queue"; `tests/Feature/ScheduleTest.php`.
- [ ] **Production:** with the Sentry alert rule routed to Discord (P5.6), stop Horizon; the alert arrives within 10 minutes. Then stop `schedule:work` and check the same.

## 7. Certificate and domain expiries show at least 30 days ahead

- **How it works:** `site.ssl` and `domain.expiration` warn at 30 days and error at 7; the dashboard's "Expiring in the next 60 days" lists both.
- **Automated:** `tests/Feature/Checkers/SslCertificateCheckerTest.php`, `tests/Feature/Checkers/DomainExpirationCheckerTest.php`, `tests/Feature/DashboardTest.php` ("expiring lists domains and certificates within 60 days").
- [ ] **Production:** after the first daily runs, the Expiring panel lists every certificate and domain under 60 days, matching the registrar and a browser's certificate view for two of them.

## 8. The dashboard answers "is anything broken?" within seconds, without a refresh

- **How it works:** the Overview polls every 10 seconds (only its props); current issues are grouped by resource, worst first.
- **Automated:** `tests/Feature/DashboardTest.php` (props contract, grouping and order, partial reloads).
- [ ] **Production:** during the scripted outage (Exit P6) the issue appears within 10 s of the incident opening, with no reload.

## 9. Readers can browse everything and change nothing

- **Automated:** `tests/Feature/AuthorizationMatrixTest.php`: every route behind `EnsureAdmin` (58 today) is in the matrix (a test fails if one is missing); readers get 403, admins get through, guests go to login. Pages hide admin controls when `can.manage` is false (`tests/Feature/RolesTest.php`).
- [ ] **Production:** sign in as a reader; browse every page; no add, edit, delete, acknowledge, mute or run buttons are shown.
