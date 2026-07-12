<script>
import { confirmState, resolveConfirm } from '../confirm.js';

// The single styled confirmation dialog. Mount it ONCE at the app root; it renders
// whatever confirm() has queued in the shared store. Reuses the .ar-modal shell so
// it matches the day-report and reset dialogs exactly.
import { bindDocEsc } from '../docEsc.js';

export default {
  name: 'ConfirmDialog',
  data() {
    return { state: confirmState };
  },
  watch: {
    'state.open'(open) {
      if (this._unEsc) this._unEsc();
      this._unEsc = open ? bindDocEsc(() => this.cancel()) : null;
      if (!open) return;
      // For a destructive prompt, land focus on Cancel so a reflexive Enter is safe;
      // otherwise land on Confirm so Enter accepts (preserving window.confirm muscle
      // memory). Esc always cancels.
      this.$nextTick(() => {
        const safe = this.state.tone === 'danger' ? this.$refs.cancelBtn : this.$refs.confirmBtn;
        if (safe) safe.focus();
      });
    },
  },
  methods: {
    confirm() {
      resolveConfirm(true);
    },
    cancel() {
      resolveConfirm(false);
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <transition name="ar-modal">
      <div v-if="state.open" class="ar-modal" @click.self="cancel">
        <div
          class="ar-modal__panel ar-modal__panel--confirm"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="ar-confirm-title"
          :aria-describedby="state.message ? 'ar-confirm-msg' : null"
          @keydown.esc="cancel"
        >
          <div class="ar-modal__head">
            <h2 id="ar-confirm-title" class="ar-modal__title">{{ state.title }}</h2>
            <p v-if="state.message" id="ar-confirm-msg" class="ar-modal__lead">{{ state.message }}</p>
          </div>
          <div class="ar-modal__actions">
            <button ref="cancelBtn" type="button" class="ar-btn ar-btn--ghost" @click="cancel">
              {{ state.cancelLabel }}
            </button>
            <button
              ref="confirmBtn"
              type="button"
              class="ar-btn"
              :class="{ 'ar-btn--danger': state.tone === 'danger' }"
              @click="confirm"
            >
              {{ state.confirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
