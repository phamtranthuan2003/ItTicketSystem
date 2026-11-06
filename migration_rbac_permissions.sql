USE internal_tickets;

CREATE TABLE IF NOT EXISTS role_permissions (
  role ENUM('admin', 'staff') NOT NULL,
  permission VARCHAR(80) NOT NULL,
  is_granted TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (role, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO role_permissions (role, permission, is_granted) VALUES
  ('admin', 'tickets.view', 1),
  ('admin', 'tickets.update', 1),
  ('admin', 'tickets.manage', 1),
  ('admin', 'tickets.delete', 1),
  ('admin', 'tickets.export', 1),
  ('admin', 'accounts.view', 1),
  ('admin', 'accounts.create', 1),
  ('admin', 'accounts.manage_password', 1),
  ('admin', 'accounts.delete', 1),
  ('staff', 'tickets.view', 1),
  ('staff', 'tickets.update', 1),
  ('staff', 'tickets.export', 1);
