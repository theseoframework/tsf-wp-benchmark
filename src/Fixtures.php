<?php
/**
 * Approved fixture provisioning, source verification, and owned cleanup.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

use JsonException;
use RuntimeException;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Validates an existing native target without installing or changing its stack.
 *
 * @param array $target Private target configuration, including database credentials.
 * @return void
 * @throws RuntimeException When the target lacks a required local capability.
 */
function validate_target( array $target ): void {

	if ( isset( $target['downloads'] ) && ! \is_bool( $target['downloads'] ) )
		throw new RuntimeException(
			'Target downloads must be a Boolean.',
		);

	if (
		   isset( $target['provider'] )
		&& ! \in_array(
			$target['provider'],
			[ 'native', 'local' ],
			true,
		)
	) {
		throw new RuntimeException( 'Unknown target provider.' );
	}

	foreach ( [ 'document_root', 'url', 'php', 'database', 'isolation' ] as $key ) {
		if ( ! isset( $target[ $key ] ) )
			throw new RuntimeException( "Missing target field: $key" );
	}

	if ( ! is_dir( $target['document_root'] ) || ! is_file( $target['php'] ) )
		throw new RuntimeException(
			'Select an existing document root and PHP executable.',
		);

	if ( ! empty( $target['php_ini'] ) && ! is_file( $target['php_ini'] ) )
		throw new RuntimeException(
			'The selected PHP ini file does not exist.',
		);

	if ( ! empty( $target['tls_ca'] ) && ! is_file( $target['tls_ca'] ) )
		throw new RuntimeException(
			'The selected TLS CA file does not exist.',
		);

	validate_origin( $target['url'] );
	$db = $target['database'];

	if (
		! preg_match(
			'/^[A-Za-z0-9_$-]+$/',
			$db['name'] ?? '',
		)
		|| ! isset( $db['user'], $db['password'] )
	) {
		throw new RuntimeException(
			'An existing dedicated database and credentials are required.',
		);
	}

	if ( ! preg_match(
		'/^(?:localhost(?::[^\r\n]+)?|127\.0\.0\.1(?::[0-9]+)?)$/',
		$db['host'] ?? '',
	) ) {
		throw new RuntimeException(
			'Only a database on the benchmark host is supported.',
		);
	}

	if ( ! \in_array(
		$target['isolation']['mode'] ?? '',
		[ 'offline', 'external-firewall' ],
		true,
	) ) {
		throw new RuntimeException(
			'Select enforced offline or external-firewall isolation.',
		);
	}

	if ( empty( $target['isolation']['description'] ) )
		throw new RuntimeException(
			'Describe the enforced isolation and how the operator will verify it.',
		);

	if ( isset( $target['worker_supervisor'] ) && normalize_worker_supervisor( $target ) !== $target )
		throw new RuntimeException( 'Supervisor binaries and ports must be frozen before approval.' );
}

/**
 * Builds an argument vector for the approved PHP executable and optional ini file.
 *
 * @param array $target    Validated private target configuration.
 * @param array $arguments Arguments appended without invoking a command shell.
 * @return array Executable and argument strings for execute().
 */
function php_command( array $target, array $arguments ): array {

	$command = [ $target['php'] ];

	if ( ! empty( $target['php_ini'] ) )
		array_push(
			$command,
			'-c',
			$target['php_ini'],
		);

	return array_merge( $command, $arguments );
}

/**
 * Collects distinct pinned archives shared by the declared benchmark arms.
 *
 * @param array $recipe Validated experiment recipe.
 * @return array Package specifications, deduplicated by SHA-256.
 */
function all_packages( array $recipe ): array {

	$packages = [ $recipe['wordpress'] ];

	if ( empty( $recipe['theme']['bundled'] ) )
		$packages[] = $recipe['theme'];

	foreach ( $recipe['arms'] as $arm )
		foreach ( $arm['packages'] as $package )
			$packages[] = $package;

	$unique = [];

	foreach ( $packages as $package )
		$unique[ $package['sha256'] ] = $package;

	return array_values( $unique );
}

/**
 * Verifies approval of the exact saved plan and, normally, its runner version.
 *
 * Cleanup can omit the runner comparison so a reviewed runner update does not
 * strand resources whose ownership remains independently verifiable.
 *
 * @param string $run         Private run directory.
 * @param bool   $check_runner Whether the current runner must match the approved one.
 * @return array The approved plan.
 * @throws RuntimeException When approval or target validation fails.
 */
function approved_plan( string $run, bool $check_runner = true ): array {

	$plan     = read_json( "$run/plan.json" );
	$approval = read_json( "$run/approval.json" );

	if (
		   ( $approval['plan_sha256'] ?? '' ) !== digest( $plan )
		|| empty( $approval['operator'] )
	) {
		throw new RuntimeException(
			'This exact plan requires recorded operator approval.',
		);
	}

	if ( $check_runner && runner_manifest()['sha256'] !== $plan['runner']['sha256'] )
		throw new RuntimeException(
			'Runner changed after approval. Create a new plan; existing cleanup remains available.',
		);

	if ( $check_runner )
		verify_private_recipe(
			$run,
			read_json( "$run/recipe.json" ),
		);

	validate_target( $plan['target'] );

	return $plan;
}

/**
 * Calls the token-protected fixture control endpoint over the approved transport.
 *
 * @param array  $site   Owned fixture identity and access token.
 * @param array  $target Validated private target configuration.
 * @param string $action Supported probe action.
 * @param array  $values Additional action parameters; cannot replace the action.
 * @return array Successful action data.
 * @throws RuntimeException When the control endpoint rejects the request.
 */
function fixture_control(
	array $site,
	array $target,
	string $action,
	array $values = []
): array {

	$response = request(
		$site['url'] . '/tsfbench-probe.php',
		$site['token'],
		[ 'action' => $action ] + $values,
		$target['tls_ca'] ?? null,
	);
	$data     = json_decode(
		$response['body'],
		true,
		512,
		JSON_THROW_ON_ERROR,
	);

	if ( 200 !== $response['status'] || empty( $data['ok'] ) ) {
		throw new RuntimeException(
			'Fixture control failed: ' . ( $data['error'] ?? (string) $response['status'] ),
		);
	}

	return $data['data'];
}

/**
 * Performs one fixture operation in a fresh PHP process and preserves failures.
 *
 * @param array  $plan   Approved run plan.
 * @param string $run    Private run directory.
 * @param array  $site   Owned fixture identity.
 * @param string $action Install, activate, or configure.
 * @return array The operation's private result receipt.
 * @throws RuntimeException When the process fails or omits its receipt.
 */
function run_fixture_command(
	array $plan,
	string $run,
	array $site,
	string $action
): array {

	$config = "$run/fixture-{$site['id']}.json";
	$output = "$run/result-{$site['id']}-$action-" . bin2hex( random_bytes( 4 ) ) . '.json';
	write_json(
		$config,
		[
			'root'   => $site['root'],
			'url'    => $site['url'],
			'action' => $action,
			'arm'    => $site['arm'],
			'recipe' => $plan['recipe'],
			'result' => $output,
		],
	);
	$result = execute( php_command(
		$plan['target'],
		[ \dirname( __DIR__ ) . '/scripts/fixture-cli.php', $config ],
	) );

	if ( 0 !== $result['exit'] || ! is_file( $output ) ) {
		write_bytes(
			"$run/setup-error-{$site['id']}.log",
			$result['stdout'] . "\n" . $result['stderr'],
		);
		throw new RuntimeException(
			"Fixture command failed for {$site['id']}; see its private setup log.",
		);
	}

	return read_json( $output );
}

/**
 * Creates a new fixture from pinned archives and records ownership before setup.
 *
 * The fixture has its own directory, table prefix, salts, and access token.
 * Existing paths and linked ancestors are rejected. Extraction never downloads;
 * prepare must have cached every approved archive before isolation.
 *
 * @param array  $plan  Approved run plan.
 * @param string $run   Private run directory.
 * @param int    $pass  One-based experimental pass.
 * @param int    $index Zero-based arm position used in the table prefix.
 * @param array  $arm   Declared arm and its pinned plugin packages.
 * @return array Private fixture identity, including its control token.
 * @throws RuntimeException When provisioning or an ownership check fails.
 */
function provision_fixture(
	array $plan,
	string $run,
	int $pass,
	int $index,
	array $arm
): array {

	$base = normalized_path( realpath(
		$plan['target']['document_root'],
	) );
	$root = "$base/{$plan['namespace']}/p$pass-{$arm['id']}";
	require_contained( $root, $base );
	require_unlinked_path( $root );

	if ( file_exists( $root ) )
		throw new RuntimeException(
			'Fixture already exists. Refusing to overwrite.',
		);

	$id   = "p$pass-{$arm['id']}";
	$site = [
		'id'     => $id,
		'pass'   => $pass,
		'arm'    => $arm,
		'root'   => $root,
		'url'    => rtrim( $plan['target']['url'], '/' ) . "/{$plan['namespace']}/" . basename( $root ),
		'prefix' => "{$plan['namespace']}_p{$pass}_a{$index}_",
		'token'  => bin2hex( random_bytes( 32 ) ),
	];
	make_directory( $root );
	write_json(
		"$root/.tsfbench-owner.json",
		[
			'run'    => $plan['id'],
			'prefix' => $site['prefix'],
		],
	);

	// Journal before extraction or database work so interrupted setup is recoverable.
	$journal   = is_file( "$run/sites.private.json" ) ? read_json( "$run/sites.private.json" ) : [];
	$journal[] = $site;
	write_json(
		"$run/sites.private.json",
		$journal,
	);
	$cache = $plan['target']['package_cache'] ?? "$run/packages";
	extract_archive(
		fetch_archive(
			$plan['recipe']['wordpress'],
			$cache,
			false,
		),
		$root,
		'wordpress',
	);
	$theme = $plan['recipe']['theme'];

	if ( empty( $theme['bundled'] ) ) {
		$theme_path = "$root/wp-content/themes/{$theme['slug']}";

		if ( is_dir( $theme_path ) )
			remove_owned_tree( $theme_path, $root );

		extract_archive(
			fetch_archive( $theme, $cache, false ),
			$theme_path,
			$theme['slug'],
		);
	}

	$style = file_get_contents(
		"$root/wp-content/themes/{$theme['slug']}/style.css",
	);

	if ( ! preg_match(
		'/^\s*Version:\s*' . preg_quote( $theme['version'], '/' ) . '\s*$/mi',
		$style,
	) ) {
		throw new RuntimeException(
			'Pinned theme version does not match.',
		);
	}

	foreach ( $arm['packages'] as $package ) {
		extract_archive(
			fetch_archive( $package, $cache, false ),
			"$root/wp-content/plugins/{$package['slug']}",
			$package['slug'],
		);
	}

	make_directory( "$root/wp-content/mu-plugins" );

	foreach ( [
		'guard.php' => '/wp-content/mu-plugins/000-benchmark.php',
		'probe.php' => '/tsfbench-probe.php',
	] as $source => $destination ) {
		$bytes = file_get_contents(
			\dirname( __DIR__ ) . "/scripts/$source",
		);

		if ( false === $bytes )
			throw new RuntimeException(
				'Cannot read fixture support script.',
			);

		write_bytes( $root . $destination, $bytes );
	}

	$db        = $plan['target']['database'];
	$constants = [
		'DB_NAME'                    => $db['name'],
		'DB_USER'                    => $db['user'],
		'DB_PASSWORD'                => $db['password'],
		'DB_HOST'                    => $db['host'],
		'DB_CHARSET'                 => 'utf8mb4',
		'WP_HOME'                    => $site['url'],
		'WP_SITEURL'                 => $site['url'],
		// Preserve production plugin behavior, including Yoast's saved indexables.
		'WP_ENVIRONMENT_TYPE'        => 'production',
		'DISABLE_WP_CRON'            => true,
		'AUTOMATIC_UPDATER_DISABLED' => true,
		'WP_AUTO_UPDATE_CORE'        => false,
		'WP_HTTP_BLOCK_EXTERNAL'     => true,
		'WP_DEBUG'                   => false,
		'WP_DEBUG_DISPLAY'           => false,
		'WP_DEBUG_LOG'               => false,
		'SCRIPT_DEBUG'               => false,
		'SAVEQUERIES'                => false,
		'TSF_BENCH_TOKEN'            => $site['token'],
		'TSF_BENCH_RUN'              => $plan['id'],
	];

	foreach ( [
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	] as $key ) {
		$constants[ $key ] = bin2hex( random_bytes( 32 ) );
	}

	write_bytes(
		"$root/wp-config.php",
		render_fixture_config(
			$constants,
			$site['prefix'],
			"$run/diagnostics-$id.log",
		),
	);

	return $site;
}

/**
 * Renders the readable WordPress configuration template using PHP literals.
 *
 * Replacement is simultaneous: marker text inside a credential is never
 * interpreted as another template directive.
 *
 * @param array  $constants Constant names and scalar values for this fixture.
 * @param string $prefix    Owned database table prefix.
 * @param string $error_log  Private error-log destination.
 * @return string Complete PHP configuration source.
 * @throws RuntimeException When the configuration template cannot be read.
 */
function render_fixture_config( array $constants, string $prefix, string $error_log ): string {

	$template = file_get_contents(
		\dirname( __DIR__ ) . '/scripts/stubs/wp-config.php',
	);

	if ( false === $template )
		throw new RuntimeException(
			'Cannot read the WordPress configuration template.',
		);

	$definitions = [];

	foreach ( $constants as $key => $value )
		$definitions[] = 'define( ' . var_export( $key, true ) . ', ' . var_export( $value, true ) . ' );';

	return strtr(
		$template,
		[
			'/* TSFB_DEFINITIONS */'     => implode( "\n", $definitions ),
			"/* TSFB_TABLE_PREFIX */ ''" => var_export( $prefix, true ),
			"/* TSFB_ERROR_LOG */ ''"    => var_export( $error_log, true ),
		],
	);
}

/**
 * Records source identity without reading minified assets or private configuration.
 *
 * @param array $site Owned fixture identity.
 * @return array Digest, relative-path hashes, and the source-receipt scope.
 * @throws RuntimeException When a source entry is linked.
 */
function verify_source_tree( array $site ): array {

	$files = [];
	$root  = $site['root'];

	foreach (
		new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$root,
			\FilesystemIterator::SKIP_DOTS,
			),
		) as $entry
	) {
		if ( $entry->isLink() )
			throw new RuntimeException(
				'Unexpected linked fixture source.',
			);

		$relative = substr(
			normalized_path( $entry->getPathname() ),
			\strlen( $root ) + 1,
		);

		// Uploads and private runtime configuration are outside this source receipt.
		if (
			   $entry->isFile()
			&& preg_match(
				'/\.(?:php|css|js)$/i',
				$relative,
			)
			&& ! preg_match(
				'/\.min\.(?:js|css)$/i',
				$relative,
			)
			&& 'wp-config.php' !== $relative
			&& ! str_starts_with(
				$relative,
				'wp-content/uploads/',
			)
		) {
			$files[ $relative ] = hash_file(
				'sha256',
				$entry->getPathname(),
			);
		}
	}

	ksort( $files );

	return [
		'sha256' => digest( $files ),
		'files'  => $files,
		'note'   => 'PHP and unminified CSS/JS source receipt; complete distribution identity is pinned by archive SHA-256.',
	];
}

/**
 * Removes only fixture files and tables authorized by the saved run identity.
 *
 * Reconcile each journal entry with its exact approved arm, pass, directory, and
 * prefix before reading ownership or dropping tables. The durable journal also
 * covers tables orphaned by a previous interrupted file cleanup. File removal
 * requires a successful database receipt confirming no owned tables remain.
 *
 * @param array  $plan Approved plan, including its original resource namespace.
 * @param string $run  Private run directory containing the ownership journal.
 * @return array Per-site file and table cleanup evidence.
 * @throws RuntimeException When ownership or cleanup cannot be established.
 */
function cleanup_fixtures( array $plan, string $run ): array {

	$sites = is_file( "$run/sites.private.json" ) ? read_json( "$run/sites.private.json" ) : [];
	$proof = [];

	foreach ( $sites as $site ) {
		$root = normalized_path( realpath(
			$plan['target']['document_root'],
		) );
		require_contained(
			$site['root'],
			"$root/{$plan['namespace']}",
		);
		$arm_index = array_search(
			$site['arm']['id'],
			array_column( $plan['recipe']['arms'], 'id' ),
			true,
		);

		if (
			   false === $arm_index
			|| "{$plan['namespace']}_p{$site['pass']}_a{$arm_index}_" !== $site['prefix']
			|| 1 > $site['pass']
			|| $site['pass'] > $plan['recipe']['measurement']['passes']
		) {
			throw new RuntimeException(
				'Cleanup journal does not match the approved namespace.',
			);
		}

		$expected_id = "p{$site['pass']}-{$site['arm']['id']}";

		if (
			   $site['id'] !== $expected_id
			|| "$root/{$plan['namespace']}/$expected_id" !== $site['root']
		) {
			throw new RuntimeException(
				'Cleanup journal directory does not match its approved fixture.',
			);
		}

		require_unlinked_path( $site['root'] );

		if ( file_exists( $site['root'] ) && ! is_dir( $site['root'] ) )
			throw new RuntimeException(
				'The fixture path no longer names its owned directory.',
			);

		if ( is_dir( $site['root'] ) ) {
			require_unlinked_path(
				$site['root'] . '/.tsfbench-owner.json',
			);
			$owner = read_json(
				$site['root'] . '/.tsfbench-owner.json',
			);

			if (
				   ( $owner['run'] ?? null ) !== $plan['id']
				|| ( $owner['prefix'] ?? null ) !== $site['prefix']
			) {
				throw new RuntimeException(
					'Fixture ownership check failed.',
				);
			}
		}

		// The journal identifies orphaned tables even when files were already removed.
		$config = "$run/cleanup-input.private.json";
		write_json(
			$config,
			[
				'database' => $plan['target']['database'],
				'prefix'   => $site['prefix'],
			],
		);
		try {
			$result = execute( php_command(
				$plan['target'],
				[ \dirname( __DIR__ ) . '/scripts/drop-owned-tables.php', $config ],
			) );
		} finally {
			unlink( $config );
		}

		if ( 0 !== $result['exit'] )
			throw new RuntimeException(
				'Owned database cleanup failed; remaining fixtures retained for recovery.',
			);

		try {
			$tables = json_decode(
				$result['stdout'],
				true,
				512,
				\JSON_THROW_ON_ERROR,
			);
		} catch ( JsonException $error ) {
			throw new RuntimeException(
				'Owned database cleanup returned an invalid receipt; fixture files retained.',
			);
		}

		if (
			   ! \is_array( $tables )
			|| 0 !== ( $tables['remaining'] ?? null )
			|| ! \is_array( $tables['removed'] ?? null )
			|| ! array_is_list( $tables['removed'] )
		) {
			throw new RuntimeException(
				'Owned database cleanup is unverified; fixture files retained.',
			);
		}

		foreach ( $tables['removed'] as $table ) {
			if (
				   ! \is_string( $table )
				|| ! str_starts_with( $table, $site['prefix'] )
				|| ! preg_match(
					'/\A[A-Za-z0-9_]+\z/',
					$table,
				)
			) {
				throw new RuntimeException(
					'Cleanup receipt names an unowned table; fixture files retained.',
				);
			}
		}

		if ( file_exists( $site['root'] ) )
			remove_owned_tree( $site['root'], $root );

		$proof[] = [
			'id'            => $site['id'],
			'files_removed' => ! file_exists( $site['root'] ),
			'tables'        => $tables,
		];
	}

	$directory = normalized_path( realpath(
		$plan['target']['document_root'],
	) ) . '/' . $plan['namespace'];

	if ( is_dir( $directory ) && 2 === \count( scandir( $directory ) ) )
		rmdir( $directory );

	return [
		'sites' => $proof,
		'scope' => 'Cleanup is restricted to the approved fixture directories and deterministic table prefixes.',
	];
}
