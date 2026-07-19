// One outline icon per tab-group headline (the serif caption under a tab strip) —
// shared by the Settings groups and the AI Visibility views. Inline SVG (WP.org: no
// external assets), 24px grid, bold 2px stroke so the mark carries the headline's
// weight. Static strings rendered via v-html; nothing user-supplied passes through.
const OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';

const GROUP_ICONS = {
  // Settings groups
  identity: OPEN + '<circle cx="12" cy="8" r="3.6"/><path d="M4.8 20.2c0-3.8 3.3-6 7.2-6s7.2 2.2 7.2 6"/></svg>',
  discovery: OPEN + '<circle cx="12" cy="12" r="8.6"/><polygon points="15.5 8.5 13.7 13.7 8.5 15.5 10.3 10.3"/></svg>',
  access: OPEN + '<path d="M11 4.2l1.6 4.4 4.4 1.6-4.4 1.6L11 16.2 9.4 11.8 5 10.2l4.4-1.6L11 4.2z"/><path d="M18.3 15.7v4M16.3 17.7h4"/></svg>',
  exposure: OPEN + '<path d="M12 3.4l6.8 2.7v5c0 4.6-3.2 7.9-6.8 9.3-3.6-1.4-6.8-4.7-6.8-9.3v-5z"/></svg>',
  advanced: OPEN + '<path d="M4.2 7.5h8M17.8 7.5h2M4.2 16.5h2M11.8 16.5h8"/><circle cx="14.8" cy="7.5" r="2.3"/><circle cx="8.2" cy="16.5" r="2.3"/></svg>',
  // AI Visibility views
  results: OPEN + '<path d="M4.5 19.5h15"/><path d="M7.5 15.5v-5M12 15.5v-9M16.5 15.5v-3"/></svg>',
  settings: OPEN + '<path d="M4.2 7.5h8M17.8 7.5h2M4.2 16.5h2M11.8 16.5h8"/><circle cx="14.8" cy="7.5" r="2.3"/><circle cx="8.2" cy="16.5" r="2.3"/></svg>',
};

export function groupIcon(key) {
  return GROUP_ICONS[key] || '';
}
