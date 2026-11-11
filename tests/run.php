<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Loads every helper declaration before executing regression cases.
 */

define( 'TSFB_TESTING', true );
require dirname( __DIR__ ) . '/benchmark.php';

/**
 * Fails a regression case with an actionable message.
 *
 * @param bool   $condition Required assertion.
 * @param string $message   Failure description.
 * @return void
 * @throws RuntimeException When the required assertion is false.
 */
function expect( bool $condition, string $message = 'Expectation failed' ): void {
	if ( ! $condition )
		throw new RuntimeException( $message );
}

// Warnings and notices are regressions unless a test explicitly suppresses them.
set_error_handler(
	/**
	 * Converts unsuppressed PHP diagnostics into test failures.
	 *
	 * @param int    $severity PHP diagnostic level.
	 * @param string $message  Diagnostic message.
	 * @param string $file     Source file.
	 * @param int    $line     Source line.
	 * @return bool False for explicitly suppressed diagnostics.
	 * @throws ErrorException When an unsuppressed PHP diagnostic occurs.
	 */
	function ( int $severity, string $message, string $file, int $line ): bool {

		if ( error_reporting() & $severity )
			throw new ErrorException(
				$message,
				0,
				$severity,
				$file,
				$line,
			);

		return false;
	},
);

$failed = 0;
$passed = 0;
$suite  = [];

foreach ( glob( __DIR__ . '/*.php' ) as $file ) {
	if ( 'run.php' === basename( $file ) ) continue;

	$tests = require $file;

	if ( ! is_array( $tests ) ) {
		fwrite(
			STDERR,
			'Invalid test file: ' . basename( $file ) . "\n",
		);
		++$failed;
		continue;
	}

	$suite[] = $tests;
}

// Load all helper declarations before executing any cross-file test cases.
foreach ( $suite as $tests ) {
	foreach ( $tests as $name => $test ) {
		try {
			$test();
			++$passed;
			echo "PASS $name\n";
		} catch ( Throwable $error ) {
			++$failed;
			fwrite(
				STDERR,
				"FAIL $name: " . $error->getMessage() . "\n",
			);
		}
	}
}

restore_error_handler();
echo "$passed passed; $failed failed.\n";
exit( $failed ? 1 : 0 );
