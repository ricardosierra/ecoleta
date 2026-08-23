-- 005_login_throttle.sql — contadores de tentativa de login.
--
-- Base do rate limit de public/api/rate_limit.php: uma linha por bucket, que é
-- o hash do par (login, IP) ou do IP sozinho. A UNIQUE em `bucket` é o que
-- permite o INSERT ... ON DUPLICATE KEY UPDATE somar falhas em uma única ida
-- ao banco.

CREATE TABLE IF NOT EXISTS login_throttle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bucket CHAR(64) NOT NULL,
    scope VARCHAR(16) NOT NULL,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    first_failure_at DATETIME NOT NULL,
    last_failure_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    UNIQUE KEY uniq_login_throttle_bucket (bucket),
    INDEX idx_login_throttle_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
