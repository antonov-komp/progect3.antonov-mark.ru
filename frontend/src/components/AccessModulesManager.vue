<template>
  <section class="access-modules">
    <div class="access-modules__header">
      <div>
        <div class="access-modules__title">Доступ к модулям</div>
        <div class="access-modules__subtitle">
          Настройка прав по пользователям и отделам
        </div>
      </div>
      <div class="access-modules__status">
        <span v-if="saving" class="access-modules__status-text">Сохранение...</span>
        <span v-else-if="lastSaved" class="access-modules__status-text success">Сохранено</span>
        <span v-else-if="saveError" class="access-modules__status-text error">
          {{ saveError }}
        </span>
      </div>
    </div>

    <div v-if="loading" class="access-modules__loading">Загрузка модулей...</div>
    <div v-else>
      <div v-if="modulesError" class="access-modules__error b24-alert b24-alert-danger">
        {{ modulesError }}
      </div>

      <div class="access-modules__grid">
        <div class="access-modules__list">
          <button
            v-for="moduleItem in modulesDraft"
            :key="moduleItem.key"
            type="button"
            class="access-modules__list-item"
            :class="{ 'is-active': moduleItem.key === activeModuleKey }"
            @click="activeModuleKey = moduleItem.key"
          >
            <div class="access-modules__list-title">{{ moduleItem.title }}</div>
            <div class="access-modules__list-subtitle">{{ moduleItem.subtitle }}</div>
          </button>
        </div>

        <div class="access-modules__editor">
          <template v-if="currentModule">
            <div class="access-modules__editor-header">
              <div>
                <div class="access-modules__editor-title">{{ currentModule.title }}</div>
                <div class="access-modules__editor-meta">
                  {{ currentModule.route || 'Маршрут не задан' }}
                </div>
              </div>
              <label class="access-modules__toggle">
                <input type="checkbox" v-model="currentEnabled" />
                Модуль активен
              </label>
            </div>

            <div class="access-modules__section">
              <div class="access-modules__section-title">Пользователи</div>
              <div v-if="users.length === 0" class="access-modules__empty">
                Нет данных пользователей.
              </div>
              <label
                v-for="user in users"
                :key="user.id"
                class="access-modules__item"
              >
                <input type="checkbox" :value="String(user.id)" v-model="currentAllowedUsers" />
                <span>{{ user.full_name }} (ID {{ user.id }})</span>
              </label>
            </div>

            <div class="access-modules__section">
              <div class="access-modules__section-title">Отделы</div>
              <div v-if="departments.length === 0" class="access-modules__empty">
                Нет данных отделов.
              </div>
              <label
                v-for="department in departments"
                :key="department.id"
                class="access-modules__item"
              >
                <input
                  type="checkbox"
                  :value="String(department.id)"
                  v-model="currentAllowedDepartments"
                />
                <span>{{ department.name }} (ID {{ department.id }})</span>
              </label>
            </div>
          </template>
          <div v-else class="access-modules__empty">
            Выберите модуль для настройки.
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useUiStateStore } from '@/stores/uiStateStore';
import { fetchAccessDirectory } from '@/services/accessConfigService';
import { notifyError, notifySuccess } from '@/services/notifications';

const store = useUiStateStore();
const { modulesConfig, modulesError } = storeToRefs(store);

const loading = ref(true);
const saving = ref(false);
const lastSaved = ref(false);
const saveError = ref('');

const users = ref([]);
const departments = ref([]);

const modulesDraft = ref([]);
const activeModuleKey = ref('');

const saveTimer = ref(null);
const controller = ref(null);
const ready = ref(false);

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

const currentModule = computed(() =>
  modulesDraft.value.find((moduleItem) => moduleItem.key === activeModuleKey.value),
);

const updateCurrentModule = (patch) => {
  if (!currentModule.value) {
    return;
  }
  modulesDraft.value = modulesDraft.value.map((moduleItem) =>
    moduleItem.key === currentModule.value.key
      ? { ...moduleItem, ...patch }
      : moduleItem,
  );
};

const currentEnabled = computed({
  get: () => (currentModule.value ? currentModule.value.enabled : false),
  set: (value) => updateCurrentModule({ enabled: value }),
});

const currentAllowedUsers = computed({
  get: () => (currentModule.value ? currentModule.value.allowed_users : []),
  set: (value) =>
    updateCurrentModule({ allowed_users: Array.isArray(value) ? value.map(String) : [] }),
});

const currentAllowedDepartments = computed({
  get: () => (currentModule.value ? currentModule.value.allowed_departments : []),
  set: (value) =>
    updateCurrentModule({
      allowed_departments: Array.isArray(value) ? value.map(String) : [],
    }),
});

const saveChanges = async () => {
  if (saving.value) {
    return;
  }
  saving.value = true;
  saveError.value = '';
  lastSaved.value = false;

  try {
    const payload = { modules: modulesDraft.value };
    const result = await store.saveModulesAccess(payload, controller.value?.signal);
    if (result?.status !== 'ok') {
      const message = result?.error_message || 'Не удалось сохранить доступ к модулям.';
      saveError.value = message;
      notifyError(message);
    } else {
      lastSaved.value = true;
      notifySuccess('Доступ к модулям сохранен.');
    }
  } catch (error) {
    const message = 'Не удалось сохранить доступ к модулям.';
    saveError.value = message;
    notifyError(message);
    console.error('Modules access update failed', error);
  } finally {
    saving.value = false;
  }
};

const loadData = async () => {
  loading.value = true;
  saveError.value = '';
  ready.value = false;

  if (controller.value) {
    controller.value.abort();
  }
  controller.value = new AbortController();

  try {
    await store.loadModulesAccess();
    const directoryResult = await fetchAccessDirectory(controller.value.signal);

    if (directoryResult.status !== 'ok') {
      throw new Error(directoryResult.error_message || 'Access directory error');
    }

    users.value = Array.isArray(directoryResult.users) ? directoryResult.users : [];
    departments.value = Array.isArray(directoryResult.departments)
      ? directoryResult.departments
      : [];

    modulesDraft.value = modulesConfig.value.modules.map((moduleItem) => ({ ...moduleItem }));
    if (!activeModuleKey.value && modulesDraft.value.length > 0) {
      activeModuleKey.value = modulesDraft.value[0].key;
    }
  } catch (error) {
    const message = 'Не удалось загрузить конфигурацию модулей.';
    saveError.value = message;
    notifyError(message);
    console.error('Modules manager load failed', error);
  } finally {
    loading.value = false;
    ready.value = true;
  }
};

watch(modulesDraft, () => {
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
