<?php
/**
 * Network-policy approval, guard, probe, and private-log regression tests.
 *
 * @package TSF_WP_Benchmark
 */

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Runs the real fixture guard or probe without WordPress or network access.
 *
 * Only the probe's input stream and socket call are replaced in its test-owned
 * copy; the branch decisions and production WordPress guard remain unchanged.
 *
 * @param string $directory Owned test directory.
 * @param string $action Guard checks, isolation, or guards.
 * @param string $mode Frozen policy mode.
 * @param string $scenario Optional later plugin-filter override.
 * @return array Child process result, private log path, and socket count.
 */
function network_fixture( string $directory, string $action, string $mode = 'wordpress-http', string $scenario = '' ): array {

	$root = dirname( __DIR__ );
	$log  = "$directory/network.private.ndjson";
	TsfBenchmark\write_bytes( $log, '' );
	copy( __DIR__ . '/fixtures/network/wp-load.php', "$directory/wp-load.php" );
	if ( 'check' === $action ) {
		$source = file_get_contents( __DIR__ . '/fixtures/network/guard-checks.php' );
	} else {
		TsfBenchmark\write_json( "$directory/input.json", [ 'action' => $action ] );
		$source = str_replace(
			[ "file_get_contents( 'php://input' )", 'stream_socket_client(' ],
			[ "file_get_contents( __DIR__ . '/input.json' )", 'tsfb_test_socket(' ],
			file_get_contents( "$root/scripts/probe.php" ),
		);
	}
	TsfBenchmark\write_bytes( "$directory/subject.php", $source );
	$result            = TsfBenchmark\execute( [ PHP_BINARY, '-n', "$directory/subject.php", "$root/scripts/guard.php", $log, $mode, $scenario ] );
	$result['log']     = $log;
	$result['sockets'] = is_file( "$log.sockets" ) ? (int) file_get_contents( "$log.sockets" ) : 0;
	return $result;
}

return [
	/** @return void */
	'planning freezes the default network policy and approval rejects later changes' => function (): void {
		$directory = runner_temp();
		try {
			TsfBenchmark\make_directory( "$directory/web" );
			$target  = [
				'document_root' => "$directory/web",
				'url'           => 'http://127.0.0.1',
				'php'           => PHP_BINARY,
				'database'      => [
					'host'     => '127.0.0.1',
					'name'     => 'synthetic',
					'user'     => 'test',
					'password' => '',
				],
			];
			$summary = TsfBenchmark\create_plan( runner_recipe(), $target, "$directory/run" );
			expect( 'wordpress-http' === $summary['isolation']['mode'] );
			TsfBenchmark\approve_plan( "$directory/run", 'synthetic test only' );
			$plan = TsfBenchmark\approved_plan( "$directory/run" );
			expect( false === TsfBenchmark\run_network_policy( $plan, "$directory/run" )['external_block_verified'] );
			$plan['target']['isolation']['mode'] = 'offline';
			TsfBenchmark\write_json( "$directory/run/plan.json", $plan );
			runner_throws( fn() => TsfBenchmark\approved_plan( "$directory/run" ) );
			$target['isolation'] = [
				'mode'        => 'unknown',
				'description' => 'Unknown network policy must fail.',
			];
			runner_throws( fn() => TsfBenchmark\create_plan( runner_recipe(), $target, "$directory/rejected" ) );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/** @return void */
	'new plans default to WordPress-only controls without rewriting saved policies' => function (): void {
		$target = TsfBenchmark\normalize_network_policy( [] );
		expect( 'wordpress-http' === $target['isolation']['mode'] );
		foreach ( [ 'offline', 'external-firewall', 'unknown' ] as $mode ) {
			$old = [
				'isolation' => [
					'mode'        => $mode,
					'description' => 'Keep the declared policy.',
				],
			];
			expect( TsfBenchmark\normalize_network_policy( $old ) === $old );
		}
		runner_throws( fn() => TsfBenchmark\run_network_policy( [ 'target' => [] ], '/missing' ) );
	},
	/** @return void */
	'WordPress-only mode needs no external receipt and never claims full isolation' => function (): void {
		$plan  = [ 'target' => TsfBenchmark\normalize_network_policy( [] ) ];
		$scope = TsfBenchmark\run_network_policy( $plan, '/missing' );
		expect( false === $scope['operator_verified'] && false === $scope['external_block_verified'] );
		$network = [
			'mode'                       => 'wordpress-http',
			'wordpress_http_blocked'     => true,
			'wordpress_mail_blocked'     => true,
			'automatic_updates_disabled' => true,
			'cron_disabled'              => true,
			'blocked'                    => null,
		];
		TsfBenchmark\assert_network_policy( $network, $plan['target'] );
		foreach ( [ 'wordpress_http_blocked', 'wordpress_mail_blocked', 'automatic_updates_disabled', 'cron_disabled' ] as $field ) {
			$bad           = $network;
			$bad[ $field ] = false;
			runner_throws( fn() => TsfBenchmark\assert_network_policy( $bad, $plan['target'] ) );
		}
		foreach ( [ 'offline', 'external-firewall', 'unknown' ] as $mode ) {
			$target          = [ 'isolation' => [ 'mode' => $mode ] ];
			$network['mode'] = $mode;
			runner_throws( fn() => TsfBenchmark\assert_network_policy( $network, $target ) );
		}
	},
	/** @return void */
	'external policies retain exact-plan and mode-bound verification' => function (): void {
		$directory = runner_temp();
		try {
			foreach ( [ 'offline', 'external-firewall' ] as $mode ) {
				$plan = [ 'target' => [ 'isolation' => [ 'mode' => $mode ] ] ];
				TsfBenchmark\write_json( "$directory/isolation.private.json",
					[
						'plan_sha256' => TsfBenchmark\digest( $plan ),
						'operator'    => 'synthetic test',
						'mode'        => $mode,
					] );
				expect( TsfBenchmark\run_network_policy( $plan, $directory )['external_block_verified'] );
				$plan['target']['isolation']['mode'] = 'offline' === $mode ? 'external-firewall' : 'offline';
				runner_throws( fn() => TsfBenchmark\run_network_policy( $plan, $directory ) );
			}
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/** @return void */
	'MU guard blocks HTTP mail and all automatic updates without retaining secrets' => function (): void {
		$directory = runner_temp();
		try {
			$result = network_fixture( $directory, 'check' );
			expect( 0 === $result['exit'], $result['stderr'] );
			$data = json_decode( $result['stdout'], true, 512, JSON_THROW_ON_ERROR );
			expect( 'benchmark_offline' === $data['http'] && false === $data['mail'] );
			expect( [
				'disabled'    => true,
				'core'        => false,
				'plugin'      => false,
				'theme'       => false,
				'translation' => false,
			] === $data['updates'] );
			$log = file_get_contents( $result['log'] );
			expect( ! str_contains( $log, 'PRIVATE_' ), 'Private request secrets leaked into retained metadata.' );
			$rows = array_map( fn( $line ) => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ), explode( "\n", trim( $log ) ) );
			expect( 2 === count( $rows ) && 'http' === $rows[0]['kind'] && 'mail' === $rows[1]['kind'] );
			expect( 'example.invalid' === $rows[0]['host'] && '/path' === $rows[0]['path'] );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/** @return void */
	'blocked-call log failures fail visibly rather than claiming complete retention' => function (): void {
		$directory = runner_temp();
		try {
			$result = network_fixture( $directory, 'check', 'wordpress-http', 'missing-log' );
			expect( 0 !== $result['exit'] && str_contains( $result['stdout'] . $result['stderr'], 'Private blocked-call log is unavailable.' ) );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/** @return void */
	'WordPress-only probes never open raw sockets while external modes retain canaries' => function (): void {
		$directory = runner_temp();
		try {
			foreach ( [ 'wordpress-http', 'offline', 'external-firewall' ] as $mode ) {
				$child = "$directory/$mode";
				TsfBenchmark\make_directory( $child );
				$result = network_fixture( $child, 'isolation', $mode );
				expect( 0 === $result['exit'], $result['stderr'] );
				$response = json_decode( $result['stdout'], true, 512, JSON_THROW_ON_ERROR );
				expect( true === $response['ok'] );
				TsfBenchmark\assert_network_policy( $response['data'], [ 'isolation' => [ 'mode' => $mode ] ] );
				expect( ( 'wordpress-http' === $mode ? 0 : 2 ) === $result['sockets'] );
				expect( 'wordpress-http' !== $mode || null === $response['data']['blocked'] );
			}
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/** @return void */
	'post-activation probes expose later filters that reopen WordPress traffic' => function (): void {
		$directory = runner_temp();
		try {
			$result = network_fixture( $directory, 'guards', 'wordpress-http', 'override' );
			expect( 0 === $result['exit'], $result['stderr'] );
			$response = json_decode( $result['stdout'], true, 512, JSON_THROW_ON_ERROR );
			expect( false === $response['data']['wordpress_http_blocked'] && false === $response['data']['wordpress_mail_blocked'] );
			runner_throws( fn() => TsfBenchmark\assert_network_policy( $response['data'], [ 'isolation' => [ 'mode' => 'wordpress-http' ] ] ) );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/** @return void */
	'probes reject effective update or cron controls being reopened' => function (): void {
		$directory = runner_temp();
		try {
			foreach ( [
				'updates-enabled' => 'automatic_updates_disabled',
				'cron-enabled'    => 'cron_disabled',
			] as $scenario => $field ) {
				$child = "$directory/$scenario";
				TsfBenchmark\make_directory( $child );
				$result = network_fixture( $child, 'guards', 'wordpress-http', $scenario );
				expect( 0 === $result['exit'], $result['stderr'] );
				$response = json_decode( $result['stdout'], true, 512, JSON_THROW_ON_ERROR );
				expect( false === $response['data'][ $field ] );
				runner_throws( fn() => TsfBenchmark\assert_network_policy( $response['data'], [ 'isolation' => [ 'mode' => 'wordpress-http' ] ] ) );
			}
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
	/** @return void */
	'guard probes preserve WordPress filter argument contracts' => function (): void {
		$directory = runner_temp();
		try {
			$result = network_fixture( $directory, 'guards', 'wordpress-http', 'contracts' );
			expect( 0 === $result['exit'], $result['stderr'] );
			$response = json_decode( $result['stdout'], true, 512, JSON_THROW_ON_ERROR );
			TsfBenchmark\assert_network_policy( $response['data'], [ 'isolation' => [ 'mode' => 'wordpress-http' ] ] );
		} finally {
			TsfBenchmark\remove_owned_tree( $directory, dirname( $directory ) );
		}
	},
];
