#!/bin/sh
# T-054 / NFR-09: full daily backup. Run from the host:
#   docker compose exec -T mariadb sh /var/lib/mysql-scripts/backup-full.sh
# (mounted read-only into the container — see docker-compose.yml). Writes to
# /backups, which is bind-mounted to ./storage/backups on the host.
#
# --master-data=2 embeds the binlog file+position active at dump time as a
# commented CHANGE MASTER TO statement in the dump — this is the join point
# between "restore the full backup" and "replay binlog from here", which is
# what makes point-in-time recovery (RPO < 15 min) possible at all instead of
# only being able to restore to the moment of the last full backup.
#
# Deliberately NOT --databases (which would embed its own `CREATE DATABASE`/
# `USE cmis` statements) — this dumps `cmis`'s contents with no database
# context baked in, so restoring it is just `mariadb <target-db> < dump`,
# whatever that target database is named. That's what lets a restore drill
# load the exact same dump into an isolated `cmis_restore_drill` database
# instead of the live `cmis` one, with no rewriting of the dump itself needed.
set -eu

BACKUP_DIR="/backups"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT_FILE="${BACKUP_DIR}/cmis-full-${TIMESTAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

mariadb-dump \
    -uroot -p"${MARIADB_ROOT_PASSWORD}" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --master-data=2 \
    cmis \
    | gzip > "${OUT_FILE}"

echo "Backup written: ${OUT_FILE}"
