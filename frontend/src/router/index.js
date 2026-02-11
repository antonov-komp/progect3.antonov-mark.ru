import { createRouter, createWebHashHistory } from 'vue-router';
import HomeView from '@/views/HomeView.vue';
import ModuleStubView from '@/views/ModuleStubView.vue';
import UserFieldsView from '@/views/UserFieldsView.vue';

const routes = [
  {
    path: '/',
    name: 'home',
    component: HomeView,
  },
  {
    path: '/user-fields',
    name: 'user-fields',
    component: UserFieldsView,
    meta: { section: null },
  },
  {
    path: '/user-fields/smart/:entityTypeId',
    name: 'user-fields-smart',
    component: UserFieldsView,
    meta: { section: 'smart' },
  },
  {
    path: '/user-fields/:section',
    name: 'user-fields-section',
    component: UserFieldsView,
    meta: { section: null },
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
