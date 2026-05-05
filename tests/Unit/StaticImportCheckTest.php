<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression: enforce that no source file imports league/html-to-markdown
 * via its original namespace. Strauss prefixes the library at install time,
 * so every reference must go through Tclp\WpMarkdownForAgents\Vendor\.
 *
 * Without this guard, a developer adding a new converter could silently
 * reintroduce the autoloader-collision bug class.
 */
class StaticImportCheckTest extends TestCase {

	public function test_no_unprefixed_league_html_to_markdown_references_in_src(): void {
		$src_dir = realpath( __DIR__ . '/../../src' );
		$this->assertNotFalse( $src_dir, 'src/ directory not found' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src_dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		$offences = [];

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
				continue;
			}

			$lines = file( $file->getPathname(), FILE_IGNORE_NEW_LINES );

			foreach ( $lines as $i => $line ) {
				if ( str_contains( $line, 'League\\HTMLToMarkdown\\' )
					&& ! str_contains( $line, 'Vendor\\League\\HTMLToMarkdown\\' ) ) {
					$offences[] = sprintf( '%s:%d  %s', $file->getPathname(), $i + 1, trim( $line ) );
				}
			}
		}

		$this->assertEmpty(
			$offences,
			"Found unprefixed League\\HTMLToMarkdown references. Use Tclp\\WpMarkdownForAgents\\Vendor\\League\\HTMLToMarkdown\\ instead:\n" . implode( "\n", $offences )
		);
	}
}
