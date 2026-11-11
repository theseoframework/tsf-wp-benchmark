<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

foreach ( [ 'Common', 'Protocol', 'Report', 'Runner', 'Evidence' ] as $module )
	require_once __DIR__ . '/../src/' . $module . '.php';
require_once __DIR__ . '/analysis.php';

/**
 * Require an invariant without depending on PHP assertion configuration.
 *
 * @param bool   $condition Expected truth value.
 * @param string $message   Failure explanation.
 * @return void
 * @throws RuntimeException When the required invariant is false.
 */
function evidence_expect( bool $condition, string $message ): void {
	if ( ! $condition )
		throw new RuntimeException( $message );
}

/**
 * Require export or verification to reject an invalid artifact.
 *
 * @param callable $callback Operation expected to fail.
 * @param string   $message  Failure explanation if it unexpectedly succeeds.
 * @return void
 * @throws RuntimeException When the operation unexpectedly succeeds.
 */
function evidence_throws( callable $callback, string $message ): void {

	try {
		$callback();
	} catch ( Throwable ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Build a complete synthetic cleanup receipt without creating WordPress sites.
 *
 * @param array $recipe Synthetic recipe defining every expected fixture.
 * @return array Successful test-only cleanup entries.
 */
function evidence_cleanup( array $recipe ): array {

	$sites = [];
	for ( $pass = 1; $pass <= $recipe['measurement']['passes']; ++$pass )
		foreach ( $recipe['arms'] as $arm )
			$sites[] = [
				'id'            => 'p' . $pass . '-' . $arm['id'],
				'files_removed' => true,
				'tables'        => [
					'remaining' => 0,
					'removed'   => [ 'posts', 'options' ],
				],
			];
	return [
		'sites'                       => $sites,
		'unrelated_resources_touched' => false,
	];
}

/**
 * Create one disposable test directory inside this repository's local area.
 *
 * @return string[] Resolved parent and test directory for contained cleanup.
 */
function evidence_test_directory(): array {

	$parent = dirname( __DIR__ ) . '/.local';
	TsfBenchmark\make_directory( $parent );
	$parent = TsfBenchmark\normalized_path( realpath( $parent ) );
	$path   = $parent . '/evidence-test-' . bin2hex( random_bytes( 5 ) );
	TsfBenchmark\make_directory( $path );
	return [ $parent, $path ];
}

return [
	'public environment removes paths and secrets without losing measured facts' => function (): void {
		$input  = [
			'host'      => [
				'cli_php'           => [
					'version' => '8.4.4',
					'binary'  => 'C:\\Users\\PRIVATE_SENTINEL\\php.exe',
				],
				'commands'          => [ 'php' => '/Users/PRIVATE_SENTINEL/php' ],
				'local_application' => '/Users/PRIVATE_SENTINEL/Local.app',
			],
			'runtime'   => [
				'opcache' => [
					'ini' => [
						'opcache.file_cache'         => '/Users/PRIVATE_SENTINEL/cache',
						'opcache.memory_consumption' => '512',
					],
				],
			],
			'telemetry' => [
				'cpu' => [
					'source' => 'Native CPU counter',
					'value'  => 12.5,
					'unit'   => 'percent',
				],
			],
			'token'     => 'PRIVATE_SENTINEL',
			'nested'    => [
				'password'     => 'PRIVATE_SENTINEL',
				'note'         => 'Permission denied at C:\\Users\\PRIVATE_SENTINEL\\cache',
				'network_note' => 'Denied: \\\\server\\PRIVATE_SENTINEL\\share',
			],
		];
		$public = TsfBenchmark\public_environment( $input );
		evidence_expect(
			! str_contains(
				json_encode( $public ),
				'PRIVATE_SENTINEL',
			),
			'Private metadata leaked.',
		);
		evidence_expect(
			'[configured]' === $public['runtime']['opcache']['ini']['opcache.file_cache'],
			'Cache presence was lost.',
		);
		evidence_expect(
			12.5 === $public['telemetry']['cpu']['value'],
			'Measured values changed.',
		);
		evidence_expect(
			TsfBenchmark\public_environment( $public ) === $public,
			'Redaction must be idempotent for replay.',
		);
	},
	'cleanup requires every expected fixture and an actual zero-table remainder' => function (): void {
		$recipe  = analysis_recipe();
		$cleanup = evidence_cleanup( $recipe );
		evidence_expect(
			TsfBenchmark\cleanup_verified( $recipe, $cleanup ),
			'Complete cleanup was rejected.',
		);
		$bad = $cleanup;
		array_pop( $bad['sites'] );
		evidence_expect(
			! TsfBenchmark\cleanup_verified( $recipe, $bad ),
			'Missing fixture qualified.',
		);
		$bad            = $cleanup;
		$bad['sites'][] = $bad['sites'][0];
		evidence_expect(
			! TsfBenchmark\cleanup_verified( $recipe, $bad ),
			'Duplicate fixture qualified.',
		);
		foreach ( [ 1, '0', null ] as $remaining ) {
			$bad                                    = $cleanup;
			$bad['sites'][0]['tables']['remaining'] = $remaining;
			evidence_expect(
				! TsfBenchmark\cleanup_verified( $recipe, $bad ),
				'Unverified table cleanup qualified.',
			);
		}
		$bad                              = $cleanup;
		$bad['sites'][0]['files_removed'] = false;
		evidence_expect(
			! TsfBenchmark\cleanup_verified( $recipe, $bad ),
			'Remaining files qualified.',
		);
	},
	'public export replays offline and excludes private files and fields' => function (): void {
		[ $parent, $base ] = evidence_test_directory();
		try {
			$run         = $base . '/run';
			$destination = $base . '/public';
			TsfBenchmark\make_directory( $run );
			$recipe                         = analysis_recipe();
			$recipe['measurement']          = [
				'passes'    => 1,
				'warmups'   => 0,
				'samples'   => 1,
				'bootstrap' => 2,
				'seed'      => 521,
			];
			$recipe['private_note']         = 'PRIVATE_SENTINEL';
			$recipe['arms'][1]['api_token'] = 'PRIVATE_SENTINEL';
			$recipe['arms'][1]['packages'][0]['private_path'] = 'PRIVATE_SENTINEL';
			TsfBenchmark\write_json( $run . '/recipe.json', $recipe );
			TsfBenchmark\write_json(
				$run . '/plan.json',
				[
					'recipe' => $recipe,
					'token'  => 'PRIVATE_SENTINEL',
				],
			);
			TsfBenchmark\write_json(
				$run . '/runner.json',
				TsfBenchmark\runner_manifest(),
			);
			TsfBenchmark\write_json(
				$run . '/cleanup.json',
				evidence_cleanup( $recipe ),
			);
			TsfBenchmark\write_json(
				$run . '/environment.json',
				[
					'blockers' => [ 'Synthetic transport-free test data; this is not a WordPress performance result.' ],
					'host'     => [
						'cli_php' => [
							'binary'  => 'C:\\Users\\PRIVATE_SENTINEL\\php.exe',
							'version' => PHP_VERSION,
						],
					],
				],
			);
			foreach ( [ 'proposal.json', 'approval.json', 'isolation.private.json', 'setup-error-p1-tsf.log', 'fixture-p1-tsf.json' ] as $private )
				file_put_contents(
					$run . '/' . $private,
					'PRIVATE_SENTINEL',
				);
			$rows                     = analysis_samples( $recipe );
			$rows[0]['authorization'] = 'PRIVATE_SENTINEL';
			file_put_contents(
				$run . '/samples.ndjson',
				implode(
					"\n",
					array_map(
						fn( array $row ): string => json_encode(
							$row,
							JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
						),
						$rows,
					),
				) . "\n",
			);
			$selection = [ '<title>Deterministic fixture</title>' ];
			TsfBenchmark\write_json(
				$run . '/output-p1-tsf-page.json',
				[
					'selection' => $selection,
					'sha256'    => hash(
						'sha256',
						implode( "\n", $selection ),
					),
					'password'  => 'PRIVATE_SENTINEL',
				],
			);
			$files = [
				'index.php'         => str_repeat( 'a', 64 ),
				'inc/tokenizer.php' => str_repeat( 'b', 64 ),
			];
			TsfBenchmark\write_json(
				$run . '/source-p1-tsf.json',
				[
					'files'        => $files,
					'sha256'       => TsfBenchmark\digest( $files ),
					'private_note' => 'PRIVATE_SENTINEL',
				],
			);
			TsfBenchmark\write_json(
				$run . '/setup-p1-tsf.json',
				[
					'features' => [ 'schema' => true ],
					'token'    => 'PRIVATE_SENTINEL',
				],
			);
			TsfBenchmark\write_json(
				$run . '/isolation-p1-tsf.json',
				[
					'confirmed' => true,
					'token'     => 'PRIVATE_SENTINEL',
					'host'      => 'C:/Users/PRIVATE_SENTINEL/site',
				],
			);
			file_put_contents(
				$run . '/telemetry-p1.ndjson',
				json_encode( [
					'timestamp' => '2026-01-01T00:00:00Z',
					'metrics'   => [
						'cpu_busy' => [
							'value'  => 4.0,
							'source' => 'Synthetic counter',
							'unit'   => 'percent',
						],
					],
					'password'  => 'PRIVATE_SENTINEL',
				] ) . "\n",
			);
			TsfBenchmark\report_run( $run );
			evidence_expect(
				TsfBenchmark\report_run( $run, true )['verified'],
				'Original report did not replay.',
			);
			$export = TsfBenchmark\export_evidence( $run, $destination );
			evidence_expect(
				$export['cleanup_verified'] && 'smoke_only' === $export['verdict'],
				'Export misclassified the test data.',
			);
			evidence_expect(
				TsfBenchmark\report_run( $destination, true )['verified'],
				'Exported report did not replay.',
			);
			evidence_expect(
				TsfBenchmark\verify_evidence_manifest( $destination )['verified'],
				'Export manifest did not verify.',
			);
			$public_source = TsfBenchmark\read_json(
				$destination . '/source-p1-tsf.json',
			);
			evidence_expect(
				$public_source['files'] === $files,
				'A source filename containing token was incorrectly treated as a secret field.',
			);
			TsfBenchmark\validate_public_receipt(
				'source-p1-tsf.json',
				$public_source,
			);
			foreach ( new DirectoryIterator( $destination ) as $entry ) {
				if ( ! $entry->isFile() ) continue;
				evidence_expect(
					! str_contains(
						file_get_contents( $entry->getPathname() ),
						'PRIVATE_SENTINEL',
					),
					'Private sentinel leaked to ' . $entry->getFilename(),
				);
			}
			evidence_expect(
				! is_file( $destination . '/plan.json' ) && ! is_file(
					$destination . '/approval.json',
				),
				'Private plan or approval was exported.',
			);
			evidence_throws(
				fn() => TsfBenchmark\export_evidence( $run, $destination ),
				'Existing export was overwritten.',
			);
			$changed                            = $recipe;
			$changed['comparison']['threshold'] = 99.0;
			TsfBenchmark\write_json(
				$run . '/recipe.json',
				$changed,
			);
			evidence_throws(
				fn() => TsfBenchmark\export_evidence(
					$run,
					$base . '/changed-recipe',
				),
				'A recipe that differs from the collection plan was exported.',
			);
			evidence_expect(
				! is_dir( $base . '/changed-recipe' ),
				'A rejected export left a visible destination.',
			);
			TsfBenchmark\write_json( $run . '/recipe.json', $recipe );
			$reexport = TsfBenchmark\export_evidence(
				$destination,
				$base . '/reexport',
			);
			evidence_expect(
				'smoke_only' === $reexport['verdict']
					&& TsfBenchmark\verify_evidence_manifest( $base . '/reexport' )['verified'],
				'An intact public export could not be re-exported.',
			);
			$cli = TsfBenchmark\execute(
				[
					PHP_BINARY,
					dirname( __DIR__ ) . '/benchmark.php',
					'report',
					'--run',
					$destination,
					'--out',
					$base . '/cli-reexport',
				],
			);
			evidence_expect(
				0 === $cli['exit'],
				'CLI public re-export failed: ' . $cli['stderr'],
			);
			evidence_expect(
				TsfBenchmark\verify_evidence_manifest( $destination )['verified']
					&& TsfBenchmark\verify_evidence_manifest( $base . '/cli-reexport' )['verified']
					&& TsfBenchmark\report_run( $base . '/cli-reexport', true )['verified'],
				'CLI re-export changed its public source or failed replay.',
			);
			$report = file_get_contents( $destination . '/report.html' );
			file_put_contents(
				$destination . '/report.html',
				'<p>Accidentally modified source artifact.</p>',
			);
			evidence_throws(
				fn() => TsfBenchmark\export_evidence(
					$destination,
					$base . '/damaged-source',
				),
				'Re-export silently replaced the damaged source artifact manifest.',
			);
			evidence_expect(
				! is_dir( $base . '/damaged-source' ),
				'A rejected source manifest left an export destination.',
			);
			file_put_contents(
				$destination . '/report.html',
				$report,
			);
			file_put_contents(
				$destination . '/runner.json',
				'{}',
			);
			evidence_throws(
				fn() => TsfBenchmark\verify_evidence_manifest( $destination ),
				'Changed artifact passed manifest verification.',
			);
		} finally {
			TsfBenchmark\remove_owned_tree( $base, $parent );
		}
	},
	'incomplete evidence can be exported but cannot become a qualified result' => function (): void {
		[ $parent, $base ] = evidence_test_directory();
		try {
			$run = $base . '/run';
			TsfBenchmark\make_directory( $run );
			TsfBenchmark\write_json(
				$run . '/recipe.json',
				analysis_recipe(),
			);
			TsfBenchmark\write_json(
				$run . '/environment.json',
				[ 'blockers' => [ 'Synthetic interrupted test.' ] ],
			);
			$export = TsfBenchmark\export_evidence( $run, $base . '/public' );
			evidence_expect(
				! $export['cleanup_verified'] && 'inconclusive' === $export['verdict'],
				'Incomplete evidence acquired a performance claim.',
			);
			evidence_expect(
				TsfBenchmark\verify_evidence_manifest( $base . '/public' )['verified'],
				'Diagnostic export integrity failed.',
			);
		} finally {
			TsfBenchmark\remove_owned_tree( $base, $parent );
		}
	},
	'unsafe source paths and altered selected output are rejected' => function (): void {
		foreach ( [ '', 'C:/Users/private/file.php', '../secret.php' ] as $path ) {
			$files = [ $path => str_repeat( 'a', 64 ) ];
			evidence_throws(
				fn() => TsfBenchmark\validate_public_receipt(
					'source-p1-tsf.json',
					[
						'files'  => $files,
						'sha256' => TsfBenchmark\digest( $files ),
					],
				),
				'An invalid relative source path was accepted.',
			);
		}
		evidence_throws(
			fn() => TsfBenchmark\validate_public_receipt(
				'output-p1-tsf-page.json',
				[
					'selection' => [ 'changed' ],
					'sha256'    => str_repeat( 'a', 64 ),
				],
			),
			'Changed selected output passed verification.',
		);
		$selection = [ 'private_metadata' => '<title>Valid title</title>' ];
		evidence_throws(
			fn() => TsfBenchmark\validate_public_receipt(
				'output-p1-tsf-page.json',
				[
					'selection' => $selection,
					'sha256'    => hash(
						'sha256',
						implode( "\n", $selection ),
					),
				],
			),
			'Associative output keys were accepted outside the output fingerprint.',
		);
	},
	'outer manifest hashes cannot validate an inconsistent source receipt' => function (): void {
		[ $parent, $base ] = evidence_test_directory();
		try {
			$path = $base . '/source-p1-tsf.json';
			TsfBenchmark\write_json(
				$path,
				[
					'files'  => [ 'index.php' => str_repeat( 'a', 64 ) ],
					'sha256' => str_repeat( 'b', 64 ),
				],
			);
			TsfBenchmark\write_json(
				$base . '/manifest.json',
				[
					'schema' => 1,
					'type'   => 'public-benchmark-evidence',
					'files'  => [
						'source-p1-tsf.json' => [
							'sha256' => hash_file( 'sha256', $path ),
							'bytes'  => filesize( $path ),
						],
					],
				],
			);
			evidence_throws(
				fn() => TsfBenchmark\verify_evidence_manifest( $base ),
				'The artifact hash hid a mismatched inner source digest.',
			);
		} finally {
			TsfBenchmark\remove_owned_tree( $base, $parent );
		}
	},
	'source receipts require a nonempty file map with the matching manifest digest' => function (): void {
		$files = [ 'index.php' => str_repeat( 'a', 64 ) ];
		foreach ( [ 'runner.json', 'source-p1-tsf.json' ] as $name ) {
			TsfBenchmark\validate_public_receipt(
				$name,
				[
					'files'  => $files,
					'sha256' => TsfBenchmark\digest( $files ),
				],
			);
			evidence_throws(
				fn() => TsfBenchmark\validate_public_receipt(
					$name,
					[
						'files'  => $files,
						'sha256' => str_repeat( 'b', 64 ),
					],
				),
				'An unrelated manifest digest qualified.',
			);
			evidence_throws(
				fn() => TsfBenchmark\validate_public_receipt(
					$name,
					[
						'files'  => [],
						'sha256' => TsfBenchmark\digest( [] ),
					],
				),
				'An empty source receipt qualified.',
			);
		}
	},
	'public recipes keep experiment inputs and reject credential-bearing package URLs' => function (): void {
		$recipe                = analysis_recipe();
		$recipe['description'] = 'A legitimate custom experiment.';
		$recipe['fixtures']    = [
			'content_html' => '<p>Specific custom content.</p>',
			'token'        => 'PRIVATE_SENTINEL',
		];
		$public                = TsfBenchmark\public_recipe( $recipe );
		evidence_expect(
			$public['description'] === $recipe['description'] && $public['fixtures']['content_html'] === $recipe['fixtures']['content_html'],
			'Public recipe changed experimental content.',
		);
		evidence_expect(
			! str_contains(
				json_encode( $public ),
				'PRIVATE_SENTINEL',
			),
			'Unknown fixture metadata leaked.',
		);
		TsfBenchmark\validate_public_package_url(
			'https://example.org/package.zip?release=5.2.1',
		);
		foreach ( [ '?token=secret', '?X-Amz-Signature=secret', '?api%5Fkey=secret', '?sv=2025-01-01&sig=secret', '?key=secret' ] as $query ) {
			$recipe['wordpress']['url'] = 'https://example.org/package.zip' . $query;
			evidence_throws(
				fn() => TsfBenchmark\public_recipe( $recipe ),
				'A credential-bearing URL was exported.',
			);
		}
	},
];
