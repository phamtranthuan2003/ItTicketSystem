USE internal_tickets;

ALTER TABLE admins
  MODIFY role ENUM('super_admin', 'admin', 'staff') NOT NULL DEFAULT 'staff';

UPDATE admins
SET role = CASE
  WHEN LOWER(username) = 'thuanpt' THEN 'super_admin'
  WHEN role = 'super_admin' THEN 'admin'
  ELSE role
END
WHERE LOWER(username) = 'thuanpt' OR role = 'super_admin';
