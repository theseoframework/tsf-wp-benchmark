/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Loads the actual macOS parser and shared checks with built-in JXA.
 *
 * The PHP test replaces the two placeholder identifiers with JSON-encoded
 * strings before execution. No sampler or WordPress site is started here.
 */
ObjC.import( 'Foundation' );

eval(
	ObjC.unwrap(
		$.NSString.stringWithContentsOfFileEncodingError(
			__TSFB_SCRIPT_JSON__,
			$.NSUTF8StringEncoding,
			null,
		),
	) + __TSFB_CHECKS_JSON__,
);
