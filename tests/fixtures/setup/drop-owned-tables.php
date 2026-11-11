<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Simulates mysqli cleanup without connecting to a database.
 *
 * The copied fixture runs with PHP's -n option and records connection arguments
 * and remaining tables even when the production script rejects the request.
 */

$script  = array_splice( $argv, 1, 1 )[0];
$fixture = json_decode(
	file_get_contents( $argv[1] ),
	true,
	512,
	JSON_THROW_ON_ERROR,
);
$state   = [
	'connected' => false,
	'mysqlnd'   => extension_loaded( 'mysqlnd' ),
	'tables'    => [
		$fixture['prefix'] . 'posts',
		'other_posts',
		'tsfb0123456789ab_p1_a10_posts',
	],
	'drops'     => [],
];

if ( ! function_exists( 'ctype_digit' ) ) {
	/**
	 * Supplies ctype's digit check when the extension is absent under PHP -n.
	 *
	 * @param string $value Candidate decimal port.
	 * @return bool Whether the string contains only decimal digits.
	 */
	function ctype_digit( string $value ): bool {
		return 1 === preg_match( '/\A[0-9]+\z/', $value );
	}
}

/**
 * Records cleanup calls against an in-memory table inventory.
 */
class mysqli {

	/**
	 * Captures the exact connection arguments without opening a connection.
	 *
	 * @param mixed ...$arguments Arguments from the production mysqli constructor.
	 */
	public function __construct( ...$arguments ) {

		$GLOBALS['state']['connected'] = true;
		$GLOBALS['state']['arguments'] = $arguments;
	}

	/**
	 * Implements only the table inventory and owned-table DROP operations.
	 *
	 * @param string $sql The cleanup statement under test.
	 * @return object|bool An inventory result, success, or a simulated DROP failure.
	 */
	public function query( string $sql ): object|bool {

		if ( 'SHOW TABLES' === $sql ) {
			/**
			 * Exposes the numeric rows expected from mysqli's default fetch mode.
			 */
			return new class {

				/**
				 * Reads the synthetic table inventory without mutating it.
				 *
				 * @return array Numeric rows containing one table name each.
				 */
				public function fetch_all(): array {
					return array_map(
						fn( string $table ): array => [ $table ],
						$GLOBALS['state']['tables'],
					);
				}
			};
		}

		if ( $GLOBALS['fixture']['fail_drop'] ?? false )
			return false;

		preg_match(
			'/\ADROP TABLE `([^`]+)`\z/',
			$sql,
			$matches,
		);
		$GLOBALS['state']['drops'][] = $matches[1];
		$GLOBALS['state']['tables']  = array_values( array_diff(
			$GLOBALS['state']['tables'],
			[ $matches[1] ],
		) );

		return true;
	}
}

/**
 * Saves observations after both ordinary completion and a production exit.
 *
 * @return void
 */
register_shutdown_function(
	function (): void {
		file_put_contents(
			__DIR__ . '/state.json',
			json_encode(
				$GLOBALS['state'],
				JSON_THROW_ON_ERROR,
			),
		);
	},
);

require $script;
