-- =====================================================================
-- Intranet Monitor Pro - Schema MySQL / MariaDB
-- =====================================================================
-- Import :
--   mysql -u root -p < database/schema.sql
-- ou via phpMyAdmin > Importer
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `intranet_monitor`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `intranet_monitor`;

-- ---------------------------------------------------------------------
-- users : comptes du dashboard
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          ENUM('admin','viewer') NOT NULL DEFAULT 'admin',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at` DATETIME     NULL DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- agents : un enregistrement par poste Windows supervisé
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agents` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hostname`          VARCHAR(128) NOT NULL,
    `token_hash`        CHAR(64)     NOT NULL COMMENT 'sha256 hex digest',
    `token_hint`        VARCHAR(8)   NOT NULL COMMENT 'derniers caracteres du token, pour affichage discret',
    `os_name`           VARCHAR(128) NULL,
    `os_version`        VARCHAR(64)  NULL,
    `current_user`      VARCHAR(128) NULL,
    `domain`            VARCHAR(128) NULL,
    `primary_ip`        VARCHAR(45)  NULL,
    `primary_mac`       VARCHAR(17)  NULL,
    `gateway`           VARCHAR(45)  NULL,
    `ethernet_active`   TINYINT(1)   NOT NULL DEFAULT 0,
    `wifi_active`       TINYINT(1)   NOT NULL DEFAULT 0,
    `wifi_ssid`         VARCHAR(128) NULL,
    `internet_status`   TINYINT(1)   NOT NULL DEFAULT 0,
    `internet_test`     VARCHAR(255) NULL COMMENT 'detail JSON compact des 3 tests',
    `internet_latency`  INT UNSIGNED NULL COMMENT 'ms',
    `internet_checked_at` DATETIME   NULL,
    `agent_version`     VARCHAR(32)  NULL,
    `is_active`         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'admin peut desactiver un agent',
    `last_seen`         DATETIME     NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_agents_token_hash` (`token_hash`),
    KEY `idx_agents_hostname` (`hostname`),
    KEY `idx_agents_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- network_adapters : etat courant de chaque interface reseau d'un agent
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_adapters` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`    INT UNSIGNED NOT NULL,
    `type`        ENUM('ETHERNET','WIFI','OTHER') NOT NULL,
    `name`        VARCHAR(128) NOT NULL,
    `status`      ENUM('UP','DOWN') NOT NULL,
    `ipv4`        VARCHAR(45)  NULL,
    `mac`         VARCHAR(17)  NULL,
    `gateway`     VARCHAR(45)  NULL,
    `ssid`        VARCHAR(128) NULL,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_adapter_agent_name` (`agent_id`, `name`),
    KEY `idx_adapters_agent` (`agent_id`),
    CONSTRAINT `fk_adapters_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- network_history : snapshots horodates (pour graphiques Chart.js)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_history` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`         INT UNSIGNED NOT NULL,
    `internet_status`  TINYINT(1) NOT NULL,
    `internet_latency` INT UNSIGNED NULL,
    `ethernet_active`  TINYINT(1) NOT NULL DEFAULT 0,
    `wifi_active`      TINYINT(1) NOT NULL DEFAULT 0,
    `recorded_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_history_agent_time` (`agent_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- events : journal des changements d'etat
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`   INT UNSIGNED NOT NULL,
    `type`       ENUM(
                    'AGENT_ONLINE','AGENT_OFFLINE',
                    'WIFI_CONNECTED','WIFI_DISCONNECTED',
                    'INTERNET_ON','INTERNET_OFF',
                    'INTERFACE_ADDED','INTERFACE_REMOVED',
                    'IP_CHANGED','GATEWAY_CHANGED',
                    'NETWORK_ANOMALY_DETECTED','GHOST_DEVICE_DETECTED'
                 ) NOT NULL,
    `message`    VARCHAR(255) NOT NULL,
    `details`    TEXT NULL COMMENT 'JSON encode',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_events_agent_time` (`agent_id`, `created_at`),
    KEY `idx_events_type` (`type`),
    CONSTRAINT `fk_events_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- alerts : alertes actives / a traiter
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `alerts` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`   INT UNSIGNED NOT NULL,
    `type`       ENUM(
                    'DOUBLE_CONNEXION','WIFI_ACTIVATED','INTERNET_DETECTED',
                    'AGENT_UNREACHABLE','INTERFACE_ANOMALY',
                    'NETWORK_ANOMALY','GHOST_DEVICE'
                 ) NOT NULL,
    `severity`   ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    `message`    VARCHAR(255) NOT NULL,
    `details`    TEXT NULL COMMENT 'JSON encode',
    `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
    `read_at`    DATETIME NULL,
    `notified_at` DATETIME NULL COMMENT 'date d envoi de la notification email (throttle)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_alerts_is_read` (`is_read`),
    KEY `idx_alerts_agent` (`agent_id`),
    CONSTRAINT `fk_alerts_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- api_logs : tracabilite + support du rate limiting
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_logs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_id`   INT UNSIGNED NULL,
    `endpoint`   VARCHAR(64)  NOT NULL,
    `ip_address` VARCHAR(45)  NOT NULL,
    `status_code` SMALLINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_apilogs_agent_time` (`agent_id`, `created_at`),
    KEY `idx_apilogs_ip_time` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- agent_baselines : empreinte reseau attendue par poste (detection d'anomalies)
-- 1 ligne par agent au maximum ; absence de ligne = pas de baseline (detecteur inactif pour ce poste)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_baselines` (
    `agent_id`      INT UNSIGNED NOT NULL,
    `expected_mac`  VARCHAR(17)  NULL COMMENT 'NULL = auto-apprise au premier rapport',
    `expected_cidr` VARCHAR(43)  NULL COMMENT 'ex: 192.168.1.0/24 ; NULL = utiliser le CIDR global des reglages',
    `locked_at`     DATETIME     NULL COMMENT 'date de capture/validation de la baseline',
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`agent_id`),
    CONSTRAINT `fk_baselines_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- known_ssids : liste blanche globale des SSID Wi-Fi autorises
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `known_ssids` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ssid`       VARCHAR(128) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_known_ssids_ssid` (`ssid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- lan_neighbors : appareils vus sur le LAN (voisins ARP remontes par les agents)
-- 1 ligne par MAC (dedup global) ; is_known recalcule a chaque upsert
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lan_neighbors` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mac`              VARCHAR(17)  NOT NULL,
    `last_ip`          VARCHAR(45)  NULL,
    `seen_by_agent_id` INT UNSIGNED NOT NULL COMMENT 'dernier agent l ayant vu',
    `is_known`         TINYINT(1)   NOT NULL DEFAULT 0,
    `is_ignored`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'ecarte manuellement par un admin (ex: imprimante)',
    `first_seen`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sightings_count`  INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_neighbors_mac` (`mac`),
    KEY `idx_neighbors_known` (`is_known`),
    KEY `idx_neighbors_agent` (`seen_by_agent_id`),
    CONSTRAINT `fk_neighbors_agent` FOREIGN KEY (`seen_by_agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- settings : configuration cle/valeur
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(64)  NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('offline_threshold_seconds', '180'),
    ('report_min_interval_seconds', '20'),
    ('dashboard_poll_seconds', '12'),
    ('anomaly_detection_enabled', '1'),
    ('anomaly_default_cidr', ''),
    ('ghost_scan_enabled', '1'),
    ('ghost_scan_min_interval_seconds', '900'),
    ('smtp_enabled', '0'),
    ('smtp_host', ''),
    ('smtp_port', '587'),
    ('smtp_encryption', 'tls'),
    ('smtp_username', ''),
    ('smtp_password_enc', ''),
    ('smtp_from_email', ''),
    ('smtp_from_name', 'Intranet Monitor Pro'),
    ('smtp_to_emails', ''),
    ('notify_min_severity', 'critical'),
    ('notify_throttle_seconds', '1800')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- ---------------------------------------------------------------------
-- Compte admin par defaut
--   identifiant : admin
--   mot de passe : ChangeMoi!2026   (a changer immediatement)
-- Hash genere avec password_hash('ChangeMoi!2026', PASSWORD_BCRYPT)
-- ---------------------------------------------------------------------
INSERT INTO `users` (`username`, `password_hash`, `role`)
VALUES ('admin', '$2y$10$v.0eJ6hDu54/WFOJ2u0BS.W.x4WSCay6qGU1UoH715/Ku7QxTcp1y', 'admin')
ON DUPLICATE KEY UPDATE `username` = `username`;
