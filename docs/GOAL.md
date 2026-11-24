# Sextant — Goal

> Draft v0.3 · 2026-11-20 · Specification: [SPEC.md](SPEC.md) · Build plan: [TODO.md](TODO.md)
>
> v0.3 adds the decisions from 19–20 Nov 2026: overview-first purpose, admin and reader roles, serving paths, adding sites from a URL, server software versions with minimum safe versions, server and site details, and the Laravel + Inertia + Vue + shadcn-vue stack on MySQL 9.7.

## Purpose

Sextant is an in-house tool for the Media24 development team. Its main purpose is a clear overview of our domains, subdomains and servers, and how they connect: which server builds each site, what sits in front of it, and what software each server runs. On top of that map it checks health on a schedule and tells us when something breaks or is about to.

## Why

We run 20+ servers hosting 50–80+ sites, with domains spread across them. That knowledge lives in people's heads, registrar accounts and scattered notes. Sextant should be the one place that answers:

- Which domains and subdomains do we have, and how is each one served?
- Which servers do we run, and which sites depend on each of them?
- Which kernel, nginx, PHP, MySQL and OpenSSL versions run where, so we know at once which servers a new CVE affects?
- Is anything broken right now, or about to break (certificates, domain renewals)?
- What went wrong recently, for how long, and is it fixed?

## Users

In-house developers, in two roles:

| Action | Admin | Reader |
|---|---|---|
| View dashboard, lists, incidents, history, change feed, software versions | Yes | Yes |
| Use global search | Yes | Yes |
| Add, edit or delete servers, external services, domains and sites | Yes | No |
| Define a site's serving path | Yes | No |
| Set version endpoints and tokens, download the collector files, set minimum safe versions | Yes | No |
| Add, change, disable or mute monitors | Yes | No |
| Run checks or refresh versions now | Yes | No |
| Acknowledge incidents | Yes | No (open question below) |
| Manage users and their roles | Yes | No |

Readers never see edit buttons. Every change in the feed shows which admin made it.

## Goals for v1

### 1. Inventory

- **Servers**: our own machines, each with its IP addresses (IPv4, IPv6, secondary IPs; one is primary), hostname, provider, operating system, where its backups are stored, and notes. Servers have no "role" field.
- **External services**: things in front of our servers that we don't run, such as Cloudflare or the Varnish server. Each has a name and the IP addresses or ranges it answers on (Cloudflare's ranges are refreshed automatically). Sextant never checks them directly.
- **Domains**: the registrable domain we renew (`svet24.si`).
- **Sites**: one hostname each (`svet24.si`, `podjetje.svet24.si`, `njena.svet24.si` are three sites on one domain), with its serving path, an optional description and an optional GitHub repository link.
- **Applications**: one web app answering on several hostnames, often on different domains (`backend.svet24.si`, `backend.reporter.si`). It groups those sites; each hostname keeps its own checks. Pasting an nginx `server_name` line on the Import page adds them all at once.

### 2. Serving paths

Every site has a serving path: the steps a request passes through before it reaches the server that builds the page.

- v1 paths have at most two steps: an optional external service in front, then one of our servers as the backend. Examples: `svet24.si` → Varnish (external) → nginx on server 2; `necenzurirano.si` → Cloudflare → the same server 2; `media24.si` → server 2 directly.
- The path is shown wherever the site appears: sites and domain lists, the site page, the server page (every site that reaches the server) and incident groups.
- When one of our servers goes down, incidents of every site whose path uses it are grouped under the server's incident.

### 3. Adding a site from its URL

An admin pastes a URL such as `https://vestnik.svet24.si`. Sextant works out and shows for review:

- the hostname, and the path the HTTP check should use;
- the registrable domain, reusing an existing one or creating it (with its registry data);
- where DNS points, and whether that address belongs to an external service or to one of our servers;
- the backend server: detected when DNS points straight at one of our servers, otherwise suggested from the server that the domain's other sites behind the same service use;
- a first HTTP, certificate and redirect probe.

Each finding is marked **Detected**, **Suggested** (admin confirms) or **Not found** (admin chooses). Nothing is saved until an admin confirms. Sextant never creates a server from a CDN or proxy address. Duplicates link to the existing site; a redirect target is saved as the expected redirect, with an offer to add the `www.` host too. Default monitors are attached and run immediately.

A domain can also be added with several subdomains at once, and a list of URLs can be imported in bulk.

### 4. Lists, subdomains and search

- **Servers list**: name, IPs, OS, backup locations, number of sites served, software versions, status and open incidents.
- **Domains list**: each domain with expiry, registrar, status and all of its subdomains, each subdomain with its serving path and status.
- **Global search** on every page (also Ctrl/⌘ + K): finds servers by name, hostname, IP, OS or software version, domains, and sites by hostname or description.

### 5. Server software versions

- Sextant records, per server: the operating system, the running kernel, the nginx version, every installed PHP version (CLI and FPM), the MySQL server version (or "not installed"), and the OpenSSL version. More software is added as needed.
- Collection needs no login to the servers. A bash script runs from root's cron and writes the versions to a JSON file outside the web root. A single PHP file, served by the server's own nginx over HTTPS, returns that file only to requests with the right bearer token.
- An admin sets the endpoint URL and token on the server's page (Sextant can generate the token) and downloads both files there, the PHP file already carrying a hash of the token.
- Sextant fetches versions daily and when an admin clicks Refresh. When the file is old, Sextant says the collector may have stopped. Unreadable versions keep their last known values and never alert.
- Upgrades and downgrades go into the change feed.
- **Minimum safe versions**: after a CVE, an admin sets the lowest safe version per software (per branch for PHP), with a link to the CVE. Servers below it turn orange (Warning), appear under current issues and are posted to Discord as a warning. Upgrading clears it at the next read.
- Every server we monitor runs PHP. Windows servers can't run the bash collector, so their versions stay empty in v1.
- **Application packages** (added 2026-11-23): Sextant reads `composer.lock`, `package-lock.json` and `pnpm-lock.yaml` from the GitHub repositories that sites and applications link to, with one read-only token per GitHub owner (fine-grained, Contents: Read-only, only the linked repositories). After a CVE, an admin sets the lowest safe version of a package per major or minor line (e.g. `laravel/framework` 12.13, 13.39; `vite` 8.1.7, 8.2.5, 8.3.7); repositories below it, and the applications and sites using them, show it, and Discord gets a warning, exactly as for server software.

### 6. Monitoring

- Each resource has monitors. A monitor is a configured instance of a checker (for example, "SSL certificate for svet24.si every 12 hours").
- Default monitors and their settings come from config. Admins can add, change, disable or mute a monitor for a single resource.
- Sextant stores each monitor's current state, the incidents it has had, and run history with limited retention. It does not keep every check forever.

### 7. Incidents

- An incident is one continuous period when a monitor is not OK.
- It opens only after N consecutive failed checks and resolves after M consecutive passing checks. N and M are set per checker, and each monitor can override them.
- Severity can escalate within the same incident, for example when an SSL warning turns into an error.
- Acknowledging an incident means "we know about it". It stops repeat notifications but does not resolve the incident. Only passing checks, or disabling the monitor, resolve it.
- Each resource page shows its incident history. A server's page also includes the incidents of the sites whose path uses it.

### 8. Dashboard

- Totals for servers, domains and sites, each with a status colour, plus the number of servers below a minimum version.
- **Current issues**: open incidents, worst first. Site incidents caused by a server outage are grouped under that server.
- **Recently resolved**: what broke, when, and for how long.
- **Software vs minimum** and **expiring in the next 60 days**.
- **Recent changes**: a combined feed of
  - status changes: incidents opened, escalated, acknowledged and resolved;
  - changes made by admins: servers, external services, domains, sites, serving paths, monitors, minimum versions;
  - changes Sextant detects: a site resolving somewhere else, a renewed certificate or domain, changed nameservers, a software upgrade or downgrade.
- Updates live, without a page reload.

### 9. Notifications

- **Discord**: one channel. It gets incidents opened, escalated and resolved. Messages mention nobody (no `@here`); people choose their own Discord notifications for the channel. When a server outage takes down many sites, they go out as one grouped message.
- **Sentry**: only Sextant's own health: its errors and failed jobs, plus heartbeats (Sentry Crons) so we find out if Sextant itself stops checking. Incidents are not sent to Sentry.

## Status model

| Status  | Colour | Meaning |
|---------|--------|---------|
| OK      | Green  | All enabled monitors are passing |
| Warning | Orange | At least one warning, e.g. a certificate expires in under 30 days, or software is below a minimum version |
| Error   | Red    | At least one confirmed failure |
| Unknown | Grey   | Not checked yet, the check could not run, or the result is stale |

A resource's status is derived from its monitors and is never set by hand. "The check could not run" (rate limit, timeout on our side, a versions endpoint that doesn't answer, a bug) is Unknown, not Error.

## Initial checkers

| Resource | Checker           | Warns or fails when |
|----------|-------------------|---------------------|
| Server   | TCP port          | A port (22, 80, 443…) stops accepting connections. This is the main reachability check |
| Server   | Ping (ICMP)       | No reply. Warning at most, because many healthy hosts block ICMP. Attached only to servers that answer ping when added |
| Server   | Software versions | Software is below a minimum safe version (warning). An endpoint that can't be read is Unknown, never an alert |
| Domain   | Expiration        | It expires within 30 days (warning) or 7 days (error). RDAP is the main source and WHOIS a fallback |
| Domain   | Nameservers       | The NS/SOA lookup fails, or live DNS doesn't match the registry's nameservers |
| Site     | HTTP              | Unexpected status code, timeout, or slow response |
| Site     | SSL certificate   | The certificate is invalid, the hostname doesn't match, or it expires within 30/7 days |
| Site     | Serving path      | DNS no longer points at the first step of the site's serving path |
| Site     | Redirect          | The site redirects somewhere unexpected, loops, or downgrades to http |

All thresholds are defaults and can be changed per monitor.

**Domain expiry sources.** We query RDAP and find each TLD's server through the IANA bootstrap file (`data.iana.org/rdap/dns.json`). For `.si` that's Register.si's official service, `rdap.register.si`, tested on 2026-10-06. It returns structured `registration`, `last changed` and `expiration` events, plus nameservers and registrar. Its dates are date-only (`2027-07-18`) rather than full RFC 3339 timestamps, so the parser must accept both. WHOIS is used only when a TLD has no RDAP service or RDAP fails. Expiry checks run once a day, well clear of registry rate limits.

## Principles

- **Overview first.** The map of domains, sites, servers, paths and versions must be right and easy to read; monitoring builds on it.
- **Incidents, not logs.** Show meaningful periods of trouble, not individual failed checks.
- **Unknown is not Error.** A check that couldn't run never opens an incident.
- **Confirm before alerting.** A single transient failure never sends a notification.
- **Detection proposes; the admin confirms.**
- **Checker = code, monitor = configuration.** A new kind of check is a new class plus a config entry, with no schema change.
- **Keep the watcher honest.** Canary checks stop incidents from opening when Sextant's own network is down, and a Sentry heartbeat reports if Sextant stops running.
- **Nothing to log in to.** Sextant never holds SSH or other credentials to our servers; servers publish what Sextant needs. The only credentials it holds are read-only GitHub tokens for reading lock files.
- **Grow without re-architecture.** Agent metrics, app checks, push heartbeats, CVE matching and CDN-bypass origin checks will all fit in later as new checkers or data.

## Not in v1

- Server metrics: CPU, disk, memory, services, containers (v1 reads only software versions)
- Checking that backups actually run (v1 records only where they are stored)
- Automatic matching of software or package versions against CVE databases (v1 has manual minimum versions)
- Checking from more than one location
- A public status page
- Multiple teams or per-resource permissions (v1 has two roles: admin and reader)
- Application checks (health endpoints, deployed versions) and mobile apps (see "After v1"); v1 reads the packages locked on a repository's default branch, not what is deployed
- Origin checks that bypass the CDN, and checks of external services themselves
- Serving paths longer than one external service plus one backend
- On-call rotations and escalation policies
- Scheduled maintenance windows. v1 only has a manual "mute until"
- Uptime and SLA reports. v1 collects the data; the reports come later
- Software versions on Windows servers

## Constraints

- **Stack**: Laravel with Inertia, Vue 3, TypeScript and shadcn-vue for the whole interface (no Filament), starting from Laravel's official Vue starter kit. MySQL 9.7 LTS, which we already run everywhere. Redis with Horizon for queues; the Laravel scheduler runs the checks. Live screens poll; no websockets in v1.
- **Scale**: about 20 servers and 50–80 sites today. It must handle 10× that without a redesign.
- **Hosting**: its own separate server, outside the infrastructure it monitors, so an outage there doesn't take Sextant down with it.
- **Retention**: run history is kept for 90 days. Current state, incidents, software history and the change feed are kept indefinitely.
- **Time**: stored in UTC and shown in Europe/Ljubljana.
- **Design**: the admin prototype ("Sextant — Admin UI prototype" canvas) sets the layout, navigation and look. Match its structure and components; pixel-perfection is not a goal.

## Success criteria

- Adding a site from its URL takes under a minute. It ends with the correct domain, the correct serving path (backend detected, or suggested and confirmed) and default monitors running.
- Any developer can find, within seconds, how a site is served and which sites depend on a given server.
- When a CVE is published, an admin can set a minimum version and see every affected server within a minute.
- A real outage reaches Discord within (check interval × failure threshold + 1 minute).
- A single failed check never sends a notification. Sextant losing its own connectivity never opens incidents.
- If Sextant stops checking, the team hears about it through Sentry within 10 minutes.
- Certificate and domain expiries show up at least 30 days ahead.
- Any developer can tell from the dashboard within a few seconds, without refreshing, whether anything is broken.
- Readers can browse everything and change nothing.

## After v1

- A server agent: disk, CPU, memory, services, containers, backup runs
- Push/heartbeat monitors, where cron jobs and backups ping Sextant
- Automatic CVE matching for tracked software
- Origin checks behind the CDN
- Longer serving paths (several services in front)
- Uptime and latency charts, SLA reports
- **Application checks**: health endpoints and deployed versions for applications (grouping sites into applications is in v1)
- **Mobile apps**: App Store and Google Play listings, later on
- Maintenance windows

## Decisions log

| # | Question | Decision | Date |
|---|----------|----------|------|
| 1 | What is an "app"? | A web application behind sites, not a mobile app. Left out of v1; mobile apps come later still | 2026-10-06 |
| 2 | Send incidents to Sentry? | No. Sentry only covers Sextant's own health | 2026-10-06 |
| 3 | Where does Sextant run? | On its own separate server | 2026-10-06 |
| 4 | Discord setup | One channel; no `@here`, people set their own notifications (was `@here` on errors until 2026-11-22) | 2026-11-22 |
| 5 | Domain expiry source | RDAP first, WHOIS as fallback. `.si` RDAP was tested and works | 2026-10-06 |
| 6 | Run history retention | 90 days | 2026-10-06 |
| 7 | What goes in "Recent changes"? | Status changes, admin changes, and changes Sextant detects | 2026-10-06 |
| 8 | Main purpose | An overview of domains, subdomains, servers and how they connect; monitoring builds on it | 2026-11-19 |
| 9 | Permissions | Two roles: admin (changes everything) and reader (view only) | 2026-11-19 |
| 10 | Serving paths | Optional external service in front plus one backend server. Varnish and Cloudflare are external services and are not checked directly | 2026-11-19 |
| 11 | How software versions are collected | Root cron script writes a JSON file; a PHP endpoint behind a bearer token serves it; no SSH. Both files downloadable from Sextant | 2026-11-19 |
| 12 | Which software | OS, kernel, nginx, PHP (all versions), MySQL, OpenSSL; more as needed | 2026-11-19 |
| 13 | Minimum safe versions | Yes, per software and per PHP branch; servers below turn Warning | 2026-11-19 |
| 14 | Servers without PHP | All monitored servers run PHP; Varnish is external and not monitored | 2026-11-19 |
| 15 | Adding sites | "Add site" from a URL, with automatic detection of domain, front and backend | 2026-11-20 |
| 16 | Server and site details | Servers: OS and backup locations (checkboxes), no role. Sites: description and GitHub link | 2026-11-20 |
| 17 | Interface stack | Laravel + Inertia + Vue 3 + TypeScript + shadcn-vue for everything; no Filament | 2026-11-20 |
| 18 | Database | MySQL 9.7 LTS (already in use); no switch to PostgreSQL | 2026-11-20 |
| 19 | One app, many hostnames | An application groups sites; each hostname stays a site with its own domain and checks. Import accepts nginx `server_name` lines. App-level checks stay after v1 | 2026-11-21 |

## Open questions

| Question | Default until decided |
|---|---|
| May readers acknowledge incidents? | No, admins only |
| Version checks vs distribution backports: Ubuntu and Debian patch CVEs without changing the upstream version (a patched OpenSSL still says 3.0.13). Compare upstream versions or package versions? | Collect both. Minimums compare upstream versions; the package version is shown beside it, so an admin can spot a backported fix and acknowledge the warning |
| Can our nginx backends add a response header naming the server (e.g. `X-Backend: web02`), so Sextant can detect the backend behind Cloudflare and Varnish instead of suggesting it? | Suggest from sibling sites |
| The real list of backup locations | Provider snapshots, backup server, offsite cloud storage, local disk |
