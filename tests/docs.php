<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * @package TSF_WP_Benchmark
 */

return [
	/**
	 * Checks the published budgets map to valid balanced recipes and real counts.
	 *
	 * @return void
	 */
	'workload budgets preserve balanced rounding and distinguish smoke evidence' => function (): void {

		$root = dirname( __DIR__ );
		$base = TsfBenchmark\read_json( "$root/recipes/tsf-5.2.1.json" );
		$text = file_get_contents( "$root/docs/run-budget.md" );
		foreach ( [ [ 10, 1, 1, 1, 18, 2916 ], [ 40, 2, 3, 3, 54, 17496 ], [ 100, 3, 3, 6, 108, 39366 ] ] as $choice ) {
			[ $target, $passes, $warmups, $samples, $actual, $expected ] = $choice;
			$recipe                           = $base;
			$recipe['reference']              = false;
			$recipe['measurement']['passes']  = $passes;
			$recipe['measurement']['warmups'] = $warmups;
			$recipe['measurement']['samples'] = $samples;
			TsfBenchmark\validate_recipe( $recipe );
			$orders = count( TsfBenchmark\balanced_orders( array_column( $recipe['arms'], 'id' ) ) );
			expect( $orders * $samples === $actual && $actual >= $target );
			expect( $passes * count( $recipe['routes'] ) * count( $recipe['arms'] ) * $orders * ( $warmups + $samples ) === $expected );
			expect( str_contains( $text, number_format( $expected ) ) );
		}
		expect( str_contains( $text, 'No iteration count guarantees accuracy.' ) );
		expect( str_contains( $text, 'All currently implemented workloads are frontend HTML.' ) );
	},
	/**
	 * Checks the public usage snapshot neither double-counts subsets nor leaks IDs.
	 *
	 * @return void
	 */
	'agent usage aggregates keep cache and reasoning within their parent totals' => function (): void {

		$root   = dirname( __DIR__ );
		$record = TsfBenchmark\read_json( "$root/docs/agent-usage-2026-11-11.json" );
		foreach ( $record['scopes'] as $scope ) {
			$usage = $scope['usage'];
			expect( $usage['input_tokens'] + $usage['output_tokens'] === $usage['total_tokens'] );
			expect( $scope['uncached_input_tokens'] + $usage['cached_input_tokens'] + $usage['cache_write_input_tokens'] === $usage['input_tokens'] );
			expect( $usage['reasoning_output_tokens'] <= $usage['output_tokens'] );
			expect( $scope['start'] < $scope['end_exclusive'] );
			foreach ( $scope['pricing_band_token_totals'] as $bands ) {
				foreach ( [ 'input_tokens', 'cached_input_tokens', 'cache_write_input_tokens', 'output_tokens', 'reasoning_output_tokens' ] as $key )
					expect( $bands['short'][ $key ] + $bands['long'][ $key ] === $usage[ $key ] );

				expect( $bands['short']['model_requests'] + $bands['long']['model_requests'] === $scope['model_requests'] );
			}
			$bands = $scope['pricing_band_token_totals']['openai_sol'];
			$short = $bands['short'];
			$long  = $bands['long'];
			$cost  = ( $short['uncached_input_tokens'] * 2 + $short['cached_input_tokens'] * 0.1 + $short['output_tokens'] * 10 + $long['uncached_input_tokens'] * 4 + $long['cached_input_tokens'] * 0.2 + $long['output_tokens'] * 15 ) / 1e6;
			expect( abs( $cost - $scope['api_equivalent_standard_usd'] ) < 0.000001 );
		}
		$text = json_encode( $record, JSON_THROW_ON_ERROR );
		foreach ( [ 'thread_id', 'session_id', 'response_id', 'C:\\Users', 'C:/Users' ] as $private )
			expect( ! str_contains( $text, $private ) );

		$guide = file_get_contents( "$root/docs/agent-costs.md" );
		expect( str_contains( $guide, 'not a measured cost for simply rerunning' ) );
		expect( str_contains( $guide, 'not charges paid' ) );
		expect( str_contains( strtolower( $guide ), 'not evaluated' ) );
	},
	/**
	 * Checks that every native agent entry point receives the same instructions.
	 *
	 * @return void
	 */
	'agent entry points have identical native safety instructions' => function (): void {

		$root = dirname( __DIR__ );
		$base = file_get_contents( $root . '/AGENTS.md' );

		foreach ( [ 'CLAUDE.md', '.github/copilot-instructions.md' ] as $path ) {
			expect(
				file_get_contents( $root . '/' . $path ) === $base,
				'Native instructions drifted: ' . $path,
			);
		}

		$cursor = file_get_contents(
			$root . '/.cursor/rules/benchmark.mdc',
		);

		expect(
			str_contains( $cursor, 'alwaysApply: true' ),
			'Cursor instructions must load natively.',
		);
		$body = preg_replace(
			'/\A---\n.*?\n---\n\n/s',
			'',
			$cursor,
		);

		expect(
			$body === $base,
			'Cursor instructions drifted.',
		);
	},
	/**
	 * Checks prompt order, explicit approval, and preservation of unfavorable results.
	 *
	 * @return void
	 */
	'readme leads with an actionable consent-aware prompt' => function (): void {

		$text   = file_get_contents(
			dirname( __DIR__ ) . '/README.md',
		);
		$prompt = strpos( $text, '```text' );
		$next   = strpos( $text, '## What happens next' );

		expect(
			(
				   false !== $prompt
				&& false !== $next
				&& $prompt < $next
			),
			'The copyable prompt must come first.',
		);
		expect(
			str_contains(
				$text,
				'Wait for my approval before installing or setting anything up.',
			),
			'The prompt must request approval.',
		);
		expect(
			str_contains(
				$text,
				'Keep the evidence even if TSF does not win.',
			),
			'The prompt must preserve unfavorable results.',
		);
		expect(
			str_contains(
				$text,
				'## Bonus: explain the differences',
			),
			'The code-quality prompt is missing.',
		);
	},
	/**
	 * Checks that documented measurement limits remain explicit.
	 *
	 * @return void
	 */
	'docs preserve measurement scope and missing-sensor limits' => function (): void {

		$root        = dirname( __DIR__ );
		$method      = file_get_contents( $root . '/docs/methodology.md' );
		$environment = file_get_contents( $root . '/docs/environment.md' );

		expect(
			str_contains( $method, 'TSF 5.2.0' ),
			'Historical comparisons must identify the original version.',
		);
		expect(
			str_contains(
				$method,
				'exclusive plugin CPU self-time',
			),
			'Estimated added time must not imply CPU attribution.',
		);
		expect(
			(
				   str_contains( $method, 'Evidence replay' )
				&& str_contains( $method, 'Repeated experiment' )
			),
			'Both reproducibility checks must be documented.',
		);
		expect(
			str_contains(
				$environment,
				'Unsupported readings stay null',
			),
			'Missing telemetry must remain explicit.',
		);
		expect(
			str_contains(
				$environment,
				'A second-level disk cache is valid',
			),
			'Do not reject all disk-cache configurations.',
		);
	},
	/**
	 * Checks the documented order of setup, isolation, cleanup, and publication.
	 *
	 * @return void
	 */
	'workflow keeps installation and isolation approvals before activation' => function (): void {

		$root      = dirname( __DIR__ );
		$text      = file_get_contents(
			$root . '/docs/agent-workflow.md',
		);
		$prepare   = strpos(
			$text,
			'php benchmark.php prepare --run',
		);
		$isolation = strpos(
			$text,
			'php benchmark.php isolation --run',
		);
		$run       = strpos(
			$text,
			'php benchmark.php run --run',
		);
		$cleanup   = strpos(
			$text,
			'php benchmark.php cleanup --run',
		);
		$report    = strpos(
			$text,
			'php benchmark.php report --run',
		);

		expect(
			(
				   false !== $prepare
				&& false !== $isolation
				&& false !== $run
				&& $prepare < $isolation
				&& $isolation < $run
			),
			'The documented sequence must isolate before activation.',
		);
		expect(
			(
				   false !== $cleanup
				&& false !== $report
				&& $cleanup < $report
			),
			'Cleanup verification must precede the final report.',
		);
		expect(
			str_contains(
				$text,
				'It does not enforce a firewall itself.',
			),
			'Do not imply the receipt itself blocks outbound traffic.',
		);
		expect(
			str_contains(
				$text,
				'scripts/local-target.php',
			),
			'Local target discovery must be documented.',
		);
		expect(
			str_contains(
				$text,
				'php benchmark.php export --run',
			),
			'Public evidence export must be documented.',
		);
		expect(
			str_contains(
				$text,
				'Do not share the entire directory.',
			),
			'Private run files must not be presented as a public bundle.',
		);
	},
];
