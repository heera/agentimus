import { createApp } from 'vue';
import App from './App.vue';
import './app.css';

const mount = document.getElementById('agentimus-app');

if (mount) {
  // Data injected by wp_localize_script (Admin::bootstrap_data()).
  const boot = window.AgentimusData || {};
  createApp(App, { boot }).mount(mount);
}
