<?php

/**
 * Конфиг доступа к модульным плиткам на стартовой странице.
 *
 * Как работает:
 * - Каждый элемент в "modules" описывает модуль, его плитку и правила доступа.
 * - Плитка показывается только если:
 *   - enabled = true
 *   - И пользователь есть в allowed_users ИЛИ его отдел есть в allowed_departments
 * - Если оба списка пустые, модуль скрыт для всех (кроме супер‑админа).
 * - Супер‑админ видит все модули независимо от списков.
 * - route используется для перехода на страницу модуля.
 *
 * Поля:
 * - key: уникальный идентификатор модуля
 * - title: заголовок плитки
 * - subtitle: подзаголовок плитки
 * - icon: код иконки (используется во фронте)
 * - route: путь модуля (например, /modules/reports)
 * - enabled: включен/выключен модуль
 * - allowed_users: список ID пользователей с доступом
 * - allowed_departments: список ID отделов с доступом
 */

return array (
  'modules' =>
  array (
    0 =>
    array (
      'key' => 'module_reports',
      'title' => 'Отчеты',
      'subtitle' => 'Сводная аналитика и показатели',
      'icon' => 'chart',
      'route' => '/modules/reports',
      'enabled' => true,
      'allowed_users' =>
      array (
      ),
      'allowed_departments' =>
      array (
      ),
    ),
    1 =>
    array (
      'key' => 'module_requests',
      'title' => 'Заявки',
      'subtitle' => 'Создание и контроль обращений',
      'icon' => 'inbox',
      'route' => '/modules/requests',
      'enabled' => true,
      'allowed_users' =>
      array (
      ),
      'allowed_departments' =>
      array (
      ),
    ),
    2 =>
    array (
      'key' => 'module_documents',
      'title' => 'Документы',
      'subtitle' => 'Шаблоны, согласования, версии',
      'icon' => 'file',
      'route' => '/modules/documents',
      'enabled' => true,
      'allowed_users' =>
      array (
      ),
      'allowed_departments' =>
      array (
      ),
    ),
    3 =>
    array (
      'key' => 'module_team',
      'title' => 'Команда',
      'subtitle' => 'Сотрудники и роли',
      'icon' => 'users',
      'route' => '/modules/team',
      'enabled' => true,
      'allowed_users' =>
      array (
      ),
      'allowed_departments' =>
      array (
      ),
    ),
    4 =>
    array (
      'key' => 'module_settings',
      'title' => 'Настройки',
      'subtitle' => 'Параметры и интеграции',
      'icon' => 'settings',
      'route' => '/modules/settings',
      'enabled' => true,
      'allowed_users' =>
      array (
      ),
      'allowed_departments' =>
      array (
      ),
    ),
  ),
);
