<template>
  <section class="access-manager">
    <div class="access-manager__header">
      <div>
        <div class="access-manager__title">Доступ к приложению</div>
        <div class="access-manager__subtitle">
          Управление правами по пользователям и отделам
        </div>
      </div>
      <button class="access-manager__back" type="button" @click="$emit('close')">
        Назад
      </button>
    </div>

    <div v-if="loading" class="access-manager__loading">Загрузка данных доступа...</div>
    <div v-else>
      <div class="access-manager__status">
        <label class="access-manager__toggle">
          <input type="checkbox" v-model="globalEnabled" />
          Глобальный вход разрешен
        </label>
        <label class="access-manager__toggle">
          <input type="checkbox" v-model="denyDirect" />
          Запретить вход по прямой ссылке
        </label>
        <span v-if="saving" class="access-manager__status-text">Сохранение...</span>
        <span v-else-if="lastSaved" class="access-manager__status-text success">Сохранено</span>
        <span v-else-if="saveError" class="access-manager__status-text error">
          {{ saveError }}
        </span>
      </div>

      <div class="access-manager__superadmin">
        <div class="access-manager__block-title">Супер админ</div>
        <div class="access-manager__superadmin-value">
          ID {{ superAdmin.id || '—' }} · {{ superAdmin.full_name || 'Не определен' }}
        </div>
      </div>

      <div class="access-manager__lists">
        <div class="access-manager__list">
          <div class="access-manager__block-title">Пользователи</div>
          <div v-if="users.length === 0" class="access-manager__empty">
            Нет данных пользователей.
          </div>
          <label v-for="user in users" :key="user.id" class="access-manager__item">
            <input type="checkbox" :value="user.id" v-model="selectedUsers" />
            <span>{{ user.full_name }} (ID {{ user.id }})</span>
          </label>
        </div>

        <div class="access-manager__list">
          <div class="access-manager__block-title">Отделы</div>
          <div v-if="departments.length === 0" class="access-manager__empty">
            Нет данных отделов.
          </div>
          <label
            v-for="department in departments"
            :key="department.id"
            class="access-manager__item"
          >
            <input type="checkbox" :value="department.id" v-model="selectedDepartments" />
            <span>{{ department.name }} (ID {{ department.id }})</span>
          </label>
        </div>
      </div>

      <div class="access-manager__current">
        <div class="access-manager__block-title">Текущие права</div>
        <div class="access-manager__summary">
          Пользователи: {{ selectedUsers.length }} · Отделы: {{ selectedDepartments.length }}
        </div>
        <div v-if="selectedUserNames.length" class="access-manager__summary-list">
          {{ selectedUserNames.join(', ') }}
        </div>
        <div v-if="selectedDepartmentNames.length" class="access-manager__summary-list">
          {{ selectedDepartmentNames.join(', ') }}
        </div>
      </div>

      <div class="access-manager__modules">
        <div class="access-manager__block-title">Доступ к модулям</div>
        <button
          class="access-manager__modules-toggle"
          type="button"
          @click="showModulesManager = !showModulesManager"
        >
          {{ showModulesManager ? 'Скрыть настройки' : 'Открыть настройки' }}
        </button>
        <AccessModulesManager v-if="showModulesManager" />
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
  fetchAccessConfig,
  fetchAccessDirectory,
  updateAccessConfig,
} from '@/services/accessConfigService';
import { notifyError, notifySuccess } from '@/services/notifications';
import AccessModulesManager from '@/components/AccessModulesManager.vue';

const emit = defineEmits(['close']);

const loading = ref(true);
const saving = ref(false);
const lastSaved = ref(false);
const saveError = ref('');
const configError = ref(false);

const users = ref([]);
const departments = ref([]);
const superAdmin = ref({ id: '', full_name: '' });

const selectedUsers = ref([]);
const selectedDepartments = ref([]);
const globalEnabled = ref(true);
const denyDirect = ref(false);

const saveTimer = ref(null);
const controller = ref(null);
const ready = ref(false);
const showModulesManager = ref(false);

const selectedUserNames = computed(() => {
  const map = new Map(users.value.map((user) => [user.id, user.full_name]));
  return selectedUsers.value
    .map((id) => map.get(id))
    .filter((name) => typeof name === 'string' && name.trim() !== '');
});

const selectedDepartmentNames = computed(() => {
  const map = new Map(departments.value.map((department) => [department.id, department.name]));
  return selectedDepartments.value
    .map((id) => map.get(id))
    .filter((name) => typeof name === 'string' && name.trim() !== '');
});

const clearSaveTimer = () => {
  if (saveTimer.value) {
    clearTimeout(saveTimer.value);
    saveTimer.value = null;
  }
};

const scheduleSave = () => {
  if (!ready.value) {
    return;
  }
  clearSaveTimer();
  saveTimer.value = setTimeout(() => {
    saveChanges();
  }, 800);
};

const saveChanges = async () => {
  if (saving.value) {
    return;
  }
  saving.value = true;
  saveError.value = '';
  lastSaved.value = false;

  try {
    const payload = {
      global_enabled: globalEnabled.value,
      deny_direct: denyDirect.value,
      allowed_users: [...selectedUsers.value],
      allowed_departments: [...selectedDepartments.value],
    };
    const result = await updateAccessConfig(payload, controller.value?.signal);
    if (result.status !== 'ok') {
      const message = result.error_message || 'Не удалось сохранить доступ.';
      saveError.value = message;
      notifyError(message);
    } else {
      lastSaved.value = true;
      notifySuccess('Доступ сохранен.');
    }
  } catch (error) {
    const message = 'Не удалось сохранить доступ.';
    saveError.value = message;
    notifyError(message);
    console.error('Access config update failed', error);
  } finally {
    saving.value = false;
  }
};

const loadData = async () => {
  loading.value = true;
  saveError.value = '';
  configError.value = false;
  ready.value = false;

  if (controller.value) {
    controller.value.abort();
  }
  controller.value = new AbortController();

  try {
    const [configResult, directoryResult] = await Promise.all([
      fetchAccessConfig(controller.value.signal),
      fetchAccessDirectory(controller.value.signal),
    ]);

    if (configResult.status !== 'ok') {
      throw new Error(configResult.error_message || 'Access config error');
    }
    if (directoryResult.status !== 'ok') {
      throw new Error(directoryResult.error_message || 'Access directory error');
    }

    superAdmin.value = configResult.super_admin || { id: '', full_name: '' };
    globalEnabled.value = !!configResult.config?.global_enabled;
    denyDirect.value = !!configResult.config?.deny_direct;
    selectedUsers.value = Array.isArray(configResult.config?.allowed_users)
      ? configResult.config.allowed_users
      : [];
    selectedDepartments.value = Array.isArray(configResult.config?.allowed_departments)
      ? configResult.config.allowed_departments
      : [];
    users.value = Array.isArray(directoryResult.users) ? directoryResult.users : [];
    departments.value = Array.isArray(directoryResult.departments) ? directoryResult.departments : [];
    configError.value = !!configResult.config_error;
  } catch (error) {
    const message = 'Не удалось загрузить доступ.';
    notifyError(message);
    console.error('Access manager load failed', error);
    emit('close');
  } finally {
    loading.value = false;
    ready.value = true;
  }
};

watch([selectedUsers, selectedDepartments, globalEnabled, denyDirect], () => {
  scheduleSave();
}, { deep: true });

onMounted(() => {
  loadData();
});

onBeforeUnmount(() => {
  clearSaveTimer();
  if (controller.value) {
    controller.value.abort();
  }
});
</script>
