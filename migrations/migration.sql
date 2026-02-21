SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS blocks;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS conversation_states;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    telegram_user_id BIGINT NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    username VARCHAR(255) NULL,
    public_slug VARCHAR(32) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    settings_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_telegram_user_id (telegram_user_id),
    UNIQUE KEY uq_users_public_slug (public_slug),
    KEY idx_users_active_created (is_active, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_states (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    telegram_user_id BIGINT NOT NULL,
    state_name VARCHAR(64) NOT NULL,
    payload_json JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversation_states_telegram_user_id (telegram_user_id),
    KEY idx_conversation_states_state_name (state_name),
    KEY idx_conversation_states_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id BIGINT UNSIGNED NOT NULL,
    sender_telegram_user_id BIGINT NOT NULL,
    thread_id CHAR(24) NOT NULL,
    message_type ENUM('text', 'photo') NOT NULL,
    text TEXT NULL,
    media_file_id VARCHAR(255) NULL,
    content_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_messages_thread_id (thread_id),
    KEY idx_messages_target_created (target_user_id, created_at),
    KEY idx_messages_sender_created (sender_telegram_user_id, created_at),
    KEY idx_messages_target_sender_created (target_user_id, sender_telegram_user_id, created_at),
    KEY idx_messages_content_hash_created (content_hash, created_at),
    KEY idx_messages_deleted_target_created (is_deleted, target_user_id, created_at),
    CONSTRAINT fk_messages_target_user
        FOREIGN KEY (target_user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id BIGINT UNSIGNED NOT NULL,
    blocked_sender_telegram_user_id BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blocks_target_sender (target_user_id, blocked_sender_telegram_user_id),
    KEY idx_blocks_sender (blocked_sender_telegram_user_id),
    KEY idx_blocks_target_created (target_user_id, created_at),
    CONSTRAINT fk_blocks_target_user
        FOREIGN KEY (target_user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id BIGINT UNSIGNED NOT NULL,
    reporter_user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reports_message_id (message_id),
    KEY idx_reports_reporter_user_id (reporter_user_id),
    KEY idx_reports_created_at (created_at),
    CONSTRAINT fk_reports_message
        FOREIGN KEY (message_id) REFERENCES messages (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_reports_reporter_user
        FOREIGN KEY (reporter_user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

