<?php
/**
 * Native process-tree cleanup fixture; starts no WordPress or web server.
 *
 * @package TSF_WP_Benchmark
 */

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

$process = proc_open(
	[ PHP_BINARY, '-r', 'sleep(30);' ],
	[
		0 => [ 'pipe', 'r' ],
		1 => [ 'file', $argv[1] . '.stdout', 'a' ],
		2 => [ 'file', $argv[1] . '.stderr', 'a' ],
	],
	$pipes,
);
if ( ! is_resource( $process ) ) exit( 1 );

fclose( $pipes[0] );
file_put_contents( $argv[1],
	json_encode( [
		'pid'       => getmypid(),
		'child_pid' => proc_get_status( $process )['pid'],
	],
	JSON_THROW_ON_ERROR ) );
sleep( 30 );
proc_terminate( $process );
proc_close( $process );
