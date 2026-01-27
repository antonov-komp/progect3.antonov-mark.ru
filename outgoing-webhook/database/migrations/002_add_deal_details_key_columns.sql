-- Ключевые поля сделки: стадия (id+title), воронка (id+title), ответственный/кто изменил (id+name)
ALTER TABLE deal_details ADD COLUMN stage_id TEXT;
ALTER TABLE deal_details ADD COLUMN stage_title TEXT;
ALTER TABLE deal_details ADD COLUMN category_id TEXT;
ALTER TABLE deal_details ADD COLUMN category_title TEXT;
ALTER TABLE deal_details ADD COLUMN assigned_by_id TEXT;
ALTER TABLE deal_details ADD COLUMN assigned_by_name TEXT;
ALTER TABLE deal_details ADD COLUMN modify_by_id TEXT;
ALTER TABLE deal_details ADD COLUMN modify_by_name TEXT;
