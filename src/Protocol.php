<?php
/**
 * Benchmark recipe validation, balanced schedules, and statistical analysis.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

use RuntimeException;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Balance treatment position and immediate predecessor across schedule rows.
 *
 * Even arm counts need one rotation per arm. Odd counts additionally need each
 * reversed row to balance directed carryover pairs.
 *
 * @param string[] $arm_ids Unique arm identifiers, in the recipe's declared order.
 * @return array Ordered lists of arm identifiers.
 * @throws RuntimeException When the arm identifiers cannot form a schedule.
 */
function balanced_orders( array $arm_ids ): array {

	$count = \count( $arm_ids );

	if (
		   2 > $count
		|| ! array_is_list( $arm_ids )
		|| \count( array_filter(
			$arm_ids,
			'is_string',
		) ) !== $count
		|| \count( array_unique(
			$arm_ids,
			\SORT_STRING,
		) ) !== $count
	) {
		throw new RuntimeException(
			'A balanced schedule needs at least two distinct arms.',
		);
	}
	$base = [ 0 ];
	for ( $low = 1, $high = $count - 1; $low <= $high; ++$low, --$high ) {
		$base[] = $low;

		if ( $low !== $high )
			$base[] = $high;
	}
	$orders = [];
	for ( $offset = 0; $offset < $count; ++$offset ) {
		$orders[] = array_map(
			fn( int $index ): string => $arm_ids[ ( $index + $offset ) % $count ],
			$base,
		);
	}

	if ( $count % 2 )
		$orders = array_merge(
			$orders,
			array_map( 'array_reverse', $orders ),
		);

	return $orders;
}

/**
 * Validate the inputs supported by the front-end HTML experiment.
 *
 * The runner separately checks fixture inputs and whether a reference recipe
 * matches the published recipe. Custom experiments use the same quality gates.
 *
 * @param array $recipe Decoded recipe with packages, routes, and measurements.
 * @return void
 * @throws RuntimeException When a required field is missing or unsupported.
 */
function validate_recipe( array $recipe ): void {

	if ( 1 !== ( $recipe['schema'] ?? null ) )
		throw new RuntimeException( 'Unsupported recipe schema.' );
	foreach ( [ 'id', 'name' ] as $key ) {
		if ( ! \is_string( $recipe[ $key ] ?? null ) || '' === trim( $recipe[ $key ] ) )
			throw new RuntimeException( "Recipe $key is required." );
	}

	if ( ! preg_match(
		'/\A[a-z0-9][a-z0-9._-]{0,79}\z/D',
		$recipe['id'],
	) ) {
		throw new RuntimeException( 'Unsafe recipe identifier.' );
	}

	if ( ! \is_bool( $recipe['reference'] ?? null ) )
		throw new RuntimeException(
			'Recipe reference must be a Boolean.',
		);

	if (
		   ! \is_array( $recipe['arms'] ?? null )
		|| ! array_is_list( $recipe['arms'] )
		|| 4 > \count( $recipe['arms'] )
	) {
		throw new RuntimeException(
			'A recipe needs two controls, an anchor, and a comparator.',
		);
	}
	$ids = [];
	foreach ( $recipe['arms'] as $arm ) {
		$id = $arm['id'] ?? '';

		if (
			   ! \is_string( $id )
			|| ! preg_match(
				'/\A[a-z0-9][a-z0-9_-]{0,39}\z/D',
				$id,
			)
			|| isset( $ids[ $id ] )
		) {
			throw new RuntimeException(
				'Invalid or duplicate arm identifier.',
			);
		}
		$ids[ $id ] = true;

		if ( ! \is_string( $arm['name'] ?? null ) || '' === $arm['name'] )
			throw new RuntimeException( "Missing arm name: $id." );

		if (
			   ! \is_array( $arm['packages'] ?? null )
			|| ! array_is_list( $arm['packages'] )
		) {
			throw new RuntimeException( "Invalid packages: $id." );
		}

		if (
			   str_starts_with( $id, 'core-' )
			&& ! \in_array(
				$id,
				[ 'core-a', 'core-b' ],
				true,
			)
		) {
			throw new RuntimeException(
				'Only core-a and core-b are control identifiers.',
			);
		}

		if ( \in_array(
			$id,
			[ 'core-a', 'core-b' ],
			true,
		) !== ( [] === $arm['packages'] ) ) {
			throw new RuntimeException(
				'Only the two controls may have no plugin packages.',
			);
		}
		$slugs = [];
		foreach ( $arm['packages'] as $package ) {
			validate_package( $package );

			if ( isset( $slugs[ $package['slug'] ] ) )
				throw new RuntimeException(
					"Duplicate plugin package in $id.",
				);
			$slugs[ $package['slug'] ] = true;

			if (
				   ! \is_string( $package['main'] ?? null )
				|| ! preg_match(
					'/\A[a-z0-9_-]+\/[a-zA-Z0-9_.-]+\.php\z/D',
					$package['main'],
				)
				|| ! str_starts_with(
					$package['main'],
					$package['slug'] . '/',
				)
			) {
				throw new RuntimeException(
					'Plugin main file must be a relative file inside its slug.',
				);
			}
		}
	}

	if ( ! isset( $ids['core-a'], $ids['core-b'] ) )
		throw new RuntimeException(
			'Both core-a and core-b are required.',
		);
	$anchor = $recipe['comparison']['anchor'] ?? null;

	if (
		   ! \is_string( $anchor )
		|| ! isset( $ids[ $anchor ] )
		|| \in_array(
			$anchor,
			[ 'core-a', 'core-b' ],
			true,
		)
	) {
		throw new RuntimeException( 'Invalid comparison anchor.' );
	}
	foreach ( [ 'threshold', 'confidence' ] as $key ) {
		$value = $recipe['comparison'][ $key ] ?? null;

		if (
			   ( ! \is_int( $value ) && ! \is_float( $value ) )
			|| ! is_finite( (float) $value )
		) {
			throw new RuntimeException( "Invalid comparison $key." );
		}
	}

	if (
		   0 >= $recipe['comparison']['threshold']
		|| 0 >= $recipe['comparison']['confidence']
		|| 1 <= $recipe['comparison']['confidence']
	) {
		throw new RuntimeException(
			'Threshold must be positive and confidence must be between zero and one.',
		);
	}
	foreach ( [ 'passes', 'warmups', 'samples', 'seed', 'bootstrap' ] as $key ) {
		$value = $recipe['measurement'][ $key ] ?? null;

		if ( ! \is_int( $value ) || $value < ( 'warmups' === $key ? 0 : 1 ) )
			throw new RuntimeException( "Invalid measurement $key." );
	}

	if (
		   100 < $recipe['measurement']['passes']
		|| 100000 < $recipe['measurement']['samples']
		|| 100000 < $recipe['measurement']['warmups']
		|| 100000 < $recipe['measurement']['bootstrap']
		|| 2147483647 <= $recipe['measurement']['seed']
	) {
		throw new RuntimeException(
			'Measurement settings exceed supported bounds.',
		);
	}

	if (
		   ! \is_array( $recipe['routes'] ?? null )
		|| ! array_is_list( $recipe['routes'] )
		|| ! $recipe['routes']
	) {
		throw new RuntimeException(
			'At least one route is required.',
		);
	}
	$routes = [];
	foreach ( $recipe['routes'] as $route ) {
		$id = $route['id'] ?? '';

		if (
			   ! \is_string( $id )
			|| ! preg_match(
				'/\A[a-z0-9][a-z0-9_-]{0,39}\z/D',
				$id,
			)
			|| isset( $routes[ $id ] )
		) {
			throw new RuntimeException(
				'Invalid or duplicate route identifier.',
			);
		}
		$routes[ $id ] = true;
		$path          = $route['path'] ?? null;

		if (
			   ! \is_string( $path )
			|| ! str_starts_with( $path, '/' )
			|| str_starts_with( $path, '//' )
			|| preg_match( '/[\x00-\x20\\\\#]/', $path )
			|| preg_match(
				'/(?:^|\/)\.\.(?:\/|$)/',
				rawurldecode( explode( '?', $path, 2 )[0] ),
			)
		) {
			throw new RuntimeException( "Unsafe route path: $id." );
		}

		if (
			! \in_array(
				$route['context'] ?? null,
				[ 'blog', 'static' ],
				true,
			)
			|| ! \in_array(
				$route['status'] ?? null,
				[ 200, 404 ],
				true,
			)
		) {
			throw new RuntimeException(
				"Invalid route context or status: $id.",
			);
		}

		if ( 'html' !== ( $route['kind'] ?? null ) )
			throw new RuntimeException(
				'Only HTML routes are supported in v0.1. Separate RSS, sitemap, and admin cohort runners are not yet implemented.',
			);
		$query = \array_key_exists( 'query', $route ) ? $route['query'] : $id;

		if ( ! \in_array(
			$query,
			[ 'home-blog', 'home-static', 'blog-page', 'post', 'page', 'category', 'tag', 'search', '404', 'other' ],
			true,
		) ) {
			throw new RuntimeException(
				"Invalid WordPress query for $id. Set query to a supported guard identifier when using a custom route name.",
			);
		}
	}
	validate_package(
		$recipe['wordpress'] ?? [],
		false,
	);
	validate_package( $recipe['theme'] ?? [] );

	if ( $recipe['reference'] ) {
		$expected = [ 'home-blog', 'home-static', 'blog-page', 'post', 'page', 'category', 'tag', 'search', '404' ];
		sort( $expected );
		// PHP converts numeric string keys such as the 404 route to integers.
		$actual = array_map(
			'strval',
			array_keys( $routes ),
		);
		sort( $actual );

		if ( $actual !== $expected )
			throw new RuntimeException(
				'The reference recipe requires all nine declared HTML routes.',
			);
	}
}

/**
 * Require a pinned archive and an explicit public package identity.
 *
 * WordPress archives have no plugin slug. Plugin and theme archives do.
 * Public evidence additionally refuses credential-bearing URL query parameters.
 *
 * @param array $package   Package identity and archive URL.
 * @param bool  $needs_slug Whether the package requires a slug.
 * @return void
 * @throws RuntimeException When the package identity is invalid.
 */
function validate_package( array $package, bool $needs_slug = true ): void {

	if (
		   $needs_slug
		&& ( ! \is_string( $package['slug'] ?? null ) || ! preg_match(
			'/\A[a-z0-9][a-z0-9_-]*\z/D',
			$package['slug'],
		) )
	) {
		throw new RuntimeException( 'Invalid package slug.' );
	}

	if (
		   ! \is_string( $package['version'] ?? null )
		|| '' === $package['version']
	) {
		throw new RuntimeException( 'Package version is required.' );
	}

	if (
		   ! \is_string( $package['sha256'] ?? null )
		|| ! preg_match(
			'/\A[a-f0-9]{64}\z/D',
			$package['sha256'],
		)
	) {
		throw new RuntimeException(
			'Every package needs a lowercase SHA-256 digest.',
		);
	}
	$url = \is_string( $package['url'] ?? null ) ? parse_url( $package['url'] ) : false;

	if (
		   ! $url
		|| 'https' !== ( $url['scheme'] ?? '' )
		|| empty( $url['host'] )
		|| isset( $url['user'] )
		|| isset( $url['pass'] )
		|| isset( $url['fragment'] )
	) {
		throw new RuntimeException(
			'Package URLs must use HTTPS without credentials or fragments.',
		);
	}
}

/**
 * Calculate the median without overflowing when averaging two finite values.
 *
 * @param float[] $values Nonempty observations already validated by the caller.
 * @return float Median observation.
 * @throws RuntimeException When the input is empty.
 */
function median( array $values ): float {

	if ( ! $values )
		throw new RuntimeException(
			'Cannot calculate a median without observations.',
		);
	sort( $values, \SORT_NUMERIC );
	$count  = \count( $values );
	$middle = intdiv( $count, 2 );

	return $count % 2 ? (float) $values[ $middle ] : $values[ $middle - 1 ] / 2 + $values[ $middle ] / 2;
}

/**
 * Calculate an interpolated percentile of the empirical distribution.
 *
 * @param float[] $values   Nonempty finite observations.
 * @param float   $fraction Quantile fraction from zero through one.
 * @return float Linearly interpolated quantile.
 * @throws RuntimeException When the observations or fraction are invalid.
 */
function percentile( array $values, float $fraction ): float {

	if ( ! $values || 0 > $fraction || 1 < $fraction )
		throw new RuntimeException( 'Invalid percentile.' );
	sort( $values, \SORT_NUMERIC );
	$index = ( \count( $values ) - 1 ) * $fraction;
	$low   = (int) floor( $index );
	$high  = (int) ceil( $index );

	return $values[ $low ] + ( $values[ $high ] - $values[ $low ] ) * ( $index - $low );
}

/**
 * Draw a deterministic block index without changing PHP's global random state.
 *
 * The Park-Miller multiplier and modulus define the replay algorithm. This
 * generator chooses bootstrap blocks; it does not produce security tokens.
 *
 * @param int $state   Current generator state, updated in place.
 * @param int $maximum Exclusive upper bound for the returned integer.
 * @return int Integer from zero through maximum minus one.
 */
function next_random( int &$state, int $maximum ): int {

	$state = ( $state * 48271 ) % 2147483647;

	return $state % $maximum;
}

/**
 * Recalculate every declared pass without dropping invalid or losing routes.
 *
 * Ratios estimate additional HTTP response time over paired WordPress controls,
 * not exclusive plugin CPU time. Small runs remain diagnostics. A qualified
 * conclusion requires a complete matrix, stable output, resolved denominators,
 * qualified environment evidence, and consistent conclusions across all passes.
 *
 * @param array $recipe      Validated experiment definition.
 * @param array $samples     Warmup and measured observations, including failures.
 * @param array $environment Recorded limitations and blocking conditions.
 * @return array Deterministic report data, without generation timestamps.
 */
function analyze( array $recipe, array $samples, array $environment = [] ): array {

	validate_recipe( $recipe );
	$arm_ids     = array_column( $recipe['arms'], 'id' );
	$route_ids   = array_column( $recipe['routes'], 'id' );
	$anchor      = $recipe['comparison']['anchor'];
	$comparators = array_values( array_diff(
		$arm_ids,
		[ 'core-a', 'core-b', $anchor ],
	) );
	$orders      = balanced_orders( $arm_ids );
	$config      = $recipe['measurement'];
	$smoke       = 3 > $config['passes'] || 3 > $config['warmups'] || 6 > $config['samples'] || 1000 > $config['bootstrap'];
	$expected    = \count( $orders ) * \count( $arm_ids ) * \count( $route_ids );
	$result      = [
		'schema'           => 1,
		'analysis_version' => '1.0.0',
		'recipe_id'        => $recipe['id'],
		'name'             => $recipe['name'],
		'reference'        => $recipe['reference'],
		'scope'            => $smoke ? 'smoke' : ( $recipe['reference'] ? 'reference' : 'custom' ),
		'verdict'          => 'inconclusive',
		'reasons'          => [],
		'environment'      => $environment,
		'metric'           => 'Arithmetic mean of route ratios: (comparator median HTTP time - pooled control median) / (anchor median HTTP time - pooled control median). This estimates added response time, not exclusive CPU time.',
		'comparison'       => $recipe['comparison'],
		'arms'             => $recipe['arms'],
		'routes'           => $recipe['routes'],
		'measurement'      => $config + [
			'orders_per_pass'            => \count( $orders ),
			'expected_measured_per_pass' => $expected * $config['samples'],
			'expected_warmups_per_pass'  => $expected * $config['warmups'],
		],
		'passes'           => [],
		'comparisons'      => [],
	];

	if ( $smoke )
		$result['reasons'][] = 'This is a setup/small-sample run. It cannot establish a broad comparative performance claim.';
	$groups                  = $hashes = $seen = $errors = $counts = [];
	$global_errors           = [];
	foreach ( $samples as $row ) {
		if ( ! \is_array( $row ) ) {
			$global_errors['Malformed sample record.'] = true;
			continue;
		}
		$pass = $row['pass'] ?? null;

		if ( ! \is_int( $pass ) || 1 > $pass || $pass > $config['passes'] ) {
			$global_errors['Sample has an invalid pass number.'] = true;
			continue;
		}
		$route    = $row['route'] ?? null;
		$arm      = $row['arm'] ?? null;
		$block    = $row['block'] ?? null;
		$sequence = $row['sequence'] ?? null;
		$position = $row['position'] ?? null;
		$phase    = $row['phase'] ?? null;

		if (
			   ! \in_array( $route, $route_ids, true )
			|| ! \in_array( $arm, $arm_ids, true )
			|| ! \in_array(
				$phase,
				[ 'warmup', 'measure' ],
				true,
			)
			|| ! \is_int( $block )
			|| 1 > $block
			|| $block > \count( $orders )
			|| ! \is_int( $position )
			|| 1 > $position
			|| $position > \count( $arm_ids )
			|| ! \is_int( $sequence )
			|| 1 > $sequence
			|| $sequence > $config[ 'warmup' === $phase ? 'warmups' : 'samples' ]
		) {
			$errors[ $pass ]['Invalid sampling coordinates.'] = true;
			continue;
		}

		if ( $orders[ $block - 1 ][ $position - 1 ] !== $arm ) {
			$errors[ $pass ]['Request order differs from the declared balanced schedule.'] = true;
			continue;
		}
		$key = implode(
			'|',
			[ $pass, $route, $arm, $block, $phase, $sequence ],
		);

		if ( isset( $seen[ $key ] ) ) {
			$errors[ $pass ]['Duplicate sampling coordinates.'] = true;
			continue;
		}
		$seen[ $key ] = true;
		$elapsed      = $row['elapsed_ms'] ?? null;

		if (
			   true !== ( $row['valid'] ?? null )
			|| ( ! \is_int( $elapsed ) && ! \is_float( $elapsed ) )
			|| ! is_finite( (float) $elapsed )
			|| 0 >= $elapsed
			|| 86400000 < $elapsed
			|| ! \is_string( $row['output_sha256'] ?? null )
			|| ! preg_match(
				'/\A[a-f0-9]{64}\z/D',
				$row['output_sha256'],
			)
		) {
			$errors[ $pass ]['Invalid response, elapsed time, or output digest.'] = true;
			continue;
		}
		$counts[ $pass ][ $phase ]                                  = ( $counts[ $pass ][ $phase ] ?? 0 ) + 1;
		$hashes[ $pass ][ $route ][ $arm ][ $row['output_sha256'] ] = true;

		if ( 'measure' === $phase )
			$groups[ $pass ][ $route ][ $arm ][ $block ][] = (float) $elapsed;
	}
	foreach ( array_keys( $global_errors ) as $error )
		$result['reasons'][] = $error;
	$environment_blocks      = $environment['blockers'] ?? [];

	if ( ! \is_array( $environment_blocks ) )
		$environment_blocks  = [ 'Malformed environment qualification.' ];
	foreach ( $environment_blocks as $blocker )
		$result['reasons'][] = 'Environment: ' . ( \is_string( $blocker ) ? $blocker : 'unqualified condition' );
	for ( $pass = 1; $pass <= $config['passes']; ++$pass ) {
		$pass_result = [
			'pass'        => $pass,
			'verdict'     => 'inconclusive',
			'reasons'     => array_keys( $errors[ $pass ] ?? [] ),
			'routes'      => [],
			'comparisons' => [],
		];
		foreach ( [
			'measure' => 'samples',
			'warmup'  => 'warmups',
		] as $phase => $key ) {
			if ( ( $counts[ $pass ][ $phase ] ?? 0 ) !== $expected * $config[ $key ] )
				$pass_result['reasons'][] = "Incomplete $phase matrix.";
		}
		foreach ( $route_ids as $route ) {
			$blocks      = $groups[ $pass ][ $route ] ?? [];
			$complete    = true;
			$order_count = \count( $orders );

			foreach ( $arm_ids as $arm ) {
				for ( $block = 1; $block <= $order_count; ++$block ) {
					if ( \count( $blocks[ $arm ][ $block ] ?? [] ) !== $config['samples'] )
						$complete = false;
				}
			}

			if ( ! $complete ) {
				$pass_result['routes'][ $route ] = [
					'qualified' => false,
					'reason'    => 'Incomplete observations.',
					'arms'      => [],
				];
				continue;
			}
			$pass_result['routes'][ $route ] = summarize_route(
				$blocks,
				$hashes[ $pass ][ $route ] ?? [],
				$arm_ids,
				\count( $orders ),
			);
		}
		$can_bootstrap = ! $pass_result['reasons'] && ! $global_errors && ! $environment_blocks;
		foreach ( $route_ids as $route ) {
			foreach ( $arm_ids as $arm ) {
				if ( \in_array(
					$arm,
					[ 'core-a', 'core-b' ],
					true,
				) ) {
					continue;
				}

				if ( ! ( $pass_result['routes'][ $route ]['arms'][ $arm ]['qualified'] ?? false ) )
					$can_bootstrap = false;
			}
		}
		$intervals = $can_bootstrap ? bootstrap_ratios(
			$groups[ $pass ],
			$route_ids,
			$anchor,
			$comparators,
			\count( $orders ),
			$config['bootstrap'],
			$config['seed'] + $pass,
			$recipe['comparison']['confidence'],
		) : [];
		foreach ( $comparators as $arm ) {
			$ratios    = $full = [];
			$qualified = ! $pass_result['reasons'] && ! $global_errors && ! $environment_blocks;
			foreach ( $route_ids as $route ) {
				$data = $pass_result['routes'][ $route ];
				$a    = $data['arms'][ $anchor ] ?? null;
				$b    = $data['arms'][ $arm ] ?? null;

				if ( ! $a || ! $b || ! $a['qualified'] || ! $b['qualified'] )
					$qualified = false;

				if (
					   $a
					&& $b
					&& 0 < $a['added_ms']
					&& is_finite(
						$b['added_ms'] / $a['added_ms'],
					)
				) {
					$ratios[] = $b['added_ms'] / $a['added_ms'];
				}

				if ( $a && $b && 0 < $a['median_ms'] )
					$full[] = $b['median_ms'] / $a['median_ms'];
			}
			$interval   = $intervals[ $arm ] ?? null;
			$comparison = [
				'ratio'               => \count( $ratios ) === \count( $route_ids ) ? array_sum( $ratios ) / \count( $ratios ) : null,
				'full_response_ratio' => \count( $full ) === \count( $route_ids ) ? array_sum( $full ) / \count( $full ) : null,
				'confidence_low'      => $interval['low'] ?? null,
				'confidence_high'     => $interval['high'] ?? null,
				'qualified'           => $qualified && null !== $interval,
				'verdict'             => 'inconclusive',
			];

			if ( $comparison['qualified'] && ! $smoke ) {
				if ( $comparison['confidence_low'] >= $recipe['comparison']['threshold'] ) {
					$comparison['verdict'] = 'supported';
				} elseif ( $comparison['confidence_high'] < $recipe['comparison']['threshold'] ) {
					$comparison['verdict'] = 'not_reproduced';
				}
			}
			$pass_result['comparisons'][ $arm ] = $comparison;
		}

		if ( ! $can_bootstrap ) {
			$pass_result['reasons'][] = 'At least one declared route, arm, control, or environment condition is unqualified; the full-cohort claim remains unresolved.';
		} elseif ( ! $intervals ) {
			$pass_result['reasons'][] = 'Bootstrap denominators were unresolved; no bounded ratio interval is reported.';
		}
		$pass_verdicts          = array_column(
			$pass_result['comparisons'],
			'verdict',
		);
		$pass_result['verdict'] = $smoke ? 'smoke_only' : ( \in_array(
			'not_reproduced',
			$pass_verdicts,
			true,
		) ? 'not_reproduced' : ( 1 === \count( array_unique( $pass_verdicts ) ) ? $pass_verdicts[0] : 'inconclusive' ) );
		$result['passes'][]     = $pass_result;
	}
	foreach ( $comparators as $arm ) {
		$ratios = $verdicts = [];
		foreach ( $result['passes'] as $pass_result ) {
			$ratios[]   = $pass_result['comparisons'][ $arm ]['ratio'];
			$verdicts[] = $pass_result['comparisons'][ $arm ]['verdict'];
		}
		$available                     = array_values( array_filter(
			$ratios,
			fn( $value ): bool => null !== $value,
		) );
		$result['comparisons'][ $arm ] = [
			'pass_ratios' => $ratios,
			'minimum'     => $available ? min( $available ) : null,
			'maximum'     => $available ? max( $available ) : null,
			'verdict'     => $smoke ? 'smoke_only' : ( 1 === \count( array_unique( $verdicts ) ) ? $verdicts[0] : 'inconsistent' ),
		];
	}
	$verdicts          = array_column( $result['passes'], 'verdict' );
	$result['verdict'] = $smoke ? 'smoke_only' : ( 1 === \count( array_unique( $verdicts ) ) ? $verdicts[0] : 'inconsistent' );

	return $result;
}

/**
 * Summarize one route and compare its estimated additions with control variation.
 *
 * All block medians must have a positive addition. The full-route addition must
 * also exceed the largest difference between the two same-block controls.
 *
 * @param array    $blocks     Measurements grouped by arm and schedule block.
 * @param array    $hashes     Distinct output fingerprints grouped by arm.
 * @param string[] $arms       Every declared arm, including both controls.
 * @param int      $block_count Number of complete schedule blocks.
 * @return array Route medians, control variation, and arm qualification reasons.
 */
function summarize_route( array $blocks, array $hashes, array $arms, int $block_count ): array {

	$all             = [];
	foreach ( $arms as $arm )
		$all[ $arm ] = array_merge(
			...array_values( $blocks[ $arm ] ),
		);
	$core            = median( array_merge(
		$all['core-a'],
		$all['core-b'],
	) );
	$noise           = 0.0;
	$block_core      = [];
	for ( $block = 1; $block <= $block_count; ++$block ) {
		$noise                = max(
			$noise,
			abs(
				median( $blocks['core-a'][ $block ] ) - median( $blocks['core-b'][ $block ] ),
			),
		);
		$block_core[ $block ] = median( array_merge(
			$blocks['core-a'][ $block ],
			$blocks['core-b'][ $block ],
		) );
	}
	$control_stable = 1 === \count( $hashes['core-a'] ?? [] ) && ( $hashes['core-a'] ?? [] ) === ( $hashes['core-b'] ?? [] );
	$result         = [
		'core_median_ms'       => $core,
		'control_variation_ms' => $noise,
		'controls_stable'      => $control_stable,
		'arms'                 => [],
	];
	foreach ( $arms as $arm ) {
		$p50         = median( $all[ $arm ] );
		$block_added = [];
		for ( $block = 1; $block <= $block_count; ++$block )
			$block_added[] = median( $blocks[ $arm ][ $block ] ) - $block_core[ $block ];
		$stable            = 1 === \count( $hashes[ $arm ] ?? [] );
		$reasons           = [];

		if ( ! $control_stable )
			$reasons[] = 'The two controls do not have matching stable output.';

		if ( ! $stable )
			$reasons[] = 'Selected output changed between requests.';

		if ( $p50 - $core <= $noise )
			$reasons[] = 'Estimated added time does not exceed control variation.';

		if ( 0 >= min( $block_added ) )
			$reasons[]          = 'At least one block has nonpositive estimated added time.';
		$result['arms'][ $arm ] = [
			'median_ms'      => $p50,
			'p95_ms'         => percentile( $all[ $arm ], 0.95 ),
			'added_ms'       => $p50 - $core,
			'block_added_ms' => $block_added,
			'stable'         => $stable,
			'qualified'      => ! $reasons,
			'reasons'        => $reasons,
		];
	}

	return $result;
}

/**
 * Resample whole schedule blocks while preserving the shared controls.
 *
 * Each draw selects the same block indices for every route and arm. A draw with
 * an unresolved denominator invalidates the interval instead of being dropped.
 * Bonferroni tails account for every declared comparator configuration.
 *
 * @param array    $data        Measurements grouped by route, arm, and block.
 * @param string[] $routes      Every declared route.
 * @param string   $anchor      Denominator arm identifier.
 * @param string[] $comparators Numerator arm identifiers.
 * @param int      $block_count  Number of complete schedule blocks.
 * @param int      $draws       Bootstrap repetitions.
 * @param int      $seed        Deterministic replay seed.
 * @param float    $confidence  Simultaneous confidence level.
 * @return array Comparator intervals, or an empty array when unresolved.
 */
function bootstrap_ratios( array $data, array $routes, string $anchor, array $comparators, int $block_count, int $draws, int $seed, float $confidence ): array {

	$state  = ( $seed % 2147483646 ) + 1;
	$ratios = array_fill_keys( $comparators, [] );
	for ( $draw = 0; $draw < $draws; ++$draw ) {
		$selection = [];
		for ( $i = 0; $i < $block_count; ++$i )
			$selection[] = next_random( $state, $block_count ) + 1;
		$sums            = array_fill_keys( $comparators, 0.0 );
		foreach ( $routes as $route ) {
			$medians = [];
			$control = [];
			foreach ( array_merge(
				[ 'core-a', 'core-b', $anchor ],
				$comparators,
			) as $arm ) {
				$values     = [];
				foreach ( $selection as $block )
					$values = array_merge(
						$values,
						$data[ $route ][ $arm ][ $block ],
					);

				if ( \in_array(
					$arm,
					[ 'core-a', 'core-b' ],
					true,
				) ) {
					$control = array_merge( $control, $values );
				} else {
					$medians[ $arm ] = median( $values );
				}
			}
			$core        = median( $control );
			$denominator = $medians[ $anchor ] - $core;

			if ( 0 >= $denominator )
				return [];
			foreach ( $comparators as $arm ) {
				$ratio = ( $medians[ $arm ] - $core ) / $denominator;

				if ( ! is_finite( $ratio ) )
					return [];
				$sums[ $arm ] += $ratio;

				if ( ! is_finite( $sums[ $arm ] ) )
					return [];
			}
		}
		foreach ( $comparators as $arm )
			$ratios[ $arm ][] = $sums[ $arm ] / \count( $routes );
	}
	// Bonferroni tails account for every comparator configuration, including both AIOSEO setups.
	$tail               = ( 1 - $confidence ) / ( 2 * \count( $comparators ) );
	$result             = [];
	foreach ( $comparators as $arm )
		$result[ $arm ] = [
			'low'  => percentile( $ratios[ $arm ], $tail ),
			'high' => percentile( $ratios[ $arm ], 1 - $tail ),
		];

	return $result;
}
