/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Collects host counters using built-in macOS JXA and one persistent top process.
 * Run with osascript; no compiler, package, or elevated permission is required.
 */
if ( typeof ObjC !== 'undefined' )
	ObjC.import( 'Foundation' );

/**
 * Creates one typed reading with explicit provenance and availability.
 *
 * @param {*}      value  Reading or null when unavailable.
 * @param {string} unit   Measurement unit.
 * @param {string} source Native provider.
 * @param {string} status Optional availability reason.
 * @return {Object} Metric receipt.
 */
function metric( value, unit, source, status ) {
	return {
		value,
		unit,
		source,
		status: status || ( value === null ? 'unsupported' : 'available' ),
	};
}

/**
 * Converts top's binary memory units to bytes.
 *
 * @param {string} value Numeric reading.
 * @param {string} unit  K, M, G, or T.
 * @return {number} Byte count.
 */
function bytes( value, unit ) {
	return Number( value ) * Math.pow( 1024, 'KMGT'.indexOf( unit.toUpperCase() ) + 1 );
}

/**
 * Assembles top blocks without assigning the next sample's time to the prior one.
 *
 * @param {Object}  state       Mutable pending text and observation metadata.
 * @param {string}  chunk       Text received from the existing top process.
 * @param {Object}  observation Host state when this chunk was received.
 * @param {boolean} flush       Whether to emit the final incomplete block.
 * @return {Object[]} Complete blocks with their original observation metadata.
 */
function collectTopBlocks( state, chunk, observation, flush ) {

	if ( ! state.pending )
		state.observation = observation;

	state.pending += chunk;

	const first = state.pending.indexOf( 'Processes:' );
	const blocks = [];

	if ( first === -1 )
		return blocks;

	if ( first > 0 )
		state.pending = state.pending.slice( first );

	let next = state.pending.indexOf( 'Processes:', 10 );

	while ( next !== -1 ) {
		blocks.push( {
			text: state.pending.slice( 0, next ),
			observation: state.observation,
		} );
		state.pending = state.pending.slice( next );
		state.observation = observation;
		next = state.pending.indexOf( 'Processes:', 10 );
	}

	if ( flush && state.pending.indexOf( 'CPU usage:' ) !== -1 ) {
		blocks.push( {
			text: state.pending,
			observation: state.observation,
		} );
		state.pending = '';
	}

	return blocks;
}

/**
 * Parses one top sample; the first CPU/swap interval is intentionally unavailable.
 *
 * @param {string} block       One top header block.
 * @param {Object} observation Foundation readings and arrival time.
 * @param {number} index       Zero-based top sample number.
 * @return {Object} Timestamped host telemetry row.
 */
function parseTopSample( block, observation, index ) {

	const metrics = {};
	const cpu = block.match( /CPU usage:\s*([\d.]+)% user,\s*([\d.]+)% sys,\s*([\d.]+)% idle/ );
	const memory = block.match( /PhysMem:\s*([\d.]+)([KMGT]) used[^\n]*?([\d.]+)([KMGT]) unused/ );
	const swap = block.match( /(\d+)\((\d+)\) swapins,\s*(\d+)\((\d+)\) swapouts/ );
	const idle = cpu ? Number( cpu[3] ) : NaN;
	const validCpu = Number.isFinite( idle ) && idle >= 0 && idle <= 100;

	metrics.cpu_busy = metric(
		validCpu && index > 0 ? 100 - idle : null,
		'percent_of_host_capacity',
		'macOS top',
		index === 0 ? 'warming_up' : validCpu ? 'available' : 'parse_unavailable',
	);
	metrics.available_memory = metric(
		memory ? bytes( memory[3], memory[4] ) : null,
		'bytes',
		'macOS top',
		memory ? 'available' : 'parse_unavailable',
	);
	metrics.total_memory = metric(
		observation.totalMemory,
		'bytes',
		'NSProcessInfo',
	);
	metrics.swapins = metric(
		swap && index > 0 ? Number( swap[2] ) : null,
		'pages_in_top_interval',
		'macOS top',
		index === 0 ? 'warming_up' : swap ? 'available' : 'parse_unavailable',
	);
	metrics.swapouts = metric(
		swap && index > 0 ? Number( swap[4] ) : null,
		'pages_in_top_interval',
		'macOS top',
		index === 0 ? 'warming_up' : swap ? 'available' : 'parse_unavailable',
	);
	metrics.thermal_pressure = metric(
		observation.thermalState,
		'state',
		'NSProcessInfo.thermalState',
		observation.thermalState ? 'os_reported_sensor_coverage_not_guaranteed' : 'unsupported',
	);
	metrics.low_power_mode = metric(
		observation.lowPowerMode,
		'boolean',
		'NSProcessInfo',
	);
	metrics.cpu_temperature = metric(
		null,
		'celsius',
		'No approved CPU-specific sensor provider',
	);
	metrics.effective_clock = metric(
		null,
		'hertz',
		'No approved effective-clock provider',
	);

	return {
		schema: 1,
		timestamp: observation.timestamp,
		timestamp_basis: 'top_block_first_received',
		monotonic_seconds: observation.elapsed,
		host_scope: 'collector_host',
		logical_processors: observation.logicalProcessors,
		metrics,
	};
}

/**
 * Captures Foundation readings at the same observation time as the top block.
 *
 * @param {Object} info  NSProcessInfo instance.
 * @param {number} start Initial monotonic uptime.
 * @return {Object} Host state, including explicit nulls for unavailable providers.
 */
function observeHost( info, start ) {

	let thermalState = null;
	let lowPowerMode = null;

	try {
		const state = Number( info.thermalState );
		thermalState = [ 'nominal', 'fair', 'serious', 'critical' ][state] || null;
	} catch ( error ) {
		// Unsupported Foundation properties remain unavailable.
	}

	try {
		const lowPower = info.isLowPowerModeEnabled;

		if ( typeof lowPower === 'boolean' || typeof lowPower === 'number' )
			lowPowerMode = Boolean( lowPower );
	} catch ( error ) {
		// Older macOS releases may not expose Low Power Mode.
	}

	return {
		timestamp: new Date().toISOString(),
		elapsed: Number( info.systemUptime ) - start,
		logicalProcessors: Number( info.processorCount ),
		totalMemory: Number( info.physicalMemory ),
		thermalState,
		lowPowerMode,
	};
}

/**
 * Runs the native sampler until its run-owned stop marker appears.
 *
 * @param {string[]} argv Existing output path, stop path, and interval in seconds.
 * @return {void}
 */
function run( argv ) {

	if ( argv.length !== 3 )
		throw new Error( 'Expected output path, stop path, interval.' );

	const output = String( argv[0] );
	const stop = String( argv[1] );
	const interval = Number( argv[2] );

	if ( ! Number.isInteger( interval ) || interval < 1 || interval > 60 )
		throw new Error( 'Invalid sampling interval.' );

	const file = $.NSFileHandle.fileHandleForWritingAtPath( output );

	if ( ! file )
		throw new Error( 'Telemetry output must already exist.' );

	file.seekToEndOfFile;

	const info = $.NSProcessInfo.processInfo;
	const top = $.NSTask.alloc.init;
	const pipe = $.NSPipe.pipe;
	const state = { pending: '', observation: null };
	const start = Number( info.systemUptime );
	let count = 0;

	top.executableURL = $.NSURL.fileURLWithPath( '/usr/bin/top' );
	top.arguments = $( [ '-l', '0', '-s', String( interval ), '-n', '0' ] );
	top.standardOutput = pipe;
	top.standardError = $.NSFileHandle.fileHandleWithNullDevice;

	try {
		const error = Ref();

		if ( ! top.launchAndReturnError( error ) )
			throw new Error( 'The built-in top collector could not start.' );

		while ( ! $.NSFileManager.defaultManager.fileExistsAtPath( stop ) ) {
			const data = pipe.fileHandleForReading.availableData;

			if ( Number( data.length ) === 0 ) break;

			const chunk = ObjC.unwrap(
				$.NSString.alloc.initWithDataEncoding( data, $.NSUTF8StringEncoding ),
			);
			const blocks = collectTopBlocks(
				state,
				chunk,
				observeHost( info, start ),
				false,
			);

			for ( const block of blocks ) {
				const row = parseTopSample( block.text, block.observation, count++ );
				file.writeData(
					$( JSON.stringify( row ) + '\n' ).dataUsingEncoding( $.NSUTF8StringEncoding ),
				);
			}
		}

		const blocks = collectTopBlocks(
			state,
			'',
			observeHost( info, start ),
			true,
		);

		for ( const block of blocks ) {
			const row = parseTopSample( block.text, block.observation, count++ );
			file.writeData(
				$( JSON.stringify( row ) + '\n' ).dataUsingEncoding( $.NSUTF8StringEncoding ),
			);
		}
	} finally {
		if ( top.running )
			top.terminate;

		file.closeFile;
	}
}
