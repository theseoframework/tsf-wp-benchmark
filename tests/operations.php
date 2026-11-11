<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Exercises run-state durability and pre-provisioning filesystem boundaries.
 *
 * No test connects to a database or launches WordPress.
 */
return [
	/**
	 * Checks that custom recipes cannot bypass an unverified HTTP runtime.
	 *
	 * @return void
	 */
	'custom experiments cannot bypass a blocking HTTP runtime condition' => function (): void {

		$recipe              = runner_recipe();
		$recipe['reference'] = false;
		$runtime             = [
			'wordpress'      => $recipe['wordpress']['version'],
			'theme'          => [
				'slug'    => $recipe['theme']['slug'],
				'version' => $recipe['theme']['version'],
			],
			'active_plugins' => [],
			'object_cache'   => false,
			'page_cache'     => false,
			'php_version'    => PHP_VERSION,
			'sapi'           => 'cli',
		];

		runner_throws(
			fn() => \TsfBenchmark\assert_runtime(
				$runtime,
				[ 'arm' => [ 'packages' => [] ] ],
				$recipe,
			),
		);
	},
	/**
	 * Checks exclusive run ownership and release after closing the lock.
	 *
	 * @return void
	 */
	'run lock refuses concurrent mutation and releases on close' => function (): void {

		$root = runner_temp();
		$lock = null;

		try {
			$lock = \TsfBenchmark\lock_run( $root );
			runner_throws(
				fn() => \TsfBenchmark\lock_run( $root ),
			);
			fclose( $lock );
			$lock = \TsfBenchmark\lock_run( $root );
		} finally {
			if ( is_resource( $lock ) )
				fclose( $lock );

			\TsfBenchmark\remove_owned_tree( $root, dirname( $root ) );
		}
	},
	/**
	 * Checks atomic replacement, float identity, and cleanup after write failures.
	 *
	 * @return void
	 */
	'atomic JSON replacement preserves floats and removes temporary files' => function (): void {

		$root = runner_temp();

		try {
			$file = $root . '/state.json';
			\TsfBenchmark\write_json(
				$file,
				[ 'phase' => 'planned' ],
			);
			\TsfBenchmark\write_json(
				$file,
				[
					'phase' => 'prepared',
					'float' => 1.0,
				],
			);
			expect(
				[
					'phase' => 'prepared',
					'float' => 1.0,
				] === \TsfBenchmark\read_json( $file ),
			);
			expect(
				[] === glob( $file . '.part-*' ),
			);
			\TsfBenchmark\make_directory( $root . '/blocked' );
			file_put_contents(
				$root . '/blocked/keep',
				'preserved',
			);
			runner_throws(
				fn() => \TsfBenchmark\write_bytes(
					$root . '/blocked',
					'replacement',
				),
			);
			expect(
				'preserved' === file_get_contents( $root . '/blocked/keep' ),
				'A failed replacement must preserve its destination.',
			);
			expect(
				[] === glob( $root . '/blocked.part-*' ),
				'Failed replacements must remove their temporary file.',
			);

			if ( 'Windows' !== PHP_OS_FAMILY )
				expect(
					0600 === ( fileperms( $file ) & 0777 ),
					'Private receipts need owner-only permissions.',
				);
		} finally {
			\TsfBenchmark\remove_owned_tree( $root, dirname( $root ) );
		}
	},
	/**
	 * Checks both insertion orders of conflicting archive files and directories.
	 *
	 * @return void
	 */
	'archive file-directory collisions fail before any extraction' => function (): void {

		foreach ( [ [ 'p/a', 'p/a/b.php' ], [ 'p/a/b.php', 'p/a' ] ] as $names ) {
			$root = runner_temp();

			try {
				$zip = new ZipArchive();
				$zip->open(
					$root . '/bad.zip',
					ZipArchive::CREATE,
				);

				foreach ( $names as $name )
					$zip->addFromString( $name, 'fixture' );

				$zip->close();
				runner_throws(
					fn() => \TsfBenchmark\extract_archive(
						$root . '/bad.zip',
						$root . '/output',
						'p',
					),
				);
				expect(
					! file_exists( $root . '/output' ),
					'Invalid archives must fail before creating the destination.',
				);
			} finally {
				\TsfBenchmark\remove_owned_tree( $root, dirname( $root ) );
			}
		}
	},
	/**
	 * Checks path normalization, approval binding, and safe custom experiment handling.
	 *
	 * @return void
	 */
	'planning resolves runtime paths and requires literal download consent' => function (): void {

		$root = runner_temp();
		$cwd  = getcwd();

		try {
			\TsfBenchmark\make_directory( $root . '/www' );
			file_put_contents(
				$root . '/custom.ini',
				'; synthetic ini file',
			);
			chdir( $root );
			$target = [
				'document_root' => $root . '/www',
				'url'           => 'http://benchmark.invalid',
				'php'           => PHP_BINARY,
				'php_ini'       => 'custom.ini',
				'database'      => [
					'host'     => 'localhost',
					'name'     => 'synthetic',
					'user'     => 'synthetic',
					'password' => 'synthetic',
				],
				'isolation'     => [
					'mode'        => 'offline',
					'description' => 'Synthetic fixture; no network or WordPress.',
				],
			];
			\TsfBenchmark\create_plan(
				runner_recipe(),
				$target,
				$root . '/run',
			);
			$plan = \TsfBenchmark\read_json( $root . '/run/plan.json' );
			expect(
				\TsfBenchmark\normalized_path( $root ) . '/custom.ini' === $plan['target']['php_ini'],
			);
			$custom = runner_recipe();
			array_pop( $custom['routes'] );
			\TsfBenchmark\create_plan(
				$custom,
				$target,
				$root . '/custom-run',
			);
			$custom_plan = \TsfBenchmark\read_json(
				$root . '/custom-run/plan.json',
			);
			expect(
				false === $custom_plan['recipe']['reference'],
				'Changed reference workloads must be allowed as visibly custom experiments.',
			);
			\TsfBenchmark\approve_plan(
				$root . '/custom-run',
				'automated synthetic unit test; no WordPress',
			);
			$edited                = $custom_plan['recipe'];
			$edited['description'] = 'Changed after approval';
			\TsfBenchmark\write_json(
				$root . '/custom-run/recipe.json',
				$edited,
			);
			runner_throws(
				fn() => \TsfBenchmark\approved_plan( $root . '/custom-run' ),
			);
			runner_throws(
				fn() => \TsfBenchmark\report_run( $root . '/custom-run' ),
			);
			expect(
				\TsfBenchmark\approved_plan( $root . '/custom-run', false )['id'] === $custom_plan['id'],
				'Recipe drift must not strand cleanup of owned resources.',
			);
			$target['downloads'] = 'false';
			runner_throws(
				fn() => \TsfBenchmark\validate_target( $target ),
			);
			unset( $target['downloads'] );
			runner_throws(
				fn() => \TsfBenchmark\create_plan(
					runner_recipe(),
					$target,
					$root . '/www/new-parent/run',
				),
			);
			expect(
				! file_exists( $root . '/www/new-parent' ),
				'Planning must not create a rejected path inside the served site.',
			);
		} finally {
			chdir( $cwd );
			\TsfBenchmark\remove_owned_tree( $root, dirname( $root ) );
		}
	},
];
