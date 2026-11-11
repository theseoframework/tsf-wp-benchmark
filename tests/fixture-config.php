<?php
/**
 * Declarative fixture regressions using pure helpers and isolated WordPress stubs.
 *
 * @package TSF_WP_Benchmark
 */

use function TsfBenchmark\execute;
use function TsfBenchmark\fixture_content;
use function TsfBenchmark\fixture_post_data;
use function TsfBenchmark\validate_fixture_recipe;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

require_once dirname( __DIR__ ) . '/src/Common.php';
require_once dirname( __DIR__ ) . '/src/FixtureRecipe.php';

/**
 * Provides an explicit dataset declaration for tests.
 *
 * @return array Valid fixture settings.
 */
function benchmark_fixture_declaration(): array {
	return [
		'posts'          => 120,
		'pages'          => 30,
		'paragraphs'     => 12,
		'content_html'   => '<p>A declared benchmark fragment.</p>',
		'date'           => '2020-01-15 12:00:00',
		'title'          => 'Benchmark website',
		'description'    => 'A reproducible website.',
		'posts_per_page' => 10,
	];
}

/**
 * Checks that malformed declarations receive a validation error.
 *
 * @param array $value Deliberately altered fixture declaration.
 * @return bool Whether validation rejected the declaration.
 */
function fixture_declaration_rejected( array $value ): bool {

	try {
		validate_fixture_recipe( $value );
	} catch ( RuntimeException ) {
		return true;
	}

	return false;
}

return [
	/**
	 * Checks declared content and repetitions alter the actual payload.
	 *
	 * @return void
	 */
	'fixtures: declared content and repetitions alter the actual payload' => function (): void {

		$original                = benchmark_fixture_declaration();
		$changed                 = benchmark_fixture_declaration();
		$changed['content_html'] = '<p>A different fragment with C:\\examples and “Unicode”.</p>';
		$changed['paragraphs']   = 3;

		expect(
			fixture_content( $original ) !== fixture_content( $changed ),
			'Changing the recipe must change fixture content.',
		);
		expect(
			str_repeat( $changed['content_html'], 3 ) === fixture_post_data( $changed, 'post', 0 )['post_content'],
			'The declared HTML is repeated exactly.',
		);
	},
	/**
	 * Checks missing or misspelled settings never acquire hidden defaults.
	 *
	 * @return void
	 */
	'fixtures: missing or misspelled settings never acquire hidden defaults' => function (): void {

		$value = benchmark_fixture_declaration();
		unset( $value['content_html'] );

		expect(
			fixture_declaration_rejected( $value ),
			'Require explicit dataset content.',
		);

		$value               = benchmark_fixture_declaration();
		$value['post_count'] = 12;

		expect(
			fixture_declaration_rejected( $value ),
			'Reject unknown fixture options.',
		);
	},
	/**
	 * Checks limits protect route IDs and dataset size.
	 *
	 * @return void
	 */
	'fixtures: limits protect route IDs and dataset size' => function (): void {

		$invalid_values = [
			'posts'          => 501,
			'pages'          => 2,
			'paragraphs'     => 0,
			'posts_per_page' => 101,
		];

		foreach ( $invalid_values as $key => $invalid ) {
			$value         = benchmark_fixture_declaration();
			$value[ $key ] = $invalid;

			expect(
				fixture_declaration_rejected( $value ),
				'Reject invalid ' . $key . '.',
			);
		}

		$value          = benchmark_fixture_declaration();
		$value['posts'] = 500;
		$value['pages'] = 500;

		expect(
			1500 === fixture_post_data( $value, 'post', 499 )['import_id']
			&& 2500 === fixture_post_data( $value, 'page', 499 )['import_id'],
			'Post and page ID ranges must remain separate.',
		);

		$value['content_html'] = str_repeat( 'x', 65536 );
		$value['paragraphs']   = 100;

		expect(
			fixture_declaration_rejected( $value ),
			'Reject oversized datasets before allocating repeated content.',
		);
	},
	/**
	 * Checks fixed UTC dates are valid and apply to both modified fields.
	 *
	 * @return void
	 */
	'fixtures: fixed UTC dates are valid and apply to both modified fields' => function (): void {

		$invalid_dates = [
			"2020-01-15 12:00:00\0",
			'2020-01-15',
			'2020-02-30 12:00:00',
			'9999-01-01 00:00:00',
		];

		foreach ( $invalid_dates as $date ) {
			$value         = benchmark_fixture_declaration();
			$value['date'] = $date;

			expect(
				fixture_declaration_rejected( $value ),
				'Reject invalid, NUL-containing, and future dates with a validation error.',
			);
		}

		$value         = benchmark_fixture_declaration();
		$value['date'] = '2019-03-18 03:04:05';
		$post          = fixture_post_data( $value, 'page', 0 );

		foreach ( [ 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ] as $key ) {
			expect(
				$post[ $key ] === $value['date'],
				'All declared timestamps use the UTC recipe time.',
			);
		}
	},
	/**
	 * Checks plain PHP installer honors a changed recipe using WordPress API stubs.
	 *
	 * @return void
	 */
	'fixtures: plain PHP installer honors a changed recipe using WordPress API stubs' => function (): void {

		$directory = sys_get_temp_dir() . '/tsfb fixture test ' . bin2hex( random_bytes( 8 ) );

		if ( ! mkdir(
			$directory . '/wp-admin/includes',
			0700,
			true,
		) ) {
			throw new RuntimeException(
				'Cannot create isolated fixture stub directory.',
			);
		}

		$files = [
			'config.json',
			'result.json',
			'stub-state.json',
			'wp-admin/includes/admin.php',
			'wp-admin/includes/upgrade.php',
			'wp-load.php',
		];

		try {
			$stub = file_get_contents(
				__DIR__ . '/fixtures/installer/wp-load.php',
			);
			file_put_contents(
				$directory . '/wp-load.php',
				$stub,
			);
			file_put_contents(
				$directory . '/wp-admin/includes/admin.php',
				'<?php',
			);
			file_put_contents(
				$directory . '/wp-admin/includes/upgrade.php',
				'<?php',
			);

			$dataset                   = benchmark_fixture_declaration();
			$dataset['posts']          = 2;
			$dataset['pages']          = 3;
			$dataset['paragraphs']     = 2;
			$dataset['title']          = 'Independent alternative';
			$dataset['description']    = 'Custom description';
			$dataset['posts_per_page'] = 7;
			$dataset['content_html']   = '<p>C:\\literal path and a "quote".</p>';

			$config = [
				'root'       => $directory,
				'url'        => 'http://127.0.0.1:12345/owned',
				'action'     => 'install',
				'recipe'     => [
					'theme'    => [ 'slug' => 'fixture-theme' ],
					'fixtures' => $dataset,
				],
				'result'     => $directory . '/result.json',
				'stub_state' => $directory . '/stub-state.json',
			];

			file_put_contents(
				$directory . '/config.json',
				json_encode( $config, JSON_THROW_ON_ERROR ),
			);
			$process = execute(
				[
					PHP_BINARY,
					dirname( __DIR__ ) . '/scripts/fixture-cli.php',
					$directory . '/config.json',
				],
			);

			expect(
				0 === $process['exit'],
				'Stubbed installer failed: ' . $process['stderr'],
			);

			if ( 'Windows' !== PHP_OS_FAMILY )
				expect(
					0600 === ( fileperms( "$directory/result.json" ) & 0777 ),
					'Fixture receipts must remain private to their owner.',
				);

			$state = json_decode(
				file_get_contents(
					$directory . '/stub-state.json',
				),
				true,
				512,
				JSON_THROW_ON_ERROR,
			);

			expect(
				5 === count( $state['inserted'] )
				&& [ 1001, 1002, 2001, 2002, 2003 ] === array_column(
					$state['inserted'],
					'import_id',
				),
				'Installer must honor declared counts and route IDs.',
			);
			expect(
				str_repeat( $dataset['content_html'], 2 ) === $state['inserted'][0]['post_content'],
				'WordPress unslashing must preserve literal backslashes and quotes.',
			);
			expect(
				$state['install_title'] === $dataset['title']
				&& $state['options']['blogdescription'] === $dataset['description']
				&& 7 === $state['options']['posts_per_page'],
				'Installer must honor declared site options.',
			);
			expect(
				! isset( $state['options']['optin_monster_api'] ),
				'Do not bypass OptinMonster native initialization by pre-seeding partial defaults.',
			);
			expect(
				5 === count( $state['updates'] ) && 5 === count( $state['cache_cleared'] ),
				'Every inserted post must have modified dates fixed and cache cleared.',
			);
			expect(
				[
					'post_modified'     => $dataset['date'],
					'post_modified_gmt' => $dataset['date'],
				] === $state['updates'][0]['data'],
				'Do not retain insertion-time modification timestamps.',
			);
			expect(
				'127.0.0.1:12345' === $state['server']['HTTP_HOST']
				&& ! $state['wp_cli_defined'],
				'Preserve the target port without pretending to be WP-CLI.',
			);
		} finally {
			foreach ( $files as $file ) {
				if ( is_file( $directory . '/' . $file ) )
					unlink( $directory . '/' . $file );
			}

			rmdir(
				$directory . '/wp-admin/includes',
			);
			rmdir( $directory . '/wp-admin' );
			rmdir( $directory );
		}
	},
];
