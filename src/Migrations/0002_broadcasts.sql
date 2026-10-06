SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS broadcasts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending','running','done','canceled') NOT NULL DEFAULT 'pending',
    total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_user_id_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    CONSTRAINT fk_broadcasts_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    INDEX idx_broadcasts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
