import { getRequestContext } from '@/services/requestContext';

const DEFAULT_HEADERS = {
  Accept: 'application/json',
};

const appendQueryParams = (url, context) => {
  Object.entries(context).forEach(([key, value]) => {
    if (value === null || value === undefined) {
      return;
    }
    const stringValue = typeof value === 'string' ? value : String(value);
    if (stringValue.trim() === '') {
      return;
    }
    url.searchParams.set(key, stringValue);
  });
};

const parseJsonResponse = async (response) => {
  const payloadText = await response.text();
  try {
    return JSON.parse(payloadText);
  } catch (error) {
    console.error('User fields JSON parse failed', error);
    throw new Error('invalid_json');
  }
};

/**
 * Загрузка разделов (включая смарт-процессы).
 *
 * @returns {Promise<{sections: Array, smart_types: Array}>}
 */
export async function fetchSections(signal) {
  const url = new URL('/api/user-fields.php', window.location.origin);
  url.searchParams.set('section', 'sections');

  const context = await getRequestContext();
  appendQueryParams(url, context);

  const response = await fetch(url.toString(), {
    method: 'GET',
    headers: DEFAULT_HEADERS,
    signal,
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }

  return parseJsonResponse(response);
}

/**
 * Загрузка полей по разделу.
 *
 * @param {string} section - deal|lead|contact|company|smart
 * @param {string|null} entityTypeId - для section=smart
 * @param {string|null} typeId - id смарт-процесса из crm.type.list (если отличается от entityTypeId)
 * @returns {Promise<{user_fields: Array, total: number}>}
 */
export async function fetchFieldsBySection(section, entityTypeId = null, typeId = null, signal) {
  const url = new URL('/api/user-fields.php', window.location.origin);
  url.searchParams.set('section', section);

  if (section === 'smart' && (entityTypeId || typeId)) {
    if (entityTypeId) {
      url.searchParams.set('entityTypeId', entityTypeId);
    }
    if (typeId) {
      url.searchParams.set('typeId', typeId);
    }
  }

  const context = await getRequestContext();
  appendQueryParams(url, context);

  const response = await fetch(url.toString(), {
    method: 'GET',
    headers: DEFAULT_HEADERS,
    signal,
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }

  return parseJsonResponse(response);
}

/**
 * Создание пользовательского поля.
 *
 * @param {string} section - deal|lead|contact|company|smart
 * @param {object} fields - поля (USER_TYPE_ID, FIELD_NAME, EDIT_FORM_LABEL и др.)
 * @param {string|null} entityTypeId - для section=smart
 * @param {string|null} typeId - для section=smart
 * @param {AbortSignal} signal - опционально
 * @returns {Promise<{field_id: number}>}
 */
export async function createUserField(
  section,
  fields,
  entityTypeId = null,
  typeId = null,
  signal,
) {
  const url = new URL('/api/user-fields.php', window.location.origin);

  const context = await getRequestContext();
  appendQueryParams(url, context);

  const body = {
    section,
    fields,
  };
  if (section === 'smart' && (entityTypeId || typeId)) {
    if (entityTypeId) body.entityTypeId = entityTypeId;
    if (typeId) body.typeId = typeId;
  }

  const response = await fetch(url.toString(), {
    method: 'POST',
    headers: {
      ...DEFAULT_HEADERS,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
    signal,
  });

  const data = await parseJsonResponse(response);

  if (data.status === 'error') {
    const err = new Error(data.error_message || 'Ошибка создания поля');
    err.response = data;
    throw err;
  }

  return { field_id: data.field_id };
}

/**
 * Создание поля-встройки (кастомный тип с handler iframe).
 *
 * @param {string} section - deal|lead|contact|company
 * @param {object} params - handler_url, user_type_id, label, field_name?, description?
 * @param {AbortSignal} signal - опционально
 * @returns {Promise<{field_id: number}>}
 */
export async function createEmbedField(section, params, signal) {
  const url = new URL('/api/user-fields.php', window.location.origin);

  const context = await getRequestContext();
  appendQueryParams(url, context);

  const body = {
    section,
    mode: 'embed',
    label: params.label,
    field_name: params.field_name ?? '',
    description: params.description ?? '',
  };
  if (params.embed_type_id) {
    body.embed_type_id = params.embed_type_id;
  } else {
    body.handler_url = params.handler_url;
    body.user_type_id = params.user_type_id;
  }
  if (section === 'smart') {
    if (params.entityTypeId) body.entityTypeId = params.entityTypeId;
    if (params.typeId) body.typeId = params.typeId;
  }

  const response = await fetch(url.toString(), {
    method: 'POST',
    headers: {
      ...DEFAULT_HEADERS,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
    signal,
  });

  const data = await parseJsonResponse(response);

  if (data.status === 'error') {
    const err = new Error(data.error_message || 'Ошибка создания поля-встройки');
    err.response = data;
    throw err;
  }

  return { field_id: data.field_id };
}

/**
 * Получение настроек поля-встройки.
 *
 * @param {string} section - deal|lead|contact|company
 * @param {number} fieldId - ID поля
 * @param {AbortSignal} signal - опционально
 * @returns {Promise<{settings: object}>}
 */
export async function fetchEmbedFieldSettings(section, fieldId, signal) {
  const url = new URL('/api/user-fields.php', window.location.origin);
  url.searchParams.set('section', section);
  url.searchParams.set('field_id', String(fieldId));
  url.searchParams.set('action', 'embed_settings');

  const context = await getRequestContext();
  appendQueryParams(url, context);

  const response = await fetch(url.toString(), { method: 'GET', headers: DEFAULT_HEADERS, signal });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  const data = await parseJsonResponse(response);
  if (data.status === 'error') throw new Error(data.error_message || 'Ошибка загрузки настроек');
  return { settings: data.settings || {} };
}

/**
 * Сохранение настроек поля-встройки.
 *
 * @param {string} section
 * @param {number} fieldId
 * @param {object} settings
 * @param {string} [fieldName] — FIELD_NAME для handler (UF_CRM_DEAL_XXX)
 * @param {AbortSignal} [signal]
 * @param {{ entityTypeId?: string, typeId?: string }} [options] — для section=smart
 * @returns {Promise<void>}
 */
export async function saveEmbedFieldSettings(section, fieldId, settings, fieldName, signal, options) {
  const url = new URL('/api/user-fields.php', window.location.origin);
  const context = await getRequestContext();
  appendQueryParams(url, context);

  const body = {
    section,
    field_id: fieldId,
    action: 'embed_settings',
    settings,
  };
  if (fieldName) {
    body.field_name = fieldName;
  }
  if (section === 'smart' && options) {
    if (options.entityTypeId) body.entityTypeId = options.entityTypeId;
    if (options.typeId) body.typeId = options.typeId;
  }

  const response = await fetch(url.toString(), {
    method: 'POST',
    headers: { ...DEFAULT_HEADERS, 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal,
  });
  const data = await parseJsonResponse(response);
  if (data.status === 'error') throw new Error(data.error_message || 'Ошибка сохранения настроек');
}
