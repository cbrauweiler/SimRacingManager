-- ============================================================
-- SimRace Liga Manager - Datenbankschema v2.0
-- Kompletter Neuaufbau mit sauberer Saison-Fahrer-Zuordnung
-- ============================================================

CREATE DATABASE IF NOT EXISTS `simracing_liga` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `simracing_liga`;

-- -------------------------------------------------------
-- Liga Einstellungen
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`key`, `value`) VALUES
('league_name',             'SimRace Liga'),
('league_abbr',             'SRL'),
('league_sub',              'Management System'),
('league_desc',             'Die kompetitivste Simracing-Liga.'),
('league_logo',             ''),
('league_favicon',          ''),
('color_primary',           '#e8333a'),
('color_secondary',         '#f5a623'),
('color_tertiary',          '#1a9fff'),
('color_bg',                '#0a0a0f'),
('color_text',              '#f0f0f5'),
('info_html',               '<h2>Willkommen</h2><p>Infos über unsere Liga...</p>'),
('points_system',           '25,18,15,12,10,8,6,4,2,1'),
('social_links',            '[]'),
('discord_webhook_url',     ''),
('discord_notify_results',  '1'),
('discord_notify_news',     '0'),
('qualifying_enabled',      '1'),
('penalties_enabled',       '1'),
('bonus_points_pole',       '1'),
('bonus_points_fl',         '1'),
('maintenance_mode',        '0'),
('google_analytics',        ''),
('reserve_scores_driver',    '1'),
('reserve_scores_team',      '0'),
('fl_only_if_finished',      '1'),
('pole_only_if_finished',    '0')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- -------------------------------------------------------
-- Admin Benutzer
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(80) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email`         VARCHAR(150),
  `role`          ENUM('superadmin','admin','editor') DEFAULT 'admin',
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_login`    TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default: admin / admin123  → bitte sofort ändern!
INSERT INTO `admin_users` (`username`, `password_hash`, `email`, `role`) VALUES
('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', 'superadmin')
ON DUPLICATE KEY UPDATE `username` = `username`;

-- -------------------------------------------------------
-- News
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `news` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `title`      VARCHAR(255) NOT NULL,
  `slug`       VARCHAR(255) NOT NULL UNIQUE,
  `category`   VARCHAR(80) DEFAULT 'News',
  `excerpt`    TEXT,
  `content`    LONGTEXT,
  `image_path` VARCHAR(500),
  `published`  TINYINT(1) DEFAULT 1,
  `author_id`  INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`author_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Saisons
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `seasons` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(150) NOT NULL,
  `year`        YEAR,
  `game`        VARCHAR(150),
  `car_class`   VARCHAR(150),
  `description` TEXT,
  `is_active`   TINYINT(1) DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Fahrer  →  GLOBAL, saisonunabhängig
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `drivers` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(150) NOT NULL,
  `nationality` VARCHAR(5),
  `photo_path`  VARCHAR(500),
  `bio`         TEXT,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Teams  →  je Saison
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teams` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `season_id`    INT NOT NULL,
  `name`         VARCHAR(150) NOT NULL,
  `abbreviation` VARCHAR(10),
  `color`        VARCHAR(7) DEFAULT '#e8333a',
  `logo_path`    VARCHAR(500),
  `car`          VARCHAR(150),
  `nationality`  VARCHAR(100),
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`season_id`) REFERENCES `seasons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Saison-Lineup  →  Fahrer ↔ Team je Saison
-- Hier wird die saisonspezifische Zuordnung gespeichert
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `season_entries` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `season_id`  INT NOT NULL,
  `driver_id`  INT NOT NULL,
  `team_id`    INT,
  `number`     SMALLINT UNSIGNED,
  `is_reserve` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_season_driver` (`season_id`, `driver_id`),
  FOREIGN KEY (`season_id`) REFERENCES `seasons`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`team_id`)   REFERENCES `teams`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Strecken
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tracks` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `name`              VARCHAR(200) NOT NULL UNIQUE,
  `location`          VARCHAR(200),
  `country`           VARCHAR(100),
  `length_km`         DECIMAL(6,3),
  `corners`           SMALLINT UNSIGNED,
  `lap_record`        VARCHAR(30),
  `lap_record_driver` VARCHAR(150),
  `lap_record_year`   YEAR,
  `image_path`        VARCHAR(500),
  `layout_path`       VARCHAR(500),
  `description`       TEXT,
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Rennkalender
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `races` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `season_id`      INT NOT NULL,
  `track_id`       INT,
  `round`          SMALLINT UNSIGNED NOT NULL,
  `track_name`     VARCHAR(200) NOT NULL,
  `location`       VARCHAR(200),
  `country`        VARCHAR(100),
  `race_date`      DATE,
  `race_time`      TIME,
  `laps`           SMALLINT UNSIGNED,
  `notes`          TEXT,
  `discord_posted` TINYINT(1) DEFAULT 0,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`season_id`) REFERENCES `seasons`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`track_id`)  REFERENCES `tracks`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Rennergebnisse (Header)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `results` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `race_id`               INT NOT NULL,
  `game`                  VARCHAR(100),
  `file_path`             VARCHAR(500),
  `fastest_lap_driver_id` INT,
  `weather`               VARCHAR(100),
  `notes`                 TEXT,
  `imported_at`           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`race_id`)               REFERENCES `races`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`fastest_lap_driver_id`) REFERENCES `drivers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Ergebnis-Einträge (pro Fahrer pro Rennen)
-- driver_id → globaler Fahrer
-- team_id   → Team dieser Saison (aus season_entries)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `result_entries` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `result_id`       INT NOT NULL,
  `position`        TINYINT UNSIGNED,
  `driver_id`       INT,
  `driver_name_raw` VARCHAR(150),
  `team_id`         INT,
  `team_name_raw`   VARCHAR(150),
  `laps`            SMALLINT UNSIGNED,
  `total_time`      VARCHAR(30),
  `gap`             VARCHAR(30),
  `fastest_lap`     VARCHAR(30),
  `is_fastest_lap`  TINYINT(1) DEFAULT 0,
  `dnf`             TINYINT(1) DEFAULT 0,
  `dsq`             TINYINT(1) DEFAULT 0,
  `points`          DECIMAL(5,1) DEFAULT 0,
  `bonus_points`    DECIMAL(5,1) DEFAULT 0,
  FOREIGN KEY (`result_id`) REFERENCES `results`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`)  ON DELETE SET NULL,
  FOREIGN KEY (`team_id`)   REFERENCES `teams`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Qualifying
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qualifying_results` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `race_id`         INT NOT NULL,
  `driver_id`       INT,
  `driver_name_raw` VARCHAR(150),
  `team_id`         INT,
  `position`        TINYINT UNSIGNED,
  `lap_time`        VARCHAR(30),
  `gap`             VARCHAR(30),
  `laps`            SMALLINT UNSIGNED,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`race_id`)   REFERENCES `races`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`team_id`)   REFERENCES `teams`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Penalties / Strafen
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `penalties` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `result_id`       INT NOT NULL,
  `driver_id`       INT,
  `driver_name_raw` VARCHAR(150),
  `type`            ENUM('time','points','grid','warning','dsq') DEFAULT 'time',
  `amount`          DECIMAL(8,3) DEFAULT 0,
  `reason`          TEXT,
  `applied`         TINYINT(1) DEFAULT 1,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`result_id`) REFERENCES `results`(`id`)  ON DELETE CASCADE,
  FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Audit Log
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT,
  `username`     VARCHAR(80),
  `action`       VARCHAR(100),
  `target_table` VARCHAR(80),
  `target_id`    INT,
  `details`      TEXT,
  `ip`           VARCHAR(45),
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------
-- Indices
-- -------------------------------------------------------
CREATE INDEX idx_season_entries_season ON `season_entries`(`season_id`);
CREATE INDEX idx_season_entries_driver ON `season_entries`(`driver_id`);
CREATE INDEX idx_season_entries_team   ON `season_entries`(`team_id`);
CREATE INDEX idx_results_race          ON `results`(`race_id`);
CREATE INDEX idx_entries_result        ON `result_entries`(`result_id`);
CREATE INDEX idx_entries_driver        ON `result_entries`(`driver_id`);
CREATE INDEX idx_entries_team          ON `result_entries`(`team_id`);
CREATE INDEX idx_races_season          ON `races`(`season_id`);
CREATE INDEX idx_teams_season          ON `teams`(`season_id`);

-- Migration: Reset-Token für Passwort-vergessen Funktion
ALTER TABLE `admin_users`
  ADD COLUMN IF NOT EXISTS `reset_token`         VARCHAR(64)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `reset_token_expires` DATETIME     NULL DEFAULT NULL;

-- Mail-Einstellungen in settings
INSERT INTO `settings` (`key`, `value`) VALUES
('mail_from',       ''),
('mail_from_name',  ''),
('mail_smtp',       '0'),
('mail_smtp_host',  ''),
('mail_smtp_port',  '587'),
('mail_smtp_user',  ''),
('mail_smtp_pass',  ''),
('mail_smtp_enc',   'tls')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- Migration: MFA / TOTP
ALTER TABLE `admin_users`
  ADD COLUMN IF NOT EXISTS `mfa_secret`  VARCHAR(64)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `mfa_enabled` TINYINT(1)   NOT NULL DEFAULT 0;

-- Settings für MFA
INSERT INTO `settings` (`key`,`value`) VALUES ('mfa_optional','1') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);

-- Migration: Navigation-Reihenfolge
INSERT INTO `settings` (`key`, `value`) VALUES ('nav_items', '') ON DUPLICATE KEY UPDATE `value`=`value`;
