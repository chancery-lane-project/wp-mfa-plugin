<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator\Converters;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Converts HTML tables to GFM-style Markdown tables.
 *
 * Adapted from wp-to-file TableConverter (namespace change only).
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator\Converters
 */
class TableConverter implements ConverterInterface {

	/**
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption' );
	}

	public function convert( ElementInterface $element ): string {
		$tag = $element->getTagName();

		switch ( $tag ) {
			case 'table':
				return $this->convert_table( $element );
			case 'caption':
				$caption = trim( $element->getValue() );
				return $caption ? "**{$caption}**\n\n" : '';
			case 'thead':
			case 'tbody':
			case 'tfoot':
				return $element->getValue();
			case 'tr':
				return $this->convert_row( $element );
			case 'th':
			case 'td':
				return $this->convert_cell( $element );
			default:
				return $element->getValue();
		}
	}

	private function convert_table( ElementInterface $element ): string {
		$rows       = array();
		$header_row = null;
		$max_cols   = 0;

		$html  = $element->getValue();
		$lines = array_filter( array_map( 'trim', explode( "\n", $html ) ) );

		foreach ( $lines as $line ) {
			if ( str_starts_with( $line, '|' ) ) {
				$cells    = array_map( 'trim', explode( '|', trim( $line, '|' ) ) );
				$max_cols = max( $max_cols, count( $cells ) );

				if ( null === $header_row ) {
					$header_row = $cells;
				} else {
					$rows[] = $cells;
				}
			}
		}

		if ( null === $header_row ) {
			return $this->build_table_from_element( $element );
		}

		$output  = '| ' . implode( ' | ', $this->pad_cells( $header_row, $max_cols ) ) . " |\n";
		$output .= '|' . str_repeat( ' --- |', $max_cols ) . "\n";

		foreach ( $rows as $row ) {
			$output .= '| ' . implode( ' | ', $this->pad_cells( $row, $max_cols ) ) . " |\n";
		}

		return "\n\n" . $output . "\n";
	}

	private function build_table_from_element( ElementInterface $element ): string {
		$dom = new \DOMDocument();
		$dom->loadHTML(
			'<?xml encoding="utf-8" ?>' . $element->getChildrenAsString(),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		$headers = array();
		$rows    = array();

		foreach ( $dom->getElementsByTagName( 'th' ) as $th ) {
			$headers[] = trim( wp_strip_all_tags( $th->textContent ) );
		}

		foreach ( $dom->getElementsByTagName( 'tr' ) as $tr ) {
			$cells    = array();
			$td_nodes = $tr->getElementsByTagName( 'td' );
			if ( $td_nodes->length > 0 ) {
				foreach ( $td_nodes as $td ) {
					$cells[] = trim( wp_strip_all_tags( $td->textContent ) );
				}
				if ( ! empty( $cells ) ) {
					$rows[] = $cells;
				}
			}
		}

		if ( empty( $headers ) && ! empty( $rows ) ) {
			$headers = array_shift( $rows );
		}

		if ( empty( $headers ) ) {
			return '';
		}

		$max_cols = max( count( $headers ), ...array_map( 'count', $rows ?: array( array() ) ) );
		$output   = '| ' . implode( ' | ', $this->pad_cells( $headers, $max_cols ) ) . " |\n";
		$output  .= '|' . str_repeat( ' --- |', $max_cols ) . "\n";

		foreach ( $rows as $row ) {
			$output .= '| ' . implode( ' | ', $this->pad_cells( $row, $max_cols ) ) . " |\n";
		}

		return "\n\n" . $output . "\n";
	}

	private function convert_row( ElementInterface $element ): string {
		$cells = array_filter( array_map( 'trim', explode( '|', $element->getValue() ) ) );
		if ( empty( $cells ) ) {
			return '';
		}
		return '| ' . implode( ' | ', $cells ) . " |\n";
	}

	private function convert_cell( ElementInterface $element ): string {
		$content = trim( $element->getValue() );
		$content = str_replace( '|', '\\|', $content );
		$content = preg_replace( '/\s+/', ' ', $content ) ?? $content;
		return $content . ' |';
	}

	/**
	 * @param  string[] $cells
	 * @return string[]
	 */
	private function pad_cells( array $cells, int $count ): array {
		while ( count( $cells ) < $count ) {
			$cells[] = '';
		}
		return array_slice( $cells, 0, $count );
	}
}
