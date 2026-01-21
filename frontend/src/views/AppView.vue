<template>
  <AppShell>
    <Loader v-if="loading" />
    <template v-else>
      <AccessDenied v-if="!state.allowed" :message="state.deny_message" />
      <template v-else>
        <div v-if="isSuperAdmin && !showAccessManager" class="app-toolbar">
          <button class="app-button" type="button" @click="showAccessManager = true">
            Управление доступом
          </button>
        </div>
        <AccessManager
          v-if="showAccessManager"
          @close="showAccessManager = false"
        />
        <GreetingCard
          v-else
          :greeting="state.greeting"
          :context-message="state.context_message"
          :access="state.access"
          :user="state.user"
          :show-context="showContextInfo"
          :show-embed-warning="showEmbedWarning"
        />
      </template>
      <ErrorState v-if="error" :message="error" />
    </template>
  </AppShell>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useUiState } from '@/composables/useUiState';
import AppShell from '@/components/AppShell.vue';
import AccessDenied from '@/components/AccessDenied.vue';
import Loader from '@/components/Loader.vue';
import ErrorState from '@/components/ErrorState.vue';
import GreetingCard from '@/components/GreetingCard.vue';
import AccessManager from '@/components/AccessManager.vue';

const { state, loading, error, reload } = useUiState();
reload();

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

const isSuperAdmin = computed(() => state.value.access.is_super_admin === true);
const showAccessManager = ref(false);

</script>
