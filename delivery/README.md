# Delivering Markdown to agents: the query-param first principle

_Why `?output_format=md` is the route we stand behind, what it means for site
operators, and how this holds up against real agent behaviour._

This note grew out of a live audit of a large install (29 URLs, 96/100 mean
fidelity) where the Markdown itself was excellent but **most agent traffic never
received it** — not because of a plugin bug, but because the site's page cache
and Cloudflare WAF sit *in front* of the plugin and answered first.

---

## TL;DR (for non-technical readers)

Think of every page on the site as having two doors:

- The **front door** (`https://example.org/some-page/`) — the normal web page a
  person sees in a browser.
- A **side door** (`https://example.org/some-page/?output_format=md`) — the same
  content as clean Markdown, the format AI tools read most cheaply.

The plugin's job is to make sure the side door always opens onto a tidy,
text-only version of the page. It does that well.

The catch: most big websites have a **security guard and a photocopier** standing
in front of the building — a CDN/WAF (Cloudflare) and a page cache (e.g. WP
Engine). For speed and safety, they often answer visitors *before* the request
ever reaches the plugin. When an AI tool knocks in a way the guard doesn't
recognise, two things can happen:

1. The **photocopier** hands back a cached copy of the *front-door* page (HTML),
   so the AI gets the heavyweight version and the savings are lost; or
2. The **guard** turns the AI away at the door entirely (a "429/403" — blocked).

Here's the important part: **the side-door URL solves both problems by itself.**
Because `?output_format=md` is a genuinely different web address, the photocopier
files it separately and the guard treats it as an ordinary page request. No
special configuration, no guessing who the visitor is. It just works.

So our first principle is simple:

> **Publish one stable, predictable Markdown address per page and point everyone
> at it. Don't rely on the building's staff to recognise AI visitors.**

Everything else — sniffing the visitor's name (User-Agent) or reading the fine
print on their request (the `Accept` header) — is a *nice-to-have* that only works
if the site's guard and photocopier are specifically told to cooperate. Useful
where you control them; never something we promise on a site we don't.

---

## The three doors, and why only one is reliable

The plugin can recognise a Markdown request three ways. The audit shows how each
fares once real infrastructure is in the path:

| Route | How the agent asks | Verdict in the wild | Why |
|---|---|---|---|
| **Query param** `?output_format=md` | A distinct URL | ✅ Reliable | Different URL → cached separately, looks like a normal dynamic page to the WAF. The request actually reaches the plugin. |
| **`Accept: text/markdown` header** | A header on the normal URL | ⚠️ Usually intercepted | Same URL as the HTML page, so the page cache answers with cached HTML before the plugin runs. |
| **User-Agent** (GPTBot, ClaudeBot, …) | Identifying as a bot | ❌ Often blocked or cached | Edge bot rules return 429/403, or the page cache serves HTML first. Also cache-hostile (see below). |

The first route is **infrastructure-independent**: it works the same whether the
site runs no cache at all or a hardened Cloudflare + WP Engine stack. The other
two depend on the operator configuring the cache and WAF to step aside — which we
can't do from PHP, and can't promise on someone else's hosting.

### Why we don't lean on User-Agent matching

Identifying agents by User-Agent is a losing game on two fronts:

- **It's cache-hostile.** Distinguishing responses by User-Agent means varying the
  cache on a near-unique string, which shatters the edge cache and works directly
  against site performance.
- **It's a maintenance treadmill.** UA strings change, get spoofed, and multiply.
  A delivery strategy built on a hand-maintained UA list is permanently behind.

A distinct URL has neither problem. (UA detection still earns its keep for
*statistics* — knowing _who_ fetched the Markdown — just not as the delivery
mechanism.)

---

## What this means for site operators

The plugin produces correct Markdown at a stable, cache-safe URL and advertises
it via a `<link rel="alternate" type="text/markdown">` tag in the page `<head>`.
That is the contract. Two optional steps make the *other* routes work if you want
them, and one diagnostic step is worth doing regardless.

### 1. Advertise the side door (already done by the plugin)

Every eligible page emits, in `<head>`:

```html
<link rel="alternate" type="text/markdown" href="https://example.org/some-page/?output_format=md">
```

This is the same autodiscovery mechanism browsers use for RSS feeds. An agent that
reads the page's `<head>` finds the absolute Markdown URL and can follow it. **No
operator action needed.**

### 2. (Optional) Let the header/UA routes through your cache and WAF

Only if you want `Accept: text/markdown` and bot User-Agents to be served directly:

- **Page cache (e.g. WP Engine / LiteSpeed / Varnish):** exclude agent-shaped
  requests from the full-page cache — any request whose `Accept` contains
  `text/markdown`, whose query contains `output_format=md`, or whose User-Agent is
  a known AI bot. **Do _not_ add User-Agent to the cache _key_** — that shatters
  the cache for everyone. Exclude from caching; don't key on it.
- **Cloudflare / edge bot rules:** add a skip/allow rule for the bot
  User-Agents you actually want to serve (GPTBot, ClaudeBot, PerplexityBot,
  Google-Extended, Amazonbot, …). Without it they get 429/403 and receive nothing.

If you skip this step, nothing breaks — agents simply use the query-param URL via
discovery instead.

> **Note:** the plugin already protects you from the inverse problem. Markdown
> responses are sent with `Cache-Control: private, no-store` and
> `Vary: Accept, User-Agent` so a shared cache can't store the Markdown variant
> and replay it to human browsers on the same URL. That class of cache-poisoning
> bug is handled in-plugin; it is not something operators need to defend against.

### 3. Diagnose delivery, don't assume it

High fidelity does not mean high delivery. A page can score 96/100 and still serve
0% Markdown to agents if the cache/WAF is intercepting. The only way to know is to
probe the live routes from outside (as the audit did). A planned
**admin self-probe** would surface this inside `wp-admin` — "your Markdown is being
intercepted on the Accept/UA routes" — turning a silent infrastructure failure into
something the operator can see and fix. (Not yet built; tracked as a follow-up.)

---

## Does real agent behaviour back this up? Yes.

Two patterns are now common in the wild, and both reinforce the query-param-first
principle rather than undercut it.

### Pattern A — fetch HTML, then come back for the Markdown

An agent loads the normal page, parses the `<head>`, finds the
`<link rel="alternate" type="text/markdown">`, and **re-fetches the Markdown URL**.

This tracks, and it's exactly what the discovery link is for. The crucial detail:
that second request goes to `?output_format=md` — the distinct URL — so it sails
through the cache and WAF and gets real Markdown. **This is why the "broken"
negotiation routes don't doom the plugin:** the discovery link funnels agents onto
the one route that actually works, no operator configuration required.

Caveat worth being honest about: if the agent already downloaded and parsed the
full HTML to find the link, the token saving on *that* page is largely already
spent. The win shows up on (a) re-fetches, (b) subsequent pages once the agent has
learned the pattern, and (c) agents that cache the discovery and skip the HTML next
time. It compounds across a crawl rather than saving on first contact.

### Pattern B — go straight to the Markdown, natively

Some agents now skip the HTML round-trip entirely: they've learned a site's
convention, they follow an `llms.txt`-style index, or they natively try appending
`?output_format=md` (or `.md`). This is the most efficient path and the one that
delivers the full token saving — no HTML is fetched at all.

This also tracks, and it's the strongest argument for the principle. "Going
straight to the Markdown" is only possible against a **stable, predictable,
URL-addressable** route. You cannot pre-learn or pre-register an `Accept` header or
a UA negotiation — those are invisible in a URL and unreliable through
infrastructure. A query-param URL is something an agent can remember, share, hard-
code, and hit directly. The behaviour agents are converging on is *URL-based*, and
URL-based is precisely the thing that survives the cache and the WAF.

### The takeaway

Both observed behaviours route through URLs, not through content negotiation. That
is a strong signal to:

- treat `?output_format=md` as the **canonical, primary** delivery route;
- keep the discovery `<link>` solid (it bridges Pattern A onto that route);
- treat `Accept`/UA negotiation as optional enhancement for operators who control
  their own edge;
- keep UA matching for *stats*, not as the thing delivery depends on.

---

## A note on `llms.txt`

A root `/llms.txt` (a plain-text index pointing at each page's Markdown URL) is the
natural way to serve Pattern B agents that look for a site-level manifest. The
plugin **used to auto-generate this and the feature was deliberately removed in
1.3.x** (along with the `--with-llmstxt` WP-CLI flag), so today it is not produced
automatically.

If a site wants one now, it can be published as a **static file at the web root**.
A worked example lives in [`examples/llms.txt`](examples/llms.txt). Whether to
bring auto-generation back is a product decision worth revisiting precisely because
Pattern B is growing — see that example's header for the trade-offs.

---

## Risks of serving Markdown to bots and agents (summary)

| Risk | Status |
|---|---|
| Cache poisoning (Markdown served to human browsers) | **Handled in-plugin** via `no-store` + `Vary` headers. |
| WAF turning agents away (429/403) | Real on the UA route; the query-param URL looks like an ordinary request and avoids it. |
| Over-exposure of template/dynamic content | Watch the length ratios — some pages emit far more in Markdown than the HTML main body (template/ACF content). Verify nothing gated or non-public leaks. |
| Geo/personalisation correctness (`r=`, `c=` params) | Markdown route is dynamic today; don't let anyone later cache it under a region-agnostic key. |
| Stale Markdown after a page is retired/404s | Invalidation must cover the `.md` variants, not just HTML. |
| No saving unless Markdown *replaces* HTML | Token win only materialises when agents fetch Markdown instead of HTML (Pattern B), or on re-fetch (Pattern A). |
