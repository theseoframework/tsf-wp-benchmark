<?php
/**
 * Real WordPress recycling and observer checks for the owned Windows server.
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
 * Retains a diagnostic response before validating it; never retries a failed sample.
 *
 * @param resource $stream Fresh diagnostic NDJSON stream.
 * @param array    $site Owned WordPress fixture.
 * @param array    $route Declared homepage context.
 * @param array    $labels Diagnostic phase and sequence.
 * @param mixed    $handle Reusable cURL handle matching the main collector.
 * @return array Validated response, including worker headers and elapsed time.
 * @throws RuntimeException When transport, retention, or qualification fails.
 * @throws \Throwable Rethrows a transport failure after retaining its diagnostics.
 */
function preflight_request( $stream, array $site, array $route, array $labels, mixed $handle ): array {

	try {
		$response = request( $site['url'] . '/', $site['token'], null, null, $handle );
		$row      = $labels + [ 'response' => $response ];
	} catch ( \Throwable $error ) {
		$line = json_encode( $labels + [ 'error' => $error->getMessage() ], \JSON_THROW_ON_ERROR ) . "\n";
		if ( fwrite( $stream, $line ) !== \strlen( $line ) || ! fflush( $stream ) )
			throw new RuntimeException( 'Cannot retain failed preflight observation.' );

		throw $error;
	}
	// Complete failed responses stay private; large successful HTML bodies need only a digest.
	$check = qualify_response( $response, $route, $site );
	if ( $check['valid'] ) {
		$row['response']['body_sha256'] = hash( 'sha256', $row['response']['body'] );
		unset( $row['response']['body'] );
	}
	$line = json_encode( $row, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION ) . "\n";
	if ( fwrite( $stream, $line ) !== \strlen( $line ) || ! fflush( $stream ) )
		throw new RuntimeException( 'Cannot retain complete preflight evidence.' );

	if ( ! $check['valid'] )
		throw new RuntimeException( 'WordPress preflight response failed: ' . implode( ', ', $check['reasons'] ) );

	return $response;
}

/**
 * Checks worker replacements and monitoring on a fresh, independent WordPress control.
 *
 * This diagnostic does not contribute to plugin timings. The parent approval covers
 * a deterministic sibling namespace derived from the parent's identity. Its journal and cleanup
 * proof prevent reuse or deletion of any measured fixture. All failed observations
 * and worker replacements are retained; a failed preflight requires a fresh plan.
 *
 * @param string $run Approved prepared private run directory.
 * @return array Qualification result bound to the exact approved plan.
 * @throws RuntimeException When ownership, runtime, recycling, or evidence checks fail.
 * @throws \Throwable Rethrows original setup or observation failures after cleanup.
 */
function supervisor_preflight( string $run ): array {

	$plan = approved_plan( $run );
	if ( ! isset( $plan['target']['worker_supervisor'] ) )
		throw new RuntimeException( 'Preflight requires an owned worker supervisor.' );

	$isolation = read_json( "$run/isolation.private.json" );
	if ( digest( $plan ) !== $isolation['plan_sha256'] )
		throw new RuntimeException( 'Verify approved isolation before preflight.' );

	$directory = "$run/preflight";
	if ( file_exists( $directory ) )
		throw new RuntimeException( 'Preserve the earlier preflight; use a fresh run plan.' );

	make_directory( $directory );
	$diagnostic_plan                            = $plan;
	$diagnostic_plan['namespace']               = 'tsfb' . substr( hash( 'sha256', $plan['namespace'] . '/preflight' ), 0, 12 );
	$diagnostic_plan['target']['package_cache'] = $plan['target']['package_cache'] ?? "$run/packages";
	write_json( "$directory/plan.private.json", $diagnostic_plan );
	$server  = null;
	$handle  = null;
	$stream  = null;
	$monitor = null;
	$failure = null;
	try {
		$server = start_worker_supervisor( $plan['target'], $directory );
		$index  = array_search( 'core-a', array_column( $plan['recipe']['arms'], 'id' ), true );
		$core   = $plan['recipe']['arms'][ $index ];
		$site   = provision_fixture( $diagnostic_plan, $directory, 1, $index, $core );

		$site['fixture'] = run_fixture_command( $diagnostic_plan, $directory, $site, 'install' );
		$network         = fixture_control( $site, $plan['target'], 'isolation' );
		write_json( "$directory/isolation.json", $network );
		if ( ! $network['blocked'] ) throw new RuntimeException( 'Preflight worker can reach the internet.' );

		run_fixture_command( $diagnostic_plan, $directory, $site, 'activate' );
		write_bytes( $site['root'] . '/wp-content/mu-plugins/999-preflight.php', file_get_contents( \dirname( __DIR__ ) . '/scripts/stubs/preflight-worker.php' ) );
		fixture_control( $site, $plan['target'], 'context', [ 'context' => 'blog' ] );
		$before = fixture_control( $site, $plan['target'], 'runtime' );
		write_json( "$directory/runtime-before.json", $before );
		if ( ! assert_runtime( $before, $site, $plan['recipe'] )['reference_ready'] )
			throw new RuntimeException( 'Preflight HTTP runtime does not qualify.' );

		$route  = [
			'id'      => 'home-blog',
			'kind'    => 'html',
			'status'  => 200,
			'context' => 'blog',
		];
		$handle = curl_init();
		$stream = fopen( "$directory/observations.ndjson", 'xb' );
		if ( ! $stream ) throw new RuntimeException( 'Cannot create preflight observations.' );

		$workers = [];
		for ( $sequence = 1; $sequence <= 2200; ++$sequence ) {
			$response = preflight_request( $stream,
				$site,
				$route,
				[
					'phase'    => 'recycling',
					'sequence' => $sequence,
				],
				$handle );
			$pid      = $response['headers']['x-tsfb-worker'] ?? '';
			if ( ! ctype_digit( $pid ) || '500' !== ( $response['headers']['x-tsfb-recycle'] ?? '' ) )
				throw new RuntimeException( 'WordPress did not verify the expected worker recycling settings.' );

			$workers[ $pid ] = ( $workers[ $pid ] ?? 0 ) + 1;
			if ( 500 < $workers[ $pid ] ) throw new RuntimeException( 'Worker did not recycle as declared.' );

			if ( 0 === $sequence % 500 ) echo "WordPress recycling preflight: $sequence/2200 responses valid.\n";
		}
		if ( \count( $workers ) <= $plan['target']['worker_supervisor']['workers'] )
			throw new RuntimeException( 'No worker replacement was observed.' );

		$pairs = [];
		for ( $pair = 1; $pair <= 12; ++$pair ) {
			foreach ( $pair % 2 ? [ 'off', 'on' ] : [ 'on', 'off' ] as $condition ) {
				$monitor = 'on' === $condition ? start_telemetry( "$directory/telemetry-$pair.ndjson" ) : null;
				sleep( 5 ); // Exclude startup; use the same settling period in both conditions.
				$times = [];
				$start = gmdate( 'c' );
				foreach ( [
					'warmup'  => 10,
					'measure' => 50,
				] as $phase => $count ) {
					for ( $sequence = 1; $sequence <= $count; ++$sequence ) {
						$response                            = preflight_request( $stream,
							$site,
							$route,
							[
								'phase'     => $phase,
								'pair'      => $pair,
								'condition' => $condition,
								'sequence'  => $sequence,
							],
							$handle );
						if ( 'measure' === $phase ) $times[] = $response['elapsed_ms'];
					}
				}
				$pairs[ $pair ][ $condition ] = median( $times );
				if ( null !== $monitor ) {
					$summary = stop_telemetry( $monitor,
						[
							'measurement_start' => $start,
							'measurement_end'   => gmdate( 'c' ),
						] );
					$monitor = null;
					write_json( "$directory/telemetry-$pair-summary.json", $summary );
					if ( ! $summary['available'] ) throw new RuntimeException( 'Monitoring was unavailable during calibration.' );
				}
			}
			printf( "Monitoring calibration %d/12: off %.3f ms; on %.3f ms\n", $pair, $pairs[ $pair ]['off'], $pairs[ $pair ]['on'] );
		}
		$ratios                               = [];
		foreach ( $pairs as $pair ) $ratios[] = $pair['on'] / $pair['off'];
		$after                                = fixture_control( $site, $plan['target'], 'runtime' );
		$after['opcache']['before']           = $before['opcache']['status'];
		write_json( "$directory/runtime-after.json", $after );
		if ( ! assert_runtime( $after, $site, $plan['recipe'] )['reference_ready'] )
			throw new RuntimeException( 'WordPress runtime did not remain qualified across recycling.' );

		$result = [
			'passed'                 => true,
			'plan_sha256'            => digest( $plan ),
			'wordpress'              => $plan['recipe']['wordpress']['version'],
			'web_server'             => $response['headers']['server'] ?? 'unavailable',
			'php_version'            => $before['php_version'],
			'stability_requests'     => 2200,
			'worker_count'           => \count( $workers ),
			'worker_response_counts' => $workers,
			'monitoring'             => [
				'pairs'               => $pairs,
				'median_on_off_ratio' => median( $ratios ),
				'measured_requests'   => 1200,
				'warmups'             => 240,
			],
			'scope'                  => 'Independent WordPress-only diagnostic; excluded from plugin timing samples.',
		];
	} catch ( \Throwable $error ) {
		$failure = $error;
		write_json( "$directory/failure.json", [ 'error' => $error->getMessage() ] );
		throw $error;
	} finally {
		if ( null !== $monitor ) stop_telemetry( $monitor );
		if ( \is_resource( $stream ) ) fclose( $stream );
		if ( null !== $handle ) curl_close( $handle );

		try {
			write_json( "$directory/cleanup.json", cleanup_fixtures( $diagnostic_plan, $directory ) );
		} catch ( \Throwable $cleanup_error ) {
			write_json( "$directory/cleanup-failure.json", [ 'error' => $cleanup_error->getMessage() ] );
			if ( null === $failure ) throw $cleanup_error;
		} finally {
			if ( null !== $server ) write_json( "$directory/server-cleanup.json", stop_worker_supervisor( $server ) );
		}
	}
	write_json( "$run/preflight.json", $result );

	return $result;
}
