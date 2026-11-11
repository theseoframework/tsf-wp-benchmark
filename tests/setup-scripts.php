<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Exercises setup scripts in isolated PHP processes with file-based stubs.
 */

/**
 * Creates a disposable directory for one isolated script regression test.
 *
 * @return string The newly created directory under the system temporary directory.
 * @throws RuntimeException When the temporary parent cannot be resolved or the directory created.
 */
function setup_script_directory(): string {

	$parent = realpath( sys_get_temp_dir() );

	if ( false === $parent )
		throw new RuntimeException(
			'Cannot resolve the system temporary directory.',
		);

	$directory = TsfBenchmark\normalized_path( $parent ) . '/tsfb setup script ' . bin2hex( random_bytes( 8 ) );

	if ( ! mkdir( $directory, 0700 ) )
		throw new RuntimeException(
			'Cannot create a script test directory.',
		);

	return $directory;
}

/**
 * Reads one inspectable PHP fixture without executing it in the test process.
 *
 * @param string $name The fixture filename under tests/fixtures/setup.
 * @return string The fixture source.
 * @throws RuntimeException When the fixture source cannot be read.
 */
function setup_script_fixture( string $name ): string {

	$source = file_get_contents(
		__DIR__ . "/fixtures/setup/$name",
	);

	if ( false === $source )
		throw new RuntimeException(
			'Cannot read a setup script fixture.',
		);

	return $source;
}

/**
 * Runs a fresh PHP process against a stub bootstrap, without WordPress or MySQL.
 *
 * @param string $directory   The test-owned working directory.
 * @param string $fixture     The bootstrap filename under tests/fixtures/setup.
 * @param string $script      The production script exercised by the bootstrap.
 * @param array  $arguments   Arguments passed directly to the production script.
 * @param bool   $without_ini Whether to disable extensions for a mysqli stub.
 * @return array Process status, stdout, and stderr from the existing runner.
 * @throws RuntimeException When bootstrap storage or the isolated process fails.
 */
function setup_script_run(
	string $directory,
	string $fixture,
	string $script,
	array $arguments = [],
	bool $without_ini = false
): array {

	$source  = setup_script_fixture( $fixture );
	$written = file_put_contents(
		"$directory/bootstrap.php",
		$source,
	);

	if ( strlen( $source ) !== $written )
		throw new RuntimeException(
			'Cannot write the setup script bootstrap.',
		);

	$command = [ PHP_BINARY ];

	if ( $without_ini )
		$command[] = '-n';

	$command[] = "$directory/bootstrap.php";
	$command[] = $script;

	return TsfBenchmark\execute( array_merge( $command, $arguments ) );
}

/**
 * Builds an owned cleanup fixture whose child process cannot reach a database.
 *
 * @return array The temporary directory, synthetic approved plan, and site journal.
 */
function setup_cleanup_fixture(): array {

	$directory = setup_script_directory();
	$web       = "$directory/web";
	$root      = "$web/tsfb0123456789ab/p1-core";
	TsfBenchmark\make_directory( $root );
	file_put_contents(
		"$directory/cleanup-stub.php",
		setup_script_fixture( 'cleanup-receipt.php' ),
	);
	file_put_contents(
		"$directory/cleanup.ini",
		'auto_prepend_file="' . "$directory/cleanup-stub.php" . '"',
	);
	$site = [
		'id'     => 'p1-core',
		'pass'   => 1,
		'arm'    => [ 'id' => 'core' ],
		'root'   => $root,
		'prefix' => 'tsfb0123456789ab_p1_a0_',
	];
	$plan = [
		'id'        => '0123456789ab',
		'namespace' => 'tsfb0123456789ab',
		'target'    => [
			'document_root' => $web,
			'php'           => PHP_BINARY,
			'php_ini'       => "$directory/cleanup.ini",
			'database'      => [],
		],
		'recipe'    => [
			'arms'        => [ [ 'id' => 'core' ] ],
			'measurement' => [ 'passes' => 1 ],
		],
	];
	TsfBenchmark\write_json(
		"$root/.tsfbench-owner.json",
		[
			'run'    => $plan['id'],
			'prefix' => $site['prefix'],
		],
	);
	TsfBenchmark\write_json(
		"$directory/sites.private.json",
		[ $site ],
	);

	return [ $directory, $plan, $site ];
}

return [
	/**
	 * Requires database cleanup proof before deleting recoverable fixture files.
	 *
	 * @return void
	 */
	'cleanup keeps files until the child proves no owned tables remain' => function (): void {

		[ $directory, $plan, $site ] = setup_cleanup_fixture();

		try {
			foreach ( [
				'not-json',
				'{}',
				'{"removed":[],"remaining":1}',
				'{"removed":"posts","remaining":0}',
				'{"removed":{"metadata":"private"},"remaining":0}',
				'{"removed":["unrelated_posts"],"remaining":0}',
				'{"removed":[12],"remaining":0}',
			] as $response ) {
				file_put_contents(
					"$directory/cleanup-response.txt",
					$response,
				);
				$rejected = false;

				try {
					TsfBenchmark\cleanup_fixtures( $plan, $directory );
				} catch ( RuntimeException $error ) {
					$rejected = true;
				}

				expect(
					$rejected,
					'A zero process exit alone cannot prove table cleanup.',
				);
				expect(
					is_file(
						$site['root'] . '/.tsfbench-owner.json',
					),
					'Invalid cleanup receipts must preserve recovery files.',
				);
				expect(
					! file_exists(
						"$directory/cleanup-input.private.json",
					),
					'Temporary cleanup credentials must be removed after dispatch.',
				);
			}

			file_put_contents(
				"$directory/cleanup-response.txt",
				'{"removed":[],"remaining":0}',
			);
			$receipt = TsfBenchmark\cleanup_fixtures( $plan, $directory );
			expect(
				true === $receipt['sites'][0]['files_removed'],
				'Valid cleanup proof permits removal of the owned directory.',
			);
		} finally {
			TsfBenchmark\remove_owned_tree(
				$directory,
				dirname( $directory ),
			);
		}
	},
	/**
	 * Refuses altered ownership before invoking any destructive subprocess.
	 *
	 * @return void
	 */
	'cleanup refuses replaced fixture paths and mismatched ownership' => function (): void {

		[ $directory, $plan, $site ] = setup_cleanup_fixture();

		try {
			file_put_contents(
				"$directory/cleanup-response.txt",
				'{"removed":[],"remaining":0}',
			);
			TsfBenchmark\write_json(
				$site['root'] . '/.tsfbench-owner.json',
				[
					'run'    => 'unrelated-run',
					'prefix' => $site['prefix'],
				],
			);
			$rejected = false;

			try {
				TsfBenchmark\cleanup_fixtures( $plan, $directory );
			} catch ( RuntimeException $error ) {
				$rejected = true;
			}

			expect(
				$rejected,
				'A fixture owned by another run must be refused.',
			);
			expect(
				! file_exists(
					"$directory/cleanup-called.txt",
				),
				'Ownership refusal must happen before database dispatch.',
			);
			TsfBenchmark\remove_owned_tree(
				$site['root'],
				$directory,
			);
			file_put_contents(
				$site['root'],
				'Unrelated replacement file.',
			);
			$rejected = false;

			try {
				TsfBenchmark\cleanup_fixtures( $plan, $directory );
			} catch ( RuntimeException $error ) {
				$rejected = true;
			}

			expect(
				$rejected,
				'A regular file cannot stand in for the owned fixture directory.',
			);
			expect(
				! file_exists(
					"$directory/cleanup-called.txt",
				),
				'A replaced fixture path must not trigger database cleanup.',
			);
			expect(
				'Unrelated replacement file.' === file_get_contents( $site['root'] ),
				'Cleanup must retain the unrelated replacement file.',
			);
		} finally {
			TsfBenchmark\remove_owned_tree(
				$directory,
				dirname( $directory ),
			);
		}
	},
	'local target: exclusive creation, served-root rejection, and encoding failure' => function (): void {

		$directory = setup_script_directory();
		$script    = dirname( __DIR__ ) . '/scripts/local-target.php';
		mkdir( "$directory/site" );

		try {
			$destination = "$directory/target.json";
			$process     = setup_script_run(
				$directory,
				'local-target.php',
				$script,
				[ $destination ],
			);
			expect(
				0 === $process['exit'],
				'A fresh private target should be written.',
			);
			$saved  = file_get_contents( $destination );
			$target = json_decode(
				$saved,
				true,
				512,
				JSON_THROW_ON_ERROR,
			);
			expect(
				'private-password' === $target['database']['password'],
				'The target must retain the actual database settings.',
			);
			expect(
				! str_contains(
					$process['stdout'] . $process['stderr'],
					'private-password',
				),
				'Credentials must not be printed.',
			);

			$process = setup_script_run(
				$directory,
				'local-target.php',
				$script,
				[ $destination ],
			);
			expect(
				(
					   1 === $process['exit']
					&& file_get_contents( $destination ) === $saved
				),
				'An existing target must remain untouched.',
			);

			foreach ( [
				[ "$directory/site/target.json", '' ],
				[ "$directory/invalid.json", 'bad-encoding' ],
				[ "$directory/no-cli.json", 'cli-disabled' ],
				[ "$directory/missing/target.json", '' ],
				[ '', '' ],
			] as $arguments ) {
				$process = setup_script_run(
					$directory,
					'local-target.php',
					$script,
					$arguments,
				);
				expect(
					1 === $process['exit'],
					'An invalid destination, missing CLI context, or invalid JSON must fail.',
				);
				expect(
					! file_exists( $arguments[0] ),
					'A rejected target must not leave a configuration file.',
				);
				expect(
					! str_contains( $process['stdout'], 'saved' ),
					'A rejected write must not report success.',
				);
			}

			// Symlink creation is not generally available to unprivileged Windows users.
			if ( 'Windows' !== PHP_OS_FAMILY ) {
				$link = "$directory/dangling.json";
				expect(
					symlink(
						"$directory/missing.json",
						$link,
					),
					'Create the Unix dangling-link regression fixture.',
				);
				$process = setup_script_run(
					$directory,
					'local-target.php',
					$script,
					[ $link ],
				);
				expect(
					(
						   1 === $process['exit']
						&& ! file_exists( "$directory/missing.json" )
					),
					'A dangling destination symlink must not be followed.',
				);
				unlink( $link );
			}
		} finally {
			TsfBenchmark\remove_owned_tree(
				$directory,
				dirname( $directory ),
			);
		}
	},
	'fixture scripts: malformed actions and invalid results fail before WordPress loads' => function (): void {

		$directory = setup_script_directory();
		$script    = dirname( __DIR__ ) . '/scripts/fixture-cli.php';
		$marker    = "$directory/loaded.txt";
		$base      = [
			'action' => 'configure',
			'root'   => $directory,
			'url'    => 'http://127.0.0.1',
			'result' => "$directory/result.json",
		];

		try {
			file_put_contents(
				"$directory/wp-load.php",
				setup_script_fixture( 'wp-load-marker.php' ),
			);
			file_put_contents(
				"$directory/existing.json",
				'preserve',
			);

			foreach ( [
				[ 'action' => 'unknown' ],
				[ 'url' => 'not-a-url' ],
				[ 'result' => "$directory/existing.json" ],
				[ 'result' => "$directory/missing/result.json" ],
				[ 'result' => $directory ],
				[ 'result' => '' ],
				[ 'result' => null ],
				[ 'result' => false ],
				[ 'result' => [] ],
				[ 'result' => "invalid\0path" ],
			] as $change ) {
				file_put_contents(
					"$directory/config.json",
					json_encode(
						array_replace( $base, $change ),
						JSON_THROW_ON_ERROR,
					),
				);
				$process = TsfBenchmark\execute(
					[ PHP_BINARY, $script, "$directory/config.json" ],
				);
				expect(
					1 === $process['exit'],
					'Malformed fixture input must fail clearly.',
				);
				expect(
					! file_exists( $marker ),
					'Rejected input must not bootstrap WordPress or its plugins.',
				);
				expect(
					'' === $process['stdout'],
					'Rejected input must not emit a success receipt or PHP warning.',
				);
				expect(
					! preg_match(
						'/PHP (?:Warning|Deprecated|Notice|Fatal error):/',
						$process['stderr'],
					),
					'Invalid output must produce a deliberate diagnostic, not a PHP warning.',
				);
			}

			expect(
				'preserve' === file_get_contents( "$directory/existing.json" ),
				'The existing result must survive.',
			);
		} finally {
			TsfBenchmark\remove_owned_tree(
				$directory,
				dirname( $directory ),
			);
		}
	},
	'cleanup script: socket and IPv6 hosts preserve ownership and reject failed drops' => function (): void {

		$directory = setup_script_directory();
		$script    = dirname( __DIR__ ) . '/scripts/drop-owned-tables.php';
		$config    = [
			'prefix'   => 'tsfb0123456789ab_p1_a1_',
			'database' => [
				'host'     => '',
				'user'     => 'private-user',
				'password' => 'private-password',
				'name'     => 'private-database',
			],
		];

		try {
			foreach ( [
				'127.0.0.1:10001'             => [ '127.0.0.1', 10001, null ],
				'localhost:/tmp/mysql.sock'   => [ 'localhost', null, '/tmp/mysql.sock' ],
				'[::1]:10002'                 => [ '::1', 10002, null ],
				'[::1]:10002:/tmp/mysql.sock' => [ '::1', 10002, '/tmp/mysql.sock' ],
				'::1'                         => [ '::1', null, null ],
			] as $host => [ $expected_host, $port, $socket ] ) {
				$config['database']['host'] = $host;
				file_put_contents(
					"$directory/config.json",
					json_encode( $config, JSON_THROW_ON_ERROR ),
				);
				$process = setup_script_run(
					$directory,
					'drop-owned-tables.php',
					$script,
					[ "$directory/config.json" ],
					true,
				);
				expect(
					0 === $process['exit'],
					"Cleanup must support the declared database host: $host",
				);
				$state = json_decode(
					file_get_contents( "$directory/state.json" ),
					true,
					512,
					JSON_THROW_ON_ERROR,
				);

				if ( $state['mysqlnd'] && str_contains( $expected_host, ':' ) )
					$expected_host = "[$expected_host]";

				$expected_connection = [ $expected_host, $port, $socket ];
				$actual_connection   = [
					$state['arguments'][0],
					$state['arguments'][4],
					$state['arguments'][5],
				];
				expect(
					$expected_connection === $actual_connection,
					'The connection must retain the host, port, and socket.',
				);
				expect(
					[ 'other_posts', 'tsfb0123456789ab_p1_a10_posts' ] === $state['tables'],
					'Unowned and neighboring-prefix tables must remain.',
				);
			}

			foreach ( [
				[ 'prefix' => "tsfb0123456789ab_p1_a1_\n" ],
				[ 'fail_drop' => true ],
			] as $change ) {
				file_put_contents(
					"$directory/config.json",
					json_encode(
						array_replace( $config, $change ),
						JSON_THROW_ON_ERROR,
					),
				);
				$process = setup_script_run(
					$directory,
					'drop-owned-tables.php',
					$script,
					[ "$directory/config.json" ],
					true,
				);
				expect(
					1 === $process['exit'],
					'Invalid ownership or an unsuccessful drop must fail cleanup.',
				);
				expect(
					'' === $process['stdout'],
					'Failed cleanup must not emit a success receipt.',
				);
				expect(
					! str_contains( $process['stderr'], 'private-' ),
					'Cleanup errors must not leak credentials.',
				);
			}
		} finally {
			TsfBenchmark\remove_owned_tree(
				$directory,
				dirname( $directory ),
			);
		}
	},
];
