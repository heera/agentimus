import { reactive } from 'vue';

// One app-wide confirmation prompt — the styled replacement for window.confirm().
// Call confirm({ ... }) from anywhere; it returns a Promise<boolean> that resolves
// true when the user confirms and false on cancel / Esc / backdrop dismiss. The
// single <ConfirmDialog /> mounted at the app root renders whatever is queued here.
export const confirmState = reactive({
  open: false,
  title: 'Are you sure?',
  message: '',
  confirmLabel: 'Confirm',
  cancelLabel: 'Cancel',
  tone: 'default', // 'default' | 'danger'
  within: null, // optional CSS selector — center the dialog inside that element
  _resolve: null,
});

function settle(result) {
  const done = confirmState._resolve;
  confirmState.open = false;
  confirmState._resolve = null;
  if (done) done(result);
}

export function confirm(options = {}) {
  // If a prompt is already open, dismiss it (resolve false) before opening the new
  // one so we never leak a dangling promise.
  if (confirmState.open) settle(false);
  confirmState.title = options.title || 'Are you sure?';
  confirmState.message = options.message || '';
  confirmState.confirmLabel = options.confirmLabel || 'Confirm';
  confirmState.cancelLabel = options.cancelLabel || 'Cancel';
  confirmState.tone = options.tone || 'default';
  confirmState.within = options.within || null;
  confirmState.open = true;
  return new Promise((resolve) => {
    confirmState._resolve = resolve;
  });
}

// Used by <ConfirmDialog /> to answer the prompt.
export const resolveConfirm = settle;
