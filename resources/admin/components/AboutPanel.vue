<script>
import { copyText } from '../js/clipboard.js';
import SelectMenu from './SelectMenu.vue';

export default {
  name: 'AboutPanel',
  components: { SelectMenu },
  props: {
    // { facts, factsLean, … } — the same block the report dialog attaches, so
    // the two can never describe the site differently.
    support: { type: Object, default: () => ({}) },
    version: { type: String, default: '' },
    // { name, version, hook, specUrl, schemaUrl } — sourced from PHP so it
    // mirrors the real constants instead of hand-copied strings.
    protocol: { type: Object, default: () => ({}) },
    // Whether the About tab is the one on screen. The panel stays MOUNTED on
    // other tabs (App uses v-show), so anything it teleports into the shared
    // page header — and the scrollspy behind it — must gate on this, or the
    // jump menu haunts every other screen's header.
    active: { type: Boolean, default: true },
  },
  emits: ['navigate'],
  data() {
    return {
      openFaq: 0,
      setupCopied: false,
      // The "On this page" menu doubles as a position indicator: this holds the
      // anchor of the section currently under the header, kept in sync by the
      // scrollspy — scrolling moves it, picking from the menu jumps to it.
      tocCurrent: '',
      // True while a picked jump is smooth-scrolling: the spy stands down so
      // the label shows the DESTINATION, not every section flying past.
      spyLock: false,
      // The jump menu teleports into the sticky page header. On the panel's
      // INITIAL mount (a direct #about load) the header target isn't in the
      // document yet — a Teleport that mounts then silently renders nothing —
      // so it waits for this flag, flipped in mounted(), when everything is.
      teleportReady: false,
      // The working manual, screen by screen — kept in the SAME order as the
      // nav (the bar tabs left to right, then the More menu top to bottom,
      // Settings in its meta group last), so the manual reads like the
      // plugin walks.
      screens: [
        {
          id: 'dashboard',
          title: 'Dashboard',
          where: 'first tab',
          purpose: 'The day’s answer at a glance: a Today line across the top — what AI read, who arrived from an AI answer and what acted here, since midnight — then your AEO/GEO score with the single most useful next step, the “What Your Site Runs” card — every Agentimus system’s standing in four panels (how AI assistants reach you, what your site tells the engines, what search shows, and what your writing holds), each led by one number and linking to its full screen — who reached your site (people and machines, counted apart), address activity by day, your busiest AI files, and recent requests. The one-time “Worth a look next” card after setup and the once-per-release “What’s new” card live here too — never anywhere else in wp-admin.',
          actions: 'Click a day’s bar for that day’s full report. Follow the score card’s “Next:” line straight to the thing it names. Answer the review bell when a new client wants a verdict. Tiles drill into their report screens with the filter carried along.',
          facts: [
            { k: 'info', t: 'The score’s Cited rung only counts when citation tracking is on; otherwise its weight is redistributed, so you are never penalised for a feature you don’t use.' },
            { k: 'info', t: 'A blocked verdict on the score card means one thing: WordPress’s “Discourage search engines” switch is on. Nothing else scores until that master switch does — the card links the fix.' },
            { k: 'tip', t: 'On a local site that verdict reads “Not public yet” in calm amber, not red — being unreachable is the expected state of a development site, and it only matters at launch.' },
            { k: 'info', t: 'Nothing you see here is ever frozen: every data screen quietly re-reads its numbers each time you come back to it, every data card carries a small circular refresh beside its title, and the bell’s Live updates switch adds a 15-second auto-refresh on top — one that pauses by itself when the tab is hidden or you have been away a while.' },
          ],
        },
        {
          id: 'readiness',
          title: 'Readiness',
          where: 'Readiness tab',
          purpose: 'Every check the score is built from, grouped by rung — Findable, Readable, Trusted — each row saying what it found, what to do, and where to do it. Below them, Optimize grades each page’s citability, and Agent preview shows the exact JSON-LD and Markdown any page serves to a machine.',
          actions: 'Work the rows: each warn or fail carries plain-words advice and a link that lands on the exact field or screen that fixes it. Run “Verify live” to fetch your own files the way an AI assistant would. Scan for exposed files. In Optimize, open a page to fix it in the editor, or set it aside as not-cited content — counted visibly, never hidden.',
          facts: [
            { k: 'info', t: 'Advice is state-aware. A thin llms.txt points at your Identity settings only until your profile is in the file — after that it points at publishing content, because that is the only lever left.' },
            { k: 'info', t: 'Search Opportunities used to live here; it now sits on Visibility → Search, directly under the search report it is carved from. Readiness keeps a pointer card with its live count, and every old link still lands.' },
            { k: 'info', t: 'The “Verify live” fetches are deliberately anonymous — they grade what an AI assistant receives, not what an admin sees — and they carry a signed token so they stay out of your own Request Log.' },
            { k: 'warn', t: '“Could not verify” is informational, never a verdict against your site: probe data fails open, and a stale good result outranks a fresh failure to fetch.' },
            { k: 'info', t: 'Checks read stored data; the llms.txt and home-page probes run in the background twice a day (and after plugin or theme changes), so a route silently taken over by another plugin is noticed without slowing your admin.' },
            { k: 'tip', t: 'Fixes made in another tab — a site icon in the Customizer, Reading settings — show up here on return, within moments, without a reload.' },
          ],
        },
        {
          id: 'discovery',
          title: 'Discovery',
          where: 'Discovery tab',
          purpose: 'The registry of everything your site publishes for machines — llms.txt and the full-text edition, discovery.json, the agent card, mcp.json — with live links and a validity check across every registration.',
          actions: 'Open any document to read exactly what AI assistants read, and expand any provider group under MCP & Tools to see the tools it contributes, by name. Re-scan after changing plugins. Third-party plugins that speak the WP_Discovery protocol appear here alongside Agentimus’s own surfaces.',
          facts: [
            { k: 'info', t: 'A 404 on a discovery URL can be by design: some documents only exist while their feature is on, so an absent file often means “switched off”, not “broken”. The validity line tells the difference.' },
            { k: 'info', t: 'With signing on, the discovery documents carry RFC 9421 signatures — an AI assistant can verify they really came from your server, unaltered.' },
            { k: 'info', t: 'The registry is open: any plugin can register its machine surfaces with one hook (the snippet is in the protocol section below), and they get listed and validated like the built-ins.' },
            { k: 'info', t: 'Two documents are written for AI assistants to act on, not just index: auth.md explains how to sign in — rebuilt from your live settings on every request, so it cannot drift from what the server really does — and the agent skill under /.well-known/agent-skills packages the whole site with explicit “use when” guidance.' },
          ],
        },
        {
          id: 'findings',
          title: 'Findings',
          where: 'Findings tab',
          purpose: 'Every open finding across your site, merged into one ranked list — pages losing a click they already earn, and anything the setup checks caught — with your content worklist below it: one row per post, page or anything else you publish, saying what that piece is actually found for and whether it answers. Ranked by what each finding costs you, never by which part of the plugin noticed it, so the first row is always the one worth doing. Findings move through three stages on the one screen: what needs you, what is finished on your side and waiting on a search engine, and — briefly, at the top — what has actually improved.',
          actions: 'Work the list top to bottom: each row carries its evidence and one button that lands exactly where you act. Load the content worklist when you want it — it reads each page once, so it waits to be asked and says when it has gone stale. Set aside anything not meant to be found or quoted; it stays counted and restorable, never hidden.',
          facts: [
            { k: 'info', t: 'The tab’s amber count is the same number the screen’s headline states — urgent plus worth-doing, kept live from any screen. It counts only what YOU can act on: a page whose edits are done and which is waiting on the next search report is never counted, because no edit can clear it and a number you cannot work down is a number you learn to ignore. Those get a quiet dot instead — a number means do something, a dot means look at something, nothing means clear.' },
            { k: 'info', t: 'Waiting on the next report is its own band, under the work. A page ranks 8th until a later report says otherwise, so once it is answered, titled and linked there is nothing left to type — the row says so, names the date it started waiting, and stops asking. Your side being done is judged by the same test the post’s own editor panel uses, so the two can never disagree about the same page.' },
            { k: 'tip', t: 'When a page really does improve, one green line says so at the top — “5th in results now, it was 8th” — and expires by itself within a week. Seen it retires it sooner. A win you have to go hunting for is not a win, and a notice you cannot finish with teaches you to skim.' },
            { k: 'info', t: 'Clients waiting on your allow/block decision are deliberately not repeated here — the bell already counts them and opens the queue itself. One story, one place.' },
            { k: 'info', t: 'An empty list reads as “checked”, never as “not working”: what is demonstrably fine is stated out loud, and any source that failed to answer is named instead of silently missing.' },
            { k: 'tip', t: 'Every finding’s button lands where the fix actually lives: the content finding hands its exact pages to the worklist below, and the search findings — pages near page one, pages seen but not clicked, and pages splitting one search between them — open the matching card on Visibility → Search, which lists those same pages with what to do about each.' },
          ],
        },
        {
          id: 'report',
          title: 'Report',
          where: 'More → Report',
          purpose: 'What AI did on your site between two dates — one page, in the order your weekly email reads: how many AI crawlers read you and which, how many people arrived from an AI answer and from where, what assistants did here, where you stand in search, whether a citation check ran, your score and the one thing worth doing. Every block links to the screen that owns its detail, so this page never becomes a second version of them.',
          actions: 'Choose Today, Yesterday, 7 days, 30 days — or Pick dates for any window, from a calendar that names both ends and lets you change either one. The dashboard’s Today line is the same reading for the day you are in.',
          facts: [
            { k: 'info', t: 'Each block says how fresh it can be, because a date means something different to each kind of number: your own log answers any window to the minute; Google and Bing publish a day or more behind and say so rather than showing a zero that means “not reported yet”; your score describes the site as it stands and reads the same whichever days you pick.' },
            { k: 'info', t: 'It is built by the same code as the weekly email, so the page, that email and the dashboard’s Today line can never tell you three different things.' },
            { k: 'info', t: 'Days here are UTC days — the clock your log is stamped in, and the one the daily chart and the weekly email already use. Whenever that is not the date on your own clock, the window says “UTC day” and hovering it explains why, so a card is never a day stale without saying so.' },
            { k: 'tip', t: 'A window where nothing happened says so in a sentence. On a small site that is the ordinary answer, not a fault.' },
          ],
        },
        {
          id: 'visibility',
          title: 'Visibility',
          where: 'More → Visibility',
          purpose: 'Three answers, one view each: how did search actually do for you (Search — what was searched for, how often you appeared, how often those results were clicked, and Search Opportunities right below: which pages to improve), are you in the engines’ indexes (Bing’s, which ChatGPT search and Copilot read today, and Google’s, which AI Overviews and Gemini read), and do assistants cite you when asked the questions your audience asks (Citations).',
          actions: 'Read the Search tab for your totals, trend and top searches — switching between Google and Bing when both are connected, each with its own week-on-week line — then work Search Opportunities below it: each group says what to do in plain words, and every page opens on the “Search & AI” box where its meta title and description live. On In the Index, connect Bing Webmaster with one read-only key (Agentimus prints the verification tag for you) and read index, crawl and error numbers day by day — or ask Bing about one page, live; connect Google Search Console and the In Google’s Index card checks your key pages daily — its Look up a page box answers for any page from your own stored checks, spending nothing. Press “Set up citation checks” inside the Citations tab — the click opens the setup and runs nothing, spends nothing — then set the questions (or let AI suggest a spread) and track mentioned, linked, ranked against rivals.',
          facts: [
            { k: 'info', t: 'Search Performance and Search Opportunities share the Search view because they read the same stored numbers through opposite lenses: the report answers “how am I doing?”, the worklist under it answers “what should I fix?”.' },
            { k: 'info', t: 'Search Opportunities only appears once a search source is connected, and it judges “not clicked enough” against your OWN page-one click rate — your site’s average, never an industry benchmark. Pages set aside there keep their own list, separate from Optimize’s on Readiness: excusing a page from citability grading and from search suggestions are different decisions. Searches you set aside keep a third list of their own, shown on the same screen with a way to put each one back — that one excuses a question rather than a page, and no page is judged on it anywhere.' },
            { k: 'info', t: 'One search, several answers: when two or more of your pages keep appearing for the same query, the clicks divide and every one of them ranks lower than one page would. The card names the page that earns the click and gives each weaker page one decision — point it at the winner, or set it aside. Only ever asserted from the engine’s own rows: thin data never accuses a page, and a query one page already owns is a result, not a problem.' },
            { k: 'info', t: 'The In Google’s Index card checks in three tiers, because Google has no bulk index report and allows 2,000 URL inspections a day: a daily watchlist (homepage, busiest pages, newest posts), every problem page until it heals, and a whole-site rotation of up to 100 pages a day. Small sites get every page checked daily; big ones get an honest stated cadence. Rows are only for what needs a look, or for a page that just healed — healthy pages are counted, never listed.' },
            { k: 'info', t: 'There is no “Request indexing” button anywhere, deliberately: Google’s Indexing API accepts only job-posting pages, and the Search Console button has no API. Every problem row links straight to that URL’s inspection in Search Console instead, where the real button is one click away.' },
            { k: 'warn', t: 'Google and Bing are never merged into one figure — they count different searchers, so a blended number would be one neither engine ever reported. The card names which one you are reading, and switches between them when both are connected.' },
            { k: 'info', t: 'Bing’s headline tiles come from its site-wide daily traffic report; its top-searches and top-pages lists are the samples Bing offers — counted separately, and the card says so. Google’s lists ARE its full report, so its tiles are simply their sum.' },
            { k: 'info', t: 'Citation checks deliberately use their own keys, not WordPress’s shared AI provider: shared connectors hand back only answer text, and grading needs the list of cited sources each assistant returns on its own API.' },
            { k: 'info', t: 'Every tab stores its history in your own database, so it keeps growing where the vendors’ own reporting windows end.' },
            { k: 'tip', t: 'Good tracked questions never mention your name. The point is to learn whether an assistant reaches for you unprompted.' },
          ],
        },
        {
          id: 'visitors',
          title: 'Visitors',
          where: 'More → Visitors',
          purpose: 'Everyone Who Visited your site. With Google Analytics connected the screen opens with the whole audience — people, visits, pages opened, time on site, your busiest pages, and which search engines sent visits (Google and Bing, counted by the one instrument that sees both) — then the AI slice beside it: visitors who arrived from an assistant’s answer, daily counts by assistant and by the page they landed on. The Request Log shows machines reading you; this screen shows people.',
          actions: 'Connect Analytics under Settings → Data Sources — the same service-account key as Search Console, plus a Viewer grant on your GA4 property; the screen itself offers the first fetch, one click, and it refreshes daily after that. Pick the window, see which assistants send visitors and to which pages — and click a day in the By Day strip for that day’s story, assistant by assistant. The weekly email draws its “readers from AI” lines from here.',
          facts: [
            { k: 'info', t: 'Counts are daily aggregates — a total per day, per source, per landing page. Never a row that stands for one person, no IP addresses, no identities.' },
            { k: 'info', t: 'Attribution is honest and therefore conservative: AI browsers — assistants browsing on a person’s behalf — often arrive looking like Direct traffic, so real AI-sent visitors can be undercounted — they are never invented.' },
            { k: 'info', t: 'Your own clicks out of assistant chats are skipped — the plugin re-checks the admin cookie even on cached pages, so the owner never inflates their own numbers.' },
            { k: 'info', t: 'Two instruments, on purpose: GA4 counts from the visitor’s browser and misses people who decline its script; the plugin’s own referral count sees every visit but misses stripped referrers. The screen shows both and names the difference instead of picking a winner.' },
            { k: 'info', t: 'The search-engines line is GA4’s own attribution, organic clicks only — a paid click can never pose as a search result, and bing.com/chat counts as Copilot, never as Bing.' },
          ],
        },
        {
          id: 'log',
          title: 'Request Log',
          where: 'More → Request Log',
          purpose: 'Every request to your AI files — llms.txt, the Markdown copies of your pages (the .md twins), the discovery documents — and the visits recognised AI assistants and crawlers make, each one named, judged and time-stamped. This is the plugin’s ground truth: when a wizard proof, a pin or a chart claims something happened, the row that proves it lives here.',
          actions: 'Filter by client, address, network or verdict. Click a user-agent to copy it. Ask “Re-check now” to verify a claimed identity against the operator’s own published checks. Allow, block or ignore a client from the review bell — Manage clients (Settings → AI Access) keeps every standing decision with an undo. Clear the log any time.',
          // Fact kinds carry the annotation: info = a notice worth knowing,
          // warn = a caution that prevents a wrong conclusion, tip = a lightbulb.
          facts: [
            { k: 'info', t: 'You are never in your own log: logged-in administrators are skipped, and the plugin’s own self-checks carry a signed token that keeps them out — the user-agent alone is never trusted for that, because anyone can wear one.' },
            { k: 'warn', t: 'Names are claims. Recognition reads the user-agent, which is forgeable — that is exactly why verdicts exist: Verified means the operator’s own published checks confirmed the address, Spoofed means they disproved it, Unchecked means nobody has looked yet.' },
            { k: 'info', t: 'Retention and the report window are different facts: rows are kept for as long as you choose (30 days by default); the screen shows at most the last 30 days of what is kept. A size cap outranks both, so the table can never grow without bound.' },
            { k: 'info', t: 'Refusals are recorded. A client that was turned away still gets its row — a proven impostor can’t be refused in silence.' },
            { k: 'info', t: 'No IP addresses are stored by default; the optional flagged-clients setting is the single, disclosed exception (see Privacy below).' },
            { k: 'tip', t: 'A quiet log on a fresh site is normal — crawlers usually find your files within a day or two, and every visit from then on counts up here.' },
          ],
        },
        {
          id: 'agent-access',
          title: 'Agent Access',
          where: 'More → Agent Access',
          purpose: 'The record of every AI assistant that signed in with a key — every key created, every ability it used, every refusal. If the Request Log is who knocked, this is what the ones you let in actually did.',
          actions: 'Watch events roll up per credential with first-seen and last-seen. A NEW pill flags a key’s first appearance — and the nav badge re-lights when something new lands. Disconnect an approved assistant from the MCP card; revoke an application password from the user’s profile.',
          facts: [
            { k: 'info', t: 'Every connected assistant acts as the WordPress user behind its credential — it can never do more than that user could on these screens, and each ability keeps its own permission check on top.' },
            { k: 'info', t: 'Refusals are events too: a key that tried something it isn’t allowed to do shows up here saying exactly that. Silence is never how a denial ends.' },
            { k: 'info', t: 'Writes only exist while “Let connected agents write” is on, drafts-only unless you also allow publishing — and every write lands in this feed either way.' },
          ],
        },
        {
          id: 'integrations',
          title: 'Integrations',
          where: 'More → Integrations',
          purpose: 'The places your site connects to, in three views. Services are the apps that receive reports from your site: your own webhook, Telegram, Slack, Discord and Google Sheets. X (Twitter) and LinkedIn are here too, but they receive no reports — you connect them so you can post announcements. Sharing is where you choose which networks your announcements go to. Plugins lists the plugins whose content Agentimus describes to AI assistants for you. It shows the ones you run and the ones you do not, so you can see what would be described if you installed them.',
          actions: 'To connect a service, open its card, paste what it asks for, tick the events you want, and click Connect. Agentimus sends a real test message first. If that message does not arrive, the connection is refused, so you never end up with a connection that looks fine but cannot work. On Sharing, turn announcing on for a network and choose where its posts go. Plugins needs no setup — it only tells you what is described.',
          facts: [
            { k: 'info', t: 'Events and announcements do two different jobs. An event tells YOU that something happened: a new finding, a caught impostor, the weekly digest numbers. An announcement tells YOUR READERS about a post you wrote. They use the same connections, and nothing else is shared: different destinations, different queues, different errors.' },
            { k: 'info', t: 'Connecting always sends something real first. Telegram sends a test message to your chat. Sheets adds a header row and one test row. Slack and Discord post one short line to the URL you pasted. X and LinkedIn finish a normal sign-in. If the service says no, Agentimus shows you its exact words and saves nothing.' },
            { k: 'info', t: 'Reports are never sent while you wait. An event is added to a queue, and a background job sends it a moment later, so a slow service never slows your pages or your admin screens. Each event is tried up to four times: after one minute, then five, then fifteen. If all four fail, the card shows the last error in the service’s own words.' },
            { k: 'info', t: 'Agentimus builds each report field by field, and every report carries a version number. It never forwards raw data from inside the plugin. Only your own site reports are sent, never anything about your visitors: an impostor report names the crawler, never an IP address.' },
            { k: 'info', t: 'Your own webhook is signed. Each POST carries an HMAC-SHA256 of the body in the X-Agentimus-Signature header, so your code can check that the event really came from your site. The secret is shown only once, when it is created, and you can create a new one at any time.' },
            { k: 'warn', t: 'For Slack and Discord, the webhook URL is the password. That is how Slack and Discord work, not a choice we made. Anyone who has that URL can post to your channel, so keep it private. Apps that copy Slack’s format, such as Mattermost and Rocket.Chat, work too, because the URL is never limited to slack.com.' },
            { k: 'tip', t: 'If you connect nothing, none of this runs at all. Telegram has one extra choice: send every new finding, or only urgent ones. Pick urgent if you want a message only when something is really broken.' },
          ],
        },
        {
          id: 'announcements',
          title: 'Announcements',
          where: 'More → Announcements',
          purpose: 'The record of every announcement: what is waiting to be posted, what was posted, and what failed. One row each, showing the time you chose, the network, the text itself, and the result. You set up the connections on Integrations. This screen shows what actually went out.',
          actions: 'Read the list. An announcement that has not been posted yet can be sent now, or cancelled. A failed one shows the network’s own error message and gives you two choices: try again, or remove it from the record. Click the eye on any row to read the exact text before it is posted.',
          facts: [
            { k: 'info', t: 'Nothing is posted without you. Every row here began as a Share draft that you approved in the post editor, and you chose the time. Agentimus never posts when you publish a post.' },
            { k: 'info', t: 'An announcement is posted at the time you chose, not before. WordPress runs late schedules on the next visit to your site, so a quiet site posts when someone next visits. The row always shows the time it really went out, not the time you asked for.' },
            { k: 'warn', t: 'A scheduled post is tried once, and only once. This is on purpose. A report arriving twice is only noise, but an announcement arriving twice looks like spam. If it fails, the row waits for you.' },
            { k: 'info', t: 'The text is posted exactly as you approved it. Nothing is rebuilt at sending time, so editing the post later does not change what goes out.' },
            { k: 'info', t: 'Posted and failed rows are kept, up to 200, and the oldest finished rows are removed first. A row that is still waiting is never removed to make space.' },
            { k: 'info', t: 'A failed announcement never changes the “last delivered” line on the Services card, and a failed event never appears on this screen. Same connection, two separate jobs.' },
          ],
        },
        {
          id: 'settings',
          title: 'Settings',
          where: 'More → Settings',
          purpose: 'Every switch the plugin has, grouped and explained where it sits — identity, content and policy, trust and verification, data sources, the MCP server. What each readiness row links to lives here. Opened from More, where it sits with About below the menu’s rule.',
          actions: 'Flip switches and type — changes autosave (switches immediately, text as you pause). Manage clients holds every allow/block/ignore decision with an undo. Data Sources connects Cloudflare, Google (Search Console and, optionally, Analytics — one key, two grants) and Bing, one scoped key each. Run setup again replays the wizard over your current answers. Reset shows a preview of exactly what would change before it does.',
          facts: [
            { k: 'warn', t: 'The write tier is deliberately nested: turning the MCP server off also turns “Let connected agents write” off. Write access can never stay armed invisibly under a switched-off server.' },
            { k: 'info', t: 'With the MCP server on, an unauthenticated client can complete the handshake: the server answers with its name and protocol version, so a scanner never reads a refusal as “no MCP here”. That is all it answers. Asking for the tool list without a key is refused with an “authenticate here” header — which points a client at your approval page rather than handing back an empty list that would read as “no tools here”. The same rule holds in the files: they say how many tools the server has, and name only the ones a plugin has published for anyone to see.' },
            { k: 'info', t: 'Connection secrets — the Cloudflare token, the Bing key, the Google service-account key, visibility keys — are stored encrypted at rest and are never echoed back in a REST response after saving.' },
            { k: 'info', t: 'Google connects through a service-account key you create in your own Google Cloud and paste here, not a “Connect with Google” button. That button, in other plugins, routes your data through the plugin maker’s server; this key talks to Google directly from your site and is revocable in your own console. The trade is a five-minute setup, walked through step by step on the card.' },
            { k: 'info', t: 'The trainer blocklist and the reading policy are separate ideas: blocking a training crawler hides nothing from readers, because assistants fetch reader requests with different crawlers. Google is the documented exception — one token governs both.' },
            { k: 'tip', t: 'You rarely need to browse here: every readiness fix that is a switch links straight to its exact field, and the wizard set the important ones already.' },
          ],
        },
],
      // Documentation of what the plugin publishes. "Default" is the shipped
      // state; the live on/off for each lives on the Settings tab.
      featureGroups: [
        {
          title: 'Guided Setup',
          lead: 'A couple of minutes from install to a working, measured site — the wizard collects what matters, fixes what it can, and proves the whole thing live.',
          items: [
            { name: 'Setup wizard', where: 'first run · Settings → Run setup again', desc: 'Five short steps that gather everything the checks below will later ask for: who you are (name, role, profile sentence — pre-filled from what WordPress already knows), the topics, profiles and audience that help AI trust you, the services you offer, and what AI may read and do with it. Skipping is safe too: sensible defaults stay on and your site’s tagline pre-fills the profile, so even a skipped setup speaks.', tag: 'On' },
            { name: 'Guided fixes', where: 'setup wizard', desc: 'Where a WordPress setting stands in the way, the wizard offers the fix as a one-tick choice instead of homework — “Discourage search engines” being the classic. Pre-ticked on live sites, where it’s almost always a leftover; explained and left unticked on local ones. Nothing is ever changed without the tick.', tag: 'On' },
            { name: 'Proof, then a map', where: 'end of setup · Dashboard', desc: 'On a public site, setup ends with proof: ask ChatGPT, Claude, Perplexity or Grok about your site and watch the visit land in your own Request Log, live on the screen — with honest reasons named when nothing arrives. Afterwards a one-time dashboard card points at the rooms worth a look next — Visibility, Cloudflare, connecting an assistant — and leaves the choosing to you.', tag: 'On' },
          ],
        },
        {
          title: 'AEO/GEO Score & Optimization',
          lead: 'Your dashboard turns everything below into one score — and the single next thing to improve.',
          items: [
            { name: 'Findings', where: 'the Findings tab', desc: 'Everything open across your site in one ranked list — pages losing a click they already earn, and anything the setup checks caught. Ranked by what each one costs you rather than by which part of the plugin noticed it, so the first row is always the one worth doing. Each row carries the evidence behind it and one button that lands on the exact place to act. Three stages on one screen: what needs you, what is done on your side and waiting on a search engine, and what has improved — the last as a single green line that expires within a week. The tab’s count is only ever work you can do; waiting shows as a quiet dot instead. What is demonstrably fine is stated too: an empty list reads as “checked”, never as “not working”. Clients waiting on a verdict are deliberately not repeated here: the bell already counts them and opens the queue itself.', tag: 'On' },
            { name: 'Your Content', where: 'Findings', desc: 'One row per post, page or whatever else you publish — the search it is actually found for, whether the page answers that search, and anything the content checks flagged. It covers every kind of content you publish, not only posts and pages: the gear above the list says which kinds are being checked and lets you change it. Products are checked for the searches they are found for, but never graded as writing. A product page is short on purpose. Read on request rather than on every page load, because it reads each page once. Set aside anything not meant to be quoted and the row steps out of the way, still counted and still restorable.', tag: 'On' },
            { name: 'What this page is for', where: 'post editor · Search & AI', desc: 'A focus keyword you do not have to guess. The searches that already reach this page are offered as choices, each with its position and traffic, and you promote one — or type your own on a post too new to have been found yet. Whatever you choose is then measured, not scored: whether one passage of the page answers that whole search, which heading it was found under, and whether the words appear in the title people actually see.', tag: 'On' },
            { name: 'AEO/GEO score', where: 'Dashboard', desc: 'One 0–100 score across five rungs — Findable, Readable, Trusted, Optimized and Cited — with a plain-language next step and an honest per-rung “n to fix” count. Each rung links to where you act on it. 100 is earned, never rounded into: the composite only reads 100 when every counted rung does.', tag: 'On' },
            { name: 'Optimize Your Content', where: 'Readiness → Optimize', desc: 'Per-page citability checks (enough substance, something concrete to quote, quotable passages, a featured image, freshness) with a worklist of the pages to improve. Work through it page by page, or by issue — pick one check and every page it flags opens in Your Content, ranked, twenty at a time, instead of a handful. Articles only — commerce products, commerce plugins’ own pages (cart, checkout, account) and container pages that are just a shortcode or plugin block with no authored prose are left out. Grading is fair to technical writing: your subject’s recurring terms read as familiar and code samples aren’t graded as prose. Set aside anything not meant to be cited. The card itself now states the headline and hands the working-through to the Findings screen’s content list, one row per page; the by-issue view and its bulk actions stay one click away. The grade covers every published page rather than a sample of recent edits, so the score describes the site rather than its last few days of writing — and the card names which kinds of content it graded, since what is checked and what is graded are not the same set. A page you edit keeps its place on the list and says it is being read again, rather than disappearing as though you had fixed everything on it — and it is read again within about a minute.', tag: 'On' },
            { name: 'Readability tips', where: 'post editor', desc: 'The same per-page tips in an editor panel while you write. Twelve of its fourteen checks serve classic SEO and accessibility as much as AI — thin content, headings and their order, image alt text (including the featured image’s), reading ease, freshness — so the panel names all three audiences rather than only assistants. The featured image is judged on the page your site actually serves: Agentimus reads a couple of your own pages in the background, once per theme, so it can tell an undescribed picture from a theme that drops the description you wrote. A picture with an empty description is left alone where it is decoration — that is what an empty description is for — but questioned where the same picture carries a caption, because a picture worth captioning is not one that means nothing. Editor-only — nothing is shown to visitors.', tag: 'On' },
            { name: 'Video & audio context', where: 'post editor', desc: 'A player carries no words, so a page built around one reads as empty to an assistant. Select a video or audio block and its own panel asks for one line — what it is, what it covers — which reaches your page’s structured data and its Markdown edition. Your caption is used when you leave it blank, so a captioned video needs nothing. The check stays quiet when the page already carries a transcript (from any plugin) or enough writing of its own, and it never asks you for a transcript: those belong to whatever tool you already keep them in. Nothing is written to your pages.', tag: 'On' },
            { name: 'Link to your own posts', where: 'post editor', desc: 'Suggests which of your own posts this one should link to — found from signals your site already has (shared topics, categories and tags, a post’s subject appearing in your text), so it works instantly with no AI at all. With an AI provider connected, one request per click picks the exact phrase to link and explains each suggestion. Insert links the phrase right where it sits in your text — an ordinary editor edit you can undo, saved only when you save the post. Also available to AI assistants as a read-only MCP tool.', tag: 'On' },
            { name: 'Evergreen content', where: 'Settings', desc: 'Mark categories whose posts are timeless (references, tutorials, legal) so they’re exempt from the freshness check.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Write With AI',
          lead: 'Optional — use the AI provider you’ve connected in WordPress (Settings → Connectors, with your own key) to draft and fix the AEO/GEO fields as you write. Everything routes through WordPress’s built-in AI Client, so Agentimus never handles your key; every suggestion is editable and nothing is saved for you. The buttons stay hidden until a provider is set up.',
          items: [
            { name: 'Writing Assistant', where: 'the spark button, every Agentimus screen', desc: 'Describe the post you want; the assistant proposes an outline you can edit, then writes every section of the article in parallel — the outline fills in as parts land, long posts aren’t capped by a single AI response, and a failed section retries alone. The assembled draft — real editor blocks, AI description, topics, suggested categories and tags — is shown to you before a single thing is saved. Create draft opens the post in the editor, where image placeholders arrive with their alt text filled in: a Generate button turns any image block’s alt text into an image, a Featured image (AI) panel drafts the hero from the title, and Ask AI rewrites, shortens or extends any text block. Choose what you are writing first — Posts, Pages, or any content type you have switched on, including a shop’s products. Each gets frames that fit it: a page invents no sections and takes no image slots, a product is described rather than reviewed, a recipe gets a method and a book gets what it covers. A type is offered only if it actually keeps its body in WordPress’s editor, and it is never asked for tags, categories or illustrations it cannot hold — so nothing is written that the save would then refuse. Where two plugins both call their type “Products”, each is named by the plugin that registered it. Once something exists it is edited in the block editor, not here; the assistant’s list opens it there in a new tab. Needs “Let connected agents write” plus an AI provider; it never publishes.', tag: 'Opt-in' },
            { name: 'Ask AI', where: 'post editor', desc: 'Revision lives here, at three scopes, because a post that already exists is a block document — it can hold any block, and the assistant only writes text. Select one block and the sidebar panel rewrites, shortens or extends it. Select several and the block menu’s Ask AI changes them together with one instruction — the answer replaces the whole selection, so three paragraphs can come back as two. Or open the Ask AI sidebar and describe a change to the WHOLE post: it reads the post as a numbered list of blocks and answers with proposed edits — rewrite this, delete that, add a section here — each with the reason and the exact instruction it will run, for you to accept or reject one at a time. Nothing is touched until you apply, blocks the plan doesn’t name are never altered — your tables, embeds and images are safe by construction, not by luck — and undo steps back through everything. Needs the same write switch and AI provider as the assistant.', tag: 'Opt-in' },
            { name: 'Draft with AI', where: 'post editor', desc: 'One click drafts the AI description for a page from its content (and “Suggest with AI” does the same for its topics), dropping the result into the field as editable text to accept or change.', tag: 'Opt-in' },
            { name: 'Fix with AI', where: 'post editor', desc: 'On each AI-readability warning, drafts a concrete fix — an opening summary you can insert with one click, a missing featured image generated with one click from the post’s title, or a heading outline / shorter quotable passages to copy in. Advisory items that add facts stay copy-only, so nothing unverified is inserted for you.', tag: 'Opt-in' },
            { name: 'Agentimus abilities', where: 'WordPress admin AI · MCP', desc: 'Exposes read-only abilities — your readiness score, AI traffic, request log, edge traffic from Cloudflare, AI-search visibility from Bing, crawler identity checks, the clients waiting on your verdict, what has been done on your site through a key, your announcement record, what your site is connected to, page / JSON-LD / Markdown previews, a page’s stored body, and a search of your media library, which can also list the pictures in it carrying no description at all — to WordPress’s built-in AI and, when you turn on the MCP server below, to external AI assistants. A second, separate switch adds write abilities (draft/edit content, change one passage of a page, describe an image in your library or one inside a page, set AI topics and descriptions, apply Readiness fixes, set a page aside, set a reported search aside so no page is judged on it, try a failed announcement again, and decide about or re-check a client in the review queue); until you flip it, they don’t exist on any surface. Each ability is permission-checked exactly like the screen it comes from.', tag: 'Auto' },
            { name: 'Content Guidelines aware', where: 'post editor · MCP', desc: 'If your WordPress defines Content Guidelines (an experimental WordPress feature: brand voice, copy and image rules), every AI writing surface honors them automatically — Draft, Suggest and Fix follow them, and an assistant connected over MCP sees them right on the draft/edit tools, so it writes in your voice too. Detected automatically, nothing to configure; your guidelines never appear in any public file.', tag: 'Auto' },
          ],
        },
        {
          title: 'Share & Ask AI',
          lead: 'For the moment a post is done: retell it for every network, and let readers hand it to their assistant — with the assistant’s visit landing in your own log.',
          items: [
            { name: 'Share drafts', where: 'post editor → Share tab', desc: 'One card per network — X (Twitter), Facebook, LinkedIn, WhatsApp, Telegram and Reddit — each holding a ready-to-post draft written from the post itself (its title, AI description and topics), shaped to where it’s going: short for X, title-plus-description for Reddit, a message that reads like a message for WhatsApp and Telegram. No AI needed — drafts assemble locally and instantly; with a provider connected, a per-card polish rewrites that one draft in the network’s register, and you keep either version. Copy puts a draft on your clipboard; Open launches the network’s own composer prefilled — Facebook and LinkedIn don’t accept prefilled text, so for those Open copies first and the card says to paste. By itself this tab posts nothing: you copy the text, or open the network’s own composer. If you connect a network under Integrations → Sharing, each card gets one extra row, “Send it later”, which puts the draft you approved in the queue to be posted at a time you choose, from your own account. Nothing is ever posted unless you choose both the words and the time, and readers never see any of this until it goes out.', tag: 'On' },
            { name: 'Ask AI about this post', where: 'after each post', desc: 'A quiet row of buttons — ChatGPT, Claude, Perplexity, Google AI Mode and Grok — each opening the reader’s assistant with a question about this post already filled in. Plain links: no script runs on your page, nothing is loaded from any assistant, and nothing is sent anywhere until a reader clicks. The payoff is on your side of the log too: the assistant has to fetch your page to answer, and that fetch shows up in your own Request Log — the loop closes on your own site. The row also obeys your own crawler rules: an assistant your blocklists forbid from reading the page loses its button, rather than shipping one that can only fail (see the Q&A below for why that’s Google today). Posts only by default; one switch under Settings → Discovery removes the row everywhere.', tag: 'On' },
          ],
        },
        {
          title: 'Integrations & Announcing',
          lead: 'When you connect a service, two kinds of message can leave your site. Events tell you what happened on your site. Announcements tell your readers about a post you wrote. Both use the same connections, and nothing is sent until you connect one.',
          items: [
            { name: 'Report events', where: 'More → Integrations → Services', desc: 'Six things Agentimus already notices, sent to wherever you work: a new finding, the weekly digest numbers, an impostor caught, a change to your robots.txt policy, a citation check finishing, and an AI assistant writing or editing a post. Tick the ones you want for each service. One event goes to every connected service that asked for it, as a separate delivery, so a slow service never holds up the others.', tag: 'Opt-in' },
            { name: 'Your own webhook', where: 'More → Integrations → Services', desc: 'Every event sent to a URL you paste, as one JSON POST. This is the door for tools that need no code: Zapier, Make and n8n can turn an event into an email, a task, or a row in Notion, and your own code can read the same events directly. Each POST carries an HMAC-SHA256 of the body in the X-Agentimus-Signature header, so you can check it really came from your site. The secret is shown once, when it is created.', tag: 'Opt-in' },
            { name: 'Telegram', where: 'More → Integrations → Services', desc: 'Events as messages from a bot you own. Send /newbot to @BotFather, paste the token it gives you, and say which chat to use — about a minute of work. Connecting sends one test message to that chat, so you know it works before anything is saved. For findings you can choose: every new one, or only urgent ones.', tag: 'Opt-in' },
            { name: 'Slack & Discord', where: 'More → Integrations → Services', desc: 'The same events, posted where your team already works. Use a Slack incoming webhook, or a Discord server webhook. Each message is formatted for its app: a Block Kit message in Slack, an embed in Discord. Only the two real alerts — an urgent finding and a caught impostor — use a warning colour, so colour keeps its meaning. Connecting posts one short test line first, so a wrong or deleted webhook is refused at once instead of being saved as “Connected”. Apps that copy Slack’s format, such as Mattermost and Rocket.Chat, work too.', tag: 'Opt-in' },
            { name: 'Google Sheets', where: 'More → Integrations → Services', desc: 'Every event added as a row in a spreadsheet you own, so you can sort it, chart it and share it — and keep your history long after the 30-day log has cleared. It uses the same service-account key as your Google connection: share the sheet with that address, then paste the sheet ID. Connecting adds a header row and one test row. If you disconnect, the rows already written stay in your sheet; only new rows stop.', tag: 'Opt-in' },
            { name: 'Announcing', where: 'More → Integrations → Sharing · post editor → Share tab', desc: 'Posts your Share drafts for real, on X (Twitter), LinkedIn and Telegram, from your own accounts. You connect the account on Services; on Sharing you choose which networks announcements go to. Each Share card in the post editor then gets one extra row, “Send it later”, which puts the draft you approved in the queue for a time you choose. It never posts when you publish, and it posts exactly what you approved.', tag: 'Opt-in' },
            { name: 'The announcements record', where: 'More → Announcements', desc: 'What is waiting, what was posted, and what failed. Each row shows the time you chose, the network, the text behind an eye, and the result. A waiting row can be sent early or cancelled. A failed row shows the network’s own error and waits for you to try again, because each scheduled post is tried once only. Posted and failed rows are kept, up to 200; a waiting row is never removed to make space.', tag: 'Opt-in' },
            { name: 'Plugins described', where: 'More → Integrations → Plugins', desc: 'The list of plugins whose content becomes part of what AI assistants can read about your site: the Fluent family, WooCommerce and Easy Digital Downloads. Plugins you do not run are listed too, so you can see what would be described if you installed them. There is nothing to set up. Any other plugin can join the same way with one WP_Discovery action, and it does not need Agentimus to do it — the developer card links to the guide.', tag: 'Auto' },
          ],
        },
        {
          title: 'Discovery Documents',
          lead: 'The core job — standard files that let an AI assistant find and understand your site.',
          items: [
            { name: 'Get Help', where: 'More → Get Help', desc: 'Opens the WordPress support forum for this plugin, where a person answers. Nothing is sent from your site — it is a link. The forum cannot be filled in from a link, so if you need to say what your site is running, About has a Copy setup details button. It gathers the versions, theme, cache and connected services for you.', tag: 'On' },
            { name: 'Report an Issue', where: 'More → Report an Issue', desc: 'Opens the matching form on the plugin’s GitHub repository with your setup already attached — versions, theme, cache, connected services — so the first reply can be an answer instead of a question. You choose what kind of issue it is, and that choice picks the form, which carries its own label. The plugin composes the link and your browser opens it. Nothing is submitted from your site. You post under your own GitHub account, and that is what lets a reply reach you. Your site address is included by default and can be left out with one tick.', tag: 'On' },
            { name: 'What’s New', where: 'More → What’s New', desc: 'The headlines from this release, readable whenever you want them rather than only in the card that appears once after an update — dismissing that card now only stops the card. Every earlier release sits underneath it, read from the plugin’s own changelog on your server.', tag: 'On' },
            { name: 'Discovery manifest', where: '/.well-known/discovery.json', desc: 'The master document describing your site, content and capabilities.', tag: 'On' },
            { name: 'Agent card', where: '/.well-known/agent-card.json', desc: 'Agent-to-agent (A2A) identity card, also served at agent.json.', tag: 'On' },
            { name: 'API description', where: '/.well-known/openapi.json · /api-catalog', desc: 'OpenAPI 3.1 spec and an RFC 9727 catalog of your public REST API — every operation carries a stable id, a description and a typed error shape, the details function-calling toolchains read.', tag: 'On' },
            { name: 'MCP manifest', where: '/.well-known/mcp.json', desc: 'Advertises Model Context Protocol servers running on your site — Agentimus’s own, or another plugin’s — with their tools and per-server cards.', tag: 'Auto' },
            { name: 'MCP server', where: '/wp-json/agentimus/v1/mcp', desc: 'Off by default. One switch (Settings → Discovery) runs a Model Context Protocol server on your own site — everything needed ships with the plugin — so the AI assistants you already use can run the read-only Agentimus tools listed above — the card itself names how many are running, so the number is never a claim this page has to keep up with. A second switch (off by default) adds the write tools — draft/edit content, change one passage of a page without touching the rest, describe an image in your library or one sitting inside a page, set AI topics and descriptions, set the search a page should answer and the title a search result shows, apply Readiness fixes, set a page aside, set a reported search aside so no page is judged on it, try a failed announcement again, and decide about or re-check a client in the review queue — and a third decides whether assistants may publish or only leave drafts for review. Anyone may ask the server what it is: it answers the handshake with its name and protocol version, so a client can tell a server is here before it has a key. The tool list is not part of that answer: asking for it without a key is refused with the standard “authenticate here” header, which is how an assistant finds where to ask for your approval. An assistant that connects then sees every tool it may run. Your public files name the server, its address and how many tools it holds — not what each one is called, because a tool that needs signing in should not have its description handed to someone who has not. It also offers its DOCUMENTS to be read rather than run — llms.txt, llms-full.txt, discovery.json and your agent card are listed as MCP resources, so an assistant can attach them without fetching a URL, and a document you have switched off is never offered. Running anything signs in: each tool keeps the same permission checks as its admin screen, every call is recorded under Agent Access, and turning the switch off disconnects connected assistants immediately.', tag: 'Opt-in' },
            { name: 'Connect an assistant', where: 'Settings → Discovery → MCP Server', desc: 'Give an assistant only your server address and it asks you for permission on a consent page served by your own site: it names what it calls itself and where it will return, and you choose Read only or Read and write — you may grant less than it asked for. Approving gives that one assistant its own key; it appears under Connected assistants with its scope, its last call and its own Disconnect, so cutting one off leaves the others working. The flow is the OAuth 2.1 standard with PKCE and dynamic client registration, served entirely by your site — no third party brokers it, and Agentimus stores only a fingerprint of every key. Assistants that cannot ask for approval use a shared token instead (one secret, read-only or read-and-write, shown once, revocable), or a WordPress application password. Whichever door it came through, a read-only key is shown only the read tools, and no key can exceed your write settings.', tag: 'Opt-in' },
            { name: 'How AI assistants sign in (auth.md)', where: '/auth.md', desc: 'A plain-markdown page telling AI assistants how to authenticate — generated from your live settings on every request, so it can never promise a flow you don’t run. It walks the standard auth.md shape (discover, pick a method, register, claim, use the credential, errors, revocation), states outright whether public registration exists, and while the MCP server is off it simply says reading is public and changes stay with you.', tag: 'On' },
            { name: 'Agent skill (SKILL.md)', where: '/.well-known/agent-skills', desc: 'Your site packaged as an installable agent skill (the agentskills.io format): what it offers, the addresses that matter, and explicit “use when” guidance — assembled live from what’s actually switched on, named after your own domain, and linked from llms.txt so AI assistants find it without guessing.', tag: 'On' },
            { name: 'Browser tools (WebMCP)', where: 'your public pages', desc: 'Off by default. Lets an AI assistant working inside a visitor’s browser call your site’s read-only tools (like site search) directly, via the emerging WebMCP browser standard. It adds one tiny, self-hosted script that stays inert in browsers without support, and each tool can be shown or hidden individually in Settings.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Machine-Readable Content',
          lead: 'Your existing content, offered in formats AI assistants read cleanly.',
          items: [
            { name: 'llms.txt', where: '/llms.txt', desc: 'A plain-text index of your site and recent content.', tag: 'On' },
            { name: 'llms-full.txt', where: '/llms-full.txt', desc: 'A full-text bundle of your pages and posts, within a size budget.', tag: 'On' },
            { name: 'Markdown', where: '/{slug}.md', desc: 'Clean Markdown of any page, at its own address — a separate URL, so a cache can never confuse it with the page a reader sees. (Answering the page URL itself with Markdown via an Accept header is opt-in; see the docs.)', tag: 'On' },
            { name: 'Change feed', where: '/agentimus-changes.json?since=', desc: 'What changed lately — added, updated and removed content as JSON, with a ?since= filter so an AI assistant re-reads only what changed instead of crawling the whole site again. Removals are announced honestly (tombstones), it’s one bounded, cached query, and it’s advertised in discovery.json so assistants find it on their own.', tag: 'On' },
          ],
        },
        {
          title: 'Structured Data & Crawl Signals',
          lead: 'Standards search engines and AI assistants already understand.',
          items: [
            { name: 'JSON-LD schema', where: 'in your page <head>', desc: 'schema.org WebSite, Person/Organization, articles, breadcrumbs and FAQ — plus speakable markup on posts, naming the headline and lead a voice assistant should read aloud.', tag: 'On' },
            { name: 'Video & audio objects', where: 'in your page <head>', desc: 'A VideoObject (or AudioObject) for each player WordPress embeds, carrying the line you wrote about it, the provider’s own title, and a transcript already published on the page. A thumbnail comes from the video’s poster or WordPress’s own extracted still — never a stand-in that belongs to something else, so it is left out rather than guessed. Emitted only where we add something no one else does, and it stands down entirely if another plugin already describes that media.', tag: 'On' },
            { name: 'Topics for AI', where: 'per post', desc: 'Per-page topics → JSON-LD keywords and about entities (linkable to Wikidata/Wikipedia) plus a Markdown line, so assistants know exactly what each page is about.', tag: 'On' },
            { name: 'AI description', where: 'per post', desc: 'A one-line summary → the JSON-LD description, the Markdown lead, and your page’s meta description (replacing your theme’s, unless a dedicated SEO plugin owns it). Blank pages fall back to the excerpt, or a short summary of the page.', tag: 'On' },
            { name: 'Search basics', where: 'Settings → Discovery', desc: 'With no SEO plugin installed, Agentimus covers the search essentials itself: a per-page “SEO title” field in the editor, Open Graph/X share cards (featured image → your chosen default → Site Icon), and canonical links on the views WordPress leaves bare. Install an SEO plugin and every one of these steps aside automatically — nothing is ever emitted twice — and a dashboard card names the division of labour.', tag: 'On' },
            { name: 'robots.txt', where: '/robots.txt', desc: 'Adds Content-Signal directives and advertises your sitemap.', tag: 'On' },
            { name: 'XML sitemap', where: '/wp-sitemap.xml', desc: 'With no SEO plugin, Agentimus serves your sitemap — including the last-changed dates WordPress core’s own leaves out — at WordPress’s standard address, so a sitemap registered in any search console keeps working. Older addresses (/sitemap.xml, /agentimus-sitemap.xml) redirect there. With an SEO plugin installed, it stands down and links theirs instead.', tag: 'On' },
          ],
        },
        {
          title: 'AI Rights & Opt-Out',
          lead: 'Tell AI systems how your content may be used.',
          items: [
            { name: 'Do-not-train signals', where: '/.well-known/tdmrep.json · tdm-reservation header', desc: 'Signals “don’t train on this” while still allowing reading (W3C TDM).', tag: 'On' },
            { name: 'noai header', where: 'X-Robots-Tag: noai', desc: 'An extra opt-out header on content pages.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Trust & Verification',
          lead: 'Prove the documents really came from you.',
          items: [
            { name: 'Verified responses', where: '/.well-known/http-message-signatures-directory', desc: 'Signs discovery docs (RFC 9421) so AI assistants can verify they’re from you and unaltered.', tag: 'On' },
            { name: 'OAuth metadata', where: '/.well-known/oauth-protected-resource', desc: 'Points AI assistants at your authorization server (RFC 9728) — the one you declare, or the plugin’s own consent flow automatically while the MCP server is on. A document another plugin registers for this address is never shadowed.', tag: 'Auto' },
            { name: 'security.txt', where: '/.well-known/security.txt', desc: 'A standard security contact for your site (RFC 9116).', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Protection',
          lead: 'Shape who reaches your files — all on your server.',
          items: [
            { name: 'Crawler guard', where: 'your generated files', desc: 'Blocks (403) denylisted or spoofed crawlers at the documents above — and, with verification on, proven impostors: clients claiming a verified bot whose address conclusively fails that operator’s own published check, or carrying a cryptographic signature that fails the math (see Web Bot Auth below). Anything unclear is served, never guessed at, and your Allow and Block lists always outrank the machinery.', tag: 'Opt-in' },
            { name: 'Web Bot Auth', where: 'every request, when “Verify bot identities” is on', desc: 'The emerging standard where AI crawlers cryptographically sign their requests (Google’s crawler and OpenAI already do). Agentimus checks the math on your own server — the only outside fetch is the operator’s public key, cached — so a signed request from a recognised operator is proven genuine, a failed signature is a caught forgery, and the many crawlers that don’t sign yet are simply unsigned, never penalised. The mirror of the plugin’s own document signing: Agentimus signs what it serves and verifies who’s asking.', tag: 'Opt-in' },
            { name: 'Verified bots', where: 'Settings → AI Access', desc: 'Optionally confirm a visitor claiming a known crawler really is that crawler, using what its operator publishes: forward-confirmed reverse DNS (Googlebot, Bingbot, Applebot, DuckDuckBot, Yandex) and published IP-range files (GPTBot, OAI-SearchBot, PerplexityBot — refreshed once a day in the background, never while serving a visitor). The list is yours to edit: switch any crawler off, or add a new operator yourself the day it starts publishing verification data.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Who’s Reading You',
          lead: 'AI traffic in every direction: crawlers taking your content, readers AI sends back, and who authenticates to or acts on the machine surface Agentimus creates. All counted on your own site — no IP addresses by default, nothing sent anywhere.',
          items: [
            { name: 'AI activity', where: 'Dashboard', desc: 'Which AI crawlers fetched which of your files, how often, and when — with a review queue for clients worth a second look. The queue’s count also rides the Agentimus entry in the admin menu as a red badge, visible from every admin screen and kept current while you work — a flagged crawler shows itself without you opening Agentimus. Every address and client row opens the Request Log pre-filtered to it, and hovering a row shows the counts behind its trend arrow. Every standing decision from that queue (blocked, trusted, ignored) is managed in one place, with dates: Settings → AI Access → Manage clients. A client refused at the door still gets a row — marked “refused” and counted toward none of your read totals — so turning enforcement on never hides an impostor from you.', tag: 'On' },
            { name: 'Does that crawler exist?', where: 'review queue', desc: 'A well-behaved crawler names a home page in its own User-Agent and keeps a page there explaining who it is and how to block it. Agentimus checks whether that page answers — once a week per site, in the background, never while you are loading a screen. Three answers, and the third is deliberately empty of judgement: it answers, there is nothing there, or your site could not reach it, which says nothing at all because the fault may be your own network. It changes no verdict and blocks nothing; it is one more honest fact on the card, and the decision stays yours.', tag: 'On' },
            { name: 'Request Log', where: 'More → Request Log', desc: 'Every recorded request, one row each. Filter by client, address, network, verification, user-agent and date to see exactly what a single crawler fetched — the first four take several values at once, so you can watch two crawlers together, or everything spoofed or refused. Every column sorts the whole filtered set, not just the page on screen.', tag: 'On' },
            { name: 'At Cloudflare', where: 'Request Log · Settings → Data Sources', desc: 'Connect Cloudflare with one scoped token and the Request Log grows its missing half: what Cloudflare saw from AI crawlers before your server did — how far each crawler’s requests got (answered from Cloudflare’s cache, reached your server, blocked), and how much data each AI company pulled. When Cloudflare’s behaviour contradicts your declared policy — blocking a crawler you welcome, waving through trainers you’ve opted out of — a pinned warning names it, links the exact Cloudflare screen, and retires by itself within a day of the fix; hide a pin and it stays readable in Settings until the situation really ends. Numbers are copied hourly into your own database, so your history outlives Cloudflare’s short window, and a Refresh link polls on demand. With one optional extra permission on the token (Cache Purge), publishing a post also clears its stale copies from Cloudflare’s cache — the post itself, the lists it appears on (your posts page, its category and tag pages, the feed and your sitemap) and your AI files, which is the one cache no caching plugin can purge for you — and the panel gains a Purge button for everything else; when a purge only gets part of the way through its list, the panel says how much of it landed rather than reading as a flat failure; a switch on the connector card turns the automatic part off. That is the only thing Agentimus ever asks Cloudflare to change; your Cloudflare settings are never touched.', tag: 'Opt-in' },
            { name: 'Agent Access', where: 'More → Agent Access', desc: 'Who authenticates to, and acts on, the machine surface Agentimus creates: assistants being approved; keys being created, first used, renamed or revoked; abilities being run; and requests that were refused, or that probed for abilities which don’t exist. Every row says who — the user and the door the call came through, resolved live: an approved assistant’s own connection, the shared token, or a named application password (a renamed key shows its current name, a revoked one says so plainly). A record, not a guard — nothing here blocks. It stores no IP addresses, so it names the key that was used, not the person, and it sees machine logins only — a normal password sign-in never appears. A brand-new application password is the one worth a second look: it keeps working even after you change your password.', tag: 'On' },
            { name: 'Traffic from AI', where: 'More → Visitors', desc: 'Real visitors who arrived from ChatGPT, Perplexity, Gemini, Claude, Copilot, Grok, DeepSeek and more — day by day, by source and landing page. Some AI visits can’t be detected, so read it as a floor. The dashboard card drills in everywhere: its day bars open the per-day detail, and each source or landing page opens the full report pre-filtered to it. The report’s “Sent by” filter takes several assistants at once, so you can read two side by side.', tag: 'On' },
            { name: 'Weekly email', where: 'your inbox · Settings → Weekly email', desc: 'A short email once a week, on the day and time you pick: AI reads against the week before, readers arriving from AI answers, impostors caught, your readiness score and its move since the last note, and one thing worth doing. Built only from the local data above and sent with WordPress’s own mail — no images, no tracking. A week with nothing to report sends nothing, and every email ends with a one-click stop link.', tag: 'On' },
          { name: 'Find missed AI sources', where: 'Settings → Visit log', desc: 'A diagnostic for the blind spot above: lists the referrers Agentimus could not name, so you can see whether an assistant is being overlooked and add it. Records the site name and utm tag only.', tag: 'Opt-in' },
            { name: 'CDN mode', where: 'Settings → Caching & CDN', desc: 'Counts AI-referred visits in the visitor’s browser instead of on the server, so the number survives a full-page cache. For sites behind Cloudflare “Cache Everything” and the like.', tag: 'Opt-in' },
            { name: 'Keep AI files out of your cache', where: 'Settings → Caching & CDN', desc: 'If a cache or CDN in front of your site serves stored copies of your AI files (llms.txt, the .well-known docs, the change feed), those fetches never reach WordPress — the log under-counts them and the change feed can go stale. This asks caches not to store those files, so each fetch is counted and current. Works with any cache that respects the standard no-store header; the readiness report links to it when it spots a cache in front of you.', tag: 'Opt-in' },
            { name: 'Refresh AI files when content changes', where: 'Settings → Caching & CDN', desc: 'When you publish or edit a post, Agentimus asks every page cache it can detect (WP Rocket, Nginx Helper, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler…) to drop your llms.txt, the .well-known docs, the change feed and the edited post’s .md twin — files a cache plugin otherwise doesn’t know to refresh, so AI assistants never get a stale copy after an edit. On by default; a no-op when no page cache is installed.', tag: 'On' },
            { name: 'How long records live', where: 'Settings → Visit log', desc: 'Choose a retention period, whether old records are deleted nightly, and a hard size cap. The cap always applies — the log can never grow without limit.', tag: 'On' },
          ],
        },
        {
          title: 'Visibility',
          lead: 'One always-on screen, three views: how search treated your pages, whether the engines’ indexes hold you, and whether assistants actually cite you when asked. Each feature switches on inside its own view, and each keeps its data on your server.',
          items: [
            { name: 'In Bing’s Index', where: 'Visibility → In the Index · Settings → Data Sources', desc: 'Bing’s index is what ChatGPT search reads today — Microsoft Copilot too — so “is Bing seeing you cleanly” is a measurable answer to “can AI search find me”. Connect Bing Webmaster Tools with one read-only key (Agentimus even prints the verification tag for you — no file upload, no DNS) and this tab shows how many pages sit in that index day by day, how many were crawled, crawl errors, and pages robots.txt closes off — with a warning pin when Bing’s view contradicts your own policy. Click any day’s bar for that day’s full crawl picture — and a day Bing answered for without any numbers in it shows as a gap that says so, rather than as a fall to zero pages. Ask Bing about a page answers live for one URL — discovered when, last crawled when; Bing’s API has no per-page “indexed: yes/no”, and the card says that too, so the tiles stay the index-level truth. Numbers are polled daily into your own database, so your history keeps growing where Bing’s own window ends. The card also carries Bing’s own record of your sitemap (registered or not, last read when, how many URLs) and, if you turn it on, the IndexNow switch — announcing each publish, edit or removal to search engines the moment it happens, sending only the changed addresses.', tag: 'Opt-in' },
            { name: 'In Google’s Index', where: 'Visibility → In the Index · Settings → Data Sources', desc: 'Google’s index is what AI Overviews, AI Mode and Gemini read — but Google publishes no bulk index report and allows 2,000 URL inspections a day, so a big site can’t be snapshot in one go, whatever a tool claims. Agentimus does the honest version in three tiers: a daily watchlist — your homepage, busiest pages, newest posts (where “is it indexed yet?” actually lives) — plus every page a check found unhealthy, which re-joins the daily check until it heals (up to 20 a day, the one asked about longest ago first), plus a whole-site rotation of up to 100 pages a day, so a small site is fully covered every day and a big one on a stated cadence. Each answer is Google’s own wording: on Google or not, when Googlebot last visited, whether robots.txt or a noindex is in the way, whether Google chose a different canonical, fetch failures and soft 404s, whether any sitemap lists the page, and rich-result issues. The card shows what needs a look, and nothing else: problems grouped by state — never seen, discovered, crawled-but-left-out, wrong address, sent elsewhere by the site itself — with Google’s own phrase quoted under each heading and its true count beside it, however many pages it holds. A page that heals says so for about two days instead of quietly vanishing. Healthy pages are counted, never listed — including the ones checked daily, because which pages get asked about first is a schedule, not a list. Every row deep-links to that URL’s inspection in Search Console — one click from Request indexing — and a lookup box answers for any single page from the stored checks, no quota spent. When the stored answer isn’t current enough — you just fixed a page, or you’re about to share it — Re-check asks Google about that one page there and then, spending one of the day’s inspections and storing what comes back, so the card never shows yesterday’s verdict beside today’s. And because Google keeps no memory of “indexing requested” — a fresh inspection simply offers the button again, and no API exposes a pending state — opening a row in Search Console is remembered HERE instead: the row reads “console opened” with the date, which is a true statement about what you did rather than a guess about Google’s queue. The card also reports when Google last managed to read the sitemap you registered — a registration pointing at a moved address fails silently, and only Google’s own bookkeeping can see it. Uses the same read-only key as Search Performance; Check Now runs the sweep on demand, and Cancel stops one after the answer already in flight — the rest of the queue waits for the daily run rather than being discarded.', tag: 'Opt-in' },
            { name: 'Citation checks', where: 'Visibility → Citations', desc: 'Off by default, and set up right inside the Citations tab — no hunting through Settings; the tab explains what it does first, and “Set up citation checks” opens the setup without running or spending anything. Turned on, Agentimus asks ChatGPT, Perplexity, Gemini and Claude the questions you set — with your own keys — tracking whether each brand or product gets mentioned, linked and ranked against its rivals, and feeds the “Cited” rung of your score. Each answer lists the sites it actually cited — by name, not by whatever redirect the assistant wrapped them in — and the verdict opens the answer in full, so you can read what was said about you and see what it was based on. Results and keys stay on your server (keys encrypted at rest). Everything saves as you change it; turning the checks off keeps your targets and history.', tag: 'Opt-in' },
            { name: 'Questions worth asking', where: 'Visibility → Citations → Settings', desc: 'Say what each tracked thing is — the category people would look for it in, like “WordPress SEO plugin” — and Agentimus suggests the questions people actually type (“What is the best WordPress SEO plugin?”). If you’ve set up an AI provider in WordPress, “Suggest with AI” asks it for a wider spread. The good questions never mention your name: the point is to find out whether an assistant reaches for you unprompted. Suggestions are only ever offered — you choose which to keep.' },
          ],
        },
        {
          title: 'Classic Search, Too',
          lead: 'With no SEO plugin installed, Agentimus covers the search basics — and now reads the results back to you. Connect Google, Bing, or both: the numbers are the engines’ own, stored on your server, and turned into a short list of pages worth an hour of your time.',
          items: [
            { name: 'Google Search Console', where: 'Settings → Data Sources', desc: 'Off until you connect it. Google reports which searches brought people to each of your pages — how often you appeared, how often they clicked, and where you ranked. Agentimus reads it with a service-account key you create in your own Google Cloud and paste here: no “Connect with Google” button, because those route your data through the plugin maker’s server. Yours talks to Google directly, costs nothing, asks for no billing details, and is revocable in your own console any time. The card walks the five steps and reads your key back to you, so you know exactly which address to grant access to.', tag: 'Opt-in' },
            { name: 'Search Performance', where: 'Visibility → Search', desc: 'The plain answer to “how did search go?” — times shown, visits, click rate and average rank for the window, then your top searches and the pages that earned them. Connect both engines and a switch appears; they are never merged into one figure, because Google and Bing count different searchers. Nothing is estimated and nothing is removed: every number is what the engine itself reported. Searches that are really scraper probes — the ones using site: or intext: operators — are marked rather than hidden, and the screen says what share of your views they were; on many sites that share is big enough to drag the click rate well below what real visitors give you. Everything is stored in your own database, so the history outlives the engines’ own reporting windows — which is what powers each engine’s week-on-week line — Google’s from Search Console’s daily split, Bing’s from its own daily traffic report — appearing only once 14 days of history exist, because before that a comparison would be a guess; a Google Discover line speaks only when Discover actually showed you. On Bing the headline tiles come from that site-wide daily report while the top lists are Bing’s samples — counted separately, and the card says so. If you run the MCP server, a connected assistant can read this same summary and answer “how is my site doing in search?” from your real numbers.', tag: 'Opt-in' },
            { name: 'Search Opportunities', where: 'Visibility → Search, under the report', desc: 'The same numbers turned into a to-do list, seated directly under Search Performance: pages ranking 8–20 that one improvement could lift onto page one, and pages already on page one that people scroll past. “Not clicked enough” is measured against your own site’s page-one click rate — never an industry average — so the bar is fair to you. Each group says what to do in plain words, and every page with a post behind it opens straight on the “Search & AI” box where its title and description live — a page without one (the homepage on many sites, an archive) says so instead, and can still be set aside. Scraper probes are excluded here — no title rewrite makes a machine click — and the screen states how much it left out and lets you read the exact searches, so you can judge that filter instead of trusting it. A page whose own work is finished — answered, titled and linked — stops asking: it drops to the foot of its group, loses the fix links it no longer needs, and says your side is done and which report it is waiting on. Anything you don’t want suggestions for gets set aside in its own visible list, restorable in a click. A connected assistant can read this worklist too, so you can ask it to take a page from here and rewrite the title and description for you — with the write tools on, it can save the draft as well.', tag: 'Opt-in' },
            { name: 'Template tags', where: 'your theme', desc: 'Three plain functions a theme can call — agentimus_get_topics(), agentimus_get_description() and agentimus_get_media_context() — so what Agentimus knows can appear on the page for a person, not only in the structured data for a machine. Each reads the same resolver the machine surfaces read, so what you print and what an assistant receives can never disagree, and each returns nothing at all rather than a guess when a feature is off or a field is blank. Documented in the developer reference.', tag: 'On' },
          ],
        },
      ],
      faqs: [
        { q: 'Do I need to configure anything?', a: 'No. Agentimus works the moment it’s activated, with safe defaults. Open Settings only if you want to add your identity details or change a default.' },
        { q: 'When does Agentimus read my content?', a: 'In the background, a few pages at a time, and again within about a minute of you saving a page. It does not need WordPress’s scheduled jobs to be working: many hosts never run them — a page served from a cache never runs PHP, so the request that would have started them never arrives — so Agentimus watches its own jobs, and when one is overdue it does that work while you are on an Agentimus screen. A site whose scheduled jobs run normally loads nothing extra, and a run stands down the moment WordPress starts one of its own.' },
        { q: 'Is there a dark mode?', a: 'Yes. The sun-or-moon button in the top-right corner switches every Agentimus screen between light and dark. Until you first press it, the look quietly follows your device’s own setting; from the first press on it’s your choice, remembered per browser — a viewing preference, not a site setting, so it changes nothing for anyone else. The WordPress admin around the plugin keeps its usual colors either way.' },
        { q: 'Can AI help me write the description, topics and fixes?', a: 'Yes, if you’ve connected an AI provider in WordPress (Settings → Connectors, with your own key). “Draft with AI” writes the AI description for a page, “Suggest with AI” fills its topics, and “Fix with AI” drafts a concrete fix for each readability warning — always as editable text you review, never saved for you. Agentimus routes everything through WordPress’s AI Client, so it never sees your API key, and the buttons stay hidden until a provider is set up. If your site defines Content Guidelines, every draft follows them.' },
        { q: 'Do the citation checks use the AI provider I set up in WordPress?', a: 'No — it keeps its own keys, on purpose. A visibility check is graded on the sources each assistant cited, and WordPress’s shared connectors hand back only the answer text: the list of cited sources is dropped before Agentimus could read it. Reading those sources means talking to each assistant’s own API, so the citation checks keep their own keys (Visibility → Citations → Settings). They stay on your server, encrypted at rest, and are used for nothing else.' },
        { q: 'What is the AEO/GEO score?', a: 'One number (0–100) on your dashboard that blends five rungs — Findable, Readable, Trusted, Optimized and Cited — into a single measure of how ready your site is for AI assistants, with the single most useful next step. Cited only counts when you turn on citation tracking; otherwise its weight is redistributed, so you’re never penalised for a feature you don’t use.' },
        { q: 'Why did my score change when I updated?', a: 'The Optimized part of the score used to read your 25 most recently edited posts and treat that as the whole site. From 1.37.0 it reads every published page instead. On most sites the number moves, and the list of pages worth fixing gets longer — often much longer. Nothing broke and nothing about your content changed: those pages were always there, and nothing was looking at them. The reading happens in the background. Depending on how much you have published it takes minutes or hours, so the number can keep settling for a while after the update. The card tells you how many pages are still to be read while that is going on. From 1.38.0 this can happen again on any update that changes what the checks look for: a stored verdict now records which checks produced it, so when they change your content is read again instead of keeping an answer to a question nobody asks any more. Your lists stay on screen while that happens — each page keeps what it last said, marked as being read again — and the counts settle once the reading is done. Two new checks also arrived in 1.37.0 and can add warnings of their own: alt text that is only a file name no longer counts as a description, and your featured image is now checked for a description too — it is drawn by your theme, so nothing had ever looked at it. On a site whose media library has no alt text, that second one can flag a lot of posts at once. Neither is a fault in your site; they are things nobody was measuring before.' },
        { q: 'Can I see exactly what AI assistants receive?', a: 'Yes. Open Readiness → Agent preview to see the exact JSON-LD and Markdown for your whole site or any page or post, and copy it — no need to view page source. A matching read-only preview also sits in the post editor. It even shows what would ship when a feature is off or an SEO plugin owns your schema.' },
        { q: 'Is my private or password-protected content exposed?', a: 'No. Drafts, private and password-protected posts are excluded from every output — llms.txt, Markdown, JSON-LD and the sitemap. Only published, publicly-visible content is ever described.' },
        { q: 'Will this block Google or real search engines?', a: 'No. Blocking is opt-in and aimed at AI training crawlers and spoofed crawlers. Real search engines are recognised and never blocked by default — and with “Verify bot identities” on, a scanner merely wearing a crawler’s name is told apart from the real one by the operator’s own published checks, so the genuine crawler stays welcome while the fake loses the disguise. Anything the checks can’t settle is served, never guessed at.' },
        { q: 'Where do I manage the clients I’ve blocked, allowed or ignored?', a: 'Settings → AI Access → Manage clients. One dialog with three tabs — Blocked, Allowed, Ignored — showing each client’s identity (for known crawlers), when you decided, and an instant undo: Unblock, Stop trusting or Stop ignoring. Decisions made from now on carry their date; older entries simply show none rather than an invented one. An ignored client also returns to the review bell on its own if its traffic materially grows — Stop ignoring just brings it back sooner.' },
        { q: 'What does “Verified responses” (signing) do?', a: 'It signs your discovery documents (RFC 9421) so an AI assistant can confirm they really came from your server and weren’t altered in transit. The key is generated on your server and never leaves it.' },
        { q: 'Does it slow my site down?', a: 'Barely. Generated documents are cached, JSON-LD is tiny, and the plugin makes no external calls on the front end — nothing is fetched from another server while your pages load. (The optional citation checks run only in the admin or on a schedule, never during a page view.) Reading your pages for the content checks is the one genuinely expensive thing Agentimus does, so it never happens while a visitor is waiting: it runs in the background, a few pages at a time, and stops early on a slow host rather than holding a request open. A content type with a very large number of items starts switched off, with its count shown, so nothing begins reading thousands of pages without you asking.' },
        { q: 'Does it collect personal data?', a: 'By default, no — the activity log stores no IP addresses, no identities and no query strings, and logged-in admins are skipped. Two optional settings, both off by default, add to that: “Store IP addresses for flagged clients” can record IPs, but only for crawlers flagged as impersonators or spoofs so you can block them; and “Find missed AI sources” records the referring site’s name and the link’s utm_source tag for visits it couldn’t attribute, as a daily count rather than against any visit. See “Privacy & data” above.' },
        { q: 'Where do I see what AI is doing on my site?', a: 'Three places, all on your own server. The Dashboard summarises both directions — which AI crawlers fetched your files, and how many readers AI sent you. More → Visitors is the full report on those visitors: day by day, by assistant and landing page. More → Request Log is the full report on the crawlers: one row per request, filterable by client, address, network and date.' },
        { q: 'Why am I getting a weekly email from Agentimus?', a: 'That’s the weekly digest — a short note about what AI did on your site: AI reads, readers arriving from AI answers, impostors caught, and your readiness score with its change since the last note. It’s on by default because the plugin’s work is otherwise invisible, and it’s built only from the data already stored on your site — the email to your own inbox is the only thing that leaves the server. A week with nothing to report sends nothing. Stop it with the one-click link inside any of the emails, or under Settings → Weekly email, where you can also pick the day and time it arrives, change the address and send yourself a test.' },
        { q: 'Does Agentimus post to social media for me?', a: 'Only what you give it, at a time you choose. Every post has a Share tab that writes the text for you. If you connect X (Twitter), LinkedIn or Telegram under Integrations → Sharing, those cards get a “Send it later” row, which puts the draft in the queue. It never posts when you publish a post — there is no setting anywhere that posts by itself. What is queued goes out exactly as you left it, so editing the post later does not change what is sent. More → Announcements holds the whole record: you can still cancel an announcement that is waiting, or send it early. If a post fails, it waits for you instead of trying again, because an announcement arriving twice looks like spam. Events are the other direction and never reach your readers: a new finding, a caught impostor or the weekly digest numbers, sent to you on Telegram, Slack, Discord, a spreadsheet or your own webhook.' },
        { q: 'How do I know if an AI assistant or app logged into my site?', a: 'More → Agent Access records it: when an application password (the key a program uses to reach WordPress as you) is created, first used, renamed or revoked; when an ability is run; and when a request is refused or someone probes for abilities that don’t exist. Every entry says who — the user and the named key it used. It’s a record, not a guard — it never blocks anything — and it stores no IP addresses, so it names the key that was used, not the person. A new application password is especially worth checking: it keeps working even after you change your password, which is exactly why one appearing unannounced matters.' },
        { q: 'Can AI write a whole post for me?', a: 'Yes — the writing assistant (the spark button on Agentimus’s screens) turns a described idea into a complete draft. It proposes an outline you edit first, then writes every section in parallel — long articles aren’t capped by a single AI response — with the title, AI description, topics and suggested categories and tags, and shows you everything before anything is saved. Create draft opens the post in the editor, where image placeholders arrive alt-filled — fill them from your library or generate them with AI. Pick what you are writing first — Posts, Pages or any content type you have enabled: a page is written as a page with no invented sections and no image slots, and a product, event, recipe or book gets frames written for that shape rather than blog frames. Revising something that already exists happens in the block editor instead (see the next question). It needs “Let connected agents write” plus an AI provider under Settings → AI, and it never publishes: drafts and pending review only.' },
        { q: 'Does Agentimus run an MCP server?', a: 'Yes — as an opt-in, on WordPress 6.9 or newer. Turn on Settings → Discovery → MCP server and the AI assistants you already use can talk to your site over the Model Context Protocol and run the same permission-checked tools your admin AI gets — the read-only ones, plus the write tools only if you separately allow those (see the next question). Everything needed ships with the plugin. Connecting is usually one approval: give the assistant your server address and it asks you for permission on a page served by your own site, where you choose read-only or read-and-write. Claude, Cursor and ChatGPT work this way today. Assistants that cannot ask — Codex at the time of writing — take a shared token instead: create one on the card and send it as a Bearer header. A WordPress application password still works too, and suits anyone who wants one key per tool tied to a specific user. The handshake is public — the server tells anyone its name and protocol version, so a client can see there is a server here before it has a key — but the tool list itself needs one, every tool run signs in, every call is recorded under More → Agent Access, and turning the switch off disconnects connected assistants immediately. Worth knowing: a connection token or an approved assistant’s key works only on this server’s address, while an application password signs in as that user across your whole REST API.' },
        { q: 'Can an AI assistant write to my site through MCP?', a: 'Only if you say so, twice. The MCP server starts read-only; a second switch (“Let connected agents write”) adds the write tools — draft and edit posts and pages complete with categories, tags and a featured image (searched out of your media library — a read-only tool lists what is there, matching titles and alt text — or imported from a URL), change one passage of a page without touching a word of the rest, describe an image so screen readers and image search can read it — either the copy in your media library or the one inside a particular page, which are genuinely two different fixes, because a page keeps its own copy of an image’s description from the moment you insert it, set their AI topics and descriptions, set the search a page should answer and the title a search result shows, and apply Readiness fixes (a fixed list that can only turn documented features on, never loosen a protection). It can also read the categories and tags your site already uses, so it files a post under the ones you have instead of inventing near-duplicates. Three write tools are not about content at all, and are worth knowing by name: it can set a page aside so no check grades it, try a failed announcement again, and answer the review queue — block, allow, set aside for now, or undo a decision — and re-run the identity check on one client. Blocking is the one that changes what your site refuses at the door, so it is worth saying what it cannot do: the rule is derived by the same guard your own screen uses, which refuses to block a search engine or anything you have allowed, and clearing the log is not offered at all because your own screen asks you to confirm it. Even then, assistants can’t publish: they leave drafts and pending posts for your review, unless you flip a third switch that allows going live. Every write runs as the signed-in user — an assistant can never do more than that user could in the editor: filing under existing categories, creating new ones, and uploading images each follow that user’s own permissions — and every call is recorded under More → Agent Access.' },
        { q: 'What happens to AI training of my content?', a: 'By default Agentimus signals “do not train” (via tdmrep.json, the tdm-reservation header and robots Content-Signal) while still letting search engines and AI assistants read it. You control all of this in Settings.' },
        { q: 'Why is there no Google button in the Ask AI row?', a: 'Because your own bot policy forbids it — and the row is honest about that. Google made a single robots token, Google-Extended, govern both AI training and Gemini/AI-Mode reading. Agentimus blocks AI training by default, which therefore also tells Google’s assistant it may not read your pages — its button could only ever answer “page inaccessible,” so Agentimus hides it rather than hand readers a dead end. Allow Google-Extended (or add it to your always-allowed list) under Settings → AI Access and the button returns. Blocking GPTBot or ClaudeBot hides nothing: OpenAI and Anthropic fetch reader-initiated requests with separate crawlers (ChatGPT-User, Claude-User) that a training block never touches.' },
      ],
      // Open standards the discovery output speaks — shown as plain chips.
      standards: [
        'WP_Discovery', 'schema.org', 'OpenAPI 3.1', 'MCP', 'A2A agent cards',
        'RFC 9421 signatures', 'RFC 9728 OAuth', 'RFC 9727 api-catalog',
        'RFC 9116 security.txt', 'W3C TDM',
      ],
    };
  },
  computed: {
    // The "On this page" jump menu: every section in page order, with the
    // screens and feature groups indented under their parents. Values are the
    // anchor ids the picker scrolls to.
    tocOptions() {
      return [
        { value: 'ar-about-screens', label: 'The Screens' },
        ...this.screens.map((s) => ({ value: `ar-about-s-${s.id}`, label: ` ${s.title}` })),
        { value: 'ar-about-features', label: 'What It Does' },
        ...this.featureGroups.map((g, gi) => ({ value: `ar-about-g-${gi}`, label: ` ${g.title}` })),
        { value: 'ar-about-cantdo', label: 'What It Can’t Do' },
        { value: 'ar-about-privacy', label: 'Privacy & Data' },
        { value: 'ar-about-protocol', label: 'The Protocol' },
        { value: 'ar-about-faq', label: 'Questions & Answers' },
      ];
    },
    protocolVersion() { return this.protocol.version || '1.0'; },
    hook() { return this.protocol.hook || 'wpdiscovery_register'; },
    specUrl() { return this.protocol.specUrl || ''; },
    schemaUrl() { return this.protocol.schemaUrl || ''; },
    devSnippet() {
      return "add_action( '" + this.hook + "', function ( $registry ) {\n"
        + "    $registry->register( [\n"
        + "        'id'    => 'my-plugin',\n"
        + "        'title' => 'My Plugin',\n"
        + "        'type'  => 'commerce',\n"
        + "    ] );\n"
        + "} );";
    },
  },
  mounted() {
    this.teleportReady = true;
    window.addEventListener('scroll', this.spy, { passive: true });
    this.$nextTick(() => this.applySpy());
  },
  beforeUnmount() {
    window.removeEventListener('scroll', this.spy);
    if (this._spyRaf) cancelAnimationFrame(this._spyRaf);
    clearTimeout(this._spyT);
  },
  methods: {
    async copySetup() {
      await copyText(this.support.factsLean || '');
      this.setupCopied = true;
      clearTimeout(this._setupTimer);
      this._setupTimer = setTimeout(() => { this.setupCopied = false; }, 2000);
    },
    toggleFaq(i) { this.openFaq = this.openFaq === i ? -1 : i; },
    // A pick is a command: jump there and show the destination at once. The
    // spy is locked until the smooth scroll settles (scrollend where it
    // exists, a timeout everywhere), then resumes following the reader.
    jump(id) {
      const el = document.getElementById(id);
      if (!el) return;
      this.tocCurrent = id;
      this.spyLock = true;
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      clearTimeout(this._spyT);
      const release = () => {
        if (!this.spyLock) return;
        this.spyLock = false;
        window.removeEventListener('scrollend', release);
        this.applySpy();
      };
      window.addEventListener('scrollend', release, { once: true });
      this._spyT = setTimeout(release, 1000);
    },
    // Scrollspy, one frame at a time: the current section is the LAST anchor
    // whose top sits above the reading line (just under the sticky bars).
    spy() {
      if (!this.active || this.spyLock || this._spyRaf) return;
      this._spyRaf = requestAnimationFrame(() => {
        this._spyRaf = null;
        this.applySpy();
      });
    },
    applySpy() {
      if (this.spyLock) return;
      const sticky = document.querySelector('.ar__sticky');
      const line = (sticky ? sticky.getBoundingClientRect().bottom : 150) + 30;
      let current = '';
      for (const o of this.tocOptions) {
        const el = document.getElementById(o.value);
        if (!el) continue;
        if (el.getBoundingClientRect().top <= line) current = o.value;
        else if (current) break; // anchors come in page order — we're past the line
      }
      this.tocCurrent = current;
    },
  },
};
</script>

<template>
  <div class="ar-about">
    <!-- "On this page" — a jump menu over every section, screen and feature
         group, teleported into the sticky page header so it's always in reach.
         Deliberately NOT a text search: the browser's own find does words
         better, and it can only work when nothing on the page is hidden. -->
    <Teleport v-if="teleportReady && active" to="#ar-pagehead-tools">
      <div class="ar-about-toc">
        <!-- NOT v-model: a user pick is a jump command (no watcher loop), while
             the scrollspy writes tocCurrent for display only. -->
        <SelectMenu :model-value="tocCurrent" :options="tocOptions" placeholder="On this page…" aria-label="Jump to a section" @update:model-value="jump" />
      </div>
    </Teleport>

    <!-- The working manual, screen by screen — in the nav's own order. -->
    <section id="ar-about-screens" class="ar-card">
      <h2 class="ar-card__title">The Screens</h2>
      <p class="ar-card__lead">
        What each screen is for, what you do on it, and the facts worth knowing so nothing
        surprises you.
      </p>
      <div v-for="s in screens" :id="`ar-about-s-${s.id}`" :key="s.id" class="ar-about-screen">
        <h3 class="ar-about-feat__title">{{ s.title }} <span class="ar-about-screen__where">{{ s.where }}</span></h3>
        <p class="ar-about-screen__purpose">{{ s.purpose }}</p>
        <p class="ar-about-screen__do"><strong>What you do here:</strong> {{ s.actions }}</p>
        <p class="ar-about-screen__k">Worth knowing</p>
        <ul class="ar-about-screen__facts">
          <li v-for="f in s.facts" :key="f.t" :class="`is-${f.k}`">
            <span class="ar-about-screen__ico" aria-hidden="true">
              <!-- notice -->
              <svg v-if="f.k === 'info'" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9" /><path d="M12 11v5" /><path d="M12 7.6v.4" /></svg>
              <!-- alert -->
              <svg v-else-if="f.k === 'warn'" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 21.5 20h-19z" /><path d="M12 10v4.5" /><path d="M12 17.2v.3" /></svg>
              <!-- lightbulb -->
              <svg v-else viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0-3.4 10.9c.8.6 1.4 1.4 1.4 2.1h4c0-.7.6-1.5 1.4-2.1A6 6 0 0 0 12 3z" /><path d="M10 19h4" /><path d="M10.7 21.5h2.6" /></svg>
            </span>
            <span class="ar-about-screen__ft">{{ f.t }}</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Features -->
    <section id="ar-about-features" class="ar-card">
      <h2 class="ar-card__title">What It Does</h2>
      <p class="ar-card__lead">
        Agentimus does two things for the age of AI assistants. <strong>First, it makes your site easy for AI to
        read and quote.</strong> It publishes the documents AI assistants look for. It offers your content in
        formats machines can read. And it adds signals that show your site is genuine. All of that becomes one
        AEO/GEO score, with the next thing to improve named for you.
        <strong>Second, it lets the AI tools you already use run your site over MCP.</strong> Turn on the built-in
        Model Context Protocol server, and Claude, Cursor or Codex can read your readiness, traffic and crawler
        data. A separate switch allows writing: the same assistant can then draft and edit posts and pages, with
        their categories, tags, featured image, AI topics and descriptions. A third switch lets it publish. Every
        write runs as the signed-in user, is permission-checked, and is recorded. So nobody has to open wp-admin
        to get a post out.
      </p>
      <p class="ar-card__lead">
        Everything here is on by default unless marked otherwise. The write and MCP features are the exception —
        they stay off until you turn them on. See the live documents on the
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'discovery' })">Discovery</button>
        tab and change any default under
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' })">Settings</button>.
        New here? The full
        <a class="ar-linkbtn" href="https://heera.github.io/agentimus/" target="_blank" rel="noopener">documentation</a>
        walks through every feature, step by step.
      </p>

      <div v-for="(g, gi) in featureGroups" :id="`ar-about-g-${gi}`" :key="g.title" class="ar-about-feat">
        <div class="ar-about-feat__head">
          <h3 class="ar-about-feat__title">{{ g.title }}</h3>
          <p class="ar-about-feat__lead">{{ g.lead }}</p>
        </div>
        <ul class="ar-about-list">
          <li v-for="it in g.items" :key="it.name" class="ar-about-item">
            <div class="ar-about-item__main">
              <div class="ar-about-item__top">
                <span class="ar-about-item__name">{{ it.name }}</span>
                <code class="ar-about-item__where">{{ it.where }}</code>
                <span class="ar-about-tag" :class="`is-${it.tag === 'On' ? 'on' : 'opt'}`">{{ it.tag }}</span>
              </div>
              <p class="ar-about-item__desc">{{ it.desc }}</p>
            </div>
          </li>
        </ul>
      </div>
    </section>

    <!-- Honest expectations -->
    <section id="ar-about-cantdo" class="ar-card">
      <h2 class="ar-card__title">What It Can’t Do</h2>
      <p class="ar-card__lead">
        Agentimus makes your site <strong>discoverable and correctly understood</strong> — when an AI assistant
        looks at your site, it finds your content, reads a clean version, and describes you accurately. That’s
        what a discovery layer controls, and it does it well. It can’t make an assistant <strong>spontaneously
        mention you</strong> when someone asks a broad question — that’s authority and reputation, earned over
        time through content others reference. No plugin can manufacture it, and tools promising “instant AI
        visibility” are overselling. Agentimus makes sure that when your work does bring an AI assistant to your door,
        nothing is lost in translation.
      </p>
    </section>

    <!-- Privacy & data -->
    <section id="ar-about-privacy" class="ar-card ar-about-priv">
      <h2 class="ar-card__title">Privacy &amp; Data</h2>
      <p class="ar-card__lead">
        A discovery plugin should be honest about itself. Here is exactly what leaves your server, what’s
        published, and what’s stored — verified against the source code.
      </p>

      <div class="ar-about-priv__grid">
        <div class="ar-about-priv__cell ar-about-priv__cell--head">
          <span class="ar-about-priv__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.4-3 7.4-7 9-4-1.6-7-4.6-7-9V6z"/></svg>
          </span>
          <div>
            <h3>What Leaves Your Server: Nothing by Default</h3>
            <p>
              Agentimus never calls home. No usage tracking, and no settings fetched from anywhere else.
              Calls out of your server come only from features you switch on yourself. All of them are off
              to begin with, and each uses a key you provide, stored encrypted on your server and masked in
              the admin. <strong>Citation checks</strong>: asks the assistants you chose — OpenAI,
              Perplexity, Gemini, Claude — with your own AI keys, to see whether they cite you.
              <strong>At Cloudflare</strong>: one hourly read of Cloudflare’s analytics with your own
              read-only token. Nothing about your site is sent; only numbers come back.
              <strong>In Bing’s Index</strong>: one daily read of Bing Webmaster Tools with your own
              read-only key, plus the page lookups you run by hand. Again, numbers come in and nothing
              goes out. <strong>Search Performance</strong>: one daily read of Google Search Console with
              your own service-account key. Agentimus signs in directly from your server, with no other
              company in between, and only your own search statistics come back.
              <strong>Visitors</strong>: the same key, given Viewer access to your GA4 property, reads
              your own visit totals once a day. Nothing about your visitors goes out.
              <strong>Verify bot identities</strong>: makes small DNS lookups. Once a day it also
              downloads the crawler-IP lists that crawler operators publish — Google’s googlebot.json,
              OpenAI’s gptbot.json and others — so impostors can be caught. Only those public files are
              fetched. Nothing about your site or your visitors is ever sent. The “Verify live” readiness
              check still runs in <em>your browser</em>, against your own URLs. And one email: the
              <strong>weekly digest</strong>, on by default and stopped with one click, goes only to the
              inbox you chose, carrying your own numbers and nothing else.
            </p>
          </div>
        </div>

        <div class="ar-about-priv__cell">
          <h3>What’s Published Publicly</h3>
          <p>
            The documents listed under “What It Does”. Publishing them is the whole purpose of Agentimus,
            and they describe only content that is already public. See the live list on the
            <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'discovery' })">Discovery</button>
            tab.
          </p>
        </div>

        <div class="ar-about-priv__cell">
          <h3>What’s Stored on Your Site</h3>
          <p>
            An optional, local-only activity log: which address was fetched, the client name, a truncated
            user-agent, and the time. Plus <em>daily aggregate counts</em> of human visits referred by AI
            assistants — a total per day, source and landing page, never a row that stands for one person.
            Kept for a period you choose (30 days by default), pruned nightly, under a size cap that always
            applies. The optional Cloudflare, Bing and Google connections add three more local tables. They
            hold hourly counts per crawler, daily index numbers, and the most recent search performance:
            which search, which of your pages, how many times it was shown, and how many clicks it got.
            These are all totals the engines themselves report. Those services never say who searched, so
            no such thing reaches your site either.
          </p>
          <ul class="ar-about-not">
            <li>No IP addresses in the log</li>
            <li>No identities or logins (admins are skipped)</li>
            <li>No emails</li>
            <li>No query strings or full URLs</li>
          </ul>
          <p class="ar-about-priv__foot">No personal data by default, and nothing that GDPR applies to. Two optional
            settings change that, and both stay off unless you switch them on.
            <strong>Store IP addresses for flagged clients</strong> can record IP addresses, but only for crawlers
            flagged as impostors or scanners. They are kept briefly on your own server, and deleted when you switch
            the setting off. <strong>Find missed AI sources</strong> handles visits it could not trace to a known
            assistant. For those it records the name of the site the reader came from, and the
            <code>utm_source</code> tag on the link. That tag is the only part of a query string Agentimus ever
            stores, and it is kept as a daily count, never against one visit. Turn either on and that data becomes
            yours to declare, so name it in your privacy policy. Your signing key never leaves the server, and is
            not loaded on every page. Uninstalling removes the tables, the settings and the key.</p>
        </div>
      </div>
    </section>

    <!-- WP_Discovery Protocol -->
    <section id="ar-about-protocol" class="ar-card ar-about-proto">
      <h2 class="ar-card__title">Built on the WP_Discovery Protocol</h2>
      <p class="ar-card__lead">
        Agentimus is the reference implementation of <strong>WP_Discovery</strong> — a small, open,
        vendor-neutral standard for how a WordPress site describes itself to AI assistants. The output isn’t a
        proprietary format: it’s an open spec any tool can read, and any plugin can extend.
      </p>

      <ul class="ar-about-chips">
        <li v-for="s in standards" :key="s" class="ar-about-chip">{{ s }}</li>
      </ul>

      <div class="ar-about-proto__meta">
        <div class="ar-about-proto__fact">
          <span class="ar-about-proto__k">Wire format</span>
          <span class="ar-about-proto__v">v{{ protocolVersion }}</span>
        </div>
        <div class="ar-about-proto__fact">
          <span class="ar-about-proto__k">Validates against</span>
          <a v-if="schemaUrl" class="ar-about-proto__v" :href="schemaUrl" target="_blank" rel="noopener">JSON Schema</a>
          <span v-else class="ar-about-proto__v">JSON Schema</span>
        </div>
        <div class="ar-about-proto__fact">
          <span class="ar-about-proto__k">Specification</span>
          <a v-if="specUrl" class="ar-about-proto__v" :href="specUrl" target="_blank" rel="noopener">Read the spec</a>
          <span v-else class="ar-about-proto__v">Open spec</span>
        </div>
      </div>

      <div class="ar-about-proto__dev">
        <h3>Extend It From Your Own Plugin</h3>
        <p>
          Any plugin can add itself to the discovery output with one hook — no hard dependency on Agentimus.
          If no discovery engine is active, the action simply never fires.
        </p>
        <pre class="ar-about-snippet"><code>{{ devSnippet }}</code></pre>
      </div>
    </section>

    <!-- FAQ -->
    <section id="ar-about-faq" class="ar-card">
      <h2 class="ar-card__title">Questions &amp; Answers</h2>
      <ul class="ar-about-faq">
        <li v-for="(f, i) in faqs" :key="f.q" class="ar-about-faq__item" :class="{ 'is-open': openFaq === i }">
          <button
            type="button"
            class="ar-about-faq__q"
            :aria-expanded="openFaq === i"
            @click="toggleFaq(i)"
          >
            <span>{{ f.q }}</span>
            <span class="ar-about-faq__caret" aria-hidden="true">▸</span>
          </button>
          <p v-show="openFaq === i" class="ar-about-faq__a">{{ f.a }}</p>
        </li>
      </ul>
    </section>

    <!-- Documentation & links -->
    <section class="ar-card">
      <h2 class="ar-card__title">Documentation &amp; Links</h2>
      <p class="ar-card__lead">
        Step-by-step guides for every feature, plus a developer reference — the full documentation lives
        online, and the source is on GitHub.
      </p>
      <p class="ar-card__lead">
        <a class="ar-linkbtn" href="https://heera.github.io/agentimus/" target="_blank" rel="noopener">Documentation</a>
        &nbsp;·&nbsp;
        <a class="ar-linkbtn" href="https://github.com/heera/agentimus" target="_blank" rel="noopener">GitHub</a>
      </p>

      <!-- ⭐ The one thing a support thread is always missing. WordPress.org
           cannot be pre-filled by anyone, so a forum post carries these numbers
           only if the person pastes them — and its own posting guide asks for
           "your server environment" without being able to say what yours is.
           It lives HERE rather than in front of Get Help: a form standing
           between somebody and asking for help is a worse trade than a button
           a reply can point at. -->
      <div v-if="support && support.facts" class="ar-about-setup">
        <h3 class="ar-about-setup__title">Your setup</h3>
        <p class="ar-about-setup__lead">
          What this install is running. Paste it into a support thread — it saves the first
          reply being a question back.
        </p>
        <pre class="ar-facts">{{ support.factsLean }}</pre>
        <button type="button" class="ar-btn ar-btn--small" @click="copySetup">
          {{ setupCopied ? 'Copied ✓' : 'Copy setup details' }}
        </button>
      </div>
    </section>
  </div>
</template>
