CREATE DATABASE IF NOT EXISTS internal_tickets
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE internal_tickets;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin', 'admin', 'staff') NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username),
  KEY idx_admins_active_role (is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_code VARCHAR(24) NULL,
  full_name VARCHAR(150) NOT NULL,
  department VARCHAR(150) NOT NULL,
  contact VARCHAR(190) NOT NULL,
  email VARCHAR(190) NULL,
  type ENUM('it', 'hr', 'admin', 'pc', 'laptop', 'printer', 'network', 'meeting_room', 'other') NOT NULL DEFAULT 'pc',
  priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
  priority_score SMALLINT UNSIGNED NULL,
  impact_scope ENUM('individual', 'department', 'company') NOT NULL DEFAULT 'individual',
  work_ability ENUM('can_work', 'partial', 'cannot_work') NOT NULL DEFAULT 'can_work',
  response_due_at DATETIME NULL,
  resolution_due_at DATETIME NULL,
  response_sla_escalated_at DATETIME NULL,
  resolution_sla_escalated_at DATETIME NULL,
  last_reminded_at DATETIME NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NOT NULL,
  status ENUM('new', 'in_progress', 'completed', 'rejected') NOT NULL DEFAULT 'new',
  admin_note TEXT NULL,
  assignee_id INT UNSIGNED NULL,
  attachment_name VARCHAR(100) NULL,
  attachment_original_name VARCHAR(255) NULL,
  ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_code (ticket_code),
  KEY idx_tickets_status_created (status, created_at),
  KEY idx_tickets_priority_created (priority, created_at),
  KEY idx_tickets_sla (status, response_due_at, resolution_due_at),
  KEY idx_tickets_type_priority (type, priority),
  KEY idx_tickets_assignee (assignee_id),
  KEY idx_tickets_created (created_at),
  CONSTRAINT fk_tickets_assignee FOREIGN KEY (assignee_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL,
  admin_id INT UNSIGNED NULL,
  action VARCHAR(150) NOT NULL,
  details TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ticket_history_ticket (ticket_id, id),
  CONSTRAINT fk_history_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_history_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL,
  stored_name VARCHAR(100) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_attachments_stored_name (stored_name),
  KEY idx_ticket_attachments_ticket (ticket_id, id),
  CONSTRAINT fk_ticket_attachments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  ip_hash CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
  login_key CHAR(64) NOT NULL,
  failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  PRIMARY KEY (login_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admins (username, password_hash, role)
VALUES ('admin', '$2y$10$7D6Qd.uPalbfRHER4ITWN.u8ysWB0RRZoufc47S2wXxfLMPQLWLB6', 'admin')
ON DUPLICATE KEY UPDATE username = VALUES(username);
