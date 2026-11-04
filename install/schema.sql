-- SpaceGame Database Schema
-- MySQL 5.7+ / MariaDB 10.3+
-- InnoDB, utf8mb4

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- users
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `pass_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- games
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `games` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `speed` DOUBLE NOT NULL DEFAULT 1,
    `cpu_res_mult` DOUBLE NOT NULL DEFAULT 1,
    `cpu_research_mult` DOUBLE NOT NULL DEFAULT 1,
    `settings_json` JSON NOT NULL,
    `config_json` JSON NOT NULL,
    `processed_until` DOUBLE NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    CONSTRAINT `fk_games_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- empires
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `empires` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `kind` ENUM('player','cpu','simple','pirate') NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `personality` VARCHAR(30) DEFAULT NULL,
    `settings_json` JSON DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `game_id` (`game_id`),
    KEY `game_kind` (`game_id`, `kind`),
    CONSTRAINT `fk_empires_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- bodies (pianeti e lune)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `bodies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `empire_id` INT UNSIGNED DEFAULT NULL,
    `g` TINYINT UNSIGNED NOT NULL,
    `s` SMALLINT UNSIGNED NOT NULL,
    `p` TINYINT UNSIGNED NOT NULL,
    `is_moon` TINYINT(1) NOT NULL DEFAULT 0,
    `moon_of` INT UNSIGNED DEFAULT NULL,
    `type` VARCHAR(30) NOT NULL,
    `temp_class` TINYINT UNSIGNED NOT NULL,
    `fields_base` INT UNSIGNED NOT NULL,
    `fields_bonus` INT NOT NULL DEFAULT 0,
    `res_m` DOUBLE NOT NULL DEFAULT 0,
    `res_c` DOUBLE NOT NULL DEFAULT 0,
    `res_d` DOUBLE NOT NULL DEFAULT 0,
    `res_lu` DOUBLE NOT NULL DEFAULT 0,
    `energy_store` DOUBLE NOT NULL DEFAULT 0,
    `resistance` DOUBLE NOT NULL DEFAULT 0,
    `last_update` DOUBLE NOT NULL DEFAULT 0,
    `destroyed_until` DOUBLE DEFAULT NULL,
    `move_json` JSON DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `game_coord_moon` (`game_id`, `g`, `s`, `p`, `is_moon`),
    KEY `empire_id` (`empire_id`),
    KEY `moon_of` (`moon_of`),
    CONSTRAINT `fk_bodies_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bodies_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_bodies_moon_of` FOREIGN KEY (`moon_of`) REFERENCES `bodies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- buildings
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildings` (
    `body_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `level` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `completed_at` DOUBLE NOT NULL DEFAULT 0,
    PRIMARY KEY (`body_id`, `code`),
    CONSTRAINT `fk_buildings_body` FOREIGN KEY (`body_id`) REFERENCES `bodies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- research
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `research` (
    `empire_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `level` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`empire_id`, `code`),
    CONSTRAINT `fk_research_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- upgrades (potenziamenti unità/difese)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `upgrades` (
    `empire_id` INT UNSIGNED NOT NULL,
    `unit_code` VARCHAR(50) NOT NULL,
    `branch` VARCHAR(30) NOT NULL,
    `level` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`empire_id`, `unit_code`, `branch`),
    CONSTRAINT `fk_upgrades_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- units (navi e difese su corpo)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `units` (
    `body_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(50) NOT NULL,
    `qty` DOUBLE NOT NULL DEFAULT 0,
    `damage` DOUBLE NOT NULL DEFAULT 0,
    PRIMARY KEY (`body_id`, `code`),
    CONSTRAINT `fk_units_body` FOREIGN KEY (`body_id`) REFERENCES `bodies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- queues (code di costruzione/ricerca/navi/potenziamenti/missili)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `queues` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `body_id` INT UNSIGNED NOT NULL,
    `empire_id` INT UNSIGNED NOT NULL,
    `kind` ENUM('building','research','shipyard','upgrade','silo') NOT NULL,
    `slot` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `code` VARCHAR(50) NOT NULL,
    `qty` DOUBLE NOT NULL DEFAULT 1,
    `position` INT UNSIGNED NOT NULL DEFAULT 0,
    `state` ENUM('waiting','running','paused') NOT NULL DEFAULT 'waiting',
    `started_at` DOUBLE DEFAULT NULL,
    `ends_at` DOUBLE DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `game_body` (`game_id`, `body_id`),
    KEY `empire_kind` (`empire_id`, `kind`),
    KEY `state_ends` (`state`, `ends_at`),
    CONSTRAINT `fk_queues_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_queues_body` FOREIGN KEY (`body_id`) REFERENCES `bodies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_queues_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- fleets
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `fleets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `empire_id` INT UNSIGNED NOT NULL,
    `mission` VARCHAR(30) NOT NULL,
    `origin_id` INT UNSIGNED NOT NULL,
    `target_g` TINYINT UNSIGNED NOT NULL,
    `target_s` SMALLINT UNSIGNED NOT NULL,
    `target_p` TINYINT UNSIGNED NOT NULL,
    `target_moon` TINYINT(1) NOT NULL DEFAULT 0,
    `units_json` JSON NOT NULL,
    `cargo_json` JSON DEFAULT NULL,
    `speed_pct` TINYINT UNSIGNED NOT NULL DEFAULT 100,
    `depart_at` DOUBLE NOT NULL,
    `arrive_at` DOUBLE NOT NULL,
    `return_at` DOUBLE DEFAULT NULL,
    `hold_until` DOUBLE DEFAULT NULL,
    `state` ENUM('outbound','holding','returning','arrived') NOT NULL DEFAULT 'outbound',
    `extra_json` JSON DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `game_empire` (`game_id`, `empire_id`),
    KEY `arrive_at` (`arrive_at`),
    KEY `return_at` (`return_at`),
    KEY `hold_until` (`hold_until`),
    CONSTRAINT `fk_fleets_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fleets_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fleets_origin` FOREIGN KEY (`origin_id`) REFERENCES `bodies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- events (coda eventi temporali)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `at` DOUBLE NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `ref_id` BIGINT UNSIGNED DEFAULT NULL,
    `payload_json` JSON DEFAULT NULL,
    `done` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `game_done_at` (`game_id`, `done`, `at`),
    CONSTRAINT `fk_events_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- reports (report battaglia)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `attacker_empire_id` INT UNSIGNED DEFAULT NULL,
    `defender_empire_id` INT UNSIGNED DEFAULT NULL,
    `type` VARCHAR(30) NOT NULL,
    `result` ENUM('attacker','defender','draw') NOT NULL,
    `data_json` JSON NOT NULL,
    `created_at` DOUBLE NOT NULL,
    PRIMARY KEY (`id`),
    KEY `game_created` (`game_id`, `created_at`),
    KEY `attacker` (`attacker_empire_id`),
    KEY `defender` (`defender_empire_id`),
    CONSTRAINT `fk_reports_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- log (registro eventi)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `empire_id` INT UNSIGNED NOT NULL,
    `category` VARCHAR(30) NOT NULL,
    `data_json` JSON NOT NULL,
    `created_at` DOUBLE NOT NULL,
    PRIMARY KEY (`id`),
    KEY `empire_category_created` (`empire_id`, `category`, `created_at`),
    CONSTRAINT `fk_log_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- debris (campi detriti)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `debris` (
    `game_id` INT UNSIGNED NOT NULL,
    `g` TINYINT UNSIGNED NOT NULL,
    `s` SMALLINT UNSIGNED NOT NULL,
    `p` TINYINT UNSIGNED NOT NULL,
    `m` DOUBLE NOT NULL DEFAULT 0,
    `c` DOUBLE NOT NULL DEFAULT 0,
    `lu` DOUBLE NOT NULL DEFAULT 0,
    `updated_at` DOUBLE NOT NULL,
    PRIMARY KEY (`game_id`, `g`, `s`, `p`),
    CONSTRAINT `fk_debris_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- asteroid_progress (progresso estrazione asteroidi per impero)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `asteroid_progress` (
    `empire_id` INT UNSIGNED NOT NULL,
    `g` TINYINT UNSIGNED NOT NULL,
    `s` SMALLINT UNSIGNED NOT NULL,
    `p` TINYINT UNSIGNED NOT NULL,
    `stratum` INT UNSIGNED NOT NULL DEFAULT 1,
    `remaining` DOUBLE NOT NULL DEFAULT 0,
    `day` DATE NOT NULL,
    PRIMARY KEY (`empire_id`, `g`, `s`, `p`, `stratum`, `day`),
    CONSTRAINT `fk_asteroid_progress_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- market (tassi di mercato)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `market` (
    `game_id` INT UNSIGNED NOT NULL,
    `res` VARCHAR(20) NOT NULL,
    `rate` DOUBLE NOT NULL,
    PRIMARY KEY (`game_id`, `res`),
    CONSTRAINT `fk_market_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- offers (offerte di mercato)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `offers` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `seller_empire_id` INT UNSIGNED DEFAULT NULL,
    `buy_res` VARCHAR(20) NOT NULL,
    `sell_res` VARCHAR(20) NOT NULL,
    `amount` DOUBLE NOT NULL,
    `rate` DOUBLE NOT NULL,
    `expires_at` DOUBLE NOT NULL,
    `delivered` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `game_expires` (`game_id`, `expires_at`),
    CONSTRAINT `fk_offers_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_offers_seller` FOREIGN KEY (`seller_empire_id`) REFERENCES `empires` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- relations (relazioni diplomatiche)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `relations` (
    `game_id` INT UNSIGNED NOT NULL,
    `a` INT UNSIGNED NOT NULL,
    `b` INT UNSIGNED NOT NULL,
    `score` SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`game_id`, `a`, `b`),
    CONSTRAINT `fk_relations_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- pacts (patti diplomatici)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pacts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `type` ENUM('non_aggression','alliance','tribute') NOT NULL,
    `a` INT UNSIGNED NOT NULL,
    `b` INT UNSIGNED NOT NULL,
    `expires_at` DOUBLE DEFAULT NULL,
    `terms_json` JSON DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `game_ab` (`game_id`, `a`, `b`),
    CONSTRAINT `fk_pacts_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- pirate_levels (livello pirata per impero)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pirate_levels` (
    `empire_id` INT UNSIGNED NOT NULL,
    `level` INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`empire_id`),
    CONSTRAINT `fk_pirate_levels_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- admiral (competenze ammiraglio)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `admiral` (
    `empire_id` INT UNSIGNED NOT NULL,
    `skill` VARCHAR(30) NOT NULL,
    `level` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `medals` DOUBLE NOT NULL DEFAULT 0,
    PRIMARY KEY (`empire_id`, `skill`),
    CONSTRAINT `fk_admiral_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- scores (classifica)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `scores` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `empire_id` INT UNSIGNED NOT NULL,
    `category` VARCHAR(30) NOT NULL,
    `value` DOUBLE NOT NULL DEFAULT 0,
    `updated_at` DOUBLE NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `game_empire_category` (`game_id`, `empire_id`, `category`),
    KEY `game_category_value` (`game_id`, `category`, `value`),
    CONSTRAINT `fk_scores_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_scores_empire` FOREIGN KEY (`empire_id`) REFERENCES `empires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;