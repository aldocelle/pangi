import { createApp } from 'vue';
import { createHead } from '@unhead/vue/client';
import App from './App.vue';
import router from './router.js';
import { registrarSEO } from './seo.js';
import { registrarAnalytics } from './analytics.js';
import './assets/app.css';

const app = createApp(App);
const head = createHead();

app.use(head);
app.use(router);
registrarSEO(router, head);
registrarAnalytics(router);

app.mount('#app');
