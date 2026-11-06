USE internal_tickets;

ALTER TABLE ticket_history
  MODIFY details LONGTEXT NOT NULL;
