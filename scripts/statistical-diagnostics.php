<?php
/**
 * Pure offline diagnostics for a validated, complete benchmark matrix.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Describe finite observations without converting diagnostics into a verdict.
 *
 * @param float[] $values Nonempty finite observations.
 * @return array Counts, location, and empirical distribution limits.
 */
function review_distribution( array $values ): array {

	return [
		'count'   => \count( $values ),
		'mean'    => array_sum( $values ) / \count( $values ),
		'median'  => median( $values ),
		'p05'     => percentile( $values, 0.05 ),
		'p95'     => percentile( $values, 0.95 ),
		'minimum' => min( $values ),
		'maximum' => max( $values ),
	];
}

/**
 * Calculate Pearson correlation and ordinary least squares slope.
 *
 * Correlation centers each paired vector independently. The slope is the sum
 * of centered cross-products divided by the centered sum of squares of x.
 * Undefined quantities remain null instead of being reported as zero.
 *
 * @param float[] $x First paired vector.
 * @param float[] $y Second paired vector with the same length.
 * @return array Pair count, correlation, and slope of y on x.
 */
function review_linear_relation( array $x, array $y ): array {

	$count  = \count( $x );
	$result = [
		'pairs'       => $count,
		'correlation' => null,
		'slope'       => null,
	];

	if ( 2 > $count ) return $result;
	$mean_x = array_sum( $x ) / $count;
	$mean_y = array_sum( $y ) / $count;
	$xx     = $yy = $xy = 0.0;
	foreach ( $x as $index => $value ) {
		$dx  = $value - $mean_x;
		$dy  = $y[ $index ] - $mean_y;
		$xx += $dx * $dx;
		$yy += $dy * $dy;
		$xy += $dx * $dy;
	}

	if ( 0 < $xx ) $result['slope']                  = $xy / $xx;
	if ( 0 < $xx && 0 < $yy ) $result['correlation'] = $xy / sqrt( $xx * $yy );

	return $result;
}

/**
 * Reproduce pooled-denominator bootstrap failures without discarding draws.
 *
 * Each draw uses the same block indices for every route and arm, with the
 * original Protocol.php generator and pass seed. Medians are recalculated
 * from the concatenated raw samples, not from precomputed block medians.
 * Failure counts are diagnostic; no ratio interval is fitted conditionally
 * on a positive denominator. This deliberately uses exactly 5000 draws.
 *
 * @param array    $data   Raw measured values grouped by route, block, and arm.
 * @param string[] $routes Declared routes, in recipe order.
 * @param string   $anchor Denominator arm identifier.
 * @param int      $seed   Recipe seed plus the pass number.
 * @return array Failure counts and unconditional denominator percentiles.
 */
function review_original_denominators( array $data, array $routes, string $anchor, int $seed ): array {

	$draws       = 5000;
	$state       = ( $seed % 2147483646 ) + 1;
	$block_ids   = array_keys( $data[ $routes[0] ] );
	$block_count = \count( $block_ids );
	$failures    = array_fill_keys( $routes, 0 );
	$values      = array_fill_keys( $routes, [] );
	$any_failure = 0;
	for ( $draw = 0; $draw < $draws; ++$draw ) {
		$selection = [];
		for ( $i = 0; $i < $block_count; ++$i )
			$selection[] = $block_ids[ next_random( $state, $block_count ) ];
		$failed          = false;
		foreach ( $routes as $route ) {
			$anchor_values = $control_values = [];
			foreach ( $selection as $block ) {
				$cell           = $data[ $route ][ $block ];
				$anchor_values  = array_merge( $anchor_values, $cell[ $anchor ] );
				$control_values = array_merge(
					$control_values,
					$cell['core-a'],
					$cell['core-b'],
				);
			}
			$denominator        = median( $anchor_values ) - median( $control_values );
			$values[ $route ][] = $denominator;

			if ( 0 >= $denominator ) {
				++$failures[ $route ];
				$failed = true;
			}
		}

		if ( $failed ) ++$any_failure;
	}
	$intervals = [];
	foreach ( $routes as $route ) {
		$intervals[ $route ] = [
			'low'     => percentile( $values[ $route ], 0.025 ),
			'high'    => percentile( $values[ $route ], 0.975 ),
			'minimum' => min( $values[ $route ] ),
		];
	}

	return [
		'draws'                          => $draws,
		'seed'                           => $seed,
		'nonpositive_any_route_draws'    => $any_failure,
		'nonpositive_any_route_fraction' => $any_failure / $draws,
		'nonpositive_draws_by_route'     => $failures,
		'denominator_percentile_95_ms'   => $intervals,
	];
}

/**
 * Diagnose existing observations without changing the reference analysis.
 *
 * The caller must validate the recipe and the complete sample matrix first.
 * Warmups are retained by the caller but do not enter these diagnostics.
 * Protocol.php must already provide median(), percentile(), and next_random().
 * No module loads, filesystem access, services, or default-analysis changes
 * occur here. Results describe the supplied data and do not qualify a claim.
 *
 * @param array $recipe Validated recipe with declared routes and arms.
 * @param array $samples Validated complete warmup and measured observations.
 * @return array Deterministic diagnostics, including all 5000 bootstrap draws.
 */
function review_diagnostics( array $recipe, array $samples ): array {

	$arms   = array_column( $recipe['arms'], 'id' );
	$routes = array_column( $recipe['routes'], 'id' );
	$anchor = $recipe['comparison']['anchor'];
	$groups = $rows = [];
	foreach ( $samples as $row ) {
		if ( 'measure' !== $row['phase'] ) continue;
		$groups[ $row['pass'] ][ $row['route'] ][ $row['block'] ][ $row['arm'] ][] = (float) $row['elapsed_ms'];
		$rows[ $row['pass'] ][ $row['route'] ][ $row['block'] ][]                  = $row;
	}
	$result = [
		'schema'      => 1,
		'scope'       => 'offline_diagnostics_only',
		'anchor'      => $anchor,
		'definitions' => [
			'paired_added_ms'              => 'Arm block median minus the median of the twelve same-block control observations; generalizes to the declared sample count.',
			'control_bias_ms'              => 'Core B block median minus core A block median.',
			'shared_control_difference_ms' => 'Comparator block median minus anchor block median; subtracting their shared control cancels it exactly.',
			'residual_lag_correlation'     => 'Within each route and schedule block, order measured rows by sequence then position and subtract that arm block median. Pool pairs (residual[i], residual[i+lag]) without crossing block boundaries, then calculate Pearson correlation with each pooled vector centered independently. No independence or causal interpretation is implied.',
			'odd_even_split_ms'            => 'Absolute difference between the mean of odd-sequence and even-sequence observations within one route, block, and arm; absent when fewer than two sequences are declared.',
			'block_trend'                  => 'OLS against schedule block number after centering each response within its route. End-to-end change multiplies the slope by the number of blocks minus one. This is a descriptive within-route trend, not a causal adjustment.',
		],
		'passes'      => [],
	];
	for ( $pass = 1; $pass <= $recipe['measurement']['passes']; ++$pass ) {
		$data = $groups[ $pass ];
		foreach ( $routes as $route ) ksort( $data[ $route ], \SORT_NUMERIC );
		$block_count = \count( $data[ $routes[0] ] );
		$paired      = $bias = $odd_even = $all_anchor = $all_control = [];
		$trend_x     = $trend_core = $trend_anchor = $trend_added = [];
		$lag_pairs   = array_fill_keys( [ 9, 18, 27 ],
			[
				'x' => [],
				'y' => [],
			] );
		$pass_result = [
			'pass'   => $pass,
			'routes' => [],
		];
		foreach ( $routes as $route ) {
			$route_added  = array_fill_keys( $arms, [] );
			$route_shared = $route_bias = $route_core = $route_anchor = [];
			$raw          = array_fill_keys( $arms, [] );
			foreach ( $data[ $route ] as $block => $cell ) {
				$core    = median( array_merge( $cell['core-a'], $cell['core-b'] ) );
				$medians = [];
				foreach ( $arms as $arm ) {
					$medians[ $arm ]       = median( $cell[ $arm ] );
					$route_added[ $arm ][] = $medians[ $arm ] - $core;
					$raw[ $arm ]           = array_merge( $raw[ $arm ], $cell[ $arm ] );
				}
				$route_bias[]               = $medians['core-b'] - $medians['core-a'];
				$route_core[]               = $core;
				$route_anchor[]             = $medians[ $anchor ];
				foreach ( array_diff( $arms, [ 'core-a', 'core-b', $anchor ] ) as $arm )
					$route_shared[ $arm ][] = $medians[ $arm ] - $medians[ $anchor ];
				$ordered                    = $rows[ $pass ][ $route ][ $block ];
				usort(
					$ordered,
					fn( array $a, array $b ): int => [ $a['sequence'], $a['position'] ] <=> [ $b['sequence'], $b['position'] ],
				);
				$residuals = [];
				$parities  = [];
				foreach ( $ordered as $row ) {
					$residuals[]                                       = $row['elapsed_ms'] - $medians[ $row['arm'] ];
					$parities[ $row['arm'] ][ $row['sequence'] % 2 ][] = $row['elapsed_ms'];
				}
				foreach ( $parities as $values ) {
					if ( ! isset( $values[0], $values[1] ) ) continue;
					$odd_even[] = abs( array_sum( $values[0] ) / \count( $values[0] ) - array_sum( $values[1] ) / \count( $values[1] ) );
				}
				foreach ( [ 9, 18, 27 ] as $lag ) {
					for ( $i = $lag, $count = \count( $residuals ); $i < $count; ++$i ) {
						$lag_pairs[ $lag ]['x'][] = $residuals[ $i - $lag ];
						$lag_pairs[ $lag ]['y'][] = $residuals[ $i ];
					}
				}
			}
			$core_raw = array_merge( $raw['core-a'], $raw['core-b'] );
			$pooled   = median( $raw[ $anchor ] ) - median( $core_raw );
			$lobo_raw = $lobo_paired = [];
			foreach ( $data[ $route ] as $omitted => $cell ) {
				$anchor_values = $control_values = [];
				foreach ( $data[ $route ] as $block => $remaining ) {
					if ( $omitted === $block ) continue;
					$anchor_values  = array_merge( $anchor_values, $remaining[ $anchor ] );
					$control_values = array_merge( $control_values, $remaining['core-a'], $remaining['core-b'] );
				}

				if ( ! $anchor_values ) continue;
				$lobo_raw[]    = median( $anchor_values ) - median( $control_values );
				$lobo_paired[] = ( array_sum( $route_added[ $anchor ] ) - ( median( $cell[ $anchor ] ) - median( array_merge( $cell['core-a'], $cell['core-b'] ) ) ) ) / ( $block_count - 1 );
			}
			$route_result                    = [
				'pooled_anchor_added_ms'           => $pooled,
				'paired_added_ms'                  => array_map( __NAMESPACE__ . '\\review_distribution', $route_added ),
				'paired_anchor_nonpositive_blocks' => \count( array_filter( $route_added[ $anchor ], fn( float $value ): bool => 0 >= $value ) ),
				'control_bias_ms'                  => review_distribution( $route_bias ),
				'shared_control_difference_ms'     => array_map( __NAMESPACE__ . '\\review_distribution', $route_shared ),
				'leave_one_block_out'              => [
					'pooled_anchor_added_ms'              => $lobo_raw ? review_distribution( $lobo_raw ) : null,
					'pooled_anchor_nonpositive_deletions' => \count( array_filter( $lobo_raw, fn( float $value ): bool => 0 >= $value ) ),
					'paired_anchor_mean_added_ms'         => $lobo_paired ? review_distribution( $lobo_paired ) : null,
				],
			];
			$pass_result['routes'][ $route ] = $route_result;
			$paired                          = array_merge( $paired, $route_added[ $anchor ] );
			$bias                            = array_merge( $bias, $route_bias );
			$all_anchor                      = array_merge( $all_anchor, $raw[ $anchor ] );
			$all_control                     = array_merge( $all_control, $core_raw );
			$mean_core                       = array_sum( $route_core ) / $block_count;
			$mean_anchor                     = array_sum( $route_anchor ) / $block_count;
			$mean_added                      = array_sum( $route_added[ $anchor ] ) / $block_count;
			foreach ( array_keys( $data[ $route ] ) as $index => $block ) {
				$trend_x[]      = (float) $block;
				$trend_core[]   = $route_core[ $index ] - $mean_core;
				$trend_anchor[] = $route_anchor[ $index ] - $mean_anchor;
				$trend_added[]  = $route_added[ $anchor ][ $index ] - $mean_added;
			}
		}
		$pass_result['counts']                                 = [
			'schedule_blocks' => $block_count,
			'route_blocks'    => \count( $paired ),
			'measured_rows'   => \count( $paired ) * \count( $arms ) * $recipe['measurement']['samples'],
		];
		$pass_result['pooled_control_median_ms']               = median( $all_control );
		$pass_result['pooled_anchor_median_ms']                = median( $all_anchor );
		$pass_result['paired_anchor_added_ms']                 = review_distribution( $paired );
		$pass_result['paired_anchor_nonpositive_blocks']       = \count( array_filter( $paired, fn( float $value ): bool => 0 >= $value ) );
		$pass_result['control_bias_ms']                        = review_distribution( $bias );
		$pass_result['absolute_odd_even_mean_split_ms']        = $odd_even ? review_distribution( $odd_even ) : null;
		$pass_result['residual_serial_dependence']             = [];
		foreach ( $lag_pairs as $lag => $values )
			$pass_result['residual_serial_dependence'][ $lag ] = review_linear_relation( $values['x'], $values['y'] );
		$pass_result['within_route_block_trends']              = [];
		foreach ( [
			'control'      => $trend_core,
			'anchor'       => $trend_anchor,
			'anchor_added' => $trend_added,
		] as $name => $values ) {
			$relation = review_linear_relation( $trend_x, $values );
			$pass_result['within_route_block_trends'][ $name ] = [
				'ms_per_block'  => $relation['slope'],
				'end_to_end_ms' => null === $relation['slope'] ? null : $relation['slope'] * ( $block_count - 1 ),
			];
		}
		$pass_result['original_denominator_bootstrap'] = review_original_denominators(
			$data,
			$routes,
			$anchor,
			$recipe['measurement']['seed'] + $pass,
		);
		$result['passes'][]                            = $pass_result;
	}

	return $result;
}
