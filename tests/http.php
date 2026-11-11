<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Synthetic loopback transport regression; no WordPress or benchmark measurement.
 */

return [
	/**
	 * Checks pinned loopback resolution, fresh markers, elapsed time, and redirects.
	 *
	 * @return void
	 */
	'loopback HTTP timing, literal hosts, fresh markers, and redirects' => function (): void {

		$socket = stream_socket_server(
			'tcp://127.0.0.1:0',
			$errno,
			$error,
		);

		if ( ! $socket )
			throw new RuntimeException(
				'Cannot allocate synthetic test port.',
			);

		$address = stream_socket_get_name( $socket, false );
		fclose( $socket );
		$port    = (int) substr(
			$address,
			strrpos( $address, ':' ) + 1,
		);
		$log     = tmpfile();
		$process = proc_open(
			[ PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/router.php' ],
			[
				0 => [ 'pipe', 'r' ],
				1 => $log,
				2 => $log,
			],
			$pipes,
			__DIR__,
			null,
			[ 'bypass_shell' => true ],
		);

		if ( ! is_resource( $process ) )
			throw new RuntimeException(
				'Cannot start synthetic HTTP fixture.',
			);

		fclose( $pipes[0] );

		try {
			$ready = false;

			// Poll startup readiness at most fifty times.
			for ( $i = 0; 50 > $i; $i++ ) {
				$s = @stream_socket_client(
					'tcp://' . $address,
					$e,
					$m,
					0.1,
				);

				if ( $s ) {
					fclose( $s );
					$ready = true;
					break;
				}

				usleep( 20000 );
			}

			expect(
				$ready,
				'Synthetic PHP server did not start.',
			);

			foreach ( [ 'benchmark.invalid', '192.0.2.123' ] as $host ) {
				$r = \TsfBenchmark\request(
					'http://' . $host . ':' . $port . '/slow',
					'synthetic-test-token',
				);

				expect(
					200 === $r['status'] && $r['headers']['x-tsf-request'] === $r['nonce'],
				);
				expect(
					20 <= $r['elapsed_ms'],
					'HTTP interval omitted server work.',
				);
			}

			$r = \TsfBenchmark\request(
				'http://benchmark.invalid:' . $port . '/redirect',
				'synthetic-test-token',
			);
			expect(
				302 === $r['status'],
				'Unexpected redirect following.',
			);

			foreach ( [ "\r\nX-Injected: yes", "\0", "\t" ] as $control ) {
				$rejected = false;

				try {
					\TsfBenchmark\request(
						'http://benchmark.invalid:' . $port . '/slow',
						'synthetic-test-token' . $control,
					);
				} catch ( RuntimeException $exception ) {
					$rejected = str_contains(
						$exception->getMessage(),
						'must not contain control characters',
					);
				}

				expect(
					$rejected,
					'Reject header controls before sending a private fixture token.',
				);
			}

			$handle = curl_init();

			try {
				curl_setopt_array(
					$handle,
					[
						CURLOPT_PROXY          => 'http://127.0.0.1:1',
						CURLOPT_FOLLOWLOCATION => true,
					],
				);
				$r = \TsfBenchmark\request(
					'http://192.0.2.123:' . $port . '/slow',
					'synthetic-test-token',
					null,
					null,
					$handle,
				);
				expect(
					200 === $r['status'],
					'A reused handle must still clear proxies and connect only to loopback.',
				);
				$r = \TsfBenchmark\request(
					'http://benchmark.invalid:' . $port . '/redirect',
					'synthetic-test-token',
					null,
					null,
					$handle,
				);
				expect(
					302 === $r['status'],
					'A reused handle must not follow a redirect with fixture credentials.',
				);
			} finally {
				curl_close( $handle );
			}
		} finally {
			proc_terminate( $process );
			proc_close( $process );
			fclose( $log );
		}
	},
];
