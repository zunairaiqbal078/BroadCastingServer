# CastPanel: handoff prompt for Cursor

Paste everything below this line into Cursor (Agent mode), with this repo open.

---

You are taking over an in-progress client project. Read this whole brief before doing anything.

## Who I am and how to talk to me
- I am a freelancer. I did not write this project and I am new to it. Explain things simply, step by step.
- Reply in Roman Urdu (Urdu written in English letters). Keep code, commands and file names in English.
- Before any command that changes a server, tell me what it does and wait for my OK.

## Hard rules
1. Work only on the `main` branch. Do not create branches.
2. **Do not commit and do not push** unless I say so in that message. I push and merge myself.
   If I ask for a commit, use: `git -c user.name="Zunaira" -c user.email="zunairaiqbal078@gmail.com" commit ...`
   The repo is github.com/zunairaiqbal078/BroadCastingServer.
3. The client gave me live admin access to their production panel (tv.tvpanelec.com). Never ask me for that password, never put it in a file, and never change that server without my explicit OK. Test on my own VPS first.
4. Do not break existing features. After every change, run the regression check (see "Local testing").

## What the project is
CastPanel v2.7.6 is a PHP 8 + MariaDB web panel for selling and running online radio stations (like Centova Cast).
- **Roles:** admin (`/admin`), reseller (`/reseller`), client (`/cliente`), DJ (`/dj`, served on its own port 2080).
- **Streaming servers:**
  - SHOUTcast DNAS v2 (`sc_serv2`, one process per station, config `/opt/shoutcast/configs/<slug>.conf`)
  - Icecast 2
- **AutoDJ:** Liquidsoap, one Docker container per station: `liquidsoap-<slug>`, `--network host`, music in `/var/castpanel/media/<slug>` mounted as `/media`. Its harbor (live DJ input) is on station port + 5.
- **Root helper daemon `castpanel-ctl`:** PHP runs as `apache`/`www-data` and cannot start services itself. It writes one command line to `/var/run/castpanel/command`, and the daemon (root) executes it and writes JSON to `/var/run/castpanel/result`. The PHP side is `castpanelCtl()`.
- **Key files:**
  - `includes/provisioning.php`: start/stop/restart logic. Use these helpers:
    - `loadStationWithPackage`, `stationHasAutoDJ`
    - `startStation`, `stopStation`, `restartStation`, `ensureAutoDJPlaying`
    - `startLiquidsoapService`, `stopLiquidsoapService`
  - `includes/functions.php`: online status with a 30-second cache.
    - `getStreamStatusCached`, `isStationOnline`, `countOnlineStations`, `clearStreamStatusCache`
  - `includes/i18n.php`: translation.
    - Output buffering translates the rendered HTML.
    - Phrases live in `lang/phrases/es.txt` (Spanish source). `lang/phrases/<lang>.txt` holds the translation on the same line number. There are 15 languages.
  - `api/dj_auth.php`: SHOUTcast/Icecast source authentication for DJs and RadioCaster.
  - `install.sh`: full server installer (AlmaLinux/Rocky 8-9, Ubuntu 20-24, Debian 11-12).
  - `install/setup_dj_port.sh`: Apache vhost for the DJ portal on port 2080.
  - `install/castpanel-ctl.sh`, `install/setup_castpanel_ctl.sh`, `install/systemd/castpanel-ctl.service`: our own root daemon and its installer.
  - `install/schema.sql`: database schema.
  - `dev/`: local Docker setup (see `dev/README.md`).

## The client's reported issues and what was done
| # | Client issue | Status |
|---|---|---|
| Error 1 | RadioCaster could not connect to a station, with or without AutoDJ. | Fixed. SHOUTcast `srcip` is 0.0.0.0 without AutoDJ and 127.0.0.1 with AutoDJ (the DJ then goes through the Liquidsoap harbor on port+5). `dj_auth.php` accepts the station source password, a DJ password, or `user:pass`. |
| Error 2 | Stations that were on air showed as Offline. | Fixed. One cached status function is used everywhere (admin, reseller and client lists and dashboards). SHOUTcast status uses `puerto_stream`, not `icecast_port`. |
| Error 3 | After uploading music and enabling AutoDJ, the station did not start, with "Could not start Liquidsoap: unknown error". | Fixed. Upload/AutoDJ now calls `startStation`/`ensureAutoDJPlaying`, and the real error text from the daemon is shown instead of "unknown error". |
| DJ 2080 | DJ accounts must log in on port 2080 (DJ Control Center). | Done. `install/setup_dj_port.sh` creates the vhost; `install.sh` calls it. |
| Language | Changing the language only translated the sidebar. | Fixed. The whole panel is translated, in 15 languages, including forms and sub-pages. |

Extra bugs found and fixed:
- **DJ panel Start/Stop (`dj/api/control.php`)** crashed: it used a non-existent column and the wrong function. It was rewritten and now checks DJ permissions.
- **Admin bulk control (`admin/bulk_control.php`)** crashed or sent wrong commands. It was rewritten to use the same helpers as the admin pages.
- **`install/schema.sql`** was missing `paquetes` columns (`default_hostname`, `social_stream_enabled`, `mount_points_enabled`) and the `shoutcast_v25` server type.
- **`admin/disk_usage.php`** crashed on `max()`.
- **The installer** had an Apache `IncludeOptional` with an empty path, and an old `liquidsoap.service` that only ran the first station. That service is now disabled; the daemon runs per-station containers.
- **We wrote our own `castpanel-ctl` daemon**, because the client's one was not in the repo. It uses strict input validation and a whitelist of commands. `setup_castpanel_ctl.sh` backs up any existing daemon before replacing it.

**Current git state:** the last commit is 74408f5. These changes are uncommitted on purpose:
- `admin/bulk_control.php`
- `dj/api/control.php`
- `includes/provisioning.php`
- `install.sh`
- `install/schema.sql`
- new files: `install/castpanel-ctl.sh`, `install/setup_castpanel_ctl.sh`, `install/systemd/castpanel-ctl.service`, `dev/CURSOR_HANDOFF.md`

**Already tested locally in Docker:**
- The language switch
- 21 DJ-port checks
- DJ API and bulk control
- 35 daemon tests, including command-injection attempts
- 89 pages loaded with no PHP errors

**Not yet tested** (a local Mac is arm64 and SHOUTcast is x64 only): real `sc_serv2`, real Liquidsoap containers, firewall and systemd. This must be done on an x86 VPS.

**Known open items** (do not fix unless I ask):
- Social streaming: the panel sends `stream_start`/`stream_stop` without parameters, so the daemon rejects them.
- The DJ dashboard shows the station source password. This is the client's design choice.
- `updateShoutcastProxyConfig` uses `/etc/httpd` paths, which only exist on AlmaLinux/Rocky, not Ubuntu.
- A DJ logging in from the main login page may not be redirected to :2080. This is unverified.

## Local testing (Mac/PC with Docker)
```bash
cd dev && docker compose up -d --build
```
- Panel: http://localhost:8080. DJ portal: http://localhost:2080.
- Logins: `admin` / `password`, `demo1` / `demo123`, `demo2` / `demo123`, reseller `res1` / `res123`, DJ `dj1` / `djpass1`.
- Regression check: log in as each role and open every page in the sidebar. There must be no PHP warnings, errors or blank pages. Also check `docker compose logs web`.

## Task 1: deploy to a test VPS (help me step by step)
- **VPS:** an x86_64 VPS (Hetzner, DigitalOcean or Vultr), at least 2 vCPU and 4 GB RAM.
- **OS:** prefer **AlmaLinux 9**. The client's server is likely AlmaLinux, and some code uses `/etc/httpd`. Ubuntu 22.04 also installs.
- **Provider firewall** (in the provider's web panel), inbound TCP: 22, 80, 443, 2080, and 8000-8200 (station ports, port+1 and harbor port+5).
- **Steps:**
  1. SSH in as root. On Windows use PowerShell `ssh root@IP`; on Mac use Terminal.
  2. Install git:
     - AlmaLinux: `dnf install -y git`
     - Ubuntu: `apt-get update && apt-get install -y git`
  3. Get the code. **Because my latest changes are uncommitted, they are not on GitHub yet.** Choose one:
     - (a) I commit and push first, then on the VPS run `git clone https://github.com/zunairaiqbal078/BroadCastingServer.git /root/castpanel`.
     - (b) Copy my local folder with `scp -r` or `rsync` to `/root/castpanel` (exclude `.git` and `dev/`).
     Ask me which one.
  4. Run `cd /root/castpanel && bash install.sh`. It asks for:
     - the domain (the IP is fine without a domain)
     - the Icecast port (keep 8000)
     - passwords (keep the auto-generated ones)
     - the panel admin password
     - SSL: answer **n** if there is no domain
     It copies the panel to `/var/www/castpanel`, installs MariaDB, Apache, PHP, Icecast, SHOUTcast, Docker plus the Liquidsoap image, `castpanel-ctl`, and the DJ port 2080.
  5. Verify. Every one of these must be OK:
     ```bash
     systemctl status castpanel-ctl httpd mariadb docker   # use apache2 on Ubuntu
     tail -50 /var/log/castpanel-ctl.log
     ls -l /var/run/castpanel
     ss -tlnp | grep -E ':80|:2080'
     ```
  6. Open `http://VPS_IP` (panel) and `http://VPS_IP:2080` (DJ portal).
- **If something fails,** read the logs before changing code:
  - `/var/log/castpanel-ctl.log`
  - `/var/log/httpd/error_log` or `/var/log/apache2/error.log`
  - `docker logs liquidsoap-<slug>`
  - `/var/log/shoutcast/`

## Task 2: browser test plan on the VPS (guide me through each, record pass/fail)
1. **Setup.** Log in as admin.
   - Create a package with AutoDJ enabled and one without it.
   - Create a client, then create two SHOUTcast stations: station A without AutoDJ and station B with AutoDJ.
2. **Error 2 (online status).**
   - Start both stations. Within about 30 seconds they must show **Online** in all of these:
     - Admin > Stations and the admin dashboard counter
     - Reseller > Stations and the reseller dashboard
     - The client dashboard
   - Stop one. It must show Offline.
3. **Error 3 (upload plus AutoDJ).**
   - As the client of station B, upload 2-3 MP3s, add them to a playlist and enable AutoDJ.
   - The station must start, with no "unknown error".
   - `docker ps` shows `liquidsoap-<slug>`.
   - The public stream `http://VPS_IP:<port>/` plays music in VLC or a browser.
   - If it fails, the message must now show the real reason.
4. **Error 1 (RadioCaster).** The client uses Windows. RadioCaster or BUTT works the same; the protocol is **SHOUTcast v1** (or v2).
   - **Station A (no AutoDJ):**
     - Server: VPS_IP. Port: the station port. Password: the station source password.
     - It must connect, and the stream plays the live audio.
   - **Station B (AutoDJ):**
     - Create a DJ under the station.
     - Connect to VPS_IP on port **station port + 5**, with the DJ username and password (or `user:pass` as the password).
     - Live audio must replace AutoDJ. When the DJ disconnects, AutoDJ resumes.
   - Also test a wrong password: it must be rejected.
5. **DJ Control Center (port 2080).**
   - Open `http://VPS_IP:2080` and log in as the DJ.
   - Start/Stop and AutoDJ start/stop must work and match their permissions. A DJ without permission must be refused.
   - `/includes` and `/uploads/*.php` on port 2080 must not be reachable.
6. **Language.**
   - Switch to English, then a few others (Arabic or Persian for right-to-left, French, Portuguese).
   - The dashboard, lists, forms, buttons and sub-pages must all change, not only the sidebar.
7. **Admin bulk control.**
   - Restart, start and stop all radios; restart and stop all AutoDJs.
   - Each row shows a result, and errors are shown, not hidden.
8. **Regression.**
   - Click through every sidebar page as admin, reseller, client and DJ, and watch for PHP errors in the Apache error log.
   - Disk usage, statistics, settings, and the create, edit and delete station flows must all work.
9. Write a short pass/fail report with screenshots I can send to the client.

## Task 3: let the client (on Windows) test my VPS
- They only need a browser and RadioCaster. Nothing Windows-specific is needed on the server.
- Prepare a test client account and a DJ account for them. Never send them the root or admin passwords.
- Send them:
  - the panel link
  - the DJ link (:2080)
  - the RadioCaster settings for each station
- After testing, the VPS can be deleted.

Start by summarizing this brief back to me in a few lines, then ask whether I already have a VPS and whether I want option (a) or (b) for getting the code onto it.
