<template>
  <div class="user-fields-view">
    <UserFieldsBreadcrumb
      v-if="showList"
      :section="currentSection"
      :entity-type-id="currentEntityTypeId"
      :section-title="sectionTitle"
      @back="goBack"
    />
    <div v-if="showSections" class="user-fields-view__sections">
      <UserFieldsSectionTiles
        :sections="allSections"
        :loading="sectionsLoading"
        :error="sectionsError"
        @select="goToSection"
      />
    </div>
    <div v-else-if="showList" class="user-fields-view__list">
      <UserFieldsTable
        :section="effectiveSection"
        :entity-type-id="routeEntityTypeId || null"
        :type-id="routeTypeId || null"
        :fields="fields"
        :loading="loading"
        :error="error"
        @back="goBack"
        @created="handleFieldCreated"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useUserFieldsStore } from '@/stores/userFieldsStore';
import { notifySuccess } from '@/services/notifications';
import UserFieldsBreadcrumb from '@/components/user-fields/UserFieldsBreadcrumb.vue';
import UserFieldsSectionTiles from '@/components/user-fields/UserFieldsSectionTiles.vue';
import UserFieldsTable from '@/components/user-fields/UserFieldsTable.vue';

const route = useRoute();
const router = useRouter();
const store = useUserFieldsStore();

const {
  allSections,
  fields,
  currentSection,
  currentEntityTypeId,
  currentSectionTitle,
  loading,
  sectionsLoading,
  error,
} = storeToRefs(store);

const sectionsError = computed(() => store.error);

const routeSection = computed(() => {
  if (route.name === 'user-fields-smart') {
    return 'smart';
  }
  return route.params.section || '';
});

const routeEntityTypeId = computed(() =>
  route.name === 'user-fields-smart' ? route.params.entityTypeId || '' : '',
);

const routeTypeId = computed(() =>
  route.name === 'user-fields-smart' ? (route.query.typeId || '') : '',
);

const showSections = computed(
  () =>
    route.name === 'user-fields' && !route.params.section && !route.params.entityTypeId,
);

const showList = computed(
  () => routeSection.value !== '' || routeEntityTypeId.value !== '',
);

const effectiveSection = computed(
  () => (routeSection.value || (routeEntityTypeId.value ? 'smart' : '')) || '',
);

const sectionTitle = computed(() => currentSectionTitle.value);

async function handleFieldCreated() {
  notifySuccess('Поле создано');
  const controller = new AbortController();
  await loadFields();
}

async function loadSections() {
  const controller = new AbortController();
  await store.loadSections(controller.signal);
}

async function loadFields() {
  const section = routeSection.value;
  const entityTypeId = routeEntityTypeId.value;
  const typeId = routeTypeId.value;
  if (!section && !entityTypeId) {
    return;
  }
  const effectiveSection = section || 'smart';
  if (effectiveSection === 'smart' && store.smartTypes.length === 0) {
    const ctrl = new AbortController();
    await store.loadSections(ctrl.signal);
  }
  const controller = new AbortController();
  await store.loadFields(effectiveSection, entityTypeId || null, typeId || null, controller.signal);
}

function goToSection(item) {
  if (item.id === 'smart' && (item.entityTypeId || item.typeId)) {
    const query = {};
    if (item.typeId != null && item.typeId !== '') {
      query.typeId = item.typeId;
    }
    router.push({
      name: 'user-fields-smart',
      params: {
        entityTypeId: item.entityTypeId || item.typeId,
      },
      query,
    });
  } else {
    router.push({ name: 'user-fields-section', params: { section: item.id } });
  }
}

function goBack() {
  router.push({ name: 'user-fields' });
}

onMounted(() => {
  if (showSections.value) {
    loadSections();
  } else {
    loadFields();
  }
});

watch(
  () => [route.name, route.params, route.query],
  () => {
    if (showSections.value) {
      loadSections();
    } else if (showList.value) {
      loadFields();
    }
  },
);
</script>

<style scoped>
.user-fields-view {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.user-fields-view__sections,
.user-fields-view__list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
</style>
