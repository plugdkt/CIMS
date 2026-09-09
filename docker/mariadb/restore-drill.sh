#!/bin/sh
# T-054 / NFR-09 restore drill — proves the full-backup + binlog-replay
# mechanism actually recovers to a specific point in time, WITHOUT touching
# the live `cmis` database: everything is restored into a separate
# `cmis_restore_drill` database via `mariadb-binlog --rewrite-db`, which
# retargets the replayed row events to a differently-named database instead
# of the one they were originally recorded against.
#
# Usage (from inside the mariadb container):
#   sh /var/lib/mysql-scripts/restore-drill.sh <full-backup.sql.gz> <stop-datetime>
#
# <stop-datetime> is passed to `mariadb-binlog --stop-datetime`, e.g.
# "2026-09-09 07:12:20" — everything up to and including that moment is
# replayed; anything after is intentionally left out, proving the mechanism
# can recover to an arbitrary point, not just "replay everything available".
set -eu

BACKUP_FILE="$1"
STOP_DATETIME="$2"
DRILL_DB="cmis_restore_drill"

echo "=== T-054 restore drill starting: $(date -u +%Y-%m-%dT%H:%M:%SZ) ==="
START_EPOCH=$(date +%s)

echo "--- Step 1: recreate empty ${DRILL_DB} ---"
mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" -e "DROP DATABASE IF EXISTS ${DRILL_DB}; CREATE DATABASE ${DRILL_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "--- Step 2: restore full backup (${BACKUP_FILE}) into ${DRILL_DB} ---"
# backup-full.sh's dump carries no database-context statements (no
# `--databases` flag was used), so it loads into whatever database is named
# on the command line here — ${DRILL_DB}, not a second `cmis`.
gunzip -c "${BACKUP_FILE}" | mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" "${DRILL_DB}"

echo "--- Step 3: find this backup's own binlog starting position ---"
START_LOG_FILE=$(gunzip -c "${BACKUP_FILE}" | grep -m1 "CHANGE MASTER TO" | sed -n "s/.*MASTER_LOG_FILE='\([^']*\)'.*/\1/p")
START_LOG_POS=$(gunzip -c "${BACKUP_FILE}" | grep -m1 "CHANGE MASTER TO" | sed -n "s/.*MASTER_LOG_POS=\([0-9]*\).*/\1/p")
echo "Binlog start position: ${START_LOG_FILE}:${START_LOG_POS}"

echo "--- Step 4: replay binlog from that position up to ${STOP_DATETIME}, rewritten into ${DRILL_DB} ---"
mariadb-binlog \
    --start-position="${START_LOG_POS}" \
    --stop-datetime="${STOP_DATETIME}" \
    --rewrite-db="cmis->${DRILL_DB}" \
    "/var/lib/mysql/${START_LOG_FILE}" \
    | mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" "${DRILL_DB}"

END_EPOCH=$(date +%s)
ELAPSED=$((END_EPOCH - START_EPOCH))
echo "=== Restore drill finished in ${ELAPSED}s ==="
