USE internal_tickets;

ALTER TABLE tickets
  ADD COLUMN last_reminded_at DATETIME NULL AFTER priority;
