/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Loads the actual macOS parser before the PHP test appends its shared checks.
 *
 * Invoked with node -e and the parser path as the first positional argument.
 * No macOS process or sensor is started in this execution path.
 */
const fs = require( 'fs' );
const vm = require( 'vm' );

vm.runInThisContext(
	fs.readFileSync(
		process.argv[1],
		'utf8',
	),
);
