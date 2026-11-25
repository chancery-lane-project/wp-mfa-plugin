# Early serving (optional)

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

Measured on one site on Pressable, 25 November 2026, with the prototype this
feature is based on (5 runs of 15 URLs, medians):

| Markdown request | Through WordPress | Served early |
| --- | --- | --- |
| PHP time | 269 ms | 27 ms |
| CPU time | 250 ms | 6.5 ms |
| Peak memory | 28 MB | 4 MB |
| Time to first byte | 450 ms | 186 ms |

What remains is WordPress core starting up. Your figures will differ with your
plugins, theme and host: compare the PHP and CPU time of a Markdown request
with the loader in place and without it before relying on these.

On energy: about 0.24 s of CPU saved per request is roughly 0.05–0.24 mWh at
0.7–3.5 W per vCPU, or 0.05–0.24 kWh per million Markdown requests. That is a
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
the plugin. If the plugin's folder has a different name, edit the path in the
loader.

Early serving stays off until one ordinary front-end request has loaded fully
and checked the filters below. On a site whose pages are all cached that can
take a while; requesting any uncached URL, such as `/?mfa-check`, does it.

## Checking it

Responses served early carry `X-Markdown-Source: markdown-for-agents-early`;
those served at `template_redirect` carry `markdown-for-agents`.

```bash
curl -sI 'https://example.org/a-post/?output_format=md' | grep -i x-markdown-source
wp option get markdown_for_agents_early_guard --format=json
```

`{"hooked":[]}` means early serving is active. A list of filter names means it
is standing aside because something hooks them.

## What it skips

**Filters from regular plugins and the theme.** They have not loaded yet. Early
serving watches these, and stands aside while any regular plugin or the theme
hooks one:

- `markdown_for_agents_serve_enabled`
- `markdown_for_agents_serve_taxonomies`
- `markdown_for_agents_canonical_url`
- `markdown_for_agents_content_signal`
- `markdown_for_agents_cache_headers`
- `markdown_for_agents_export_path`
- `markdown_for_agents_post_relative_path`
- `get_canonical_url` (SEO plugins that change the canonical URL)

The check runs at `wp_loaded` on front-end requests that load fully, so it
updates on the next such request after you add or remove a filter. A filter
added later than `wp_loaded`, for example inside a `template_redirect`
callback, is not seen. Filters added by must-use plugins load on every
request, so they apply to early responses too and don't block them.

**Plugins that act on every request.** Maintenance-mode and coming-soon
plugins, membership plugins and security plugins that block or rate-limit in
PHP have not loaded. Remove the loader while the site is in maintenance mode.
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
includes the home page, files moved by the path filters, and, if
`sanitize_file_name()` changes them, slugs with non-ASCII characters.

**Multisite.** Not supported; the loader does nothing there.

## What stays the same

Early responses use the same code as the normal route for deciding whether a
request asks for Markdown (`?output_format=md`, `Accept: text/markdown`, a
listed User-Agent), for the headers, and for the statistics. A GET is counted
under the same agent, access method and post or term; HEAD is not counted.
Files exist only for published, non-password-protected, non-excluded content,
so the same content is served.
