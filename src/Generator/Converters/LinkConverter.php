<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator\Converters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Vendor\League\HTMLToMarkdown\Converter\LinkConverter as BaseLinkConverter;
use Tclp\WpMarkdownForAgents\Vendor\League\HTMLToMarkdown\ElementInterface;

/**
 * Keeps Markdown link text inline.
 *
 * Markdown link text may only hold inline content. When an `<a>` wraps block
 * markup (a card with an image, a heading, a tag list and a summary), the
 * default converter puts headings, list items and blank lines inside `[...]`.
 * Parsers then end the link at the first block line, so the text loses its
 * link and a stray `](url)` is left behind.
 *
 * This converter applies one rule to every link: the text becomes a single,
 * single-spaced line. Block markers at the start of a line (`#`, `-`, `1.`,
 * `>`) are dropped. All text and images are kept. Literal characters that
 * happen to look like markers are already backslash-escaped by the converter
 * (`\#`, `\-`), so they are not affected.
 *
 * @since  1.8.0
 * @package Tclp\WpMarkdownForAgents\Generator\Converters
 */
class LinkConverter extends BaseLinkConverter {

	/**
	 * Convert an `<a>` element, flattening its text to one line.
	 *
	 * @param  ElementInterface $element The link element.
	 * @return string
	 */
	public function convert( ElementInterface $element ): string {
		$markdown = parent::convert( $element );

		// Same text the parent class builds the link from.
		$text   = trim( $element->getValue(), "\t\n\r\0\x0B" );
		$prefix = '[' . $text . '](';

		// Autolinks, bare text from href-less links, and empty links are left alone.
		if ( '' === $text || ! str_starts_with( $markdown, $prefix ) ) {
			return $markdown;
		}

		$flat = self::flatten( $text );

		if ( $flat === $text ) {
			return $markdown;
		}

		return '[' . $flat . '](' . substr( $markdown, strlen( $prefix ) );
	}

	/**
	 * Flatten Markdown text to a single line.
	 *
	 * @param  string $text Markdown link text.
	 * @return string
	 */
	public static function flatten( string $text ): string {
		$parts = array();
		$lines = preg_split( '/\R/u', $text );

		foreach ( false === $lines ? array( $text ) : $lines as $line ) {
			$line = preg_replace( '/^\h+|\h+$/u', '', $line ) ?? $line;
			$line = preg_replace( '/^(?:#{1,6}\h+|[-*+]\h+|\d+[.)]\h+|(?:>\h?)+)/u', '', $line ) ?? $line;

			if ( '' !== $line ) {
				$parts[] = $line;
			}
		}

		$flat = implode( ' ', $parts );

		// Collapse runs of whitespace; a single (possibly non-breaking) space is kept.
		return preg_replace( '/\h{2,}/u', ' ', $flat ) ?? $flat;
	}
}
