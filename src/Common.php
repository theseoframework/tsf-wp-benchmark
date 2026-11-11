<?php
/**
 * Shared filesystem, process, and evidence helpers for the native benchmark.
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

const VERSION = '0.1.0';

/**
 * Reads structured JSON from an existing local file.
 *
 * @param string $file File to read.
 * @return array Decoded JSON object or list.
 * @throws RuntimeException When the file cannot be read or its JSON is not an object or list.
 */
function read_json( string $file ): array {

	if ( ! is_file( $file ) )
		throw new RuntimeException(
			'Required JSON file is missing or unreadable: ' . basename( $file ),
		);

	$bytes = file_get_contents( $file );

	if ( false === $bytes )
		throw new RuntimeException(
			'Cannot read ' . basename( $file ),
		);

	$data = json_decode(
		$bytes,
		true,
		512,
		\JSON_THROW_ON_ERROR,
	);

	if ( ! \is_array( $data ) )
		throw new RuntimeException(
			'Expected a JSON object or array: ' . basename( $file ),
		);

	return $data;
}

/**
 * Replaces a file only after its complete content has been written.
 *
 * A sibling temporary file keeps rename on the same filesystem. POSIX modes
 * restrict receipts and generated configuration to the current user.
 *
 * @param string $file  Destination file.
 * @param string $bytes Complete replacement content.
 * @return void
 * @throws RuntimeException When the destination is unsafe or replacement cannot finish.
 */
function write_bytes( string $file, string $bytes ): void {

	require_unlinked_path( \dirname( $file ) );

	$temp   = $file . '.part-' . bin2hex( random_bytes( 8 ) );
	$stream = fopen( $temp, 'xb' );

	if ( false === $stream )
		throw new RuntimeException(
			'Cannot create replacement for ' . basename( $file ),
		);

	try {
		if ( 'Windows' !== \PHP_OS_FAMILY && ! chmod( $temp, 0600 ) )
			throw new RuntimeException(
				'Cannot restrict replacement permissions.',
			);

		$offset = 0;
		$length = \strlen( $bytes );

		while ( $offset < $length ) {
			$written = fwrite(
				$stream,
				substr( $bytes, $offset ),
			);

			if ( false === $written || 0 === $written )
				throw new RuntimeException(
					'Cannot write ' . basename( $file ),
				);

			$offset += $written;
		}

		if ( ! fflush( $stream ) )
			throw new RuntimeException(
				'Cannot flush ' . basename( $file ),
			);

		fclose( $stream );
		$stream = null;

		if ( ! rename( $temp, $file ) )
			throw new RuntimeException(
				'Cannot replace ' . basename( $file ),
			);
	} finally {
		if ( \is_resource( $stream ) )
			fclose( $stream );

		if ( is_file( $temp ) )
			unlink( $temp );
	}
}

/**
 * Atomically stores JSON while preserving float identity for exact replay.
 *
 * @param string $file Destination file.
 * @param array  $data JSON-serializable data.
 * @return void
 */
function write_json( string $file, array $data ): void {
	write_bytes(
		$file,
		json_encode(
			$data,
			\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
		) . "\n",
	);
}

/**
 * Hashes structured data without depending on object-key insertion order.
 *
 * Lists retain their order and floating-point values retain their type.
 *
 * @param array $data Data to fingerprint.
 * @return string SHA-256 digest.
 */
function digest( array $data ): string {

	/**
	 * Sorts nested object keys without reordering lists.
	 *
	 * @param array $value Structured value.
	 * @return array Value with deterministic object-key order.
	 */
	$sort = function ( array $value ) use ( &$sort ): array {

		if ( ! array_is_list( $value ) )
			ksort( $value );

		foreach ( $value as &$item )
			if ( \is_array( $item ) )
				$item = $sort( $item );

		return $value;
	};

	return hash(
		'sha256',
		json_encode(
			$sort( $data ),
			\JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
		),
	);
}

/**
 * Creates an owned directory with private permissions where POSIX modes apply.
 *
 * @param string $path Directory to create.
 * @return void
 * @throws RuntimeException When the directory cannot be created.
 */
function make_directory( string $path ): void {
	if ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) )
		throw new RuntimeException( 'Cannot create directory.' );
}

/**
 * Normalizes separators and removes a trailing directory separator.
 *
 * @param string $path Filesystem path.
 * @return string Normalized path.
 */
function normalized_path( string $path ): string {
	return rtrim(
		str_replace( '\\', '/', $path ),
		'/',
	);
}

/**
 * Resolves a path whose parent already exists without creating directories.
 *
 * @param string $path Existing path or proposed leaf name.
 * @return string Absolute path with its parent resolved.
 * @throws RuntimeException When the parent is missing or the path does not name a leaf entry.
 */
function absolute_path( string $path ): string {

	if ( ! preg_match(
		'/^(?:[A-Za-z]:[\/\\\\]|\/)/',
		$path,
	) ) {
		$path = getcwd() . '/' . $path;
	}

	$parent = realpath( \dirname( $path ) );

	if (
		   false === $parent
		|| \in_array(
			basename( $path ),
			[ '.', '..', '' ],
			true,
		)
	) {
		throw new RuntimeException(
			'Path parent must exist and path must name a file or directory.',
		);
	}

	return normalized_path( $parent ) . '/' . basename( $path );
}

/**
 * Checks lexical containment, including paths not yet created.
 *
 * @param string $path Child path to check.
 * @param string $root Ownership boundary.
 * @return void
 * @throws RuntimeException When the path is outside the ownership boundary or contains traversal.
 */
function require_contained( string $path, string $root ): void {

	$path = normalized_path( $path );
	$root = normalized_path( $root );

	if ( 'Windows' === \PHP_OS_FAMILY ) {
		$path = strtolower( $path );
		$root = strtolower( $root );
	}

	if (
		   ! str_starts_with( $path, $root . '/' )
		|| preg_match( '/(?:^|\/)\.\.?(\/|$)/', $path )
	) {
		throw new RuntimeException(
			'Path escapes the owned directory.',
		);
	}
}

/**
 * Removes a contained owned entry without following linked directories.
 *
 * @param string $path Owned entry to remove.
 * @param string $root Canonical ownership boundary.
 * @return void
 * @throws RuntimeException When ownership is unsafe or an entry cannot be removed.
 */
function remove_owned_tree( string $path, string $root ): void {

	require_contained( $path, $root );

	if ( ! file_exists( $path ) && ! is_link( $path ) ) return;

	if ( is_link( $path ) ) {
		require_unlinked_path( \dirname( $path ) );

		if ( ! unlink( $path ) )
			throw new RuntimeException( 'Cannot remove owned link.' );

		return;
	}

	require_unlinked_path( $path );

	if ( is_dir( $path ) ) {
		foreach ( new \FilesystemIterator(
			$path,
			\FilesystemIterator::SKIP_DOTS,
		) as $entry )
			remove_owned_tree( $entry->getPathname(), $root );

		if ( ! rmdir( $path ) )
			throw new RuntimeException(
				'Cannot remove owned directory.',
			);
	} elseif ( ! unlink( $path ) ) {
		throw new RuntimeException( 'Cannot remove owned file.' );
	}
}

/**
 * Refuses linked ancestors before writing or deleting owned resources.
 *
 * Relative paths are based on the current directory. Parent traversal is
 * rejected rather than normalized across a possibly linked component.
 *
 * @param string $path Filesystem path, including uncreated descendants.
 * @return void
 * @throws RuntimeException When traversal, linked ancestry, or an unresolved path is found.
 */
function require_unlinked_path( string $path ): void {

	$existing = str_replace( '\\', '/', $path );

	if ( ! preg_match(
		'/^(?:[A-Za-z]:\/|\/)/',
		$existing,
	) ) {
		$existing = getcwd() . '/' . $existing;
	}

	$existing = preg_replace(
		'/\/\.(?=\/|$)/',
		'',
		$existing,
	);

	if ( preg_match(
		'/(?:^|\/)\.\.(?:\/|$)/',
		$existing,
	) ) {
		throw new RuntimeException(
			'Parent traversal in filesystem path refused.',
		);
	}

	$existing = normalized_path( $existing );

	if ( '' === $existing )
		$existing = '/';

	if ( preg_match( '/\A[A-Za-z]:\z/', $existing ) )
		$existing .= '/';

	while ( ! file_exists( $existing ) && ! is_link( $existing ) ) {
		$parent = normalized_path( \dirname( $existing ) );

		if ( '' === $parent )
			$parent = '/';

		if ( preg_match( '/\A[A-Za-z]:\z/', $parent ) )
			$parent .= '/';

		if ( $existing === $parent )
			throw new RuntimeException(
				'Cannot resolve path ancestry.',
			);

		$existing = $parent;
	}

	$resolved = realpath( $existing );
	$matches  = false !== $resolved && (
		   'Windows' === \PHP_OS_FAMILY
		? 0 === strcasecmp(
			normalized_path( $resolved ),
			normalized_path( $existing ),
		)
		: normalized_path( $resolved ) === normalized_path( $existing )
	);

	if ( is_link( $existing ) || ! $matches )
		throw new RuntimeException(
			'Linked filesystem path refused.',
		);
}

/**
 * Locks a run against simultaneous mutating CLI commands.
 *
 * @param string $run Existing run directory.
 * @return resource Close this handle when the command finishes.
 * @throws RuntimeException When the lock path is unsafe, unavailable, or already locked.
 */
function lock_run( string $run ) {

	require_unlinked_path( "$run/.runner.lock" );

	$lock = fopen( $run . '/.runner.lock', 'c' );

	if ( false === $lock )
		throw new RuntimeException( 'Cannot open the run lock.' );

	if ( ! flock( $lock, \LOCK_EX | \LOCK_NB ) ) {
		fclose( $lock );
		throw new RuntimeException(
			'Another command is using this run. Wait for it to finish.',
		);
	}

	return $lock;
}

/**
 * Executes literal process arguments with a timeout and bounded output.
 *
 * @param string[]    $arguments   Executable and literal arguments, never shell code.
 * @param string|null $cwd         Optional working directory.
 * @param array|null  $environment Optional child environment; null inherits it.
 * @param int         $timeout     Runtime limit in seconds.
 * @return array{exit:int,stdout:string,stderr:string} Process result.
 * @throws RuntimeException When process setup fails or its time or output bound is exceeded.
 */
function execute( array $arguments, ?string $cwd = null, ?array $environment = null, int $timeout = 300 ): array {

	// File descriptors avoid Windows pipe reads blocking the timeout loop.
	$stdout = tmpfile();
	$stderr = tmpfile();
	$p      = null;

	try {
		if ( ! $stdout || ! $stderr )
			throw new RuntimeException(
				'Cannot create child output streams.',
			);

		$p = proc_open(
			$arguments,
			[
				0 => [ 'pipe', 'r' ],
				1 => $stdout,
				2 => $stderr,
			],
			$pipes,
			$cwd,
			$environment,
			[ 'bypass_shell' => true ],
		);

		if ( ! \is_resource( $p ) )
			throw new RuntimeException( 'Cannot start child process.' );

		fclose( $pipes[0] );
		$deadline = hrtime( true ) + $timeout * 1000000000; // Nanoseconds per second.

		do {
			$status = proc_get_status( $p );

			if ( $status['running'] && hrtime( true ) > $deadline )
				throw new RuntimeException(
					'Owned child process timed out.',
				);

			// Captured output is limited to 16 MiB, even if the child exits between polls.
			if ( 16777216 < fstat( $stdout )['size'] + fstat( $stderr )['size'] )
				throw new RuntimeException(
					'Child process output limit exceeded.',
				);

			// Poll every 10 ms.
			if ( $status['running'] )
				usleep( 10000 );
		} while ( $status['running'] );

		rewind( $stdout );
		rewind( $stderr );
		$out  = stream_get_contents( $stdout );
		$err  = stream_get_contents( $stderr );
		$exit = proc_close( $p );
		$p    = null;

		return [
			'exit'   => 0 <= $status['exitcode'] ? $status['exitcode'] : $exit,
			'stdout' => $out,
			'stderr' => $err,
		];
	} finally {
		if ( \is_resource( $p ) ) {
			// SIGKILL prevents a POSIX child ignoring SIGTERM from defeating the timeout.
			proc_terminate(
				$p,
				( 'Windows' === \PHP_OS_FAMILY ) ? 15 : 9,
			);
			proc_close( $p );
		}

		if ( \is_resource( $stdout ) )
			fclose( $stdout );

		if ( \is_resource( $stderr ) )
			fclose( $stderr );
	}
}

/**
 * Fingerprints the implementation used for collection and numerical replay.
 *
 * @return array Runner version, relative source digests, and combined digest.
 */
function runner_manifest(): array {

	$root  = \dirname( __DIR__ );
	$files = [ $root . '/benchmark.php' ];

	foreach ( [ 'src', 'scripts' ] as $directory ) {
		if ( ! is_dir( $root . '/' . $directory ) ) continue;

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator(
			$root . '/' . $directory,
			\FilesystemIterator::SKIP_DOTS,
		) ) as $entry )
			if ( $entry->isFile() )
				$files[] = $entry->getPathname();
	}

	$hashes = [];

	foreach ( $files as $file )
		$hashes[ substr(
			normalized_path( $file ),
			\strlen( normalized_path( $root ) ) + 1,
		) ] = hash_file( 'sha256', $file );

	ksort( $hashes );

	return [
		'version' => VERSION,
		'files'   => $hashes,
		'sha256'  => digest( $hashes ),
	];
}
