#!/usr/bin/env php
<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * Reanalyze a public evidence bundle offline; never starts WordPress.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

\define( 'TSFB_TESTING', true );
require \dirname( __DIR__ ) . '/benchmark.php';
require __DIR__ . '/statistical-review.php';
require __DIR__ . '/statistical-diagnostics.php';

try {
	if ( 5 !== \count( $argv ) || '--run' !== $argv[1] || '--out' !== $argv[3] )
		throw new \RuntimeException( 'Usage: php scripts/review-statistics.php --run PUBLIC_EVIDENCE --out NEW_DIRECTORY' );
	$source = normalized_path( realpath( $argv[2] ) ?: '' );
	require_unlinked_path( $argv[4] );
	$out = absolute_path( $argv[4] );
	if ( file_exists( $out ) || is_link( $out ) )
		throw new \RuntimeException( 'Output directory must be new; existing evidence is never overwritten.' );
	if ( str_starts_with( strtolower( $out ), strtolower( $source . '/' ) ) )
		throw new \RuntimeException( 'Output must be outside the original evidence bundle.' );
	$proof       = verify_evidence_manifest( $source );
	$recipe      = read_json( "$source/recipe.json" );
	$samples     = read_samples( "$source/samples.ndjson" );
	$environment = public_environment( read_json( "$source/environment.json" ) );
	if ( ! cleanup_verified( $recipe, read_json( "$source/cleanup.json" ) ) )
		throw new \RuntimeException( 'Original cleanup evidence is unqualified.' );
	$saved    = read_json( "$source/summary.json" );
	$replayed = '1.0.0' === $saved['analysis_version']
		? analyze_legacy( $recipe, $samples, $environment )
		: analyze( $recipe, $samples, $environment );
	if ( '2.0.0' === $saved['analysis_version'] ) {
		$context_path                 = evidence_input( $source, 'analysis-context.json' );
		$replayed['analysis_context'] = validate_analysis_context( null === $context_path ? [ 'retrospective' => false ] : read_json( $context_path ) );
	}
	$numeric = $saved;
	// Preserve historical source identity while checking numerical replay separately.
	unset( $numeric['provenance'] );
	if ( digest( $numeric ) !== digest( $replayed ) )
		throw new \RuntimeException( 'Original numerical analysis does not replay.' );
	if ( file_get_contents( "$source/report.html" ) !== render_report( $saved ) )
		throw new \RuntimeException( 'Original report does not replay with its historical provenance.' );
	$input                                = review_cells( $recipe, $samples, $environment );
	$anchor                               = $recipe['comparison']['anchor'];
	$limit                                = (float) $recipe['comparison']['threshold'];
	$points                               = review_points( $input['cells'] );
	$cases                                = [
		'primary-l3'    => [ 3, false, false ],
		'independent'   => [ 1, false, false ],
		'longer-l6'     => [ 6, false, false ],
		'shared-l3'     => [ 3, true, false ],
		'pass-level-l3' => [ 3, false, true ],
	];
	$result                               = [
		'method'                         => 'paired-block-review-1',
		'status'                         => 'exploratory_post_collection',
		'original_verdict'               => $input['original_verdict'],
		'original_scope'                 => $input['original_scope'],
		'input_manifest'                 => $proof,
		'original_numerical_replay'      => true,
		'original_provenance'            => $saved['provenance'],
		'current_analysis_runner_sha256' => runner_manifest()['sha256'],
		'source_sha256'                  => [],
		'estimand'                       => 'Equal-weight mean of paired six-request cell-median differences over all blocks, fixed routes, and passes. Not the original mean of route ratios; not CPU self-time.',
		'points_added_ms'                => $points,
		'bootstrap'                      => [],
		'per_pass_diagnostic_intervals'  => [],
		'influence'                      => review_influence( $input['cells'], $anchor, $limit ),
		'diagnostics'                    => review_diagnostics( $recipe, $samples ),
		'limitations'                    => [
			'Chosen after inspecting the original result; not independent confirmation.',
			'Nominal Bonferroni bootstrap intervals are approximate and conditional on this session; serial stationarity and block length remain assumptions.',
			'Routes ran sequentially. Common-index resampling is schedule dependence sensitivity, not simultaneous temporal clustering.',
			'Only three passes. Pass resampling is sensitivity, not calibrated future-session or other-host coverage.',
			'No clipping, negative-cell deletion, invalid-draw deletion, selective routes, or rerun.',
			'A positive threshold contrast addresses a ratio of average additions only jointly with a positive anchor addition.',
			'Original analysis and its verdict remain unchanged. No automatic publication qualification is assigned.',
		],
	];
	foreach ( [ 'review-statistics.php', 'statistical-review.php', 'statistical-diagnostics.php' ] as $name )
		$result['source_sha256'][ $name ] = hash_file( 'sha256', __DIR__ . '/' . $name );
	foreach ( $cases as $name => [ $length, $shared, $hierarchical ] )
		$result['bootstrap'][ $name ]     = review_bootstrap( $input['cells'], $anchor, $limit, 50000, $length, $shared, $hierarchical, 521 );
	foreach ( $input['cells'] as $pass => $routes )
		$result['per_pass_diagnostic_intervals'][ $pass ] = review_bootstrap( [ $routes ], $anchor, $limit, 50000, 3, false, false, 521 + $pass, false );
	// Verify every original artifact again after calculation, before writing elsewhere.
	$after = verify_evidence_manifest( $source );
	if ( $after !== $proof )
		throw new \RuntimeException( 'Source evidence changed during review.' );
	if ( file_exists( $out ) || is_link( $out ) )
		throw new \RuntimeException( 'Output directory must be new; existing evidence is never overwritten.' );
	// Exclusive creation prevents concurrent reviews from sharing an output directory.
	if ( ! mkdir( $out, 0700 ) )
		throw new \RuntimeException( 'Cannot exclusively create the review output directory.' );
	write_json( "$out/summary.json", $result );
	write_bytes( "$out/report.html", '<!doctype html><html lang="en"><meta charset="utf-8"><title>Exploratory statistical review</title><style>body{font:16px system-ui;max-width:1000px;margin:3em auto;padding:1em}pre{white-space:pre-wrap;overflow-wrap:anywhere}</style><h1>Exploratory statistical review</h1><p>Post-collection evaluation; original verdict unchanged. Read the methodological review before interpreting intervals.</p><pre>' . report_escape( json_encode( $result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR ) ) . '</pre></html>' );
	// CLI output contains only the result destination, never private target settings.
	fwrite( \STDOUT, "Offline review saved: $out\n" );
} catch ( \Throwable $error ) {
	fwrite( \STDERR, 'Error: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
