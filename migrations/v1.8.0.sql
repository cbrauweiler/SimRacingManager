-- ============================================================
-- SimRace Liga Manager – Migration v1.7.5 → v1.8.0
-- ============================================================
-- Idempotent: kann gefahrlos mehrfach ausgeführt werden.
-- Anwenden via phpMyAdmin (Datenbank auswählen → Reiter "SQL")
-- oder per CLI:  mysql -u USER -p DBNAME < migrations/v1.8.0.sql
-- Hinweis: Der Web-Installer (install.php) enthält dieselben
-- Migrationen bereits in install.sql – ein erneuter Lauf genügt.
-- ============================================================

-- Punkt 1: Saison ohne Jahreszahl – Jahr-Spalte entfernen
ALTER TABLE `seasons`
  DROP COLUMN IF EXISTS `year`;

-- Punkt 2/6: Renndauer kann Runden ODER Minuten sein
ALTER TABLE `races`
  ADD COLUMN IF NOT EXISTS `duration_type` ENUM('laps','minutes') NOT NULL DEFAULT 'laps';

-- Punkt 5: Standard-Rennstartzeit für die Kalender-Anlage
INSERT INTO `settings` (`key`, `value`) VALUES
('default_race_time', '')
ON DUPLICATE KEY UPDATE `value` = `value`;

-- Punkt 7: Teams n:m – ein Team kann in mehreren Saisons gemeldet sein
CREATE TABLE IF NOT EXISTS `team_seasons` (
  `team_id`   INT NOT NULL,
  `season_id` INT NOT NULL,
  PRIMARY KEY (`team_id`,`season_id`),
  FOREIGN KEY (`team_id`)   REFERENCES `teams`(`id`)   ON DELETE CASCADE,
  FOREIGN KEY (`season_id`) REFERENCES `seasons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_team_seasons_season ON `team_seasons`(`season_id`);

-- teams.season_id wird zur Legacy-Spalte (nicht mehr für Filter genutzt)
ALTER TABLE `teams`
  MODIFY `season_id` INT NULL;

-- Bestehende 1:1-Zuordnungen in die Junction-Tabelle übernehmen
INSERT IGNORE INTO `team_seasons` (`team_id`, `season_id`)
  SELECT `id`, `season_id` FROM `teams` WHERE `season_id` IS NOT NULL;
