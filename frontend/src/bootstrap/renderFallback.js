const DEFAULT_TITLE = 'Приложение недоступно';

export const renderFallback = (root, message) => {
  if (!root) {
    return;
  }

  root.innerHTML = '';

  const wrapper = document.createElement('div');
  wrapper.className = 'app-shell';

  const card = document.createElement('div');
  card.className = 'app-card app-fallback';

  const title = document.createElement('div');
  title.className = 'app-greeting';
  title.textContent = DEFAULT_TITLE;

  const description = document.createElement('div');
  description.className = 'app-context app-context-message';
  description.textContent = message || 'Не удалось инициализировать Bitrix24 SDK.';

  card.appendChild(title);
  card.appendChild(description);
  wrapper.appendChild(card);
  root.appendChild(wrapper);
};
