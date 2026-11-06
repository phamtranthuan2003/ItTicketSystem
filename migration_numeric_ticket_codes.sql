USE internal_tickets;

UPDATE tickets
SET ticket_code = CAST(id AS CHAR)
WHERE ticket_code IS NULL OR ticket_code <> CAST(id AS CHAR);
