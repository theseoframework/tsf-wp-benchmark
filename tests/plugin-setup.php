<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

// Load the API doubles before setup so WordPress never needs to bootstrap.
require_once __DIR__ . '/fixtures/plugins/setup-apis.php';
require_once dirname( __DIR__ ) . '/scripts/plugin-setup.php';

/**
 * Resets simulated WordPress state and builds the requested declared arm.
 *
 * @param string $setup The plugin setup identifier.
 * @param array  $mains Declared active plugin entrypoints.
 * @return array The arm identifier, setup handler, and declared package list.
 */
function tsfb_setup_test_arm( string $setup, array $mains = [] ): array {

	$GLOBALS['tsfb_setup_test'] = [
		'administrator'  => true,
		'options'        => [
			'active_plugins' => $mains,
			'blogname'       => 'Custom fixture title',
		],
		'yoast_features' => array_fill_keys(
			[
				'content_analysis_active',
				'enable_schema',
				'enable_text_link_counter',
				'enable_xml_sitemap',
				'keyword_analysis_active',
				'tracking',
			],
			true,
		),
		'requests'       => [],
		'aioseo'         => (object) [
			'options' => (object) [
				'advanced' => (object) [ 'usageTracking' => true ],
				'sitemap'  => (object) [
					'general' => (object) [ 'enable' => true ],
				],
				'social'   => (object) [
					'facebook' => (object) [
						'general' => (object) [ 'enable' => true ],
					],
					'twitter'  => (object) [
						'general' => (object) [ 'enable' => true ],
					],
				],
			],
		],
	];

	return [
		'id'       => $setup,
		'setup'    => $setup,
		'packages' => array_map(
			fn( string $main ): array => [ 'main' => $main ],
			$mains,
		),
	];
}

/**
 * Requires a callback to fail with the expected non-sensitive setup reason.
 *
 * @param callable $callback The setup operation expected to fail.
 * @param string   $message  The diagnostic substring expected from the operation.
 * @return void
 * @throws RuntimeException When setup succeeds or fails for an unexpected reason.
 */
function tsfb_setup_test_throws( callable $callback, string $message ): void {

	try {
		$callback();
	} catch ( RuntimeException $error ) {
		expect(
			str_contains( $error->getMessage(), $message ),
			'Unexpected setup error: ' . $error->getMessage(),
		);

		return;
	}

	throw new RuntimeException(
		"Expected setup to fail: $message",
	);
}

return [
	'plugin setup rejects unknown recipes, wrong actors, and undeclared active plugins' => function (): void {

		$arm = tsfb_setup_test_arm( 'core' );
		expect(
			[] === tsfb_setup_plugin( $arm, [] )['active_plugins'],
			'A clean control should pass.',
		);
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( [ 'setup' => 'unknown' ], [] ),
			'Unknown plugin setup',
		);
		$GLOBALS['tsfb_setup_test']['administrator'] = false;
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'fixture administrator',
		);
		$GLOBALS['tsfb_setup_test']['administrator']             = true;
		$GLOBALS['tsfb_setup_test']['options']['active_plugins'] = [ 'unexpected/plugin.php' ];
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'active plugins do not match',
		);
	},
	'Yoast setup completes all index tasks without disabling features' => function (): void {

		$arm                                    = tsfb_setup_test_arm(
			'yoast',
			[ 'wordpress-seo/wp-seo.php' ],
		);
		$GLOBALS['tsfb_setup_test']['response'] = fn(): array => [
			200,
			(object) [
				'success'  => true,
				'next_url' => null,
			],
		];
		$receipt                                = tsfb_setup_plugin(
			$arm,
			[
				'logo_id'  => 0,
				'logo_url' => '',
			],
		);
		expect(
			8 === count( $receipt['indexing_batches'] ),
			'All index tasks must complete.',
		);
		expect(
			false === $receipt['privacy']['usage_tracking'],
			'Tracking must be declined.',
		);
		expect(
			! in_array(
				false,
				$receipt['features'],
				true,
			),
			'Default features must remain enabled.',
		);
		expect(
			4 === count( $receipt['wizard_steps'] ),
			'The wizard stages must be recorded.',
		);
		expect(
			'Custom fixture title' === $GLOBALS['tsfb_setup_test']['requests'][0]['body']['website_name'],
			'Setup must use the declared fixture title.',
		);
	},
	'Yoast setup bounds unfinished indexing and rejects disabled defaults' => function (): void {

		$arm                                    = tsfb_setup_test_arm(
			'yoast',
			[ 'wordpress-seo/wp-seo.php' ],
		);
		$GLOBALS['tsfb_setup_test']['response'] = fn(): array => [
			200,
			[ 'next_url' => '/same-task' ],
		];
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'indexing did not finish',
		);
		$attempts                               = array_filter(
			$GLOBALS['tsfb_setup_test']['requests'],
			fn( array $row ): bool => '/yoast/v1/indexing/posts' === $row['route'],
		);
		expect(
			100 === count( $attempts ),
			'Unfinished indexing must stop at the fixed bound.',
		);
		$arm = tsfb_setup_test_arm(
			'yoast',
			[ 'wordpress-seo/wp-seo.php' ],
		);
		$GLOBALS['tsfb_setup_test']['yoast_features']['enable_schema'] = false;
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'default Yoast feature is disabled',
		);
	},
	'setup REST helper rejects external routes and failed responses' => function (): void {

		tsfb_setup_test_arm( 'core' );
		tsfb_setup_test_throws(
			fn() => tsfb_setup_rest(
				'https://vendor.example/collect',
			),
			'Unknown setup REST route',
		);
		$GLOBALS['tsfb_setup_test']['response'] = fn(): array => [
			403,
			[ 'message' => 'private response text' ],
		];
		tsfb_setup_test_throws(
			fn() => tsfb_setup_rest( '/aioseo/v1/wizard' ),
			'Setup REST request failed',
		);
		$GLOBALS['tsfb_setup_test']['response'] = fn(): array => [
			200,
			[ 'success' => false ],
		];
		tsfb_setup_test_throws(
			fn() => tsfb_setup_rest( '/aioseo/v1/wizard' ),
			'Setup REST request failed',
		);
		$GLOBALS['tsfb_setup_test']['response'] = fn(): array => [
			200,
			(object) [ 'success' => false ],
		];
		tsfb_setup_test_throws(
			fn() => tsfb_setup_rest(
				'/yoast/v1/configuration/site_representation',
			),
			'Setup REST request failed',
		);
		$GLOBALS['tsfb_setup_test']['response'] = fn(): array => [ 200, 'invalid response' ];
		tsfb_setup_test_throws(
			fn() => tsfb_setup_rest(
				'/yoast/v1/configuration/site_representation',
			),
			'Setup REST request failed',
		);
	},
	'Rank Math uses its wizard defaults and preserves active modules' => function (): void {

		$arm = tsfb_setup_test_arm(
			'rankmath',
			[ 'seo-by-rank-math/rank-math.php' ],
		);
		$GLOBALS['tsfb_setup_test']['options']['rank_math_modules'] = [ 'sitemap', 'rich-snippet', 'seo-analysis' ];
		$receipt = tsfb_setup_plugin( $arm, [] );
		expect(
			5 === count( $receipt['wizard_steps'] ),
			'All declared wizard steps must execute.',
		);
		expect(
			'yes' === $GLOBALS['tsfb_setup_test']['rank_steps']['sitemaps']['preserve_default'],
			'Native defaults must be retained.',
		);
		expect(
			true === $receipt['features']['configured'],
			'The wizard must complete.',
		);
		expect(
			false === $receipt['privacy']['usage_tracking'],
			'Tracking must remain off.',
		);
	},
	'AIOSEO standalone uses native wizard and never supplies an account' => function (): void {

		$arm     = tsfb_setup_test_arm(
			'aioseo',
			[ 'all-in-one-seo-pack/all_in_one_seo_pack.php' ],
		);
		$receipt = tsfb_setup_plugin( $arm, [] );
		expect(
			[ 'optimized-search-appearance', 'sitemaps' ] === $receipt['features']['wizard_selection'],
			'Standalone must not request companions.',
		);
		expect(
			in_array(
				'smartRecommendations',
				$receipt['wizard_steps'],
				true,
			),
			'The privacy step must actually be saved.',
		);

		foreach ( $GLOBALS['tsfb_setup_test']['requests'] as $row ) {
			expect(
				null === $row['body']['wizard']['smartRecommendations']['accountInfo'],
				'Do not connect an account.',
			);
			expect(
				false === $row['body']['wizard']['smartRecommendations']['usageTracking'],
				'Do not enable tracking.',
			);
		}
	},
	'AIOSEO bundle requires complete active companions and retains unrelated settings' => function (): void {

		$plugins   = [
			'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'google-analytics-for-wordpress/googleanalytics.php',
			'optinmonster/optin-monster-wp-api.php',
			'broken-link-checker-seo/aioseo-broken-link-checker.php',
			'universally-language-translation-multilingual-tool/universally.php',
		];
		$arm       = tsfb_setup_test_arm( 'aioseo', $plugins );
		$arm['id'] = 'aioseo-default';
		$GLOBALS['tsfb_setup_test']['options']['monsterinsights_settings'] = [
			'anonymous_data' => true,
			'preserve'       => 'setting',
		];
		$receipt = tsfb_setup_plugin( $arm, [] );
		expect(
			4 === count(
				$receipt['features']['companions_active'],
			),
			'All default companions must be active.',
		);
		expect(
			in_array(
				'translation',
				$receipt['features']['wizard_selection'],
				true,
			),
			'The companion selection must match the bundle.',
		);
		expect(
			'setting' === get_option( 'monsterinsights_settings' )['preserve'],
			'Unrelated settings must be retained.',
		);
		expect(
			false === get_option( 'monsterinsights_settings' )['anonymous_data'],
			'Tracking must be declined.',
		);
		$arm = tsfb_setup_test_arm(
			'aioseo',
			array_slice( $plugins, 0, 2 ),
		);
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'companion set is incomplete',
		);
	},
	'TSF validates feature defaults without writing configuration' => function (): void {

		$arm     = tsfb_setup_test_arm(
			'tsf',
			[ 'autodescription/autodescription.php' ],
		);
		$before  = $GLOBALS['tsfb_setup_test']['options'];
		$receipt = tsfb_setup_plugin( $arm, [] );
		expect(
			4 === count( $receipt['features'] ),
			'TSF default features must be checked.',
		);
		expect(
			$before === $GLOBALS['tsfb_setup_test']['options'],
			'TSF configuration must remain unchanged.',
		);
		$GLOBALS['tsfb_setup_test']['tsf_features']['og_tags'] = 0;
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'default TSF feature is disabled',
		);
	},
	'Slim and SEOPress require active native features without changing settings' => function (): void {

		$arm                 = tsfb_setup_test_arm(
			'slim',
			[ 'slim-seo/slim-seo.php' ],
		);
		$GLOBALS['slim_seo'] = new TSFB_Setup_Slim_Container();
		expect(
			8 === count( tsfb_setup_plugin( $arm, [] )['features'] ),
			'Slim feature state must be checked.',
		);
		$arm = tsfb_setup_test_arm(
			'seopress',
			[ 'wp-seopress/seopress.php' ],
		);
		$GLOBALS['tsfb_setup_test']['seopress_features'] = [
			'titles'      => '1',
			'social'      => '0',
			'xml-sitemap' => '1',
		];
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'default SEOPress module is disabled',
		);
		expect(
			'0' === $GLOBALS['tsfb_setup_test']['seopress_features']['social'],
			'Do not silently enable a disabled module.',
		);
		$GLOBALS['tsfb_setup_test']['seopress_features']['social']                 = '1';
		$GLOBALS['tsfb_setup_test']['options']['seopress_social_option_name']      = [
			'seopress_social_facebook_og'  => '1',
			'seopress_social_twitter_card' => '1',
		];
		$GLOBALS['tsfb_setup_test']['options']['seopress_xml_sitemap_option_name'] = [
			'seopress_xml_sitemap_general_enable' => '1',
		];
		$GLOBALS['tsfb_setup_test']['options']['seopress_titles_option_name']      = [
			'seopress_titles_single_titles' => [
				'post' => [
					'title'       => '%%post_title%%',
					'description' => '%%post_excerpt%%',
				],
				'page' => [
					'title'       => '%%post_title%%',
					'description' => '%%post_excerpt%%',
				],
			],
			'seopress_titles_tax_titles'    => [
				'post_tag' => [ 'noindex' => '1' ],
			],
		];
		$GLOBALS['tsfb_setup_test']['options']['seopress_toggle']                  = [
			'toggle-titles'   => '1',
			'toggle-optional' => '0',
		];
		$before  = $GLOBALS['tsfb_setup_test']['options'];
		$receipt = tsfb_setup_plugin( $arm, [] );
		expect(
			true === $receipt['features']['open_graph'],
			'SEOPress Open Graph must be enabled.',
		);
		expect(
			true === $receipt['features']['tag_archive_noindex'],
			'Record the default indexing-policy nuance.',
		);
		expect(
			[ 'toggle-optional' ] === $receipt['inactive_module_toggles'],
			'Record inactive optional toggles.',
		);
		expect(
			$before === $GLOBALS['tsfb_setup_test']['options'],
			'Do not rewrite native feature settings.',
		);
		expect(
			true === $receipt['requires_frontend_validation'],
			'Setup receipts cannot replace frontend validation.',
		);
		unset( $GLOBALS['tsfb_setup_test']['options']['seopress_social_option_name'] );
		tsfb_setup_test_throws(
			fn() => tsfb_setup_plugin( $arm, [] ),
			'default SEOPress feature is disabled or uninitialized',
		);
	},
];
