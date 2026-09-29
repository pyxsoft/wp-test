#!/usr/bin/env python3
"""What a run costs the server: CPU and memory, sampled on the machine under test.

    ./srvstat.py 15 --php-user site1 --web corehttpd,nginx,httpd

Run it on the server, started after the load generator's warm-up and lasting the
measured window. It prints one JSON object:

  cpu_busy_pct   share of all cores that was busy over the window
  cpu_s          CPU-seconds spent by the whole machine in the window — web
                 server, PHP, database and kernel. Divide by the requests answered
                 in the window for "CPU per request"
  web_pss_mb     peak PSS of the web server processes
  php_pss_mb     peak PSS of PHP processes (php-fpm, php-cgi) running as --php-user
  web_procs, php_procs   how many of each at the peak

Memory is PSS (proportional set size), not RSS: a prefork server's children share
most of their pages, and summing RSS counts those pages once per child. PSS gives
each shared page to its sharers in proportion, so the sum means something.

Restricting PHP to one user keeps other pools on the box (webmail, another site)
out of the figure.
"""
import argparse
import json
import os
import pwd
import time


def cpu():
    v = list(map(int, open("/proc/stat").readline().split()[1:]))
    return sum(v), v[3] + v[4]  # total, idle + iowait


def sample(web, php_uid):
    web_kb = php_kb = nweb = nphp = 0
    for pid in os.listdir("/proc"):
        if not pid.isdigit():
            continue
        try:
            comm = open(f"/proc/{pid}/comm").read().strip()
            is_web = comm in web
            is_php = comm.startswith("php") and (php_uid is None or os.stat(f"/proc/{pid}").st_uid == php_uid)
            if not (is_web or is_php):
                continue
            pss = 0
            for line in open(f"/proc/{pid}/smaps_rollup"):
                if line.startswith("Pss:"):
                    pss = int(line.split()[1])
            if is_web:
                web_kb += pss
                nweb += 1
            else:
                php_kb += pss
                nphp += 1
        except (FileNotFoundError, ProcessLookupError, PermissionError):
            continue  # the process ended between listing and reading
    return web_kb / 1024, php_kb / 1024, nweb, nphp


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("seconds", type=float, help="length of the measured window")
    ap.add_argument("--php-user", help="count only PHP processes running as this user")
    ap.add_argument("--web", default="corehttpd,nginx,httpd,apache2,caddy",
                    help="comma-separated process names that make up the web server")
    a = ap.parse_args()
    web = set(a.web.split(","))
    php_uid = pwd.getpwnam(a.php_user).pw_uid if a.php_user else None

    t0, i0 = cpu()
    peak = [0, 0, 0, 0]
    end = time.time() + a.seconds
    while time.time() < end:
        peak = [max(p, s) for p, s in zip(peak, sample(web, php_uid))]
        time.sleep(1)
    t1, i1 = cpu()
    busy = (t1 - t0) - (i1 - i0)
    print(json.dumps({
        "cpu_busy_pct": round(100 * busy / max(t1 - t0, 1), 1),
        "cpu_s": round(busy / os.sysconf("SC_CLK_TCK"), 2),
        "web_pss_mb": round(peak[0], 1), "php_pss_mb": round(peak[1], 1),
        "web_procs": peak[2], "php_procs": peak[3],
    }))


if __name__ == "__main__":
    main()
