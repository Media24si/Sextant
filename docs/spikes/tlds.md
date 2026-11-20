# TLDs Media24 uses — [HUMAN]

P0.13 asks for every TLD Media24 uses, with RDAP or WHOIS parsing confirmed for each. I don't have the domain list; fill in the **Used?** column (and add rows), and I'll confirm parsing for each with a real domain.

What's known from the RDAP spike (`rdap.md`, 2026-11-20):

| TLD | Used? | Expiry source | Status |
|---|---|---|---|
| `.si` | yes (svet24.si) | RDAP `rdap.register.si` | **Confirmed**: date-only events, fixture saved |
| `.com` | ? | RDAP Verisign | Confirmed on example.com (RFC 3339 events) |
| `.net` | ? | RDAP Verisign | Same server family as `.com`; confirm with a real domain |
| `.org` | ? | RDAP Public Interest Registry | Not yet checked |
| `.info` | ? | RDAP Identity Digital | Not yet checked |
| `.hr` | ? | WHOIS `whois.dns.hr` (no RDAP) | Expiry present and matched by the existing pattern (carnet.hr) |
| `.eu` | ? | WHOIS `whois.eu` (no RDAP) | **No expiry date published**: `domain.expiration` can't work (spec issue logged) |

To help: a list of the registrable domains (or just the TLDs) is enough.
