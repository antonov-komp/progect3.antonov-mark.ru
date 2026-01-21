import { storeToRefs } from 'pinia';
import { useUiStateStore } from '@/stores/uiStateStore';

export function useUiState() {
  const store = useUiStateStore();
  const { state, loading, error } = storeToRefs(store);
  return {
    state,
    loading,
    error,
    reload: store.load,
  };
}
