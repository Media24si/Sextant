# TLS and spatie/ssl-certificate

Spike for SPEC §9.0 (`TlsProbe`) and §9.6 (`site.ssl`). Run on 2026-11-20 with PHP 8.4 streams and `spatie/ssl-certificate` **2.7.0**, against svet24.si and badssl.com.

**It works as §9.6 describes: a verifying connection decides validity, spatie reads the fields.**

## The verifying connection

```php
$context = stream_context_create(['ssl' => [
    'verify_peer' => true,
    'verify_peer_name' => true,
    'peer_name' => $host,          // checked against the certificate, and used for SNI
    'SNI_enabled' => true,
    'capture_peer_cert' => true,
    'capture_peer_cert_chain' => true,
]]);
$socket = stream_socket_client("ssl://{$ipOrHost}:{$port}", $code, $message, $timeout, STREAM_CLIENT_CONNECT, $context);
```

- Connecting to a **specific IP** works: `ssl://46.19.13.43:443` with `peer_name = svet24.si` verifies and returns the same certificate. That is how `TlsProbe::inspect(host, port, ip)` checks one address.
- On success: `stream_context_get_params($socket)['options']['ssl']['peer_certificate']` is the leaf; `peer_certificate_chain` is the whole chain (4 for svet24.si); `stream_socket_get_name($socket, true)` is the address used.
- Read the leaf with `SslCertificate::createFromString(pem)` after `openssl_x509_export()`.

## Telling failures apart

`stream_socket_client()` returns `false` in every failure case. Telling them apart:

| Case | `$code` | PHP warning | Meaning for `site.ssl` |
|---|---|---|---|
| Port closed | 61 (`Connection refused`) | Unable to connect | No connection: **CheckCouldNotRun** (§9.6: availability is `site.http`'s job) |
| No answer | 60 (`Operation timed out`) | Unable to connect | **CheckCouldNotRun** |
| Expired, self-signed, untrusted root | **0** | `certificate verify failed` (`error:0A000086`) | Verification failed: **Error** |
| Hostname mismatch | **0** | `Peer certificate subjectAltName did not match expected name` | Verification failed: **Error** |

- **`$code === 0` means TLS verification failed**; any other code means the TCP connection failed. Capture the warning with a temporary error handler to keep its text.
- OpenSSL's message doesn't say *why* verification failed (expired, self-signed and untrusted root all give the same code). For a useful message, download the certificate again **without** verification and check it:
  ```php
  $cert = SslCertificate::download()->withVerifyPeer(false)->withVerifyPeerName(false)->setTimeout(5)->forHost($host);
  ```
  Then `isExpired()` → "expired", `!appliesToUrl("https://{$host}")` → "hostname doesn't match" (wrong.host.badssl.com: domains `*.badssl.com`, `badssl.com`), `isSelfSigned()` → "self-signed", otherwise "untrusted chain".

## Fields (svet24.si)

| §9.6 data | spatie call | Value |
|---|---|---|
| `subject` | `getDomain()` | `svet24.si` |
| `issuer` | `getOrganization()` | `Let's Encrypt` (`getIssuer()` is the intermediate's CN, `YE2`; use the organisation in messages) |
| `sans` | `getAdditionalDomains()` | `*.svet24.si`, `svet24.si` |
| `valid_from`, `expires_at` | `validFromDate()`, `expirationDate()` | mutable `Carbon\Carbon`, UTC: 2026-10-29 → 2027-01-27 |
| `days_remaining` | `daysUntilExpirationDate()` | 67 (§9.6 wants calendar days in Europe/Ljubljana; compute it ourselves) |
| `fingerprint_sha256` | `getFingerprintSha256()` | `acb319a2…` (tracked for `certificate.renewed`) |
| `serial` | `getSerialNumber()` | `0x06BFD838…` |
| `chain_valid` | from the verifying connection | |
| `ip` | `stream_socket_get_name($socket, true)` | `46.19.13.43:443` (strip the port) |

## Timing

The verifying handshake took 25–60 ms to svet24.si, 300–450 ms to badssl.com.

## For `TlsProbe` (P2.1)

`inspect(host, port, ip?)` returns `{verified, verify_error, certificate}`. It throws CheckCouldNotRun only when `$code !== 0`. When verification fails, it downloads unverified and fills `verify_error` with the reason above. The live test (`->group('live')`) inspects svet24.si.
