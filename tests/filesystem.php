<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Creates a directory alias without requiring Windows symbolic-link privileges.
 *
 * @param string $link   Disposable link path.
 * @param string $target Existing directory owned by the same regression case.
 * @return void
 * @throws RuntimeException When the platform cannot create the required test alias.
 */
function filesystem_directory_link( string $link, string $target ): void {

	if ( 'Windows' !== PHP_OS_FAMILY ) {
		expect( symlink( $target, $link ) );

		return;
	}

	$shell = \TsfBenchmark\find_executable( 'powershell' );

	if ( null === $shell )
		throw new RuntimeException(
			'Windows junction tests require the native PowerShell executable.',
		);

	$result = \TsfBenchmark\execute(
		[
			$shell,
			'-NoProfile',
			'-NonInteractive',
			'-ExecutionPolicy',
			'Bypass',
			'-File',
			__DIR__ . '/fixtures/filesystem/make-junction.ps1',
			$link,
			$target,
		],
	);
	expect(
		0 === $result['exit'],
		'Cannot create the disposable junction: ' . $result['stderr'],
	);
}

return [
	'filesystem guards refuse linked ancestors before writing locking or unlinking' => function (): void {

		$root  = runner_temp();
		$links = [ "$root/owned/redirect", "$root/unrelated/neighbor" ];

		try {
			foreach ( [ 'owned', 'unrelated', 'kept' ] as $directory )
				\TsfBenchmark\make_directory( "$root/$directory" );

			\TsfBenchmark\write_bytes(
				"$root/unrelated/keep",
				'preserved',
			);
			filesystem_directory_link(
				"$root/unrelated/neighbor",
				"$root/kept",
			);
			filesystem_directory_link(
				"$root/owned/redirect",
				"$root/unrelated",
			);
			runner_throws( fn() => \TsfBenchmark\write_bytes(
				"$root/owned/redirect/keep",
				'changed',
			) );
			runner_throws( fn() => \TsfBenchmark\lock_run(
				"$root/owned/redirect",
			) );
			runner_throws( fn() => \TsfBenchmark\remove_owned_tree(
				"$root/owned/redirect/neighbor",
				"$root/owned",
			) );
			expect(
				'preserved' === file_get_contents( "$root/unrelated/keep" ),
				'A redirected write must preserve the unrelated file.',
			);
			expect(
				is_dir( "$root/unrelated/neighbor" ) && is_dir( "$root/kept" ),
				'Both the unrelated link and its destination must survive.',
			);
			expect(
				! file_exists( "$root/unrelated/.runner.lock" ),
				'A redirected lock must not create an unrelated file.',
			);
		} finally {
			// Remove only the two known link entries; never recurse through an alias.
			foreach ( $links as $link ) {
				\TsfBenchmark\require_contained( $link, $root );

				if ( ! file_exists( $link ) && ! is_link( $link ) ) continue;

				if ( 'Windows' === PHP_OS_FAMILY ) {
					rmdir( $link );
				} else {
					unlink( $link );
				}
			}

			\TsfBenchmark\remove_owned_tree(
				$root,
				dirname( $root ),
			);
		}
	},
	'atomic writes accept ordinary relative paths without following parent traversal' => function (): void {

		$root = runner_temp();
		$cwd  = getcwd();

		try {
			chdir( $root );
			\TsfBenchmark\write_bytes( './relative.txt', 'complete' );
			expect(
				'complete' === file_get_contents( 'relative.txt' ),
			);
			runner_throws( fn() => \TsfBenchmark\write_bytes(
				'../outside.txt',
				'forbidden',
			) );
		} finally {
			chdir( $cwd );
			\TsfBenchmark\remove_owned_tree(
				$root,
				dirname( $root ),
			);
		}
	},
];
