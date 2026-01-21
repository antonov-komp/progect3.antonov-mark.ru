<template>
  <section class="module-tiles">
    <div class="module-tiles__header">
      <div class="module-tiles__title">Модули</div>
      <div class="module-tiles__subtitle">
        Выберите модуль для работы
      </div>
    </div>

    <div v-if="showDirectNotice" class="module-tiles__notice b24-alert b24-alert-warning">
      Модули доступны только внутри Bitrix24, где определяется пользователь.
    </div>

    <LoadingState v-else-if="modulesLoading" />

    <ErrorState v-else-if="modulesError" :message="modulesError" />

    <div v-else-if="visibleModules.length === 0" class="module-tiles__empty">
      Доступных модулей пока нет.
    </div>

    <div v-else class="module-tiles__grid">
      <button
        v-for="moduleItem in visibleModules"
        :key="moduleItem.key"
        type="button"
        class="module-tile"
        @click="openModule(moduleItem)"
      >
        <div class="module-tile__icon">
          <span>{{ resolveIconLabel(moduleItem.icon) }}</span>
        </div>
        <div class="module-tile__content">
          <div class="module-tile__title">{{ moduleItem.title }}</div>
          <div class="module-tile__subtitle">{{ moduleItem.subtitle }}</div>
        </div>
      </button>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useRouter } from 'vue-router';
import { useUiStateStore } from '@/stores/uiStateStore';
import ErrorState from '@/components/ErrorState.vue';
import LoadingState from '@/components/LoadingState.vue';

const router = useRouter();
const store = useUiStateStore();
const { state, modulesConfig, modulesLoading, modulesError } = storeToRefs(store);

const hasUserIdentity = computed(() => String(state.value.user.id || '') !== '');
const showDirectNotice = computed(() => !hasUserIdentity.value);

onMounted(() => {
  if (!showDirectNotice.value) {
    store.loadModulesAccess();
  }
});

const isSuperAdmin = computed(() => state.value.access.is_super_admin === true);

const currentUserId = computed(() => String(state.value.user.id || ''));
const currentDepartmentIds = computed(() =>
  Array.isArray(state.value.user.department_ids)
    ? state.value.user.department_ids.map((id) => String(id))
    : [],
);

const hasModuleAccess = (moduleItem) => {
  if (isSuperAdmin.value) {
    return true;
  }
  if (!moduleItem.enabled) {
    return false;
  }

  const allowedUsers = Array.isArray(moduleItem.allowed_users)
    ? moduleItem.allowed_users.map((id) => String(id))
    : [];
  const allowedDepartments = Array.isArray(moduleItem.allowed_departments)
    ? moduleItem.allowed_departments.map((id) => String(id))
    : [];

  if (allowedUsers.length === 0 && allowedDepartments.length === 0) {
    return false;
  }

  if (allowedUsers.includes(currentUserId.value)) {
    return true;
  }

  return currentDepartmentIds.value.some((id) => allowedDepartments.includes(id));
};

const visibleModules = computed(() =>
  modulesConfig.value.modules.filter((moduleItem) => hasModuleAccess(moduleItem)),
);

const resolveIconLabel = (icon) => {
  switch (icon) {
    case 'chart':
      return 'CH';
    case 'inbox':
      return 'IN';
    case 'file':
      return 'FI';
    case 'users':
      return 'US';
    case 'settings':
      return 'SE';
    default:
      return 'MD';
  }
};

const openModule = (moduleItem) => {
  if (!moduleItem || !moduleItem.route) {
    return;
  }
  router.push(moduleItem.route);
};
</script>
