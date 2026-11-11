#!/usr/bin/env php
<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 3 as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

if ( \PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit;
}

if ( \PHP_VERSION_ID < 80200 || \PHP_INT_SIZE !== 8 ) {
	fwrite(
		\STDERR,
		"Error: The runner requires 64-bit PHP 8.2 or later.\n",
	);
	exit( 1 );
}

// Load dependencies before callers; these files never bootstrap WordPress.
foreach ( [
	'Common',
	'Protocol',
	'Report',
	'PairedReport',
	'Transport',
	'Environment',
	'Telemetry',
	'Supervisor',
	'FixtureRecipe',
	'Fixtures',
	'Evidence',
	'Runner',
	'SupervisorPreflight',
] as $module )
	require_once __DIR__ . '/src/' . $module . '.php';

/**
 * Parses the command's literal argument vector before any operation runs.
 *
 * @param string[] $arguments Command and --name VALUE pairs.
 * @return array{0:string,1:array<string,string>} Command and validated options.
 * @throws \RuntimeException When a command or option is unknown, repeated, or incomplete.
 */
function parse_arguments( array $arguments ): array {

	$command = array_shift( $arguments ) ?? 'help';
	$allowed = [
		'approve'   => [ 'run', 'operator' ],
		'cleanup'   => [ 'run' ],
		'doctor'    => [],
		'export'    => [ 'run', 'out' ],
		'help'      => [],
		'isolation' => [ 'run', 'operator', 'evidence' ],
		'plan'      => [ 'recipe', 'target', 'out' ],
		'preflight' => [ 'run' ],
		'prepare'   => [ 'run' ],
		'report'    => [ 'run', 'out' ],
		'run'       => [ 'run' ],
		'verify'    => [ 'run' ],
	];

	if ( ! isset( $allowed[ $command ] ) )
		throw new \RuntimeException( 'Unknown command: ' . $command );

	$options = [];

	while ( $arguments ) {
		$argument = array_shift( $arguments );

		if ( ! str_starts_with( $argument, '--' ) )
			throw new \RuntimeException( 'Options use --name VALUE.' );

		$key = substr( $argument, 2 );

		if ( ! \in_array( $key, $allowed[ $command ], true ) )
			throw new \RuntimeException(
				'Unknown option for ' . $command . ': ' . $argument,
			);

		if ( \array_key_exists( $key, $options ) )
			throw new \RuntimeException(
				'Repeated option: ' . $argument,
			);

		if (
			   ! $arguments
			|| '' === $arguments[0]
			|| str_starts_with( $arguments[0], '--' )
		) {
			throw new \RuntimeException(
				'Missing value for ' . $argument,
			);
		}

		$options[ $key ] = array_shift( $arguments );
	}

	return [ $command, $options ];
}

/**
 * Dispatches one approved operation and reports failures to stderr.
 *
 * @param string[] $arguments CLI arguments without the executable name.
 * @return int Process exit status.
 * @throws \RuntimeException Internally for invalid commands or state; caught and reported as a failure status.
 */
function main( array $arguments ): int {

	$lock = null;

	try {
		[ $command, $options ] = parse_arguments( $arguments );
		$read_only             = (
			\in_array(
				$command,
				[ 'verify', 'export' ],
				true,
			)
			|| ( 'report' === $command && isset( $options['out'] ) )
		);

		if (
			   isset( $options['run'] )
			&& ! $read_only
		) {
			$run = absolute_path( $options['run'] );

			if ( is_file( "$run/manifest.json" ) )
				throw new \RuntimeException(
					'Public evidence is immutable. Use verify, export, or report with --out NEW_DIRECTORY.',
				);

			$lock = lock_run( $run );
		}

		/**
		 * Reads a required option after command-specific validation.
		 *
		 * @param string $name Required option name.
		 * @return string Operator-supplied option value.
		 * @throws \RuntimeException When the required option was not supplied.
		 */
		$need = function ( string $name ) use ( $options ): string {

			if ( ! isset( $options[ $name ] ) )
				throw new \RuntimeException( "Missing --$name" );

			return $options[ $name ];
		};

		switch ( $command ) {
			case 'doctor':
				$result = discover_environment();
				break;
			case 'plan':
				$result = create_plan(
					read_json( $need( 'recipe' ) ),
					read_json( $need( 'target' ) ),
					$need( 'out' ),
				);
				break;
			case 'approve':
				$result = approve_plan(
					absolute_path( $need( 'run' ) ),
					$need( 'operator' ),
				);
				break;
			case 'prepare':
				$result = prepare_run( absolute_path( $need( 'run' ) ) );
				break;
			case 'isolation':
				$result = confirm_isolation(
					absolute_path( $need( 'run' ) ),
					$need( 'operator' ),
					$need( 'evidence' ),
				);
				break;
			case 'run':
				$result = collect_run( absolute_path( $need( 'run' ) ) );
				break;
			case 'preflight':
				$result = supervisor_preflight( absolute_path( $need( 'run' ) ) );
				break;
			case 'report':
				$result = isset( $options['out'] )
					? export_evidence(
						absolute_path( $need( 'run' ) ),
						$options['out'],
					)
					: report_run( absolute_path( $need( 'run' ) ) );
				break;
			case 'verify':
				$run = absolute_path( $need( 'run' ) );

				if ( is_file( $run . '/manifest.json' ) )
					verify_evidence_manifest( $run );

				$result = report_run( $run, true );
				break;
			case 'export':
				$result = export_evidence(
					absolute_path( $need( 'run' ) ),
					$need( 'out' ),
				);
				break;
			case 'cleanup':
				$run    = absolute_path( $need( 'run' ) );
				$result = cleanup_fixtures(
					approved_plan( $run, false ),
					$run,
				);
				write_json(
					$run . '/cleanup.json',
					$result,
				);
				break;
			case 'help':
				$result = [
					'name'     => 'TSF WordPress Benchmark',
					'version'  => VERSION,
					'commands' => [
						'doctor',
						'plan --recipe FILE --target PRIVATE_JSON --out NEW_DIRECTORY',
						'approve --run DIRECTORY --operator NAME',
						'prepare --run DIRECTORY',
						'isolation --run DIRECTORY --operator NAME --evidence DESCRIPTION',
						'preflight --run DIRECTORY',
						'run --run DIRECTORY',
						'cleanup --run DIRECTORY',
						'report --run DIRECTORY [--out NEW_DIRECTORY]',
						'verify --run DIRECTORY',
						'export --run DIRECTORY --out NEW_DIRECTORY',
					],
					'note'     => 'Read docs/agent-workflow.md. Only record approval after the operator explicitly agrees to the concrete proposal.',
				];
				break;
		}

		echo json_encode(
			$result,
			\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
		) . "\n";

		return 0;
	} catch ( \Throwable $error ) {
		fwrite(
			\STDERR,
			'Error: ' . $error->getMessage() . "\n",
		);

		return 1;
	} finally {
		if ( \is_resource( $lock ) )
			fclose( $lock );
	}
}

// WP-CLI eval-file exposes its positional arguments through $args.
if ( ! \defined( 'TSFB_TESTING' ) )
	exit( main(
		   isset( $args ) && \is_array( $args )
		? $args
		: \array_slice( $argv, 1 ),
	) );
