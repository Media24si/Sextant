# Package versions

Every package Sextant depends on, why it's there, and the version installed. `composer.lock` and `package-lock.json` pin the exact versions; `composer.json` and `package.json` use caret constraints. Add a row whenever a package is added (docs/CLAUDE.md, "Dependencies").

Versions are as installed at the end of Phase 0 (2026-11-20).

## Decisions

- **Typed routes: Wayfinder, not Ziggy.** The starter kit already ships Wayfinder (`laravel/wayfinder` and `@laravel/vite-plugin-wayfinder` with `formVariants`), and it works with Inertia v3. It generates typed functions per controller action (`@/actions/…`) and per named route (`@/routes/…`). The output is gitignored and regenerated on every build and dev run.
- **Inertia v3**, not v2 as SPEC §2 says: it's what the starter kit ships, and `usePoll`, partial reloads and deferred props are all there.
- **vite-plus** (`vp check` = oxlint + oxfmt, `vp test` = Vitest) instead of ESLint + Prettier: it's what the starter kit ships.
- **TypeScript types** come from `spatie/laravel-typescript-transformer` v3, configured in `app/Providers/TypeScriptTransformerServiceProvider.php`. It writes one global-namespace declaration file, `resources/js/types/generated.d.ts`, so types are used without imports (`App.Data.AuthUserData`, `App.Enums.Status`, `Illuminate.LengthAwarePaginator<…>`).
  - Every laravel-data class and enum under `app/` is included; no attribute is needed.
  - The file is committed. `npm run build` regenerates it first, and CI fails if the result differs from the committed file. After changing a data class or enum, run `npm run types:generate` (or `php artisan typescript:transform`).
  - The file isn't formatted (no Prettier) and is excluded from `vp check`.
  - `CarbonInterface` properties become `string`; laravel-data serialises dates as ISO 8601 (`DATE_ATOM`), matching SPEC §13.1.

- **TanStack Table v8, not v9.** v9 (released this year) replaces `useVueTable` with a new feature-based API; shadcn-vue's data-table pattern and its docs are written for v8. Revisit when shadcn-vue moves to v9.
- **shadcn-vue CLI quirk.** `npx shadcn-vue add` wrote `import { cn } from "cn"` and added the unrelated npm package `cn`. Both were corrected (`@/lib/utils`, package removed). Check imports and `package.json` after every `add`. Answer "no" when it offers to overwrite existing components.
- **`npm audit`:** 5 moderate advisories in `glob`/`minimatch`, reached only through `@vue/test-utils` → `js-beautify` (dev dependency, not shipped).

## Composer

| Package | Version | Why |
|---|---|---|
| `laravel/framework` | 13.43.0 | SPEC §2 |
| `inertiajs/inertia-laravel` | 3.7.0 | Starter kit |
| `laravel/fortify` | 1.43.0 | Starter kit: login, password reset, 2FA, passkeys |
| `laravel/wayfinder` | 0.1.23 | Typed routes (see Decisions) |
| `laravel/chisel` | 0.1.1 | Starter kit |
| `laravel/tinker` | 3.2.0 | Starter kit |
| `spatie/laravel-data` | 4.23.1 | Data classes for every page's props (SPEC §2) |
| `spatie/laravel-typescript-transformer` | 3.3.0 | TypeScript types from data classes (SPEC §2) |
| `spatie/laravel-query-builder` | 7.3.9 | Server-side filters and sorts for `DataTable` (SPEC §13.5) |
| `laravel/horizon` | 5.52.0 | Queue supervisors and dashboard (SPEC §16) |
| `sentry/sentry-laravel` | 4.31.0 | Sextant's own errors and Sentry Crons (SPEC §12.2) |
| `pear/net_dns2` | 2.0.7 | DNS with our own resolvers, timeouts and direct authoritative queries (SPEC §2, §9.0; `net-dns2.md`) |
| `spatie/ssl-certificate` | 2.7.0 | Certificate fields for `site.ssl` (SPEC §2, §9.6; `tls.md`) |
| `jeremykendall/php-domain-parser` | 6.4.1 | Registrable domain via the Public Suffix List (SPEC §10.1, P2.2). Snapshot in `resources/data/public_suffix_list.dat`; `rdap:refresh-bootstrap` keeps a fresher copy in storage |

Dev: `larastan/larastan` 3.15.0, `laravel/boost` 2.10.4, `laravel/pail` 1.3.0, `laravel/pao` 1.1.6, `laravel/pint` 1.32.3, `laravel/sail` 1.71.0, `pestphp/pest` 5.3.7, `pestphp/pest-plugin-laravel` 5.0.1, `mockery/mockery` 1.6.15, `nunomaduro/collision` 8.9.6, `fakerphp/faker` 1.24.1. All from the starter kit.

## npm

| Package | Version | Why |
|---|---|---|
| `vue` | 3.5.46 | Starter kit |
| `@inertiajs/vue3`, `@inertiajs/vite` | 3.11.0 | Starter kit |
| `reka-ui` | 2.11.0 | shadcn-vue primitives |
| `tailwindcss`, `@tailwindcss/vite` | 4.3.5 | Starter kit |
| `@laravel/vite-plugin-wayfinder` | 0.1.11 | Typed routes |
| `@laravel/passkeys` | 0.2.0 | Starter kit: passkeys |
| `@lucide/vue` | 1.77.0 | Icons |
| `@vueuse/core` | 12.8.2 | Starter kit |
| `vue-sonner` | 2.0.9 | Toasts |
| `vite-plus` | 1.4.0 | Build, lint, format, Vitest |
| `vue-tsc` | 3.3.14 | Type checking |
| `@tanstack/vue-table` | 8.21.3 | Headless table for `DataTable` (SPEC §2, §13.5) |
| `@vue/test-utils` (dev) | 2.6.0 | Mounting components in Vitest |
| `happy-dom` (dev) | 21.0.4 | DOM for Vitest (`test.environment` in `vite.config.ts`) |
| `typescript` | 5.9.3 | |

Others from the starter kit: `class-variance-authority` 0.7.1, `clsx` 2.1.1, `tailwind-merge` 3.7.1, `tw-animate-css` 1.4.0, `vue-input-otp` 0.3.2, `laravel-vite-plugin` 3.2.1, `@vitejs/plugin-vue` 6.0.11, `concurrently` 10.0.7, `@types/node` 22.20.5, `@laravel/multiplex` 0.4.5 (optional).
