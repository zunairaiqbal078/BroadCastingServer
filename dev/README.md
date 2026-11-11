# Local development (Docker)

Runs the panel UI (PHP 8.2 + Apache) and MariaDB on your machine.

```bash
cd dev
docker compose up -d --build
```

Open http://localhost:8080

DJ Control Center (port 2080): http://localhost:2080 — set up by
`install/setup_dj_port.sh`, the same script used on real servers.

| User | Password | Role |
|---|---|---|
| admin | password | admin |
| demo1 | demo123 | client, station `demo-autodj` (AutoDJ package) |
| demo2 | demo123 | client, station `demo-live` (no AutoDJ) |

Database: `localhost:3307`, user `castpanel` / `castpanel`.

Reset the database (re-imports `install/schema.sql` and `dev/seed.sql`):

```bash
docker compose down -v && docker compose up -d
```

## What does NOT work locally

SHOUTcast (`sc_serv2`), Icecast, Liquidsoap (Docker) and the `castpanel-ctl`
daemon are not part of this stack. Start/stop buttons, AutoDJ playback
and RadioCaster connections must be tested on a Linux
server or VM installed with `install.sh`.

`dev/database.local.php` is mounted over `config/database.php`, so the real
config file (with `__PLACEHOLDER__` values for `install.sh`) is never changed.

### AutoDJ logic (no streaming stack)

Quick Control title/skip fixes can be checked without SHOUTcast/Liquidsoap:

```bash
docker exec dev-web-1 php /var/www/castpanel/dev/test_autodj_track_logic.php
```

Full AutoDJ playback, skip on telnet, and encoder connections still need an x86 Linux VPS (`install.sh`).
