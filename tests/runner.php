<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Runner ownership, approval, package, process, and response qualification tests.
 */

/**
 * Creates a unique test directory under the repository's private local area.
 *
 * @return string Owned absolute test directory.
 */
function runner_temp(): string {

	$parent = dirname( __DIR__ ) . '/.local';
	\TsfBenchmark\make_directory( $parent );
	$root = $parent . '/tests-' . bin2hex( random_bytes( 6 ) );
	\TsfBenchmark\make_directory( $root );

	return $root;
}

/**
 * Requires an operation to refuse its deliberately invalid input.
 *
 * @param callable $call Operation expected to throw.
 * @return void
 */
function runner_throws( callable $call ): void {

	$threw = false;

	try {
		$call();
	} catch ( Throwable ) {
		$threw = true;
	}

	expect( $threw, 'Expected refusal.' );
}

/**
 * Reads the published reference recipe without changing its declaration.
 *
 * @return array Reference recipe.
 */
function runner_recipe(): array {
	return \TsfBenchmark\read_json(
		dirname( __DIR__ ) . '/recipes/tsf-5.2.1.json',
	);
}

return [
	/**
	 * Preserves plugin messages while detecting native PHP runtime diagnostics.
	 *
	 * @return void
	 */
	'fixture logs distinguish PHP diagnostics from preserved plugin messages' => function (): void {

		$directory = runner_temp();
		try {
			$path     = "$directory/php.log";
			$messages = "[11-Nov-2026 17:24:18 UTC] [Universally][INFO] Migration completed\n[11-Nov-2026 17:24:21 UTC] [Universally][ERROR] API Key is not configured in settings.\n";
			TsfBenchmark\write_bytes( $path, $messages );
			$receipt = TsfBenchmark\inspect_fixture_diagnostics( $path );
			expect( ! $receipt['php_diagnostics'] && hash( 'sha256', $messages ) === $receipt['sha256'] );
			foreach ( [ 'Warning', 'Notice', 'Deprecated', 'Fatal error', 'Parse error', 'Recoverable fatal error', 'Strict Standards' ] as $kind ) {
				foreach ( [ '', '[11-Nov-2026 17:24:21 UTC] PHP ' ] as $prefix ) {
					TsfBenchmark\write_bytes( $path, $messages . "$prefix$kind: A genuine diagnostic\n" );
					expect( TsfBenchmark\inspect_fixture_diagnostics( $path )['php_diagnostics'] );
				}
			}
			expect( ! TsfBenchmark\inspect_fixture_diagnostics( "$directory/missing.log" )['php_diagnostics'] );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/**
	 * Exercises provisioning with the exact lowercase root used by WordPress ZIPs.
	 *
	 * @return void
	 */
	'WordPress fixture provisioning accepts the official lowercase archive root' => function (): void {

		$directory = runner_temp();
		try {
			TsfBenchmark\make_directory( "$directory/web" );
			TsfBenchmark\make_directory( "$directory/cache" );
			TsfBenchmark\make_directory( "$directory/run" );
			$archive = "$directory/core.zip";
			$zip     = new ZipArchive();
			$zip->open( $archive, ZipArchive::CREATE );
			$zip->addFromString( 'wordpress/wp-settings.php', '<?php // offline stub' );
			$zip->addFromString( 'wordpress/wp-content/themes/test-theme/style.css', "Version: 1.0\n" );
			$zip->close();
			$hash = hash_file( 'sha256', $archive );
			copy( $archive, "$directory/cache/$hash.zip" );
			$arm  = [
				'id'       => 'core-a',
				'packages' => [],
				'setup'    => 'core',
			];
			$plan = [
				'id'        => 'offline-provision-test',
				'namespace' => 'tsfb0123456789ab',
				'target'    => [
					'document_root' => "$directory/web",
					'url'           => 'http://127.0.0.1',
					'package_cache' => "$directory/cache",
					'database'      => [
						'name'     => 'test',
						'user'     => 'test',
						'password' => '',
						'host'     => '127.0.0.1',
					],
				],
				'recipe'    => [
					'wordpress' => [ 'sha256' => $hash ],
					'theme'     => [
						'bundled' => true,
						'slug'    => 'test-theme',
						'version' => '1.0',
					],
				],
			];
			$site = TsfBenchmark\provision_fixture( $plan, "$directory/run", 1, 0, $arm );
			expect( is_file( $site['root'] . '/wp-settings.php' ) );
			expect( is_file( $site['root'] . '/wp-config.php' ) );
			expect( str_contains( file_get_contents( $site['root'] . '/wp-config.php' ), "define( 'WP_ENVIRONMENT_TYPE', 'production' );" ) );
			expect( is_file( "$directory/run/sites.private.json" ) );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/**
	 * Checks reference arm counts, repetitions, and package coverage.
	 *
	 * @return void
	 */
	'pinned reference recipe has nine arms and all three complete passes' => function (): void {

		$r = runner_recipe();
		\TsfBenchmark\validate_recipe( $r );
		expect( 9 === count( $r['arms'] ) );
		expect(
			3 === $r['measurement']['passes'],
		);
		expect(
			11 === count(
				\TsfBenchmark\all_packages( $r ),
			),
			'WordPress, six SEO plugins, four companions.',
		);
	},
	/**
	 * Checks that approval covers the exact plan and does not disclose secrets.
	 *
	 * @return void
	 */
	'plan requires approval bound to exact changes without modifying target' => function (): void {

		$t = runner_temp();
		\TsfBenchmark\make_directory( $t . '/www' );

		try {
			$target  = [
				'document_root' => $t . '/www',
				'url'           => 'http://benchmark.invalid',
				'php'           => PHP_BINARY,
				'database'      => [
					'host'     => '127.0.0.1',
					'name'     => 'synthetic_test',
					'user'     => 'synthetic',
					'password' => 'test-secret',
				],
				'isolation'     => [
					'mode'        => 'offline',
					'description' => 'Synthetic tests never connect to a database.',
				],
			];
			$summary = \TsfBenchmark\create_plan(
				runner_recipe(),
				$target,
				$t . '/run',
			);

			expect(
				2 === count( scandir( $t . '/www' ) ),
				'Planning must not touch the web root.',
			);
			expect(
				! str_contains(
					json_encode( $summary ),
					'test-secret',
				),
				'Proposal exposed a secret.',
			);
			runner_throws(
				fn() => \TsfBenchmark\approved_plan( $t . '/run' ),
			);
			\TsfBenchmark\approve_plan(
				$t . '/run',
				'automated unit test: synthetic target only',
			);
			\TsfBenchmark\approved_plan( $t . '/run' );

			$p                        = \TsfBenchmark\read_json( $t . '/run/plan.json' );
			$p['target']['downloads'] = true;
			\TsfBenchmark\write_json(
				$t . '/run/plan.json',
				$p,
			);
			runner_throws(
				fn() => \TsfBenchmark\approved_plan( $t . '/run' ),
			);
		} finally {
			\TsfBenchmark\remove_owned_tree( $t, dirname( $t ) );
		}
	},
	/**
	 * Checks complete archive validation before any files are extracted.
	 *
	 * @return void
	 */
	'archive traversal is refused before extraction'       => function (): void {

		$t = runner_temp();

		try {
			$z = new ZipArchive();
			$z->open(
				$t . '/bad.zip',
				ZipArchive::CREATE,
			);
			$z->addFromString( 'plugin/good.php', '<?php' );
			$z->addFromString(
				'plugin/../escaped.php',
				'<?php',
			);
			$z->close();

			runner_throws(
				fn() => \TsfBenchmark\extract_archive(
					$t . '/bad.zip',
					$t . '/out',
					'plugin',
				),
			);
			expect(
				! file_exists( $t . '/out/good.php' ),
				'Extraction began before complete path validation.',
			);
		} finally {
			\TsfBenchmark\remove_owned_tree( $t, dirname( $t ) );
		}
	},
	/**
	 * Checks Unix symbolic-link mode bits before any archive entry is written.
	 *
	 * @return void
	 */
	'archive symbolic-link attributes are rejected before extraction' => function (): void {

		$t = runner_temp();

		try {
			$zip = new ZipArchive();
			$zip->open(
				"$t/linked.zip",
				ZipArchive::CREATE,
			);
			$zip->addFromString( 'p/first.php', 'first' );
			$zip->addFromString( 'p/link', '../unrelated' );
			expect( $zip->setExternalAttributesName(
				'p/link',
				ZipArchive::OPSYS_UNIX,
				0120777 << 16, // Unix symbolic-link type and permission bits.
			) );
			$zip->close();
			runner_throws(
				fn() => \TsfBenchmark\extract_archive(
					"$t/linked.zip",
					"$t/output",
					'p',
				),
			);
			expect(
				! file_exists( "$t/output" ),
				'Symbolic-link attributes must fail the bitmask check before writing.',
			);
		} finally {
			\TsfBenchmark\remove_owned_tree(
				$t,
				dirname( $t ),
			);
		}
	},
	/**
	 * Checks archive names that collide or behave differently across platforms.
	 *
	 * @return void
	 */
	'archive collision and nonportable names are rejected' => function (): void {

		foreach ( [
			[ 'p/A.php', 'p/a.php' ],
			[ 'p/con.txt' ],
			[ 'p/a.php:stream' ],
			[ 'p/folder./a.php' ],
			[ 'p/a?.php' ],
			[ 'p/a*.php' ],
			[ 'p/a|b.php' ],
			[ 'p/a<b>.php' ],
			[ 'p/a"b.php' ],
		] as $names ) {
			$t = runner_temp();

			try {
				$z = new ZipArchive();
				$z->open(
					$t . '/bad.zip',
					ZipArchive::CREATE,
				);
				$z->addFromString( 'p/first.php', 'first' );

				foreach ( $names as $name )
					$z->addFromString( $name, 'test' );

				$z->close();
				runner_throws(
					fn() => \TsfBenchmark\extract_archive(
						$t . '/bad.zip',
						$t . '/out',
						'p',
					),
				);
				expect(
					! file_exists( $t . '/out/first.php' ),
					'Nonportable names must be rejected before extraction begins.',
				);
			} finally {
				\TsfBenchmark\remove_owned_tree( $t, dirname( $t ) );
			}
		}
	},
	/**
	 * Checks offline package reuse and rejection after cached bytes change.
	 *
	 * @return void
	 */
	'valid archive extraction and cache verification work offline' => function (): void {

		$t = runner_temp();

		try {
			$z = new ZipArchive();
			$z->open(
				$t . '/source.zip',
				ZipArchive::CREATE,
			);
			$z->addFromString(
				'plugin/index.php',
				'<?php echo "fixture";',
			);
			$z->close();

			$hash = hash_file( 'sha256', $t . '/source.zip' );
			rename(
				$t . '/source.zip',
				$t . '/' . $hash . '.zip',
			);
			$p       = [
				'sha256' => $hash,
				'url'    => 'https://downloads.wordpress.org/plugin/example.1.zip',
			];
			$f       = \TsfBenchmark\fetch_archive( $p, $t, false );
			$receipt = \TsfBenchmark\extract_archive(
				$f,
				$t . '/out',
				'plugin',
			);

			expect(
				'index.php' === $receipt[0]['path'],
			);
			expect(
				'<?php echo "fixture";' === file_get_contents( $t . '/out/index.php' ),
			);
			file_put_contents( $f, 'corrupt' );
			runner_throws(
				fn() => \TsfBenchmark\fetch_archive( $p, $t, false ),
			);
		} finally {
			\TsfBenchmark\remove_owned_tree( $t, dirname( $t ) );
		}
	},
	/**
	 * Checks all destination conflicts before the first archive file is written.
	 *
	 * @return void
	 */
	'archive preflight preserves existing files without partial extraction' => function (): void {

		foreach ( [ 'existing.txt', 'blocked/nested.txt' ] as $conflict ) {
			$t = runner_temp();

			try {
				$zip = new ZipArchive();
				$zip->open(
					$t . '/collision.zip',
					ZipArchive::CREATE,
				);
				$zip->addFromString( 'p/first.txt', 'new file' );
				$zip->addFromString(
					'p/' . $conflict,
					'replacement',
				);
				expect( $zip->close() );
				\TsfBenchmark\make_directory( $t . '/out' );
				$existing = str_contains( $conflict, '/' ) ? 'blocked' : $conflict;
				file_put_contents(
					$t . '/out/' . $existing,
					'preserve',
				);

				runner_throws(
					fn() => \TsfBenchmark\extract_archive(
						$t . '/collision.zip',
						$t . '/out',
						'p',
					),
				);
				expect(
					! file_exists( $t . '/out/first.txt' ),
					'Destination preflight must finish before writing earlier entries.',
				);
				expect(
					'preserve' === file_get_contents( $t . '/out/' . $existing ),
					'Pre-existing files must remain byte-identical.',
				);
			} finally {
				\TsfBenchmark\remove_owned_tree( $t, dirname( $t ) );
			}
		}
	},
	/**
	 * Checks that a corrupt ZIP entry cannot leave a truncated destination file.
	 *
	 * @return void
	 */
	'CRC failure removes only its incomplete archive output' => function (): void {

		$t = runner_temp();

		try {
			$zip = new ZipArchive();
			$zip->open(
				$t . '/corrupt.zip',
				ZipArchive::CREATE,
			);
			$zip->addFromString(
				'p/payload.txt',
				'original-payload-000',
			);
			// Stored bytes can be altered without changing the central-directory CRC.
			$zip->setCompressionName(
				'p/payload.txt',
				ZipArchive::CM_STORE,
			);
			expect( $zip->close() );
			$bytes = str_replace(
				'original-payload-000',
				'modified-payload-000',
				file_get_contents( $t . '/corrupt.zip' ),
				$changed,
			);
			expect(
				1 === $changed,
				'The fixture must corrupt exactly one stored payload.',
			);
			file_put_contents(
				$t . '/corrupt.zip',
				$bytes,
			);
			\TsfBenchmark\make_directory( $t . '/out' );
			file_put_contents(
				$t . '/out/owner.json',
				'preserve',
			);

			runner_throws(
				fn() => \TsfBenchmark\extract_archive(
					$t . '/corrupt.zip',
					$t . '/out',
					'p',
				),
			);
			expect(
				! file_exists( $t . '/out/payload.txt' ),
				'A rejected CRC entry must not remain as a partial file.',
			);
			expect(
				'preserve' === file_get_contents( $t . '/out/owner.json' ),
				'Failure cleanup must preserve unrelated ownership evidence.',
			);
		} finally {
			\TsfBenchmark\remove_owned_tree( $t, dirname( $t ) );
		}
	},
	/**
	 * Checks that cleanup cannot remove an entry outside its ownership boundary.
	 *
	 * @return void
	 */
	'cleanup containment refuses unrelated files'          => function (): void {

		$t = runner_temp();

		try {
			file_put_contents( $t . '/keep', 'safe' );
			runner_throws(
				fn() => \TsfBenchmark\remove_owned_tree(
					$t . '/keep',
					$t . '/owned',
				),
			);
			expect(
				'safe' === file_get_contents( $t . '/keep' ),
			);
		} finally {
			\TsfBenchmark\remove_owned_tree( $t, dirname( $t ) );
		}
	},
	/**
	 * Checks that literal child arguments never become shell expressions.
	 *
	 * @return void
	 */
	'child process preserves spaces and literal shell characters' => function (): void {

		$value = 'space & literal $(echo unsafe)';
		$r     = \TsfBenchmark\execute( [
			PHP_BINARY,
			'-r',
			'echo $argv[1];',
			$value,
		] );

		expect(
			0 === $r['exit'] && $r['stdout'] === $value,
		);
	},
	/**
	 * Checks the bounded lifetime of an owned child process.
	 *
	 * @return void
	 */
	'child process timeout terminates only its own command' => function (): void {
		runner_throws(
			fn() => \TsfBenchmark\execute(
				[ PHP_BINARY, '-r', 'sleep(10);' ],
				null,
				null,
				1,
			),
		);
	},
	/**
	 * Checks that an otherwise valid page needs a fresh PHP request marker.
	 *
	 * @return void
	 */
	'fresh execution and route validation detect cached output' => function (): void {

		$r = [
			'status'  => 200,
			'headers' => [
				'content-type'  => 'text/html',
				'x-tsf-route'   => 'post',
				'x-tsf-request' => 'old',
			],
			'nonce'   => 'new',
			'body'    => '<html><head><title>Fixture</title></head></html>',
		];
		$v = \TsfBenchmark\qualify_response(
			$r,
			[
				'id'     => 'post',
				'status' => 200,
			],
			[
				'url' => 'http://fixture.invalid',
				'arm' => [
					'id'    => 'core-a',
					'setup' => 'core',
				],
			],
		);

		expect(
			! $v['valid'] && in_array(
				'Missing fresh PHP execution marker',
				$v['reasons'],
				true,
			),
		);
	},
	/**
	 * Checks that a custom route name retains its underlying query requirements.
	 *
	 * @return void
	 */
	'custom route names retain their query feature requirements' => function (): void {

		$r = [
			'status'  => 200,
			'headers' => [
				'content-type'  => 'text/html',
				'x-tsf-route'   => 'page',
				'x-tsf-request' => 'fresh',
			],
			'nonce'   => 'fresh',
			'body'    => '<html><head><title>Fixture</title></head></html>',
		];
		$v = \TsfBenchmark\qualify_response(
			$r,
			[
				'id'     => 'content-heavy',
				'query'  => 'page',
				'status' => 200,
			],
			[
				'url' => 'http://fixture.invalid',
				'arm' => [
					'id'    => 'yoast',
					'setup' => 'yoast',
				],
			],
		);

		foreach ( [
			'Missing expected canonical',
			'Missing expected Open Graph output',
			'Missing default Yoast schema',
		] as $reason ) {
			expect(
				in_array( $reason, $v['reasons'], true ),
			);
		}

		expect(
			! in_array(
				'Wrong WordPress query',
				$v['reasons'],
				true,
			),
		);
	},
];
