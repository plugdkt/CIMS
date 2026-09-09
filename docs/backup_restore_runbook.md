# CMIS Backup & Restore Runbook (T-054, NFR-09)

NFR-09: "Full daily + binlog ต่อเนื่อง, RPO ≤ 15 นาที, RTO ≤ 4 ชม." — full daily backup plus
continuous binary logging, Recovery Point Objective ≤ 15 minutes, Recovery Time Objective ≤ 4 hours.

## How it works

1. **Continuous binary logging** (`docker/mariadb/conf.d/backup.cnf`) — every committed write is
   recorded to the binlog (ROW format, `sync_binlog=1` for durability), independent of when the next
   full backup runs. Also sets `log_bin_trust_function_creators=1` — without it, `CREATE TRIGGER`
   (this app's `stock_ledger` append-only triggers, T-017) fails with error 1419 ("You do not have
   the SUPER privilege and binary logging is enabled") for any DB user without SUPER, which
   `cmis_app` deliberately doesn't have (T-027's restricted grants). This is MariaDB's own documented
   alternative to granting SUPER just for trigger creation — safe here since both triggers are a
   plain deterministic `SIGNAL`, nothing that could diverge on a replica.
2. **Full daily backup** (`docker/mariadb/backup-full.sh`) — a `mariadb-dump` of the `cmis` database,
   gzip-compressed, with `--master-data=2` embedding the binlog file+position active at dump time as
   a comment. That position is the join point between "restore the full backup" and "replay the
   binlog from here" — it's what makes recovering to *any* point in time between backups possible,
   not just to the moment of the last backup.
3. **Point-in-time restore** = load the full backup, then replay binlog events from its embedded
   position up to the desired moment (`mariadb-binlog --stop-datetime=...`).

Backups land in `./storage/backups` on the host (bind-mounted into the `mariadb` container at
`/backups`) — gitignored, same as every other generated artifact in `storage/`.

## Running a backup

```bash
docker compose exec -T mariadb sh /var/lib/mysql-scripts/backup-full.sh
```

**Scheduling this "daily" for real** depends on the actual deployment host. This project's Docker
Compose setup has no cron/scheduler container of its own (same reasoning as every other scheduled
command in this app — see CLAUDE.md's T-044/T-047 notes: nothing in this dev environment runs
anything on a timer, by design; scheduling is a deployment concern). Two concrete options for the
real target environment (T-055 documents an IIS/Windows Server install):

- **Windows Task Scheduler** (matches T-055's IIS target): a daily trigger running
  `docker compose exec -T mariadb sh /var/lib/mysql-scripts/backup-full.sh` (or the equivalent native
  `mariadb-dump.exe` invocation if MariaDB runs natively rather than in Docker on that host).
  Windows Server also needs a Volume Shadow Copy or equivalent to also back up the binlog files
  themselves alongside `/var/lib/mysql`, since those are the RPO-critical piece, not just the daily
  dump.
- **cron** (`0 2 * * * ...`), if the real deployment host is Linux.

## Retention

- Binlogs: 14 days (`binlog_expire_logs_seconds=1209600` in `backup.cnf`) — comfortably longer than
  one full-backup cycle, so there's always a complete backup+binlog chain covering "now" back to the
  oldest retained backup.
- Full backups: keep at least 14 days of daily fulls (matching the binlog retention window above) —
  not automated by any script here (this project has no backup-rotation job), so whatever schedules
  `backup-full.sh` in the real deployment should also prune backups older than this window.
- `stock_ledger`/`audit_logs` retention (NFR-10, ≥ 10 years) is a separate, already-satisfied
  guarantee: both tables are append-only (AGENT RULE #6) with no purge mechanism anywhere in this
  app, so the *live* data is retained indefinitely by construction. This runbook's retention numbers
  are about backup *files*, not the live database.

## Restoring — full procedure

### To the live database (real disaster recovery)

1. Stop the app (`docker compose stop app fpm nginx`) so nothing writes to `cmis` mid-restore.
2. Restore the most recent full backup: `gunzip -c <backup>.sql.gz | docker compose exec -T mariadb
   mariadb -uroot -p"$DB_ROOT_PASSWORD" cmis`
3. Find the backup's own binlog starting position: `gunzip -c <backup>.sql.gz | grep -m1 "CHANGE
   MASTER TO"` (a commented `MASTER_LOG_FILE='...', MASTER_LOG_POS=...` line).
4. Replay every binlog file from that position onward, up to the desired point in time (or to "now"
   for a full recovery with no data loss beyond whatever wasn't yet flushed):
   `docker compose exec mariadb sh -c 'mariadb-binlog --start-position=<pos> --stop-datetime="<target>"
   /var/lib/mysql/<start-file> /var/lib/mysql/<later-files...>' | docker compose exec -T mariadb
   mariadb -uroot -p"$DB_ROOT_PASSWORD" cmis`
5. Restart the app, verify.

### As a drill (verify the mechanism without touching live data)

`docker/mariadb/restore-drill.sh` automates exactly this, but into an isolated `cmis_restore_drill`
database (via `mariadb-binlog --rewrite-db`) instead of the live `cmis` — safe to run at any time,
including against a production replica, without any risk to real data:

```bash
docker compose exec mariadb sh /var/lib/mysql-scripts/restore-drill.sh \
  /backups/<backup-file>.sql.gz "<stop-datetime, e.g. 2026-09-09 07:14:35>"
```

Verify afterward with e.g. `SELECT ... FROM cmis_restore_drill.<table>` and compare against `cmis`.
Drop `cmis_restore_drill` when done (`DROP DATABASE cmis_restore_drill;`).

## Drill results (2026-09-09)

Ran the full drill end-to-end against real data in the dev `cmis` database:

1. Full backup taken (`cmis-full-20260909T071414Z.sql.gz`).
2. A tracked row inserted immediately after (`labs` id 142, created 2026-09-09 07:14:28 UTC) —
   deliberately *not* in the full backup, only recoverable via binlog replay.
3. Drill restore stopping at `07:14:35` (7 seconds after the insert): the row **was** present in
   `cmis_restore_drill` — proves the mechanism recovers changes made after the last full backup.
4. A second drill restore stopping at `07:14:27` (1 second *before* the insert): the row was
   correctly **absent** — proves genuine point-in-time precision, not "replay everything available."
5. Both drills completed in ~1 second (tiny dev dataset — see caveat below).

**RPO**: satisfied by construction — continuous binlog means the only unrecoverable window is
whatever hasn't been synced to the binlog yet (`sync_binlog=1` syncs on every commit), which is
effectively zero, well inside the 15-minute target. The 15-minute figure in practice bounds how
*stale* your last verified-restorable point can be if binlog shipping/backup to an off-host location
lags — this project's dev setup doesn't yet ship backups off-host (see Known open items).

**RTO**: ~1 second measured, but **this is not a real RTO figure** — it reflects a near-empty dev
database, not production data volume. `mariadb-dump`/restore time scales with data size (roughly
linearly for a logical dump+reload like this one), so the ≤4-hour target must be re-validated against
a realistically-sized dataset before going live, and periodically afterward as real data grows. This
is a genuine, flagged gap, not a claimed pass — see Known open items below.

## An unplanned real recovery, the same day (2026-09-09)

While investigating an unrelated test failure right after the drill above, a `php artisan
migrate:fresh --env=testing --force` command was run to try to reproduce it. `--env=testing` was
assumed to point the connection at `cmis_testing` — it doesn't: this project has no `.env.testing`
file, so the flag had no effect, and the command ran against the connection `.env` actually names
(`cmis`) and dropped every table's data.

This was recovered for real, live, using exactly the mechanism this runbook describes — not a drill:
the full backup from earlier that day was restored into a freshly recreated `cmis`, then the binlog
was replayed from the backup's embedded position up to (but excluding) the exact byte position where
the accidental `migrate:fresh` began (found via `mariadb-binlog | grep -B3 "generated by server"` —
Laravel's schema builder tags every table it drops for `migrate:fresh`/`db:wipe` with that comment,
which is what made the accidental drop unambiguously identifiable in the binlog stream against
everything else). The live database was back and serving requests within a few minutes.

**Two real lessons from this, beyond the mechanism itself working as designed**:
- **`artisan --env=<name>` does nothing useful without a matching `.env.<name>` file** — it is not a
  safe way to redirect a command at a different database "just for this one run." If a task ever
  needs that, it should pass `--database=<connection-name>` (a named connection in `config/
  database.php`) explicitly, never rely on `--env` alone.
- **A destructive operation needing user confirmation is exactly the case a backup mechanism exists
  for** — this incident is the reason NFR-09 matters in the first place, played out for real rather
  than staying hypothetical. The recovery worked; the underlying mistake (running an unreviewed
  destructive command against the wrong target) is the thing to actually prevent next time, not
  something a backup alone fixes.

**A separate, still-unexplained finding surfaced while verifying the recovery**: the restored `cmis`
had far less data than the project's own history implies it should have had — no `wittaya.su` (the
real ADMIN account), only a couple of stray test rows. Checking the backup file itself directly
(independent of the restore process) confirmed this wasn't a restore bug — the *backup*, taken at
07:14:14 that same day, already only contained that much. So whatever removed the richer historical
dataset happened **before** this whole T-054 drill started, not because of it. The root cause hasn't
been tracked down (user-deprioritized for now — see below); a real admin account needs to be
re-provisioned (real SSO login + `Role::where('code','ADMIN')` grant via tinker, the same
bootstrapping step CLAUDE.md's "Known open items" already describes) before this app is usable
again by a real person.

## Known open items

- **Root cause of the pre-existing data loss (above) is not yet identified.** Candidates worth
  checking first if this is investigated later: whether `docker compose down` (removes containers
  but not named volumes, should be safe) vs. some other command that *does* remove volumes was ever
  run; whether Docker Desktop's documented instability this session ever came with a volume reset;
  or an earlier, unlogged `migrate:fresh`/`db:wipe` against `cmis` from a prior session. User-
  deprioritized 2026-09-09 ("เดินหน้าต่อไปก่อนเลย admin ค่อยเพิ่มทีหลัง" — move on, re-add admin later).
- **No admin user currently exists in `cmis`.** Needs the same bootstrapping step used the first
  time (real SSO login, then `php artisan tinker` to attach the `ADMIN` role) before the app's own
  `/admin/users` UI can be used to manage anyone else.
- **Backups aren't shipped off-host yet** — `./storage/backups` lives on the same machine as the
  database it backs up, so a whole-host failure (not just a bad migration) would take both out
  together. A real deployment needs these copied somewhere else (object storage, a second host) on
  the same daily cadence, which this project's Docker Compose setup doesn't do on its own.
- **RTO (~1s measured) has only been validated against a near-empty dev dataset** — re-validate
  against production-scale data before relying on the ≤4h target for real, and periodically after as
  data volume grows (see the RTO note above).
