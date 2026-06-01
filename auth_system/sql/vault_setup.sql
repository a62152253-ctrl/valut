-- VaultAuth — Password Manager Schema
-- Run once in phpMyAdmin or mysql CLI

CREATE TABLE IF NOT EXISTS `vault_salt` (
  `user_id`           INT(11)      NOT NULL,
  `salt`              VARCHAR(64)  NOT NULL COMMENT '32 random bytes hex — used for PBKDF2',
  `hint`              VARCHAR(200) DEFAULT NULL,
  `verification_blob` TEXT         DEFAULT NULL COMMENT 'AES-GCM(key, "vault:ok") to verify master pwd',
  `verification_iv`   VARCHAR(32)  DEFAULT NULL,
  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vault_folders` (
  `id`         INT(11)     NOT NULL AUTO_INCREMENT,
  `user_id`    INT(11)     NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `color`      VARCHAR(7)  NOT NULL DEFAULT '#5865f2',
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vf_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vault_entries` (
  `id`             INT(11)    NOT NULL AUTO_INCREMENT,
  `user_id`        INT(11)    NOT NULL,
  `uuid`           VARCHAR(36) NOT NULL,
  `folder_id`      INT(11)    DEFAULT NULL,
  `type`           ENUM('login','note','card','identity') NOT NULL DEFAULT 'login',
  `encrypted_data` MEDIUMTEXT NOT NULL COMMENT 'AES-256-GCM ciphertext, base64',
  `iv`             VARCHAR(32) NOT NULL COMMENT '12-byte GCM IV, base64',
  `favorite`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ve_uuid` (`uuid`),
  KEY `idx_ve_user`   (`user_id`),
  KEY `idx_ve_folder` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vault_history` (
  `id`             INT(11)    NOT NULL AUTO_INCREMENT,
  `entry_uuid`     VARCHAR(36) NOT NULL,
  `user_id`        INT(11)    NOT NULL,
  `encrypted_data` MEDIUMTEXT NOT NULL,
  `iv`             VARCHAR(32) NOT NULL,
  `changed_at`     TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vh_entry` (`entry_uuid`),
  KEY `idx_vh_user`  (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
