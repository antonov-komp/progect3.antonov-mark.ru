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
import { ref, watch } from 'vue';
import { createUserField } from '@/services/userFieldsService';

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
});

const emit = defineEmits(['created', 'cancel']);

const form = ref({
  EDIT_FORM_LABEL: '',
  FIELD_NAME: '',
  USER_TYPE_ID: 'string',
  MANDATORY: 'N',
  MULTIPLE: 'N',
  SHOW_IN_LIST: 'N',
  SHOW_FILTER: 'N',
  SORT: 100,
});

const submitting = ref(false);
const errorMessage = ref('');

const FIELD_NAME_REGEX = /^[A-Za-z0-9_]+$/;

function validate() {
  errorMessage.value = '';

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

function resetForm() {
  form.value = {
    EDIT_FORM_LABEL: '',
    FIELD_NAME: '',
    USER_TYPE_ID: 'string',
    MANDATORY: 'N',
    MULTIPLE: 'N',
    SHOW_IN_LIST: 'N',
    SHOW_FILTER: 'N',
    SORT: 100,
  };
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

  const fields = {
    USER_TYPE_ID: form.value.USER_TYPE_ID,
    EDIT_FORM_LABEL: String(form.value.EDIT_FORM_LABEL || '').trim(),
    MANDATORY: form.value.MANDATORY,
    MULTIPLE: form.value.MULTIPLE,
    SHOW_IN_LIST: form.value.SHOW_IN_LIST,
    SHOW_FILTER: form.value.SHOW_FILTER,
    SORT: Math.max(1, Number(form.value.SORT) || 100),
  };

  if (form.value.FIELD_NAME && String(form.value.FIELD_NAME).trim() !== '') {
    fields.FIELD_NAME = String(form.value.FIELD_NAME).trim();
  }

  try {
    await createUserField(
      props.section,
      fields,
      props.entityTypeId || null,
      props.typeId || null,
    );
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
