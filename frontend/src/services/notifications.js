const DEFAULT_AUTO_HIDE = 4000;

const sanitizeMessage = (message) => {
  if (!message) {
    return '';
  }
  return String(message).replace(/[\r\n]+/g, ' ').trim();
};

const resolveNotify = () =>
  window.BX &&
  window.BX.UI &&
  window.BX.UI.Notification &&
  window.BX.UI.Notification.Center &&
  typeof window.BX.UI.Notification.Center.notify === 'function'
    ? window.BX.UI.Notification.Center.notify
    : null;

const resolveAlert = () =>
  window.BX && window.BX.UI && window.BX.UI.Alert && typeof window.BX.UI.Alert.show === 'function'
    ? window.BX.UI.Alert.show
    : null;

const resolveNotificationStyle = (level) => {
  switch (level) {
    case 'success':
      return 'success';
    case 'warning':
      return 'warning';
    case 'error':
      return 'danger';
    case 'info':
    default:
      return 'info';
  }
};

export function notify(message, level = 'info', options = {}) {
  const content = sanitizeMessage(message);
  if (!content) {
    return;
  }

  const notifyFn = resolveNotify();
  if (notifyFn) {
    notifyFn({
      content,
      autoHideDelay: options.autoHideDelay ?? DEFAULT_AUTO_HIDE,
      position: options.position,
      category: resolveNotificationStyle(level),
    });
    return;
  }

  const alertFn = resolveAlert();
  if (alertFn) {
    alertFn(content);
  }
}

export function notifyError(message, options = {}) {
  notify(message, 'error', options);
}

export function notifySuccess(message, options = {}) {
  notify(message, 'success', options);
}

export function notifyWarning(message, options = {}) {
  notify(message, 'warning', options);
}
