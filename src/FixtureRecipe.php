<?php
/**
 * Validates the declared dataset and generates deterministic post records.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Validates a fixture declaration without supplying hidden defaults.
 *
 * @param array $fixture Counts, exact content fragment, UTC date, and site options.
 * @return void
 * @throws \RuntimeException When fields are missing, unknown, or outside bounds.
 */
function validate_fixture_recipe( array $fixture ): void {

	$required = [
		'content_html',
		'date',
		'description',
		'pages',
		'paragraphs',
		'posts',
		'posts_per_page',
		'title',
	];

	foreach ( $required as $key ) {
		if ( ! \array_key_exists( $key, $fixture ) )
			throw new \RuntimeException(
				'Fixture recipe is missing ' . $key . '.',
			);
	}

	foreach ( $fixture as $key => $_ ) {
		if ( ! \in_array( $key, $required, true ) )
			throw new \RuntimeException(
				'Unknown fixture setting: ' . $key . '.',
			);
	}

	$bounds = [
		'posts'          => [ 1, 500 ],
		'pages'          => [ 3, 500 ],
		'paragraphs'     => [ 1, 100 ],
		'posts_per_page' => [ 1, 100 ],
	];

	foreach ( $bounds as $key => [ $minimum, $maximum ] ) {
		if (
			   ! \is_int( $fixture[ $key ] )
			|| $fixture[ $key ] < $minimum
			|| $fixture[ $key ] > $maximum
		) {
			throw new \RuntimeException(
				"Fixture $key must be an integer from $minimum to $maximum.",
			);
		}
	}

	foreach ( [
		'content_html' => 65536,
		'title'        => 512,
		'description'  => 2000,
	] as $key => $maximum ) {
		$value = $fixture[ $key ];

		if (
			! \is_string( $value )
			|| '' === trim( $value )
			|| \strlen( $value ) > $maximum
			|| str_contains( $value, "\0" )
			|| 1 !== preg_match( '//u', $value )
		) {
			throw new \RuntimeException(
				"Fixture $key must be nonempty UTF-8 text of at most $maximum bytes without NUL characters.",
			);
		}
	}

	if ( ( $fixture['posts'] + $fixture['pages'] ) * $fixture['paragraphs'] * \strlen( $fixture['content_html'] ) > 64 * 1024 * 1024 )
		throw new \RuntimeException(
			'Declared fixture content exceeds the 64 MiB dataset limit.',
		);

	if (
		   ! \is_string( $fixture['date'] )
		|| ! preg_match(
			'/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/',
			$fixture['date'],
		)
	) {
		throw new \RuntimeException(
			'Fixture date must be a UTC datetime string in YYYY-MM-DD HH:MM:SS form.',
		);
	}

	$date = \DateTimeImmutable::createFromFormat(
		'!Y-m-d H:i:s',
		$fixture['date'],
		new \DateTimeZone( 'UTC' ),
	);

	if (
		false === $date
		|| $date->format( 'Y-m-d H:i:s' ) !== $fixture['date']
		|| '1970-01-01 00:00:00' > $fixture['date']
		|| $date >= new \DateTimeImmutable(
			'now',
			new \DateTimeZone( 'UTC' ),
		)
	) {
		throw new \RuntimeException(
			'Fixture date must be a valid past UTC datetime in YYYY-MM-DD HH:MM:SS form, from 1970 onward. Future posts would be scheduled instead of published.',
		);
	}
}

/**
 * Repeats the declared fragment without altering its content.
 *
 * @param array $fixture Validated fixture declaration.
 * @return string Exact repeated HTML; WordPress handles its subsequent storage.
 */
function fixture_content( array $fixture ): string {

	validate_fixture_recipe( $fixture );

	return str_repeat(
		$fixture['content_html'],
		$fixture['paragraphs'],
	);
}

/**
 * Builds a post with stable IDs and dates independent of database history.
 *
 * @param array  $fixture Dataset declaration.
 * @param string $type    Supported post type: post or page.
 * @param int    $index   Zero-based index within the declared type count.
 * @return array Fields passed to wp_insert_post().
 * @throws \RuntimeException When the type or index is outside the declaration.
 */
function fixture_post_data( array $fixture, string $type, int $index ): array {

	if ( ! \in_array(
		$type,
		[ 'post', 'page' ],
		true,
	) ) {
		throw new \RuntimeException(
			'Unsupported fixture post type.',
		);
	}

	validate_fixture_recipe( $fixture );

	if ( 0 > $index || $index >= $fixture[ 'post' === $type ? 'posts' : 'pages' ] )
		throw new \RuntimeException(
			'Fixture post index is outside the declared dataset.',
		);

	return [
		'import_id'         => ( 'post' === $type ? 1001 : 2001 ) + $index,
		'post_title'        => 'Benchmark ' . $type . ' ' . ( $index + 1 ),
		'post_name'         => 'benchmark-' . $type . '-' . ( $index + 1 ),
		'post_type'         => $type,
		'post_status'       => 'publish',
		'post_author'       => 1,
		'post_date'         => $fixture['date'],
		'post_date_gmt'     => $fixture['date'],
		'post_modified'     => $fixture['date'],
		'post_modified_gmt' => $fixture['date'],
		'post_content'      => str_repeat(
			$fixture['content_html'],
			$fixture['paragraphs'],
		),
		'comment_status'    => 'closed',
		'ping_status'       => 'closed',
	];
}
