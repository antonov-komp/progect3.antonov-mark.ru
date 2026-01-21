<template>
  <div class="app-greeting b24-title">
    {{ greeting }}
  </div>
</template>

<script setup>
defineProps({
  greeting: {
    type: String,
    required: true,
  },
});
</script>
