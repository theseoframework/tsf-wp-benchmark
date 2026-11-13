<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Blocks WordPress HTTP/mail calls and identifies fresh fixture responses.
 *
 * Installed identically in every run-owned fixture. These hooks do not control
 * direct cURL or sockets. External network isolation is an optional extra.
 */

if ( ! defined( 'TSF_BENCH_RUN' ) ) return;

/**
 * Retains refused-call metadata outside the served site, without message bodies.
 *
 * URL user information, query values, and fragments are omitted. The private
 * log is not part of a public evidence export. Failed retention fails the call
 * visibly instead of silently claiming complete request coverage.
 *
 * @param string $kind HTTP or mail.
 * @param array  $metadata Nonsecret request context.
 * @return void
 * @throws RuntimeException When the private log cannot be written.
 */
function tsfb_log_blocked_call( string $kind, array $metadata = [] ): void {

	if (
		   ! defined( 'TSF_BENCH_NETWORK_LOG' )
		|| ! is_file( TSF_BENCH_NETWORK_LOG )
		|| is_link( TSF_BENCH_NETWORK_LOG )
	) {
		throw new RuntimeException( 'Private blocked-call log is unavailable.' );
	}
	$line = json_encode(
		[
			'time' => gmdate( 'c' ),
			'kind' => $kind,
		] + $metadata,
		JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
	) . "\n";
	if ( strlen( $line ) !== file_put_contents( TSF_BENCH_NETWORK_LOG, $line, FILE_APPEND | LOCK_EX ) )
		throw new RuntimeException( 'Cannot retain blocked-call metadata.' );
}

add_filter(
	'pre_http_request',
	/**
	 * Prevents WordPress-managed outbound HTTP before another filter runs.
	 *
	 * @return WP_Error The deliberate offline failure.
	 */
	function () {
		return new WP_Error(
			'benchmark_offline',
			'Outbound HTTP is blocked in this fixture.',
		);
	},
	PHP_INT_MIN,
);
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MIN );

// Keep the early block visible to plugin filters and restore it after them.
add_filter(
	'pre_http_request',
	/**
	 * Blocks and records WordPress HTTP after ordinary plugin filters run.
	 *
	 * @param mixed $pre Existing preemption result.
	 * @param array $args HTTP arguments; body and headers are never recorded.
	 * @param string $url Requested URL.
	 * @return WP_Error Deliberate blocked response.
	 */
	function ( $pre, $args, $url ) {

		$parts = parse_url( $url );
		tsfb_log_blocked_call( 'http',
			[
				'method' => $args['method'] ?? 'GET',
				'scheme' => $parts['scheme'] ?? '',
				'host'   => $parts['host'] ?? '',
				'path'   => $parts['path'] ?? '',
			] );

		return new WP_Error( 'benchmark_offline', 'Outbound WordPress HTTP is blocked in this fixture.' );
	},
	PHP_INT_MAX,
	3,
);
add_filter(
	'pre_wp_mail',
	/**
	 * Blocks mail without retaining recipients, subject, or content.
	 *
	 * @return false Deliberate blocked result.
	 */
	function () {
		tsfb_log_blocked_call( 'mail' );
		return false;
	},
	PHP_INT_MAX,
);

/**
 * Disables individual update offers with an identifiable fixture callback.
 *
 * @return false Updates remain disabled in this fixture.
 */
function tsfb_prevent_auto_update() {
	return false;
}

add_filter( 'automatic_updater_disabled', '__return_true', PHP_INT_MAX );
foreach ( [ 'core', 'plugin', 'theme', 'translation' ] as $tsfb_update_type )
	add_filter( 'auto_update_' . $tsfb_update_type, 'tsfb_prevent_auto_update', PHP_INT_MAX );
unset( $tsfb_update_type );

add_action(
	'wp',
	/**
	 * Identifies the resolved query after WordPress has built its main query.
	 *
	 * The request token lets the collector reject cached or mismatched responses.
	 *
	 * @return void
	 */
	function () {

		if ( is_404() ) {
			$route = '404';
		} elseif ( is_search() ) {
			$route = 'search';
		} elseif ( is_category() ) {
			$route = 'category';
		} elseif ( is_tag() ) {
			$route = 'tag';
		} elseif ( is_home() ) {
			$route = is_front_page() ? 'home-blog' : 'blog-page';
		} elseif ( is_front_page() ) {
			$route = 'home-static';
		} elseif ( is_single() ) {
			$route = 'post';
		} elseif ( is_page() ) {
			$route = 'page';
		} else {
			$route = 'other';
		}
		header( 'X-TSF-Route: ' . $route );
		header(
			'X-TSF-Request: ' . preg_replace(
				'/[^a-f0-9]/',
				'',
				$_SERVER['HTTP_X_TSF_REQUEST'] ?? '',
			),
		);
		header( 'Cache-Control: no-store' );
	},
	PHP_INT_MAX,
);
