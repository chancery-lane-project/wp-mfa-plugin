# Early serving (experimental)

> Experimental. It has been measured on one site, it skips regular plugins and
> the theme for the requests it answers, and it may change or be removed in a
> later release. Try it on a staging site first, and check the statistics
> still look as they did after a week or two.

Normally the plugin decides whether to serve Markdown at `template_redirect`.
By then WordPress has loaded every active plugin, the theme, run every `init`
callback and the main query. The plugin's own work is a couple of
milliseconds; the rest of the request is everything that ran before it.

Early serving answers a Markdown request straight after must-use plugins load,
before any of that. It is off unless you switch it on, and it changes nothing
for HTML pages.

## When it helps

- Markdown requests are a noticeable share of uncached traffic. Markdown
  responses are sent `no-store` so every request can be counted, which means
  every one reaches PHP, while HTML is often served from a page cache.
- The site has heavy plugins or a heavy theme, so each request that reaches
  WordPress is expensive.
- You don't rely on filters in a theme or regular plugin to decide what is
  served (see [What it skips](#what-it-skips)).

Measured on one site on Pressable, 25 November 2026 (5 runs of 14 URLs,
medians):

| Markdown request | Through WordPress | Served early |
| --- | --- | --- |
| PHP time | 269 ms | 36 ms |
| CPU time | 250 ms | 13 ms |
| Peak memory | 28 MB | 6 MB |
| Time to first byte | 450 ms | 169 ms |

For comparison, an HTML page that missed the cache on the same site took
363 ms of CPU and 32 MB. What remains of an early request is WordPress core
starting up and the must-use plugins loading; early serving waits for all
must-use plugins so their filters and any security code in them still run. Your figures will differ with your
plugins, theme and host: compare the PHP and CPU time of a Markdown request
with the loader in place and without it before relying on these.

On energy: about 0.24 s of CPU saved per request is roughly 0.05–0.23 mWh at
0.7–3.5 W per vCPU, or 0.05–0.23 kWh per million Markdown requests. That is a
modelled figure, not a measurement, and small for one site. The stronger
reasons are capacity and speed: a crawler sending five Markdown requests a
second keeps about 1.2 CPU cores busy through WordPress and about 0.03 served
early.

## Switching it on and off

Copy the loader into must-use plugins:

```bash
cp wp-content/plugins/markdown-for-agents-and-statistics/mu-plugins/markdown-for-agents-early.php \
   wp-content/mu-plugins/
```

Delete that file to switch it off. The loader only loads code from the plugin.
It does nothing if the plugin is deactivated or missing, and it updates with
the plugin. It finds the plugin among the active plugins, so the plugin's
folder can have any name.

Early serving stays off until one ordinary front-end request has loaded fully
and checked the filters below. It switches off again, until the next such
request, whenever a plugin is activated or deactivated, the theme is switched
or an update runs. On a site whose pages are all cached that can take a
while; requesting any uncached URL, such as `/?mfa-check`, does it.

## Checking it

Responses served early carry `X-Markdown-Source: markdown-for-agents-early`;
those served at `template_redirect` carry `markdown-for-agents`.

```bash
curl -sI 'https://example.org/a-post/?output_format=md' | grep -i x-markdown-source
wp option get markdown_for_agents_early_guard --format=json
```

An empty `hooked` list means early serving is active. A list of filter names
means it is standing aside because something hooks them. `upload_dir` in the
list means a regular plugin or the theme moves the uploads directory, so the
early route would look for exports in the wrong place. No `hooked` at all, only
a `reset` value, means a plugin or theme changed and no full front-end request
has checked the filters since.

A filter stays on the list once it has been seen, even if later requests
don't see it: a plugin may hook a filter for some visitors only. The list
starts again when a plugin is activated or deactivated, the theme is switched
or an update runs. If you remove a filter by editing code, clear it yourself:

```bash
wp option delete markdown_for_agents_early_guard
```

## What it skips

**Serving filters.** Early serving has no main query, post object or canonical
URL, so it can't apply these. It stands aside while anything hooks one, a
must-use plugin included:

- `markdown_for_agents_serve_enabled`
- `markdown_for_agents_serve_post_types`
- `markdown_for_agents_serve_taxonomies`
- `markdown_for_agents_canonical_url`
- `markdown_for_agents_export_path`
- `markdown_for_agents_post_relative_path`
- `get_canonical_url` (SEO plugins that change the canonical URL)

It does apply these two, when sending the response:

- `markdown_for_agents_content_signal`
- `markdown_for_agents_cache_headers`

Must-use plugins load before early serving, so their callbacks on these two
apply to early responses too. Regular plugins and the theme have not loaded
yet, so early serving stands aside while either of them hooks one.

The check for regular plugins and the theme runs at `wp_loaded` on front-end
requests that load fully. A filter added later than `wp_loaded`, for example
inside a `template_redirect` callback, is not seen. Must-use plugins are
checked on every early request.

**Plugins that act on every request.** Maintenance-mode and coming-soon
plugins, membership plugins and security plugins that block or rate-limit in
PHP have not loaded. Remove the loader while the site is in maintenance mode.
Response headers a regular plugin or the theme adds, such as CORS headers, are
missing from early responses.
Firewalls that run before WordPress, such as a CDN or `auto_prepend_file`
WAF, are unaffected.

**Canonical redirects.** WordPress would redirect `/a-post` to `/a-post/`;
early serving answers both with the same Markdown.

**Finding the file without the main query.** The export is found from the URL
path, following the default export layout: `{post_type}/{slug}.md`,
`{post_type}/{parent}/{slug}.md` for hierarchical types, and
`taxonomy/{taxonomy}/{slug}.md`. It is served only if the `permalink` in the
file's own front matter has the same path as the request, so a date archive
whose last segment matches a post's slug is never answered with that post. A
URL it cannot match carries on to WordPress and is handled as usual. That
includes the home page, the page set as the posts page (WordPress shows the
blog index there, which has no Markdown), files moved by the path filters,
and, if `sanitize_file_name()` changes them, slugs with non-ASCII characters.

**Query strings.** A query variable can change what WordPress shows at a path
(`?s=`, `?p=`, `?preview=`), and early serving has no main query to tell. Any
request whose query string holds something other than `output_format`,
tracking parameters such as `utm_source` included, goes through WordPress.

**Multisite.** Not supported; the loader does nothing there.

## What stays the same

Early responses use the same code as the normal route for deciding whether a
request asks for Markdown (`?output_format=md`, `Accept: text/markdown`, a
listed User-Agent), for the headers, and for the statistics. A GET is counted
under the same agent, access method and post or term; HEAD is not counted.
Files exist only for published, non-password-protected, non-excluded content,
so the same content is served.
