const SDK_UNAVAILABLE_MESSAGE =
  'Bitrix24 SDK недоступен. Откройте приложение внутри портала Bitrix24.';

const waitForDomReady = () =>
  new Promise((resolve) => {
    if (window.BX && typeof window.BX.ready === 'function') {
      window.BX.ready(resolve);
      return;
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', resolve, { once: true });
      return;
    }

    resolve();
  });

export const initBitrixSdk = async () => {
  await waitForDomReady();

  if (!window.BX24 || typeof window.BX24.init !== 'function') {
    return {
      available: false,
      message: SDK_UNAVAILABLE_MESSAGE,
    };
  }

  return new Promise((resolve) => {
    try {
      window.BX24.init(() => {
        resolve({ available: true, message: '' });
      });
    } catch (error) {
      console.warn('BX24 init failed', error);
      resolve({
        available: false,
        message: SDK_UNAVAILABLE_MESSAGE,
      });
    }
  });
};
