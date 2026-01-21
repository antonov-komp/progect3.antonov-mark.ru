import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig({
  plugins: [vue()],
  base: '/vue/',
  build: {
    outDir: path.resolve(__dirname, '../public/vue'),
    emptyOutDir: false,
    rollupOptions: {
      input: path.resolve(__dirname, 'src/app/main.js'),
      output: {
        entryFileNames: 'app.js',
        assetFileNames: 'app.[ext]',
      },
    },
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
});
