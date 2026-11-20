# TLDs Media24 uses

P0.13: every TLD Media24 uses, with its expiry source confirmed against a real domain on 2026-11-20. TLD list from the team; `.eu` and `.hr` were checked before the list arrived and aren't used.

| TLD | Expiry source | Checked with | Result |
|---|---|---|---|
| `.si` | RDAP `https://rdap.register.si/` | svet24.si | **Confirmed.** Date-only events (`2027-07-18`). Fixture `rdap/svet24.si.json` |
| `.com` | RDAP `https://rdap.verisign.com/com/v1/` | dobertek.com, example.com | **Confirmed.** RFC 3339 events (`2027-04-13T04:00:00Z`); nameservers come back **uppercase** (`KEHLANI.NS.CLOUDFLARE.COM`). Fixture `rdap/example.com.json` |
| `.dev` | RDAP `https://pubapi.registry.google/rdap/` (Google Registry) | web.dev | **Confirmed.** RFC 3339 events **with milliseconds** (`2027-10-29T15:57:39.435Z`); content type `application/rdap+json; charset=utf-8`. Fixture `rdap/web.dev.json` |
| `.work` | RDAP `https://rdap.nic.work/` | nic.work | **Confirmed.** RFC 3339 events (`2027-09-07T04:15:49Z`) |
| `.me` | **WHOIS** `whois.nic.me` (not in the IANA RDAP bootstrap) | nic.me | **Confirmed.** `Registry Expiry Date: 2027-04-29T17:53:03Z`, matched by the existing pattern `/Registry Expiry Date:\s*(.+)/i`. Fixture `whois/nic.me.txt` |

Not used, kept for reference: `.hr` (WHOIS has an expiry date, fixture `whois/carnet.hr.txt`) and `.eu` (WHOIS has no expiry date, fixture `whois/europa.eu.txt`).

## What this means for `RdapClient` / `domain.expiration` (P2.1, P3.16)

- Four of the five TLDs have RDAP; only `.me` needs the WHOIS fallback.
- The date parser must accept three forms: `Y-m-d`, RFC 3339 without fractions, and RFC 3339 **with** milliseconds.
- Lowercase nameservers and registrar data before storing or comparing.
- Accept any `application/rdap+json` content type, with or without a charset, in any case (`.si` sends `application/rdap+JSON`).
- The `.eu` spec issue doesn't apply: Media24 has no `.eu` domains.
