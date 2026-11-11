<?php
/**
 * Loopback requests, pinned archives, and selected output verification.
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
 * Validates a target origin without accepting embedded authentication.
 *
 * @param string $url Target URL, optionally including a base path.
 * @return array Parsed URL components.
 * @throws RuntimeException When the URL is malformed or contains unsupported components.
 */
function validate_origin( string $url ): array {

	$p = parse_url( $url );

	if (
		   ! $p
		|| ! \in_array(
			$p['scheme'] ?? '',
			[ 'http', 'https' ],
			true,
		)
		|| empty( $p['host'] )
		|| isset( $p['user'] )
		|| isset( $p['pass'] )
		|| isset( $p['query'] )
		|| isset( $p['fragment'] )
	) {
		throw new RuntimeException(
			'Use an HTTP(S) target URL without credentials, query or fragment.',
		);
	}

	if ( preg_match( '/[\x00-\x1f\x7f]/', $url ) )
		throw new RuntimeException( 'Invalid URL.' );

	return $p;
}

/**
 * Measures one HTTP request against loopback, preserving the virtual hostname.
 *
 * Only curl_exec() is timed. Parsing and output validation happen afterward.
 *
 * @param string           $url    Full fixture URL.
 * @param string           $token  Private fixture token.
 * @param array|null       $body   Optional JSON control request.
 * @param string|null      $ca     Optional trusted CA certificate file.
 * @param \CurlHandle|null $handle Existing per-arm connection handle.
 * @return array Timing, response bytes, headers, status, and fresh request nonce.
 * @throws RuntimeException When the request is unsafe, fails, or does not use loopback.
 */
function request( string $url, string $token = '', ?array $body = null, ?string $ca = null, mixed $handle = null ): array {

	if (
		   preg_match( '/[\x00-\x1f\x7f]/', $url )
		|| preg_match( '/[\x00-\x1f\x7f]/', $token )
	) {
		throw new RuntimeException(
			'HTTP URLs and fixture tokens must not contain control characters.',
		);
	}

	$p       = validate_origin( preg_replace(
		'/\?.*$/',
		'',
		$url,
	) );
	$port    = $p['port'] ?? ( 'https' === $p['scheme'] ? 443 : 80 );
	$owned   = null === $handle;
	$curl    = $handle ?? curl_init();
	$headers = [];
	$nonce   = bin2hex( random_bytes( 12 ) );
	$sent    = [
		'Accept: text/html,application/json',
		'Cache-Control: no-cache',
		'X-TSF-Benchmark: ' . $token,
		'X-TSF-Request: ' . $nonce,
	];

	if ( null !== $body ) $sent[] = 'Content-Type: application/json';

	try {
		curl_setopt_array(
			$curl,
			[
				\CURLOPT_URL            => $url,
				\CURLOPT_RETURNTRANSFER => true,
				\CURLOPT_FOLLOWLOCATION => false,
				\CURLOPT_CONNECTTIMEOUT => 5,
				\CURLOPT_TIMEOUT        => 60,
				\CURLOPT_PROXY          => '',
				\CURLOPT_CONNECT_TO     => [ ':' . $port . ':127.0.0.1:' . $port ],
				\CURLOPT_SSL_VERIFYPEER => true,
				\CURLOPT_SSL_VERIFYHOST => 2,
				\CURLOPT_HTTP_VERSION   => \CURL_HTTP_VERSION_1_1,
				\CURLOPT_HTTPHEADER     => $sent,
				\CURLOPT_POST           => null !== $body,
				\CURLOPT_HEADERFUNCTION => function ( $c, string $line ) use ( &$headers ): int {

					if ( str_contains( $line, ':' ) ) {
						[ $key, $value ]                       = explode( ':', $line, 2 );
						$headers[ strtolower( trim( $key ) ) ] = trim( $value );
					}

					return \strlen( $line );
				},
			],
		);

		if ( null !== $ca ) curl_setopt( $curl, \CURLOPT_CAINFO, $ca );

		if ( null !== $body ) {
			curl_setopt(
				$curl,
				\CURLOPT_POSTFIELDS,
				json_encode( $body, \JSON_THROW_ON_ERROR ),
			);
		} else {
			curl_setopt( $curl, \CURLOPT_HTTPGET, true );
		}

		$start   = hrtime( true );
		$data    = curl_exec( $curl );
		$elapsed = ( hrtime( true ) - $start ) / 1e6; // Nanoseconds to milliseconds.
		$info    = curl_getinfo( $curl );
		$error   = curl_error( $curl );

		if ( false === $data )
			throw new RuntimeException( "Loopback HTTP failed: $error" );

		if ( ! \in_array(
			$info['primary_ip'],
			[ '127.0.0.1', '::1' ],
			true,
		) ) {
			throw new RuntimeException(
				'Measurement did not use loopback.',
			);
		}

		return [
			'elapsed_ms' => $elapsed,
			'status'     => $info['http_code'],
			'headers'    => $headers,
			'body'       => $data,
			'bytes'      => \strlen( $data ),
			'nonce'      => $nonce,
		];
	} finally {
		if ( $owned ) curl_close( $curl );
	}
}

/**
 * Reuses a checksum-verified archive or downloads an explicitly approved one.
 *
 * @param array  $package Pinned URL and SHA-256 identity.
 * @param string $cache Directory for archives named by their SHA-256 digest.
 * @param bool   $allow_download Whether the approved plan permits downloads.
 * @return string Path to the verified local ZIP file.
 * @throws RuntimeException When consent, source identity, download, or archive storage fails.
 */
function fetch_archive( array $package, string $cache, bool $allow_download ): string {

	$hash = $package['sha256'];

	if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) )
		throw new RuntimeException(
			'A pinned SHA-256 is required.',
		);

	$file = $cache . '/' . $hash . '.zip';

	if ( is_file( $file ) ) {
		if ( ! hash_equals(
			$hash,
			hash_file( 'sha256', $file ),
		) ) {
			throw new RuntimeException(
				'Cached archive checksum mismatch.',
			);
		}

		return $file;
	}

	if ( ! $allow_download )
		throw new RuntimeException(
			"Missing cached archive: $hash.zip. Download approval is required.",
		);

	$url = parse_url( $package['url'] );

	if (
		   'https' !== ( $url['scheme'] ?? '' )
		|| ! \in_array(
			$url['host'] ?? '',
			[ 'downloads.wordpress.org', 'wordpress.org' ],
			true,
		)
		|| isset( $url['user'] )
		|| isset( $url['pass'] )
	) {
		throw new RuntimeException(
			'Only HTTPS official WordPress archives can be fetched automatically.',
		);
	}

	make_directory( $cache );
	$temp   = $file . '.part-' . bin2hex( random_bytes( 4 ) );
	$stream = fopen( $temp, 'xb' );
	$curl   = null;

	try {
		if ( false === $stream )
			throw new RuntimeException(
				'Cannot create the archive download.',
			);

		$curl = curl_init( $package['url'] );
		curl_setopt_array(
			$curl,
			[
				\CURLOPT_FILE           => $stream,
				\CURLOPT_FOLLOWLOCATION => false,
				\CURLOPT_TIMEOUT        => 180,
				\CURLOPT_CONNECTTIMEOUT => 15,
				\CURLOPT_PROTOCOLS      => \CURLPROTO_HTTPS,
				\CURLOPT_SSL_VERIFYPEER => true,
				\CURLOPT_PROXY          => '',
			],
		);
		$ok     = curl_exec( $curl );
		$status = curl_getinfo( $curl, \CURLINFO_RESPONSE_CODE );
		fclose( $stream );
		$stream = null;

		if (
			   ! $ok
			|| 200 !== $status
			|| ! hash_equals(
				$hash,
				hash_file( 'sha256', $temp ),
			)
		) {
			throw new RuntimeException(
				'Archive fetch or checksum verification failed.',
			);
		}

		if ( ! rename( $temp, $file ) )
			throw new RuntimeException(
				'Cannot retain verified archive.',
			);
	} finally {
		if ( $curl instanceof \CurlHandle ) curl_close( $curl );
		if ( \is_resource( $stream ) ) fclose( $stream );
		if ( is_file( $temp ) ) unlink( $temp );
	}

	return $file;
}

/**
 * Extracts a pinned distribution after validating every archive destination.
 *
 * Paths must be portable, unique, unlinked, and contained in the owned tree.
 * Existing destinations are checked before writing, and a failed entry copy
 * removes only the incomplete file created by this invocation.
 *
 * @param string $archive Verified archive file.
 * @param string $destination Run-owned extraction directory.
 * @param string $prefix Expected archive root directory.
 * @return array Entry paths, uncompressed sizes, and CRC values.
 * @throws RuntimeException When an archive path is unsafe or extraction cannot finish intact.
 */
function extract_archive( string $archive, string $destination, string $prefix ): array {

	require_unlinked_path( $destination );

	if ( file_exists( $destination ) && ! is_dir( $destination ) )
		throw new RuntimeException(
			'Archive destination must be a directory.',
		);

	$zip = new \ZipArchive();

	if ( true !== $zip->open( $archive ) )
		throw new RuntimeException( 'Cannot open archive.' );

	$entries     = [];
	$seen        = [];
	$expanded    = 0;
	$entry_count = \count( $zip );

	try {
		for ( $i = 0; $i < $entry_count; $i++ ) {
			$s    = $zip->statIndex( $i );
			$name = $s['name'];

			if (
				   ! str_starts_with( $name, $prefix . '/' )
				|| str_contains( $name, '\\' )
				|| str_contains( $name, ':' )
				|| preg_match(
					'/(?:^|\/)\.\.?(\/|$)|[\x00-\x1f<>"|?*]/',
					$name,
				)
			) {
				throw new RuntimeException( 'Unsafe archive path.' );
			}

			$relative = substr( $name, \strlen( $prefix ) + 1 );

			if ( '' === $relative || str_ends_with( $name, '/' ) ) continue;

			foreach ( explode( '/', $relative ) as $component ) {
				if (
					   '' === $component
					|| preg_match( '/[. ]$/', $component )
					|| preg_match(
						'/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i',
						$component,
					)
				) {
					throw new RuntimeException( 'Nonportable archive path.' );
				}
			}

			$key = strtolower( $relative );

			if ( isset( $seen[ $key ] ) )
				throw new RuntimeException( 'Duplicate archive path.' );

			$seen[ $key ] = true;
			$zip->getExternalAttributesIndex( $i, $os, $attributes );

			if ( 0120000 === ( $attributes >> 16 & 0170000 ) )
				throw new RuntimeException( 'Archive links are refused.' );

			$expanded += $s['size'];

			// Bound expansion to 1 GiB and 100,000 entries before writing anything.
			if ( 1073741824 < $expanded || 100000 <= \count( $entries ) )
				throw new RuntimeException(
					'Archive expansion limit exceeded.',
				);

			$entries[] = [
				'index' => $i,
				'path'  => $relative,
				'size'  => $s['size'],
				'crc'   => $s['crc'],
			];
		}

		// Validate the complete directory before writing any archive entry.
		foreach ( $entries as $entry ) {
			$file = $destination . '/' . $entry['path'];
			require_contained( $file, $destination );
			require_unlinked_path( $file );

			if ( file_exists( $file ) || is_link( $file ) )
				throw new RuntimeException(
					'Refusing to overwrite an extracted file.',
				);

			$parent = \dirname( $entry['path'] );

			while ( '.' !== $parent ) {
				if ( isset( $seen[ strtolower( $parent ) ] ) )
					throw new RuntimeException(
						'Archive file conflicts with a directory.',
					);

				if (
					   file_exists( $destination . '/' . $parent )
					&& ! is_dir( $destination . '/' . $parent )
				) {
					throw new RuntimeException(
						'Archive parent conflicts with an existing file.',
					);
				}

				$parent = \dirname( $parent );
			}
		}

		foreach ( $entries as &$entry ) {
			$file = $destination . '/' . $entry['path'];
			require_contained( $file, $destination );

			if ( file_exists( $file ) || is_link( $file ) )
				throw new RuntimeException(
					'Refusing to overwrite an extracted file.',
				);

			make_directory( \dirname( $file ) );
			$input    = $zip->getStream( $prefix . '/' . $entry['path'] );
			$output   = null;
			$created  = false;
			$complete = false;

			try {
				if ( ! \is_resource( $input ) )
					throw new RuntimeException( 'Cannot read archive entry.' );

				$output = fopen( $file, 'xb' );

				if ( ! \is_resource( $output ) )
					throw new RuntimeException(
						'Cannot create archive destination.',
					);

				$created = true;

				if ( stream_copy_to_stream( $input, $output ) !== $entry['size'] )
					throw new RuntimeException( 'Truncated archive entry.' );

				$complete = true;
			} finally {
				if ( \is_resource( $input ) )
					fclose( $input );

				if ( \is_resource( $output ) )
					fclose( $output );

				if ( $created && ! $complete )
					unlink( $file );
			}

			unset( $entry['index'] );
		}
	} finally {
		$zip->close();
	}

	return $entries;
}

/**
 * Selects stable SEO output and normalizes each fixture's own site URL.
 *
 * @param string $html Complete HTML response.
 * @param string $base_url Fixture URL to normalize, including JSON-escaped forms.
 * @return array{sha256:string,selection:string[]} Selected markup and digest.
 */
function selected_output( string $html, string $base_url ): array {

	$dom      = new \DOMDocument();
	$previous = libxml_use_internal_errors( true );

	try {
		$dom->loadHTML(
			$html,
			\LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING,
		);
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
	}

	$xpath     = new \DOMXPath( $dom );
	$selection = [];
	$escaped   = substr(
		json_encode( $base_url, \JSON_THROW_ON_ERROR ),
		1,
		-1,
	);

	foreach ( $xpath->query(
		'//head/title | //head/meta | //head/link[@rel="canonical"] | //script[@type="application/ld+json"]',
	) as $node )
		$selection[] = str_replace(
			[ $base_url, $escaped ],
			'{SITE}',
			$dom->saveHTML( $node ),
		);

	return [
		'sha256'    => hash(
			'sha256',
			implode( "\n", $selection ),
		),
		'selection' => $selection,
	];
}
