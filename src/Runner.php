<?php
/**
 * Approved benchmark planning, collection, and replay orchestration.
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
 * Saves a reviewable run proposal without downloading packages or creating sites.
 *
 * A modified reference recipe becomes a custom experiment before validation.
 * The proposal includes its exact workload, target, packages, and runner identity.
 *
 * @param array  $recipe Requested experiment recipe.
 * @param array  $target Existing private native-stack configuration.
 * @param string $output New private directory outside the served document root.
 * @return array The concrete proposal that requires operator approval.
 * @throws RuntimeException When inputs or the output destination are invalid.
 */
function create_plan( array $recipe, array $target, string $output ): array {

	$reference = read_json(
		\dirname( __DIR__ ) . '/recipes/tsf-5.2.1.json',
	);

	if ( true === ( $recipe['reference'] ?? false ) && digest( $recipe ) !== digest( $reference ) ) {
		$recipe['reference']               = false;
		$recipe['reference_parent_sha256'] = digest( $reference );
		$recipe['description']             = ( $recipe['description'] ?? '' )
			. ' Modified recipe: reported as a custom experiment.';
	}

	validate_recipe( $recipe );
	$target = normalize_network_policy( normalize_worker_supervisor( $target ) );
	validate_target( $target );
	validate_fixture_recipe( $recipe['fixtures'] ?? [] );

	if ( file_exists( $output ) )
		throw new RuntimeException(
			'Choose a fresh run directory.',
		);

	$output = absolute_path( $output );
	$web    = normalized_path( realpath( $target['document_root'] ) );

	if ( str_starts_with(
		strtolower( $output ) . '/',
		strtolower( $web ) . '/',
	) ) {
		throw new RuntimeException(
			'Private run files must be outside the served document root.',
		);
	}

	make_directory( $output );
	$id                      = bin2hex( random_bytes( 6 ) );
	$target['document_root'] = $web;
	$target['php']           = normalized_path( realpath( $target['php'] ) );

	foreach ( [ 'php_ini', 'tls_ca', 'package_cache' ] as $path_key ) {
		if ( ! empty( $target[ $path_key ] ) )
			$target[ $path_key ] = absolute_path( $target[ $path_key ] );
	}

	$plan                        = [
		'schema'        => 1,
		'id'            => $id,
		'namespace'     => "tsfb$id",
		'recipe'        => $recipe,
		'target'        => $target,
		'runner'        => runner_manifest(),
		'consent_scope' => [
			'dedicated_fixture_directories_and_tables',
			'pinned_packages',
			'plugin_activation_and_setup',
			'host_telemetry',
			'owned_resource_cleanup',
		],
		'network'       => 'wordpress-http' === $target['isolation']['mode']
			? 'WordPress HTTP/mail calls are blocked and privately logged. Raw sockets/direct cURL remain uncontrolled; this differs from the historical externally isolated recipe.'
			: 'Operator-approved external isolation and WordPress HTTP/mail guards are required before activation.',
	];
	if ( isset( $target['worker_supervisor'] ) )
		$plan['consent_scope'][] = 'owned_native_supervisor_and_wordpress_preflight';

	write_json( "$output/plan.json", $plan );
	write_json( "$output/recipe.json", $recipe );
	write_json(
		"$output/runner.json",
		$plan['runner'],
	);
	write_json(
		"$output/state.json",
		[ 'phase' => 'planned' ],
	);

	$count   = \count( balanced_orders( array_column( $recipe['arms'], 'id' ) ) )
		* \count( $recipe['arms'] )
		* \count( $recipe['routes'] );
	$summary = [
		'run'               => $output,
		'plan_sha256'       => digest( $plan ),
		'provider'          => $target['provider'] ?? 'native',
		'url'               => $target['url'],
		'fixture_parent'    => "$web/{$plan['namespace']}",
		'packages'          => array_map(
			fn( $package ) => [
				'version' => $package['version'],
				'url'     => $package['url'],
				'sha256'  => $package['sha256'],
			],
			all_packages( $recipe ),
		),
		'downloads'         => $target['downloads'] ?? false,
		'isolation'         => $target['isolation'],
		'passes'            => $recipe['measurement']['passes'],
		'measured_requests' => $count * $recipe['measurement']['samples'] * $recipe['measurement']['passes'],
		'warmup_requests'   => $count * $recipe['measurement']['warmups'] * $recipe['measurement']['passes'],
		'approval_required' => true,
	];
	write_json(
		"$output/proposal.json",
		$summary,
	);

	return $summary;
}

/**
 * Records the named operator's prior explicit approval of the saved proposal.
 *
 * This receipt is not authentication and cannot substitute for actual consent.
 *
 * @param string $run      Private run directory.
 * @param string $operator Name supplied after the operator approves the proposal.
 * @return array Approval confirmation and approved plan digest.
 * @throws RuntimeException When the approval is missing, duplicated, or invalid.
 */
function approve_plan( string $run, string $operator ): array {

	if ( '' === trim( $operator ) )
		throw new RuntimeException(
			'Name the operator who explicitly approved this proposal.',
		);

	if ( is_file( "$run/approval.json" ) )
		throw new RuntimeException(
			'Approval already recorded. Create a new plan to change scope.',
		);

	$plan = read_json( "$run/plan.json" );
	validate_recipe( $plan['recipe'] );
	validate_target( $plan['target'] );
	$receipt = [
		'plan_sha256' => digest( $plan ),
		'operator'    => $operator,
		'recorded_at' => gmdate( 'c' ),
		'scope'       => $plan['consent_scope'],
	];
	write_json( "$run/approval.json", $receipt );

	return [
		'approved'    => true,
		'plan_sha256' => $receipt['plan_sha256'],
	];
}

/**
 * Obtains approved pinned archives without creating or activating WordPress sites.
 *
 * @param string $run Approved private run directory in the planned phase.
 * @return array Prepared state and the required isolation step.
 * @throws RuntimeException When preparation cannot complete as approved.
 */
function prepare_run( string $run ): array {

	$plan = approved_plan( $run );

	if ( 'planned' !== read_json( "$run/state.json" )['phase'] )
		throw new RuntimeException(
			'Preparation is not repeatable over an existing run. Cleanup and create a fresh plan.',
		);

	$cache    = $plan['target']['package_cache'] ?? "$run/packages";
	$packages = [];

	foreach ( all_packages( $plan['recipe'] ) as $package ) {
		$file       = fetch_archive(
			$package,
			$cache,
			$plan['target']['downloads'] ?? false,
		);
		$packages[] = [
			'url'     => $package['url'],
			'version' => $package['version'],
			'sha256'  => hash_file( 'sha256', $file ),
			'bytes'   => filesize( $file ),
		];
	}

	write_json(
		"$run/packages.json",
		$packages,
	);
	write_json(
		"$run/state.json",
		[
			'phase' => 'prepared',
			'next'  => 'wordpress-http requires no host network changes or separate isolation receipt. External modes require verification recorded with isolation --run PATH --operator NAME --evidence DESCRIPTION.',
		],
	);

	return read_json( "$run/state.json" );
}

/**
 * Records review of the exact approved external network policy.
 *
 * WordPress-only mode requires no separate operator receipt. External modes also
 * require verification and TCP canaries. The receipt changes no host settings.
 *
 * @param string $run      Approved and prepared private run directory.
 * @param string $operator Operator who verified the enforced restriction.
 * @param string $evidence Description of the restriction and its verification.
 * @return array Recorded-isolation confirmation.
 * @throws RuntimeException When verification is insufficient or out of sequence.
 */
function confirm_isolation( string $run, string $operator, string $evidence ): array {

	$plan = approved_plan( $run );

	// Require more than a short acknowledgement; the CLI stores an explanation.
	if ( '' === trim( $operator ) || 12 > \strlen( trim( $evidence ) ) )
		throw new RuntimeException(
			'Record the operator and concrete verification of the offline host or firewall policy.',
		);

	if ( 'prepared' !== read_json( "$run/state.json" )['phase'] )
		throw new RuntimeException(
			'Cache packages before confirming the network policy.',
		);

	$receipt = [
		'plan_sha256' => digest( $plan ),
		'operator'    => $operator,
		'recorded_at' => gmdate( 'c' ),
		'mode'        => $plan['target']['isolation']['mode'],
		'evidence'    => $evidence,
	];
	write_json(
		"$run/isolation.private.json",
		$receipt,
	);

	return [
		'isolation_recorded'                             => true,
		'mode'                                           => $receipt['mode'],
		'activation_still_requires_worker_guard_checks'  => true,
		'activation_still_requires_worker_canary_checks' => 'wordpress-http' !== $receipt['mode'],
	];
}

/**
 * Checks actual HTTP runtime identity and rejects conditions invalid for any run.
 *
 * Reference-only limitations are returned for the caller to enforce or disclose.
 * Blocking conditions apply equally to reference and custom experiments.
 *
 * @param array $runtime Runtime evidence returned by the HTTP worker.
 * @param array $site    Owned fixture with its declared active plugin set.
 * @param array $recipe  Approved experiment recipe.
 * @return array Runtime assessment and reference-readiness conditions.
 * @throws RuntimeException When runtime identity or a blocking condition fails.
 */
function assert_runtime( array $runtime, array $site, array $recipe ): array {

	if (
		   $runtime['wordpress'] !== $recipe['wordpress']['version']
		|| $runtime['theme']['slug'] !== $recipe['theme']['slug']
		|| $runtime['theme']['version'] !== $recipe['theme']['version']
	) {
		throw new RuntimeException(
			'HTTP core/theme does not match the recipe.',
		);
	}

	$active   = $runtime['active_plugins'];
	$expected = array_column(
		$site['arm']['packages'],
		'main',
	);
	sort( $active );
	sort( $expected );

	if ( $active !== $expected || $runtime['object_cache'] || $runtime['page_cache'] )
		throw new RuntimeException(
			'Unexpected plugins or cache drop-ins in fixture.',
		);

	$assessment = assess_runtime( $runtime );

	foreach ( $assessment['conditions'] as $condition ) {
		if ( 'blocking' === $condition['severity'] )
			throw new RuntimeException(
				'Invalid HTTP runtime: ' . $condition['message'],
			);
	}

	return $assessment;
}

/**
 * Validates the resolved query, fresh execution marker, and expected SEO output.
 *
 * Output is selected and hashed outside the timed request interval. Plugins need
 * not produce identical metadata, but declared active features cannot disappear.
 *
 * @param array $response HTTP response and collector execution nonce.
 * @param array $route    Declared route identity, status, and expected query.
 * @param array $site     Owned fixture and plugin setup identity.
 * @return array Validity, failure reasons, selected metadata, and its digest.
 */
function qualify_response( array $response, array $route, array $site ): array {

	$reasons = [];
	$query   = $route['query'] ?? $route['id'];

	if ( $response['status'] !== $route['status'] )
		$reasons[] = 'Unexpected HTTP status';

	if ( ( $response['headers']['x-tsf-route'] ?? null ) !== $query )
		$reasons[] = 'Wrong WordPress query';

	if ( ( $response['headers']['x-tsf-request'] ?? null ) !== $response['nonce'] )
		$reasons[] = 'Missing fresh PHP execution marker';

	if ( isset( $response['headers']['x-xdebug-profile-filename'] ) )
		$reasons[] = 'Xdebug profiling active';

	if ( ! str_contains(
		strtolower(
			$response['headers']['content-type'] ?? '',
		),
		'text/html',
	) ) {
		$reasons[] = 'Unexpected content type';
	}

	if ( preg_match(
		'/(?:<b>)?(?:Warning|Fatal error|Parse error|Notice):/i',
		$response['body'],
	) ) {
		$reasons[] = 'PHP diagnostic in response';
	}

	$output = selected_output(
		$response['body'],
		$site['url'],
	);
	$head   = implode( "\n", $output['selection'] );

	if ( ! preg_match( '/<title\b/i', $head ) )
		$reasons[] = 'Missing title';

	if ( ! str_starts_with( $site['arm']['id'], 'core-' ) ) {
		if (
			\in_array(
				$query,
				[ 'home-blog', 'home-static', 'post', 'page' ],
				true,
			)
			&& ! preg_match(
				'/rel=["\']canonical["\']/i',
				$head,
			)
		) {
			$reasons[] = 'Missing expected canonical';
		}

		if (
			\in_array(
				$site['arm']['setup'],
				[ 'tsf', 'yoast', 'rankmath', 'aioseo', 'slim', 'seopress' ],
				true,
			)
			&& \in_array(
				$query,
				[ 'post', 'page' ],
				true,
			)
			&& ! preg_match(
				'/(?:property|name)=["\']og:title["\']/i',
				$head,
			)
		) {
			$reasons[] = 'Missing expected Open Graph output';
		}

		if (
			   'yoast' === $site['arm']['setup']
			&& \in_array(
				$query,
				[ 'post', 'page' ],
				true,
			)
			&& ! str_contains( $head, 'application/ld+json' )
		) {
			$reasons[] = 'Missing default Yoast schema';
		}
	}

	return [
		'valid'         => ! $reasons,
		'reasons'       => $reasons,
		'output_sha256' => $output['sha256'],
		'selection'     => $output['selection'],
	];
}

/**
 * Identifies PHP diagnostics without confusing application error_log() messages.
 *
 * Preserve the complete private log and its digest. Plugin setup/account messages
 * remain visible limitations; native warnings, notices, deprecations and errors
 * still stop collection. No messages are deleted or logging settings changed.
 *
 * @param string $path Private fixture error log.
 * @return array Byte length, digest, and whether native PHP diagnostics matched.
 * @throws RuntimeException When an existing private log cannot be read.
 */
function inspect_fixture_diagnostics( string $path ): array {

	$bytes = is_file( $path ) ? file_get_contents( $path ) : '';
	if ( false === $bytes ) throw new RuntimeException( 'Cannot inspect the private PHP log.' );

	return [
		'bytes'           => \strlen( $bytes ),
		'sha256'          => hash( 'sha256', $bytes ),
		'php_diagnostics' => (bool) preg_match( '/^(?:\[[^\r\n]*\]\s*)?(?:PHP\s+)?(?:Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error|Strict Standards):/im', $bytes ),
	];
}

/**
 * Collects the complete approved workload without selective retries or trimming.
 *
 * Each pass uses fresh fixtures. Runtime, output, source, diagnostics, and host
 * conditions are checked around collection. Interrupted runs retain their samples
 * and failure evidence; cleanup remains a separate ownership-verified command.
 *
 * @param string $run Approved, prepared private run directory.
 * @return array Completed collection state.
 * @throws RuntimeException When setup, qualification, or collection fails.
 * @throws \Throwable Rethrows the original failure after preserving diagnostics and stopping telemetry.
 */
function collect_run( string $run ): array {

	$plan   = approved_plan( $run );
	$recipe = $plan['recipe'];
	$target = $plan['target'];
	if ( isset( $target['worker_supervisor'] ) ) {
		$preflight = read_json( "$run/preflight.json" );
		if ( empty( $preflight['passed'] ) || digest( $plan ) !== $preflight['plan_sha256'] )
			throw new RuntimeException( 'The exact supervised target must pass its WordPress preflight.' );
	}

	if ( 'prepared' !== read_json( "$run/state.json" )['phase'] )
		throw new RuntimeException(
			'Only a prepared, unused run can collect measurements.',
		);

	$isolation = run_network_policy( $plan, $run );

	$probe        = execute( php_command(
		$target,
		[
			\dirname( __DIR__ ) . '/scripts/php-capabilities.php',
		],
	) );
	$capabilities = json_decode( $probe['stdout'], true );

	if ( 0 !== $probe['exit'] || empty( $capabilities['mysqli'] ) )
		throw new RuntimeException(
			'Selected PHP lacks mysqli; request an approved runtime/configuration change.',
		);

	$stream = fopen( "$run/samples.ndjson", 'xb' );

	if ( false === $stream )
		throw new RuntimeException(
			'Cannot create sample evidence.',
		);

	$environment                     = [
		'host'       => discover_environment(),
		'conditions' => [],
		'blockers'   => [],
		'runtime'    => [],
		'telemetry'  => [],
		'isolation'  => $isolation,
	];
	if ( 'wordpress-http' === $isolation['mode'] )
		$environment['conditions'][] = [
			'scope'    => 'network',
			'severity' => 'limitation',
			'code'     => 'wordpress_http_scope',
			'message'  => 'WordPress HTTP/mail calls are blocked and privately logged; direct cURL/raw sockets remain uncontrolled. Any in-response waiting remains measured with no synthetic penalty. This network policy differs from the historical externally isolated recipe.',
		];
	$state                           = [
		'phase'      => 'running',
		'started_at' => gmdate( 'c' ),
	];
	write_json( "$run/state.json", $state );
	$orders    = balanced_orders( array_column( $recipe['arms'], 'id' ) );
	$handles   = [];
	$telemetry = null;
	$server    = null;

	try {
		$server                               = start_worker_supervisor( $target, $run );
		if ( null !== $server )
			$environment['worker_supervisor'] = [
				'mode'             => 'owned-nginx-and-native-cgi',
				'workers'          => $target['worker_supervisor']['workers'],
				'recycle_requests' => 500,
				'preflight'        => $preflight,
			];

		for ( $pass = 1; $pass <= $recipe['measurement']['passes']; ++$pass ) {
			$sites             = [];
			$sources           = [];
			$runtime_before    = [];
			$conditions_before = [];

			foreach ( $recipe['arms'] as $index => $arm ) {
				$site                  = provision_fixture(
					$plan,
					$run,
					$pass,
					$index,
					$arm,
				);
				$sources[ $arm['id'] ] = verify_source_tree( $site );
				write_json(
					"$run/source-{$site['id']}.json",
					$sources[ $arm['id'] ],
				);
				$site['fixture'] = run_fixture_command( $plan, $run, $site, 'install' );
				$network         = fixture_control( $site, $target, 'isolation' );
				write_json(
					"$run/isolation-{$site['id']}.json",
					$network,
				);

				assert_network_policy( $network, $target );

				run_fixture_command( $plan, $run, $site, 'activate' );
				$setup = run_fixture_command(
					$plan,
					$run,
					$site,
					'configure',
				);
				write_json(
					"$run/setup-{$site['id']}.json",
					$setup,
				);
				$guards = fixture_control( $site, $target, 'guards' );
				write_json( "$run/guards-{$site['id']}-after-setup.json", $guards );
				assert_network_policy( $guards, $target, false );

				if ( isset( $setup['account_connection'] ) ) {
					$environment['conditions'][] = [
						'scope'    => $site['id'],
						'severity' => 'limitation',
						'code'     => 'account_features',
						'message'  => $setup['account_connection'],
					];
				}

				if ( verify_source_tree( $site )['sha256'] !== $sources[ $arm['id'] ]['sha256'] )
					throw new RuntimeException(
						'Fixture source changed during setup.',
					);

				$runtime = fixture_control( $site, $target, 'runtime' );
				// Preserve a rejected worker's observed settings for the failure report.
				$environment['runtime'][ $site['id'] ] = $runtime;
				$assessment                            = assert_runtime( $runtime, $site, $recipe );
				$diagnostics                           = inspect_fixture_diagnostics( "$run/diagnostics-{$site['id']}.log" );
				write_json( "$run/diagnostics-before-{$site['id']}.json", $diagnostics );
				if ( $diagnostics['php_diagnostics'] )
					throw new RuntimeException( 'PHP diagnostics were recorded during setup; inspect the private log.' );

				if (
					   isset( $recipe['environment']['reference_php'] )
					&& $runtime['php_version'] !== $recipe['environment']['reference_php']
				) {
					$environment['conditions'][] = [
						'scope'    => $site['id'],
						'severity' => 'limitation',
						'code'     => 'reference_php_differs',
						'message'  => 'Reference workload on PHP ' . $runtime['php_version']
							. '; the recorded reference environment uses ' . $recipe['environment']['reference_php']
							. '. This is a different environment.',
					];
				}

				foreach ( $assessment['conditions'] as $condition )
					$environment['conditions'][] = [ 'scope' => $site['id'] ] + $condition;

				if ( $recipe['reference'] && ! $assessment['reference_ready'] )
					throw new RuntimeException(
						'HTTP runtime does not meet reference conditions. Inspect environment.json; do not silently change settings.',
					);

				$runtime_before[ $arm['id'] ]    = $runtime;
				$conditions_before[ $arm['id'] ] = array_column(
					$assessment['conditions'],
					'code',
				);
				$sites[ $arm['id'] ]             = $site;
				$handles[ $arm['id'] ]           = curl_init();
			}

			$telemetry = start_telemetry( "$run/telemetry-p$pass.ndjson" );
			// A fixed idle window lets telemetry distinguish background activity.
			echo "Pass $pass: collecting a 30-second idle baseline before warmup.\n";
			sleep( 30 );
			$measurement_start = gmdate( 'c' );

			foreach ( $recipe['routes'] as $route ) {
				foreach ( $sites as $site ) {
					fixture_control(
						$site,
						$target,
						'context',
						[ 'context' => $route['context'] ],
					);
				}

				foreach ( $orders as $block => $order ) {
					foreach ( [
						'warmup'  => $recipe['measurement']['warmups'],
						'measure' => $recipe['measurement']['samples'],
					] as $phase => $count ) {
						for ( $sequence = 1; $sequence <= $count; ++$sequence ) {
							foreach ( $order as $position => $id ) {
								$site         = $sites[ $id ];
								$path         = preg_replace_callback(
									'/\{([a-z_]+)\}/',
									/**
									 * Resolves only integer IDs from the owned fixture.
									 *
									 * @param array $match Full placeholder and captured fixture key.
									 * @return string The declared fixture ID.
									 * @throws RuntimeException When the placeholder lacks an integer fixture ID.
									 */
									function ( $match ) use ( $site ) {

										if (
											   ! isset( $site['fixture'][ $match[1] ] )
											|| ! \is_int( $site['fixture'][ $match[1] ] )
										) {
											throw new RuntimeException( 'Unknown route placeholder.' );
										}

										return (string) $site['fixture'][ $match[1] ];
									},
									$route['path'],
								);
								$response     = request(
									$site['url'] . $path,
									$site['token'],
									null,
									$target['tls_ca'] ?? null,
									$handles[ $id ],
								);
								$check        = qualify_response( $response, $route, $site );
								$row          = [
									'pass'          => $pass,
									'route'         => $route['id'],
									'block'         => $block + 1,
									'sequence'      => $sequence,
									'position'      => $position + 1,
									'arm'           => $id,
									'phase'         => $phase,
									'elapsed_ms'    => $response['elapsed_ms'],
									'valid'         => $check['valid'],
									'output_sha256' => $check['output_sha256'],
									'bytes'         => $response['bytes'],
									'status'        => $response['status'],
									'monotonic_ns'  => hrtime( true ),
									'reasons'       => $check['reasons'],
								];
								$sample_bytes = json_encode(
									$row,
									\JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
								) . "\n";

								if ( fwrite( $stream, $sample_bytes ) !== \strlen( $sample_bytes ) )
									throw new RuntimeException(
										'Cannot retain complete sample evidence.',
									);

								if ( 0 === $block && 1 === $sequence && 'measure' === $phase ) {
									write_json(
										"$run/output-{$site['id']}-{$route['id']}.json",
										[
											'selection' => $check['selection'],
											'sha256'    => $check['output_sha256'],
										],
									);
								}

								if ( ! $check['valid'] ) {
									write_json( "$run/failed-response.private.json",
										[
											'observation' => $row,
											'response'    => $response,
										] );
									throw new RuntimeException(
										"Output validation failed: $id/{$route['id']}: "
											. implode( ', ', $check['reasons'] ),
									);
								}
							}
						}
					}

					if ( ! fflush( $stream ) )
						throw new RuntimeException(
							'Cannot flush sample evidence.',
						);
				}

				foreach ( $sites as $id => $site ) {
					$after                   = fixture_control( $site, $target, 'runtime' );
					$after['network_policy'] = fixture_control( $site, $target, 'guards' );
					assert_network_policy( $after['network_policy'], $target, false );
					$after['opcache']['before'] = $runtime_before[ $id ]['opcache']['status'];
					// Save the actual changed state even when its validation fails.
					write_json(
						"$run/runtime-{$site['id']}-{$route['id']}.json",
						$after,
					);
					$assessment = assert_runtime( $after, $site, $recipe );

					foreach ( [ 'php_version', 'sapi', 'extensions', 'xdebug', 'wordpress_debug', 'php_diagnostics' ] as $field ) {
						if ( $after[ $field ] !== $runtime_before[ $id ][ $field ] )
							throw new RuntimeException(
								"Unplanned HTTP runtime change: $field",
							);
					}

					if (
						   $after['opcache']['ini'] !== $runtime_before[ $id ]['opcache']['ini']
						|| empty( $after['opcache']['status']['opcache_enabled'] )
							!== empty( $runtime_before[ $id ]['opcache']['status']['opcache_enabled'] )
					) {
						throw new RuntimeException(
							'OPcache configuration or shared-memory availability changed during collection.',
						);
					}

					foreach ( $assessment['conditions'] as $condition ) {
						$environment['conditions'][] = [
							'scope' => "{$site['id']}/{$route['id']}",
						] + $condition;

						if (
							\in_array(
								$condition['severity'],
								[ 'blocking', 'reference' ],
								true,
							)
							&& (
								   'opcache_continuity_changed' === $condition['code']
								|| ! \in_array(
									$condition['code'],
									$conditions_before[ $id ],
									true,
								)
							)
						) {
							$environment['blockers'][] = $condition['message'];
						}
					}

					if ( $recipe['reference'] && ! $assessment['reference_ready'] )
						throw new RuntimeException(
							'Runtime changed during measurements.',
						);
				}

				echo "Pass $pass: {$route['id']} complete.\n";
			}

			$environment['telemetry'][ "pass-$pass" ] = stop_telemetry(
				$telemetry,
				[
					'measurement_start' => $measurement_start,
					'measurement_end'   => gmdate( 'c' ),
				],
			);
			$telemetry                                = null;

			foreach ( $environment['telemetry'][ "pass-$pass" ]['conditions'] ?? [] as $condition ) {
				$environment['conditions'][] = [ 'scope' => "pass-$pass" ] + $condition;

				if ( \in_array(
					$condition['severity'] ?? '',
					[ 'blocking', 'reference' ],
					true,
				) ) {
					$environment['blockers'][] = $condition['message'];
				}
			}

			foreach ( $sites as $id => $site ) {
				if ( verify_source_tree( $site )['sha256'] !== $sources[ $id ]['sha256'] )
					throw new RuntimeException(
						'Fixture source changed during collection.',
					);

				$diagnostics = inspect_fixture_diagnostics( "$run/diagnostics-{$site['id']}.log" );
				write_json( "$run/diagnostics-after-{$site['id']}.json", $diagnostics );
				if ( $diagnostics['php_diagnostics'] )
					throw new RuntimeException( 'PHP diagnostics were recorded; inspect the private log.' );

				if ( $diagnostics['bytes'] ) {
					$environment['conditions'][] = [
						'scope'    => $site['id'],
						'severity' => 'limitation',
						'code'     => 'plugin_log_messages',
						'message'  => 'Plugin messages were retained in the private error log for review, including setup or account-dependent limitations; no native PHP diagnostic matched.',
					];
				}

				curl_close( $handles[ $id ] );
				unset( $handles[ $id ] );
			}

			// Each pass has fresh fixture namespaces; previous plugin data cannot leak in.
			write_json(
				"$run/environment.json",
				$environment,
			);
		}

		$state['phase']       = 'collected';
		$state['finished_at'] = gmdate( 'c' );
	} catch ( \Throwable $error ) {
		$environment['blockers'][] = $error->getMessage();
		$state['phase']            = 'interrupted';
		$state['reason']           = $error->getMessage();
		throw $error;
	} finally {
		if ( null !== $telemetry )
			$environment['telemetry']['interrupted'] = stop_telemetry( $telemetry );

		foreach ( $handles as $handle )
			curl_close( $handle );
		if ( null !== $server )
			$environment['worker_supervisor']['cleanup'] = stop_worker_supervisor( $server );

		fclose( $stream );
		write_json(
			"$run/environment.json",
			$environment,
		);
		write_json( "$run/state.json", $state );
	}

	return $state;
}

/**
 * Loads retained NDJSON observations without discarding malformed nonempty lines.
 *
 * @param string $path Saved sample file.
 * @return array Decoded observations in their original order.
 */
function read_samples( string $path ): array {

	$rows   = [];
	$stream = fopen( $path, 'rb' );

	try {
		for ( $line = fgets( $stream ); false !== $line; $line = fgets( $stream ) ) {
			if ( '' !== trim( $line ) )
				$rows[] = json_decode(
					$line,
					true,
					32,
					\JSON_THROW_ON_ERROR,
				);
		}
	} finally {
		fclose( $stream );
	}

	return $rows;
}

/**
 * Regenerates an evidence report, or verifies exact replay of its saved result.
 *
 * Cleanup absence remains a blocker. An existing different analysis is never
 * overwritten; alternate analysis uses a new public evidence directory.
 *
 * @param string $run    Private run or public evidence directory.
 * @param bool   $verify Whether to compare saved results without writing them.
 * @return array Report location and verdict, or successful verification evidence.
 * @throws RuntimeException When replay or the existing analysis differs.
 */
function report_run( string $run, bool $verify = false ): array {

	$existing     = is_file( "$run/summary.json" ) ? read_json( "$run/summary.json" ) : null;
	$version      = null === $existing ? '2.0.0' : ( $existing['analysis_version'] ?? null );
	$presentation = read_presentation( $run );
	$renderer     = $presentation['renderer'] ?? ( null === $existing ? 'compact-1' : 'expanded-1' );

	if ( true === ( $presentation['presentation_only'] ?? false ) )
		throw new RuntimeException(
			'A presentation-only view cannot be numerically verified or reanalyzed. Use the original public evidence export.',
		);

	if ( ! \in_array( $version, [ '1.0.0', '2.0.0' ], true ) )
		throw new RuntimeException( 'Unsupported saved analysis version.' );

	if ( ! $verify && null !== $existing && '2.0.0' !== $version )
		throw new RuntimeException(
			'Existing analysis differs. Preserve it; use report --run SOURCE --out NEW_DIRECTORY for another analysis.',
		);

	if ( $verify && null === $existing )
		throw new RuntimeException( 'A saved summary is required for verification.' );

	$recipe = read_json( "$run/recipe.json" );
	verify_private_recipe( $run, $recipe );
	$samples     = read_samples( "$run/samples.ndjson" );
	$environment = is_file( "$run/environment.json" )
		? read_json( "$run/environment.json" )
		: [ 'blockers' => [ 'Environment evidence missing.' ] ];
	$environment = public_environment( $environment );

	if (
		   ! is_file( "$run/cleanup.json" )
		|| ! cleanup_verified(
			$recipe,
			read_json( "$run/cleanup.json" ),
		)
	) {
		$environment['blockers'][] = 'Owned-resource cleanup has not been verified.';
	}

	$analysis = '1.0.0' === $version
		? analyze_legacy( $recipe, $samples, $environment )
		: analyze( $recipe, $samples, $environment );

	if ( '2.0.0' === $version ) {
		$context_path                 = evidence_input( $run, 'analysis-context.json' );
		$analysis['analysis_context'] = validate_analysis_context(
			null === $context_path ? [ 'retrospective' => false ] : read_json( $context_path ),
		);
	}
	$collection_path   = evidence_input( $run, 'runner.json' );
	$collection_runner = null;

	if ( null !== $collection_path ) {
		$collection = read_json( $collection_path );
		validate_public_receipt( 'runner.json', $collection );
		$collection_runner = digest( $collection['files'] );
	}
	$current_runner         = runner_manifest()['sha256'];
	$analysis['provenance'] = [
		'recipe_sha256'            => digest( $recipe ),
		'samples_sha256'           => hash_file(
			'sha256',
			"$run/samples.ndjson",
		),
		'collection_runner_sha256' => $collection_runner,
		'analysis_runner_sha256'   => $current_runner,
	];

	if ( $verify ) {
		$historical_runner = $existing['provenance']['analysis_runner_sha256'] ?? null;

		if ( ! \is_string( $historical_runner ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $historical_runner ) )
			throw new RuntimeException( 'Saved analysis runner provenance is invalid.' );

		// Replay can establish the saved numbers, but cannot recreate the old source tree.
		$analysis['provenance']['analysis_runner_sha256'] = $historical_runner;

		if ( digest( $existing ) !== digest( $analysis ) )
			throw new RuntimeException(
				'Numerical replay or provenance differs from saved report.',
			);

		if ( null === $presentation && file_get_contents( "$run/report.html" ) !== render_report( $analysis, $renderer ) )
			throw new RuntimeException( 'Rendered report differs.' );

		$result                     = [
			'verified'                          => true,
			'summary_sha256'                    => digest( $analysis ),
			'historical_analysis_runner_sha256' => $historical_runner,
			'replay_runner_sha256'              => $current_runner,
			'analysis_runner_matches'           => hash_equals( $historical_runner, $current_runner ),
		];
		if ( null !== $presentation )
			$result['presentation'] = verify_presentation( $run, $analysis );

		return $result;
	}

	if (
		   null !== $existing
		&& digest( $existing ) !== digest( $analysis )
	) {
		throw new RuntimeException(
			'Existing analysis differs. Preserve it; use report --run SOURCE --out NEW_DIRECTORY for another analysis.',
		);
	}

	write_json( "$run/summary.json", $analysis );
	if ( 'compact-1' === $renderer ) {
		write_presentation( $run, $analysis );
	} else {
		write_bytes(
			"$run/report.html",
			render_report( $analysis, $renderer ),
		);
	}
	$csv     = fopen( 'php://temp', 'w+' );
	$columns = [
		'pass',
		'route',
		'block',
		'sequence',
		'position',
		'arm',
		'phase',
		'elapsed_ms',
		'valid',
		'output_sha256',
		'bytes',
		'status',
		'monotonic_ns',
	];
	if ( false === $csv )
		throw new RuntimeException( 'Cannot create request CSV.' );

	try {
		if ( false === fputcsv( $csv, $columns, ',', '"', '' ) )
			throw new RuntimeException(
				'Cannot write request CSV header.',
			);

		foreach ( $samples as $row ) {
			$written = fputcsv(
				$csv,
				array_map(
					fn( $key ) => $row[ $key ] ?? '',
					$columns,
				),
				',',
				'"',
				'',
			);

			if ( false === $written )
				throw new RuntimeException(
					'Cannot write request CSV row.',
				);
		}

		rewind( $csv );
		$bytes = stream_get_contents( $csv );

		if ( false === $bytes )
			throw new RuntimeException(
				'Cannot read completed request CSV.',
			);

		write_bytes( "$run/requests.csv", $bytes );
	} finally {
		fclose( $csv );
	}

	return [
		'report'  => "$run/report.html",
		'verdict' => $analysis['verdict'],
	];
}
