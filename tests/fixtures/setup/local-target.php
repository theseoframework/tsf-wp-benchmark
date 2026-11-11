<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Supplies a minimal Local/WP-CLI environment for private target export tests.
 *
 * The test runner copies this fixture into a disposable directory and passes
 * the production script path before its normal arguments.
 */

$script = array_splice( $argv, 1, 1 )[0];

define(
	'WP_CLI',
	'cli-disabled' !== ( $argv[2] ?? '' ),
);
define( 'ABSPATH', __DIR__ . '/site/' );
define( 'DB_HOST', 'localhost:12345' );
define( 'DB_NAME', 'private-database' );
define( 'DB_USER', 'private-user' );
define(
	'DB_PASSWORD',
	'bad-encoding' === ( $argv[2] ?? '' ) ? "\xFF" : 'private-password',
);

/**
 * Reproduces the output and terminating error behavior used by local-target.php.
 */
class WP_CLI {

	/**
	 * Reports a setup error and stops this isolated process.
	 *
	 * @param string $message Public diagnostic without credentials.
	 * @return void
	 */
	public static function error( string $message ): void {

		fwrite( STDERR, $message );
		exit( 1 );
	}

	/**
	 * Reports a successful configuration export.
	 *
	 * @param string $message Public success message.
	 * @return void
	 */
	public static function success( string $message ): void {
		echo $message;
	}
}

/**
 * Supplies a local URL without bootstrapping WordPress or making a request.
 *
 * @return string The synthetic site's URL.
 */
function home_url(): string {
	return 'http://127.0.0.1:12345';
}

$args = [ $argv[1] ];

require $script;
