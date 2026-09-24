-- ---------------------------------------------------------------------
-- Options de la coupure Internet
--
-- La coupure n'est jamais temporaire : elle reste active tant qu'un
-- administrateur ne la leve pas explicitement. On enregistre seulement
-- le motif, affiche dans le panneau et journalise dans les evenements.
--
-- A executer une seule fois (ou via database/migrate.php) :
--   mysql -u <user> -p -h 127.0.0.1 --port=3307 intranet_monitor < migration_block_options.sql
-- ---------------------------------------------------------------------

ALTER TABLE `agents`
    ADD COLUMN `internet_block_reason` VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Motif de la coupure saisi par l administrateur' AFTER `internet_block_by`;
