# Sextant

In-house overview and monitoring for Media24: domains, subdomains (sites), servers, how each site is served, server software versions, and health checks with Discord alerts.

## Read first

- `GOAL.md`: what and why. **Wins over everything else.**
- `SPEC.md`: how. Section refs like §10.3 point here.
- `TODO.md`: the build plan. Work top to bottom, phase by phase.
- `docs/design/`: screenshots of the admin prototype. Match structure and components, not pixels.
- `docs/spikes/`: findings from Phase 0 spikes and pinned versions.

## Stack

Laravel (latest stable, PHP ≥ 8.4) from the official Vue starter kit · Inertia 3 · Vue 3 `<script setup lang="ts">` · shadcn-vue + Tailwind · MySQL 9.7 LTS · Redis + Horizon · Pest · vite-plus (oxlint, oxfmt, Vitest) · laravel-data + TypeScript transformer · spatie/laravel-query-builder · TanStack Table · Wayfinder (see `docs/spikes/versions.md`).

Not used: Filament, Livewire, websockets, PostgreSQL, SSH to monitored servers.

## Commands

```bash
composer install && npm ci
php artisan migrate --seed
composer run dev                 # app, queue, vite (starter kit script)
php artisan horizon              # queues, when testing jobs
php artisan schedule:work        # scheduler (needed for sub-minute tasks)

php artisan test                 # Pest, against the sextant_testing database (MySQL 9.7)
vendor/bin/pint                  # code style
vendor/bin/phpstan analyse       # Larastan
npm run check                    # vite-plus: oxlint + oxfmt (`npm run check:fix` to fix)
npm run types:check              # vue-tsc --noEmit
npm run test                     # Vitest via vite-plus
composer ci:check                # everything CI runs (needs `npm run build` first)
shellcheck resources/collector/sextant-collect.sh && shellcheck --shell=bash resources/collector/install.sh.stub
php artisan typescript:transform # regenerate TS types after changing data classes
php artisan test --group=live    # the few tests that use the real network (DNS, TLS, RDAP)
php artisan proxies:refresh      # download CDN IP ranges (daily in the scheduler)
php artisan sextant:import urls.txt [--application=Name]  # bulk-add sites: URLs or nginx server_name lines
php artisan sextant:checkers     # list and validate config/checkers.php
php artisan monitors:run 12 --no-save -v   # run one monitor now and print the result
php artisan monitors:sync-defaults --checker=site.http [--dry-run]  # attach a default monitor to existing resources
```

Keep this list current when scripts change.

## Workflow

1. Pick the next unticked task in `TODO.md` (or the one you were given). Don't start a phase before the previous phase's Exit box is ticked.
2. Read the SPEC sections it references. Plan before coding when a task touches more than a few files.
3. One task or a small group of related tasks per branch and PR. Branch names: `p{phase}-{task}-{slug}`, e.g. `p1-10-servers-list`.
4. Write tests with the code. A task is done only when Pint, Larastan, Pest, `vp check`, `vue-tsc` and Vitest pass (`composer ci:check`).
5. Tick the task in `TODO.md` in the same PR.
6. Ambiguity or a contradiction: choose what fits GOAL's principles, leave a `// SPEC:` comment, and add a line under "Spec issues found" in `TODO.md`. Don't invent features.
7. Tasks marked **[HUMAN]**: prepare everything you can, then stop and say what you need.

## Rules

- **Permissions.** Every route that changes data goes behind `EnsureAdmin` and gets a row in the authorization matrix test (reader → 403, admin → success). Pages hide controls when `can.manage` is false.
- **Network I/O** only through `app/Net` wrappers, which have fakes. No live network in the default test suite; live tests use `->group('live')`.
- **System writes** use `saveQuietly()` so they never appear as admin activity. Admin changes are recorded by observers.
- **Secrets.** `servers.versions_token` is encrypted, hidden from serialisation and never sent to the browser after generation. Never log tokens, webhook URLs or passwords, or put them in activity `properties`.
- **Checks.** A check that couldn't run throws `CheckCouldNotRun` (→ Unknown), never returns an error. New checkers = class + `config/checkers.php` entry + tests.
- **Data to Vue** goes through laravel-data classes; use the generated TypeScript types, never hand-written duplicates. Use typed routes, never hard-coded URLs.
- **UI.** Use shadcn-vue components from `resources/js/components/ui` (add new ones with the shadcn-vue CLI) and the shared app components in `resources/js/components/app` (`DataTable`, `StatusBadge`, `ServingPath`, `ConfidenceBadge`, `VersionCell`, …). Lists are server-side through `DataTable` (§13.5). Hostnames, IPs, versions and tokens use the mono font.
- **Time.** Store UTC; format in the browser in Europe/Ljubljana.
- **Hostnames** lowercase punycode without a trailing dot; display with `idn_to_utf8()`.
- **Dependencies.** Add a package only when SPEC names it or a task needs it; record it in `docs/spikes/versions.md`.
- **Migrations** are append-only once merged to main.

## Commit messages

Conventional commits, e.g. `feat(servers): list with OS and backup columns (P1.10)`. Reference the TODO id.
