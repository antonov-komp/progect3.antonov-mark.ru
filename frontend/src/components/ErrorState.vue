<template>
  <div class="app-error b24-alert b24-alert-danger" role="alert">
    {{ message }}
  </div>
</template>

<script setup>
defineProps({
  message: {
    type: String,
    required: true,
  },
});
</script>
