<?php
/**
 * Versioned report presentation without repeating statistical analysis.
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
 * Fingerprints the compact renderer and its embedded asset sources.
 *
 * @return string Combined digest of the fixed renderer source allowlist.
 * @throws RuntimeException When a renderer source is unavailable.
 */
function presentation_renderer_sha256(): string {

	$hashes = [];
	foreach ( [ 'Report.php', 'PairedReport.php', 'CompactReport.php', 'compact-report.css', 'compact-report.js' ] as $name ) {
		$path = __DIR__ . '/' . $name;
		if ( ! is_file( $path ) || is_link( $path ) )
			throw new RuntimeException( 'A compact renderer source is unavailable.' );

		$hashes[ $name ] = hash_file( 'sha256', $path );
	}

	return digest( $hashes );
}

/**
 * Reads an optional presentation receipt bound to the exact summary bytes.
 *
 * The receipt contains only versions, flags, hashes, sizes, and fixed filenames.
 * Historical reports without a receipt retain their expanded renderer.
 *
 * @param string $run Existing report directory.
 * @return ?array Validated receipt, or null for a historical report.
 * @throws RuntimeException When the receipt is unsupported or its summary changed.
 */
function read_presentation( string $run ): ?array {

	$path = evidence_input( $run, 'presentation.json' );
	if ( null === $path ) return null;

	$receipt      = read_json( $path );
	$fields       = [ 'schema', 'renderer', 'summary_sha256', 'renderer_source_sha256' ];
	$view         = true === ( $receipt['presentation_only'] ?? false );
	$hashes       = [ 'summary_sha256', 'renderer_source_sha256' ];
	if ( $view )
		$fields   = array_merge( $fields, [ 'presentation_only', 'source_manifest_sha256', 'files' ] );
	if ( $view )
		$hashes[] = 'source_manifest_sha256';

	if (
		   1 !== ( $receipt['schema'] ?? null )
		|| ! \in_array( $receipt['renderer'] ?? null, [ 'expanded-1', 'compact-1' ], true )
		|| array_diff( array_keys( $receipt ), $fields )
		|| array_diff( $fields, array_keys( $receipt ) )
	) {
		throw new RuntimeException( 'Unsupported report presentation receipt.' );
	}
	foreach ( $hashes as $field )
		if ( ! \is_string( $receipt[ $field ] ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $receipt[ $field ] ) )
			throw new RuntimeException( 'Invalid report presentation fingerprint.' );

	$summary = evidence_input( $run, 'summary.json' );
	if ( null === $summary || ! hash_equals( $receipt['summary_sha256'], hash_file( 'sha256', $summary ) ) )
		throw new RuntimeException( 'Report presentation summary differs from its receipt.' );

	if ( $view ) {
		if (
			   'compact-1' !== $receipt['renderer']
			|| ! \is_array( $receipt['files'] )
			|| array_diff( array_keys( $receipt['files'] ), [ 'summary.json', 'report.html', 'details.html' ] )
			|| 3 !== \count( $receipt['files'] )
		) {
			throw new RuntimeException( 'Unsupported presentation-only artifacts.' );
		}
		foreach ( $receipt['files'] as $file )
		if (
				   ! \is_array( $file )
				|| array_diff( array_keys( $file ), [ 'sha256', 'bytes' ] )
				|| ! \is_string( $file['sha256'] ?? null )
				|| ! preg_match( '/\A[a-f0-9]{64}\z/D', $file['sha256'] )
				|| ! \is_int( $file['bytes'] ?? null )
				|| 0 > $file['bytes']
			) {
			throw new RuntimeException( 'Invalid presentation-only artifact receipt.' );
		}
	}

	return $receipt;
}

/**
 * Writes a compact report and its expanded fallback from an existing summary.
 *
 * @param string  $run             Owned destination with summary.json already saved.
 * @param array   $analysis        Recorded analysis; no estimates are recalculated.
 * @param ?string $source_manifest Source public-manifest digest for a separate view.
 * @return array Presentation receipt saved alongside the two HTML views.
 * @throws RuntimeException When a source fingerprint or summary is unavailable.
 */
function write_presentation( string $run, array $analysis, ?string $source_manifest = null ): array {

	if ( null !== $source_manifest && ! preg_match( '/\A[a-f0-9]{64}\z/D', $source_manifest ) )
		throw new RuntimeException( 'Invalid source public-manifest fingerprint.' );

	$summary = evidence_input( $run, 'summary.json' );
	if ( null === $summary )
		throw new RuntimeException( 'A saved summary is required for report presentation.' );

	write_bytes( $run . '/report.html', render_report( $analysis, 'compact-1' ) );
	write_bytes( $run . '/details.html', render_report( $analysis, 'expanded-1' ) );
	$receipt = [
		'schema'                 => 1,
		'renderer'               => 'compact-1',
		'summary_sha256'         => hash_file( 'sha256', $summary ),
		'renderer_source_sha256' => presentation_renderer_sha256(),
	];
	if ( null !== $source_manifest ) {
		$receipt['presentation_only']      = true;
		$receipt['source_manifest_sha256'] = $source_manifest;
		$receipt['files']                  = [];
		foreach ( [ 'summary.json', 'report.html', 'details.html' ] as $name )
			$receipt['files'][ $name ]     = [
				'sha256' => hash_file( 'sha256', $run . '/' . $name ),
				'bytes'  => filesize( $run . '/' . $name ),
			];
	}
	write_json( $run . '/presentation.json', $receipt );

	return $receipt;
}

/**
 * Checks presentation bytes and receipts without replaying measurement analysis.
 *
 * A matching render establishes presentation consistency, not numerical replay.
 * Source changes are reported separately; historical fingerprints are preserved.
 *
 * @param string $run      Existing report directory.
 * @param ?array $analysis Already replayed analysis, or null to read the saved summary.
 * @return array Presentation consistency, source fingerprints, and replay limitation.
 * @throws RuntimeException When any saved presentation artifact differs.
 */
function verify_presentation( string $run, ?array $analysis = null ): array {

	$receipt = read_presentation( $run );
	if ( null === $receipt )
		throw new RuntimeException( 'A report presentation receipt is required.' );

	$saved = read_json( $run . '/summary.json' );
	if ( null !== $analysis && digest( $saved ) !== digest( $analysis ) )
		throw new RuntimeException( 'Presentation analysis differs from its saved summary.' );
	$analysis ??= $saved;
	$report     = evidence_input( $run, 'report.html' );
	if ( null === $report || file_get_contents( $report ) !== render_report( $analysis, $receipt['renderer'] ) )
		throw new RuntimeException( 'Rendered report differs.' );

	if ( 'compact-1' === $receipt['renderer'] ) {
		$details = evidence_input( $run, 'details.html' );
		if ( null === $details || file_get_contents( $details ) !== render_report( $analysis, 'expanded-1' ) )
			throw new RuntimeException( 'Expanded report fallback differs.' );
	}
	foreach ( $receipt['files'] ?? [] as $name => $file ) {
		$path = evidence_input( $run, $name );
		if (
			   null === $path
			|| filesize( $path ) !== $file['bytes']
			|| ! hash_equals( $file['sha256'], hash_file( 'sha256', $path ) )
		) {
			throw new RuntimeException( 'Presentation-only artifact differs: ' . $name );
		}
	}
	$current = presentation_renderer_sha256();

	return [
		'verified'                          => true,
		'renderer'                          => $receipt['renderer'],
		'presentation_only'                 => $receipt['presentation_only'] ?? false,
		'statistics_recomputed'             => false,
		'summary_sha256'                    => $receipt['summary_sha256'],
		'historical_renderer_source_sha256' => $receipt['renderer_source_sha256'],
		'current_renderer_source_sha256'    => $current,
		'renderer_source_matches'           => hash_equals( $receipt['renderer_source_sha256'], $current ),
	];
}

/**
 * Creates a separate HTML view of a verified public version 2 evidence bundle.
 *
 * Summary bytes and their historical provenance remain unchanged. The view is
 * not a new evidence export and performs no numerical replay or bootstrapping.
 *
 * @param string $run         Original public evidence directory.
 * @param string $destination New view directory outside the original evidence.
 * @return array View location, source fingerprints, and explicit analysis limitation.
 * @throws RuntimeException When the source, destination, or saved version is invalid.
 * @throws \Throwable Rethrows failures after removing the incomplete owned view.
 */
function render_saved_report( string $run, string $destination ): array {

	$resolved = realpath( $run );
	if ( false === $resolved || ! is_dir( $resolved ) )
		throw new RuntimeException( 'Public evidence directory does not exist.' );
	$run      = normalized_path( $resolved );
	$manifest = verify_evidence_manifest( $run );
	foreach ( [ 'recipe.json', 'samples.ndjson', 'report.html', 'summary.json' ] as $name )
		if ( null === evidence_input( $run, $name ) )
			throw new RuntimeException( 'Rendering requires an original public evidence export.' );

	$summary = file_get_contents( $run . '/summary.json' );
	if ( false === $summary )
		throw new RuntimeException( 'Cannot read the public summary.' );
	$analysis = json_decode( $summary, true, 512, \JSON_THROW_ON_ERROR );
	if ( 2 !== ( $analysis['schema'] ?? null ) || '2.0.0' !== ( $analysis['analysis_version'] ?? null ) )
		throw new RuntimeException( 'Saved-summary rendering requires analysis version 2.0.0.' );

	$receipt = read_presentation( $run );
	if ( true === ( $receipt['presentation_only'] ?? false ) )
		throw new RuntimeException( 'Render from the original public evidence, not a presentation-only view.' );

	if ( file_exists( $destination ) || is_link( $destination ) )
		throw new RuntimeException( 'Choose a fresh report view directory.' );
	$destination = absolute_path( $destination );
	if ( str_starts_with( strtolower( $destination . '/' ), strtolower( $run . '/' ) ) )
		throw new RuntimeException( 'Output must be outside the original evidence directory.' );
	$parent = normalized_path( \dirname( $destination ) );
	require_unlinked_path( $parent );
	if ( ! mkdir( $destination, 0700 ) )
		throw new RuntimeException( 'Cannot exclusively create the report view directory.' );

	try {
		write_bytes( $destination . '/summary.json', $summary );
		$presentation = write_presentation( $destination, $analysis, $manifest['manifest_sha256'] );
		if ( verify_evidence_manifest( $run ) !== $manifest )
			throw new RuntimeException( 'Original public evidence changed during rendering.' );

		return [
			'directory'              => $destination,
			'report'                 => $destination . '/report.html',
			'presentation_only'      => true,
			'statistics_recomputed'  => false,
			'verdict'                => $analysis['verdict'],
			'summary_sha256'         => $presentation['summary_sha256'],
			'source_manifest_sha256' => $manifest['manifest_sha256'],
		];
	} catch ( \Throwable $error ) {
		remove_owned_tree( $destination, $parent );
		throw $error;
	}
}
