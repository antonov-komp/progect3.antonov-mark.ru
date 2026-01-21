import { createRouter, createWebHashHistory } from 'vue-router';
import HomeView from '@/views/HomeView.vue';
import ModuleStubView from '@/views/ModuleStubView.vue';

const routes = [
  {
    path: '/',
    name: 'home',
    component: HomeView,
  },
  {
    path: '/modules/:moduleKey',
    name: 'module',
    component: ModuleStubView,
  },
];

export const router = createRouter({
  history: createWebHashHistory(),
  routes,
});
