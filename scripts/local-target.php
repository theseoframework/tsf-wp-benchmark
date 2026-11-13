<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Saves a new private target file from an approved Local shell site.
 *
 * Run through Local's official CLI with the selected WordPress site loaded:
 * localwp cli SITE -- "eval-file /repo/scripts/local-target.php /repo/.local/target.json"
 *
 * This script reads credentials but never prints them. The caller must obtain
 * approval for the dedicated shell site before invoking it.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI )
	exit( 1 );

$destination = $args[0] ?? '';

if (
	   ! is_string( $destination )
	|| '' === $destination
	|| file_exists( $destination )
	|| is_link( $destination )
	|| ! is_dir( dirname( $destination ) )
) {
	WP_CLI::error( 'Choose a new private target.json outside the served site.' );
}

$root   = realpath( ABSPATH );
$parent = realpath( dirname( $destination ) );

if ( false === $root || false === $parent )
	WP_CLI::error( 'Cannot resolve the site or destination directory.' );

$root   = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
$parent = rtrim( str_replace( '\\', '/', $parent ), '/' ) . '/';

if ( str_starts_with( strtolower( $parent ), strtolower( $root ) ) )
	WP_CLI::error( 'The target file must be outside the web root.' );

$target = [
	'provider'      => 'local',
	'document_root' => realpath( ABSPATH ),
	'url'           => home_url(),
	'php'           => PHP_BINARY,
	'php_ini'       => php_ini_loaded_file() ?: null,
	'database'      => [
		'host'     => DB_HOST,
		'name'     => DB_NAME,
		'user'     => DB_USER,
		'password' => DB_PASSWORD,
	],
	'downloads'     => false,
	'isolation'     => [
		'mode'        => 'wordpress-http',
		'description' => 'Block and privately log WordPress HTTP/mail. Direct cURL/raw sockets remain uncontrolled; no host network changes.',
	],
];

try {
	$json = json_encode(
		$target,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
	) . "\n";
} catch ( JsonException $error ) {
	WP_CLI::error( 'Cannot encode the private target configuration.' );
}

// Exclusive creation also rejects a file or symlink created after validation.
$stream = @fopen( $destination, 'xb' );

if ( false === $stream )
	WP_CLI::error( 'Cannot create the private target file.' );

// Windows uses its inherited ACL; chmod restricts access on supporting filesystems.
@chmod( $destination, 0600 );
$written = fwrite( $stream, $json );
$flushed = fflush( $stream );
fclose( $stream );

if ( strlen( $json ) !== $written || ! $flushed ) {
	@unlink( $destination );
	WP_CLI::error( 'Cannot finish writing the private target file.' );
}

WP_CLI::success(
	'Private target configuration saved. No credentials printed. Review it before planning.',
);
