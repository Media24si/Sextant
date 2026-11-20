# ICMP ping on the Sextant server

`server.ping` (§9.2) runs the system `ping` through Laravel `Process` as whichever user runs the job: php-fpm's user for "Run now" in the browser, Horizon's user for scheduled runs. Raw ICMP sockets need `cap_net_raw` (or Linux's unprivileged ICMP sockets via `net.ipv4.ping_group_range`), so this has to be checked on the real server, as both users.

Local check (macOS, 2026-11-20): `ping -c 1 1.1.1.1` works as a normal user. That says nothing about the Linux server.

## On the Sextant server

Replace `www-data` with the php-fpm and Horizon users if they differ:

```bash
sudo -u www-data ping -c 3 -w 5 1.1.1.1
sudo -u www-data ping -6 -c 3 -w 5 2606:4700:4700::1111
getcap "$(command -v ping)"            # expect: cap_net_raw=ep
sysctl net.ipv4.ping_group_range       # alternative to the capability
```

Expected: 3 packets received, exit code 0, for every user. If it fails, install `iputils-ping` (§16) or restore the capability with `sudo setcap cap_net_raw+ep "$(command -v ping)"`.

Record the## Result (2026-11-20, Sextant server, user `sites`)

| Check | Result |
|---|---|
| `sudo -u sites ping -c 3 -w 5 1.1.1.1` | **Works**: 3/3 received, ~0.8 ms |
| `sudo -u sites ping -6 -c 3 -w 5 2606:4700:4700::1111` | **Fails**: `socket: Address family not supported by protocol` |
| `getcap /usr/bin/ping` | `cap_net_raw=ep` |
| `net.ipv4.ping_group_range` | `1 0` (unprivileged ICMP sockets off; the capability is what makes ping work) |

<details><summary>Raw output</summary>

```text
$ sudo -u sites ping -c 3 -w 5 1.1.1.1
PING 1.1.1.1 (1.1.1.1) 56(84) bytes of data.
64 bytes from 1.1.1.1: icmp_seq=1 ttl=251 time=0.819 ms
64 bytes from 1.1.1.1: icmp_seq=2 ttl=251 time=0.823 ms
64 bytes from 1.1.1.1: icmp_seq=3 ttl=251 time=0.751 ms

--- 1.1.1.1 ping statistics ---
3 packets transmitted, 3 received, 0% packet loss, time 2025ms
rtt min/avg/max/mdev = 0.751/0.797/0.823/0.033 ms

$ sudo -u sites ping -6 -c 3 -w 5 2606:4700:4700::1111
ping: socktype: SOCK_DGRAM
ping: socket: Address family not supported by protocol

$ getcap "$(command -v ping)"
/usr/bin/ping cap_net_raw=ep

$ sysctl net.ipv4.ping_group_range
net.ipv4.ping_group_range = 1	0
```

</details>

## What this means

- **IPv4 ping works** for `sites`. This assumes php-fpm and Horizon both run as `sites`; if either uses another user, run the IPv4 command as that user too.
- **The server has no IPv6.** "Address family not supported by protocol" is the kernel refusing to open an IPv6 socket at all (IPv6 disabled or not configured), not a permission problem. So it affects every check that connects over IPv6, not just ping: `server.tcp` and `server.ping` on an IPv6 address, and HTTP/TLS to a site that only has AAAA records.
- Until the server gets IPv6, those checks can't run from here. They must end as **Unknown** (CheckCouldNotRun: "Sextant has no IPv6 connectivity"), never Error. Logged under "Spec issues found" in `docs/TODO.md`.
