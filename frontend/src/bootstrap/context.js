import { getRequestContext } from '@/services/requestContext';

export const resolveRequestContext = async () => {
  try {
    const context = await getRequestContext();

    if (!window.APP_REQUEST_CONTEXT || typeof window.APP_REQUEST_CONTEXT !== 'object') {
      window.APP_REQUEST_CONTEXT = {};
    }

    window.APP_REQUEST_CONTEXT = {
      ...window.APP_REQUEST_CONTEXT,
      ...context,
    };

    return window.APP_REQUEST_CONTEXT;
  } catch (error) {
    console.warn('Request context resolve failed', error);
    return {};
  }
};
