<?php
/**
 * Minimal WordPress filter harness for the real network guard and probe.
 *
 * @package TSF_WP_Benchmark
 */

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

define( 'TSF_BENCH_RUN', 'synthetic-network-test' );
define( 'TSF_BENCH_NETWORK_LOG', $argv[2] );
define( 'TSF_BENCH_NETWORK_MODE', $argv[3] ?? 'wordpress-http' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'DISABLE_WP_CRON', 'cron-enabled' !== ( $argv[4] ?? '' ) );
define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );

require dirname( $argv[1], 2 ) . '/tests/fixtures/network/error.php';

/**
 * @param mixed $value Filter result.
 * @return bool Whether the value is the WordPress error double.
 */
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

/**
 * Registers one synthetic hook with WordPress-compatible priority ordering.
 *
 * @param string          $name Hook name.
 * @param callable|string $callback Callback or a known WordPress return helper.
 * @param int             $priority Callback priority.
 * @param int             $accepted Accepted argument count.
 * @return void
 */
function add_filter( $name, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['tsfb_test_hooks'][ $name ][ $priority ][] = [ $callback, $accepted ];
}

/**
 * Registers an action without executing it.
 *
 * @param string          $name Hook name.
 * @param callable|string $callback Callback or a known WordPress return helper.
 * @param int             $priority Callback priority.
 * @param int             $accepted Accepted argument count.
 * @return void
 */
function add_action( $name, $callback, $priority = 10, $accepted = 1 ) {
	add_filter( $name, $callback, $priority, $accepted );
}

/**
 * Finds the registered priority for one WordPress-compatible callback.
 *
 * @param string          $name Hook name.
 * @param callable|string $callback Requested callback.
 * @return int|false Registered priority, or false when absent.
 */
function has_filter( $name, $callback ) {
	foreach ( $GLOBALS['tsfb_test_hooks'][ $name ] ?? [] as $priority => $callbacks )
		foreach ( $callbacks as [ $registered ] )
			if ( $registered === $callback ) return $priority;
	return false;
}

/**
 * Applies registered callbacks with each prior callback's result.
 *
 * @param string $name Hook name.
 * @param mixed  $value Initial filter value.
 * @param mixed  ...$args Remaining hook arguments.
 * @return mixed Final filter value.
 */
function apply_filters( $name, $value, ...$args ) {
	$hooks = $GLOBALS['tsfb_test_hooks'][ $name ] ?? [];
	ksort( $hooks, SORT_NUMERIC );
	foreach ( $hooks as $callbacks )
	foreach ( $callbacks as [ $callback, $accepted ] ) {
		if ( '__return_false' === $callback ) {
			$value = false;
		} elseif ( '__return_true' === $callback ) {
			$value = true;
		} else {
			$value = $callback( ...array_slice( [ $value, ...$args ], 0, $accepted ) );
		}
	}
	return $value;
}

/**
 * Records a canary call without making a network connection.
 *
 * @param mixed ...$args Production socket arguments.
 * @return false No actual connection is attempted.
 * @throws RuntimeException When the production canary signature changes.
 */
function tsfb_test_socket( ...$args ) {
	if ( 4 !== count( $args ) )
		throw new RuntimeException( 'Unexpected socket canary signature.' );
	$path  = TSF_BENCH_NETWORK_LOG . '.sockets';
	$count = is_file( $path ) ? (int) file_get_contents( $path ) : 0;
	file_put_contents( $path, (string) ( $count + 1 ) );
	return false;
}

// Ordinary plugin filters must not accidentally reopen HTTP, mail, or updates.
add_filter( 'pre_http_request', '__return_false', 10 );
add_filter( 'pre_wp_mail', '__return_true', 10 );
add_filter( 'automatic_updater_disabled', '__return_false', 10 );
foreach ( [ 'core', 'plugin', 'theme', 'translation' ] as $update_type ) {
	// Another plugin may already use WordPress's common false callback.
	add_filter( 'auto_update_' . $update_type, '__return_false', 5 );
	add_filter( 'auto_update_' . $update_type, '__return_true', 10 );
}

require $argv[1];

if ( 'override' === ( $argv[4] ?? '' ) ) {
	add_filter( 'pre_http_request', '__return_false', PHP_INT_MAX );
	add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
}
if ( 'updates-enabled' === ( $argv[4] ?? '' ) )
	add_filter( 'automatic_updater_disabled', '__return_false', PHP_INT_MAX );
if ( 'missing-log' === ( $argv[4] ?? '' ) )
	unlink( TSF_BENCH_NETWORK_LOG );

if ( 'contracts' === ( $argv[4] ?? '' ) ) {
	add_filter(
		'pre_http_request',
		/**
		 * Reads parsed HTTP fields as a normal plugin filter can do.
		 *
		 * @param mixed $pre Preemption value.
		 * @param array $args Parsed HTTP arguments.
		 * @return mixed Existing preemption value.
		 * @throws RuntimeException When the probe breaks the WordPress contract.
		 */
		function ( $pre, $args ) {
			if ( ! is_array( $args['headers'] ) || ! is_array( $args['cookies'] ) || ! is_string( $args['user-agent'] ) || ! is_int( $args['timeout'] ) || ! array_key_exists( 'limit_response_size', $args ) )
				throw new RuntimeException( 'Incomplete parsed HTTP arguments.' );
			return $pre;
		},
		20,
		2,
	);
	add_filter(
		'pre_wp_mail',
		/**
		 * Reads every mail field as a normal plugin filter can do.
		 *
		 * @param mixed $pre Preemption value.
		 * @param array $atts WordPress mail attributes.
		 * @return mixed Existing preemption value.
		 * @throws RuntimeException When the probe breaks the WordPress contract.
		 */
		function ( $pre, $atts ) {
			if ( ! is_array( $atts['to'] ) || ! is_string( $atts['subject'] ) || ! is_string( $atts['message'] ) || ! is_array( $atts['headers'] ) || ! is_array( $atts['attachments'] ) || ! is_array( $atts['embeds'] ) )
				throw new RuntimeException( 'Incomplete mail attributes.' );
			return $pre;
		},
		20,
		2,
	);
	foreach ( [ 'core', 'plugin', 'theme', 'translation' ] as $update_type )
		add_filter(
			'auto_update_' . $update_type,
			/**
			 * Requires a real update offer whenever an update filter is evaluated.
			 *
			 * @param mixed $update Proposed update decision.
			 * @param object $item Update offer, never null.
			 * @return mixed Existing update decision.
			 */
			function ( $update, object $item ) {
				return $item->autoupdate ?? $update;
			},
			20,
			2,
		);
}
