# ICMP ping on the Sextant server — [HUMAN]

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

Record the result here (date, server, users, IPv4 and IPv6) and tick the P0.13 item.
