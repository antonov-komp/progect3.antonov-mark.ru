<template>
  <div class="app-context b24-text">
    <div class="app-context-message">
      {{ resolvedMessage }}
    </div>
    <div class="app-context-meta b24-text-muted">
      <span>Доступ: {{ access.decision }}</span>
      <span>|</span>
      <span>Контекст: {{ contextLabel }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  contextMessage: {
    type: String,
    default: '',
  },
  access: {
    type: Object,
    required: true,
  },
  user: {
    type: Object,
    required: true,
  },
});

const contextLabel = computed(() => {
  if (props.access.context === 'embedded') {
    return 'внутри Bitrix24';
  }

  if (props.access.context === 'direct') {
    return 'по прямой ссылке';
  }

  return 'неизвестен';
});

const resolvedMessage = computed(() => {
  if (props.contextMessage.trim() !== '') {
    return props.contextMessage;
  }

  const adminLabel =
    props.user.is_admin === null ? 'неизвестно' : props.user.is_admin ? 'да' : 'нет';
  const departmentLabel = props.user.department || 'не указан';

  return `Администратор портала: ${adminLabel}; контекст: ${contextLabel.value}; отдел: ${departmentLabel}.`;
});
</script>
