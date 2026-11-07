# Local development (Docker)

Runs the panel UI (PHP 8.2 + Apache) and MariaDB on your machine.

```bash
cd dev
docker compose up -d --build
```

Open http://localhost:8080

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
daemon are not part of this stack. Start/stop buttons, AutoDJ playback,
RadioCaster connections and the DJ port 2080 must be tested on a Linux
server or VM installed with `install.sh`.

`dev/database.local.php` is mounted over `config/database.php`, so the real
config file (with `__PLACEHOLDER__` values for `install.sh`) is never changed.
