import { defineStore } from 'pinia';
import { ref } from 'vue';
import { fetchUiState } from '@/services/uiStateService';
import { notifyError } from '@/services/notifications';

const NEUTRAL_ERROR = 'Не удалось загрузить данные приложения. Попробуйте позже.';
const REQUEST_TIMEOUT = 8000;

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

export const useUiStateStore = defineStore('uiState', () => {
  const state = ref({ ...DEFAULT_STATE });
  const loading = ref(true);
  const error = ref('');
  let activeController = null;

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

  return {
    state,
    loading,
    error,
    load,
  };
});
