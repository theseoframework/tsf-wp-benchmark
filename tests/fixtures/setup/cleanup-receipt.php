<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

/**
 * Replaces the cleanup subprocess before any database code can execute.
 *
 * The temporary ini loads this fixture with auto_prepend_file. It emits the
 * test's injected receipt and records whether destructive dispatch was reached.
 */

file_put_contents(
	__DIR__ . '/cleanup-called.txt',
	'called',
);
echo file_get_contents(
	__DIR__ . '/cleanup-response.txt',
);
exit( 0 );
