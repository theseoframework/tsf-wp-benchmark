<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Run-owned HTTP probe protected by token and loopback checks in wp-config.php.
 */

require __DIR__ . '/wp-load.php';

header(
	'Content-Type: application/json',
);

try {
	$input = json_decode(
		file_get_contents( 'php://input' ),
		true,
		32,
		JSON_THROW_ON_ERROR,
	);

	switch ( $input['action'] ?? '' ) {
		case 'isolation':
			$results = [];

			foreach ( [ '1.1.1.1', '8.8.8.8' ] as $host ) {
				$socket           = @stream_socket_client(
					'tcp://' . $host . ':443',
					$errno,
					$error,
					2,
				);
				$results[ $host ] = is_resource( $socket ) ? 'reachable' : 'blocked_or_unreachable';

				if ( is_resource( $socket ) )
					fclose( $socket );
			}

			$data = [
				'blocked' => ! in_array( 'reachable', $results, true ),
				'probes'  => $results,
				'scope'   => 'TCP canaries supplement operator verification of enforced isolation; they do not prove arbitrary egress is impossible.',
			];
			break;

		case 'context':
			if ( ! in_array(
				$input['context'] ?? '',
				[ 'blog', 'static' ],
				true,
			) ) {
				throw new RuntimeException( 'Invalid context.' );
			}

			$fixture = get_option( 'tsfb_fixture' );

			if (
				   ! is_array( $fixture )
				|| ! isset( $fixture['home_id'], $fixture['blog_id'] )
			) {
				throw new RuntimeException(
					'The declared fixture is not installed.',
				);
			}

			update_option(
				'show_on_front',
				'blog' === $input['context'] ? 'posts' : 'page',
			);
			update_option(
				'page_on_front',
				$fixture['home_id'],
			);
			update_option(
				'page_for_posts',
				$fixture['blog_id'],
			);
			$data = [ 'context' => $input['context'] ];
			break;

		case 'runtime':
			$ini  = [];
			$keys = [
				'opcache.enable',
				'opcache.file_cache',
				'opcache.file_cache_fallback',
				'opcache.file_cache_only',
				'opcache.jit',
				'opcache.jit_buffer_size',
				'opcache.max_accelerated_files',
				'opcache.memory_consumption',
				'opcache.revalidate_freq',
				'opcache.validate_timestamps',
			];

			foreach ( $keys as $key )
				$ini[ $key ] = ini_get( $key );

			$debug = [];

			foreach ( [ 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG' ] as $key )
				$debug[ $key ] = defined( $key ) ? (bool) constant( $key ) : null;

			// A nonempty string can configure a debug-log path; never expose it.
			$debug['WP_DEBUG_LOG'] = defined( 'WP_DEBUG_LOG' )
				? (
					in_array(
						strtolower( (string) WP_DEBUG_LOG ),
						[ 'true', '1' ],
						true,
					)
					|| ( is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG !== '' )
				)
				: null;

			// WordPress leaves SAVEQUERIES undefined when recording is disabled.
			$debug['SAVEQUERIES'] = defined( 'SAVEQUERIES' ) && (bool) SAVEQUERIES;

			$xdebug_loaded = extension_loaded( 'xdebug' );

			// An unavailable effective-mode query must not masquerade as "off".
			$xdebug_modes = function_exists( 'xdebug_info' )
				? xdebug_info( 'mode' )
				: ( $xdebug_loaded ? null : [] );

			$data = [
				'php_version'           => PHP_VERSION,
				'sapi'                  => PHP_SAPI,
				'extensions'            => get_loaded_extensions(),
				'wordpress_debug'       => $debug,
				'wordpress_environment' => wp_get_environment_type(),
				'php_diagnostics'       => [
					'log_errors'      => filter_var(
						ini_get( 'log_errors' ),
						FILTER_VALIDATE_BOOLEAN,
					),
					'display_errors'  => ! in_array(
						strtolower(
							(string) ini_get( 'display_errors' ),
						),
						[ '', '0', 'off', 'false', 'no' ],
						true,
					),
					'error_reporting' => error_reporting(),
				],
				'xdebug'                => [
					'loaded'          => $xdebug_loaded,
					'effective_modes' => $xdebug_modes,
					'ini_mode'        => ini_get( 'xdebug.mode' ),
					'env_mode'        => getenv( 'XDEBUG_MODE' ) ?: null,
				],
				'opcache'               => [
					'ini'    => $ini,
					'status' => function_exists( 'opcache_get_status' )
						? opcache_get_status( false )
						: false,
				],
				'wordpress'             => $GLOBALS['wp_version'],
				'database'              => $GLOBALS['wpdb']->db_version(),
				'server'                => $_SERVER['SERVER_SOFTWARE'] ?? null,
				'theme'                 => [
					'slug'    => get_stylesheet(),
					'version' => wp_get_theme()->get( 'Version' ),
				],
				'active_plugins'        => get_option( 'active_plugins' ),
				'object_cache'          => wp_using_ext_object_cache(),
				'page_cache'            => defined( 'WP_CACHE' ) && WP_CACHE,
				'fixture'               => get_option( 'tsfb_fixture' ),
			];
			break;

		default:
			throw new RuntimeException( 'Unknown probe action.' );
	}

	echo json_encode(
		[
			'ok'   => true,
			'data' => $data,
		],
		JSON_THROW_ON_ERROR,
	);
} catch ( Throwable $exception ) {
	http_response_code( 500 );
	echo json_encode( [
		'ok'    => false,
		'error' => $exception->getMessage(),
	] );
}
