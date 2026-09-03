-- T-027 / SEC-DB-02 / AGENT RULE #6 (layer #1 of 2 — layer #2 is the
-- trg_ledger_no_update / trg_ledger_no_delete triggers on stock_ledger).
--
-- The official mariadb image's MARIADB_USER/MARIADB_PASSWORD bootstrap grants
-- `GRANT ALL PRIVILEGES ON <db>.*` to the app's DB user — including UPDATE
-- and DELETE on stock_ledger and audit_logs, both of which must be
-- append-only in every circumstance. MySQL/MariaDB privilege checks are a
-- union across grant levels, so a db-level UPDATE/DELETE grant cannot be
-- selectively revoked for one table while a coarser db-level grant still
-- exists — the db-level grant must be replaced by explicit per-table grants
-- instead.
--
-- NOT a docker-entrypoint-initdb.d script: this MariaDB version requires a
-- table-level GRANT's target table to already exist (confirmed empirically —
-- `GRANT ... ON db.no_such_table ...` errors 1146 even when the database
-- itself exists), so this cannot run before migrations create stock_ledger/
-- audit_logs. Run this by hand once, after `php artisan migrate`, against
-- every database the app uses:
--
--   docker compose exec -T mariadb mariadb -uroot -p"$DB_ROOT_PASSWORD" \
--     < docker/mariadb/restrict_app_grants.sql
--
-- Idempotent (safe to re-run — e.g. after a fresh `migrate:fresh`, or after
-- manually creating cmis_testing per CLAUDE.md's cmis_testing deviation
-- note). Silently skips stock_ledger/audit_logs for any database where
-- those tables don't exist yet (e.g. run once against `cmis` right after
-- migrating it, before `cmis_testing` has been created/migrated at all —
-- re-run afterward to cover it too).

USE mysql;

DROP PROCEDURE IF EXISTS cmis_restrict_app_grants;

DELIMITER $$

CREATE PROCEDURE cmis_restrict_app_grants(IN db_name VARCHAR(64))
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE tbl VARCHAR(64);
    DECLARE cur CURSOR FOR
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = db_name
          AND table_name NOT IN ('stock_ledger', 'audit_logs');
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    SET @sql = CONCAT('REVOKE ALL PRIVILEGES ON `', db_name, '`.* FROM ''cmis_app''@''%''');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

    -- schema-management privileges stay at db level — migrations need these
    SET @sql = CONCAT(
        'GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE TEMPORARY TABLES, ',
        'LOCK TABLES, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, ',
        'EXECUTE, EVENT, TRIGGER ON `', db_name, '`.* TO ''cmis_app''@''%'''
    );
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

    -- full DML on every table that exists except the two append-only ones
    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO tbl;
        IF done THEN
            LEAVE read_loop;
        END IF;
        SET @sql = CONCAT(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `', db_name, '`.`', tbl,
            '` TO ''cmis_app''@''%'''
        );
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;

    -- append-only tables: SELECT + INSERT only, never UPDATE/DELETE — only if
    -- they've actually been migrated yet in this database
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = db_name AND table_name = 'stock_ledger') THEN
        SET @sql = CONCAT('GRANT SELECT, INSERT ON `', db_name, '`.`stock_ledger` TO ''cmis_app''@''%''');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = db_name AND table_name = 'audit_logs') THEN
        SET @sql = CONCAT('GRANT SELECT, INSERT ON `', db_name, '`.`audit_logs` TO ''cmis_app''@''%''');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL cmis_restrict_app_grants('cmis');
CALL cmis_restrict_app_grants('cmis_testing');

DROP PROCEDURE cmis_restrict_app_grants;

FLUSH PRIVILEGES;
