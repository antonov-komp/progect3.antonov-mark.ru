<template>
  <Teleport to="body">
    <div v-if="visible" class="configure-modal-overlay" @click.self="$emit('cancel')">
      <div class="configure-modal">
        <div class="configure-modal__header">
          <h3 class="configure-modal__title">Настроить поле «{{ fieldTitle }}»</h3>
          <button type="button" class="configure-modal__close" aria-label="Закрыть" @click="$emit('cancel')">
            ×
          </button>
        </div>

        <div v-if="errorMessage" class="configure-modal__error">{{ errorMessage }}</div>

        <template v-if="userTypeId === 'buttons_view'">
          <div class="configure-modal__field">
            <label class="configure-modal__label">Кнопки</label>
            <div
              v-for="(btn, idx) in settings.buttons"
              :key="idx"
              class="configure-modal__button-row"
            >
              <input
                v-model="btn.text"
                type="text"
                class="configure-modal__input"
                placeholder="Текст кнопки"
              />
              <select v-model="btn.action" class="configure-modal__select configure-modal__input">
                <option value="open_url">Открыть URL</option>
                <option value="open_phone">Позвонить</option>
                <option value="open_email">Написать email</option>
              </select>
              <input
                v-if="btn.action === 'open_url'"
                v-model="btn.url"
                type="text"
                class="configure-modal__input"
                placeholder="https://..."
              />
              <input
                v-else-if="btn.action === 'open_phone'"
                v-model="btn.phone"
                type="text"
                class="configure-modal__input"
                placeholder="tel: или номер"
              />
              <input
                v-else-if="btn.action === 'open_email'"
                v-model="btn.email"
                type="text"
                class="configure-modal__input"
                placeholder="email@example.com"
              />
              <button
                type="button"
                class="configure-modal__btn-remove"
                @click="removeButton(idx)"
              >
                ×
              </button>
            </div>
            <button
              type="button"
              class="configure-modal__btn-add"
              @click="addButton"
            >
              + Добавить кнопку
            </button>
          </div>
        </template>

        <template v-else-if="userTypeId === 'dependent_fields_view'">
          <div class="configure-modal__field">
            <label class="configure-modal__label">Источник</label>
            <select v-model="settings.source" class="configure-modal__select configure-modal__input">
              <option value="current_entity">Текущая сущность</option>
              <option value="contact">Связанный контакт</option>
              <option value="company">Связанная компания</option>
            </select>
          </div>
          <div class="configure-modal__field">
            <label class="configure-modal__label">Поля для вывода</label>
            <div
              v-for="(f, idx) in settings.fields"
              :key="idx"
              class="configure-modal__button-row"
            >
              <input
                v-model="f.fieldName"
                type="text"
                class="configure-modal__input"
                placeholder="UF_CRM_PHONE"
              />
              <input
                v-model="f.label"
                type="text"
                class="configure-modal__input"
                placeholder="Подпись"
              />
              <select v-model="f.format" class="configure-modal__select configure-modal__input">
                <option value="text">Текст</option>
                <option value="phone">Телефон</option>
                <option value="email">Email</option>
                <option value="url">Ссылка</option>
              </select>
              <button
                type="button"
                class="configure-modal__btn-remove"
                @click="removeField(idx)"
              >
                ×
              </button>
            </div>
            <button
              type="button"
              class="configure-modal__btn-add"
              @click="addField"
            >
              + Добавить поле
            </button>
          </div>
          <div class="configure-modal__field">
            <label class="configure-modal__label">Макс. высота (px)</label>
            <input
              v-model.number="settings.maxHeight"
              type="number"
              min="0"
              class="configure-modal__input"
            />
          </div>
        </template>

        <div class="configure-modal__actions">
          <button
            type="button"
            class="configure-modal__btn configure-modal__btn--secondary"
            @click="$emit('cancel')"
          >
            Отмена
          </button>
          <button
            type="button"
            class="configure-modal__btn configure-modal__btn--primary"
            :disabled="saving"
            @click="handleSave"
          >
            {{ saving ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue';
import { fetchEmbedFieldSettings, saveEmbedFieldSettings } from '@/services/userFieldsService';

const props = defineProps({
  visible: { type: Boolean, default: false },
  section: { type: String, default: '' },
  entityTypeId: { type: String, default: null },
  typeId: { type: String, default: null },
  field: { type: Object, default: null },
});

const emit = defineEmits(['saved', 'cancel']);

const fieldTitle = ref('');
const userTypeId = ref('');
const settings = ref(defaultSettingsForType(''));
const saving = ref(false);
const errorMessage = ref('');

function defaultSettingsForType(typeId) {
  if (typeId === 'buttons_view') {
    return { buttons: [{ text: '', action: 'open_url', url: '' }] };
  }
  if (typeId === 'dependent_fields_view') {
    return {
      source: 'current_entity',
      fields: [{ fieldName: '', label: '', format: 'text' }],
      maxHeight: 200,
    };
  }
  return {};
}

function addButton() {
  settings.value.buttons.push({ text: '', action: 'open_url', url: '' });
}

function removeButton(idx) {
  settings.value.buttons.splice(idx, 1);
}

function addField() {
  settings.value.fields.push({ fieldName: '', label: '', format: 'text' });
}

function removeField(idx) {
  settings.value.fields.splice(idx, 1);
}

async function loadSettings() {
  if (!props.field || !props.section) return;
  const fieldId = props.field.ID ?? props.field.id ?? 0;
  if (!fieldId) return;
  try {
    const { settings: loaded } = await fetchEmbedFieldSettings(props.section, fieldId);
    if (loaded && Object.keys(loaded).length > 0) {
      settings.value = { ...defaultSettingsForType(userTypeId.value), ...loaded };
      if (userTypeId.value === 'buttons_view' && !Array.isArray(settings.value.buttons)) {
        settings.value.buttons = settings.value.buttons ? [settings.value.buttons] : [{ text: '', action: 'open_url', url: '' }];
      }
      if (userTypeId.value === 'dependent_fields_view' && !Array.isArray(settings.value.fields)) {
        settings.value.fields = settings.value.fields ? [settings.value.fields] : [{ fieldName: '', label: '', format: 'text' }];
      }
    }
  } catch {
    // use defaults
  }
}

async function handleSave() {
  if (!props.field || !props.section) return;
  const fieldId = props.field.ID ?? props.field.id ?? 0;
  const fieldName = props.field.FIELD_NAME ?? props.field.fieldName ?? '';
  if (!fieldId) return;
  saving.value = true;
  errorMessage.value = '';
  try {
    await saveEmbedFieldSettings(props.section, fieldId, settings.value, fieldName, null, {
      entityTypeId: props.entityTypeId,
      typeId: props.typeId,
    });
    emit('saved');
  } catch (err) {
    errorMessage.value = err?.message || 'Ошибка сохранения';
  } finally {
    saving.value = false;
  }
}

watch(
  () => [props.visible, props.field],
  async ([visible, field]) => {
    if (visible && field) {
      fieldTitle.value = field.TITLE ?? field.FIELD_NAME ?? field.fieldName ?? '';
      userTypeId.value = field.USER_TYPE_ID ?? field.userTypeId ?? '';
      settings.value = defaultSettingsForType(userTypeId.value);
      await loadSettings();
    }
  },
);
</script>

<style scoped>
.configure-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1001;
}
.configure-modal {
  background: var(--b24-card-bg);
  border-radius: 12px;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.15);
  width: 90%;
  max-width: 560px;
  max-height: 90vh;
  overflow-y: auto;
}
.configure-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid var(--b24-border);
}
.configure-modal__title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
}
.configure-modal__close {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: var(--b24-text-muted);
}
.configure-modal__error {
  background: #fef2f2;
  color: var(--b24-danger);
  padding: 10px 20px;
  font-size: 13px;
}
.configure-modal__field {
  padding: 16px 20px;
  border-bottom: 1px solid var(--b24-border);
}
.configure-modal__label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 8px;
}
.configure-modal__input {
  padding: 8px 12px;
  border: 1px solid var(--b24-border);
  border-radius: 6px;
  font-size: 14px;
  flex: 1;
  min-width: 0;
}
.configure-modal__select {
  cursor: pointer;
  min-width: 140px;
}
.configure-modal__button-row {
  display: flex;
  gap: 8px;
  margin-bottom: 8px;
  align-items: center;
}
.configure-modal__btn-remove {
  background: none;
  border: none;
  color: var(--b24-danger);
  cursor: pointer;
  font-size: 18px;
  padding: 0 8px;
}
.configure-modal__btn-add {
  margin-top: 8px;
  padding: 8px 12px;
  border: 1px dashed var(--b24-border);
  background: var(--b24-card-bg);
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
}
.configure-modal__btn-add:hover {
  border-color: var(--b24-primary);
  color: var(--b24-primary);
}
.configure-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 20px;
  border-top: 1px solid var(--b24-border);
}
.configure-modal__btn {
  padding: 10px 20px;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
  border: 1px solid var(--b24-border);
}
.configure-modal__btn--primary {
  background: var(--b24-primary);
  color: white;
  border-color: var(--b24-primary);
}
.configure-modal__btn--primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.configure-modal__btn--secondary {
  background: var(--b24-card-bg);
}
.configure-modal__btn--secondary:hover {
  border-color: var(--b24-primary);
  color: var(--b24-primary);
}
</style>
