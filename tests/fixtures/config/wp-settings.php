<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Reports rendered configuration values without loading WordPress.
 */

echo json_encode(
	[
		'password'      => DB_PASSWORD,
		'prefix'        => $table_prefix,
		'debug'         => WP_DEBUG,
		'debug_display' => WP_DEBUG_DISPLAY,
		'error_log'     => ini_get( 'error_log' ),
	],
	JSON_THROW_ON_ERROR,
);
