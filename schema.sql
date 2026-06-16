-- Mass Open: database schema for the email signup list.
--
-- Create the database and table, then a least-privilege app user:
--
--   mysql -u root -p < schema.sql
--
-- Adjust the user/password/grant to match db_config.php (or its env vars).

CREATE DATABASE IF NOT EXISTS massopen
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE massopen;

CREATE TABLE IF NOT EXISTS subscribers (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(254) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address  VARCHAR(45) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscribers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Least-privilege application user (edit credentials as needed):
-- CREATE USER IF NOT EXISTS 'massopen'@'localhost' IDENTIFIED BY 'super-secret';
-- GRANT SELECT, INSERT ON massopen.subscribers TO 'massopen'@'localhost';
-- FLUSH PRIVILEGES;
