<?php
/**
 * Owned server configuration, port, identity, and process-tree regressions.
 *
 * @package TSF_WP_Benchmark
 */

use function TsfBenchmark\execute;
use function TsfBenchmark\find_executable;
use function TsfBenchmark\normalize_worker_supervisor;
use function TsfBenchmark\start_worker_supervisor;
use function TsfBenchmark\supervisor_nginx_config;
use function TsfBenchmark\supervisor_path;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

return [
	/**
	 * Checks that native code-page diagnostics stay lossless without corrupting JSON.
	 *
	 * @return void
	 */
	'supervisor retains non-UTF8 native diagnostics byte for byte' => function (): void {

		$directory = runner_temp();
		try {
			$raw     = "Native path: caf\xe9";
			$receipt = TsfBenchmark\supervisor_diagnostics( $directory,
				[
					'exit'   => 0,
					'stdout' => '',
					'stderr' => $raw,
				] );
			TsfBenchmark\write_json( "$directory/receipt.json", $receipt );
			expect( file_get_contents( "$directory/nginx-syntax-stderr.log" ) === $raw );
			expect( hash( 'sha256', $raw ) === $receipt['stderr_sha256'] );
			expect( 0 === TsfBenchmark\read_json( "$directory/receipt.json" )['exit'] );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/**
	 * Checks literal paths and keeps configuration expansion out of native templates.
	 *
	 * @return void
	 */
	'supervisor rejects nginx expansion and control characters' => function (): void {

		foreach ( [ '/tmp/a"b', '/tmp/$variable', "/tmp/a\nb" ] as $path ) {
			try {
				supervisor_path( $path );
				throw new RuntimeException( 'Unsafe path accepted.' );
			} catch ( RuntimeException $error ) {
				expect( 'Unsafe path accepted.' !== $error->getMessage() );
			}
		}
		expect( 'C:/path with spaces' === supervisor_path( 'C:\\path with spaces' ) );
	},
	/**
	 * Checks frozen executable identity and rejects invalid worker/port settings.
	 *
	 * @return void
	 */
	'supervisor freezes binary identity and rejects unsafe settings' => function (): void {

		$target     = [
			'php'               => PHP_BINARY,
			'worker_supervisor' => [
				'nginx'        => PHP_BINARY,
				'php_cgi'      => PHP_BINARY,
				'http_port'    => 49001,
				'fastcgi_port' => 49002,
			],
		];
		$normalized = normalize_worker_supervisor( $target );
		expect( 'http://127.0.0.1:49001' === $normalized['url'] );
		expect( 2 === $normalized['worker_supervisor']['workers'] );
		expect( normalize_worker_supervisor( $normalized ) === $normalized );
		foreach ( [ [ 'workers', 100000 ], [ 'workers', true ], [ 'http_port', 80 ], [ 'fastcgi_port', 49001 ], [ 'php_cgi_sha256', str_repeat( '0', 64 ) ] ] as $case ) {
			$invalid                                  = $normalized;
			$invalid['worker_supervisor'][ $case[0] ] = $case[1];
			try {
				normalize_worker_supervisor( $invalid );
				throw new RuntimeException( 'Invalid server setting accepted.' );
			} catch ( RuntimeException $error ) {
				expect( 'Invalid server setting accepted.' !== $error->getMessage() );
			}
		}
	},
	/**
	 * Checks that the HTTP listener is loopback-only and renders path spaces literally.
	 *
	 * @return void
	 */
	'supervisor config is loopback-only and preserves path spaces' => function (): void {

		$config = supervisor_nginx_config( [
			'document_root'     => 'C:/test site/public',
			'worker_supervisor' => [
				'http_port'    => 49001,
				'fastcgi_port' => 49002,
			],
		],
			str_repeat( 'a', 32 ) );
		expect( str_contains( $config, 'listen 127.0.0.1:49001;' ) );
		expect( str_contains( $config, 'root "C:/test site/public";' ) );
		expect( str_contains( $config, 'fastcgi_pass 127.0.0.1:49002;' ) );
		expect( str_contains( $config, 'master_process off;' ) );
		expect( ! str_contains( $config, '{{' ) );
	},
	/**
	 * Checks conflict rejection before any controller or native server is launched.
	 *
	 * @return void
	 */
	'supervisor occupied port is rejected before startup' => function (): void {

		if ( 'Windows' !== PHP_OS_FAMILY ) return;

		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
		$port   = (int) substr( strrchr( stream_socket_get_name( $socket, false ), ':' ), 1 );
		try {
			$target = normalize_worker_supervisor( [
				'php'               => PHP_BINARY,
				'worker_supervisor' => [
					'nginx'     => PHP_BINARY,
					'php_cgi'   => PHP_BINARY,
					'http_port' => $port,
				],
			] );
			try {
				start_worker_supervisor( $target, sys_get_temp_dir() . '/must-not-create-supervisor' );
				throw new RuntimeException( 'Occupied port accepted.' );
			} catch ( RuntimeException $error ) {
				expect( str_contains( $error->getMessage(), 'occupied' ) );
			}
		} finally {
			fclose( $socket );
		}
	},
	/**
	 * Checks kill-on-close ownership with a real descendant and unrelated sentinel.
	 *
	 * @return void
	 */
	'Windows job closes owned descendants and preserves unrelated processes' => function (): void {

		if ( 'Windows' !== PHP_OS_FAMILY ) return;

		$directory = sys_get_temp_dir() . '/tsfb-job-' . bin2hex( random_bytes( 6 ) );
		mkdir( $directory );
		try {
			$interpreters = array_unique( array_filter( [ find_executable( 'powershell.exe' ), find_executable( 'pwsh.exe' ) ] ) );
			foreach ( $interpreters as $index => $interpreter ) {
				$result = execute( [ $interpreter, '-NoProfile', '-File', __DIR__ . '/fixtures/supervisor-job-test.ps1', '-Repository', dirname( __DIR__ ), '-Php', PHP_BINARY, '-Receipt', "$directory/child-$index.json" ], null, null, 20 );
				expect( 0 === $result['exit'], $result['stderr'] );
				$receipt = json_decode( $result['stdout'], true, 16, JSON_THROW_ON_ERROR );
				expect( $receipt['root_exited'] && $receipt['descendant_exited'] && $receipt['unowned_process_preserved'] );
			}
		} finally {
			foreach ( glob( "$directory/*" ) as $file ) unlink( $file );
			rmdir( $directory );
		}
	},
];
