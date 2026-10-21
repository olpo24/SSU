-- ShortURL Manager v8: login rate limiting. Run once on the existing database.
CREATE TABLE admin_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    username_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_login_attempt_ip_time (ip_hash, created_at),
    KEY idx_login_attempt_ip_user_time (ip_hash, username_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
