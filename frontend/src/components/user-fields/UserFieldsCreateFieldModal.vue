<template>
  <Teleport to="body">
    <div v-if="visible" class="create-field-modal-overlay" @click.self="handleCancel">
      <div class="create-field-modal">
        <div class="create-field-modal__header">
          <h3 class="create-field-modal__title">Создать поле</h3>
          <button
            type="button"
            class="create-field-modal__close"
            aria-label="Закрыть"
            @click="handleCancel"
          >
            ×
          </button>
        </div>

        <form class="create-field-modal__form" @submit.prevent="handleSubmit">
          <div v-if="errorMessage" class="create-field-modal__error">
            {{ errorMessage }}
          </div>

          <div v-if="showEmbedOption" class="create-field-modal__field create-field-modal__mode-switch">
            <span class="create-field-modal__label">Режим:</span>
            <div class="create-field-modal__radio-group">
              <label class="create-field-modal__radio">
                <input v-model="fieldMode" type="radio" value="standard" />
                Обычное поле
              </label>
              <label class="create-field-modal__radio">
                <input v-model="fieldMode" type="radio" value="embed" />
                Поле-встройка (iframe)
              </label>
            </div>
          </div>

          <template v-if="fieldMode === 'standard'">
          <div class="create-field-modal__field">
            <label for="edit-form-label" class="create-field-modal__label">
              Название поля <span class="required">*</span>
            </label>
            <input
              id="edit-form-label"
              v-model="form.EDIT_FORM_LABEL"
              type="text"
              class="create-field-modal__input"
              placeholder="Моё поле"
              required
            />
          </div>

          <div class="create-field-modal__field">
            <label for="field-name" class="create-field-modal__label">
              Код поля
            </label>
            <input
              id="field-name"
              v-model="form.FIELD_NAME"
              type="text"
              class="create-field-modal__input"
              placeholder="Авто (оставьте пустым)"
              maxlength="20"
            />
          </div>

          <div class="create-field-modal__field">
            <label for="user-type-id" class="create-field-modal__label">
              Тип поля <span class="required">*</span>
            </label>
            <select
              id="user-type-id"
              v-model="form.USER_TYPE_ID"
              class="create-field-modal__input create-field-modal__select"
              required
            >
              <option value="string">Строка</option>
              <option value="integer">Число (целое)</option>
              <option value="double">Число (дробное)</option>
              <option value="boolean">Да/Нет</option>
              <option value="date">Дата</option>
              <option value="datetime">Дата и время</option>
              <option value="money">Деньги</option>
              <option value="url">Ссылка</option>
              <option value="enumeration">Список</option>
              <option value="crm_status">Справочник CRM</option>
              <option value="crm">Связь с CRM</option>
            </select>
          </div>

          <div class="create-field-modal__row">
            <label class="create-field-modal__checkbox">
              <input v-model="form.MANDATORY" type="checkbox" true-value="Y" false-value="N" />
              Обязательное
            </label>
            <label class="create-field-modal__checkbox">
              <input v-model="form.MULTIPLE" type="checkbox" true-value="Y" false-value="N" />
              Множественное
            </label>
          </div>

          <div class="create-field-modal__row">
            <label class="create-field-modal__checkbox">
              <input v-model="form.SHOW_IN_LIST" type="checkbox" true-value="Y" false-value="N" />
              Показывать в списке
            </label>
            <label class="create-field-modal__checkbox">
              <input v-model="form.EDIT_IN_LIST" type="checkbox" true-value="Y" false-value="N" />
              Редактировать в списке
            </label>
            <label class="create-field-modal__checkbox">
              <input v-model="form.SHOW_FILTER" type="checkbox" true-value="Y" false-value="N" />
              Показывать в фильтре
            </label>
          </div>

          <div class="create-field-modal__field">
            <label for="sort" class="create-field-modal__label">Сортировка</label>
            <input
              id="sort"
              v-model.number="form.SORT"
              type="number"
              min="1"
              class="create-field-modal__input"
            />
          </div>
          </template>

          <template v-else>
          <div class="create-field-modal__field">
            <label for="embed-type" class="create-field-modal__label">
              Тип встройки <span class="required">*</span>
            </label>
            <select
              id="embed-type"
              v-model="embedForm.embed_type_id"
              class="create-field-modal__input create-field-modal__select"
            >
              <option value="">Выберите тип</option>
              <option
                v-for="et in embedTypes"
                :key="et.id"
                :value="et.id"
              >
                {{ et.title }}
              </option>
              <option :value="EMBED_TYPE_CUSTOM">Свой handler</option>
            </select>
          </div>
          <div v-if="isEmbedCustom" class="create-field-modal__field">
            <label for="embed-handler-url" class="create-field-modal__label">
              URL handler'а <span class="required">*</span>
            </label>
            <input
              id="embed-handler-url"
              v-model="embedForm.handler_url"
              type="url"
              class="create-field-modal__input"
              placeholder="https://example.com/handler.php"
            />
          </div>
          <div v-if="isEmbedCustom" class="create-field-modal__field">
            <label for="embed-user-type-id" class="create-field-modal__label">
              Код типа поля (USER_TYPE_ID) <span class="required">*</span>
            </label>
            <input
              id="embed-user-type-id"
              v-model="embedForm.user_type_id"
              type="text"
              class="create-field-modal__input"
              placeholder="my_buttons_view"
              maxlength="50"
            />
            <span class="create-field-modal__hint">Только a-z, 0-9, _. Макс. 50 символов</span>
          </div>
          <div class="create-field-modal__field">
            <label for="embed-label" class="create-field-modal__label">
              Название поля <span class="required">*</span>
            </label>
            <input
              id="embed-label"
              v-model="embedForm.label"
              type="text"
              class="create-field-modal__input"
              :placeholder="isEmbedCustom ? 'Мои кнопки' : 'Поле с кнопками'"
            />
          </div>
          <div class="create-field-modal__field">
            <label for="embed-description" class="create-field-modal__label">
              Описание типа
            </label>
            <input
              id="embed-description"
              v-model="embedForm.description"
              type="text"
              class="create-field-modal__input"
              placeholder="Описание (опционально)"
            />
          </div>
          <div class="create-field-modal__field">
            <label for="embed-field-name" class="create-field-modal__label">
              Код поля
            </label>
            <input
              id="embed-field-name"
              v-model="embedForm.field_name"
              type="text"
              class="create-field-modal__input"
              placeholder="Авто (оставьте пустым)"
              maxlength="20"
            />
          </div>
          </template>

          <div class="create-field-modal__actions">
            <button
              type="button"
              class="create-field-modal__btn create-field-modal__btn--secondary"
              @click="handleCancel"
            >
              Отмена
            </button>
            <button
              type="submit"
              class="create-field-modal__btn create-field-modal__btn--primary"
              :disabled="submitting"
            >
              {{ submitting ? 'Создание...' : 'Создать' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { createUserField, createEmbedField } from '@/services/userFieldsService';

const EMBED_TYPE_CUSTOM = 'custom';

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  section: {
    type: String,
    default: '',
  },
  entityTypeId: {
    type: String,
    default: null,
  },
  typeId: {
    type: String,
    default: null,
  },
  embedTypes: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['created', 'cancel']);

const fieldMode = ref('standard');
const form = ref({
  EDIT_FORM_LABEL: '',
  FIELD_NAME: '',
  USER_TYPE_ID: 'string',
  MANDATORY: 'N',
  MULTIPLE: 'N',
  SHOW_IN_LIST: 'N',
  EDIT_IN_LIST: 'Y',
  SHOW_FILTER: 'N',
  SORT: 100,
});
const embedForm = ref({
  embed_type_id: '',
  handler_url: '',
  user_type_id: '',
  label: '',
  description: '',
  field_name: '',
});

const submitting = ref(false);
const errorMessage = ref('');

const showEmbedOption = computed(
  () =>
    props.section === 'smart' ||
    ['deal', 'lead', 'contact', 'company'].includes(String(props.section || '')) ||
    !!props.entityTypeId,
);

const isEmbedCustom = computed(() => embedForm.value.embed_type_id === EMBED_TYPE_CUSTOM);

const FIELD_NAME_REGEX = /^[A-Za-z0-9_]+$/;
const USER_TYPE_ID_REGEX = /^[a-z0-9_]{1,50}$/;

function validateStandard() {
  const label = String(form.value.EDIT_FORM_LABEL || '').trim();
  if (!label) {
    errorMessage.value = 'Укажите название поля.';
    return false;
  }
  const code = String(form.value.FIELD_NAME || '').trim();
  if (code !== '' && !FIELD_NAME_REGEX.test(code)) {
    errorMessage.value = 'Код поля может содержать только буквы A-Z, цифры 0-9 и символ _';
    return false;
  }
  if (code !== '' && code.length > 20) {
    errorMessage.value = 'Код поля не более 20 символов.';
    return false;
  }
  const sort = Number(form.value.SORT);
  if (sort < 1 || !Number.isInteger(sort)) {
    errorMessage.value = 'Сортировка должна быть целым числом больше 0.';
    return false;
  }
  return true;
}

function validateEmbed() {
  const embedTypeId = String(embedForm.value.embed_type_id || '').trim();
  if (!embedTypeId) {
    errorMessage.value = 'Выберите тип встройки.';
    return false;
  }
  const label = String(embedForm.value.label || '').trim();
  if (!label) {
    errorMessage.value = 'Укажите название поля.';
    return false;
  }
  if (embedTypeId === EMBED_TYPE_CUSTOM) {
    const handlerUrl = String(embedForm.value.handler_url || '').trim();
    if (!handlerUrl) {
      errorMessage.value = 'Укажите URL handler\'а.';
      return false;
    }
    try {
      new URL(handlerUrl);
    } catch {
      errorMessage.value = 'Некорректный формат URL.';
      return false;
    }
    const userTypeId = String(embedForm.value.user_type_id || '').trim();
    if (!userTypeId) {
      errorMessage.value = 'Укажите код типа поля (USER_TYPE_ID).';
      return false;
    }
    if (!USER_TYPE_ID_REGEX.test(userTypeId)) {
      errorMessage.value = 'Код типа поля: только a-z, 0-9, _, макс. 50 символов.';
      return false;
    }
  }
  return true;
}

function validate() {
  errorMessage.value = '';
  return fieldMode.value === 'embed' ? validateEmbed() : validateStandard();
}

function resetForm() {
  form.value = {
    EDIT_FORM_LABEL: '',
    FIELD_NAME: '',
    USER_TYPE_ID: 'string',
    MANDATORY: 'N',
    MULTIPLE: 'N',
    SHOW_IN_LIST: 'N',
    EDIT_IN_LIST: 'Y',
    SHOW_FILTER: 'N',
    SORT: 100,
  };
  embedForm.value = {
    embed_type_id: '',
    handler_url: '',
    user_type_id: '',
    label: '',
    description: '',
    field_name: '',
  };
  fieldMode.value = 'standard';
  errorMessage.value = '';
}

function handleCancel() {
  if (!submitting.value) {
    emit('cancel');
  }
}

async function handleSubmit() {
  if (!validate()) {
    return;
  }

  submitting.value = true;
  errorMessage.value = '';

  try {
    if (fieldMode.value === 'embed') {
      const payload = {
        label: String(embedForm.value.label || '').trim(),
        description: String(embedForm.value.description || '').trim(),
        field_name: String(embedForm.value.field_name || '').trim() || undefined,
      };
      if (embedForm.value.embed_type_id === EMBED_TYPE_CUSTOM) {
        payload.handler_url = String(embedForm.value.handler_url || '').trim();
        payload.user_type_id = String(embedForm.value.user_type_id || '').trim();
      } else {
        payload.embed_type_id = String(embedForm.value.embed_type_id || '').trim();
      }
      const embedPayload = { ...payload };
      if (props.section === 'smart') {
        const eid = props.entityTypeId != null && props.entityTypeId !== '' ? String(props.entityTypeId) : null;
        const tid = props.typeId != null && props.typeId !== '' ? String(props.typeId) : null;
        if (tid) embedPayload.typeId = tid;
        if (eid) embedPayload.entityTypeId = eid;
        if (!tid && !eid) {
          errorMessage.value = 'Для смарт-процесса не указан typeId или entityTypeId. Выберите раздел заново.';
          submitting.value = false;
          return;
        }
      }
      await createEmbedField(props.section, embedPayload);
    } else {
      const fields = {
        USER_TYPE_ID: form.value.USER_TYPE_ID,
        EDIT_FORM_LABEL: String(form.value.EDIT_FORM_LABEL || '').trim(),
        MANDATORY: form.value.MANDATORY,
        MULTIPLE: form.value.MULTIPLE,
        SHOW_IN_LIST: form.value.SHOW_IN_LIST,
        EDIT_IN_LIST: form.value.EDIT_IN_LIST,
        SHOW_FILTER: form.value.SHOW_FILTER,
        SORT: Math.max(1, Number(form.value.SORT) || 100),
      };
      if (form.value.FIELD_NAME && String(form.value.FIELD_NAME).trim() !== '') {
        fields.FIELD_NAME = String(form.value.FIELD_NAME).trim();
      }
      await createUserField(
        props.section,
        fields,
        props.entityTypeId || null,
        props.typeId || null,
      );
    }
    resetForm();
    emit('created');
  } catch (err) {
    errorMessage.value = err?.message || 'Ошибка создания поля.';
  } finally {
    submitting.value = false;
  }
}

watch(
  () => props.visible,
  (v) => {
    if (v) {
      resetForm();
    }
  },
);
</script>

<style scoped>
.create-field-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.create-field-modal {
  background: var(--b24-card-bg);
  border-radius: 12px;
  box-shadow: 0 8px 40px rgba(0, 0, 0, 0.15);
  width: 90%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
}

.create-field-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid var(--b24-border);
}

.create-field-modal__title {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
}

.create-field-modal__close {
  background: none;
  border: none;
  font-size: 24px;
  line-height: 1;
  cursor: pointer;
  color: var(--b24-text-muted);
  padding: 0 4px;
}

.create-field-modal__close:hover {
  color: var(--b24-text);
}

.create-field-modal__form {
  padding: 20px;
}

.create-field-modal__error {
  background: #fef2f2;
  color: var(--b24-danger);
  padding: 10px 12px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 16px;
}

.create-field-modal__field {
  margin-bottom: 16px;
}

.create-field-modal__label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 6px;
}

.required {
  color: var(--b24-danger);
}

.create-field-modal__input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--b24-border);
  border-radius: 6px;
  font-size: 14px;
}

.create-field-modal__input:focus {
  outline: none;
  border-color: var(--b24-primary);
}

.create-field-modal__select {
  cursor: pointer;
}

.create-field-modal__mode-switch {
  padding-bottom: 12px;
  margin-bottom: 12px;
  border-bottom: 1px solid var(--b24-border);
}

.create-field-modal__radio-group {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-top: 8px;
}

.create-field-modal__radio {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  cursor: pointer;
}

.create-field-modal__radio input {
  width: 18px;
  height: 18px;
}

.create-field-modal__hint {
  display: block;
  font-size: 12px;
  color: var(--b24-text-muted);
  margin-top: 4px;
}

.create-field-modal__row {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  margin-bottom: 16px;
}

.create-field-modal__checkbox {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  cursor: pointer;
}

.create-field-modal__checkbox input {
  width: 18px;
  height: 18px;
}

.create-field-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--b24-border);
}

.create-field-modal__btn {
  padding: 10px 20px;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
  border: 1px solid var(--b24-border);
}

.create-field-modal__btn--primary {
  background: var(--b24-primary);
  color: white;
  border-color: var(--b24-primary);
}

.create-field-modal__btn--primary:hover:not(:disabled) {
  opacity: 0.9;
}

.create-field-modal__btn--primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.create-field-modal__btn--secondary {
  background: var(--b24-card-bg);
}

.create-field-modal__btn--secondary:hover {
  border-color: var(--b24-primary);
  color: var(--b24-primary);
}
</style>
