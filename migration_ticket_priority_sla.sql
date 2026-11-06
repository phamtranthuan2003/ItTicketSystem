USE internal_tickets;

ALTER TABLE tickets
  ADD COLUMN priority_score SMALLINT UNSIGNED NULL AFTER priority,
  ADD COLUMN impact_scope ENUM('individual', 'department', 'company') NOT NULL DEFAULT 'individual' AFTER priority_score,
  ADD COLUMN work_ability ENUM('can_work', 'partial', 'cannot_work') NOT NULL DEFAULT 'can_work' AFTER impact_scope,
  ADD COLUMN response_due_at DATETIME NULL AFTER work_ability,
  ADD COLUMN resolution_due_at DATETIME NULL AFTER response_due_at,
  ADD COLUMN response_sla_escalated_at DATETIME NULL AFTER resolution_due_at,
  ADD COLUMN resolution_sla_escalated_at DATETIME NULL AFTER response_sla_escalated_at,
  ADD KEY idx_tickets_priority_created (priority, created_at),
  ADD KEY idx_tickets_sla (status, response_due_at, resolution_due_at);

UPDATE tickets
SET response_due_at = TIMESTAMPADD(
      MINUTE,
      CASE priority WHEN 'high' THEN 15 WHEN 'medium' THEN 30 ELSE 60 END,
      created_at
    ),
    resolution_due_at = TIMESTAMPADD(
      MINUTE,
      CASE priority WHEN 'high' THEN 120 WHEN 'medium' THEN 240 ELSE 360 END,
      created_at
    );
