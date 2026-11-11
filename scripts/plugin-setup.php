<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Configures an activated fixture through the pinned plugins' native setup APIs.
 *
 * Run after activation in a fresh WordPress process with the fixture administrator
 * selected. Preserve enabled defaults, finish indexing where supported, and
 * decline tracking without connecting vendor accounts. The receipt supplements
 * frontend output qualification; it cannot replace it.
 *
 * @param array $arm     Approved arm with id, setup, and packages/main entries.
 * @param array $fixture Declared fixture identifiers and optional logo data.
 * @return array Feature, indexing, and privacy evidence without credentials.
 * @throws RuntimeException When setup or a required default cannot be verified.
 */
function tsfb_setup_plugin( array $arm, array $fixture ): array {

	$setup = $arm['setup'] ?? '';
	tsfb_setup_require(
		in_array( $setup, [ 'core', 'tsf', 'yoast', 'rankmath', 'aioseo', 'seopress', 'slim' ], true ),
		'Unknown plugin setup recipe.',
	);
	tsfb_setup_require(
		function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ),
		'Plugin setup requires the fixture administrator.',
	);
	$expected = array_column( $arm['packages'] ?? [], 'main' );
	$active   = get_option( 'active_plugins', [] );
	sort( $expected );
	sort( $active );
	tsfb_setup_require(
		$active === $expected,
		'The active plugins do not match the approved arm.',
	);
	if ( 'core' === $setup ) {
		tsfb_setup_require(
			[] === $active,
			'A WordPress-only control has active plugins.',
		);
		return [
			'setup'          => 'core',
			'active_plugins' => [],
			'features'       => [],
			'privacy'        => [],
		];
	}

	$receipt = [
		'setup'          => $setup,
		'active_plugins' => $active,
		'features'       => [],
		'privacy'        => [],
	];
	if ( 'yoast' === $setup ) {
		tsfb_setup_require(
			class_exists( 'WPSEO_Options' ),
			'Yoast options API is unavailable.',
		);
		$keys   = [ 'keyword_analysis_active', 'content_analysis_active', 'enable_text_link_counter', 'enable_schema', 'enable_xml_sitemap' ];
		$before = [];
		foreach ( $keys as $key ) {
			$before[ $key ] = WPSEO_Options::get( $key, null );
			tsfb_setup_require(
				true === $before[ $key ],
				'A default Yoast feature is disabled: ' . $key,
			);
		}
		tsfb_setup_rest( '/yoast/v1/configuration/site_representation',
			[
				'company_or_person'         => 'person',
				'company_or_person_user_id' => 1,
				'website_name'              => get_option( 'blogname' ),
				'person_logo'               => $fixture['logo_url'] ?? '',
				'person_logo_id'            => $fixture['logo_id'] ?? 0,
			] );
		tsfb_setup_rest(
			'/yoast/v1/configuration/social_profiles',
			[
				'facebook_site'     => '',
				'twitter_site'      => '',
				'other_social_urls' => [],
			],
		);
		tsfb_setup_rest(
			'/yoast/v1/configuration/enable_tracking',
			[ 'tracking' => false ],
		);
		tsfb_setup_rest( '/yoast/v1/indexing/prepare' );
		$receipt['indexing_batches'] = [];
		foreach ( [ 'indexing/posts', 'indexing/terms', 'indexing/post-type-archives', 'indexing/general', 'indexing/indexables-complete', 'link-indexing/posts', 'link-indexing/terms', 'indexing/complete' ] as $task ) {
			for ( $batch = 1; 100 >= $batch; ++$batch ) {
				$result = tsfb_setup_rest( '/yoast/v1/' . $task );
				if ( empty( $result['next_url'] ) ) {
					$receipt['indexing_batches'][ $task ] = $batch;
					break;
				}
			}
			tsfb_setup_require(
				isset( $receipt['indexing_batches'][ $task ] ),
				'Yoast indexing did not finish: ' . $task,
			);
		}
		$steps = [ 'optimizeSeoData', 'siteRepresentation', 'socialProfiles', 'personalPreferences' ];
		tsfb_setup_rest(
			'/yoast/v1/configuration/save_configuration_state',
			[ 'finishedSteps' => array_slice( $steps, 0, 3 ) ],
		);
		tsfb_setup_rest(
			'/yoast/v1/configuration/save_configuration_state',
			[ 'finishedSteps' => $steps ],
		);
		foreach ( $keys as $key ) {
			$receipt['features'][ $key ] = WPSEO_Options::get( $key, null );
			tsfb_setup_require(
				true === $receipt['features'][ $key ],
				'Yoast setup changed a default feature: ' . $key,
			);
		}
		$receipt['privacy']['usage_tracking'] = WPSEO_Options::get( 'tracking', null );
		tsfb_setup_require(
			false === $receipt['privacy']['usage_tracking'],
			'Yoast tracking opt-out was not retained.',
		);
		$receipt['wizard_steps'] = $steps;
	} elseif ( 'rankmath' === $setup ) {
		tsfb_setup_require(
			class_exists( '\RankMath\Admin\Setup_Wizard' ) && class_exists( '\RankMath\Helper' ),
			'Rank Math setup API is unavailable.',
		);
		update_option( 'rank_math_registration_skip', true );
		update_option( 'rank_math_mixpanel_optin', false );
		$before                  = (array) get_option( 'rank_math_modules', [] );
		$receipt['wizard_steps'] = [];
		foreach ( [ 'compatibility', 'yoursite', 'sitemaps', 'optimization', 'ready' ] as $step ) {
			$values = \RankMath\Admin\Setup_Wizard::get_localized_data( $step );
			tsfb_setup_require(
				is_array( $values ),
				'Rank Math wizard returned no defaults: ' . $step,
			);
			if ( 'yoursite' === $step ) {
				$values = array_replace( $values,
					[
						'site_type'       => 'blog',
						'company_name'    => 'Benchmark author',
						'website_name'    => get_option( 'blogname' ),
						'company_logo'    => $fixture['logo_url'] ?? '',
						'company_logo_id' => $fixture['logo_id'] ?? 0,
					] );
			}
			tsfb_setup_require(
				true === \RankMath\Admin\Setup_Wizard::save_data( $step, $values ),
				'Rank Math wizard save failed: ' . $step,
			);
			$receipt['wizard_steps'][] = $step;
		}
		$after = (array) get_option( 'rank_math_modules', [] );
		tsfb_setup_require(
			[] === array_diff( $before, $after ),
			'Rank Math setup disabled an active module.',
		);
		$receipt['features'] = [
			'active_modules' => array_values( $after ),
			'sitemap'        => \RankMath\Helper::is_module_active( 'sitemap' ),
			'configured'     => (bool) \RankMath\Helper::is_configured(),
		];
		tsfb_setup_require(
			true === $receipt['features']['sitemap'] && $receipt['features']['configured'],
			'Rank Math did not complete its declared default setup.',
		);
		$receipt['privacy']['usage_tracking'] = (bool) get_option( 'rank_math_mixpanel_optin', false );
		tsfb_setup_require(
			false === $receipt['privacy']['usage_tracking'],
			'Rank Math tracking opt-out was not retained.',
		);
	} elseif ( 'aioseo' === $setup ) {
		tsfb_setup_require(
			function_exists( 'aioseo' ),
			'AIOSEO API is unavailable.',
		);
		$companions = [ 'google-analytics-for-wordpress/googleanalytics.php', 'optinmonster/optin-monster-wp-api.php', 'broken-link-checker-seo/aioseo-broken-link-checker.php', 'universally-language-translation-multilingual-tool/universally.php' ];
		$bundle     = 0 < count( array_intersect( $active, $companions ) );
		tsfb_setup_require(
			! $bundle || count( array_intersect( $active, $companions ) ) === count( $companions ),
			'The default AIOSEO companion set is incomplete.',
		);
		tsfb_setup_require(
			'aioseo-default' !== ( $arm['id'] ?? '' ) || $bundle,
			'The AIOSEO default arm is missing its declared companions.',
		);
		$receipt['privacy']                        = tsfb_setup_companion_privacy( $active );
		aioseo()->options->advanced->usageTracking = false;
		$features                                  = [ 'optimized-search-appearance', 'sitemaps' ];
		if ( $bundle ) {
			$features = [ 'aioseo-eeat', 'aioseo-image-seo', 'analytics', 'broken-link-checker', 'conversion-tools', 'optimized-search-appearance', 'sitemaps', 'translation' ];
		}
		$wizard                  = tsfb_setup_aioseo_wizard( $features, $fixture );
		$receipt['wizard_steps'] = [];
		foreach ( [
			'category'              => 'category',
			'additionalInformation' => 'additional-information',
			'features'              => 'features',
			'searchAppearance'      => 'search-appearance',
			'smartRecommendations'  => 'smart-recommendations',
			'success'               => 'success',
		] as $section => $stage ) {
			$wizard['currentStage'] = $stage;
			tsfb_setup_rest(
				'/aioseo/v1/wizard',
				[
					'section' => $section,
					'wizard'  => $wizard,
					'network' => false,
				],
			);
			$receipt['wizard_steps'][] = $section;
		}
		$receipt['features'] = [
			'wizard_selection'  => $features,
			'sitemap'           => aioseo()->options->sitemap->general->enable,
			'open_graph'        => aioseo()->options->social->facebook->general->enable,
			'twitter_cards'     => aioseo()->options->social->twitter->general->enable,
			'companions_active' => array_values( array_intersect( $active, $companions ) ),
		];
		foreach ( [ 'sitemap', 'open_graph', 'twitter_cards' ] as $feature ) {
			tsfb_setup_require(
				true === $receipt['features'][ $feature ],
				'A default AIOSEO feature is disabled: ' . $feature,
			);
		}
		$receipt['privacy']['usage_tracking'] = aioseo()->options->advanced->usageTracking;
		tsfb_setup_require(
			false === $receipt['privacy']['usage_tracking'],
			'AIOSEO tracking opt-out was not retained.',
		);
		$receipt['account_connection'] = 'No vendor account connected; account-dependent companion features remain unavailable.';
	} elseif ( 'tsf' === $setup ) {
		tsfb_setup_require(
			class_exists( '\The_SEO_Framework\Data\Plugin' ),
			'TSF options API is unavailable.',
		);
		foreach ( [ 'og_tags', 'twitter_tags', 'ld_json_enabled', 'sitemaps_output' ] as $key ) {
			$receipt['features'][ $key ] = (bool) \The_SEO_Framework\Data\Plugin::get_option( $key );
			tsfb_setup_require(
				$receipt['features'][ $key ],
				'A default TSF feature is disabled: ' . $key,
			);
		}
		$receipt['configuration'] = 'Native defaults retained; no setup wizard.';
	} elseif ( 'slim' === $setup ) {
		$container = $GLOBALS['slim_seo'] ?? null;
		tsfb_setup_require(
			is_object( $container ) && is_callable( [ $container, 'get_service' ] ),
			'Slim SEO container is unavailable.',
		);
		$settings = $container->get_service( 'settings' );
		tsfb_setup_require(
			is_object( $settings ) && is_callable( [ $settings, 'is_feature_active' ] ),
			'Slim SEO feature API is unavailable.',
		);
		foreach ( [ 'canonical_url', 'meta_title', 'meta_description', 'meta_robots', 'open_graph', 'twitter_cards', 'schema', 'sitemaps' ] as $key ) {
			$receipt['features'][ $key ] = (bool) $settings->is_feature_active( $key );
			tsfb_setup_require(
				$receipt['features'][ $key ],
				'A default Slim SEO feature is disabled: ' . $key,
			);
		}
		$receipt['configuration'] = 'Native defaults retained; no setup wizard.';
	} elseif ( 'seopress' === $setup ) {
		tsfb_setup_require(
			defined( 'SEOPRESS_VERSION' ) && function_exists( 'seopress_get_service' ),
			'SEOPress API is unavailable.',
		);
		$toggles = seopress_get_service( 'ToggleOption' );
		tsfb_setup_require(
			is_callable( [ $toggles, 'searchOptionByKey' ] ),
			'SEOPress module API is unavailable.',
		);
		foreach ( [ 'titles', 'social', 'xml-sitemap' ] as $key ) {
			$receipt['features'][ $key ] = $toggles->searchOptionByKey( $key );
			tsfb_setup_require(
				'1' === $receipt['features'][ $key ],
				'A default SEOPress module is disabled or uninitialized: ' . $key,
			);
		}
		$social  = get_option( 'seopress_social_option_name', [] );
		$sitemap = get_option( 'seopress_xml_sitemap_option_name', [] );
		$titles  = get_option( 'seopress_titles_option_name', [] );
		tsfb_setup_require(
			is_array( $social ) && is_array( $sitemap ) && is_array( $titles ),
			'SEOPress activation option storage is missing.',
		);
		$receipt['features']['open_graph']      = '1' === ( $social['seopress_social_facebook_og'] ?? null );
		$receipt['features']['twitter_cards']   = '1' === ( $social['seopress_social_twitter_card'] ?? null );
		$receipt['features']['sitemap_enabled'] = '1' === ( $sitemap['seopress_xml_sitemap_general_enable'] ?? null );
		foreach ( [ 'open_graph', 'twitter_cards', 'sitemap_enabled' ] as $key ) {
			tsfb_setup_require(
				$receipt['features'][ $key ],
				'A default SEOPress feature is disabled or uninitialized: ' . $key,
			);
		}
		foreach ( [ 'post', 'page' ] as $type ) {
			$template                                    = $titles['seopress_titles_single_titles'][ $type ] ?? [];
			$receipt['features'][ $type . '_templates' ] = ! empty( $template['title'] ) && ! empty( $template['description'] );
			tsfb_setup_require(
				$receipt['features'][ $type . '_templates' ],
				'SEOPress title/description defaults were not initialized: ' . $type,
			);
		}
		$receipt['inactive_module_toggles'] = [];
		foreach ( (array) get_option( 'seopress_toggle', [] ) as $key => $value ) {
			if ( '1' !== $value ) {
				$receipt['inactive_module_toggles'][] = $key;
			}
		}
		$receipt['features']['tag_archive_noindex'] = '1' === ( $titles['seopress_titles_tax_titles']['post_tag']['noindex'] ?? null );
		$receipt['configuration']                   = 'Native activation defaults verified without changing features; tag-archive indexing policy and inactive toggles are recorded separately.';
	}
	$receipt['requires_frontend_validation'] = true;
	return $receipt;
}

/**
 * Stops setup on an unmet contract, without substituting a faster configuration.
 *
 * @param bool   $condition Whether the expected contract holds.
 * @param string $message   Non-sensitive explanation for the private setup log.
 * @return void
 * @throws RuntimeException When the condition is false.
 */
function tsfb_setup_require( bool $condition, string $message ): void {
	if ( ! $condition )
		throw new RuntimeException( $message );
}

/**
 * Dispatches a local setup POST to the pinned Yoast or AIOSEO REST namespace.
 *
 * Internal dispatch preserves native permissions and validation. Normalize
 * Yoast's object responses and require an explicit successful HTTP status,
 * without copying vendor response contents into an error message.
 *
 * @param string $route Known local setup route, without a host or query string.
 * @param array  $body  JSON request parameters.
 * @return array The normalized response data, including any indexing next_url.
 * @throws RuntimeException When the route or response is invalid.
 */
function tsfb_setup_rest( string $route, array $body = [] ): array {

	tsfb_setup_require(
		(bool) preg_match(
			'/\A\/(?:yoast|aioseo)\/v1\/[a-zA-Z0-9_\/-]+\z/',
			$route,
		),
		'Unknown setup REST route.',
	);
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( json_encode( $body, JSON_THROW_ON_ERROR ) );
	$response = rest_do_request( $request );
	tsfb_setup_require(
		(
			   is_object( $response )
			&& is_callable( [ $response, 'get_status' ] )
			&& is_callable( [ $response, 'get_data' ] )
		),
		'Invalid setup REST response: ' . $route,
	);
	$data = $response->get_data();

	// Internal REST dispatch retains Yoast's stdClass response instead of decoding JSON.
	if ( $data instanceof stdClass )
		$data = (array) $data;

	tsfb_setup_require(
		(
			   200 === $response->get_status()
			&& is_array( $data )
			&& false !== ( $data['success'] ?? true )
		),
		'Setup REST request failed: ' . $route,
	);

	return $data;
}

/**
 * Declines companion usage tracking while preserving unrelated stored settings.
 *
 * These option keys were checked against the pinned companion source. External
 * isolation is still required during activation and setup because these options
 * do not control every possible outbound request.
 *
 * @param array $active Exact active plugin main-file paths.
 * @return array Privacy values indexed by active companion main-file path.
 * @throws RuntimeException When an option cannot be preserved or verified.
 */
function tsfb_setup_companion_privacy( array $active ): array {

	$privacy = [];
	foreach ( [
		'google-analytics-for-wordpress/googleanalytics.php' => [ 'monsterinsights_settings', 'anonymous_data' ],
		'optinmonster/optin-monster-wp-api.php' => [ 'optin_monster_api', 'usage_tracking' ],
		'universally-language-translation-multilingual-tool/universally.php' => [ 'universally_settings', 'usage_tracking' ],
	] as $plugin => [ $option, $key ] ) {
		if ( ! in_array( $plugin, $active, true ) ) continue;

		$value = get_option( $option, [] );
		tsfb_setup_require(
			is_array( $value ),
			'Unexpected companion privacy storage: ' . $option,
		);
		$value[ $key ] = false;
		update_option( $option, $value );
		$privacy[ $plugin ] = get_option( $option, [] )[ $key ] ?? null;
		tsfb_setup_require(
			false === $privacy[ $plugin ],
			'Companion tracking opt-out was not retained: ' . $plugin,
		);
	}

	return $privacy;
}

/**
 * Builds the AIOSEO wizard's public blog/person defaults for the declared arm.
 *
 * Account, email-report, and tracking consent remain declined. The caller sends
 * each native wizard section separately, including smartRecommendations, which
 * actually persists AIOSEO Lite's usage-tracking preference.
 *
 * @param array $features Declared standalone or default companion selections.
 * @param array $fixture  Fixture identifiers and optional logo data.
 * @return array Request data accepted by the pinned AIOSEO wizard.
 */
function tsfb_setup_aioseo_wizard( array $features, array $fixture ): array {
	return [
		'currentStage'           => 'category',
		'stages'                 => [
			'category',
			'additional-information',
			'features',
			'search-appearance',
			'smart-recommendations',
			'search-console',
			'license-key',
		],
		'importers'              => [],
		'category'               => [
			'category'        => 'blog',
			'categoryOther'   => null,
			'siteTitle'       => '#site_title #separator_sa #tagline',
			'metaDescription' => '#tagline',
		],
		'additionalInformation'  => [
			'siteRepresents'          => 'person',
			'person'                  => 1,
			'personName'              => null,
			'personLogo'              => $fixture['logo_url'] ?? '',
			'organizationName'        => '#site_title',
			'organizationDescription' => '#tagline',
			'organizationLogo'        => '',
			'phone'                   => null,
			'socialShareImage'        => '',
			'social'                  => [
				'profiles' => [
					'sameUsername'   => [
						'enable'   => false,
						'username' => null,
						'included' => [
							'facebookPageUrl',
							'twitterUrl',
							'tiktokUrl',
							'pinterestUrl',
							'instagramUrl',
							'youtubeUrl',
							'linkedinUrl',
						],
					],
					'urls'           => array_fill_keys(
						[
							'facebookPageUrl',
							'twitterUrl',
							'instagramUrl',
							'tiktokUrl',
							'pinterestUrl',
							'youtubeUrl',
							'linkedinUrl',
							'tumblrUrl',
							'yelpPageUrl',
							'soundCloudUrl',
							'wikipediaUrl',
							'myspaceUrl',
							'googlePlacesUrl',
							'wordPressUrl',
							'blueskyUrl',
							'threadsUrl',
						],
						null,
					),
					'additionalUrls' => null,
				],
			],
		],
		'features'               => $features,
		'searchAppearance'       => [
			'underConstruction'       => false,
			'postTypes'               => [
				'postTypes' => [
					'all'      => true,
					'included' => [ 'post', 'page', 'attachment', 'product' ],
				],
			],
			'multipleAuthors'         => true,
			'redirectAttachmentPages' => true,
			'emailReports'            => false,
		],
		'smartRecommendations'   => [
			'accountInfo'   => null,
			'usageTracking' => false,
		],
		'licenseKey'             => null,
		'showUsageTrackingModal' => false,
	];
}
