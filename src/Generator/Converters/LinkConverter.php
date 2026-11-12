<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator\Converters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Tclp\WpMarkdownForAgents\Vendor\League\HTMLToMarkdown\Converter\LinkConverter as BaseLinkConverter;
use Tclp\WpMarkdownForAgents\Vendor\League\HTMLToMarkdown\ElementInterface;

/**
 * Keeps Markdown link text inline when a link wraps block markup.
 *
 * Markdown link text may only hold inline content. When an `<a>` wraps block
 * markup (a card with an image, a heading, a tag list and a summary), the
 * default converter puts headings, list items and blank lines inside `[...]`.
 * Parsers then end the link at the first block line, so the text loses its
 * link and a stray `](url)` is left behind.
 *
 * Only link text that spans more than one line is changed. A single-line link
 * is left exactly as the default converter writes it, spaces and all.
 *
 * Multi-line text becomes a single, single-spaced line. Heading, bullet and
 * quote markers at the start of a line (`#`, `-`, `*`, `>`) are dropped; the
 * converter has already backslash-escaped literal text that looks like one of
 * them (`\#`, `\-`, `\*`). Numbers (`1.`, `1)`) and `+` are kept: they are not
 * escaped until later, so a literal "2. Payment terms" cannot be told apart
 * from a list item here. Whitespace at either end of the text becomes one
 * space outside the brackets, so neighbouring words do not run together.
 *
 * @since  1.8.0
 * @package Tclp\WpMarkdownForAgents\Generator\Converters
 */
class LinkConverter extends BaseLinkConverter {

	/**
	 * Convert an `<a>` element, flattening multi-line text to one line.
	 *
	 * @param  ElementInterface $element The link element.
	 * @return string
	 */
	public function convert( ElementInterface $element ): string {
		$markdown = parent::convert( $element );

		// Same text the parent class builds the link from.
		$text   = trim( $element->getValue(), "\t\n\r\0\x0B" );
		$prefix = '[' . $text . '](';

		// Single-line text, autolinks, bare text from href-less links and empty links are left alone.
		if ( ! preg_match( '/\R/u', $text ) || ! str_starts_with( $markdown, $prefix ) ) {
			return $markdown;
		}

		$before = preg_match( '/^\s/u', $text ) ? ' ' : '';
		$after  = preg_match( '/\s$/u', $text ) ? ' ' : '';

		return $before . '[' . self::flatten( $text ) . '](' . substr( $markdown, strlen( $prefix ) ) . $after;
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
			$line = preg_replace( '/^(?:#{1,6}\h+|[-*]\h+|(?:>\h?)+)/u', '', $line ) ?? $line;

			if ( '' !== $line ) {
				$parts[] = $line;
			}
		}

		$flat = implode( ' ', $parts );

		// Collapse runs of whitespace; a single (possibly non-breaking) space is kept.
		return preg_replace( '/\h{2,}/u', ' ', $flat ) ?? $flat;
	}
}
