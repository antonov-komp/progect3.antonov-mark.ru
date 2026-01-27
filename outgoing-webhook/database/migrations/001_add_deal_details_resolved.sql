-- Добавить колонку details_resolved в deal_details (для существующих БД)
ALTER TABLE deal_details ADD COLUMN details_resolved TEXT;
