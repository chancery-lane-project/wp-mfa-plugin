# Design: User Agent Detection for LLM Agents

**Date:** 2026-03-05
**Status:** Approved

## Overview

Force-serve pre-generated Markdown to known LLM agent crawlers based on the
HTTP `User-Agent` string, without requiring the client to send
`Accept: text/markdown`. Admins configure the list of UA substrings via the
existing settings page.

## Motivation

Most LLM crawlers do not send `Accept: text/markdown`. Serving them Markdown
directly avoids the overhead of HTML rendering and gives agents cleaner,
structured content.

## Architecture

### New class: `Negotiate\AgentDetector`

Single responsibility: decide whether a given UA string matches any configured
substring.

```php
class AgentDetector {
    public function __construct(private readonly array $options) {}
    public function is_known_agent(string $ua): bool;
}
```

- Reads `$options['ua_force_enabled']` — if false, always returns false.
- Reads `$options['ua_agent_strings']` (array of substrings).
- Matching is case-insensitive substring (`stripos`).
- Injected into `Negotiator` via constructor.

### Changes to `Negotiate\Negotiator`

`AgentDetector` added as a constructor parameter.

`maybe_serve_markdown()` serves Markdown if **either**:
- `HTTP_ACCEPT` contains `text/markdown`, **or**
- `AgentDetector::is_known_agent(HTTP_USER_AGENT)` returns true.

`output_link_tag()` emits the `<link rel="alternate">` tag for known agent UAs
as well as for Accept-header requests.

### Changes to `Core\Options`

Two new keys added to `get_defaults()`:

| Key | Type | Default |
|-----|------|---------|
| `ua_force_enabled` | bool | `true` |
| `ua_agent_strings` | string[] | See default list below |

### Changes to `Admin\SettingsPage`

New settings section `wp_mfa_ua_detection` ("Agent Detection") added to the
existing settings page with two fields:

- **Enable UA detection** — checkbox for `ua_force_enabled`
- **Agent user-agent strings** — textarea, one substring per line,
  for `ua_agent_strings`

`sanitize_options()` extended to parse the textarea: trim each line, drop
empty lines, re-index array.

### Changes to `Core\Plugin`

`AgentDetector` instantiated in `define_negotiate_hooks()` and injected into
`Negotiator`.

## Default UA Substrings

| Substring | Agent |
|-----------|-------|
| `GPTBot` | OpenAI training crawler |
| `ChatGPT-User` | OpenAI ChatGPT browsing |
| `ClaudeBot` | Anthropic crawler |
| `Claude-Web` | Anthropic |
| `anthropic-ai` | Anthropic |
| `PerplexityBot` | Perplexity |
| `Google-Extended` | Google / Gemini training |
| `Amazonbot` | Amazon |
| `cohere-ai` | Cohere |
| `meta-externalagent` | Meta AI |
| `Bytespider` | ByteDance |
| `CCBot` | Common Crawl |
| `Applebot-Extended` | Apple |

## Data Flow

```
template_redirect (priority 1)
  └─ Negotiator::maybe_serve_markdown()
       ├─ is_eligible_singular()?  → no → return
       ├─ Accept: text/markdown?   → yes → serve .md
       ├─ AgentDetector::is_known_agent(UA)?  → yes → serve .md
       └─ otherwise → return (serve normal HTML)
```

## Testing

### `AgentDetectorTest` (new)
- Known substring matches (case-insensitive)
- Unknown UA string does not match
- Empty UA string does not match
- Returns false when `ua_force_enabled` is false
- Matches any entry in the list (not just first)

### `NegotiatorTest` (extended)
- Serves Markdown when UA matches known agent (no Accept header)
- Does not serve when `ua_force_enabled` is false even if UA matches
- `output_link_tag()` emits tag for known agent UA

### `SettingsPageTest` (extended)
- `ua_agent_strings` textarea parsed to array (trims whitespace, drops empty lines)
- `ua_force_enabled` cast to bool

### `OptionsTest` (extended)
- `ua_force_enabled` and `ua_agent_strings` present in defaults with correct types

## Decisions

- **Single settings field, no constant** — default list lives entirely in
  `Options::get_defaults()`. No split between hardcoded defaults and
  user-customisable additions; the stored value is the single source of truth.
- **Substring matching only** — regex is not needed for current use cases and
  would complicate sanitisation.
- **Case-insensitive matching** — UA strings are not case-sensitive by
  convention.
- **Master toggle** — `ua_force_enabled` disables the feature entirely without
  requiring the admin to delete all UA strings.
