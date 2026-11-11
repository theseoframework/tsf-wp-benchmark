<?php
/**
 * Low-rate native host telemetry, with explicit coverage and missing-data reasons.
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
 * Builds sampler arguments without allowing paths to become shell expressions.
 *
 * @param string $family     PHP operating system family.
 * @param string $executable Native interpreter path.
 * @param string $script     Adapter script path.
 * @param string $output     Fresh JSONL output path.
 * @param string $stop       Run-owned stop marker path.
 * @return array Process argument vector.
 * @throws \RuntimeException When no native adapter exists.
 */
function telemetry_command(
	string $family,
	string $executable,
	string $script,
	string $output,
	string $stop
): array {

	if ( 'Windows' === $family ) {
		return [
			$executable,
			'-NoLogo',
			'-NoProfile',
			'-NonInteractive',
			'-File',
			$script,
			'-OutputPath',
			$output,
			'-StopPath',
			$stop,
			'-IntervalSeconds',
			'2',
		];
	}

	if ( 'Darwin' === $family )
		return [ $executable, '-l', 'JavaScript', $script, $output, $stop, '2' ];

	throw new \RuntimeException(
		'No native telemetry adapter for ' . $family . '.',
	);
}

/**
 * Starts one collector outside the HTTP request loop.
 *
 * @param string $output_path Fresh output path in an existing run-owned directory.
 * @return array Process handle and owned paths, or an explicit unavailable reason.
 * @throws \RuntimeException When output cannot be created safely.
 */
function start_telemetry( string $output_path ): array {

	$family = \PHP_OS_FAMILY;

	if ( ! \in_array(
		$family,
		[ 'Windows', 'Darwin' ],
		true,
	) ) {
		return [
			'supported' => false,
			'reason'    => 'unsupported_platform',
			'output'    => $output_path,
		];
	}

	if ( ! \function_exists( 'proc_open' ) )
		return [
			'supported' => false,
			'reason'    => 'process_launch_unavailable',
			'output'    => $output_path,
		];

	$root       = \dirname( __DIR__ );
	$executable = 'Windows' === $family
		? ( find_executable( 'pwsh.exe' ) ?? find_executable( 'powershell.exe' ) )
		: '/usr/bin/osascript';

	if ( null === $executable || ! is_file( $executable ) )
		return [
			'supported' => false,
			'reason'    => 'native_tool_unavailable',
			'output'    => $output_path,
		];

	// Batch wrappers require a command shell, which this collector never invokes.
	if (
		   'Windows' === $family
		&& 'exe' !== strtolower( pathinfo(
			$executable,
			\PATHINFO_EXTENSION,
		) )
	) {
		return [
			'supported' => false,
			'reason'    => 'native_executable_required',
			'output'    => $output_path,
		];
	}

	$script = $root . '/scripts/telemetry-' . ( 'Windows' === $family ? 'windows.ps1' : 'macos.js' );
	$stop   = $output_path . '.stop';
	$error  = $output_path . '.stderr';

	foreach ( [ $output_path, $stop, $error ] as $path ) {
		if ( file_exists( $path ) || is_link( $path ) )
			throw new \RuntimeException(
				'Telemetry needs fresh paths in an existing run-owned directory.',
			);
	}

	if ( ! is_dir( \dirname( $output_path ) ) )
		throw new \RuntimeException(
			'Telemetry output directory does not exist.',
		);

	$created = fopen( $output_path, 'x' );

	if ( false === $created )
		throw new \RuntimeException(
			'Cannot create telemetry output.',
		);

	fclose( $created );
	$command = telemetry_command(
		$family,
		$executable,
		$script,
		$output_path,
		$stop,
	);
	$process = proc_open(
		$command,
		[
			0 => [ 'pipe', 'r' ],
			1 => [ 'file', $error, 'a' ],
			2 => [ 'file', $error, 'a' ],
		],
		$pipes,
		$root,
		null,
		[
			'bypass_shell'       => true,
			'create_new_console' => false,
		],
	);

	if ( ! \is_resource( $process ) )
		return [
			'supported' => false,
			'reason'    => 'process_launch_failed',
			'output'    => $output_path,
		];

	fclose( $pipes[0] );

	return [
		'supported' => true,
		'process'   => $process,
		'output'    => $output_path,
		'stop'      => $stop,
		'stderr'    => $error,
	];
}

/**
 * Stops the owned collector and summarizes the retained readings.
 *
 * @param array $handle Collector handle returned by start_telemetry().
 * @param array $window Optional measurement_start and measurement_end ISO timestamps.
 * @return array Availability, process outcome, sample counts, coverage, and conditions.
 */
function stop_telemetry( array $handle, array $window = [] ): array {

	if ( empty( $handle['supported'] ) ) {
		return [
			'available' => false,
			'reason'    => $handle['reason'] ?? 'unavailable',
			'samples'   => 0,
		] + summarize_telemetry_readings( [], $window );
	}

	$process = $handle['process'];

	if ( ! file_exists( $handle['stop'] ) ) {
		$flag = fopen( $handle['stop'], 'x' );

		if ( false !== $flag )
			fclose( $flag );
	}

	// Allow three normal sampling intervals for graceful shutdown.
	$deadline = hrtime( true ) + 6_000_000_000;
	$status   = proc_get_status( $process );

	while ( $status['running'] && hrtime( true ) < $deadline ) {
		usleep( 50_000 );
		$status = proc_get_status( $process );
	}

	$forced = $status['running'];

	if ( $forced )
		proc_terminate( $process, 9 );

	$exit = proc_close( $process );

	if ( is_file( $handle['stop'] ) )
		unlink( $handle['stop'] );

	$rows    = [];
	$invalid = 0;
	$file    = fopen( $handle['output'], 'r' );

	if ( false !== $file ) {
		for ( $line = fgets( $file ); false !== $line; $line = fgets( $file ) ) {
			try {
				$row = json_decode(
					trim( $line ),
					true,
					512,
					\JSON_THROW_ON_ERROR,
				);

				if (
					\is_array( $row )
					&& \is_array( $row['metrics'] ?? null )
					&& null !== telemetry_timestamp( $row['timestamp'] ?? null )
				) {
					$rows[] = $row;
				} else {
					++$invalid;
				}
			} catch ( \JsonException ) {
				++$invalid;
			}
		}

		fclose( $file );
	}

	return [
		'available'    => (bool) $rows,
		'reason'       => $rows ? null : 'collector_unavailable_or_permission_denied',
		'samples'      => \count( $rows ),
		'invalid_rows' => $invalid,
		'forced_stop'  => $forced,
		'exit_code'    => 0 <= $status['exitcode'] ? $status['exitcode'] : $exit,
		'notice'       => 'Readings describe the collector host. Thermal pressure, ACPI zone temperatures, and CPU die temperature are different measurements.',
	] + summarize_telemetry_readings( $rows, $window );
}

/**
 * Parses an ISO timestamp without normalizing invalid dates into another day.
 *
 * @param mixed $timestamp Timestamp from a collector or measurement boundary.
 * @return float|null Unix seconds including fractional seconds, or null if invalid.
 */
function telemetry_timestamp( mixed $timestamp ): ?float {

	if (
		! \is_string( $timestamp )
		|| ! preg_match(
			'/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|([+-])(\d{2}):(\d{2}))\z/',
			$timestamp,
			$parts,
		)
	) {
		return null;
	}

	if (
		! checkdate(
			(int) $parts[2],
			(int) $parts[3],
			(int) $parts[1],
		)
		|| 23 < (int) $parts[4]
		|| 59 < (int) $parts[5]
		|| 59 < (int) $parts[6]
		|| 23 < (int) ( $parts[8] ?? 0 )
		|| 59 < (int) ( $parts[9] ?? 0 )
	) {
		return null;
	}

	try {
		return (float) ( new \DateTimeImmutable( $timestamp ) )->format( 'U.u' );
	} catch ( \Exception ) {
		return null;
	}
}

/**
 * Summarizes each metric, retaining missing samples, units, and source provenance.
 *
 * A metric omitted from a row is missing, not an available reading. Invalid
 * values cannot inflate coverage. Values with incompatible units are not pooled.
 *
 * @param array $rows Collector rows with a metrics map.
 * @return array Metric-name map of counts, reasons, states, and numeric summaries.
 */
function telemetry_coverage( array $rows ): array {

	$coverage = [];

	foreach ( $rows as $row ) {
		if ( ! \is_array( $row['metrics'] ?? null ) ) continue;

		foreach ( $row['metrics'] as $name => $_ ) {
			if ( ! \is_string( $name ) ) continue;

			$coverage[ $name ] ??= [
				'available'   => 0,
				'unavailable' => 0,
				'reasons'     => [],
				'sources'     => [],
				'units'       => [],
				'statuses'    => [],
				'states'      => [],
				'_numeric'    => [],
			];
		}
	}

	foreach ( $coverage as $name => &$item ) {
		foreach ( $rows as $row ) {
			$metric = $row['metrics'][ $name ] ?? null;
			$value  = \is_array( $metric ) ? ( $metric['value'] ?? null ) : null;
			$status = \is_string( $metric['status'] ?? null ) ? $metric['status'] : 'unspecified';
			$source = $metric['source'] ?? null;
			$unit   = $metric['unit'] ?? null;

			if ( ! \is_array( $metric ) ) {
				$status = 'missing_or_invalid_metric';
			} elseif (
				   ! \is_string( $source )
				|| '' === $source
				|| ! \is_string( $unit )
				|| '' === $unit
			) {
				$value  = null;
				$status = 'invalid_metric_metadata';
			} else {
				$item['sources'][] = $source;
				$item['units'][]   = $unit;
			}

			$numeric = \is_int( $value ) || \is_float( $value );

			if ( $numeric && ! is_finite( (float) $value ) ) {
				$value  = null;
				$status = 'invalid_nonfinite_reading';
			} elseif (
				   null !== $value
				&& ! $numeric
				&& ! \is_string( $value )
				&& ! \is_bool( $value )
			) {
				$value  = null;
				$status = 'invalid_reading_type';
			}

			$item['statuses'][] = $status;
			++$item[ null === $value ? 'unavailable' : 'available' ];

			if ( null === $value ) {
				$item['reasons'][] = $status;
			} elseif ( $numeric ) {
				$item['_numeric'][] = $value;
			} else {
				$item['states'][ json_encode( $value, \JSON_THROW_ON_ERROR ) ] = $value;
			}
		}

		foreach ( [ 'reasons', 'sources', 'units', 'statuses' ] as $key )
			$item[ $key ] = array_values( array_unique( $item[ $key ] ) );

		$item['states']      = array_values( $item['states'] );
		$item['mixed_units'] = 1 < \count( $item['units'] );
		$values              = $item['_numeric'];
		unset( $item['_numeric'] );
		$item['numeric'] = null;

		if ( $values && ! $item['mixed_units'] ) {
			sort( $values, \SORT_NUMERIC );
			$count  = \count( $values );
			$middle = intdiv( $count, 2 );

			$median = $values[ $middle ];

			if ( 0 === $count % 2 ) {
				$sum = $values[ $middle - 1 ] + $values[ $middle ];
				// Halve first only when adding two finite readings overflows.
				$median = is_finite( (float) $sum )
					? $sum / 2
					: $values[ $middle - 1 ] / 2 + $values[ $middle ] / 2;
			}

			$item['numeric'] = [
				'count'  => $count,
				'min'    => $values[0],
				'median' => $median,
				'max'    => $values[ $count - 1 ],
			];
		}
	}

	unset( $item );

	return $coverage;
}

/**
 * Separates measurement observations from collector startup and shutdown.
 *
 * @param array $rows   Collector rows with observation timestamps.
 * @param array $window Optional ordered measurement_start and measurement_end.
 * @return array Complete and measured coverage, limitations, and disturbances.
 * @throws \RuntimeException When measurement boundaries are invalid.
 */
function summarize_telemetry_readings( array $rows, array $window = [] ): array {

	$start = telemetry_timestamp(
		$window['measurement_start'] ?? null,
	);
	$end   = telemetry_timestamp(
		$window['measurement_end'] ?? null,
	);

	if ( $window && ( null === $start || null === $end || $end < $start ) )
		throw new \RuntimeException(
			'Telemetry measurement bounds must be ordered ISO 8601 timestamps.',
		);

	$measured = [];

	foreach ( $rows as $row ) {
		$time = telemetry_timestamp( $row['timestamp'] ?? null );

		if ( $window && null !== $time && $time >= $start && $time <= $end )
			$measured[] = $row;
	}

	$coverage             = telemetry_coverage( $rows );
	$measurement_coverage = $window ? telemetry_coverage( $measured ) : null;
	$assessment           = $measurement_coverage ?? $coverage;
	$scope                = $window ? 'measurement interval' : 'whole collector session, including startup';
	$conditions           = [];

	/**
	 * Records an observation without correcting or discarding benchmark samples.
	 *
	 * @param string $code     Stable condition identifier.
	 * @param string $severity Reference qualification or limitation.
	 * @param string $message  Human-readable explanation.
	 * @return void
	 */
	$add = function ( string $code, string $severity, string $message ) use ( &$conditions ): void {
		$conditions[] = [
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		];
	};

	if ( $window && ! $measured )
		$add(
			'telemetry_measurement_missing',
			'limitation',
			'No host readings fall inside the measured interval.',
		);

	if ( empty( $assessment['cpu_busy']['available'] ) ) {
		$add(
			'telemetry_cpu_unavailable',
			'limitation',
			'Host CPU utilization was unavailable in the ' . $scope . '.',
		);
	}

	if ( empty( $assessment['cpu_temperature']['available'] ) ) {
		$add(
			'telemetry_cpu_temperature_unavailable',
			'limitation',
			'CPU temperature was not measured; missing readings do not establish a cool CPU.',
		);
	}

	if ( empty( $assessment['thermal_pressure']['available'] ) ) {
		$add(
			'telemetry_thermal_pressure_unavailable',
			'limitation',
			'Thermal pressure was not reported; throttling cannot be excluded from this telemetry alone.',
		);
	}

	$thermal = $assessment['thermal_pressure']['states'] ?? [];

	if ( array_intersect(
		[ 'serious', 'critical' ],
		$thermal,
	) ) {
		$add(
			'telemetry_thermal_disturbance',
			$window ? 'reference' : 'limitation',
			'Serious or critical thermal pressure was observed in the ' . $scope . '; retain these observations and review stability.',
		);
	}

	foreach ( [ 'power_source', 'power_plan', 'battery_saver', 'low_power_mode' ] as $name ) {
		if ( 1 < \count(
			$assessment[ $name ]['states'] ?? [],
		) ) {
			$add(
				'telemetry_power_state_changed',
				$window ? 'reference' : 'limitation',
				"$name changed during the $scope; retain the affected measurements.",
			);
		}
	}

	$observer = $assessment['collector_cpu']['numeric'] ?? null;

	if (
		null !== $observer
		&& [ 'percent_of_one_logical_cpu' ] === ( $assessment['collector_cpu']['units'] ?? [] )
		&& 1 < $observer['median']
	) {
		$add(
			'telemetry_observer_cpu',
			'limitation',
			'Collector CPU median exceeded 1% of one logical CPU in the ' . $scope . '. This is an observer-cost warning, not an estimate of its effect on request times; qualify with an A/A check.',
		);
	}

	foreach ( $assessment as $name => $item ) {
		if ( $item['mixed_units'] ) {
			$add(
				'telemetry_mixed_units',
				'limitation',
				"$name changed units; numeric readings were not combined.",
			);
		}

		if ( $item['unavailable'] && $item['available'] ) {
			$add(
				'telemetry_partial_coverage',
				'limitation',
				"$name was unavailable for some observations in the $scope; inspect coverage before judging stability.",
			);
		}
	}

	return [
		'coverage'                     => $coverage,
		'measurement_coverage'         => $measurement_coverage,
		'measurement_samples'          => $window ? \count( $measured ) : null,
		'measurement_window'           => $window ?: null,
		'conditions'                   => $conditions,
		'observer_overhead_calibrated' => false,
	];
}
