<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Negotiate;

/**
 * Detects whether a User-Agent string belongs to a known LLM agent.
 *
 * Matching is case-insensitive substring. The list of substrings is
 * configured via the `ua_agent_strings` plugin option.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Negotiate
 */
class AgentDetector {

	/**
	 * @since  1.1.0
	 * @param  array<string, mixed> $options Plugin options.
	 */
	public function __construct( private readonly array $options ) {}

	/**
	 * Return the first matching UA substring regardless of ua_force_enabled.
	 *
	 * Use this for stats labelling. For the serving gate, use get_matched_agent().
	 *
	 * @since  1.2.0
	 * @param  string $ua The HTTP User-Agent header value.
	 * @return string|null The matched substring, or null.
	 */
	public function detect_agent( string $ua ): ?string {
		if ( '' === $ua ) {
			return null;
		}

		$substrings = (array) ( $this->options['ua_agent_strings'] ?? array() );

		foreach ( $substrings as $substring ) {
			if ( '' !== $substring && false !== stripos( $ua, $substring ) ) {
				return $substring;
			}
		}

		return null;
	}

	/**
	 * Return the first matching UA substring, or null if none matches.
	 *
	 * Returns null when ua_force_enabled is off — this controls whether a UA
	 * match alone triggers serving. For stats, use detect_agent() instead.
	 *
	 * @since  1.1.0
	 * @param  string $ua The HTTP User-Agent header value.
	 * @return string|null The matched substring, or null.
	 */
	public function get_matched_agent( string $ua ): ?string {
		if ( empty( $this->options['ua_force_enabled'] ) ) {
			return null;
		}

		return $this->detect_agent( $ua );
	}

	/**
	 * Extract a stable product name from a UA string for stats labelling.
	 *
	 * Returns the first token before the first '/' (e.g. 'LangChain' from
	 * 'LangChain/0.1.0'), making the label consistent across version changes.
	 * Returns the raw string if it contains no '/', and '' for empty input.
	 *
	 * @since  1.2.0
	 * @param  string $ua The HTTP User-Agent header value.
	 * @return string
	 */
	public function normalise_ua( string $ua ): string {
		if ( '' === $ua ) {
			return '';
		}

		if ( preg_match( '/^([^\/\s]+)/', $ua, $matches ) ) {
			return $matches[1];
		}

		return $ua;
	}

	/**
	 * Classify an agent label by the intent behind its fetch.
	 *
	 * Background crawlers (model training, search indexing) are not the same as
	 * real-time, human-triggered reads. The category is derived at read time from
	 * the stored agent substring — no schema change. Matching is case-insensitive
	 * substring, mirroring detect_agent(); the first matching category wins, with
	 * on-demand checked first so human-intent labels take precedence.
	 *
	 * @since  1.5.0
	 * @param  string $agent The stored agent label (matched substring or product name).
	 * @return string One of 'training', 'search', 'on-demand', or 'unknown'.
	 */
	public function categorise_agent( string $agent ): string {
		$agent = trim( $agent );
		if ( '' === $agent ) {
			return 'unknown';
		}

		foreach ( $this->get_agent_categories() as $category => $substrings ) {
			foreach ( (array) $substrings as $substring ) {
				if ( '' !== $substring && false !== stripos( $agent, (string) $substring ) ) {
					return (string) $category;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * Return the intent-category → substrings map, filtered.
	 *
	 * Categories are checked in array order, so on-demand (human-triggered) is
	 * listed first. Override via the `markdown_for_agents_agent_categories` filter.
	 *
	 * Treat this map as append-only. Categories are derived from labels already
	 * written to the stats table, so removing an entry retrospectively drops that
	 * bot's history into 'unknown'. The four category keys are fixed — StatsPage
	 * renders one tile per key and coerces anything else to 'unknown'.
	 *
	 * @since  1.5.0
	 * @return array<string, string[]>
	 */
	private function get_agent_categories(): array {
		$defaults = array(
			'on-demand' => array(
				// Baseline.
				'ChatGPT-User',
				'Claude-User',
				'Claude-Web',
				'Perplexity-User',
				'Gemini-User',
				// Cloudflare Radar AI_ASSISTANT.
				'meta-externalfetcher',
				'MistralAI-User',
				'Google-Agent',
				'DuckAssistBot',
				'Devin',
				'TwinAgent',
				'ApifyWebsiteContentCrawler',
				'ChathiveCrawler',
				'CledaraBot',
				'EasyScan',
				'HarkBot',
				'HIFIBot',
				'QATechBot',
				'Instapaper',
				'Nava/',
				'Retool/',
			),
			'search'    => array(
				// Baseline.
				'OAI-SearchBot',
				'PerplexityBot',
				'Applebot-Extended',
				// Cloudflare Radar AI_SEARCH.
				'Claude-SearchBot',
				'Bravebot',
				'Amzn-SearchBot',
				'Cloudflare-AI-Search',
				'Anomura',
				'Element451Bot',
				'KernelSearchBot',
				'ShapBot',
				'alphalens-bot',
			),
			'training'  => array(
				// Baseline.
				'GPTBot',
				'ClaudeBot',
				'CCBot',
				'Google-Extended',
				'Bytespider',
				'meta-externalagent',
				'Amazonbot',
				'cohere-ai',
				'anthropic-ai',
				// Cloudflare Radar AI_CRAWLER.
				'KimiBot',
				'PetalBot',
				'GoogleOther',
				'CloudVertexBot',
				'ICC-Crawler',
				'Cotoyogi',
				'atlassian-bot',
				'LinerBot',
				'magpie-crawler',
				'bigsur.ai',
				'QualifiedBot',
				'Awario',
				'amazon-kendra-',
				'Anchor Browser',
				'BorderxBot',
				'CitibotSiteCrawler',
				'CloudflareBrowserRenderingCrawler',
				'netEstate NE Crawler',
				'FishBot',
				'make.com',
				'NavuBot',
				'Novellum',
				'AdpResearchBot',
				'SelectikaScraper',
				'SemrushBot-OCOB',
				'SemrushBot-SWA',
				'WARDBot',
				'ygs-scraper-bot',
			),
		);

		return (array) apply_filters( 'markdown_for_agents_agent_categories', $defaults );
	}

	/**
	 * Resolve the reviewed operator behind an agent label.
	 *
	 * Operators are the organisations that run an agent (OpenAI, Anthropic, …).
	 * Like categorise_agent(), this is derived at read time from the stored label
	 * with case-insensitive substring matching, first match wins. Labels with no
	 * reviewed entry return null — an operator is never guessed from an arbitrary
	 * User-Agent, so callers should report those as unattributed.
	 *
	 * @since  1.8.0
	 * @param  string $agent The stored agent label (matched substring or product name).
	 * @return string|null   Operator key (e.g. 'openai'), or null when unattributed.
	 */
	public function get_operator( string $agent ): ?string {
		$agent = trim( $agent );
		if ( '' === $agent ) {
			return null;
		}

		foreach ( $this->get_agent_operators() as $key => $operator ) {
			foreach ( (array) ( $operator['agents'] ?? array() ) as $substring ) {
				if ( '' !== $substring && false !== stripos( $agent, (string) $substring ) ) {
					return (string) $key;
				}
			}
		}

		return null;
	}

	/**
	 * Return the display name for an operator key.
	 *
	 * @since  1.8.0
	 * @param  string $key Operator key from get_operator().
	 * @return string      Display name, or the key itself when unknown.
	 */
	public function get_operator_label( string $key ): string {
		$operators = $this->get_agent_operators();

		return (string) ( $operators[ $key ]['label'] ?? $key );
	}

	/**
	 * Return the operator key → {label, agents} map, filtered.
	 *
	 * Only agents whose operator has been reviewed are listed; the long tail stays
	 * unattributed rather than guessed. Tokens added through the categories filter
	 * or the agent-strings option have no operator until they are added here via
	 * the `markdown_for_agents_agent_operators` filter.
	 *
	 * Treat this map as append-only, like the category map: operators are derived
	 * from labels already in the stats table, so removing an entry retrospectively
	 * moves that agent's history into the unattributed bucket.
	 *
	 * @since  1.8.0
	 * @return array<string, array{label: string, agents: string[]}>
	 */
	public function get_agent_operators(): array {
		$defaults = array(
			'openai'       => array(
				'label'  => 'OpenAI',
				'agents' => array( 'ChatGPT-User', 'OAI-SearchBot', 'GPTBot' ),
			),
			'anthropic'    => array(
				'label'  => 'Anthropic',
				'agents' => array( 'Claude-User', 'Claude-Web', 'Claude-SearchBot', 'ClaudeBot', 'anthropic-ai' ),
			),
			'google'       => array(
				'label'  => 'Google',
				'agents' => array( 'Gemini-User', 'Google-Agent', 'Google-Extended', 'GoogleOther', 'CloudVertexBot' ),
			),
			'perplexity'   => array(
				'label'  => 'Perplexity',
				'agents' => array( 'Perplexity-User', 'PerplexityBot' ),
			),
			'meta'         => array(
				'label'  => 'Meta',
				'agents' => array( 'meta-externalfetcher', 'meta-externalagent' ),
			),
			'amazon'       => array(
				'label'  => 'Amazon',
				'agents' => array( 'Amzn-SearchBot', 'Amazonbot', 'amazon-kendra-' ),
			),
			'apple'        => array(
				'label'  => 'Apple',
				'agents' => array( 'Applebot-Extended' ),
			),
			'mistral'      => array(
				'label'  => 'Mistral AI',
				'agents' => array( 'MistralAI-User' ),
			),
			'bytedance'    => array(
				'label'  => 'ByteDance',
				'agents' => array( 'Bytespider' ),
			),
			'common-crawl' => array(
				'label'  => 'Common Crawl',
				'agents' => array( 'CCBot' ),
			),
			'cohere'       => array(
				'label'  => 'Cohere',
				'agents' => array( 'cohere-ai' ),
			),
			'duckduckgo'   => array(
				'label'  => 'DuckDuckGo',
				'agents' => array( 'DuckAssistBot' ),
			),
			'brave'        => array(
				'label'  => 'Brave',
				'agents' => array( 'Bravebot' ),
			),
			'cloudflare'   => array(
				'label'  => 'Cloudflare',
				'agents' => array( 'Cloudflare-AI-Search', 'CloudflareBrowserRenderingCrawler' ),
			),
			'moonshot'     => array(
				'label'  => 'Moonshot AI',
				'agents' => array( 'KimiBot' ),
			),
			'huawei'       => array(
				'label'  => 'Huawei',
				'agents' => array( 'PetalBot' ),
			),
			'cognition'    => array(
				'label'  => 'Cognition',
				'agents' => array( 'Devin' ),
			),
			'semrush'      => array(
				'label'  => 'Semrush',
				'agents' => array( 'SemrushBot-OCOB', 'SemrushBot-SWA' ),
			),
			'atlassian'    => array(
				'label'  => 'Atlassian',
				'agents' => array( 'atlassian-bot' ),
			),
		);

		return (array) apply_filters( 'markdown_for_agents_agent_operators', $defaults );
	}
}
