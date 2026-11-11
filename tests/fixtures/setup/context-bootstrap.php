<?php
/**
 * Captures the setup bootstrap flags before loading any WordPress/plugin code.
 *
 * @package TSF_WP_Benchmark
 */

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

echo json_encode( [
	'admin' => WP_ADMIN,
	'ajax'  => DOING_AJAX,
	'rest'  => defined( 'REST_REQUEST' ) && REST_REQUEST,
] );
exit;
