<template>
  <div class="user-fields-breadcrumb">
    <button type="button" class="user-fields-breadcrumb__back" @click="$emit('back')">
      ← Назад
    </button>
    <span class="user-fields-breadcrumb__sep">|</span>
    <span class="user-fields-breadcrumb__path">
      <router-link :to="{ name: 'user-fields' }" class="user-fields-breadcrumb__link">
        Пользовательские поля
      </router-link>
      <span v-if="sectionTitle" class="user-fields-breadcrumb__current">
        → {{ sectionTitle }}
      </span>
    </span>
  </div>
</template>

<script setup>
defineProps({
  section: {
    type: String,
    default: '',
  },
  entityTypeId: {
    type: String,
    default: null,
  },
  sectionTitle: {
    type: String,
    default: '',
  },
});

defineEmits(['back']);
</script>

<style scoped>
.user-fields-breadcrumb {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  padding: 12px 0;
}

.user-fields-breadcrumb__back {
  border: 1px solid var(--b24-border);
  background: var(--b24-card-bg);
  padding: 6px 12px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
}

.user-fields-breadcrumb__back:hover {
  border-color: var(--b24-primary);
  color: var(--b24-primary);
}

.user-fields-breadcrumb__sep {
  color: var(--b24-text-muted);
}

.user-fields-breadcrumb__path {
  color: var(--b24-text-muted);
}

.user-fields-breadcrumb__link {
  color: var(--b24-primary);
  text-decoration: none;
}

.user-fields-breadcrumb__link:hover {
  text-decoration: underline;
}

.user-fields-breadcrumb__current {
  color: var(--b24-text);
}
</style>
