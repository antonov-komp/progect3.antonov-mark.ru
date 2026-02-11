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
