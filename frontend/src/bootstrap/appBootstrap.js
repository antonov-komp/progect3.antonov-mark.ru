import { createApp } from 'vue';
import { createPinia } from 'pinia';
import AppView from '@/views/AppView.vue';
import { router } from '@/router';
import { initBitrixSdk } from '@/bootstrap/bitrixSdk';
import { resolveRequestContext } from '@/bootstrap/context';
import { renderFallback } from '@/bootstrap/renderFallback';

const mountVueApp = (root) => {
  const app = createApp(AppView);
  app.use(createPinia());
  app.use(router);
  app.mount(root);
};

const isEmbeddedContext = (context) =>
  Boolean(
    context &&
      (context.PLACEMENT ||
        context.PLACEMENT_OPTIONS ||
        context.IFRAME ||
        context.B24_FRAME),
  );

export const bootstrapApp = async () => {
  const root = document.getElementById('app');
  if (!root) {
    console.warn('Root element #app not found');
    return;
  }

  await resolveRequestContext();
  const context =
    window.APP_REQUEST_CONTEXT && typeof window.APP_REQUEST_CONTEXT === 'object'
      ? window.APP_REQUEST_CONTEXT
      : {};

  if (isEmbeddedContext(context)) {
    const sdkState = await initBitrixSdk();
    if (!sdkState.available) {
      renderFallback(root, sdkState.message);
      return;
    }
  }

  mountVueApp(root);
};
