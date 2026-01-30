-- activity_first_metrics: тип Activity, сделки, полный результат
-- Факт: задача (task_id), сделки куда помещаем (deal_ids), тип (Обложка/Согласованный бланк), полный результат (получилось или нет)

ALTER TABLE activity_first_metrics ADD COLUMN activity_type TEXT DEFAULT '';
ALTER TABLE activity_first_metrics ADD COLUMN deal_ids TEXT DEFAULT '';
ALTER TABLE activity_first_metrics ADD COLUMN result_full TEXT;

-- activity_type: 'cover' | 'approved_form' | ''
-- deal_ids: через запятую, например "12147,13249"
-- result_full: JSON с полным результатом (taskAttach, dealUpdates)
