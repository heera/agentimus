import { createApp } from 'vue';
import App from './App.vue';
import './app.css';

const mount = document.getElementById('heera-agent-discovery-app');

if (mount) {
  // Data injected by wp_localize_script (Admin::bootstrap_data()).
  const boot = window.HeeraAgentDiscoveryData || {};
  createApp(App, { boot }).mount(mount);
}
