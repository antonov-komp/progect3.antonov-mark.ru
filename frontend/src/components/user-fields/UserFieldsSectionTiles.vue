<template>
  <section class="user-fields-sections">
    <div class="user-fields-sections__header">
      <div class="user-fields-sections__title">Разделы</div>
      <div class="user-fields-sections__subtitle">
        Выберите раздел для просмотра пользовательских полей
      </div>
    </div>

    <LoadingState v-if="loading" />

    <ErrorState v-else-if="error" :message="error" />

    <div v-else class="user-fields-sections__grid">
      <button
        v-for="item in sections"
        :key="item.id + (item.entityTypeId || '')"
        type="button"
        class="user-fields-section-tile"
        @click="$emit('select', item)"
      >
        <div class="user-fields-section-tile__icon">
          {{ item.id === 'smart' ? 'SP' : item.id.substring(0, 2).toUpperCase() }}
        </div>
        <div class="user-fields-section-tile__content">
          <div class="user-fields-section-tile__title">{{ item.title }}</div>
          <div v-if="item.id === 'smart'" class="user-fields-section-tile__subtitle">
            ID: {{ item.entityTypeId || item.entityId }}
          </div>
        </div>
      </button>
    </div>
  </section>
</template>

<script setup>
import ErrorState from '@/components/ErrorState.vue';
import LoadingState from '@/components/LoadingState.vue';

defineProps({
  sections: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: '',
  },
});

defineEmits(['select']);
</script>

<style scoped>
.user-fields-sections {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.user-fields-sections__header {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.user-fields-sections__title {
  font-size: 16px;
  font-weight: 600;
}

.user-fields-sections__subtitle {
  font-size: 13px;
  color: var(--b24-text-muted);
}

.user-fields-sections__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}

.user-fields-section-tile {
  display: flex;
  align-items: center;
  gap: 12px;
  border: 1px solid var(--b24-border);
  background: var(--b24-card-bg);
  border-radius: 12px;
  padding: 16px;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.user-fields-section-tile:hover {
  border-color: var(--b24-primary);
  box-shadow: 0 6px 14px rgba(0, 123, 255, 0.12);
}

.user-fields-section-tile__icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: rgba(0, 123, 255, 0.12);
  color: var(--b24-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 12px;
}

.user-fields-section-tile__content {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.user-fields-section-tile__title {
  font-size: 14px;
  font-weight: 600;
}

.user-fields-section-tile__subtitle {
  font-size: 12px;
  color: var(--b24-text-muted);
}
</style>
