<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Installs, activates, or configures one previously approved owned fixture.
 *
 * The parent runner validates target ownership and network isolation. Each
 * invocation runs in a fresh PHP process so newly activated plugins bootstrap
 * normally before their setup APIs are called.
 */

if ( PHP_SAPI !== 'cli' )
	exit( 1 );

require_once dirname( __DIR__ ) . '/src/FixtureRecipe.php';

try {
	$config = json_decode(
		file_get_contents( $argv[1] ?? '' ),
		true,
		64,
		JSON_THROW_ON_ERROR,
	);

	if (
		   ! is_array( $config )
		|| ! in_array( $config['action'] ?? '', [ 'install', 'activate', 'configure' ], true )
	) {
		throw new RuntimeException( 'Unknown fixture operation.' );
	}

	$url = parse_url( $config['url'] );

	if (
		   ! is_array( $url )
		|| ! isset( $url['host'], $url['scheme'] )
		|| ! in_array( $url['scheme'], [ 'http', 'https' ], true )
	) {
		throw new RuntimeException( 'Invalid fixture URL.' );
	}

	if (
		   ! is_string( $config['result'] ?? null )
		|| '' === $config['result']
		|| str_contains( $config['result'], "\0" )
		|| file_exists( $config['result'] )
		|| is_link( $config['result'] )
		|| ! is_dir( dirname( $config['result'] ) )
	) {
		throw new RuntimeException(
			'The fixture result must use a new file in an existing directory.',
		);
	}

	define( 'WP_ADMIN', true );
	define( 'DOING_AJAX', true );

	$_SERVER['HTTP_HOST']       = $url['host'] . ( isset( $url['port'] ) ? ':' . $url['port'] : '' );
	$_SERVER['SERVER_NAME']     = $url['host'];
	$_SERVER['REQUEST_URI']     = rtrim( $url['path'] ?? '', '/' ) . '/';
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	$_SERVER['REQUEST_METHOD']  = 'GET';
	$_SERVER['SERVER_PORT']     = (string) ( $url['port'] ?? ( 'https' === $url['scheme'] ? 443 : 80 ) );
	$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
	$_SERVER['DOCUMENT_ROOT']   = $config['root'];

	if ( 'https' === $url['scheme'] )
		$_SERVER['HTTPS'] = 'on';

	if ( 'install' === $config['action'] ) {
		if ( ! isset( $config['recipe']['fixtures'] ) || ! is_array( $config['recipe']['fixtures'] ) )
			throw new RuntimeException( 'A declared fixture dataset is required.' );

		\TsfBenchmark\validate_fixture_recipe( $config['recipe']['fixtures'] );
		define( 'WP_INSTALLING', true );
	}

	require $config['root'] . '/wp-load.php';

	// Plain PHP has no WP-CLI bootstrap to provide admin installation helpers.
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	switch ( $config['action'] ) {
		case 'install':
			$dataset = $config['recipe']['fixtures'];

			if ( is_blog_installed() )
				throw new RuntimeException( 'Fixture database already exists.' );

			foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
				if ( str_starts_with( $table, $wpdb->prefix ) )
					throw new RuntimeException( 'Owned prefix collision.' );
			}

			$installation = wp_install(
				$dataset['title'],
				'benchmark-admin',
				'benchmark@example.invalid',
				true,
				'',
				bin2hex( random_bytes( 24 ) ),
			);

			if ( is_wp_error( $installation ) )
				throw new RuntimeException( 'WordPress installation failed.' );

			wp_set_current_user( 1 );
			update_option( 'active_plugins', [] );
			update_option( 'permalink_structure', '' );
			update_option( 'blog_public', 1 );
			update_option( 'blogdescription', $dataset['description'] );
			update_option( 'timezone_string', 'UTC' );
			update_option( 'gmt_offset', 0 );
			update_option( 'posts_per_page', $dataset['posts_per_page'] );
			update_option( 'posts_per_rss', $dataset['posts_per_page'] );
			switch_theme( $config['recipe']['theme']['slug'] );

			foreach (
				get_posts( [
					'post_type'   => 'any',
					'post_status' => 'any',
					'numberposts' => -1,
				] ) as $existing_post
			) {
				if ( ! wp_delete_post( $existing_post->ID, true ) )
					throw new RuntimeException( 'Cannot remove a default fixture post.' );
			}

			// Remove core's sample discussion and widget state with its sample posts.
			foreach (
				get_comments( [
					'status' => 'all',
					'number' => 0,
				] ) as $existing_comment
			) {
				if ( ! wp_delete_comment( $existing_comment->comment_ID, true ) )
					throw new RuntimeException( 'Cannot remove a default fixture comment.' );
			}

			update_option(
				'sidebars_widgets',
				[
					'wp_inactive_widgets' => [],
					'array_version'       => 3,
				],
			);

			/**
			 * Inserts one declared post and preserves its deterministic timestamps.
			 *
			 * @param string $type  WordPress post type.
			 * @param int    $index Zero-based position within that type.
			 * @return int The expected imported post ID.
			 * @throws RuntimeException When insertion changes the ID or timestamps cannot be restored.
			 */
			$insert = function ( string $type, int $index ) use ( $dataset, $wpdb ): int {

				$existing_post = \TsfBenchmark\fixture_post_data( $dataset, $type, $index );
				$result        = wp_insert_post( wp_slash( $existing_post ), true );

				if ( is_wp_error( $result ) )
					throw new RuntimeException( 'Cannot insert a fixture post.' );

				if ( $result !== $existing_post['import_id'] )
					throw new RuntimeException( 'WordPress did not preserve the declared fixture post ID.' );

				// wp_insert_post can replace modification dates during insertion.
				$updated = $wpdb->update(
					$wpdb->posts,
					[
						'post_modified'     => $dataset['date'],
						'post_modified_gmt' => $dataset['date'],
					],
					[ 'ID' => $result ],
					[ '%s', '%s' ],
					[ '%d' ],
				);

				if ( false === $updated )
					throw new RuntimeException( 'Cannot fix fixture modification timestamps.' );

				clean_post_cache( $result );

				return $result;
			};

			$category = wp_insert_term(
				'Benchmark category',
				'category',
				[ 'slug' => 'benchmark-category' ],
			);
			$tag_term = wp_insert_term(
				'Benchmark tag',
				'post_tag',
				[ 'slug' => 'benchmark-tag' ],
			);

			if ( is_wp_error( $category ) || is_wp_error( $tag_term ) )
				throw new RuntimeException( 'Cannot create fixture terms.' );

			for ( $i = 0; $i < $dataset['posts']; ++$i ) {
				$fixture_post_id = $insert( 'post', $i );

				foreach ( [
					'category' => $category,
					'post_tag' => $tag_term,
				] as $taxonomy_name => $taxonomy_term ) {
					$assigned = wp_set_post_terms(
						$fixture_post_id,
						[ $taxonomy_term['term_id'] ],
						$taxonomy_name,
					);

					if ( is_wp_error( $assigned ) )
						throw new RuntimeException( 'Cannot assign a fixture term.' );
				}
			}

			for ( $i = 0; $i < $dataset['pages']; ++$i )
				$insert( 'page', $i );

			// The declared dataset reserves these fixed IDs for the measured routes.
			$fixture = [
				'home_id'     => 2001,
				'blog_id'     => 2002,
				'post_id'     => 1001,
				'page_id'     => 2003,
				'category_id' => $category['term_id'],
				'tag_id'      => $tag_term['term_id'],
				'logo_id'     => 0,
				'logo_url'    => '',
			];
			update_option( 'tsfb_fixture', $fixture );
			update_option( 'rank_math_registration_skip', true );
			update_option( 'rank_math_mixpanel_optin', false );
			update_option( 'monsterinsights_settings', [ 'anonymous_data' => false ] );
			update_option( 'universally_settings', [ 'usage_tracking' => false ] );
			// OptinMonster defaults already opt out; pre-seeding skips its native defaults.
			$result = $fixture;
			break;

		case 'activate':
			wp_set_current_user( 1 );

			foreach ( $config['arm']['packages'] as $package ) {
				$header = get_plugin_data(
					WP_PLUGIN_DIR . '/' . $package['main'],
					false,
					false,
				);

				if ( $header['Version'] !== $package['version'] )
					throw new RuntimeException( 'Plugin version mismatch.' );

				$activation_error = activate_plugin( $package['main'] );

				if ( is_wp_error( $activation_error ) )
					throw new RuntimeException( 'Cannot activate the declared plugin.' );
			}

			$result = [ 'active_plugins' => get_option( 'active_plugins' ) ];
			break;

		case 'configure':
			wp_set_current_user( 1 );
			require __DIR__ . '/plugin-setup.php';
			$result = tsfb_setup_plugin(
				$config['arm'],
				get_option( 'tsfb_fixture' ),
			);
			break;
	}

	$json   = json_encode(
		$result,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
	) . "\n";
	$stream = @fopen( $config['result'], 'xb' );

	if ( false === $stream )
		throw new RuntimeException( 'Cannot create the fixture result file.' );

	if ( 'Windows' !== PHP_OS_FAMILY && ! chmod( $config['result'], 0600 ) ) {
		fclose( $stream );
		unlink( $config['result'] );
		throw new RuntimeException(
			'Cannot restrict fixture result permissions.',
		);
	}

	$written = fwrite( $stream, $json );
	$flushed = fflush( $stream );
	fclose( $stream );

	if ( strlen( $json ) !== $written || ! $flushed ) {
		unlink( $config['result'] );
		throw new RuntimeException( 'Cannot finish writing the fixture result.' );
	}
} catch ( Throwable $activation_error ) {
	fwrite( STDERR, $activation_error->getMessage() . "\n" );
	exit( 1 );
}
