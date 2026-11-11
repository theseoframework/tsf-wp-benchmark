<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

// Load only the pure helpers exercised by these offline regressions.
require_once __DIR__ . '/../scripts/statistical-review.php';

/**
 * Compare a calculated effect with its independently specified expected value.
 *
 * @param float  $actual   Calculated effect in milliseconds.
 * @param float  $expected Required effect in milliseconds.
 * @param string $message  Failure explanation.
 * @return void
 */
function review_expect_close( float $actual, float $expected, string $message ): void {

	analysis_expect( 1e-9 > abs( $actual - $expected ), $message );
}

/**
 * Supply unequal route sizes and effects for weighting and deletion checks.
 *
 * @return array Synthetic additions, including negative observed effects.
 */
function review_weighted_cells(): array {

	return [
		1 => [
			'page' => [
				[
					'tsf'   => 2.0,
					'other' => 8.0,
				],
				[
					'tsf'   => 4.0,
					'other' => 1.0,
				],
				[
					'tsf'   => 10.0,
					'other' => 20.0,
				],
			],
			'post' => [
				[
					'tsf'   => -9.0,
					'other' => 2.0,
				],
				[
					'tsf'   => 0.0,
					'other' => 3.0,
				],
				[
					'tsf'   => 3.0,
					'other' => -1.0,
				],
				[
					'tsf'   => 14.0,
					'other' => 6.0,
				],
			],
		],
		2 => [
			'page' => [
				[
					'tsf'   => 40.0,
					'other' => 21.0,
				],
				[
					'tsf'   => 60.0,
					'other' => 31.0,
				],
			],
			'post' => [
				[
					'tsf'   => 44.0,
					'other' => 17.0,
				],
				[
					'tsf'   => 50.0,
					'other' => 10.0,
				],
				[
					'tsf'   => 56.0,
					'other' => 24.0,
				],
			],
		],
	];
}

return [
	'review direct differences cancel a shared control shift' => function (): void {
		$recipe                     = analysis_recipe();
		$rows                       = analysis_samples( $recipe );
		$before                     = TsfBenchmark\review_cells( $recipe, $rows, [] );
		foreach ( $rows as &$row )
			if ( str_starts_with( $row['arm'], 'core-' ) )
				$row['elapsed_ms'] += 10.0;
		unset( $row );
		$after = TsfBenchmark\review_cells( $recipe, $rows, [] );
		$a     = TsfBenchmark\review_contrasts(
			TsfBenchmark\review_points( $before['cells'] )['overall'],
			'tsf',
			1.5,
		);
		$b     = TsfBenchmark\review_contrasts(
			TsfBenchmark\review_points( $after['cells'] )['overall'],
			'tsf',
			1.5,
		);
		review_expect_close( $a['gain:other'], 2.0, 'The original paired difference is wrong.' );
		review_expect_close( $b['gain:other'], 2.0, 'Controls failed to cancel from the direct difference.' );
		review_expect_close( $b['anchor_added'], -9.0, 'A negative control-adjusted anchor was changed.' );
		review_expect_close( $b['threshold:other'], 6.5, 'The threshold contrast lost its control coefficient.' );
	},
	'review retains a negative cell without a single-block veto' => function (): void {
		$recipe                     = analysis_recipe();
		$rows                       = analysis_samples( $recipe );
		foreach ( $rows as &$row )
			if ( 1 === $row['pass'] && 'page' === $row['route'] && 1 === $row['block'] && 'tsf' === $row['arm'] && 'measure' === $row['phase'] )
				$row['elapsed_ms'] -= 5.0;
		unset( $row );
		$input  = TsfBenchmark\review_cells( $recipe, $rows, [] );
		$cells  = $input['cells'];
		$points = TsfBenchmark\review_points( $cells );
		analysis_expect( 'inconsistent' === $input['original_verdict'], 'The original verdict was silently replaced.' );
		analysis_expect( 3 === count( $cells ) && 3 === count( $cells[1] ) && 4 === count( $cells[1]['page'] ), 'Observed cells were removed.' );
		review_expect_close( $cells[1]['page'][0]['tsf'], -4.0, 'The negative cell was clipped or dropped.' );
		review_expect_close( $points['overall']['tsf'], 31 / 36, 'The negative cell did not retain its declared weight.' );
		$result = TsfBenchmark\review_bootstrap( $cells, 'tsf', 1.5, 20, 1, false, false, 521 );
		analysis_expect( 3 === $result['family_size'], 'A noisy block prevented the contrast intervals.' );
	},
	'review rejects invalid coordinates responses output and environment' => function (): void {
		$recipe                       = analysis_recipe();
		$rows                         = analysis_samples( $recipe );
		$cases                        = [ array_slice( $rows, 1 ), array_merge( $rows, [ $rows[0] ] ) ];
		$bad                          = $rows;
		$bad[0]['position']           = 4;
		$cases[]                      = $bad;
		$bad                          = $rows;
		$bad[0]['elapsed_ms']         = NAN;
		$cases[]                      = $bad;
		$bad                          = $rows;
		$bad[0]['valid']              = false;
		$cases[]                      = $bad;
		$bad                          = $rows;
		$bad[1]['output_sha256']      = str_repeat( 'b', 64 );
		$cases[]                      = $bad;
		$bad                          = $rows;
		foreach ( $bad as &$row )
			if ( 'core-b' === $row['arm'] )
				$row['output_sha256'] = str_repeat( 'c', 64 );
		unset( $row );
		$cases[] = $bad;
		foreach ( $cases as $case )
			analysis_throws(
				fn() => TsfBenchmark\review_cells( $recipe, $case, [] ),
				'Exploratory analysis accepted invalid experiment evidence.',
			);
		analysis_throws(
			fn() => TsfBenchmark\review_cells( $recipe, $rows, [ 'blockers' => [ 'Profiling is active.' ] ] ),
			'Exploratory analysis ignored an environment blocker.',
		);
	},
	'review circular segments retain consecutive indices and wrap' => function (): void {
		foreach ( [ 1, 3, 6 ] as $length ) {
			$state   = 521;
			$indices = TsfBenchmark\review_indices( 18, $length, $state );
			analysis_expect( 18 === count( $indices ), 'A circular draw changed the number of blocks.' );
			foreach ( $indices as $offset => $index ) {
				analysis_expect( 0 <= $index && 18 > $index, 'A circular index escaped the observed blocks.' );
				if ( $offset % $length )
					analysis_expect( ( $indices[ $offset - 1 ] + 1 ) % 18 === $index, 'A sampled segment lost consecutive blocks.' );
			}
			$replay_state = 521;
			analysis_expect( TsfBenchmark\review_indices( 18, $length, $replay_state ) === $indices && $state === $replay_state, 'Circular draws cannot be replayed.' );
		}
		$state   = 1;
		$indices = TsfBenchmark\review_indices( 8, 3, $state );
		analysis_expect( [ 7, 0, 1 ] === array_slice( $indices, 0, 3 ), 'Circular resampling did not wrap at the observed boundary.' );
		analysis_expect( 8 === count( $indices ), 'The last partial segment was not truncated.' );
	},
	'review bootstrap replays complete paired vectors including negative effects' => function (): void {
		$cells = [];
		foreach ( [ 1, 2 ] as $pass ) {
			foreach ( [ 'page', 'post' ] as $route ) {
				foreach ( [ -30.0, -6.0, -3.0, 3.0, 6.0, 12.0 ] as $value ) {
					$anchor                     = $value - $pass;
					$cells[ $pass ][ $route ][] = [
						'tsf'   => $anchor,
						'other' => $anchor + 7.0,
						'third' => 1.5 * $anchor - 2.0,
					];
				}
			}
		}
		foreach ( [ false, true ] as $shared ) {
			foreach ( [ false, true ] as $hierarchical ) {
				$result = TsfBenchmark\review_bootstrap( $cells, 'tsf', 1.5, 100, 3, $shared, $hierarchical, 521 );
				$replay = TsfBenchmark\review_bootstrap( $cells, 'tsf', 1.5, 100, 3, $shared, $hierarchical, 521 );
				analysis_expect( $result === $replay, 'Identical paired bootstrap evidence changed its result.' );
				analysis_expect( 5 === $result['family_size'], 'The contrast family omitted a configuration.' );
				review_expect_close( $result['tail_probability'], 0.005, 'Simultaneous tails use the wrong family size.' );
				foreach ( [ 'point_ms', 'low_ms', 'high_ms' ] as $key ) {
					review_expect_close( $result['intervals']['gain:other'][ $key ], 7.0, 'Bootstrap draws separated a paired arm vector.' );
					review_expect_close( $result['intervals']['threshold:third'][ $key ], -2.0, 'Negative threshold effects were altered or arms resampled separately.' );
				}
				analysis_expect( 0 > $result['intervals']['anchor_added']['point_ms'], 'A negative anchor was clipped.' );
			}
		}
		$other_seed = TsfBenchmark\review_bootstrap( $cells, 'tsf', 1.5, 100, 3, false, false, 522 );
		$first_seed = TsfBenchmark\review_bootstrap( $cells, 'tsf', 1.5, 100, 3, false, false, 521 );
		analysis_expect( $other_seed['intervals']['anchor_added'] !== $first_seed['intervals']['anchor_added'], 'The declared seed does not affect bootstrap draws.' );
	},
	'review points weight every route and pass equally' => function (): void {
		$result = TsfBenchmark\review_points( review_weighted_cells() );
		review_expect_close( $result['passes'][1]['tsf'], 11 / 3, 'Unequal route sizes changed their equal weights.' );
		review_expect_close( $result['passes'][2]['tsf'], 50.0, 'The second pass point is wrong.' );
		review_expect_close( $result['overall']['tsf'], 161 / 6, 'Unequal pass sample counts changed their equal weights.' );
		review_expect_close( $result['overall']['other'], 331 / 24, 'Comparator values received different weights from the anchor.' );
	},
	'review conditional bootstrap retains equal route and pass weights' => function (): void {
		$cells  = [
			1 => [
				'page' => array_fill(
					0,
					2,
					[
						'tsf'   => 0.0,
						'other' => 10.0,
					],
				),
				'post' => array_fill(
					0,
					4,
					[
						'tsf'   => 20.0,
						'other' => 30.0,
					],
				),
			],
			2 => [
				'page' => array_fill(
					0,
					3,
					[
						'tsf'   => 40.0,
						'other' => 60.0,
					],
				),
				'post' => array_fill(
					0,
					2,
					[
						'tsf'   => 60.0,
						'other' => 80.0,
					],
				),
			],
		];
		$result = TsfBenchmark\review_bootstrap( $cells, 'tsf', 1.5, 20, 1, false, false, 521 );
		foreach ( [ 'point_ms', 'low_ms', 'high_ms' ] as $key ) {
			review_expect_close( $result['intervals']['anchor_added'][ $key ], 30.0, 'Bootstrap draws changed equal route/pass weights.' );
			review_expect_close( $result['intervals']['gain:other'][ $key ], 15.0, 'Bootstrap comparator weights differ from anchor weights.' );
			review_expect_close( $result['intervals']['threshold:other'][ $key ], 0.0, 'Bootstrap weighting changed the threshold contrast.' );
		}
	},
	'review influence agrees with explicit deletion and reweighting' => function (): void {
		$cells    = review_weighted_cells();
		$expected = [];
		foreach ( $cells as $pass => $routes ) {
			foreach ( $routes as $route => $blocks ) {
				foreach ( array_keys( $blocks ) as $block ) {
					$deleted = $cells;
					unset( $deleted[ $pass ][ $route ][ $block ] );
					$deleted[ $pass ][ $route ]        = array_values( $deleted[ $pass ][ $route ] );
					foreach ( TsfBenchmark\review_contrasts( TsfBenchmark\review_points( $deleted )['overall'], 'tsf', 1.5 ) as $metric => $value )
						$expected['cell'][ $metric ][] = $value;
				}
			}
			$deleted = $cells;
			unset( $deleted[ $pass ] );
			foreach ( TsfBenchmark\review_contrasts( TsfBenchmark\review_points( $deleted )['overall'], 'tsf', 1.5 ) as $metric => $value )
				$expected['pass'][ $metric ][] = $value;
		}
		$actual = TsfBenchmark\review_influence( $cells, 'tsf', 1.5 );
		foreach ( $expected as $scope => $metrics ) {
			foreach ( $metrics as $metric => $values ) {
				review_expect_close( $actual[ $scope ][ $metric ]['minimum_ms'], min( $values ), 'Deletion minimum lost equal route/pass weighting.' );
				review_expect_close( $actual[ $scope ][ $metric ]['maximum_ms'], max( $values ), 'Deletion maximum lost equal route/pass weighting.' );
			}
		}
		analysis_expect( review_weighted_cells() === $cells, 'Influence analysis mutated the retained cells.' );
	},
	'review rejects undefined helper dimensions'        => function (): void {
		$state = 521;
		analysis_throws( fn() => TsfBenchmark\review_contrasts( [], 'tsf', 1.5 ), 'Empty contrasts were accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_contrasts( [ 'other' => 1.0 ], 'tsf', 1.5 ), 'A missing anchor was accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_contrasts( [ 'tsf' => \NAN ], 'tsf', 1.5 ), 'A nonfinite anchor was accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_contrasts( [
			'tsf'   => 1.0,
			'other' => \INF,
		],
			'tsf',
			1.5 ),
			'A nonfinite comparator was accepted.' );
		$added = [
			'tsf'   => 1.0,
			'other' => 2.0,
		];
		foreach ( [ 0.0, -1.0, INF, NAN ] as $threshold )
			analysis_throws( fn() => TsfBenchmark\review_contrasts( $added, 'tsf', $threshold ), 'An undefined practical threshold was accepted.' );
		foreach ( [ [], [ [] ], [ [ [] ] ] ] as $empty )
			analysis_throws( fn() => TsfBenchmark\review_points( $empty ), 'An empty cell dimension was accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_indices( 0, 1, $state ), 'Empty circular blocks were accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_indices( 4, 0, $state ), 'A zero-length segment was accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_indices( 4, 5, $state ), 'A segment longer than the observations was accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_bootstrap( [], 'tsf', 1.5, 10, 1, false, false, 521 ), 'Empty bootstrap cells were accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_bootstrap( review_weighted_cells(), 'tsf', 1.5, 0, 1, false, false, 521 ), 'Zero bootstrap draws were accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_bootstrap( review_weighted_cells(), 'tsf', 1.5, 10, 0, false, false, 521 ), 'Zero bootstrap segment length was accepted.' );
		analysis_throws( fn() => TsfBenchmark\review_bootstrap( review_weighted_cells(), 'tsf', 1.5, 10, 1, true, false, 521 ), 'Shared indices accepted unequal route block counts.' );
		$single = [ [ array_slice( review_weighted_cells()[1]['page'], 0, 1 ) ] ];
		analysis_throws( fn() => TsfBenchmark\review_influence( $single, 'tsf', 1.5 ), 'An undefined single-block deletion was accepted.' );
	},
];
