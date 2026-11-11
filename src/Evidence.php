<?php
/**
 * Private evidence validation and reproducible public exports.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

use RuntimeException;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Remove private machine metadata while preserving measured performance facts.
 *
 * Paths in diagnostic text are redacted as well as explicitly private fields.
 * The operation is idempotent so exported observations can be replayed directly.
 * Actual fixture content and source manifests use separate export allowlists.
 *
 * @param array $environment Environment, telemetry, or diagnostic metadata.
 * @return array Public metadata with private fields excluded.
 */
function public_environment( array $environment ): array {

	$clean = function ( array $node ) use ( &$clean ): array {

		$result = [];
		foreach ( $node as $key => $value ) {
			$name = strtolower( (string) $key );

			if (
				preg_match(
					'/password|passwd|secret|token|cookie|authorization|credential|api[_-]?key|auth[_-]?key|nonce/',
					$name,
				)
				|| \in_array(
					$name,
					[
						'binary',
						'commands',
						'computer_name',
						'cwd',
						'document_root',
						'evidence',
						'home',
						'hostname',
						'local_application',
						'logo_url',
						'operator',
						'php_binary',
						'url',
						'user',
						'username',
					],
					true,
				)
			) {
				continue;
			}

			if ( 'opcache.file_cache' === $name ) {
				$result[ $key ] = '' === $value || false === $value || null === $value ? '' : '[configured]';
			} elseif ( \is_array( $value ) ) {
				$result[ $key ] = $clean( $value );
			} elseif ( \is_string( $value ) ) {
				// Diagnostic strings can contain paths even when their field name is harmless.
				$result[ $key ] = preg_replace(
					'/(?:[a-zA-Z]:[\\\\\/]|\\\\\\\\|\/(?:Users|home|private|tmp|var|Applications|Library|Volumes|mnt)\/)[^\r\n]*/',
					'[local path redacted]',
					$value,
				);
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	};

	return $clean( $environment );
}

/**
 * Require a complete cleanup receipt for every declared arm in every pass.
 *
 * Missing, duplicate, or extra fixture entries do not establish cleanup. Both
 * file removal and an integer zero count of remaining tables must be recorded.
 *
 * @param array $recipe  Experiment definition.
 * @param array $cleanup Cleanup receipt containing fixture results.
 * @return bool Whether every expected resource has a successful receipt.
 */
function cleanup_verified( array $recipe, array $cleanup ): bool {

	$passes = $recipe['measurement']['passes'] ?? null;
	$arms   = array_column( $recipe['arms'] ?? [], 'id' );

	if (
		   ! \is_int( $passes )
		|| 1 > $passes
		|| 100 < $passes
		|| ! $arms
		|| \count( array_unique(
			$arms,
			\SORT_STRING,
		) ) !== \count( $arms )
		|| ! \is_array( $cleanup['sites'] ?? null )
	) {
		return false;
	}
	$expected = [];
	for ( $pass = 1; $pass <= $passes; ++$pass )
		foreach ( $arms as $arm )
			$expected[ 'p' . $pass . '-' . $arm ] = true;
	$seen = [];
	foreach ( $cleanup['sites'] as $site ) {
		$id = $site['id'] ?? null;

		if (
			   ! \is_string( $id )
			|| ! isset( $expected[ $id ] )
			|| isset( $seen[ $id ] )
			|| true !== ( $site['files_removed'] ?? null )
			|| 0 !== ( $site['tables']['remaining'] ?? null )
		) {
			return false;
		}
		$seen[ $id ] = true;
	}

	return \count( $seen ) === \count( $expected );
}

/**
 * Resolve a direct regular evidence file without following links out of the run.
 *
 * @param string $run  Normalized, resolved run directory.
 * @param string $name Allowlisted direct filename.
 * @return ?string Existing file path, or null when the optional file is absent.
 * @throws RuntimeException When the entry is linked or escapes the directory.
 */
function evidence_input( string $run, string $name ): ?string {

	$path = $run . '/' . $name;

	if ( ! file_exists( $path ) && ! is_link( $path ) )
		return null;

	if ( ! is_file( $path ) || is_link( $path ) )
		throw new RuntimeException(
			'Linked or non-file evidence is refused: ' . $name,
		);
	$resolved = realpath( $path );

	if ( false === $resolved || normalized_path( \dirname( $resolved ) ) !== $run )
		throw new RuntimeException(
			'Evidence file resolves outside its run directory.',
		);

	return $path;
}

/**
 * Prevent a private run from describing inputs other than its approved recipe.
 *
 * Collection uses the plan's recipe, whereas replay reads recipe.json. Checking
 * their identity catches accidental edits between approval and reporting. Public
 * exports deliberately omit the private plan and replay their exported recipe.
 *
 * @param string $run    Existing private run or public evidence directory.
 * @param array  $recipe Recipe about to be used for collection or analysis.
 * @return void
 * @throws RuntimeException When a private plan disagrees with its recipe.
 */
function verify_private_recipe( string $run, array $recipe ): void {

	$resolved = realpath( $run );

	if ( false === $resolved )
		throw new RuntimeException(
			'Recipe evidence directory does not exist.',
		);

	$input = evidence_input(
		normalized_path( $resolved ),
		'plan.json',
	);

	if ( null === $input ) return;

	$plan = read_json( $input );

	if (
		   ! \is_array( $plan['recipe'] ?? null )
		|| digest( $plan['recipe'] ) !== digest( $recipe )
	) {
		throw new RuntimeException(
			'The run recipe differs from its private plan. Preserve the evidence and create a new plan for changed inputs.',
		);
	}
}

/**
 * Stream public observation fields without copying private per-request extras.
 *
 * @param string $input  Source NDJSON observations.
 * @param string $output Fresh output path.
 * @return array Exported row count and number of excluded extra fields.
 * @throws RuntimeException When an observation or output stream is invalid.
 */
function export_samples( string $input, string $output ): array {

	$allowed = array_fill_keys(
		[
			'arm',
			'block',
			'bytes',
			'elapsed_ms',
			'monotonic_ns',
			'output_sha256',
			'pass',
			'phase',
			'position',
			'reasons',
			'route',
			'sequence',
			'status',
			'timestamp',
			'utc',
			'valid',
		],
		true,
	);
	$source  = fopen( $input, 'rb' );

	if ( false === $source )
		throw new RuntimeException(
			'Cannot open public observations.',
		);

	$destination = fopen( $output, 'xb' );

	if ( false === $destination ) {
		fclose( $source );
		throw new RuntimeException(
			'Cannot create public observations.',
		);
	}
	$rows = $removed = 0;
	try {
		for ( $line = fgets( $source ); false !== $line; $line = fgets( $source ) ) {
			if ( '' === trim( $line ) ) continue;
			$record = json_decode(
				$line,
				true,
				32,
				\JSON_THROW_ON_ERROR,
			);

			if ( ! \is_array( $record ) )
				throw new RuntimeException(
					'Malformed observation cannot be exported.',
				);
			$public   = array_intersect_key( $record, $allowed );
			$removed += \count( $record ) - \count( $public );
			$public   = public_environment( $public );
			$encoded  = json_encode(
				$public,
				\JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
			) . "\n";

			if ( fwrite( $destination, $encoded ) !== \strlen( $encoded ) )
				throw new RuntimeException(
					'Public observations could not be written completely.',
				);
			++$rows;
		}
	} finally {
		fclose( $source );
		fclose( $destination );
	}

	return [
		'rows'                 => $rows,
		'extra_fields_removed' => $removed,
	];
}

/**
 * Verify source-map and selected-output identities before exporting receipts.
 *
 * These hashes establish internal consistency, not independent attestation that
 * an experiment was run. Source paths must remain relative to their package.
 *
 * @param string $name Receipt filename, used to select its schema.
 * @param array  $data Decoded receipt contents.
 * @return void
 * @throws RuntimeException When a path or recorded digest is invalid.
 */
function validate_public_receipt( string $name, array $data ): void {

	if ( str_starts_with( $name, 'source-' ) || 'runner.json' === $name ) {
		if (
			   ! \is_array( $data['files'] ?? null )
			|| ! $data['files']
			|| ! \is_string( $data['sha256'] ?? null )
			|| ! hash_equals(
				digest( $data['files'] ),
				$data['sha256'],
			)
		) {
			throw new RuntimeException(
				'Source manifest digest does not match: ' . $name,
			);
		}
		foreach ( $data['files'] ?? [] as $path => $hash ) {
			if (
				   ! \is_string( $path )
				|| '' === $path
				|| str_starts_with( $path, '/' )
				|| str_contains( $path, ':' )
				|| str_contains( $path, '\\' )
				|| preg_match(
					'/(?:^|\/)\.\.?(?:\/|$)|[\x00-\x1f]/',
					$path,
				)
				|| ! \is_string( $hash )
				|| ! preg_match( '/\A[a-f0-9]{64}\z/D', $hash )
			) {
				throw new RuntimeException(
					'Unsafe relative source receipt: ' . $name,
				);
			}
		}
	}

	if ( str_starts_with( $name, 'output-' ) ) {
		if (
			   ! \is_array( $data['selection'] ?? null )
			|| ! array_is_list( $data['selection'] )
			|| \count( array_filter(
				$data['selection'],
				'is_string',
			) ) !== \count( $data['selection'] )
			|| ! \is_string( $data['sha256'] ?? null )
			|| ! hash_equals(
				hash(
					'sha256',
					implode( "\n", $data['selection'] ),
				),
				$data['sha256'],
			)
		) {
			throw new RuntimeException(
				'Output selection digest does not match: ' . $name,
			);
		}
	}
}

/**
 * Keep only recipe fields consumed by the runner or presented as public context.
 *
 * Unknown fields can contain local planning notes or credentials. They have no
 * effect on the experiment and must not hitchhike into its public evidence.
 * Actual fixture content is preserved because redacting it changes the recipe.
 *
 * @param array $recipe Validated recipe.
 * @return array Recipe with undeclared metadata excluded.
 */
function public_recipe( array $recipe ): array {

	$select              = function ( array $value, array $keys ): array {
		return array_intersect_key(
			$value,
			array_fill_keys( $keys, true ),
		);
	};
	$public              = $select(
		$recipe,
		[
			'arms',
			'comparison',
			'description',
			'environment',
			'fixtures',
			'id',
			'measurement',
			'name',
			'reference',
			'reference_parent_sha256',
			'routes',
			'schema',
			'theme',
			'wordpress',
		],
	);
	$package             = function ( array $value ) use ( $select ): array {

		validate_public_package_url( $value['url'] );

		return $select(
			$value,
			[ 'slug', 'version', 'url', 'sha256', 'main', 'bundled' ],
		);
	};
	$public['wordpress'] = $package( $recipe['wordpress'] );
	$public['theme']     = $package( $recipe['theme'] );
	$public['arms']      = array_map(
		function ( array $arm ) use ( $select, $package ): array {

			$arm             = $select(
				$arm,
				[ 'id', 'name', 'packages', 'setup' ],
			);
			$arm['packages'] = array_map( $package, $arm['packages'] );

			return $arm;
		},
		$recipe['arms'],
	);
	$public['routes']    = array_map(
		function ( array $route ) use ( $select ): array {
			return $select(
				$route,
				[ 'id', 'path', 'context', 'status', 'kind', 'query' ],
			);
		},
		$recipe['routes'],
	);
	$schemas             = [
		'comparison'  => [ 'anchor', 'threshold', 'confidence' ],
		'environment' => [ 'reference_php', 'opcache', 'profiling', 'network' ],
		'fixtures'    => [
			'content_html',
			'date',
			'description',
			'pages',
			'paragraphs',
			'posts',
			'posts_per_page',
			'title',
		],
		'measurement' => [ 'passes', 'warmups', 'samples', 'seed', 'bootstrap' ],
	];
	foreach ( $schemas as $key => $keys ) {
		if ( isset( $public[ $key ] ) )
			$public[ $key ] = $select( $public[ $key ], $keys );
	}

	return $public;
}

/**
 * Refuse package URLs that would disclose access credentials in public evidence.
 *
 * Ordinary public query parameters remain supported. Private download URLs must
 * be replaced with a redistributable artifact before exporting an experiment.
 *
 * @param string $url Source package URL.
 * @return void
 * @throws RuntimeException When credentials are present in the URL.
 */
function validate_public_package_url( string $url ): void {

	$parts = parse_url( $url );

	if ( ! $parts || isset( $parts['user'] ) || isset( $parts['pass'] ) )
		throw new RuntimeException(
			'Credential-bearing package URLs cannot be exported.',
		);

	foreach ( explode( '&', $parts['query'] ?? '' ) as $parameter ) {
		$name = rawurldecode(
			explode( '=', $parameter, 2 )[0],
		);

		if ( preg_match(
			'/token|password|passwd|secret|signature|credential|authorization|api[_-]?key|access[_-]?key|auth|nonce|^(?:key|sig|jwt|bearer|session|sessionid)$/i',
			$name,
		) ) {
			throw new RuntimeException(
				'Credential-bearing package URLs cannot be exported.',
			);
		}
	}
}

/**
 * Verify that a public export contains exactly its declared valid artifacts.
 *
 * The manifest protects integrity after export. Anyone able to replace all
 * evidence and its manifest can create another internally consistent package.
 * Source and output receipts must also satisfy their own identity checks.
 *
 * @param string $directory Existing public evidence directory.
 * @return array Verification result, file count, and manifest fingerprint.
 * @throws RuntimeException When an artifact is missing, altered, or undeclared.
 */
function verify_evidence_manifest( string $directory ): array {

	$resolved = realpath( $directory );

	if ( false === $resolved )
		throw new RuntimeException(
			'Evidence directory does not exist.',
		);
	$directory = normalized_path( $resolved );
	$input     = evidence_input( $directory, 'manifest.json' );

	if ( null === $input )
		throw new RuntimeException(
			'Public evidence manifest is missing.',
		);
	$manifest = read_json( $input );

	if (
		   1 !== ( $manifest['schema'] ?? null )
		|| 'public-benchmark-evidence' !== ( $manifest['type'] ?? null )
		|| ! \is_array( $manifest['files'] ?? null )
		|| ! $manifest['files']
	) {
		throw new RuntimeException(
			'Unsupported public evidence manifest.',
		);
	}
	foreach ( $manifest['files'] as $name => $file ) {
		if (
			   ! \is_string( $name )
			|| ! preg_match(
				'/\A[a-zA-Z0-9_.-]+\z/D',
				$name,
			)
			|| 'manifest.json' === $name
			|| ! \is_array( $file )
		) {
			throw new RuntimeException( 'Unsafe public artifact name.' );
		}
		$path = evidence_input( $directory, $name );

		if (
			   null === $path
			|| ! \is_int( $file['bytes'] ?? null )
			|| filesize( $path ) !== $file['bytes']
			|| ! \is_string( $file['sha256'] ?? null )
			|| ! hash_equals(
				$file['sha256'],
				hash_file( 'sha256', $path ),
			)
		) {
			throw new RuntimeException(
				'Public artifact failed verification: ' . $name,
			);
		}

		if (
			   'runner.json' === $name
			|| preg_match(
				'/\A(?:source|output)-.*\.json\z/D',
				$name,
			)
		) {
			validate_public_receipt( $name, read_json( $path ) );
		}
	}
	foreach ( new \DirectoryIterator( $directory ) as $entry ) {
		if (
			   $entry->isDot()
			|| 'manifest.json' === $entry->getFilename()
		) {
			continue;
		}

		if ( ! isset(
			$manifest['files'][ $entry->getFilename() ],
		) ) {
			throw new RuntimeException(
				'Unmanifested file in public evidence: ' . $entry->getFilename(),
			);
		}
	}

	return [
		'verified'        => true,
		'files'           => \count( $manifest['files'] ),
		'manifest_sha256' => hash_file( 'sha256', $input ),
	];
}

/**
 * Create a fresh public export and regenerate its reports from public inputs.
 *
 * Files are allowlisted individually. Private plans, approvals, logs, database
 * configuration, and fixture credentials are excluded. Incomplete experiments
 * can be exported for diagnosis but cannot acquire a qualified conclusion.
 * Existing public evidence must pass its original integrity manifest first.
 * The destination appears only after the complete temporary export succeeds.
 *
 * @param string $run         Existing run directory.
 * @param string $destination Fresh destination under an existing parent.
 * @return array Export location, artifact count, cleanup status, and verdict.
 * @throws RuntimeException When evidence is unsafe or export cannot complete.
 * @throws \Throwable Rethrows an export failure after removing its incomplete owned output.
 */
function export_evidence( string $run, string $destination ): array {

	$resolved = realpath( $run );

	if ( false === $resolved || ! is_dir( $resolved ) )
		throw new RuntimeException( 'Evidence run does not exist.' );
	$run = normalized_path( $resolved );

	if ( null !== evidence_input( $run, 'manifest.json' ) )
		verify_evidence_manifest( $run );

	if ( file_exists( $destination ) || is_link( $destination ) )
		throw new RuntimeException(
			'Choose a fresh export directory.',
		);
	$destination = absolute_path( $destination );
	$parent      = normalized_path( \dirname( $destination ) );
	$temporary   = $parent . '/.tsf-export-' . bin2hex( random_bytes( 8 ) );
	make_directory( $temporary );
	$source_hashes   = [];
	$transformations = [
		'Environment paths and credential fields were removed.',
		'Undeclared recipe and receipt metadata was excluded; experiment inputs are preserved.',
		'Samples retain the public measurement columns; private extras are excluded.',
		'Summary, HTML, and CSV were regenerated from the exported observations.',
	];
	try {
		$recipe_path = evidence_input( $run, 'recipe.json' );

		if ( null === $recipe_path )
			throw new RuntimeException(
				'A recipe is required for public evidence.',
			);
		$recipe = read_json( $recipe_path );
		validate_recipe( $recipe );
		verify_private_recipe( $run, $recipe );
		$names = [ 'recipe.json', 'runner.json', 'packages.json', 'cleanup.json' ];
		foreach ( new \DirectoryIterator( $run ) as $entry ) {
			$name = $entry->getFilename();

			if ( preg_match(
				'/\A(?:source|setup|output|isolation)-p[1-9][0-9]*-[a-z0-9_-]+\.json\z/D',
				$name,
			) ) {
				$names[] = $name;
			}
		}
		sort( $names, \SORT_STRING );
		foreach ( $names as $name ) {
			$input = evidence_input( $run, $name );

			if ( null === $input ) continue;
			$data = read_json( $input );
			validate_public_receipt( $name, $data );
			$source_hashes[ $name ] = hash_file( 'sha256', $input );

			if ( 'recipe.json' === $name )
				$data = public_recipe( $data );

			if (
				   'cleanup.json' === $name
				|| str_starts_with( $name, 'setup-' )
				|| str_starts_with( $name, 'isolation-' )
			) {
				$data = public_environment( $data );
			}

			if ( str_starts_with( $name, 'source-' ) )
				$data = array_intersect_key(
					$data,
					array_fill_keys(
						[ 'files', 'sha256' ],
						true,
					),
				);

			if ( 'runner.json' === $name )
				$data = array_intersect_key(
					$data,
					array_fill_keys(
						[ 'version', 'files', 'sha256' ],
						true,
					),
				);

			if ( 'packages.json' === $name )
				$data = array_map(
					function ( array $package ): array {

						validate_public_package_url( $package['url'] );

						return array_intersect_key(
							$package,
							array_fill_keys(
								[ 'url', 'version', 'sha256', 'bytes', 'slug' ],
								true,
							),
						);
					},
					$data,
				);

			if ( str_starts_with( $name, 'output-' ) )
				$data = array_intersect_key(
					$data,
					array_fill_keys(
						[ 'selection', 'sha256' ],
						true,
					),
				);
			write_json(
				$temporary . '/' . $name,
				$data,
			);
		}
		$input = evidence_input( $run, 'samples.ndjson' );

		if ( null !== $input ) {
			$source_hashes['samples.ndjson'] = hash_file( 'sha256', $input );
			$observations                    = export_samples(
				$input,
				$temporary . '/samples.ndjson',
			);
		} else {
			write_bytes(
				$temporary . '/samples.ndjson',
				'',
			);
			$observations = [
				'rows'                 => 0,
				'extra_fields_removed' => 0,
			];
		}
		$input       = evidence_input( $run, 'environment.json' );
		$environment = null === $input ? [ 'blockers' => [ 'Environment evidence missing.' ] ] : public_environment( read_json( $input ) );

		if ( null !== $input )
			$source_hashes['environment.json'] = hash_file( 'sha256', $input );
		write_json(
			$temporary . '/environment.json',
			$environment,
		);
		foreach ( new \DirectoryIterator( $run ) as $entry ) {
			$name = $entry->getFilename();

			if ( ! preg_match(
				'/\Atelemetry-p[1-9][0-9]*\.ndjson\z/D',
				$name,
			) ) {
				continue;
			}
			$input                  = evidence_input( $run, $name );
			$source_hashes[ $name ] = hash_file( 'sha256', $input );
			$source                 = fopen( $input, 'rb' );

			if ( false === $source )
				throw new RuntimeException(
					'Cannot open telemetry evidence.',
				);

			$output = fopen( $temporary . '/' . $name, 'xb' );

			if ( false === $output ) {
				fclose( $source );
				throw new RuntimeException(
					'Cannot create public telemetry evidence.',
				);
			}
			try {
				for ( $line = fgets( $source ); false !== $line; $line = fgets( $source ) ) {
					if ( '' === trim( $line ) ) continue;
					$row = json_decode(
						$line,
						true,
						64,
						\JSON_THROW_ON_ERROR,
					);

					if (
						   ! \is_array( $row )
						|| ! isset( $row['timestamp'] )
						|| ! \is_array( $row['metrics'] ?? null )
					) {
						throw new RuntimeException( 'Malformed telemetry receipt.' );
					}
					$public  = public_environment( array_intersect_key(
						$row,
						array_fill_keys(
							[ 'schema', 'timestamp', 'monotonic_seconds', 'metrics', 'collector' ],
							true,
						),
					) );
					$encoded = json_encode(
						$public,
						\JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
					) . "\n";

					if ( fwrite( $output, $encoded ) !== \strlen( $encoded ) )
						throw new RuntimeException(
							'Telemetry export was truncated.',
						);
				}
			} finally {
				fclose( $source );
				fclose( $output );
			}
		}
		$cleanup = is_file( $temporary . '/cleanup.json' ) ? read_json( $temporary . '/cleanup.json' ) : [];
		$clean   = cleanup_verified( $recipe, $cleanup );

		if (
			   ! $clean
			&& ! \in_array(
				'Owned-resource cleanup has not been verified.',
				$environment['blockers'] ?? [],
				true,
			)
		) {
			$environment['blockers'][] = 'Owned-resource cleanup has not been verified.';
			write_json(
				$temporary . '/environment.json',
				$environment,
			);
		}
		$report   = report_run( $temporary );
		$manifest = [
			'schema'           => 1,
			'type'             => 'public-benchmark-evidence',
			'cleanup_verified' => $clean,
			'verdict'          => $report['verdict'],
			'observations'     => $observations,
			'transformations'  => $transformations,
			'source_sha256'    => $source_hashes,
			'files'            => [],
		];
		foreach ( new \DirectoryIterator( $temporary ) as $entry ) {
			if ( ! $entry->isFile() ) continue;
			$manifest['files'][ $entry->getFilename() ] = [
				'sha256' => hash_file(
					'sha256',
					$entry->getPathname(),
				),
				'bytes'  => $entry->getSize(),
			];
		}
		ksort( $manifest['source_sha256'] );
		ksort( $manifest['files'] );
		write_json(
			$temporary . '/manifest.json',
			$manifest,
		);

		if (
			   file_exists( $destination )
			|| is_link( $destination )
			|| ! rename( $temporary, $destination )
		) {
			throw new RuntimeException(
				'Cannot publish the fresh export directory.',
			);
		}

		return [
			'directory'        => $destination,
			'files'            => \count( $manifest['files'] ) + 1,
			'cleanup_verified' => $clean,
			'verdict'          => $manifest['verdict'],
			'manifest_sha256'  => hash_file(
				'sha256',
				$destination . '/manifest.json',
			),
		];
	} catch ( \Throwable $error ) {
		remove_owned_tree( $temporary, $parent );
		throw $error;
	}
}
