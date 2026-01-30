-- activity_first_metrics: автор комментария (ID, Имя Фамилия), текст комментария дословно
ALTER TABLE activity_first_metrics ADD COLUMN author_id TEXT DEFAULT '';
ALTER TABLE activity_first_metrics ADD COLUMN author_name TEXT DEFAULT '';
ALTER TABLE activity_first_metrics ADD COLUMN comment_text TEXT DEFAULT '';
