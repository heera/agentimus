/**
 * Clicking the footer's rating stars ends the rating ask, like answering the Dashboard's
 * prompt, so somebody who has just gone to rate is not asked again. The footer sits
 * outside the Vue app, hence a listener on the document. The click is never cancelled, so
 * the review page still opens, and a failed save only means the ask stays a little longer.
 *
 * @param {Document} doc The document.
 * @param {function(string): Promise} ack Records the answer.
 */
export function bindRatingLink(doc, ack) {
  doc.addEventListener('click', (event) => {
    const target = event.target;
    if (!target || !target.closest || !target.closest('.agentimus-rating-link')) return;
    Promise.resolve(ack('review')).catch(() => {});
  });
}
