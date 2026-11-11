<?php
/**
 * Runtime qualification and native telemetry regressions; no WordPress launch.
 *
 * @package TSF_WP_Benchmark
 */

use function TsfBenchmark\assess_runtime;
use function TsfBenchmark\execute;
use function TsfBenchmark\find_executable;
use function TsfBenchmark\ini_enabled;
use function TsfBenchmark\stop_telemetry;
use function TsfBenchmark\summarize_telemetry_readings;
use function TsfBenchmark\telemetry_command;
use function TsfBenchmark\telemetry_coverage;
use function TsfBenchmark\telemetry_timestamp;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

require_once dirname( __DIR__ ) . '/src/Common.php';
require_once dirname( __DIR__ ) . '/src/Environment.php';
require_once dirname( __DIR__ ) . '/src/Telemetry.php';

/**
 * Builds a complete, uninstrumented HTTP receipt for assessment tests.
 *
 * @return array Valid reference runtime evidence.
 */
function healthy_environment_fixture(): array {
	return [
		'php_version'           => '8.4.27',
		'sapi'                  => 'fpm-fcgi',
		'extensions'            => [],
		'wordpress_environment' => 'production',
		'wordpress_debug'       => [
			'WP_DEBUG'         => false,
			'WP_DEBUG_DISPLAY' => false,
			'WP_DEBUG_LOG'     => false,
			'SCRIPT_DEBUG'     => false,
			'SAVEQUERIES'      => false,
		],
		'xdebug'                => [ 'loaded' => false ],
		'opcache'               => [
			'ini'    => [
				'opcache.file_cache'      => '',
				'opcache.file_cache_only' => '0',
			],
			'status' => [
				'opcache_enabled'    => true,
				'cache_full'         => false,
				'opcache_statistics' => [
					'start_time'      => 100,
					'oom_restarts'    => 0,
					'hash_restarts'   => 0,
					'manual_restarts' => 0,
				],
			],
		],
	];
}

/**
 * Checks the stable condition code independently of explanatory wording.
 *
 * @param array  $assessment Runtime or telemetry assessment.
 * @param string $code       Expected condition code.
 * @return bool Whether the condition is present.
 */
function has_environment_condition( array $assessment, string $code ): bool {
	return in_array(
		$code,
		array_column(
			$assessment['conditions'],
			'code',
		),
		true,
	);
}

/**
 * Builds a numeric sensor reading for coverage tests.
 *
 * @param mixed  $value  Number, null, or intentionally malformed value.
 * @param string $unit   Declared measurement unit.
 * @param string $status Declared provider status.
 * @return array Metric receipt.
 */
function environment_metric( mixed $value, string $unit = 'percent', string $status = 'available' ): array {
	return [
		'value'  => $value,
		'unit'   => $unit,
		'source' => 'OS counter',
		'status' => $status,
	];
}

return [
	/**
	 * Checks local and missing environment evidence cannot qualify as production.
	 *
	 * @return void
	 */
	'environment: production behavior must be verified'    => function (): void {

		foreach ( [ 'local', 'development', 'staging', null ] as $environment ) {
			$runtime                          = healthy_environment_fixture();
			$runtime['wordpress_environment'] = $environment;
			expect(
				has_environment_condition( assess_runtime( $runtime ), 'wordpress_environment_not_production' ),
				'Non-production or unknown behavior must not qualify.',
			);
		}
	},
	/**
	 * Checks healthy HTTP runtime qualifies.
	 *
	 * @return void
	 */
	'environment: healthy HTTP runtime qualifies'          => function (): void {

		expect(
			assess_runtime( healthy_environment_fixture() )['reference_ready'],
			'Healthy HTTP worker must qualify.',
		);
	},
	/**
	 * Checks CLI is not HTTP evidence.
	 *
	 * @return void
	 */
	'environment: CLI is not HTTP evidence'                => function (): void {

		$runtime         = healthy_environment_fixture();
		$runtime['sapi'] = 'cli';

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'http_runtime_unverified',
			),
			'Reject CLI-only runtime proof.',
		);
	},
	/**
	 * Checks WordPress debug modes are separate from ordinary logging.
	 *
	 * @return void
	 */
	'environment: WordPress debug modes are separate from ordinary logging' => function (): void {

		$flags = [
			'WP_DEBUG'     => 'wordpress_debug_enabled',
			'SCRIPT_DEBUG' => 'wordpress_script_debug_enabled',
			'SAVEQUERIES'  => 'wordpress_query_recording_enabled',
		];

		foreach ( $flags as $flag => $code ) {
			$runtime                             = healthy_environment_fixture();
			$runtime['wordpress_debug'][ $flag ] = true;
			$assessment                          = assess_runtime( $runtime );

			expect(
				! $assessment['reference_ready'] && has_environment_condition( $assessment, $code ),
				'Flag active WordPress instrumentation: ' . $flag . '.',
			);
		}

		$runtime                    = healthy_environment_fixture();
		$runtime['php_diagnostics'] = [
			'log_errors'      => true,
			'display_errors'  => false,
			'error_reporting' => E_ALL,
		];

		expect(
			assess_runtime( $runtime )['reference_ready'],
			'Ordinary PHP error logging is not query recording or WordPress debug mode.',
		);
	},
	/**
	 * Checks missing debug evidence is not a verified false flag.
	 *
	 * @return void
	 */
	'environment: missing debug evidence is not a verified false flag' => function (): void {

		foreach ( [ 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG', 'SAVEQUERIES' ] as $flag ) {
			$runtime = healthy_environment_fixture();
			unset( $runtime['wordpress_debug'][ $flag ] );

			expect(
				has_environment_condition(
					assess_runtime( $runtime ),
					'wordpress_debug_unverified',
				),
				'Missing flag must be reported as unknown: ' . $flag . '.',
			);
		}

		$runtime                                    = healthy_environment_fixture();
		$runtime['wordpress_debug']['WP_DEBUG_LOG'] = '/private/debug.log';
		$assessment                                 = assess_runtime( $runtime );

		expect(
			! $assessment['reference_ready'] && ! str_contains(
				json_encode( $assessment ),
				'/private/debug.log',
			),
			'Expect only a Boolean log flag; do not repeat malformed private paths.',
		);
	},
	/**
	 * Checks effective Xdebug modes override ini text.
	 *
	 * @return void
	 */
	'environment: effective Xdebug modes override ini text' => function (): void {

		$runtime           = healthy_environment_fixture();
		$runtime['xdebug'] = [
			'loaded'          => true,
			'effective_modes' => [ 'profile' ],
			'ini_mode'        => 'off',
			'env_mode'        => 'profile',
		];

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'xdebug_active',
			),
			'Effective profiling must be detected.',
		);

		$runtime['xdebug'] = [
			'loaded'          => true,
			'effective_modes' => [],
			'ini_mode'        => 'profile',
			'env_mode'        => 'off',
		];

		expect(
			assess_runtime( $runtime )['reference_ready'],
			'Verified effective off modes qualify with a recorded limitation.',
		);

		unset( $runtime['xdebug']['effective_modes'] );

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'xdebug_mode_unverified',
			),
			'INI text alone is not verification.',
		);

		$runtime['xdebug']['loaded'] = false;
		$runtime['extensions']       = [ 'xdebug' ];

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'xdebug_mode_unverified',
			),
			'A conflicting loaded=false field must not hide the extension list.',
		);
	},
	/**
	 * Checks disk second-level cache is not disk-only.
	 *
	 * @return void
	 */
	'environment: disk second-level cache is not disk-only' => function (): void {

		$runtime = healthy_environment_fixture();
		$runtime['opcache']['ini']['opcache.file_cache'] = '/cache';

		expect(
			assess_runtime( $runtime )['reference_ready'],
			'Second-level disk cache remains permissible with shared memory.',
		);

		$runtime['opcache']['ini']['opcache.file_cache_only'] = '1';

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'opcache_file_only',
			),
			'Disk-only mode must be reported.',
		);

		$runtime['opcache']['ini']['opcache.file_cache_only'] = '0';
		$runtime['opcache']['status']                         = false;

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'opcache_shared_unverified',
			),
			'Shared-memory fallback or failure cannot pass based on configuration.',
		);

		$runtime['opcache']['ini']['opcache.file_cache'] = false;

		expect(
			! has_environment_condition(
				assess_runtime( $runtime ),
				'opcache_second_level',
			),
			'An unavailable INI directive does not establish a disk cache.',
		);
	},
	/**
	 * Checks cache fullness and restarts are observable.
	 *
	 * @return void
	 */
	'environment: cache fullness and restarts are observable' => function (): void {

		$runtime                                    = healthy_environment_fixture();
		$runtime['opcache']['status']['cache_full'] = true;

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'opcache_full',
			),
			'Full cache must be flagged.',
		);

		$runtime                      = healthy_environment_fixture();
		$runtime['opcache']['before'] = $runtime['opcache']['status'];
		++$runtime['opcache']['status']['opcache_statistics']['manual_restarts'];

		expect(
			has_environment_condition(
				assess_runtime( $runtime ),
				'opcache_continuity_changed',
			),
			'A restart during the run invalidates continuity.',
		);
	},
	/**
	 * Checks ini Boolean handling is literal.
	 *
	 * @return void
	 */
	'environment: ini Boolean handling is literal'         => function (): void {

		expect(
			! ini_enabled( 'off' ) && ! ini_enabled( 'false' ) && ! ini_enabled( '0' ),
			'False INI strings remain false.',
		);
		expect(
			ini_enabled( 'On' ) && ini_enabled( '1' ),
			'True INI strings are recognized.',
		);
	},
	/**
	 * Checks paths remain separate process arguments.
	 *
	 * @return void
	 */
	'telemetry: paths remain separate process arguments'   => function (): void {

		$path    = 'C:/space & $(unsafe)/sample.jsonl';
		$command = telemetry_command(
			'Windows',
			'C:/PowerShell/pwsh.exe',
			'C:/scripts path/collector.ps1',
			$path,
			$path . '.stop',
		);

		expect(
			in_array( $path, $command, true ),
			'Never shell-interpolate paths.',
		);
		expect(
			! in_array( '-Command', $command, true ),
			'Pass a script, not generated shell code.',
		);
		expect(
			null === find_executable( 'php; anything' ),
			'Reject shell expressions.',
		);
	},
	/**
	 * Checks missing providers are explicit and never zero degrees.
	 *
	 * @return void
	 */
	'telemetry: missing providers are explicit and never zero degrees' => function (): void {

		$result = stop_telemetry( [
			'supported' => false,
			'reason'    => 'permission_denied',
		] );

		expect(
			false === $result['available'] && 'permission_denied' === $result['reason'],
			'Preserve the missing-provider reason.',
		);
		expect(
			! array_key_exists( 'temperature', $result ),
			'Do not invent a temperature.',
		);
	},
	/**
	 * Checks numeric ranges preserve sources and units.
	 *
	 * @return void
	 */
	'telemetry: numeric ranges preserve sources and units' => function (): void {

		$rows = [];

		foreach ( [ 9, 1, 5, 3 ] as $value )
			$rows[] = [ 'metrics' => [ 'cpu_busy' => environment_metric( $value ) ] ];

		$metric = telemetry_coverage( $rows )['cpu_busy'];

		expect(
			[
				'count'  => 4,
				'min'    => 1,
				'median' => 4,
				'max'    => 9,
			] === $metric['numeric'],
			'Use the complete observed numeric distribution.',
		);
		expect(
			[ 'OS counter' ] === $metric['sources'] && [ 'percent' ] === $metric['units'],
			'Carry provenance into the summary.',
		);

		$rows[] = [
			'metrics' => [
				'cpu_busy' => environment_metric(
					null,
					'percent',
					'permission_denied',
				),
			],
		];
		$metric = telemetry_coverage( $rows )['cpu_busy'];

		expect(
			4 === $metric['available'] && 1 === $metric['unavailable'] && 4 === $metric['numeric']['median'],
			'Missing readings are not zero-valued samples.',
		);
	},
	/**
	 * Checks incompatible units and nonfinite data are not combined.
	 *
	 * @return void
	 */
	'telemetry: incompatible units and nonfinite data are not combined' => function (): void {

		$coverage = telemetry_coverage(
			[
				[ 'metrics' => [ 'temperature' => environment_metric( 40, 'celsius' ) ] ],
				[ 'metrics' => [ 'temperature' => environment_metric( 104, 'fahrenheit' ) ] ],
				[ 'metrics' => [ 'counter' => environment_metric( INF, 'count' ) ] ],
			],
		);

		expect(
			$coverage['temperature']['mixed_units'] && null === $coverage['temperature']['numeric'],
			'Do not average unlike units.',
		);
		expect(
			0 === $coverage['counter']['available'] && 3 === $coverage['counter']['unavailable'],
			'Reject nonfinite values and count omitted readings as missing.',
		);
	},
	/**
	 * Checks absent and malformed metrics cannot inflate coverage.
	 *
	 * @return void
	 */
	'telemetry: absent and malformed metrics cannot inflate coverage' => function (): void {

		$rows   = [
			[ 'metrics' => [ 'cpu_busy' => environment_metric( 20 ) ] ],
			[ 'metrics' => [] ],
			[ 'metrics' => [ 'cpu_busy' => environment_metric( [ 0 ] ) ] ],
			[ 'metrics' => [ 'cpu_busy' => [ 'value' => 0 ] ] ],
		];
		$metric = telemetry_coverage( $rows )['cpu_busy'];

		expect(
			1 === $metric['available'] && 3 === $metric['unavailable'],
			'Only complete scalar readings count as available.',
		);
		expect(
			20 === $metric['numeric']['median'] && ! $metric['mixed_units'],
			'Missing metadata does not invent a unit or a zero.',
		);

		$large = telemetry_coverage(
			[
				[ 'metrics' => [ 'counter' => environment_metric( PHP_FLOAT_MAX, 'count' ) ] ],
				[ 'metrics' => [ 'counter' => environment_metric( PHP_FLOAT_MAX, 'count' ) ] ],
			],
		);
		expect(
			PHP_FLOAT_MAX === $large['counter']['numeric']['median'],
			'Finite readings must retain a finite median.',
		);
	},
	/**
	 * Checks impossible dates cannot enter measured windows.
	 *
	 * @return void
	 */
	'telemetry: impossible dates cannot enter measured windows' => function (): void {

		foreach ( [ '2026-02-30T00:00:00Z', '2026-01-01T25:00:00Z', '2026-01-01T00:00:00+99:00' ] as $time )
			expect(
				null === telemetry_timestamp( $time ),
				'Reject normalized invalid timestamps.',
			);

		expect(
			null !== telemetry_timestamp( '2026-11-11T00:00:00.1234567Z' ),
			'Windows seven-digit fractional seconds remain supported.',
		);

		$rejected = false;

		try {
			summarize_telemetry_readings(
				[],
				[
					'measurement_start' => '2026-02-30T00:00:00Z',
					'measurement_end'   => '2026-03-03T00:00:00Z',
				],
			);
		} catch ( RuntimeException ) {
			$rejected = true;
		}

		expect(
			$rejected,
			'Reject invalid window boundaries instead of shifting them.',
		);
	},
	/**
	 * Checks measured-window conditions exclude startup spikes.
	 *
	 * @return void
	 */
	'telemetry: measured-window conditions exclude startup spikes' => function (): void {

		$rows = [];

		foreach ( [ [ '00', 90, 'critical' ], [ '31', 0.2, 'nominal' ], [ '33', 0.4, 'nominal' ] ] as [ $second, $cpu, $thermal ] ) {
			$rows[] = [
				'timestamp' => '2026-11-11T00:00:' . $second . 'Z',
				'metrics'   => [
					'collector_cpu'    => environment_metric(
						$cpu,
						'percent_of_one_logical_cpu',
					),
					'thermal_pressure' => environment_metric( $thermal, 'state' ),
				],
			];
		}

		$summary = summarize_telemetry_readings(
			$rows,
			[
				'measurement_start' => '2026-11-11T00:00:30Z',
				'measurement_end'   => '2026-11-11T00:00:34Z',
			],
		);

		expect(
			90 === $summary['coverage']['collector_cpu']['numeric']['max'],
			'Retain startup readings in complete evidence.',
		);
		expect(
			2 === $summary['measurement_samples']
			&& 0.4 === $summary['measurement_coverage']['collector_cpu']['numeric']['max'],
			'Limit the measured summary to declared timestamps.',
		);
		expect(
			! has_environment_condition(
				$summary,
				'telemetry_thermal_disturbance',
			)
			&& ! has_environment_condition(
				$summary,
				'telemetry_observer_cpu',
			),
			'Startup events must not masquerade as timed-run contamination.',
		);
	},
	/**
	 * Checks thermal and power disturbances remain visible.
	 *
	 * @return void
	 */
	'telemetry: thermal and power disturbances remain visible' => function (): void {

		$rows = [];

		foreach ( [ 'nominal', 'critical' ] as $index => $state ) {
			$rows[] = [
				'timestamp' => '2026-11-11T00:00:0' . $index . 'Z',
				'metrics'   => [
					'thermal_pressure' => environment_metric( $state, 'state' ),
					'battery_saver'    => environment_metric( (bool) $index, 'boolean' ),
					'collector_cpu'    => environment_metric(
						4,
						'percent_of_one_logical_cpu',
					),
				],
			];
		}

		$summary = summarize_telemetry_readings(
			$rows,
			[
				'measurement_start' => '2026-11-11T00:00:00Z',
				'measurement_end'   => '2026-11-11T00:00:02Z',
			],
		);

		foreach ( [ 'telemetry_thermal_disturbance', 'telemetry_power_state_changed', 'telemetry_observer_cpu' ] as $code )
			expect(
				has_environment_condition( $summary, $code ),
				'Keep observed disturbance: ' . $code,
			);

		expect(
			[ false, true ] === $summary['coverage']['battery_saver']['states'],
			'Boolean states keep their types.',
		);
		expect(
			2 === $summary['measurement_samples'],
			'Keep disturbed observations.',
		);
	},
	/**
	 * Checks observer warning uses the declared unit.
	 *
	 * @return void
	 */
	'telemetry: observer warning uses the declared unit'   => function (): void {

		$summary = summarize_telemetry_readings(
			[ [ 'metrics' => [ 'collector_cpu' => environment_metric( 40, 'milliseconds' ) ] ] ],
		);

		expect(
			! has_environment_condition(
				$summary,
				'telemetry_observer_cpu',
			),
			'Forty milliseconds does not establish forty percent CPU utilization.',
		);
	},
	/**
	 * Checks macOS parser retains observation time across partial chunks.
	 *
	 * @return void
	 */
	'telemetry: macOS parser retains observation time across partial chunks' => function (): void {

		$script = dirname( __DIR__ ) . '/scripts/telemetry-macos.js';
		$node   = find_executable( 'node' );
		$checks = file_get_contents(
			__DIR__ . '/fixtures/telemetry/macos-parser-checks.js',
		);

		if ( null !== $node ) {
			$source = file_get_contents(
				__DIR__ . '/fixtures/telemetry/node-loader.js',
			) . $checks;
			$result = execute( [
				$node,
				'-e',
				$source,
				$script,
			] );
		} elseif ( PHP_OS_FAMILY === 'Darwin' ) {
			$source = strtr(
				file_get_contents(
					__DIR__ . '/fixtures/telemetry/jxa-loader.js',
				),
				[
					'__TSFB_SCRIPT_JSON__' => json_encode(
						$script,
						JSON_THROW_ON_ERROR,
					),
					'__TSFB_CHECKS_JSON__' => json_encode(
						"\n" . $checks,
						JSON_THROW_ON_ERROR,
					),
				],
			);
			$result = execute( [
				'/usr/bin/osascript',
				'-l',
				'JavaScript',
				'-e',
				$source,
			] );
		} else {
			echo "SKIP macOS parser execution: no installed JavaScript interpreter.\n";

			return;
		}

		expect(
			0 === $result['exit'],
			'The actual macOS parser failed its stream fixture: ' . $result['stderr'],
		);
	},
];
