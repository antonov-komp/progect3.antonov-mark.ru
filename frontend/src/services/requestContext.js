const CONTEXT_KEYS = [
  'AUTH_ID',
  'DOMAIN',
  'member_id',
  'PLACEMENT',
  'PLACEMENT_OPTIONS',
  'IFRAME',
  'B24_FRAME',
];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const sanitizeValue = (value) => {
  if (value === null || value === undefined) {
    return '';
  }

  const stringValue = typeof value === 'string' ? value : String(value);
  return stringValue.replace(/[\r\n]+/g, ' ').trim();
};

const readBaseContext = () => {
  const source =
    window.APP_REQUEST_CONTEXT && typeof window.APP_REQUEST_CONTEXT === 'object'
      ? window.APP_REQUEST_CONTEXT
      : {};

  return CONTEXT_KEYS.reduce((acc, key) => {
    const value = sanitizeValue(source[key]);
    if (value !== '') {
      acc[key] = value;
    }
    return acc;
  }, {});
};

const resolveBxAuth = async () => {
  if (!window.BX24 || typeof window.BX24.getAuth !== 'function') {
    return null;
  }

  for (let attempt = 0; attempt < 5; attempt += 1) {
    try {
      const auth = window.BX24.getAuth();
      if (auth && typeof auth === 'object' && auth.access_token) {
        return auth;
      }
    } catch (error) {
      console.warn('BX24 auth attempt failed', error);
    }

    await sleep(200);
  }

  return null;
};

export async function getRequestContext() {
  const context = readBaseContext();
  const auth = await resolveBxAuth();

  if (auth) {
    const authId = sanitizeValue(auth.access_token);
    const domain = sanitizeValue(auth.domain);
    const memberId = sanitizeValue(auth.member_id);

    if (authId) {
      context.AUTH_ID = authId;
    }
    if (domain) {
      context.DOMAIN = domain;
    }
    if (memberId) {
      context.member_id = memberId;
    }
  }

  return context;
}
