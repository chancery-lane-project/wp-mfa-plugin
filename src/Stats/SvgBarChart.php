<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Ultra-minimal, dependency-free SVG bar chart.
 *
 * Series are stacked into one full-width bar per slot (drawn bottom→top in
 * array order). Returns an SVG string sized by its viewBox (the chart scales to
 * its container). No JavaScript, no build step.
 *
 * @since  1.5.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class SvgBarChart {

	/**
	 * Render the chart to an SVG string.
	 *
	 * @since  1.5.0
	 * @param  array<string, mixed> $o {
	 *     Chart definition.
	 *
	 *     @type array<int, array{name?: string, color: string, data: array<int, int|float>}> $series  One or more series, stacked bottom→top in array order.
	 *     @type array<int, string>                                                            $labels  X-axis labels, one per bar slot.
	 *     @type int                                                                           $width   ViewBox width. Default 1200.
	 *     @type int                                                                           $height  ViewBox height. Default 320.
	 *     @type array{top?: float, right?: float, bottom?: float, left?: float}               $pad     Inner padding.
	 *     @type float                                                                         $max     Fixed y-max. Default a "nice" auto max.
	 *     @type int                                                                           $ticks   Gridlines above the baseline. Default 2.
	 *     @type float                                                                         $gap     Fraction of each slot left empty (0–1). Default 0.30.
	 *     @type int                                                                           $labelEvery Show every Nth x-label. Default 2.
	 * }
	 * @return string SVG markup.
	 */
	public static function render( array $o ): string {
		$w   = (float) ( $o['width'] ?? 1200 );
		$h   = (float) ( $o['height'] ?? 320 );
		$pad = array_merge(
			array(
				'top'    => 16.0,
				'right'  => 38.0,
				'bottom' => 28.0,
				'left'   => 8.0,
			),
			$o['pad'] ?? array()
		);

		$series = array_values( $o['series'] ?? array() );
		$n      = empty( $series ) ? 0 : count( $series[0]['data'] );

		$iw = $w - $pad['left'] - $pad['right'];
		$ih = $h - $pad['top'] - $pad['bottom'];

		// Y scale — round the tallest column total up to a clean step.
		$sums = array_fill( 0, $n, 0.0 );
		foreach ( $series as $ser ) {
			foreach ( $ser['data'] as $i => $v ) {
				$sums[ $i ] += (float) $v;
			}
		}
		$peak = empty( $sums ) ? 1.0 : max( 1.0, ...$sums );
		$max  = isset( $o['max'] ) ? (float) $o['max'] : self::nice( $peak );
		if ( $max <= 0 ) {
			$max = 1.0;
		}

		$y = static fn( float $v ): float => $pad['top'] + $ih - ( $v / $max ) * $ih;

		$slot  = $n > 0 ? $iw / $n : $iw;
		$gap   = (float) ( $o['gap'] ?? 0.30 );
		$every = max( 1, (int) ( $o['labelEvery'] ?? 2 ) );
		$ticks = max( 1, (int) ( $o['ticks'] ?? 2 ) );

		$svg = sprintf(
			'<svg viewBox="0 0 %s %s" xmlns="http://www.w3.org/2000/svg" role="img">',
			self::num( $w ),
			self::num( $h )
		);

		// Gridlines + right-hand axis labels.
		for ( $t = 0; $t <= $ticks; $t++ ) {
			$v  = ( $max / $ticks ) * $t;
			$yy = $y( $v );
			$svg .= sprintf(
				'<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="var(--grid,#ececec)"/>',
				self::num( $pad['left'] ),
				self::num( $yy ),
				self::num( $pad['left'] + $iw ),
				self::num( $yy )
			);
			$svg .= sprintf(
				'<text x="%s" y="%s" font-size="12" fill="var(--muted,#8a8f8a)">%s</text>',
				self::num( $w - $pad['right'] + 6 ),
				self::num( $yy + 4 ),
				esc_html( self::num( $v ) )
			);
		}

		// Bars: one full-width bar per slot; each series sits on the previous top.
		$bw    = $slot * ( 1 - $gap );
		$stack = array_fill( 0, $n, 0.0 );
		foreach ( $series as $ser ) {
			foreach ( $ser['data'] as $i => $v ) {
				$v = (float) $v;
				if ( $v <= 0 ) {
					continue;
				}
				$top = $stack[ $i ] + $v;
				$cx  = $pad['left'] + $slot * $i + $slot / 2;
				$svg .= sprintf(
					'<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
					self::num( $cx - $bw / 2 ),
					self::num( $y( $top ) ),
					self::num( $bw ),
					self::num( ( $v / $max ) * $ih ),
					esc_attr( (string) ( $ser['color'] ?? '#1a9e1a' ) )
				);
				$stack[ $i ] = $top;
			}
		}

		// X labels.
		$labels = $o['labels'] ?? array();
		foreach ( $labels as $i => $lab ) {
			if ( $i % $every ) {
				continue;
			}
			$cx = $pad['left'] + $slot * $i + $slot / 2;
			$svg .= sprintf(
				'<text x="%s" y="%s" font-size="12" text-anchor="middle" fill="var(--muted,#8a8f8a)">%s</text>',
				self::num( $cx ),
				self::num( $h - 8 ),
				esc_html( (string) $lab )
			);
		}

		return $svg . '</svg>';
	}

	/**
	 * Round a value up to a clean axis maximum (1, 2, 5 × 10ⁿ).
	 *
	 * @since  1.5.0
	 * @param  float $v Positive peak value.
	 * @return float
	 */
	private static function nice( float $v ): float {
		$e = 10 ** floor( log10( $v ) );
		$m = $v / $e;
		$f = $m <= 1 ? 1 : ( $m <= 2 ? 2 : ( $m <= 5 ? 5 : 10 ) );

		return $f * $e;
	}

	/**
	 * Format a coordinate: up to 1 decimal place, locale-independent, no trailing zeros.
	 *
	 * @since  1.5.0
	 * @param  float $v
	 * @return string
	 */
	private static function num( float $v ): string {
		$s = number_format( $v, 1, '.', '' );

		return str_contains( $s, '.' ) ? rtrim( rtrim( $s, '0' ), '.' ) : $s;
	}
}
