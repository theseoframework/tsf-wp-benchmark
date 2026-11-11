<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Marks any forbidden WordPress bootstrap during preflight rejection tests.
 */

file_put_contents(
	__DIR__ . '/loaded.txt',
	'loaded',
);
exit( 9 );
