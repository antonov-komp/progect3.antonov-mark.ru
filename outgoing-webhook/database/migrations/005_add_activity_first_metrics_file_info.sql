-- activity_first_metrics: имя файла с форматом, размер файла
ALTER TABLE activity_first_metrics ADD COLUMN file_name TEXT DEFAULT '';
ALTER TABLE activity_first_metrics ADD COLUMN file_size INTEGER;
