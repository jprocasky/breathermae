-- Tag snapshot table for change detection.
-- Run once against the WordPress database (adjust prefix if not wp_).

CREATE TABLE IF NOT EXISTS uls_bm_tag_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    user_email VARCHAR(190) NOT NULL,
    tags_json JSON NOT NULL,
    tags_hash CHAR(64) NOT NULL,
    tag_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_id (user_id),
    KEY idx_email (user_email),
    KEY idx_hash (tags_hash),
    KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
