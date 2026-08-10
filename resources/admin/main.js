import { createApp } from 'vue';
import App from './App.vue';
import { tip } from './tip.js';
import './app.css';

const mount = document.getElementById('agentimus-app');

if (mount) {
  // Data injected by wp_localize_script (Admin::bootstrap_data()).
  const boot = window.AgentimusData || {};
  const app = createApp(App, { boot });
  app.directive('tip', tip); // v-tip="…" — the custom tooltip, replacing native title="…".
  app.mount(mount);
}
