/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Exercises the actual Mac telemetry parsers with deterministic native output.
 *
 * The PHP test appends these checks to either the Node or JXA loader. All
 * functions exercised here come from scripts/telemetry-macos.js.
 */
const first = {
	timestamp:         '2026-11-11T00:00:00Z',
	elapsed:           0,
	totalMemory:       17179869184, // Sixteen GiB.
	logicalProcessors: 10,
	thermalState:      'nominal',
	lowPowerMode:      false,
};
const second = {
	...first,
	timestamp: '2026-11-11T00:00:02Z',
	elapsed:   2,
};
const state = { pending: '', observation: null };
const header = 'Processes: 300 total\n'
	+ 'CPU usage: 3.0% user, 2.0% sys, 95.0% idle\n'
	+ 'PhysMem: 5G used (1G wired), 11G unused.\n'
	+ 'VM: 2(1) swapins, 4(3) swapouts.\n';

// Split within the CPU line so the parser must retain an incomplete block.
if ( collectTopBlocks(
	state,
	header.slice( 0, 45 ),
	first,
	false,
).length !== 0 ) {
	throw new Error(
		'Partial block emitted prematurely.',
	);
}

collectTopBlocks(
	state,
	header.slice( 45 ),
	first,
	false,
);
const blocks = collectTopBlocks(
	state,
	header,
	second,
	false,
);

if (
	   blocks.length !== 1
	|| blocks[0].observation.timestamp !== first.timestamp
) {
	throw new Error(
		'A delayed block acquired the next sample timestamp.',
	);
}

const initial = parseTopSample(
	blocks[0].text,
	blocks[0].observation,
	0,
);

if ( initial.metrics.cpu_busy.value !== null )
	throw new Error(
		'First cumulative top sample is not a measured interval.',
	);

const final = collectTopBlocks(
	state,
	'',
	second,
	true,
);
const row = parseTopSample(
	final[0].text,
	final[0].observation,
	1,
);

// Eleven unused GiB must be reported in bytes.
if (
	   row.timestamp !== second.timestamp
	|| row.metrics.cpu_busy.value !== 5
	|| row.metrics.available_memory.value !== 11811160064
) {
	throw new Error(
		'Top counters, binary units, or observation timestamp changed.',
	);
}

if (
	   row.metrics.swapins.value !== 1
	|| row.metrics.swapouts.value !== 3
	|| row.metrics.cpu_temperature.value !== null
) {
	throw new Error(
		'Swap intervals or unavailable temperature changed.',
	);
}

const malformed = parseTopSample(
	header.replace(
		'95.0% idle',
		'195.0% idle',
	),
	second,
	1,
);

if ( malformed.metrics.cpu_busy.value !== null )
	throw new Error(
		'Impossible CPU percentages must remain unavailable.',
	);

const iostatState = { pending: '', observation: null };
const iostatHeader = '      cpu    load average\n us sy id   1m   5m   15m\n';
const iostatLine = '  2  2 95  1.27 1.28 1.39\n';
const memory = 'Mach Virtual Memory Statistics: (page size of 16384 bytes)\n'
	+ 'Pages free: 275068.\nPages speculative: 45918.\n'
	+ 'Pages inactive: 260816.\nPages purgeable: 9253.\n'
	+ 'Swapins: 169212.\nSwapouts: 328396.\n';

if ( collectIostatRows( iostatState, iostatHeader + iostatLine.slice( 0, 8 ), first, false ).length !== 0 )
	throw new Error( 'Partial iostat rows must not become readings.' );

const iostatRows = collectIostatRows( iostatState, iostatLine.slice( 8 ), second, false );

if ( iostatRows.length !== 1 || iostatRows[0].observation.timestamp !== first.timestamp )
	throw new Error( 'A partial iostat row acquired its completion timestamp.' );

const iostatInitial = parseIostatSample( iostatLine, memory, first, 0 );
const iostatMeasured = parseIostatSample( iostatLine, memory, second, 1 );

if (
	   iostatInitial.metrics.cpu_busy.value !== null
	|| iostatMeasured.metrics.cpu_busy.value !== 5
	|| iostatMeasured.metrics.cpu_busy.source !== 'macOS iostat'
	|| iostatMeasured.metrics.available_memory.value !== null
	|| iostatMeasured.metrics.free_and_speculative_memory.value !== 5259034624
	|| iostatMeasured.metrics.swapins.value !== null
	|| iostatMeasured.metrics.swapouts.value !== null
	|| iostatMeasured.metrics.thermal_pressure.value !== second.thermalState
) {
	throw new Error( 'Fallback providers lost warmup, memory semantics, or provenance.' );
}

if ( parseIostatSample( '2 2 195 1.27 1.28 1.39', '', second, 1 ).metrics.cpu_busy.value !== null )
	throw new Error( 'Impossible fallback CPU percentages must remain unavailable.' );

if ( parseIostatSample( iostatLine, '', second, 1 ).metrics.free_and_speculative_memory.value !== null )
	throw new Error( 'Missing vm_stat output must not become zero memory.' );
