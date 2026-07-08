// The readiness ladder. The report runs the same flat list of checks it always
// has; this groups them into three plain-English rungs an agent climbs in order —
// Findable (can it crawl you) → Readable (does it get clean content) → Trusted
// (identity + trust signals: who you are, a contact, your usage policy) — plus a
// "not reachable" floor when the site is hidden from crawlers entirely. No checks
// are added here; it is purely how they're framed.

export const RUNGS = [
  {
    key: 'findable',
    label: 'Findable',
    blurb: 'An agent can crawl and navigate your site.',
    ids: ['public', 'permalinks', 'robots', 'sitemap', 'robots_sitemap'],
  },
  {
    key: 'readable',
    label: 'Readable',
    blurb: 'What it crawls comes back clean and structured.',
    ids: ['llms', 'llms_words', 'llms_full', 'llms_full_size', 'schema', 'post_types', 'topics'],
  },
  {
    key: 'trusted',
    label: 'Trusted',
    blurb: 'An agent can identify you, trust the source, and attribute it with confidence.',
    ids: ['about', 'expertise', 'same_as', 'entity_image', 'entity_role', 'security_txt', 'ai_usage'],
  },
];

// Order checks so the ones still needing attention float to the top of a group.
const RANK = { fail: 0, warn: 1, pass: 2 };
const byStatus = (a, b) => (RANK[a.status] ?? 3) - (RANK[b.status] ?? 3);

// A rung's overall status is its worst check: a fail trumps a warn trumps pass.
// Drives the header's accent colour (dot, divider, count).
function rungStatus(items) {
  if (items.some((c) => c.status === 'fail')) return 'fail';
  if (items.some((c) => c.status === 'warn')) return 'warn';
  return 'pass';
}

// Group the report's checks under the rungs. Anything a Pro add-on appended that
// we don't map falls into a trailing "More checks" group rather than vanishing.
export function groupChecks(checks) {
  const list = Array.isArray(checks) ? checks : [];
  const byId = {};
  list.forEach((c) => { if (c && c.id) byId[c.id] = c; });

  const seen = new Set();
  const groups = RUNGS.map((r) => {
    const items = r.ids.map((id) => byId[id]).filter(Boolean);
    items.forEach((c) => seen.add(c.id));
    const pass = items.filter((c) => c.status === 'pass').length;
    return {
      key: r.key,
      label: r.label,
      blurb: r.blurb,
      items: items.slice().sort(byStatus),
      pass,
      total: items.length,
      complete: items.length > 0 && pass === items.length,
      status: rungStatus(items),
    };
  });

  const extra = list.filter((c) => c && c.id && !seen.has(c.id)).sort(byStatus);
  if (extra.length) {
    const pass = extra.filter((c) => c.status === 'pass').length;
    groups.push({
      key: 'more',
      label: 'More checks',
      blurb: '',
      items: extra,
      pass,
      total: extra.length,
      complete: pass === extra.length,
    });
  }
  return groups;
}

// Reduce the grouped checks to the one-line standing the rail badge shows: the
// rung you've reached, the next rung, and the single next thing to do. A site that
// discourages search engines sits on the floor — nothing below can be read until
// that's fixed — so it gets its own headline rather than just "Findable 0/5".
export function summarize(checks) {
  const list = Array.isArray(checks) ? checks : [];
  const pub = list.find((c) => c && c.id === 'public');
  const floor = !!(pub && pub.status === 'fail');

  // Only the three real rungs count toward the ladder; the "more" group doesn't.
  const rungs = groupChecks(list).filter((g) => g.key !== 'more');

  // Achieved = the highest rung that's complete with every rung below it complete
  // too — you climb from the bottom up, so a gap low down caps you there.
  let achievedIndex = -1;
  for (let i = 0; i < rungs.length; i += 1) {
    if (rungs[i].complete) achievedIndex = i;
    else break;
  }
  const nextIndex = rungs.findIndex((g) => !g.complete);
  const topped = rungs.length > 0 && nextIndex === -1;
  const achieved = achievedIndex >= 0 ? rungs[achievedIndex] : null;
  const next = nextIndex >= 0 ? rungs[nextIndex] : null;

  return {
    floor,
    topped,
    achieved: achieved ? { key: achieved.key, label: achieved.label } : null,
    next: next
      ? {
          key: next.key,
          label: next.label,
          remaining: next.items
            .filter((c) => c.status !== 'pass')
            .map((c) => ({ id: c.id, label: c.label })),
        }
      : null,
    // The pip strip: every rung with its tally and display state.
    rungs: rungs.map((g, i) => ({
      key: g.key,
      label: g.label,
      pass: g.pass,
      total: g.total,
      state: g.complete ? 'done' : (!topped && i === nextIndex ? 'current' : 'todo'),
    })),
  };
}
