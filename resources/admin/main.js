import { createApp } from 'vue';
import App from './App.vue';
import { tip } from './tip.js';
import { initTheme } from './theme.js';
import './app.css';

const mount = document.getElementById('agentimus-app');

// Resolve light/dark/system before the app mounts, so the first paint is
// already in the right mode — no flash of the wrong theme.
initTheme();

if (mount) {
  // Data injected by wp_localize_script (Admin::bootstrap_data()).
  const boot = window.AgentimusData || {};
  const app = createApp(App, { boot });
  app.directive('tip', tip); // v-tip="…" — the custom tooltip, replacing native title="…".
  app.mount(mount);
}
