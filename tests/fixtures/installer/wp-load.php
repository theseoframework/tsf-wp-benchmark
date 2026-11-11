<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Minimal WordPress storage boundary for the isolated installer regression test.
 *
 * The test copies this source to its temporary wp-load.php. It must not load
 * WordPress, connect to a database, or run as part of the test harness itself.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['stub'] = [
	'inserted'       => [],
	'updates'        => [],
	'cache_cleared'  => [],
	'options'        => [],
	'server'         => $_SERVER,
	'wp_cli_defined' => defined( 'WP_CLI' ),
];

/**
 * Records installer SQL updates without opening a database connection.
 */
class FixtureDatabaseStub {
	/** @var string Synthetic table prefix used by fixture cleanup queries. */
	public $prefix = 'owned_';

	/** @var string Synthetic posts table used by timestamp updates. */
	public $posts = 'owned_posts';

	/**
	 * Returns no previously installed records.
	 *
	 * @param string $query Requested query.
	 * @return array Empty existing dataset.
	 */
	public function get_col( $query ) {
		return [];
	}

	/**
	 * Records the installer modification timestamp update.
	 *
	 * @param string $table        Target table.
	 * @param array  $data         Updated fields.
	 * @param array  $where        Record selector.
	 * @param array  $formats      Value formats.
	 * @param array  $where_formats Selector formats.
	 * @return int One modified record.
	 */
	public function update( $table, $data, $where, $formats, $where_formats ) {

		$GLOBALS['stub']['updates'][] = [
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		];

		return 1;
	}
}

$wpdb = new FixtureDatabaseStub();

/**
 * Declares an uninstalled site without querying a database.
 *
 * @return bool False for the isolated fixture.
 */
function is_blog_installed() {
	return false;
}

/**
 * Records the installation title.
 *
 * @param mixed ...$args Installation arguments.
 * @return void
 */
function wp_install( ...$args ) {
	$GLOBALS['stub']['install_title'] = $args[0];
}

/**
 * Accepts the synthetic administrator without initializing WordPress.
 *
 * @param int $id User ID.
 * @return void
 */
function wp_set_current_user( $id ) {}

/**
 * Records option writes.
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 * @return void
 */
function update_option( $name, $value ) {
	$GLOBALS['stub']['options'][ $name ] = $value;
}

/**
 * Records the declared theme.
 *
 * @param string $name Theme slug.
 * @return void
 */
function switch_theme( $name ) {
	$GLOBALS['stub']['theme'] = $name;
}

/**
 * Provides an empty existing post list.
 *
 * @param array $args Query arguments.
 * @return array No existing posts.
 */
function get_posts( $args ) {
	return [];
}

/**
 * Provides an empty existing comment list.
 *
 * @param array $args Query arguments.
 * @return array No existing comments.
 */
function get_comments( $args ) {
	return [];
}

/**
 * Supplies stable term IDs for category and tag setup.
 *
 * @param string $name     Term name.
 * @param string $taxonomy Taxonomy name.
 * @param array  $args     Term attributes.
 * @return array Synthetic term ID.
 */
function wp_insert_term( $name, $taxonomy, $args ) {
	return [
		'term_id' => 'category' === $taxonomy ? 2 : 3,
	];
}

/**
 * Confirms that these deterministic stubs return no WordPress errors.
 *
 * @param mixed $value Stub return value.
 * @return bool False.
 */
function is_wp_error( $value ) {
	return false;
}

/**
 * Reproduces WordPress's recursive slashing storage contract.
 *
 * @param mixed $value Field value or field map.
 * @return mixed Slashed value.
 */
function wp_slash( $value ) {
	return is_array( $value )
		? array_map( 'wp_slash', $value )
		: ( is_string( $value ) ? addslashes( $value ) : $value );
}

/**
 * Records a post after the WordPress storage boundary removes added slashes.
 *
 * @param array $data  Slashed post data.
 * @param bool  $error Whether errors should be returned.
 * @return int Declared imported ID.
 */
function wp_insert_post( $data, $error ) {

	foreach ( $data as $key => $value ) {
		if ( is_string( $value ) )
			$data[ $key ] = stripslashes( $value );
	}

	$GLOBALS['stub']['inserted'][] = $data;

	return $data['import_id'];
}

/**
 * Records cache invalidation after the direct timestamp update.
 *
 * @param int $id Post ID.
 * @return void
 */
function clean_post_cache( $id ) {
	$GLOBALS['stub']['cache_cleared'][] = $id;
}

/**
 * Accepts term assignments without a database.
 *
 * @param int    $id       Post ID.
 * @param array  $terms    Term IDs.
 * @param string $taxonomy Taxonomy name.
 * @return void
 */
function wp_set_post_terms( $id, $terms, $taxonomy ) {}

register_shutdown_function(
	/**
	 * Writes the test receipt after the installer completes.
	 *
	 * @return void
	 */
	function () use ( $config ) {
		file_put_contents(
			$config['stub_state'],
			json_encode(
				$GLOBALS['stub'],
				JSON_THROW_ON_ERROR,
			),
		);
	},
);
