<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Template for a private, run-owned WordPress configuration.
 *
 * The renderer replaces only the three explicit markers below.
 */

/* TSFB_DEFINITIONS */

if (
	   PHP_SAPI !== 'cli'
	&& (
		! in_array(
			$_SERVER['REMOTE_ADDR'] ?? '',
			[ '127.0.0.1', '::1' ],
			true,
		)
		|| ! hash_equals(
			TSF_BENCH_TOKEN,
			$_SERVER['HTTP_X_TSF_BENCHMARK'] ?? '',
		)
	)
) {
	http_response_code( 403 );
	exit;
}

$table_prefix = /* TSFB_TABLE_PREFIX */ '';

// Keep ordinary PHP errors private without enabling WordPress debug code paths.
ini_set( 'log_errors', '1' );
ini_set( 'display_errors', '0' );
ini_set(
	'error_log',
	/* TSFB_ERROR_LOG */ '',
);

defined( 'ABSPATH' ) or define( 'ABSPATH', __DIR__ . '/' );

require_once ABSPATH . 'wp-settings.php';
