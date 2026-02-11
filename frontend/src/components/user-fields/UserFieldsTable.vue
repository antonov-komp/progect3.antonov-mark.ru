<template>
  <section class="user-fields-table-section">
    <div class="user-fields-table-section__header">
      <h2 class="user-fields-table-section__title">Пользовательские поля</h2>
      <button type="button" class="user-fields-table-section__back" @click="$emit('back')">
        Назад к разделам
      </button>
    </div>

    <LoadingState v-if="loading" />

    <ErrorState v-else-if="error" :message="error" />

    <div v-else-if="fields.length === 0" class="user-fields-table-section__empty">
      Поля не найдены.
    </div>

    <div v-else class="user-fields-table-wrapper">
      <table class="user-fields-table">
        <thead>
          <tr>
            <th>Название поля</th>
            <th>Код поля</th>
            <th>Тип</th>
            <th>ID</th>
            <th>Обязательное</th>
            <th>Множественное</th>
            <th>Сортировка</th>
            <th>В списке</th>
            <th>Ред. в списке</th>
            <th>В фильтре</th>
            <th>Поиск</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="field in fields" :key="field.ID || field.FIELD_NAME">
            <td>{{ field.TITLE || field.FIELD_NAME || field.fieldName }}</td>
            <td><code>{{ field.FIELD_NAME || field.fieldName }}</code></td>
            <td>{{ field.USER_TYPE_ID || field.userTypeId }}</td>
            <td>{{ field.ID || field.id }}</td>
            <td>{{ field.MANDATORY === 'Y' ? 'Да' : 'Нет' }}</td>
            <td>{{ field.MULTIPLE === 'Y' ? 'Да' : 'Нет' }}</td>
            <td>{{ field.SORT }}</td>
            <td>{{ field.SHOW_IN_LIST === 'Y' ? 'Да' : 'Нет' }}</td>
            <td>{{ field.EDIT_IN_LIST === 'Y' ? 'Да' : 'Нет' }}</td>
            <td>{{ field.SHOW_FILTER || '—' }}</td>
            <td>{{ field.IS_SEARCHABLE === 'Y' ? 'Да' : 'Нет' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<script setup>
import ErrorState from '@/components/ErrorState.vue';
import LoadingState from '@/components/LoadingState.vue';

defineProps({
  fields: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: '',
  },
});

defineEmits(['back']);
</script>

<style scoped>
.user-fields-table-section {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.user-fields-table-section__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}

.user-fields-table-section__title {
  font-size: 18px;
  font-weight: 600;
  margin: 0;
}

.user-fields-table-section__back {
  border: 1px solid var(--b24-border);
  background: var(--b24-card-bg);
  padding: 8px 14px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
}

.user-fields-table-section__back:hover {
  border-color: var(--b24-primary);
  color: var(--b24-primary);
}

.user-fields-table-section__empty {
  font-size: 14px;
  color: var(--b24-text-muted);
  padding: 20px;
}

.user-fields-table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--b24-border);
  border-radius: 8px;
  background: var(--b24-card-bg);
}

.user-fields-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.user-fields-table th,
.user-fields-table td {
  padding: 10px 12px;
  text-align: left;
  border-bottom: 1px solid var(--b24-border);
}

.user-fields-table th {
  background: #f8fafc;
  font-weight: 600;
  white-space: nowrap;
}

.user-fields-table tbody tr:hover {
  background: #f8fafc;
}

.user-fields-table code {
  font-size: 12px;
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
}
</style>
