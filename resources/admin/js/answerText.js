/**
 * An engine's answer, read as prose.
 *
 * Assistants answer in Markdown whether or not anyone asked them to, so a
 * stored answer arrives full of syntax: `**Sheikh Heera**`, `### 1.`, backticked
 * `llms.txt`, `* **Role:**`. Shown raw in a hover bubble that has no way to
 * render any of it, the punctuation is just noise between the words — his catch,
 * 2026-08-24, on the citation chips.
 *
 * ⛔ Stripped for DISPLAY only. What the engine actually said stays in the
 * database exactly as it said it — that text is the evidence behind a verdict,
 * and rewriting evidence to make it prettier is not ours to do.
 *
 * Deliberately small: this un-marks the handful of constructs an answer really
 * uses. It is not a Markdown parser, and nothing here renders HTML — the result
 * is plain text, for a `textContent` bubble or for text nodes in a dialog.
 *
 * Two readings of the same words: {@see answerText} for a hover bubble (one
 * line), {@see answerParagraphs} for the answer dialog (keeps the shape).
 */

function unmark(raw) {
  let text = String(raw == null ? '' : raw);
  if (!text) return '';

  // Fenced code: keep the code, drop the fences (and any language tag).
  text = text.replace(/^[ \t]*```[^\n]*$/gm, '');

  // Block syntax, line by line: headings, quote markers, bullets, thematic
  // breaks. A bullet becomes "· " so a list still reads as a list once the
  // newlines collapse below.
  text = text
    .replace(/^[ \t]*#{1,6}[ \t]+/gm, '')
    // A heading that has already lost its line break — two or more hashes
    // mid-sentence are never prose, while a lone "#" can be ("the # sign").
    .replace(/(\s)#{2,6}[ \t]+/g, '$1')
    .replace(/^[ \t]*>[ \t]?/gm, '')
    .replace(/^[ \t]*(?:[-*_][ \t]*){3,}$/gm, '')
    .replace(/^[ \t]*[-*+][ \t]+/gm, '· ');

  // Links and images: keep the words, drop the address.
  text = text.replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1');

  // Emphasis and inline code. Bold before italic so `**x**` doesn't leave a
  // stray pair, and each marker must actually wrap something: the opening one
  // starts a word, the closing one ends one.
  //
  // ⚠️ The closing marker is followed by any NON-WORD character, not a listed
  // few. Listing them missed "*FluentAuth*—which" — an em dash was not on the
  // list, so the asterisks stayed on screen. His real run found it, 2026-08-24.
  // The word-boundary guards are what keep "5 * 3" and "snake_case_name" whole.
  text = text
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/__([^_]+)__/g, '$1')
    .replace(/(^|[^\w*])\*(?=\S)([^*\n]*[^\s*])\*(?=[^\w*]|$)/g, '$1$2')
    .replace(/(^|[^\w_])_(?=\S)([^_\n]*[^\s_])_(?=[^\w_]|$)/g, '$1$2')
    .replace(/`([^`]+)`/g, '$1');

  // Tidy the spacing WITHIN each line, keeping the line breaks — what reads as
  // one paragraph in a bubble is still a shape in a dialog that has room for it.
  return text
    .split('\n')
    .map((line) => line.replace(/[ \t]+/g, ' ').trim())
    .join('\n')
    .trim();
}

/**
 * One line of prose, for a hover bubble: a paragraph break that survives as a
 * newline just leaves a hole in a tooltip.
 */
export function answerText(raw) {
  return unmark(raw).replace(/\s+/g, ' ').trim();
}

/**
 * The same words as paragraphs, for a surface with room to read them — the
 * answer dialog. Blank lines separate paragraphs; a heading or a bullet is a
 * paragraph of its own, which is what it was before the markers came off.
 *
 * ⛔ Plain strings, never HTML. This text comes from a third party and is
 * rendered as text nodes; there is no sanitiser to get wrong.
 */
export function answerParagraphs(raw) {
  return unmark(raw)
    .split(/\n+/)
    .map((line) => line.trim())
    .filter(Boolean);
}
