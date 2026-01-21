<template>
  <div class="home-view">
    <GreetingCard
      :greeting="state.greeting"
      :context-message="state.context_message"
      :access="state.access"
      :user="state.user"
      :show-context="showContextInfo"
      :show-embed-warning="showEmbedWarning"
    />
    <AccessModuleTiles />
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useUiState } from '@/composables/useUiState';
import GreetingCard from '@/components/GreetingCard.vue';
import AccessModuleTiles from '@/components/AccessModuleTiles.vue';

const { state } = useUiState();

const showContextInfo = computed(
  () =>
    state.value.context_message ||
    state.value.user.name ||
    state.value.user.last_name ||
    state.value.user.department ||
    state.value.access.context !== 'unknown',
);

const showEmbedWarning = computed(
  () => state.value.access.context === 'direct' || state.value.access.is_embedded === false,
);
</script>
