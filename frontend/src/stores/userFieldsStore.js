import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import {
  fetchSections,
  fetchFieldsBySection,
  createUserField as createUserFieldApi,
} from '@/services/userFieldsService';

const FIXED_SECTIONS = [
  { id: 'deal', title: 'Сделки', entityId: 'CRM_DEAL' },
  { id: 'lead', title: 'Лиды', entityId: 'CRM_LEAD' },
  { id: 'contact', title: 'Контакты', entityId: 'CRM_CONTACT' },
  { id: 'company', title: 'Компании', entityId: 'CRM_COMPANY' },
];

export const useUserFieldsStore = defineStore('userFields', () => {
  const sections = ref(FIXED_SECTIONS);
  const smartTypes = ref([]);
  const fields = ref([]);
  const currentSection = ref('');
  const currentEntityTypeId = ref(null);
  const currentSectionTitle = ref('');
  const loading = ref(false);
  const sectionsLoading = ref(false);
  const error = ref('');

  const allSections = computed(() => {
    const items = [...sections.value];
    smartTypes.value.forEach((sp) => {
      // id из crm.type.list — для ENTITY_ID в crm.userfield.list (CRM_{id})
      const typeId = sp.id;
      const entityTypeId = sp.entityTypeId || typeId;
      items.push({
        id: 'smart',
        entityTypeId,
        typeId,
        title: sp.title || `Смарт-процесс ${entityTypeId}`,
        entityId: entityTypeId,
      });
    });
    return items;
  });

  async function loadSections(signal) {
    sectionsLoading.value = true;
    error.value = '';

    try {
      const data = await fetchSections(signal);
      smartTypes.value = data.smart_types || [];
      if (Array.isArray(data.sections) && data.sections.length > 0) {
        sections.value = data.sections;
      }
    } catch (err) {
      error.value = err?.message || 'Не удалось загрузить разделы';
      smartTypes.value = [];
    } finally {
      sectionsLoading.value = false;
    }
  }

  async function loadFields(section, entityTypeId, typeId, signal) {
    loading.value = true;
    error.value = '';
    currentSection.value = section || '';
    currentEntityTypeId.value = entityTypeId || null;
    fields.value = [];
    currentSectionTitle.value = '';

    try {
      const data = await fetchFieldsBySection(section, entityTypeId, typeId, signal);
      fields.value = data.user_fields || [];
      currentSectionTitle.value = resolveSectionTitle(section, entityTypeId);
    } catch (err) {
      error.value = err?.message || 'Не удалось загрузить поля';
      fields.value = [];
    } finally {
      loading.value = false;
    }
  }

  function resolveSectionTitle(section, entityTypeId) {
    if (section === 'smart' && entityTypeId) {
      const sp = smartTypes.value.find(
        (t) => String(t.entityTypeId || t.id) === String(entityTypeId),
      );
      return sp?.title || `Смарт-процесс ${entityTypeId}`;
    }
    const fixed = FIXED_SECTIONS.find((s) => s.id === section);
    return fixed?.title || section;
  }

  function clearError() {
    error.value = '';
  }

  async function createField(section, fields, entityTypeId = null, typeId = null, signal) {
    const result = await createUserFieldApi(
      section,
      fields,
      entityTypeId,
      typeId,
      signal,
    );
    const controller = new AbortController();
    await loadFields(section, entityTypeId, typeId, controller.signal);
    return result;
  }

  return {
    sections,
    smartTypes,
    allSections,
    fields,
    currentSection,
    currentEntityTypeId,
    currentSectionTitle,
    loading,
    sectionsLoading,
    error,
    loadSections,
    loadFields,
    createField,
    resolveSectionTitle,
    clearError,
  };
});
