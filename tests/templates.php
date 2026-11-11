<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Exercises actual rendered PHP templates in isolated subprocesses.
 */

return [
	'configuration template preserves PHP literals and does not reinterpret markers' => function (): void {

		$root = runner_temp();

		try {
			$bootstrap = file_get_contents(
				__DIR__ . '/fixtures/config/wp-settings.php',
			);

			if ( false === $bootstrap )
				throw new RuntimeException(
					'Cannot read the configuration bootstrap fixture.',
				);

			\TsfBenchmark\write_bytes(
				"$root/wp-settings.php",
				$bootstrap,
			);

			foreach ( [
				'',
				"quote ' and \" and \\; exit( 9 );",
				"binary\0with\nnewlines",
				"/* TSFB_TABLE_PREFIX */ '' /* TSFB_ERROR_LOG */ ''",
			] as $password ) {
				$constants = [
					'DB_PASSWORD'      => $password,
					'WP_DEBUG'         => false,
					'WP_DEBUG_DISPLAY' => false,
					'TSF_BENCH_TOKEN'  => 'synthetic-only',
				];
				$prefix    = 'tsfb_synthetic_';
				$log       = "$root/private.log";
				\TsfBenchmark\write_bytes(
					"$root/wp-config.php",
					\TsfBenchmark\render_fixture_config(
						$constants,
						$prefix,
						$log,
					),
				);
				$result = \TsfBenchmark\execute(
					[ PHP_BINARY, "$root/wp-config.php" ],
				);
				expect(
					0 === $result['exit'] && '' === $result['stderr'],
					'The rendered configuration failed to execute: ' . $result['stderr'],
				);
				$values = json_decode(
					$result['stdout'],
					true,
					512,
					JSON_THROW_ON_ERROR,
				);
				expect( [
					'password'      => $password,
					'prefix'        => $prefix,
					'debug'         => false,
					'debug_display' => false,
					'error_log'     => $log,
				] === $values );
			}
		} finally {
			\TsfBenchmark\remove_owned_tree(
				$root,
				dirname( $root ),
			);
		}
	},
	'capability script reports its selected PHP runtime without WordPress' => function (): void {

		$result = \TsfBenchmark\execute(
			[
				PHP_BINARY,
				dirname( __DIR__ ) . '/scripts/php-capabilities.php',
			],
		);
		expect( 0 === $result['exit'] );
		$values = json_decode(
			$result['stdout'],
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		expect(
			PHP_VERSION === $values['version'] && is_bool( $values['mysqli'] ),
			'The standalone capability script must report the selected executable.',
		);
	},
];
