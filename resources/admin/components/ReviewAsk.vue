<script>
/**
 * The review ask — one quiet card on the plugin's own Dashboard, shown only
 * after the plugin has earned it (a week present, a few visits, at least one
 * real action taken through the plugin, a readiness score over the bar — the
 * server decides; see Review::eligible()). Never a site-wide notice.
 *
 * Manners: it asks once, and every button puts it away. "Maybe later" brings
 * it back in a month; "Write a review" and "I already did" close it for good —
 * and the admin footer's rating line goes quiet with them. Nothing is sent
 * anywhere: the button is a plain link to the WordPress.org review form.
 */
export default {
  name: 'ReviewAsk',
  props: {
    data: { type: Object, required: true }, // { url }
    api: { type: Object, default: null },
  },
  emits: ['dismiss'],
  data() {
    return { answering: false };
  },
  methods: {
    async answer(kind) {
      if (this.answering) return;
      this.answering = true;
      try {
        if (this.api) await this.api.reviewAck(kind);
      } catch (e) { /* answering is best-effort — never block the click on a failed write */ }
      this.$emit('dismiss');
    },
    writeReview() {
      window.open(this.data.url, '_blank', 'noopener');
      this.answer('review');
    },
  },
};
</script>

<template>
  <section class="ar-card ar-reviewask" aria-label="Review request">
    <div class="ar-reviewask__body">
      <span class="ar-reviewask__stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
      <div>
        <h2 class="ar-reviewask__title">Has Agentimus earned a review?</h2>
        <p class="ar-reviewask__text">
          It has been working on this site for a while now, and your readiness score says
          it is doing its job. If you agree, a short review on WordPress.org helps other
          site owners find it — and whatever you choose, this card will not keep asking.
        </p>
      </div>
    </div>
    <div class="ar-reviewask__actions">
      <button type="button" class="ar-btn ar-newtab" :disabled="answering" @click="writeReview">Write a review</button>
      <button type="button" class="ar-linkbtn ar-linkbtn--act" :disabled="answering" @click="answer('done')">I already did</button>
      <button type="button" class="ar-linkbtn ar-linkbtn--act" :disabled="answering" @click="answer('later')">Maybe later</button>
    </div>
  </section>
</template>
