-- ============================================================
-- WIP & BOM Monitoring System - Database Schema
-- MySQL 5.7+ / 8.x
-- ============================================================

CREATE DATABASE IF NOT EXISTS `wip_sto`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE `wip_sto`;

-- ------------------------------------------------------------
-- Table: users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT(11) NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(50)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `full_name`  VARCHAR(100) NOT NULL,
  `email`      VARCHAR(100) DEFAULT NULL,
  `role`       ENUM('admin','user') NOT NULL DEFAULT 'user',
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: bom  (Master Bill of Material)
-- part_number = component tanpa akhiran "-00"
-- model diambil dari nama sheet excel (opsional / boleh kosong)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bom` (
  `id`                   BIGINT(20) NOT NULL AUTO_INCREMENT,
  `model`                VARCHAR(30)  DEFAULT NULL,
  `material`             VARCHAR(50)  DEFAULT NULL,
  `suffix`               VARCHAR(80)  DEFAULT NULL,
  `component`            VARCHAR(60)  DEFAULT NULL,
  `part_number`          VARCHAR(60)  DEFAULT NULL,
  `material_description` VARCHAR(255) DEFAULT NULL,
  `qty`                  DECIMAL(14,3) NOT NULL DEFAULT 0,
  `uom`                  VARCHAR(10)  DEFAULT NULL,
  `shop_code`            VARCHAR(30)  DEFAULT NULL,
  `created_at`           DATETIME DEFAULT NULL,
  `updated_at`           DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bom_material` (`material`),
  KEY `idx_bom_component` (`component`),
  KEY `idx_bom_part_number` (`part_number`),
  KEY `idx_bom_shop_code` (`shop_code`),
  KEY `idx_bom_model` (`model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Table: bom_upload_log  (riwayat upload excel)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bom_upload_log` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `file_name`     VARCHAR(255) NOT NULL,
  `mode`          ENUM('append','replace') NOT NULL DEFAULT 'append',
  `total_rows`    INT(11) NOT NULL DEFAULT 0,
  `inserted_rows` INT(11) NOT NULL DEFAULT 0,
  `skipped_rows`  INT(11) NOT NULL DEFAULT 0,
  `status`        ENUM('success','failed') NOT NULL DEFAULT 'success',
  `message`       TEXT,
  `user_id`       INT(11) DEFAULT NULL,
  `created_at`    DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Default account : admin / admin123
-- ------------------------------------------------------------
INSERT INTO `users` (`username`,`password`,`full_name`,`email`,`role`,`is_active`,`created_at`)
SELECT 'admin','$2y$12$j8joswPkZ0vTc9GQxSK4YuBfFYETzwpVa9X3t.k3gj5smJLAvWNqi','Administrator','admin@local','admin',1,NOW()
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');
