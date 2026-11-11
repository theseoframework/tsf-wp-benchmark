<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

// Load only the pure helpers exercised by these offline regressions.
require_once __DIR__ . '/../src/Protocol.php';
require_once __DIR__ . '/../src/Report.php';

/**
 * Fail an offline regression without relying on optional PHP assertions.
 *
 * @param bool   $condition Expected truth value.
 * @param string $message   Failure explanation.
 * @return void
 * @throws RuntimeException When the required invariant is false.
 */
function analysis_expect( bool $condition, string $message ): void {
	if ( ! $condition )
		throw new RuntimeException( $message );
}

/**
 * Require a validation failure from the supplied operation.
 *
 * @param callable $callback Operation expected to reject its input.
 * @param string   $message  Failure explanation when it accepts the input.
 * @return void
 * @throws RuntimeException When the operation unexpectedly accepts its input.
 */
function analysis_throws( callable $callback, string $message ): void {

	try {
		$callback();
	} catch ( RuntimeException ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Define a small, deterministic HTML experiment for offline analysis tests.
 *
 * The identities are synthetic and never downloaded or executed as plugins.
 *
 * @return array Synthetic custom recipe with two controls and two plugin arms.
 */
function analysis_recipe(): array {

	$package = [
		'slug'    => 'fixture',
		'version' => '1.0',
		'url'     => 'https://example.org/fixture.zip',
		'sha256'  => str_repeat( 'a', 64 ),
		'main'    => 'fixture/fixture.php',
	];
	return [
		'schema'      => 1,
		'id'          => 'test-v1',
		'name'        => 'Synthetic analysis test',
		'reference'   => false,
		'wordpress'   => $package,
		'theme'       => $package,
		'arms'        => [
			[
				'id'       => 'core-a',
				'name'     => 'Control A',
				'packages' => [],
				'setup'    => 'none',
			],
			[
				'id'       => 'tsf',
				'name'     => 'TSF',
				'packages' => [ $package ],
				'setup'    => 'none',
			],
			[
				'id'       => 'other',
				'name'     => 'Other',
				'packages' => [ $package ],
				'setup'    => 'none',
			],
			[
				'id'       => 'core-b',
				'name'     => 'Control B',
				'packages' => [],
				'setup'    => 'none',
			],
		],
		'routes'      => [
			[
				'id'      => 'page',
				'path'    => '/?page_id=2',
				'context' => 'static',
				'status'  => 200,
				'kind'    => 'html',
			],
			[
				'id'      => 'post',
				'path'    => '/?p=3',
				'context' => 'static',
				'status'  => 200,
				'kind'    => 'html',
			],
			[
				'id'      => 'search',
				'path'    => '/?s=fixture',
				'context' => 'static',
				'status'  => 200,
				'kind'    => 'html',
			],
		],
		'measurement' => [
			'passes'    => 3,
			'warmups'   => 3,
			'samples'   => 6,
			'bootstrap' => 1000,
			'seed'      => 521,
		],
		'comparison'  => [
			'anchor'     => 'tsf',
			'threshold'  => 1.5,
			'confidence' => 0.95,
		],
	];
}

/**
 * Build a complete balanced observation matrix with known added-time ratios.
 *
 * @param array $recipe        Synthetic recipe defining the matrix.
 * @param float $other_addition Comparator addition in milliseconds.
 * @return array Synthetic observations; never represents a WordPress execution.
 */
function analysis_samples( array $recipe, float $other_addition = 3.0 ): array {

	$rows   = [];
	$orders = TsfBenchmark\balanced_orders( array_column(
		$recipe['arms'],
		'id',
	) );
	foreach ( range(
		1,
		$recipe['measurement']['passes'],
	) as $pass ) {
		foreach ( $recipe['routes'] as $route ) {
			foreach ( $orders as $block => $order ) {
				foreach ( [
					'warmup'  => 'warmups',
					'measure' => 'samples',
				] as $phase => $key ) {
					for ( $sequence = 1; $sequence <= $recipe['measurement'][ $key ]; ++$sequence ) {
						foreach ( $order as $position => $arm ) {
							$rows[] = [
								'pass'          => $pass,
								'route'         => $route['id'],
								'block'         => $block + 1,
								'sequence'      => $sequence,
								'position'      => $position + 1,
								'arm'           => $arm,
								'phase'         => $phase,
								'elapsed_ms'    => 20.0 + $block / 100 + ( 'tsf' === $arm ? 1.0 : ( 'other' === $arm ? $other_addition : 0.0 ) ),
								'valid'         => true,
								'output_sha256' => hash(
									'sha256',
									$route['id'] . ':' . ( str_starts_with( $arm, 'core-' ) ? 'core' : $arm ),
								),
							];
						}
					}
				}
			}
		}
	}
	return $rows;
}

return [
	'balanced orders cover positions and directed predecessors' => function (): void {
		for ( $n = 2; 11 >= $n; ++$n ) {
			$ids      = array_map(
				fn( int $i ): string => 'arm-' . $i,
				range( 0, $n - 1 ),
			);
			$orders   = TsfBenchmark\balanced_orders( $ids );
			$expected = $n % 2 ? 2 : 1;
			analysis_expect(
				count( $orders ) === $n * $expected,
				'Wrong balanced row count.',
			);
			$positions = $pairs = [];
			foreach ( $orders as $order ) {
				analysis_expect(
					count( array_unique( $order ) ) === $n,
					'Repeated arm in a row.',
				);
				foreach ( $order as $position => $id ) {
					$key               = "$position:$id";
					$positions[ $key ] = ( $positions[ $key ] ?? 0 ) + 1;
					if ( $position ) {
						$key           = $order[ $position - 1 ] . ':' . $id;
						$pairs[ $key ] = ( $pairs[ $key ] ?? 0 ) + 1;
					}
				}
			}
			analysis_expect(
				count( $pairs ) === $n * ( $n - 1 ),
				'Missing predecessor combinations.',
			);
			analysis_expect(
				array_values( array_unique( $positions ) ) === [ $expected ],
				'Position imbalance.',
			);
			analysis_expect(
				array_values( array_unique( $pairs ) ) === [ $expected ],
				'Predecessor imbalance.',
			);
		}
		analysis_throws(
			fn() => TsfBenchmark\balanced_orders( [ 'a', 'a' ] ),
			'Duplicate arms were accepted.',
		);
		analysis_throws(
			fn() => TsfBenchmark\balanced_orders( [ 'a', 2 ] ),
			'Non-string arms were accepted.',
		);
	},
	'recipe validation rejects unsafe paths and missing controls' => function (): void {
		$recipe = analysis_recipe();
		TsfBenchmark\validate_recipe( $recipe );
		foreach ( [ '//evil.example/path', '/../outside', '/%2e%2e/outside', '/path#fragment', '/path with space', '/path\\outside' ] as $path ) {
			$bad                      = $recipe;
			$bad['routes'][0]['path'] = $path;
			analysis_throws(
				fn() => TsfBenchmark\validate_recipe( $bad ),
				'Unsafe route was accepted.',
			);
		}
		$recipe['arms'][0]['id'] = 'absent';
		analysis_throws(
			fn() => TsfBenchmark\validate_recipe( $recipe ),
			'Missing control was accepted.',
		);
	},
	'cohorts cannot silently combine feeds and HTML' => function (): void {
		$recipe                      = analysis_recipe();
		$recipe['routes'][0]['kind'] = 'rss';
		analysis_throws(
			fn() => TsfBenchmark\validate_recipe( $recipe ),
			'Mixed cohorts were accepted.',
		);
	},
	'non-HTML cohorts fail before provisioning with an explicit unsupported message' => function (): void {
		foreach ( [ 'rss', 'sitemap', 'admin', 'other' ] as $kind ) {
			$recipe            = analysis_recipe();
			foreach ( $recipe['routes'] as &$route )
				$route['kind'] = $kind;
			unset( $route );
			try {
				TsfBenchmark\validate_recipe( $recipe );
				throw new LogicException(
					'A separate unsupported cohort was accepted.',
				);
			} catch ( RuntimeException $error ) {
				analysis_expect(
					str_contains(
						$error->getMessage(),
						'not yet implemented',
					),
					'Unsupported cohort failure is unclear.',
				);
			}
		}
	},
	'custom HTML route names require a supported query identifier' => function (): void {
		$recipe                    = analysis_recipe();
		$recipe['routes'][0]['id'] = 'content-heavy';
		analysis_throws(
			fn() => TsfBenchmark\validate_recipe( $recipe ),
			'Unknown implicit query was accepted.',
		);
		$recipe['routes'][0]['query'] = 'page';
		TsfBenchmark\validate_recipe( $recipe );
		foreach ( [ 'rss', 'admin', 'made-up', 404, null ] as $query ) {
			$recipe['routes'][0]['query'] = $query;
			analysis_throws(
				fn() => TsfBenchmark\validate_recipe( $recipe ),
				'Unsupported explicit query was accepted.',
			);
		}
	},
	'reference cannot omit declared frontend routes' => function (): void {
		$recipe              = analysis_recipe();
		$recipe['reference'] = true;
		analysis_throws(
			fn() => TsfBenchmark\validate_recipe( $recipe ),
			'Incomplete reference was accepted.',
		);
	},
	'published reference recipe preserves the numeric-looking 404 route' => function (): void {
		$recipe = json_decode(
			file_get_contents(
				__DIR__ . '/../recipes/tsf-5.2.1.json',
			),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		TsfBenchmark\validate_recipe( $recipe );
		analysis_expect(
			in_array(
				'404',
				array_column( $recipe['routes'], 'id' ),
				true,
			),
			'The reference 404 identifier changed type.',
		);
		$recipe['reference']   = false;
		$recipe['routes']      = array_values( array_filter(
			$recipe['routes'],
			fn( array $route ): bool => '404' === $route['id'],
		) );
		$recipe['measurement'] = [
			'passes'    => 1,
			'warmups'   => 0,
			'samples'   => 1,
			'bootstrap' => 2,
			'seed'      => 1,
		];
		$result                = TsfBenchmark\analyze(
			$recipe,
			analysis_samples( $recipe ),
		);
		analysis_expect(
			isset(
				$result['passes'][0]['routes']['404']['arms']['tsf'],
			),
			'The 404 route was lost during analysis.',
		);
	},
	'exact evidence replay and known independent added-time result' => function (): void {
		$recipe = analysis_recipe();
		$rows   = analysis_samples( $recipe );
		$a      = TsfBenchmark\analyze( $recipe, $rows );
		$b      = TsfBenchmark\analyze( $recipe, $rows );
		analysis_expect(
			$a === $b,
			'Identical evidence changed the analysis.',
		);
		analysis_expect(
			'supported' === $a['verdict'],
			'Stable 3x effect was not supported.',
		);
		analysis_expect(
			1e-9 > abs(
				$a['comparisons']['other']['minimum'] - 3.0,
			),
			'Incorrect baseline-subtracted effect.',
		);
		analysis_expect(
			1.1 > $a['passes'][0]['comparisons']['other']['full_response_ratio'],
			'Added time was confused with full response.',
		);
	},
	'competitor can outperform TSF without failing the analysis' => function (): void {
		$recipe = analysis_recipe();
		$result = TsfBenchmark\analyze(
			$recipe,
			analysis_samples( $recipe, 0.4 ),
		);
		analysis_expect(
			'not_reproduced' === $result['verdict'],
			'A faster comparator was not reported honestly.',
		);
		analysis_expect(
			1 > $result['comparisons']['other']['maximum'],
			'Comparator ordering changed.',
		);
	},
	'incomplete duplicate nonfinite and wrong-order records cannot qualify' => function (): void {
		$recipe               = analysis_recipe();
		$rows                 = analysis_samples( $recipe );
		$cases                = [];
		$cases[]              = array_slice( $rows, 1 );
		$cases[]              = array_merge( $rows, [ $rows[0] ] );
		$bad                  = $rows;
		$bad[0]['elapsed_ms'] = INF;
		$cases[]              = $bad;
		$bad[0]['elapsed_ms'] = NAN;
		$cases[]              = $bad;
		$bad[0]['elapsed_ms'] = PHP_FLOAT_MAX;
		$cases[]              = $bad;
		$bad                  = $rows;
		$bad[0]['position']   = 4;
		$cases[]              = $bad;
		foreach ( $cases as $case ) {
			$result = TsfBenchmark\analyze( $recipe, $case );
			analysis_expect(
				'supported' !== $result['verdict'],
				'Broken observations qualified.',
			);
			analysis_expect(
				'inconclusive' === $result['passes'][0]['verdict'],
				'Broken pass gained a comparative verdict.',
			);
		}
	},
	'control noise and negative denominators remain unresolved' => function (): void {
		// Preserve the historical version 1 timing-gate contract.
		$recipe = analysis_recipe();
		foreach ( [ 1.1, 2.5 ] as $control_shift ) {
			$rows                       = analysis_samples( $recipe );
			foreach ( $rows as &$row )
				if ( 'core-b' === $row['arm'] )
					$row['elapsed_ms'] += $control_shift;
			unset( $row );
			$result = TsfBenchmark\analyze_legacy( $recipe, $rows );
			analysis_expect(
				'inconclusive' === $result['verdict'],
				'Noisy baseline was treated as a claim.',
			);
			analysis_expect(
				null === $result['passes'][0]['comparisons']['other']['confidence_low'],
				'Unresolved denominator received an interval.',
			);
		}
	},
	'unstable output retains all routes and blocks the cohort claim' => function (): void {
		$recipe                   = analysis_recipe();
		$rows                     = analysis_samples( $recipe );
		$rows[1]['output_sha256'] = str_repeat( 'b', 64 );
		$result                   = TsfBenchmark\analyze( $recipe, $rows );
		analysis_expect(
			3 === count( $result['passes'][0]['routes'] ),
			'Unqualified route was dropped.',
		);
		analysis_expect(
			'inconclusive' === $result['passes'][0]['verdict'],
			'Changing output qualified.',
		);
	},
	'small runs are visibly smoke-only'              => function (): void {
		$recipe                = analysis_recipe();
		$recipe['measurement'] = [
			'passes'    => 1,
			'samples'   => 1,
			'warmups'   => 0,
			'bootstrap' => 10,
			'seed'      => 1,
		];
		$result                = TsfBenchmark\analyze(
			$recipe,
			analysis_samples( $recipe ),
		);
		analysis_expect(
			'smoke_only' === $result['verdict'] && 'smoke' === $result['scope'],
			'Small run emitted a broad verdict.',
		);
	},
	'fresh passes with opposing effects remain inconsistent' => function (): void {
		$recipe                     = analysis_recipe();
		$rows                       = analysis_samples( $recipe );
		foreach ( $rows as &$row )
			if ( 2 === $row['pass'] && 'other' === $row['arm'] )
				$row['elapsed_ms'] -= 2.6;
		unset( $row );
		$result = TsfBenchmark\analyze( $recipe, $rows );
		analysis_expect(
			'inconsistent' === $result['verdict'],
			'Conflicting passes were pooled into a favorable result.',
		);
		analysis_expect(
			3 === count(
				$result['comparisons']['other']['pass_ratios'],
			),
			'Pass evidence was omitted.',
		);
	},
	'environment blockers prevent a reference-style verdict' => function (): void {
		$recipe = analysis_recipe();
		$result = TsfBenchmark\analyze(
			$recipe,
			analysis_samples( $recipe ),
			[ 'blockers' => [ 'Profiling is active.' ] ],
		);
		analysis_expect(
			'inconclusive' === $result['verdict'],
			'Environment blocker was ignored.',
		);
	},
	'report escapes all user-controlled content and loads no external assets' => function (): void {
		$recipe                             = analysis_recipe();
		$recipe['name']                     = '<script>alert("bad")</script>';
		$recipe['arms'][2]['name']          = '<img src=x onerror=bad()>';
		$recipe['measurement']['bootstrap'] = 10;
		$analysis                           = TsfBenchmark\analyze(
			$recipe,
			analysis_samples( $recipe ),
			[ 'note' => '</pre><script>bad()</script>' ],
		);
		$html                               = TsfBenchmark\render_report( $analysis );
		analysis_expect(
			! str_contains( $html, '<script>' ) && ! str_contains( $html, '<img' ),
			'Report permits injected markup.',
		);
		analysis_expect(
			str_contains( $html, '&lt;script&gt;' ) && str_contains( $html, 'smoke only' ),
			'Escaped content or limitation is missing.',
		);
		analysis_expect(
			! preg_match(
				'/(?:src|href)=["\']https?:\/\//i',
				$html,
			),
			'Report requires remote resources.',
		);
		analysis_expect(
			str_contains(
				$html,
				'95.000% simultaneous interval',
			)
				&& str_contains(
					$html,
					'A ratio above 1 means the comparator added more response time',
				)
				&& str_contains(
					$html,
					'not a ratio of whole-page loading speeds',
				),
			'Confidence level or ratio interpretation is missing.',
		);
	},
	'environment conditions and unavailable sensors are visible without opening raw data' => function (): void {
		$html    = TsfBenchmark\render_environment( [
			'conditions' => [
				[
					'severity' => 'reference',
					'message'  => 'Shared-memory OPcache unavailable.',
				],
			],
			'telemetry'  => [
				'pass-1' => [
					'coverage' => [
						'temperature_c' => [
							'available'   => 0,
							'unavailable' => 5,
							'reasons'     => [ 'sensor_unavailable' ],
						],
					],
				],
			],
		] );
		$visible = explode( '<details>', $html )[0];
		analysis_expect(
			str_contains(
				$visible,
				'Shared-memory OPcache unavailable.',
			),
			'Important condition was hidden.',
		);
		analysis_expect(
			str_contains( $visible, 'sensor_unavailable' ) && str_contains( $visible, 'temperature c' ),
			'Missing sensor coverage was hidden.',
		);
	},
	'monitoring shows measured ranges and never merges incompatible units' => function (): void {
		$metric = [
			'available'   => 4,
			'unavailable' => 0,
			'sources'     => [ 'Native CPU counter' ],
			'units'       => [ 'percent' ],
			'mixed_units' => false,
			'numeric'     => [
				'min'    => 3.0,
				'median' => 4.5,
				'max'    => 8.0,
			],
		];
		$html   = TsfBenchmark\render_environment( [
			'telemetry' => [
				'coverage'             => [ 'cpu_busy' => $metric ],
				'measurement_coverage' => [ 'cpu_busy' => $metric ],
			],
		] );
		analysis_expect(
			str_contains( $html, 'median 4.500' ) && str_contains( $html, 'measurement window' ),
			'Measured CPU summary is missing.',
		);
		$metric['mixed_units'] = true;
		$metric['units'][]     = 'MHz';
		$html                  = explode(
			'<details>',
			TsfBenchmark\render_environment( [
				'coverage' => [ 'mixed' => $metric ],
			] ),
		)[0];
		analysis_expect(
			! str_contains( $html, 'median 4.500' ) && str_contains( $html, 'Mixed units' ),
			'Incompatible measurements were summarized as one value.',
		);
	},
];
