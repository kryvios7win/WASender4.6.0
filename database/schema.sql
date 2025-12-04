-- Banco de dados WASender

CREATE DATABASE IF NOT EXISTS `u695379688_mysql` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `u695379688_mysql`;

-- Tabela de sessões WhatsApp
CREATE TABLE IF NOT EXISTS `wasender_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `session_id` VARCHAR(100) NOT NULL UNIQUE,
    `status` ENUM('waiting_qr', 'qr_ready', 'connected', 'disconnected', 'stopped', 'error') DEFAULT 'waiting_qr',
    `qr_code` TEXT,
    `phone_number` VARCHAR(50),
    `chats_count` INT DEFAULT 0,
    `connected_at` DATETIME,
    `last_active` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de chats
CREATE TABLE IF NOT EXISTS `wasender_chats` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `session_id` VARCHAR(100) NOT NULL,
    `chat_id` VARCHAR(100) NOT NULL,
    `name` VARCHAR(200),
    `is_group` BOOLEAN DEFAULT 0,
    `last_message` TEXT,
    `last_message_time` BIGINT,
    `unread_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_chat` (`user_id`, `session_id`, `chat_id`),
    INDEX `idx_user_session` (`user_id`, `session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de mensagens
CREATE TABLE IF NOT EXISTS `wasender_chat_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `session_id` VARCHAR(100) NOT NULL,
    `chat_id` VARCHAR(100) NOT NULL,
    `message_id` VARCHAR(100) NOT NULL,
    `from_me` BOOLEAN DEFAULT 0,
    `body` TEXT,
    `media_url` VARCHAR(500),
    `media_type` VARCHAR(50),
    `timestamp` BIGINT,
    `status` ENUM('sent', 'delivered', 'read', 'failed') DEFAULT 'sent',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_message` (`user_id`, `session_id`, `message_id`),
    INDEX `idx_user_session_chat` (`user_id`, `session_id`, `chat_id`),
    INDEX `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de mensagens enviadas pelo sistema
CREATE TABLE IF NOT EXISTS `wasender_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `message` TEXT NOT NULL,
    `status` ENUM('pending', 'sending', 'sent', 'failed') DEFAULT 'pending',
    `session_id` VARCHAR(100),
    `sent_at` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de contatos
CREATE TABLE IF NOT EXISTS `wasender_contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(100),
    `contact_group` VARCHAR(50) DEFAULT 'default',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_contact` (`user_id`, `phone`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de configurações
CREATE TABLE IF NOT EXISTS `wasender_config` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `config_key` VARCHAR(50) NOT NULL,
    `config_value` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_config` (`user_id`, `config_key`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de logs
CREATE TABLE IF NOT EXISTS `wasender_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `log_type` VARCHAR(50) NOT NULL,
    `log_message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de mensagens agendadas
CREATE TABLE IF NOT EXISTS `wasender_scheduled` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `message` TEXT NOT NULL,
    `schedule_time` DATETIME NOT NULL,
    `status` ENUM('scheduled', 'sent', 'cancelled') DEFAULT 'scheduled',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_schedule` (`schedule_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações padrão
INSERT INTO `wasender_config` (`user_id`, `config_key`, `config_value`) VALUES
('admin', 'message_delay', '30'),
('admin', 'max_messages_per_hour', '50'),
('admin', 'auto_reply_enabled', '0'),
('admin', 'proxy_enabled', '1'),
('admin', 'session_timeout', '3600')
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`);