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

export async function fetchUiState(signal) {
  const url = new URL('/api/ui-state.php', window.location.origin);
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

  const payloadText = await response.text();
  try {
    return JSON.parse(payloadText);
  } catch (error) {
    console.error('UI state JSON parse failed', error);
    throw new Error('invalid_json');
  }
}
