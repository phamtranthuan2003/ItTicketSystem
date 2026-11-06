USE internal_tickets;

-- Chuyển toàn bộ ticket có loại 'accounting' sang 'pc'
UPDATE tickets
SET type = 'pc'
WHERE type = 'accounting';

-- Cập nhật cấu trúc bảng tickets, loại bỏ giá trị 'accounting' khỏi ENUM
ALTER TABLE tickets
  MODIFY type ENUM('it', 'hr', 'admin', 'pc', 'laptop', 'printer', 'network', 'meeting_room', 'other')
  NOT NULL DEFAULT 'pc';
