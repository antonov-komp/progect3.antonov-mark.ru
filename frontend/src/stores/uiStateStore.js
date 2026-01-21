import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchUiState } from '@/services/uiStateService';
import { fetchAccessModulesConfig, updateAccessModulesConfig } from '@/services/accessConfigService';
import { notifyError } from '@/services/notifications';

const NEUTRAL_ERROR = 'Не удалось загрузить данные приложения. Попробуйте позже.';
const REQUEST_TIMEOUT = 8000;

const DEFAULT_MODULES = [
  {
    key: 'module_reports',
    title: 'Отчеты',
    subtitle: 'Сводная аналитика и показатели',
    icon: 'chart',
    route: '/modules/reports',
    enabled: true,
    allowed_users: [],
    allowed_departments: [],
  },
  {
    key: 'module_requests',
    title: 'Заявки',
    subtitle: 'Создание и контроль обращений',
    icon: 'inbox',
    route: '/modules/requests',
    enabled: true,
    allowed_users: [],
    allowed_departments: [],
  },
  {
    key: 'module_documents',
    title: 'Документы',
    subtitle: 'Шаблоны, согласования, версии',
    icon: 'file',
    route: '/modules/documents',
    enabled: true,
    allowed_users: [],
    allowed_departments: [],
  },
  {
    key: 'module_team',
    title: 'Команда',
    subtitle: 'Сотрудники и роли',
    icon: 'users',
    route: '/modules/team',
    enabled: true,
    allowed_users: [],
    allowed_departments: [],
  },
  {
    key: 'module_settings',
    title: 'Настройки',
    subtitle: 'Параметры и интеграции',
    icon: 'settings',
    route: '/modules/settings',
    enabled: true,
    allowed_users: [],
    allowed_departments: [],
  },
];

const DEFAULT_STATE = {
  allowed: false,
  deny_message: 'Доступ ограничен.',
  greeting: 'Привет!',
  context_message: '',
  access: {
    context: 'unknown',
    is_embedded: false,
    is_super_admin: false,
    decision: 'deny',
    reason: 'unknown',
  },
  auth: {
    source: 'app_token',
  },
  user: {
    id: '',
    name: '',
    last_name: '',
    is_admin: null,
    department: '',
    department_ids: [],
  },
  status: 'ok',
  error_message: '',
};

const normalizeString = (value, fallback = '') => (typeof value === 'string' ? value : fallback);
const normalizeBool = (value, fallback = false) => (typeof value === 'boolean' ? value : fallback);
const normalizeOptionalBool = (value) => (typeof value === 'boolean' ? value : null);
const normalizeStringArray = (value) =>
  Array.isArray(value) ? value.map((entry) => String(entry)).filter((entry) => entry !== '') : [];

const normalizeState = (payload) => {
  if (!payload || typeof payload !== 'object') {
    return { ...DEFAULT_STATE };
  }

  const access = payload.access && typeof payload.access === 'object' ? payload.access : {};
  const auth = payload.auth && typeof payload.auth === 'object' ? payload.auth : {};
  const user = payload.user && typeof payload.user === 'object' ? payload.user : {};
  const status = payload.status === 'error' ? 'error' : 'ok';

  return {
    allowed: normalizeBool(payload.allowed, DEFAULT_STATE.allowed),
    deny_message: normalizeString(payload.deny_message, DEFAULT_STATE.deny_message),
    greeting: normalizeString(payload.greeting, DEFAULT_STATE.greeting),
    context_message: normalizeString(payload.context_message, DEFAULT_STATE.context_message),
    access: {
      context: normalizeString(access.context, DEFAULT_STATE.access.context),
      is_embedded: normalizeBool(access.is_embedded, DEFAULT_STATE.access.is_embedded),
      is_super_admin: normalizeBool(access.is_super_admin, DEFAULT_STATE.access.is_super_admin),
      decision: normalizeString(access.decision, DEFAULT_STATE.access.decision),
      reason: normalizeString(access.reason, DEFAULT_STATE.access.reason),
    },
    auth: {
      source: normalizeString(auth.source, DEFAULT_STATE.auth.source),
    },
    user: {
      id: normalizeString(user.id, DEFAULT_STATE.user.id),
      name: normalizeString(user.name, DEFAULT_STATE.user.name),
      last_name: normalizeString(user.last_name, DEFAULT_STATE.user.last_name),
      is_admin: normalizeOptionalBool(user.is_admin),
      department: normalizeString(user.department, DEFAULT_STATE.user.department),
      department_ids: Array.isArray(user.department_ids) ? user.department_ids : [],
    },
    status,
    error_message: normalizeString(payload.error_message, DEFAULT_STATE.error_message),
  };
};

const normalizeModule = (moduleItem) => {
  const item = moduleItem && typeof moduleItem === 'object' ? moduleItem : {};
  return {
    key: normalizeString(item.key),
    title: normalizeString(item.title),
    subtitle: normalizeString(item.subtitle),
    icon: normalizeString(item.icon),
    route: normalizeString(item.route),
    enabled: normalizeBool(item.enabled, true),
    allowed_users: normalizeStringArray(item.allowed_users),
    allowed_departments: normalizeStringArray(item.allowed_departments),
  };
};

const normalizeModulesConfig = (payload) => {
  const modulesPayload = payload && typeof payload === 'object' ? payload.modules : null;
  const modules = Array.isArray(modulesPayload) && modulesPayload.length > 0
    ? modulesPayload.map((moduleItem) => normalizeModule(moduleItem))
    : DEFAULT_MODULES.map((moduleItem) => ({ ...moduleItem }));

  return { modules };
};

export const useUiStateStore = defineStore('uiState', () => {
  const state = ref({ ...DEFAULT_STATE });
  const loading = ref(true);
  const error = ref('');
  let activeController = null;

  const modulesConfig = ref({ modules: DEFAULT_MODULES.map((moduleItem) => ({ ...moduleItem })) });
  const modulesLoading = ref(false);
  const modulesError = ref('');
  const modulesLoaded = ref(false);
  let modulesController = null;

  const load = async () => {
    if (activeController) {
      activeController.abort();
    }

    const controller = new AbortController();
    activeController = controller;
    const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);

    loading.value = true;
    error.value = '';

    try {
      const data = await fetchUiState(controller.signal);
      const normalized = normalizeState(data);
      state.value = normalized;

      if (normalized.status === 'error') {
        const message = normalized.error_message || NEUTRAL_ERROR;
        error.value = message;
        notifyError(message);
      }
    } catch (fetchError) {
      const message = NEUTRAL_ERROR;
      error.value = message;
      state.value = {
        ...DEFAULT_STATE,
        allowed: true,
        deny_message: '',
        greeting: '',
        context_message: '',
        status: 'error',
        error_message: message,
      };
      notifyError(message);
      console.error('UI state fetch failed', fetchError);
    } finally {
      clearTimeout(timeoutId);
      if (activeController === controller) {
        activeController = null;
      }
      loading.value = false;
    }
  };

  const loadModulesAccess = async (force = false) => {
    if (modulesLoaded.value && !force) {
      return modulesConfig.value;
    }

    if (modulesController) {
      modulesController.abort();
    }

    const controller = new AbortController();
    modulesController = controller;
    const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);

    modulesLoading.value = true;
    modulesError.value = '';

    try {
      const data = await fetchAccessModulesConfig(controller.signal);
      const normalized = normalizeModulesConfig(data);
      modulesConfig.value = normalized;
      modulesLoaded.value = true;
      return normalized;
    } catch (fetchError) {
      const message = 'Не удалось загрузить доступ к модулям.';
      modulesError.value = message;
      modulesConfig.value = { modules: DEFAULT_MODULES.map((moduleItem) => ({ ...moduleItem })) };
      notifyError(message);
      console.error('Modules access fetch failed', fetchError);
      return null;
    } finally {
      clearTimeout(timeoutId);
      if (modulesController === controller) {
        modulesController = null;
      }
      modulesLoading.value = false;
    }
  };

  const saveModulesAccess = async (payload, signal) => {
    modulesError.value = '';
    try {
      const result = await updateAccessModulesConfig(payload, signal);
      if (result?.status === 'ok') {
        const normalized = normalizeModulesConfig(payload);
        modulesConfig.value = normalized;
        modulesLoaded.value = true;
      } else {
        const message = result?.error_message || 'Не удалось сохранить доступ к модулям.';
        modulesError.value = message;
      }
      return result;
    } catch (saveError) {
      const message = 'Не удалось сохранить доступ к модулям.';
      modulesError.value = message;
      console.error('Modules access save failed', saveError);
      throw saveError;
    }
  };

  return {
    state,
    loading,
    error,
    load,
    modulesConfig,
    modulesLoading,
    modulesError,
    loadModulesAccess,
    saveModulesAccess,
  };
});
