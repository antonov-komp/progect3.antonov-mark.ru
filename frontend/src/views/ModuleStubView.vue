<template>
  <section class="module-stub">
    <div class="module-stub__header">
      <div>
        <div class="module-stub__title">{{ moduleTitle }}</div>
        <div class="module-stub__subtitle">{{ moduleSubtitle }}</div>
      </div>
      <button type="button" class="module-stub__back" @click="goBack">
        Назад
      </button>
    </div>
    <div class="module-stub__body">
      Раздел в разработке. Скоро здесь появится функционал модуля.
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useUiStateStore } from '@/stores/uiStateStore';

const router = useRouter();
const route = useRoute();
const store = useUiStateStore();
const { modulesConfig } = storeToRefs(store);

onMounted(() => {
  store.loadModulesAccess();
});

const moduleKey = computed(() => String(route.params.moduleKey || ''));

const moduleData = computed(() => {
  const modules = modulesConfig.value.modules;
  if (!Array.isArray(modules)) {
    return null;
  }
  return modules.find(
    (moduleItem) =>
      moduleItem.key === moduleKey.value || moduleItem.route === route.path,
  );
});

const moduleTitle = computed(() => moduleData.value?.title || 'Модуль');
const moduleSubtitle = computed(() => moduleData.value?.subtitle || 'Описание модуля');

const goBack = () => {
  router.push('/');
};
</script>
