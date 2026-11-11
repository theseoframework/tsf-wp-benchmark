<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Reports the selected PHP runtime without bootstrapping WordPress.
 */

if ( PHP_SAPI !== 'cli' ) exit( 1 );

echo json_encode(
	[
		'mysqli'  => extension_loaded( 'mysqli' ),
		'version' => PHP_VERSION,
	],
	JSON_THROW_ON_ERROR,
);
