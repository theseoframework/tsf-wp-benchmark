<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Synthetic HTTP transport fixture; never WordPress or benchmark evidence.
 */

if ( 'synthetic-test-token' !== ( $_SERVER['HTTP_X_TSF_BENCHMARK'] ?? '' ) ) {
	http_response_code( 403 );
	exit;
}

if ( str_starts_with(
	$_SERVER['REQUEST_URI'],
	'/redirect',
) ) {
	header(
		'Location: https://example.invalid/',
	);
	http_response_code( 302 );
	exit;
}

// A fixed 25 ms server delay verifies the measured interval includes server work.
if ( str_starts_with(
	$_SERVER['REQUEST_URI'],
	'/slow',
) ) {
	usleep( 25000 );
}

header( 'Content-Type: text/html' );
header(
	'X-TSF-Request: ' . ( $_SERVER['HTTP_X_TSF_REQUEST'] ?? '' ),
);
header( 'X-TSF-Route: post' );
echo '<!doctype html><html><head><title>Synthetic fixture</title></head><body>Transport test only.</body></html>';
