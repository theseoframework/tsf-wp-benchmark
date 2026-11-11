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
 * Installed identically in every run-owned fixture. This supplements the
 * operator's enforced network policy; arbitrary sockets require that policy.
 */

if ( ! defined( 'TSF_BENCH_RUN' ) ) return;

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
add_filter( 'automatic_updater_disabled', '__return_true' );

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
