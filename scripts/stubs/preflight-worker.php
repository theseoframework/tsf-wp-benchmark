<?php
/**
 * Owned WordPress preflight headers; never installed in measured plugin fixtures.
 *
 * @package TSF_WP_Benchmark
 */

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

header( 'X-TSFB-Worker: ' . getmypid() );
header( 'X-TSFB-Recycle: ' . getenv( 'PHP_FCGI_MAX_REQUESTS' ) );
header( 'X-TSFB-Memory: ' . memory_get_usage( true ) );
