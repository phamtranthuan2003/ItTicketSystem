USE internal_tickets;

ALTER TABLE admins
  MODIFY role ENUM('super_admin', 'admin', 'staff') NOT NULL DEFAULT 'staff';

UPDATE admins
SET role = 'super_admin'
WHERE username = 'thuanpt';
