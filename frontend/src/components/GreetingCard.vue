<template>
  <section class="app-greeting-card">
    <div v-if="greeting" class="app-greeting b24-title">
      {{ greeting }}
    </div>
    <AccessContextInfo
      v-if="showContext"
      :context-message="contextMessage"
      :access="access"
      :user="user"
    />
    <div v-if="showEmbedWarning" class="app-warning b24-alert b24-alert-warning">
      Откройте приложение внутри Bitrix24, чтобы определить пользователя.
    </div>
  </section>
</template>

<script setup>
import AccessContextInfo from '@/components/AccessContextInfo.vue';

defineProps({
  greeting: {
    type: String,
    default: '',
  },
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
  showContext: {
    type: Boolean,
    default: false,
  },
  showEmbedWarning: {
    type: Boolean,
    default: false,
  },
});
</script>
