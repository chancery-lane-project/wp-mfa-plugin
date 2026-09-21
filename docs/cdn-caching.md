# CDN and page-cache configuration

The plugin chooses Markdown at the same URL that normally serves HTML. A cache
that answers before WordPress runs can hide that choice: an agent receives cached
HTML without the plugin seeing the request. Conversely, a cache that stores
negotiated Markdown under the page URL can later send it to a browser.

Configure every cache layer, including any hosting-provider cache behind the CDN.
This guide applies to public posts with current exports and UA detection enabled.
A missing export, excluded post or disabled serving policy can legitimately fall
back to HTML even when no cache interferes.

## Response headers and their limits

Negotiated Markdown carries `Cache-Control: private, no-store, max-age=0`,
`Vary: Accept, User-Agent`, `X-LiteSpeed-Cache-Control: no-cache` and
`X-Accel-Expires: 0`. HTML with an alternate carries `Vary: Accept` and a `Link`
header advertising `?output_format=md`. These protect responses only when the
relevant cache respects them; they cannot change an existing HTML cache hit.

The query URL is the preferred discovery route, provided the cache preserves the
query string or bypasses that request. A cache that ignores `output_format` can
collapse it onto the HTML key. Query negotiation does not bypass WAF rules, bot
blocking, authentication or rate limits.

Do not make UA-negotiated responses publicly cacheable merely because a cache
keys on `Accept`: a UA-only request can have the same Accept value as a browser.
Leave those responses private and bypass agent requests. If relaxing headers for
query URLs, first verify query-sensitive keys at **every** cache layer and accept
that cache hits will disappear from plugin statistics.

## Cloudflare checklist

| Setting | Required behaviour |
| --- | --- |
| Cache-everything rule, if used | Respect origin cache-control; do not choose an Edge TTL that ignores it |
| Agent bypass rule | Match the hostname and Markdown query trigger or configured agent UA; action **Bypass cache** |
| Rule order | Put bypass **after** cache-everything; later matching cache-eligibility settings win |
| Agent list | Generate from this installation's configured list; regenerate after changes |
| Cache key | Preserve `output_format`, or explicitly bypass it |
| Bot/security controls | Permit the intended agents separately from cache rules; inspect challenges and blocks |
| Robots preferences | Decide whether the origin or Cloudflare owns robots.txt; check the file actually returned at the edge |

Cloudflare documents the [last matching rule behaviour](https://developers.cloudflare.com/cache/how-to/cache-rules/order/)
and [Edge TTL overrides that can defeat no-store](https://developers.cloudflare.com/cache/troubleshooting/investigating-uncached-responses/).
A cache bypass rule does not itself allow a request through security controls.
For controlled testing, review Bot Fight Mode, Browser Integrity Check and the
available search/training/agent policies with the site operator. Do not assume
that an ordinary WAF skip rule disables every bot product. Avoid speculative
prefetch traffic when measuring counters; review Speed Brain, Early Hints,
Rocket Loader and Always Online as applicable.

On a controlled **Cloudflare free-plan test on 21 September 2026**, a Cache Rule
using the Accept request header was rejected with "service identity is not
authorized". Query and UA conditions worked. After moving bypass below
cache-everything, listed UAs received Markdown on warm URLs while browsers kept
receiving cached HTML. Unlisted clients using only `Accept: text/markdown`
still received cached HTML. Treat this as a dated, measured plan limitation;
check current account capabilities before promising Accept-based bypass.

Those measurements came from the related Wagtail implementation. They explain
the cache behaviour but do **not** certify a WordPress deployment. Verify the
actual WordPress stack using the procedure below.

### Generate the bypass expression

Run this in the WordPress installation with WP-CLI and the plugin active. Replace
the example hostname. It prints an expression; it does not change Cloudflare.
Use saved options rather than copying a fixed list from a different installation.

```bash
wp eval '
$host = "www.example.org";
$options = \Tclp\WpMarkdownForAgents\Core\Options::get();
$agents = array_unique(array_filter((array) $options["ua_agent_strings"], "strlen"));
sort($agents, SORT_STRING);
$clauses = array(
    "http.request.uri.query contains \"output_format=md\"",
    "http.request.uri.query contains \"output_format=markdown\""
);
foreach ($agents as $agent) {
    $clauses[] = "http.user_agent contains " . json_encode($agent, JSON_UNESCAPED_SLASHES);
}
echo "(http.host eq " . json_encode($host) . " and (" . implode(" or ", $clauses) . "))\n";
'
```

The shipped defaults currently contain 69 strings, but saved site options may
contain a different list. Review the generated length against the dashboard's
expression limit and inspect the expression saved by Cloudflare. `contains` is
case-sensitive, unlike the plugin's UA matching: test the actual UA spelling and
review variants where needed. The broad query containment may also bypass similar
values, but only the plugin's recognised values select Markdown.

## Other caches

| Layer | Configuration to verify |
| --- | --- |
| LiteSpeed Cache | Exclude the export directory and `output_format`; also exclude **all configured agent UAs**. Add the Accept rewrite rule from the [README](../README.md#litespeed-cache). |
| nginx proxy/fastcgi cache | Map the Accept, query and configured-UA triggers to both cache bypass and no-cache controls. `X-Accel-Expires: 0` prevents storing a new negotiated response; it does not rescue an existing HTML hit. |
| Varnish/Fastly | Pass agent-shaped requests, or correctly normalise/key Accept variants. UA-only requests still need bypass unless independently varied. |
| Managed hosting, including WP Engine | Ask the provider to exclude these requests at its own cache as well as the CDN. If headers cannot be used for bypass, verify the query route with that provider. |
| WordPress page-cache plugins | Check whether an early cache/drop-in answers before `template_redirect`. PHP headers and later hooks cannot repair that hit. |

Multiple layers can each be wrong. `CF-Cache-Status: DYNAMIC` with HTML, for
example, does not prove WordPress selected HTML: an origin-side page cache may
have answered instead. Inspect origin cache headers/logs and export eligibility.

### Direct uploads are a separate path

The plugin streams an export through PHP when a canonical page URL negotiates
Markdown. A direct `/wp-content/uploads/{export_dir}/...md` URL normally goes
straight to the web server. Internal exported links use these direct URLs.

Static responses do not run the negotiator, receive its PHP cache-header filters,
or increment its statistics. Configure their content type and caching separately.
Either bypass storage of these URLs or define a bounded TTL and a reliable purge
process for regeneration, deletion and withdrawal. Deleting the origin file does
not remove a previously cached copy. Apply this to indexes, manifests and bundles
as well as individual Markdown files. A CDN bypass alone does not invalidate
copies already held by browsers or another cache.

## Verify both request orders

Agree a small request budget and use public, controlled URLs from an external
network. Confirm the chosen post has an export and UA serving is enabled. These
are **GET** checks: `-D - -o /dev/null` receives and discards the body; it is not
`curl -I`. For content comparisons, save bodies privately as well.

```bash
H=https://www.example.org/a-published-post/
# Browser first: repeat until headers actually demonstrate warm cached HTML.
curl -sS -D - -o /dev/null -A 'Mozilla/5.0' -H 'Accept: text/html' "$H"
curl -sS -D - -o /dev/null -A 'Mozilla/5.0' -H 'Accept: text/html' "$H"
# A listed agent must still receive Markdown after cached HTML.
curl -sS -D - -o /dev/null -A 'GPTBot/1.4' -H 'Accept: text/html' "$H"
curl -sS -D - -o /dev/null -A 'GPTBot/1.4' -H 'Accept: text/markdown' "$H"
curl -sS -D - -o /dev/null "$H?output_format=md"
# Unlisted Accept-only: cached HTML is the measured Cloudflare-free limitation.
curl -sS -D - -o /dev/null -A 'MarkdownVerification/1.0' -H 'Accept: text/markdown' "$H"
# A browser must still receive HTML after the agent requests.
curl -sS -D - -o /dev/null -A 'Mozilla/5.0' -H 'Accept: text/html' "$H"
```

For agent-first checks, use a separately prepared uncached public URL, or agree a
specific cache purge with the operator. Request Markdown and then browser HTML;
repeat for query, Accept and UA triggers. A sequence labelled "agent first" does
not prove cold cache state. Do not claim a randomly cache-busted URL tests the
ordinary warm page key. Fetch a real direct export too, and check its independent
headers and freshness. Add HEAD checks to confirm headers without page increments.

Inspect status, `Content-Type`, `Cache-Control`, `Vary`, `X-Markdown-Source` and
CDN/origin cache status. A negotiated plugin response carries
`X-Markdown-Source: markdown-for-agents`; static exports need not. If a browser
receives Markdown, stop traffic and agree correction and purging before resuming.
Do not interpret HTML alone as proof of a cache fault: confirm page eligibility,
export presence and response provenance.

## Statistics and correlation

Counters record **singular-post Markdown GET selections at WordPress**, not
all agent traffic or proof of complete delivery. HEAD and other HTTP methods do
not increment them. Neither do HTML fallback, taxonomy archives, direct uploads,
aggregate downloads, CDN hits or requests blocked before WordPress. Versions up
to 1.7.1 also counted negotiated singular HEAD requests; the GET-only correction
is recorded under Unreleased in the changelog.

For reconciliation, log a unique run ID and request ID at the client and origin.
Sending `X-Sim-Run` and `X-Sim-Request` is not enough: ordinary combined access logs
do not contain them. Inspect an **actual** origin record for both exact values,
method, URI, UA and compatible UTC time before relying on correlation. Restrict
verification logs to appropriate public routes and omit IPs, credentials and
cookies. Record whether background traffic is omitted from your log.

Finish all discovery/probe requests **before** taking the baseline: they can
increment counters. Start the measured run only after that snapshot completes;
wait for origin work to finish before taking the final snapshot. Compare matching
UTC date/post/agent/access-method buckets, preserving residuals and documenting
any tolerance. Do not reset/prune counters or regenerate exports inside the window.
Keep CDN hits, static bypasses, HEAD and background traffic separate. A successful
HTTP response without origin evidence is not proof of a counter selection.

Synthetic UA tests exercise detection, not vendor identity. Genuine vendor fetches
need separate evidence and an agreed API budget. The Wagtail simulator's direct
exports are application routes with counters; WordPress uploads are static, so
its expected counts cannot be reused unchanged. A WordPress adapter must also
account for this plugin's manifest/frontmatter and taxonomy/aggregate behaviour.
