<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Stats\SvgBarChart;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\SvgBarChart
 */
class SvgBarChartTest extends TestCase {

	public function test_returns_svg_element(): void {
		$svg = SvgBarChart::render( [
			'series' => [ [ 'color' => '#2fb62f', 'data' => [ 1, 2, 3 ] ] ],
		] );

		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringEndsWith( '</svg>', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 1200 320"', $svg );
	}

	public function test_renders_one_rect_per_nonzero_value(): void {
		$svg = SvgBarChart::render( [
			'series' => [ [ 'color' => '#2fb62f', 'data' => [ 3, 0, 5, 0 ] ] ],
		] );

		// Two non-zero values → two bars; zeros are skipped.
		$this->assertSame( 2, substr_count( $svg, '<rect' ) );
	}

	public function test_stacks_multiple_series(): void {
		$svg = SvgBarChart::render( [
			'series' => [
				[ 'color' => '#8a8f8a', 'data' => [ 4, 4 ] ],
				[ 'color' => '#2fb62f', 'data' => [ 2, 2 ] ],
			],
		] );

		$this->assertStringContainsString( 'fill="#8a8f8a"', $svg );
		$this->assertStringContainsString( 'fill="#2fb62f"', $svg );
		// 2 slots × 2 series, all non-zero → 4 stacked segments.
		$this->assertSame( 4, substr_count( $svg, '<rect' ) );
	}

	public function test_respects_explicit_max(): void {
		// With max=10 and value=5, the bar height is half the inner height (320-16-28=276 → 138).
		$svg = SvgBarChart::render( [
			'series' => [ [ 'color' => '#2fb62f', 'data' => [ 5 ] ] ],
			'max'    => 10,
		] );

		$this->assertStringContainsString( 'height="138"', $svg );
	}

	public function test_renders_gridlines_and_axis_labels(): void {
		$svg = SvgBarChart::render( [
			'series' => [ [ 'color' => '#2fb62f', 'data' => [ 1 ] ] ],
			'ticks'  => 2,
		] );

		// ticks + 1 gridlines.
		$this->assertSame( 3, substr_count( $svg, '<line' ) );
	}

	public function test_renders_x_labels_every_nth(): void {
		$svg = SvgBarChart::render( [
			'series'     => [ [ 'color' => '#2fb62f', 'data' => [ 1, 1, 1, 1 ] ] ],
			'labels'     => [ 'a', 'b', 'c', 'd' ],
			'labelEvery' => 2,
		] );

		$this->assertStringContainsString( '>a<', $svg );
		$this->assertStringContainsString( '>c<', $svg );
		$this->assertStringNotContainsString( '>b<', $svg );
		$this->assertStringNotContainsString( '>d<', $svg );
	}

	public function test_handles_empty_series_without_error(): void {
		$svg = SvgBarChart::render( [ 'series' => [] ] );

		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringNotContainsString( '<rect', $svg );
	}

	public function test_handles_all_zero_data_without_error(): void {
		$svg = SvgBarChart::render( [
			'series' => [ [ 'color' => '#2fb62f', 'data' => [ 0, 0, 0 ] ] ],
		] );

		$this->assertStringStartsWith( '<svg', $svg );
		$this->assertStringNotContainsString( '<rect', $svg );
	}

	public function test_emits_no_nan_or_empty_coordinates(): void {
		$svg = SvgBarChart::render( [
			'series' => [ [ 'color' => '#2fb62f', 'data' => [ 7, 3, 9, 1 ] ] ],
			'labels' => [ 'a', 'b', 'c', 'd' ],
		] );

		$this->assertStringNotContainsString( 'NAN', strtoupper( $svg ) );
		$this->assertStringNotContainsString( '=""', $svg );
	}

	public function test_stacked_bars_are_full_width_and_equal_across_series(): void {
		$svg = SvgBarChart::render( [
			'series' => [
				[ 'color' => '#aaaaaa', 'data' => [ 2 ] ],
				[ 'color' => '#bbbbbb', 'data' => [ 2 ] ],
			],
		] );

		preg_match_all( '/width="([\d.]+)"/', $svg, $m );
		$widths = array_unique( $m[1] );
		// Every stacked segment shares one full-slot width.
		$this->assertCount( 1, $widths );
	}

	public function test_stacked_segments_sit_on_top_of_each_other(): void {
		// max=10, inner height 320-16-28=276 → 1 unit = 27.6px.
		$svg = SvgBarChart::render( [
			'series' => [
				[ 'color' => '#aaaaaa', 'data' => [ 2 ] ],
				[ 'color' => '#bbbbbb', 'data' => [ 3 ] ],
			],
			'max'    => 10,
		] );

		// Base segment (v=2) sits on the baseline; top segment (v=3) starts where
		// the base ends (y = 16 + 276 - 5/10*276 = 154).
		$this->assertStringContainsString( 'height="55.2"', $svg );
		$this->assertStringContainsString( 'y="154" width', $svg );
		$this->assertStringContainsString( 'height="82.8"', $svg );
	}

	public function test_y_max_uses_column_totals(): void {
		// Column total 3+4=7 → nice max 10 (the tallest single bar alone is 4).
		$svg = SvgBarChart::render( [
			'series' => [
				[ 'color' => '#aaaaaa', 'data' => [ 3 ] ],
				[ 'color' => '#bbbbbb', 'data' => [ 4 ] ],
			],
		] );

		// Top axis label is the max; the stacked column total drives it to 10.
		$this->assertStringContainsString( '>10<', $svg );
	}

	public function test_escapes_label_content(): void {
		$svg = SvgBarChart::render( [
			'series'     => [ [ 'color' => '#2fb62f', 'data' => [ 1 ] ] ],
			'labels'     => [ '<script>' ],
			'labelEvery' => 1,
		] );

		$this->assertStringNotContainsString( '<script>', $svg );
		$this->assertStringContainsString( '&lt;script&gt;', $svg );
	}
}
