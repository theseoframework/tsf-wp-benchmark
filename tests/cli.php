<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Literal CLI argument parsing without executing benchmark commands.
 */

return [
	/**
	 * Checks that malformed options are rejected before any command executes.
	 *
	 * @return void
	 */
	'CLI rejects unknown, repeated, misplaced and empty options before execution' => function (): void {

		$invalid = [
			[ 'run', '--run', 'fixture', '--dry-run', 'yes' ],
			[ 'run', '--run', 'first', '--run', 'second' ],
			[ 'doctor', '--run', 'fixture' ],
			[ 'prepare', 'fixture' ],
			[ 'prepare', '--run' ],
			[ 'prepare', '--run', '' ],
			[ 'plan', '--recipe', '--target', 'fixture' ],
			[ 'unknown' ],
		];

		foreach ( $invalid as $arguments ) {
			$threw = false;

			try {
				\TsfBenchmark\parse_arguments( $arguments );
			} catch ( RuntimeException ) {
				$threw = true;
			}

			expect(
				$threw,
				'Unsafe arguments were accepted: ' . json_encode( $arguments ),
			);
		}
	},
	/**
	 * Checks that spaces stay within option values and an empty command asks for help.
	 *
	 * @return void
	 */
	'CLI preserves spaced paths and requires explicit command options' => function (): void {

		expect(
			[ 'help', [] ] === \TsfBenchmark\parse_arguments( [] ),
		);
		expect(
			[
				'report',
				[
					'run' => 'path with spaces',
					'out' => 'new directory',
				],
			] === \TsfBenchmark\parse_arguments( [
				'report',
				'--run',
				'path with spaces',
				'--out',
				'new directory',
			] ),
		);
	},
];
