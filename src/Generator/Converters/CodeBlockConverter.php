<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator\Converters;

use Tclp\WpMarkdownForAgents\Vendor\League\HTMLToMarkdown\Converter\ConverterInterface;
use Tclp\WpMarkdownForAgents\Vendor\League\HTMLToMarkdown\ElementInterface;

/**
 * Converts WordPress Gutenberg code blocks to fenced Markdown code blocks.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator\Converters
 */
class CodeBlockConverter implements ConverterInterface {

	/**
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'pre' );
	}

	public function convert( ElementInterface $element ): string {
		$css_class = $element->getAttribute( 'class' ) ?? '';

		if ( ! str_contains( $css_class, 'wp-block-code' ) ) {
			return $element->getValue();
		}

		$language = $this->extract_language( $css_class );
		$code     = $this->extract_code( $element );

		// Strip any existing fenced markers the default converter may have added.
		$code = preg_replace( '/^```[a-z]*\n/m', '', $code ) ?? $code;
		$code = preg_replace( '/\n```$/m', '', $code ) ?? $code;
		$code = trim( $code );

		return "\n```{$language}\n{$code}\n```\n\n";
	}

	private function extract_language( string $css_class ): string {
		if ( preg_match( '/is-style-(\w+)/', $css_class, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	private function extract_code( ElementInterface $element ): string {
		try {
			$reflection    = new \ReflectionClass( $element );
			$node_property = $reflection->getProperty( 'node' );
			$dom_node      = $node_property->getValue( $element );

			if ( $dom_node instanceof \DOMNode ) {
				$code = $dom_node->textContent ?? ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode API.
				$code = html_entity_decode( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return rtrim( $code );
			}
		} catch ( \ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fall through to the tag-stripping fallback below.
			// Fall through to fallback.
		}

		$code = wp_strip_all_tags( $element->getChildrenAsString() );
		$code = html_entity_decode( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return rtrim( $code );
	}
}
