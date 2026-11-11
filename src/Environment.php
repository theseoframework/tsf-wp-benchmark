<?php
/**
 * Read-only discovery and assessment of the actual HTTP runtime.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 */

/**
 * Finds an executable without running it or invoking a shell.
 *
 * @param string $name Bare command name.
 * @return string|null Resolved executable path, or null when unavailable.
 */
function find_executable( string $name ): ?string {

	if ( ! preg_match( '/\A[a-zA-Z0-9_.-]+\z/', $name ) )
		return null;

	$extensions = \PHP_OS_FAMILY === 'Windows' ? [ '', '.exe', '.cmd', '.bat' ] : [ '' ];

	foreach ( explode(
		\PATH_SEPARATOR,
		getenv( 'PATH' ) ?: '',
	) as $directory ) {
		$directory = trim( $directory, " \t\n\r\0\x0B\"" );

		if ( '' === $directory ) continue;

		foreach ( $extensions as $extension ) {
			$path = $directory . \DIRECTORY_SEPARATOR . $name . $extension;

			if (
				   is_file( $path )
				&& ( \PHP_OS_FAMILY === 'Windows' || is_executable( $path ) )
			) {
				return realpath( $path ) ?: $path;
			}
		}
	}

	return null;
}

/**
 * Discovers installed capabilities without starting Local or inspecting sites.
 *
 * Paths belong to the private doctor receipt, not a public report.
 *
 * @return array Operating system, CLI capabilities, command paths, and issues.
 */
function discover_environment(): array {

	$extensions = [];

	foreach ( [ 'curl', 'dom', 'hash', 'json', 'mysqli', 'zip' ] as $name )
		$extensions[ $name ] = \extension_loaded( $name );

	$commands = [];

	foreach ( [ 'localwp', 'wp', 'php' ] as $name )
		$commands[ $name ] = find_executable( $name );

	$local_candidates = \PHP_OS_FAMILY === 'Windows'
		? [
			getenv( 'ProgramFiles' ) . '/Local/Local.exe',
			getenv( 'LOCALAPPDATA' ) . '/Programs/Local/Local.exe',
		]
		: [ '/Applications/Local.app' ];
	$local            = null;

	foreach ( $local_candidates as $candidate ) {
		if ( file_exists( $candidate ) ) {
			$local = realpath( $candidate ) ?: $candidate;
			break;
		}
	}

	$issues = [];

	if (
		   \PHP_VERSION_ID < 80200
		|| \PHP_INT_SIZE !== 8
		|| false === hrtime( true )
	) {
		$issues[] = 'The runner needs 64-bit PHP 8.2+ and a working monotonic clock.';
	}

	foreach ( $extensions as $name => $present ) {
		if ( ! $present )
			$issues[] = "CLI PHP extension missing: $name. Another installed PHP runtime may provide it.";
	}

	if ( ! \function_exists( 'proc_open' ) )
		$issues[] = 'proc_open is unavailable; fixture processes and native telemetry cannot run.';

	return [
		'os'                    => \PHP_OS_FAMILY,
		'architecture'          => php_uname( 'm' ),
		'cli_php'               => [
			'version' => \PHP_VERSION,
			'binary'  => \PHP_BINARY,
			'bits'    => \PHP_INT_SIZE * 8,
		],
		'extensions'            => $extensions,
		'commands'              => $commands,
		'local_application'     => $local,
		'issues'                => $issues,
		'http_runtime_verified' => false,
		'notice'                => 'Discovery did not start services, inspect development sites, or change configuration. Approve a dedicated target before setup.',
	];
}

/**
 * Interprets an INI Boolean without treating the string "off" as true.
 *
 * @param mixed $value Value returned by an INI getter or runtime receipt.
 * @return bool Whether the value explicitly enables the setting.
 */
function ini_enabled( mixed $value ): bool {
	return \in_array(
		strtolower( trim( (string) $value ) ),
		[ '1', 'on', 'yes', 'true' ],
		true,
	);
}

/**
 * Assesses the HTTP worker; CLI settings cannot establish server readiness.
 *
 * @param array $runtime HTTP probe receipt, including effective Xdebug modes,
 *                       WordPress debug flags, and shared OPcache status.
 * @return array Conditions with severity, and reference_ready Boolean.
 */
function assess_runtime( array $runtime ): array {

	$conditions = [];

	/**
	 * Appends an explicit qualification without silently changing the environment.
	 *
	 * @param string $code     Stable condition identifier.
	 * @param string $severity Blocking, reference, or limitation.
	 * @param string $message  Human-readable reason.
	 * @return void
	 */
	$add = function ( string $code, string $severity, string $message ) use ( &$conditions ): void {
		$conditions[] = [
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		];
	};

	$sapi = $runtime['sapi'] ?? null;

	if ( null === $sapi || 'cli' === $sapi ) {
		$add(
			'http_runtime_unverified',
			'blocking',
			'Probe the HTTP worker, not CLI PHP.',
		);
	} elseif ( 'cli-server' === $sapi ) {
		$add(
			'development_server',
			'reference',
			'PHPâ€™s development server is for plumbing checks, not the native reference environment.',
		);
	}

	if ( empty( $runtime['php_version'] ) )
		$add(
			'php_version_unverified',
			'blocking',
			'The HTTP PHP version was not recorded.',
		);

	$debug      = $runtime['wordpress_debug'] ?? [];
	$unverified = [];

	foreach ( [ 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG', 'SAVEQUERIES' ] as $flag ) {
		if ( ! \is_bool( $debug[ $flag ] ?? null ) )
			$unverified[] = $flag;
	}

	if ( $unverified ) {
		$add(
			'wordpress_debug_unverified',
			'reference',
			'HTTP WordPress debug flags were not verified: ' . implode( ', ', $unverified ) . '.',
		);
	}

	if ( true === ( $debug['WP_DEBUG'] ?? null ) ) {
		$add(
			'wordpress_debug_enabled',
			'reference',
			'WP_DEBUG is enabled and can change core or plugin behavior. Reference measurements use WP_DEBUG=false.',
		);
	}

	if ( true === ( $debug['SCRIPT_DEBUG'] ?? null ) ) {
		$add(
			'wordpress_script_debug_enabled',
			'reference',
			'SCRIPT_DEBUG is enabled; the reference uses production asset behavior.',
		);
	}

	if ( true === ( $debug['SAVEQUERIES'] ?? null ) ) {
		$add(
			'wordpress_query_recording_enabled',
			'reference',
			'SAVEQUERIES records database calls and adds instrumentation overhead.',
		);
	}

	$extensions = array_map(
		'strtolower',
		$runtime['extensions'] ?? [],
	);
	$xdebug     = $runtime['xdebug'] ?? [];

	// Either source establishes that the extension is loaded.
	if ( ! empty( $xdebug['loaded'] ) || \in_array( 'xdebug', $extensions, true ) ) {
		$modes = $xdebug['effective_modes'] ?? $runtime['effectiveXdebugModes'] ?? null;

		if ( ! \is_array( $modes ) ) {
			$add(
				'xdebug_mode_unverified',
				'reference',
				'Xdebug is loaded but effective modes were not verified. XDEBUG_MODE can override php.ini.',
			);
		} else {
			$active = [];

			foreach ( $modes as $mode ) {
				if (
					   ! \is_string( $mode )
					|| ! \in_array(
						strtolower( $mode ),
						[ '', 'off' ],
						true,
					)
				) {
					$active[] = \is_string( $mode ) ? $mode : 'unverified';
				}
			}

			if ( $active ) {
				$add(
					'xdebug_active',
					'reference',
					'Xdebug features are enabled or unverified: ' . implode( ', ', $active ) . '. Timings may include instrumentation overhead.',
				);
			} else {
				$add(
					'xdebug_loaded_off',
					'limitation',
					'Xdebug is loaded with all features off; its presence is recorded.',
				);
			}
		}
	}

	foreach ( [ 'blackfire', 'tideways', 'tideways_xhprof', 'xhprof' ] as $extension ) {
		if ( \in_array( $extension, $extensions, true ) ) {
			$add(
				'other_instrumentation',
				'reference',
				"The $extension extension is loaded; its effects require an explicitly instrumented custom experiment.",
			);
		}
	}

	$opcache   = $runtime['opcache'] ?? [];
	$ini       = $opcache['ini'] ?? [];
	$status    = $opcache['status'] ?? false;
	$file_only = ini_enabled(
		$ini['opcache.file_cache_only'] ?? false,
	);

	if ( $file_only )
		$add(
			'opcache_file_only',
			'reference',
			'OPcache is configured for disk-only caching instead of shared memory.',
		);

	if ( ! \is_array( $status ) || empty( $status['opcache_enabled'] ) ) {
		$add(
			'opcache_shared_unverified',
			'reference',
			'Working shared-memory OPcache was not established in this HTTP worker; it may be disabled, restricted, or using file-cache fallback.',
		);
	} else {
		if (
			   ! \array_key_exists( 'cache_full', $status )
			|| ! isset( $status['opcache_statistics'] )
		) {
			$add(
				'opcache_status_incomplete',
				'reference',
				'OPcache status is incomplete; capacity and continuity cannot be verified.',
			);
		}

		if ( ! empty( $status['cache_full'] ) )
			$add(
				'opcache_full',
				'reference',
				'The shared-memory OPcache is full.',
			);

		if (
			   ! empty( $status['restart_pending'] )
			|| ! empty( $status['restart_in_progress'] )
		) {
			$add(
				'opcache_restart_pending',
				'reference',
				'An OPcache restart is pending or in progress.',
			);
		}

		$before = $opcache['before']['opcache_statistics'] ?? null;

		if ( \is_array( $before ) ) {
			$after = $status['opcache_statistics'] ?? [];

			foreach ( [ 'start_time', 'oom_restarts', 'hash_restarts', 'manual_restarts' ] as $key ) {
				if (
					! \array_key_exists( $key, $before )
					|| ! \array_key_exists( $key, $after )
					|| $before[ $key ] !== $after[ $key ]
				) {
					$add(
						'opcache_continuity_changed',
						'reference',
						"OPcache continuity changed or could not be verified ($key).",
					);
					break;
				}
			}
		}
	}

	$file_cache = $ini['opcache.file_cache'] ?? '';

	// ini_get() returns false for an unavailable directive.
	if ( \is_string( $file_cache ) && '' !== $file_cache && ! $file_only )
		$add(
			'opcache_second_level',
			'limitation',
			'A disk cache supplements OPcache. This setting alone does not disable shared-memory caching.',
		);

	if ( ! empty( $status['jit']['on'] ) ) {
		$add(
			'jit_enabled',
			'reference',
			'JIT is enabled; declare a custom environment or approve matching the reference settings.',
		);
	}

	$ready = true;

	foreach ( $conditions as $condition ) {
		if ( 'limitation' !== $condition['severity'] )
			$ready = false;
	}

	return [
		'conditions'      => $conditions,
		'reference_ready' => $ready,
	];
}
