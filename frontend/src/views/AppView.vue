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
        <RouterView v-else />
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
import AccessManager from '@/components/AccessManager.vue';
import { RouterView } from 'vue-router';

const { state, loading, error, reload } = useUiState();
reload();

const isSuperAdmin = computed(() => state.value.access.is_super_admin === true);
const showAccessManager = ref(false);

</script>
