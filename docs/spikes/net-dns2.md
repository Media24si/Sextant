# Net_DNS2

Spike for SPEC §9.0 (`DnsResolver`), §9.4 and §9.7. Run on 2026-11-20 against 1.1.1.1 and 8.8.8.8 with `pear/net_dns2` **2.0.7**.

**It does everything `DnsResolver` needs.** Use v2 (namespaced `\NetDNS2\…`), not the 1.x `Net_DNS2_*` classes.

## Setup

```php
$resolver = new \NetDNS2\Resolver([
    'nameservers' => config('sextant.dns.resolvers'),   // ['1.1.1.1', '8.8.8.8']
    'timeout' => config('sextant.dns.timeout'),         // 3 (seconds, float allowed)
]);
$response = $resolver->query('svet24.si', 'A');           // \NetDNS2\Packet\Response
```

- No cache by default (`cache_type` none), which is what we want: TTLs and live answers matter.
- `$response->answer`, `->authority`, `->additional` are arrays of `\NetDNS2\RR\*` objects. `$response->answer_from` is the server that answered.

## Findings

| Question | Result |
|---|---|
| Custom resolvers | `nameservers` option. Tried in order; a dead first server falls through to the next after `timeout` (192.0.2.1 then 1.1.1.1: answered by 1.1.1.1 after 1.53 s at a 1.5 s timeout). |
| Timeout | Per server, in seconds. A resolver that never answers throws `\NetDNS2\Exception` code **3855** (`ENUM\Error::INT_FAILED_SOCKET`, "timeout on read select()") after the timeout. That is a **CheckCouldNotRun** case (§9.0). |
| NXDOMAIN | Throws `\NetDNS2\Exception` code **3** (`ENUM\Error::DNS_NXDOMAIN`). Map to a real result (the name doesn't exist), not CheckCouldNotRun. |
| No records of a type | Not an exception: empty `answer` (svet24.si has no AAAA). |
| CNAME chain | A recursive `A` query returns the chain in `answer`, in order: `CNAME www.github.com → github.com`, then `A github.com`. Walk it from the queried name; `$rr->cname` is the target. |
| NS | `$rr->nsdname` (svet24.si → dns1/2/3.media24.si). |
| Authoritative SOA | Resolve an NS host, then a resolver with `nameservers => [that IP]` and `recurse = false`. The answer has `header->aa = 1`. svet24.si at dns1.media24.si: serial `2026110501`. `$rr->serial` is an `int`. |
| IDN | Unicode names are converted for you (`šola.si` NS worked), but hostnames are stored as punycode anyway (§3). |
| Speed | All of the above, including one 1.5 s timeout, took 1.65 s. |

## Gotchas

- **Record fields are value objects, not strings** in v2: `$rr->address` is `\NetDNS2\Data\IPv4`/`IPv6`, names are `\NetDNS2\Data\Domain`. Cast with `(string)` before using them; passing one straight back as a nameserver throws a `TypeError`.
- Names can carry a trailing dot (the record's text form prints `github.com.`); always `rtrim($name, '.')` and lowercase before comparing.
- Wrap every call: anything other than NXDOMAIN (SERVFAIL code 2, REFUSED 5, socket errors 38xx) means our lookup failed, so CheckCouldNotRun.

## For `DnsResolver` (P2.1)

- `resolve(host)`: one `A` and one `AAAA` query; collect the CNAME chain from the `A` answer; return `{cname_chain, a, aaaa}` as strings.
- `ns(domain)`: `NS` query, `nsdname` cast and lowercased.
- `soa(domain, nameserver)`: resolve the nameserver's address with the normal resolver, then query it directly with `recurse = false`.
- The fake returns canned arrays; the live test (`->group('live')`) resolves svet24.si.
