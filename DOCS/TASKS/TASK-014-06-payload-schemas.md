# TASK-014-06: Схемы payload/логов/очереди и политика хранения

Дата: 2026-01-15 16:45 (UTC+3, Брест)
Статус: draft
Приоритет: high
Исполнитель: Backend PHP (Bitrix24 REST)

## Цель
Зафиксировать точные схемы файлов raw/enriched/event.log/queue и правила хранения/ротации.

## Контекст
Нужен единый стандарт, чтобы все сервисы обрабатывали файлы одинаково и безопасно (без утечек токена).

## Схема raw.json
```json
{
  "eventType": "ONCRMDEALADD",
  "receivedAt": "2026-01-15T16:45:00+03:00",
  "ip": "1.2.3.4",
  "payload": {
    "token": "****abcd1234",
    "event": "ONCRMDEALADD",
    "data": {
      "FIELDS": {
        "ID": "123"
      }
    }
  }
}
```

## Схема enriched.json
```json
{
  "eventType": "ONCRMDEALADD",
  "entityType": "deal",
  "entityId": "123",
  "enrichedAt": "2026-01-15T16:45:10+03:00",
  "source": {
    "method": "crm.deal.get",
    "responseTimeMs": 320
  },
  "rawRef": "/outgoing-webhook/logs/ONCRMDEALADD/raw.json",
  "data": {
    "deal": { "ID": "123", "TITLE": "..." },
    "category": { "ID": "1", "NAME": "..." },
    "stage": { "STATUS_ID": "NEW", "NAME": "Новый" }
  }
}
```

## Формат event.log (строка)
```
2026-01-15T16:45:00+03:00 | IP=1.2.3.4 | event=ONCRMDEALADD | entityType=deal | entityId=123
```

## Схема очереди (queue/pending/*.json)
```json
{
  "eventType": "ONCRMDEALADD",
  "entityType": "deal",
  "entityId": "123",
  "rawPath": "/outgoing-webhook/logs/ONCRMDEALADD/raw.json",
  "createdAt": "2026-01-15T16:45:00+03:00",
  "attempt": 0,
  "priority": "normal",
  "source": "outgoing-webhook"
}
```

## Схема ошибки (queue/failed/*.error.json)
```json
{
  "failedAt": "2026-01-15T16:50:00+03:00",
  "reason": "REST timeout",
  "attempt": 3,
  "lastMethod": "crm.deal.get"
}
```

## Политика хранения и ротации
- raw/enriched: хранить 30 дней (если не согласовано иначе).
- event.log: ротация по дням или при достижении 10 MB.
- allowed-events отчёты: 7 дней.
- failed задания: 30 дней.

## Безопасность данных
- token всегда маскируется в raw.
- никакие секреты не пишутся в логи.

## Критерии приёмки
- Схемы согласованы и реализованы в коде.
- Политика хранения утверждена.
