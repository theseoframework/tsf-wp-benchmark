<?php
/**
 * Exercises the real guard using the synthetic WordPress filter harness.
 *
 * @package TSF_WP_Benchmark
 */

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

require __DIR__ . '/wp-load.php';

$http                        = apply_filters(
	'pre_http_request',
	false,
	[
		'method'  => 'POST',
		'body'    => 'PRIVATE_BODY',
		'headers' => [ 'Authorization' => 'PRIVATE_HEADER' ],
	],
	'https://user:PRIVATE_PASSWORD@example.invalid/path?key=PRIVATE_QUERY#PRIVATE_FRAGMENT',
);
$mail                        = apply_filters( 'pre_wp_mail',
	null,
	[
		'to'      => 'PRIVATE_RECIPIENT',
		'subject' => 'PRIVATE_SUBJECT',
		'message' => 'PRIVATE_BODY',
	] );
$updates                     = [ 'disabled' => apply_filters( 'automatic_updater_disabled', false ) ];
foreach ( [ 'core', 'plugin', 'theme', 'translation' ] as $update_type )
	$updates[ $update_type ] = apply_filters( 'auto_update_' . $update_type, true );

echo json_encode( [
	'http'    => is_wp_error( $http ) ? $http->get_error_code() : $http,
	'mail'    => $mail,
	'updates' => $updates,
] );
