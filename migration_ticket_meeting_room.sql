USE internal_tickets;

ALTER TABLE tickets
  MODIFY type ENUM('it', 'hr', 'admin', 'pc', 'laptop', 'printer', 'network', 'meeting_room', 'other')
  NOT NULL DEFAULT 'other';
