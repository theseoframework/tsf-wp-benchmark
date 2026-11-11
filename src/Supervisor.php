<?php
/**
 * Owned native Windows HTTP/FastCGI servers with automatic process-tree cleanup.
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
 * Rejects characters that would expand or terminate an nginx configuration value.
 *
 * @param string $path Literal native path.
 * @return string Slash-normalized path safe inside nginx double quotes.
 * @throws RuntimeException When the path contains unsupported characters.
 */
function supervisor_path( string $path ): string {

	if ( preg_match( '/[\x00-\x1f\x7f"$]/', $path ) )
		throw new RuntimeException( 'Unsupported character in supervisor path.' );

	return normalized_path( $path );
}

/**
 * Validates and freezes optional server binaries and loopback ports before approval.
 *
 * @param array $target Private target with an optional worker_supervisor object.
 * @return array Target with frozen executable digests and HTTP origin.
 * @throws RuntimeException When a binary, directory, setting, or port is invalid.
 */
function normalize_worker_supervisor( array $target ): array {

	if ( ! isset( $target['worker_supervisor'] ) ) return $target;

	$server = $target['worker_supervisor'];
	if ( ! \is_array( $server ) )
		throw new RuntimeException( 'worker_supervisor must be an object.' );

	foreach ( [ 'nginx', 'php_cgi' ] as $key ) {
		if ( ! \is_string( $server[ $key ] ?? null ) || ! is_file( $server[ $key ] ) )
			throw new RuntimeException( "Select an installed $key executable." );

		$server[ $key ] = supervisor_path( realpath( $server[ $key ] ) );
		$hash           = hash_file( 'sha256', $server[ $key ] );
		if ( isset( $server[ $key . '_sha256' ] ) && $server[ $key . '_sha256' ] !== $hash )
			throw new RuntimeException( 'An approved server binary changed.' );

		$server[ $key . '_sha256' ] = $hash;
	}

	$server['workers'] ??= 2;
	if ( ! \is_int( $server['workers'] ) || 1 > $server['workers'] || 4 < $server['workers'] )
		throw new RuntimeException( 'Use one to four active supervised workers.' );

	$server['dll_paths'] ??= [ \dirname( $target['php'] ) ];
	if ( ! \is_array( $server['dll_paths'] ) || ! array_is_list( $server['dll_paths'] ) )
		throw new RuntimeException( 'DLL paths must be a list.' );

	foreach ( $server['dll_paths'] as &$path ) {
		if ( ! \is_string( $path ) || ! is_dir( $path ) )
			throw new RuntimeException( 'Select installed DLL directories.' );

		$path = supervisor_path( realpath( $path ) );
	}
	unset( $path );

	$held = [];
	try {
		foreach ( [ 'http_port', 'fastcgi_port' ] as $key ) {
			if ( ! isset( $server[ $key ] ) ) {
				$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
				if ( ! $socket ) throw new RuntimeException( 'Cannot allocate a loopback port.' );

				$held[]         = $socket;
				$server[ $key ] = (int) substr( strrchr( stream_socket_get_name( $socket, false ), ':' ), 1 );
			}
			if ( ! \is_int( $server[ $key ] ) || 1024 > $server[ $key ] || 65535 < $server[ $key ] )
				throw new RuntimeException( 'Supervisor ports must be integers from 1024 to 65535.' );
		}
	} finally {
		foreach ( $held as $socket ) fclose( $socket );
	}
	if ( $server['http_port'] === $server['fastcgi_port'] )
		throw new RuntimeException( 'HTTP and FastCGI ports must differ.' );

	$target['worker_supervisor'] = $server;
	$target['url']               = 'http://127.0.0.1:' . $server['http_port'];

	return $target;
}

/**
 * Renders the owned nginx configuration without evaluating configuration fragments.
 *
 * @param array  $target Approved normalized private target.
 * @param string $token Random health-response identity.
 * @return string Complete native nginx configuration.
 * @throws RuntimeException When paths or the template are invalid.
 */
function supervisor_nginx_config( array $target, string $token ): string {

	if ( ! preg_match( '/\A[a-f0-9]{32}\z/', $token ) )
		throw new RuntimeException( 'Invalid supervisor health identity.' );

	$template = file_get_contents( \dirname( __DIR__ ) . '/scripts/stubs/supervisor-nginx.conf' );
	if ( false === $template ) throw new RuntimeException( 'Cannot read nginx template.' );

	return strtr( $template,
		[
			'{{ROOT}}'         => supervisor_path( $target['document_root'] ),
			'{{PID}}'          => 'nginx.pid',
			'{{ERROR_LOG}}'    => 'nginx-error.log',
			'{{HTTP_PORT}}'    => (string) $target['worker_supervisor']['http_port'],
			'{{FASTCGI_PORT}}' => (string) $target['worker_supervisor']['fastcgi_port'],
			'{{HEALTH_PATH}}'  => "tsfb-health-$token",
			'{{HEALTH_TOKEN}}' => $token,
		] );
}

/**
 * Retains native command bytes even when Windows diagnostics are not UTF-8.
 *
 * Behavioral JSON from fixtures remains strict; only native diagnostic text uses
 * a readable fallback while its original bytes and digest remain inspectable.
 *
 * @param string $directory Owned private supervisor directory.
 * @param array  $result Native syntax-check exit status and output streams.
 * @return array UTF-8-safe diagnostic receipt with raw stream digests.
 */
function supervisor_diagnostics( string $directory, array $result ): array {

	$receipt = [ 'exit' => $result['exit'] ];
	foreach ( [ 'stdout', 'stderr' ] as $stream ) {
		write_bytes( "$directory/nginx-syntax-$stream.log", $result[ $stream ] );
		$receipt[ $stream ]             = preg_match( '//u', $result[ $stream ] )
			? $result[ $stream ]
			: "Native text retained in nginx-syntax-$stream.log";
		$receipt[ $stream . '_sha256' ] = hash( 'sha256', $result[ $stream ] );
	}

	return $receipt;
}

/**
 * Starts owned native servers; Windows job ownership also covers abrupt runner exit.
 *
 * No Local installation or configuration file is changed. A supervisor keeps the
 * FastCGI listener open while its children recycle after 500 requests. Startup
 * polling is never part of a measured sample and does not retry benchmark requests.
 *
 * @param array  $target Approved target with optional worker_supervisor settings.
 * @param string $run Private operation directory, outside the served root.
 * @return array|null Owned process handle, or null for an externally served target.
 * @throws RuntimeException When ownership, binary identity, ports, or startup fail.
 * @throws \Throwable Rethrows startup failures after stopping owned servers.
 */
function start_worker_supervisor( array $target, string $run ): ?array {

	if ( ! isset( $target['worker_supervisor'] ) ) return null;

	if ( 'Windows' !== \PHP_OS_FAMILY )
		throw new RuntimeException( 'The native worker supervisor currently supports Windows only.' );

	$target = normalize_worker_supervisor( $target );
	$server = $target['worker_supervisor'];
	foreach ( [ 'http_port', 'fastcgi_port' ] as $key ) {
		$listener = @stream_socket_client( 'tcp://127.0.0.1:' . $server[ $key ], $errno, $error, 0.1 );
		if ( $listener ) {
			fclose( $listener );
			throw new RuntimeException( "Supervisor port is occupied: {$server[$key]}" );
		}
		$socket = @stream_socket_server( 'tcp://127.0.0.1:' . $server[ $key ], $errno, $error );
		if ( ! $socket ) throw new RuntimeException( "Supervisor port is occupied: {$server[$key]}" );

		fclose( $socket );
	}
	$directory = "$run/worker-supervisor";
	if ( file_exists( $directory ) )
		throw new RuntimeException( 'Preserve prior supervisor evidence; use a fresh operation directory.' );

	make_directory( "$directory/logs" );
	make_directory( "$directory/temp" );
	$token = bin2hex( random_bytes( 16 ) );
	write_bytes( "$directory/nginx.conf", supervisor_nginx_config( $target, $token ) );
	// Relative nginx arguments avoid Windows code-page conversion of Unicode paths.
	$syntax = execute( [ $server['nginx'], '-t', '-p', './', '-c', 'nginx.conf' ], $directory );
	write_json( "$directory/nginx-syntax.json", supervisor_diagnostics( $directory, $syntax ) );
	if ( 0 !== $syntax['exit'] ) throw new RuntimeException( 'Owned nginx configuration failed validation; inspect nginx-syntax.json.' );

	$interpreter = find_executable( 'powershell.exe' ) ?? find_executable( 'pwsh.exe' );
	if ( ! $interpreter ) throw new RuntimeException( 'An installed PowerShell runtime is required.' );

	$config = [
		'parent_pid'   => getmypid(),
		'directory'    => supervisor_path( $directory ),
		'prefix'       => './',
		'php_cgi'      => $server['php_cgi'],
		'nginx'        => $server['nginx'],
		'php_ini'      => empty( $target['php_ini'] ) ? null : supervisor_path( $target['php_ini'] ),
		'workers'      => $server['workers'],
		'fastcgi_port' => $server['fastcgi_port'],
		'dll_paths'    => $server['dll_paths'],
		'nginx_config' => 'nginx.conf',
		'ready'        => supervisor_path( "$directory/ready.private.json" ),
		'failure'      => supervisor_path( "$directory/failure.private.json" ),
		'stop'         => supervisor_path( "$directory/stop" ),
		'stopped'      => supervisor_path( "$directory/stopped.private.json" ),
	];
	write_json( "$directory/config.private.json", $config );
	$process = proc_open(
		[ $interpreter, '-NoLogo', '-NoProfile', '-NonInteractive', '-File', \dirname( __DIR__ ) . '/scripts/supervisor-windows.ps1', '-ConfigPath', "$directory/config.private.json" ],
		[
			0 => [ 'pipe', 'r' ],
			1 => [ 'file', "$directory/controller.log", 'a' ],
			2 => [ 'file', "$directory/controller.log", 'a' ],
		],
		$pipes,
		$directory,
		null,
		[
			'bypass_shell'       => true,
			'create_new_console' => false,
		],
	);
	if ( ! \is_resource( $process ) ) throw new RuntimeException( 'Cannot launch native supervisor.' );

	fclose( $pipes[0] );
	$handle = [
		'process'   => $process,
		'config'    => $config,
		'directory' => $directory,
	];
	register_shutdown_function( __NAMESPACE__ . '\\shutdown_worker_supervisor', $handle );
	try {
		$deadline = hrtime( true ) + 20_000_000_000; // Allow native helper compilation and startup.
		do {
			if ( file_exists( $config['failure'] ) || ! proc_get_status( $process )['running'] )
				throw new RuntimeException( 'Native supervisor failed; inspect its retained controller log.' );

			if ( file_exists( $config['ready'] ) ) {
				try {
					$response = request( $target['url'] . "/tsfb-health-$token" );
					if ( 200 === $response['status'] && $token === $response['body'] ) return $handle;
				} catch ( RuntimeException $error ) {
					// Retry only startup readiness; no measured request has begun.
					write_json( "$directory/readiness-error.json", [ 'error' => $error->getMessage() ] );
				}
			}
			usleep( 100000 );
		} while ( hrtime( true ) < $deadline );
		throw new RuntimeException( 'Native supervisor startup did not qualify.' );
	} catch ( \Throwable $error ) {
		stop_worker_supervisor( $handle );
		throw $error;
	}
}

/**
 * Signals owned servers before PHP destroys process resources during shutdown.
 *
 * This avoids shutdown waiting on a controller watching the still-alive runner.
 * Explicit cleanup has already closed the process resource and needs no repeat.
 *
 * @param array $handle Owned controller and stop-marker identity.
 * @return void
 */
function shutdown_worker_supervisor( array $handle ): void {

	if ( ! \is_resource( $handle['process'] ) ) return;

	try {
		stop_worker_supervisor( $handle );
	} catch ( \Throwable $error ) {
		// Closing the kernel job also handles a failure to write its cleanup receipt.
		if ( \is_resource( $handle['process'] ) ) {
			proc_terminate( $handle['process'] );
			proc_close( $handle['process'] );
		}
	}
}

/**
 * Stops only the owned controller; closing its Windows job also removes descendants.
 *
 * @param array|null $handle Handle returned by start_worker_supervisor().
 * @return array Cleanup outcome without native process handles.
 * @throws RuntimeException When the stop marker cannot be written.
 */
function stop_worker_supervisor( ?array $handle ): array {

	if ( null === $handle ) return [ 'used' => false ];

	write_bytes( $handle['config']['stop'], '' );
	$deadline = hrtime( true ) + 5_000_000_000; // Bounded graceful shutdown.
	do {
		$status = proc_get_status( $handle['process'] );
		if ( ! $status['running'] ) break;

		usleep( 50000 );
	} while ( hrtime( true ) < $deadline );
	$forced = $status['running'];
	if ( $forced ) proc_terminate( $handle['process'] );

	proc_close( $handle['process'] );
	$result = [
		'used'                   => true,
		'forced_controller_stop' => $forced,
		'receipt'                => is_file( $handle['config']['stopped'] ) ? read_json( $handle['config']['stopped'] ) : null,
	];
	write_json( $handle['directory'] . '/cleanup.json', $result );

	return $result;
}
