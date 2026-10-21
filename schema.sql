CREATE TABLE projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    api_key_hash CHAR(64) NOT NULL,
    api_key_hint VARCHAR(12) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_projects_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    slug VARCHAR(100) NOT NULL,
    title VARCHAR(190) DEFAULT NULL,
    target_url TEXT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    clicks INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_project_slug (project_id, slug),
    KEY idx_links_project (project_id),
    CONSTRAINT fk_links_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clicks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id BIGINT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    ip_hash CHAR(64) DEFAULT NULL,
    user_agent VARCHAR(1000) DEFAULT NULL,
    referrer VARCHAR(1000) DEFAULT NULL,
    country_code CHAR(2) DEFAULT NULL,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    bot_reason VARCHAR(120) DEFAULT NULL,
    counted TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_clicks_link_date (link_id, created_at),
    KEY idx_clicks_dedupe (link_id, ip_hash, counted, created_at),
    KEY idx_clicks_project_date (project_id, created_at),
    KEY idx_clicks_bot (is_bot, created_at),
    CONSTRAINT fk_clicks_link FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE,
    CONSTRAINT fk_clicks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    last_login DATETIME DEFAULT NULL,
    UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE admin_login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    username_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_login_attempt_ip_time (ip_hash, created_at),
    KEY idx_login_attempt_ip_user_time (ip_hash, username_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
