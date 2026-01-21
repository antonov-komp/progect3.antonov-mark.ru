import { getRequestContext } from '@/services/requestContext';

const DEFAULT_HEADERS = {
  Accept: 'application/json',
  'Content-Type': 'application/json',
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
    console.error('Access config JSON parse failed', error);
    throw new Error('invalid_json');
  }
};

export async function fetchAccessConfig(signal) {
  const url = new URL('/api/access-config.php', window.location.origin);
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

export async function updateAccessConfig(payload, signal) {
  const url = new URL('/api/access-config.php', window.location.origin);
  const context = await getRequestContext();
  appendQueryParams(url, context);

  const response = await fetch(url.toString(), {
    method: 'POST',
    headers: DEFAULT_HEADERS,
    body: JSON.stringify(payload),
    signal,
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }

  return parseJsonResponse(response);
}

export async function fetchAccessDirectory(signal) {
  const url = new URL('/api/access-directory.php', window.location.origin);
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

export async function fetchAccessModulesConfig(signal) {
  const url = new URL('/api/access-modules.php', window.location.origin);
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

export async function updateAccessModulesConfig(payload, signal) {
  const url = new URL('/api/access-modules.php', window.location.origin);
  const context = await getRequestContext();
  appendQueryParams(url, context);

  const response = await fetch(url.toString(), {
    method: 'POST',
    headers: DEFAULT_HEADERS,
    body: JSON.stringify(payload),
    signal,
  });

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }

  return parseJsonResponse(response);
}
