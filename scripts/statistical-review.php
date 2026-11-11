<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * Shared paired-block calculations used by the default and historical review.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

/**
 * Validate observations through the original analyzer, then form paired cells.
 *
 * Timing eligibility warnings remain diagnostics. Invalid responses, incomplete
 * matrices, changing output, and environment blockers still prevent analysis.
 *
 * @param array $recipe      Original declared experiment.
 * @param array $samples     All retained observations, including warmups.
 * @param array $environment Original environment qualification.
 * @return array Cells, point estimates, and original verdict.
 * @throws \RuntimeException When evidence is incomplete or otherwise invalid.
 */
function review_cells( array $recipe, array $samples, array $environment ): array {

	$original = analyze_legacy( $recipe, $samples, $environment );
	$allowed  = [
		'This is a setup/small-sample run. It cannot establish a broad comparative performance claim.',
		'At least one declared route, arm, control, or environment condition is unqualified; the full-cohort claim remains unresolved.',
		'Bootstrap denominators were unresolved; no bounded ratio interval is reported.',
	];
	foreach ( array_merge( [ $original ], $original['passes'] ) as $scope ) {
		foreach ( $scope['reasons'] as $reason ) {
			if ( ! \in_array( $reason, $allowed, true ) )
				throw new \RuntimeException( 'Evidence remains blocked: ' . $reason );
		}
	}
	foreach ( $original['passes'] as $pass ) {
		foreach ( $pass['routes'] as $route ) {
			if ( ! ( $route['controls_stable'] ?? false ) )
				throw new \RuntimeException( 'Stable matching controls are required.' );
			foreach ( $route['arms'] as $arm ) {
				if ( ! $arm['stable'] )
					throw new \RuntimeException( 'Stable selected output is required.' );
			}
		}
	}
	return [
		'cells'            => review_build_cells( $recipe, $samples ),
		'original_verdict' => $original['verdict'],
		'original_scope'   => $original['scope'],
	];
}

/**
 * Form paired additions from a complete, validated measurement matrix.
 *
 * @param array $recipe  Declared experiment definition.
 * @param array $samples Observations already checked for complete valid cells.
 * @return array Additions grouped by pass, route, and schedule block.
 */
function review_build_cells( array $recipe, array $samples ): array {

	$groups = [];
	foreach ( $samples as $row ) {
		if ( 'measure' === $row['phase'] )
			$groups[ $row['pass'] ][ $row['route'] ][ $row['block'] ][ $row['arm'] ][] = $row['elapsed_ms'];
	}
	$arms  = array_values( array_diff( array_column( $recipe['arms'], 'id' ), [ 'core-a', 'core-b' ] ) );
	$cells = [];
	foreach ( $groups as $pass => $routes ) {
		foreach ( $routes as $route => $blocks ) {
			ksort( $blocks, \SORT_NUMERIC );
			foreach ( $blocks as $block ) {
				$baseline                   = median( array_merge( $block['core-a'], $block['core-b'] ) );
				$vector                     = [];
				foreach ( $arms as $arm )
					$vector[ $arm ]         = median( $block[ $arm ] ) - $baseline;
				$cells[ $pass ][ $route ][] = $vector;
			}
		}
	}
	return $cells;
}

/**
 * Calculate linear contrasts without dividing by a noisy estimated addition.
 *
 * @param array  $added     Estimated additions indexed by configuration.
 * @param string $anchor    Denominator configuration in the original recipe.
 * @param float  $threshold Declared practical multiplier.
 * @return array Anchor addition, direct differences, and threshold contrasts.
 * @throws \RuntimeException When the anchor or threshold is unavailable.
 */
function review_contrasts( array $added, string $anchor, float $threshold ): array {

	if ( ! isset( $added[ $anchor ] ) || ! is_finite( $threshold ) || 0 >= $threshold )
		throw new \RuntimeException( 'A finite anchor and positive threshold are required.' );
	foreach ( $added as $value ) {
		if ( ( ! \is_int( $value ) && ! \is_float( $value ) ) || ! is_finite( (float) $value ) )
			throw new \RuntimeException( 'Contrasts require finite numeric additions.' );
	}
	$result = [ 'anchor_added' => $added[ $anchor ] ];
	foreach ( $added as $arm => $value ) {
		if ( $arm === $anchor ) continue;
		$result[ 'gain:' . $arm ]      = $value - $added[ $anchor ];
		$result[ 'threshold:' . $arm ] = $value - $threshold * $added[ $anchor ];
	}
	return $result;
}

/**
 * Average equally over cells, routes, and passes, without deleting negative cells.
 *
 * @param array $cells Paired additions grouped by pass, route, and block.
 * @return array Overall and per-pass added-time estimates.
 * @throws \RuntimeException When a pass or route has no observations.
 */
function review_points( array $cells ): array {

	if ( ! $cells )
		throw new \RuntimeException( 'No paired cells were supplied.' );
	$passes = $overall = [];
	foreach ( $cells as $pass => $routes ) {
		if ( ! $routes )
			throw new \RuntimeException( 'Empty pass.' );
		$totals = [];
		foreach ( $routes as $blocks ) {
			if ( ! $blocks )
				throw new \RuntimeException( 'Empty route.' );
			foreach ( $blocks as $vector ) {
				foreach ( $vector as $arm => $value )
					$totals[ $arm ] = ( $totals[ $arm ] ?? 0.0 ) + $value / \count( $blocks ) / \count( $routes );
			}
		}
		$passes[ $pass ]     = $totals;
		foreach ( $totals as $arm => $value )
			$overall[ $arm ] = ( $overall[ $arm ] ?? 0.0 ) + $value / \count( $cells );
	}
	return [
		'overall' => $overall,
		'passes'  => $passes,
	];
}

/**
 * Draw consecutive circular schedule blocks; all configurations stay paired.
 *
 * @param int $count  Number of observed blocks in one route/pass.
 * @param int $length Consecutive blocks per sampled segment.
 * @param int $state  Deterministic generator state, updated in place.
 * @return int[] Zero-based indices, including repeats, with original length.
 * @throws \RuntimeException When circular block dimensions are invalid.
 */
function review_indices( int $count, int $length, int &$state ): array {

	if ( 1 > $count || 1 > $length || $length > $count )
		throw new \RuntimeException( 'Invalid circular block dimensions.' );
	$indices = [];
	$filled  = 0;
	while ( $filled < $count ) {
		$start = next_random( $state, $count );
		for ( $i = 0; $i < $length && $filled < $count; ++$i, ++$filled )
			$indices[] = ( $start + $i ) % $count;
	}
	return $indices;
}

/**
 * Estimate conditional intervals while retaining pairing and optional dependence.
 *
 * Fixed-pass intervals describe this observed session. Three-pass hierarchical
 * resampling is only a sensitivity; it cannot calibrate future-host coverage.
 * All negative effects remain in the bootstrap distribution.
 *
 * @param array  $cells        Paired additions grouped by pass, route, and block.
 * @param string $anchor       Anchor configuration.
 * @param float  $threshold    Practical multiplier.
 * @param int    $draws        Number of deterministic bootstrap draws.
 * @param int    $length       Consecutive schedule blocks per segment.
 * @param bool   $shared       Share schedule indices across sequential routes.
 * @param bool   $hierarchical Resample entire passes before their blocks.
 * @param int    $seed         Fixed replay seed.
 * @param bool   $familywise   Adjust all contrast intervals as one family.
 * @param float  $confidence   Nominal confidence level from the recipe.
 * @return array Simultaneous intervals for the declared contrast family.
 * @throws \RuntimeException When parameters cannot describe a valid resample.
 */
function review_bootstrap( array $cells, string $anchor, float $threshold, int $draws, int $length, bool $shared, bool $hierarchical, int $seed, bool $familywise = true, float $confidence = 0.95 ): array {

	if ( ! $cells || 1 > $draws || 1 > $length || 0 >= $confidence || 1 <= $confidence || ! is_finite( $confidence ) )
		throw new \RuntimeException( 'Invalid bootstrap dimensions.' );
	$points  = review_points( $cells );
	$metrics = review_contrasts( $points['overall'], $anchor, $threshold );
	$drawn   = array_fill_keys( array_keys( $metrics ), [] );
	$passes  = array_keys( $cells );
	$state   = ( $seed % 2147483646 ) + 1;
	foreach ( $cells as $routes ) {
		$counts = array_map( '\\count', $routes );
		if ( $length > min( $counts ) || ( $shared && 1 !== \count( array_unique( $counts ) ) ) )
			throw new \RuntimeException( 'Invalid or mismatched schedule block counts.' );
	}
	for ( $draw = 0; $draw < $draws; ++$draw ) {
		$totals = array_fill_keys( array_keys( $points['overall'] ), 0.0 );
		foreach ( $passes as $fixed_pass ) {
			$pass    = $hierarchical ? $passes[ next_random( $state, \count( $passes ) ) ] : $fixed_pass;
			$indices = null;
			foreach ( $cells[ $pass ] as $blocks ) {
				if ( ! $shared || null === $indices )
					$indices = review_indices( \count( $blocks ), $length, $state );
				$weight      = 1 / \count( $passes ) / \count( $cells[ $pass ] ) / \count( $blocks );
				foreach ( $indices as $index ) {
					foreach ( $blocks[ $index ] as $arm => $value )
						$totals[ $arm ] += $value * $weight;
				}
			}
		}
		foreach ( review_contrasts( $totals, $anchor, $threshold ) as $metric => $value )
			$drawn[ $metric ][] = $value;
	}
	// Preserve the original review's exact 5% arithmetic for historical replay.
	$alpha                 = 0.95 === $confidence ? 0.05 : 1 - $confidence;
	$tail                  = $alpha / ( 2 * ( $familywise ? \count( $metrics ) : 1 ) );
	$result                = [];
	foreach ( $drawn as $metric => $values )
		$result[ $metric ] = [
			'point_ms' => $metrics[ $metric ],
			'low_ms'   => percentile( $values, $tail ),
			'high_ms'  => percentile( $values, 1 - $tail ),
		];
	return [
		'draws'            => $draws,
		'seed'             => $seed,
		'block_length'     => $length,
		'shared_indices'   => $shared,
		'hierarchical'     => $hierarchical,
		'nominal_family'   => $confidence,
		'familywise'       => $familywise,
		'family_size'      => \count( $metrics ),
		'tail_probability' => $tail,
		'intervals'        => $result,
	];
}

/**
 * Record influence without excluding any cell from the reported estimates.
 *
 * Each deletion keeps equal route/pass weighting. These are descriptive ranges,
 * not confidence intervals and not permission to select a favorable subset.
 *
 * @param array  $cells     Paired additions by pass, route, and block.
 * @param string $anchor    Anchor configuration.
 * @param float  $threshold Practical multiplier.
 * @return array Per-contrast leave-one-cell and leave-one-pass point ranges.
 * @throws \RuntimeException When a route has fewer than two blocks.
 */
function review_influence( array $cells, string $anchor, float $threshold ): array {

	$points = review_points( $cells );
	$ranges = [];
	foreach ( $cells as $routes ) {
		foreach ( $routes as $blocks ) {
			if ( 2 > \count( $blocks ) )
				throw new \RuntimeException( 'Influence analysis requires at least two blocks per route.' );
			$route_mean = review_points( [ [ $blocks ] ] )['overall'];
			foreach ( $blocks as $vector ) {
				$added                           = $points['overall'];
				foreach ( $vector as $arm => $value )
					$added[ $arm ]              -= ( $value - $route_mean[ $arm ] ) / ( \count( $blocks ) - 1 ) / \count( $routes ) / \count( $cells );
				foreach ( review_contrasts( $added, $anchor, $threshold ) as $metric => $value )
					$ranges['cell'][ $metric ][] = $value;
			}
		}
	}
	if ( 1 < \count( $cells ) ) {
		foreach ( array_keys( $cells ) as $pass ) {
			$other = $cells;
			unset( $other[ $pass ] );
			foreach ( review_contrasts( review_points( $other )['overall'], $anchor, $threshold ) as $metric => $value )
				$ranges['pass'][ $metric ][] = $value;
		}
	}
	foreach ( $ranges as &$scope ) {
		foreach ( $scope as &$values )
			$values = [
				'minimum_ms' => min( $values ),
				'maximum_ms' => max( $values ),
			];
	}
	return $ranges;
}
