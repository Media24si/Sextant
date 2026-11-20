# RDAP and WHOIS

Spike for SPEC §9.0 (`RdapClient`, `WhoisClient`) and §9.3 (`domain.expiration`). Run on 2026-11-20 with the `Sextant/1.0 (Media24 monitoring)` user agent.

## IANA bootstrap

- `https://data.iana.org/rdap/dns.json`: 71 KB, `application/json`, version `1.0`, published 2026-11-19, 592 service entries.
- Shape: `services: [[["tld", …], ["https://rdap-base/"]], …]`. Find the TLD in the first list and append `domain/{name}` to the base URL (bases end with `/`).
- Relevant entries: `si` → `https://rdap.register.si/`, `com` → `https://rdap.verisign.com/com/v1/`, `net` → `https://rdap.verisign.com/net/v1/`, `org` → `https://rdap.publicinterestregistry.org/rdap/`, `info` → `https://rdap.identitydigital.services/rdap/`.
- **`eu` and `hr` are not in the bootstrap**: they have no RDAP service, so `domain.expiration` must use WHOIS for them (below).
- Fixture: `tests/Fixtures/rdap/iana-dns.json`.

## `.si`: rdap.register.si

`GET https://rdap.register.si/domain/svet24.si` → 200, `application/rdap+JSON`. Fixture: `tests/Fixtures/rdap/svet24.si.json`.

| Field | Value |
|---|---|
| `events` | `registration` **2012-07-18**, `last changed` **2026-07-13**, `expiration` **2027-07-18**: date-only, as GOAL says. The parser must accept `Y-m-d` as well as RFC 3339. |
| `status` | `["active"]` |
| `nameservers` | `ldhName` dns1/dns2/dns3.media24.si, with `ipAddresses.v4` |
| Registrar | entity with role `registrar`, name in `vcardArray` `fn`: `INFONET MEDIA d.o.o.` |
| `remarks` | "object truncated due to server policy": no registrant data, which we don't need |

No rate-limit headers were returned. Expiry checks run once a day (§9.3), far below any sensible limit; a 429 still maps to CheckCouldNotRun.

**Unknown domain:** `GET …/domain/sextant-does-not-exist-20261120.si` → **404** `{"errorCode":404,"title":"Domain '…' not found"}`. §9.3: Error "Domain not found in registry". Fixture: `tests/Fixtures/rdap/not-found.si.json`.

## `.com`: Verisign (RFC 3339 dates)

`GET https://rdap.verisign.com/com/v1/domain/example.com` → 200. Events are full timestamps (`2027-08-13T04:00:00Z`), plus an extra `last update of RDAP database` event to ignore. Statuses look like `client transfer prohibited`. Fixture: `tests/Fixtures/rdap/example.com.json`.

## Parsing rules for `RdapClient` / `domain.expiration`

- Expiry = the event with `eventAction` `expiration`. Parse `Y-m-d` as a date, or RFC 3339 as an instant converted to a Europe/Ljubljana date (§9.3 counts calendar days there).
- `registered_at` = `registration`, `last_changed_at` = `last changed`; ignore other events.
- Registrar = the entity whose `roles` contain `registrar`, `fn` from its `vcardArray`.
- Nameservers = `nameservers[].ldhName`, lowercased, trailing dot stripped.
- Statuses: compare lowercase; §9.3 errors on `client hold`, `server hold`, `redemption period`, `pending delete`.

## WHOIS fallback (TLDs without RDAP)

| TLD | Server | Expiry in WHOIS? |
|---|---|---|
| `.hr` | `whois.dns.hr` | Yes: `Registrar Registration Expiration Date: 2050-11-15T23:00:00Z` (carnet.hr). Matches the existing pattern `/Expiration Date:\s*(.+)/i`. |
| `.eu` | `whois.eu` | **No.** EURid's WHOIS shows registrar, technical contact and nameservers, but no dates at all (europa.eu). |

**Spec issue:** with no RDAP and no expiry in WHOIS, `domain.expiration` can never find an expiry for `.eu` domains, so it would throw "Could not determine expiry date" forever and stay Unknown. Logged in TODO "Spec issues found"; the fix depends on whether Media24 has `.eu` domains (see `tlds.md`).
