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

		$grok  = TsfBenchmark\read_json( "$root/docs/agent-usage-2026-11-13.json" );
		$usage = $grok['usage'];
		expect( 22488387 === $usage['input_tokens'] && 22648958 === $usage['total_tokens'] );
		expect( $usage['ordinary_input_tokens'] + $usage['cached_input_tokens'] + $usage['cache_write_input_tokens'] === $usage['input_tokens'] );
		expect( $usage['input_tokens'] + $usage['output_tokens'] === $usage['total_tokens'] );
		expect( 18 === $grok['coverage']['numeric_usage_rows'] && 1 === $grok['coverage']['free_rows_without_usage'] );
		expect( null === $grok['coverage']['free_row_tokens'] && null === $grok['coverage']['individual_model_requests'] );
		expect( false === $grok['timed_collection_completed'] && false === $grok['pricing']['invoice_reconstruction'] );
		expect( 'Included' === $grok['operator']['billing_status'] && null === $grok['coverage']['exact_pool_debit_usd'] );
		foreach ( $grok['pricing']['scenarios'] as $scenario ) {
			$rates = $scenario['rate_per_million'];
			$cost  = (
				$usage['ordinary_input_tokens'] * $rates['ordinary_input']
				+ $usage['cached_input_tokens'] * $rates['cache_read']
				+ $usage['output_tokens'] * $rates['output']
			) / $grok['pricing']['rate_unit_tokens'];
			expect( abs( $cost - $scenario['api_equivalent_usd'] ) < 1e-9 );
		}
		$base = $grok['pricing']['scenarios'][0]['api_equivalent_usd'];
		expect( abs( $base - 12.87588 ) < 1e-9 );
		expect( abs( array_sum( $grok['pricing']['base_cost_components_usd'] ) - $base ) < 1e-9 );
		expect( false === $grok['allowance']['pools_interchangeable'] && false === $grok['allowance']['projection_is_plan_entitlement'] );
		expect( null === $grok['allowance']['actual_account_used_or_remaining_percent'] );
		foreach ( $grok['allowance']['multiplier_projection'] as $tier ) {
			$pool = $grok['allowance']['reported_cursor_models_usd'] * $tier['multiplier'] / 50;
			expect( abs( $pool - $tier['hypothetical_eligible_pool_usd'] ) < 1e-9 );
			expect( abs( 100 * $base / $pool - $tier['regular_scope_share_percent'] ) < 1e-9 );
		}
		$effort = $grok['effort_scenario'];
		expect( true === $effort['not_observed_usage'] && true === $effort['input_held_fixed'] );
		expect( abs( $usage['output_tokens'] * $effort['output_multiplier'] - $effort['estimated_output_tokens'] ) < 1e-9 );
		expect( abs( $base + $usage['output_tokens'] * 0.3 * 6 / 1e6 - $effort['regular_api_equivalent_usd'] ) < 1e-9 );
		foreach ( [ '$12.875880', '$13.164908', '0.64%', '1.29%' ] as $figure )
			expect( str_contains( $guide, $figure ), 'Recorded Grok figure missing from the guide: ' . $figure );
	},
	/**
	 * Checks illustrative cost arithmetic without treating assumptions as usage.
	 *
	 * @return void
	 */
	'prospective costs distinguish cache assumptions and measured usage' => function (): void {

		$text = file_get_contents( dirname( __DIR__ ) . '/docs/agent-costs.md' );
		foreach (
			[
				[ 'GPT-6.1 Sol Standard', 2, 0.1, 10 ],
				[ 'GPT-6.1 Sol Fast', 4, 0.2, 20 ],
				[ 'Grok 4.7 Standard', 2, 0.5, 6 ],
				[ 'Claude Sonnet 5.5 Standard', 2, 0.1, 10 ],
				[ 'Claude Opus 5.5 Standard', 4, 0.2, 20 ],
			] as [ $model, $input, $cache, $output ]
		) {
			$cached_rate = 0.1 * $input + 0.9 * $cache;
			$expected    = sprintf(
				'| %s | $%.2f–$%.2f | $%.2f–$%.2f | Not evaluated |',
				$model,
				3 * $cached_rate + 0.05 * $output,
				12 * $cached_rate + 0.15 * $output,
				3 * $input + 0.05 * $output,
				12 * $input + 0.15 * $output,
			);
			expect( str_contains( $text, $expected ), 'Incorrect estimated costs for ' . $model );
		}
		expect( str_contains( $text, 'actual token usage: not evaluated' ) );
		expect( str_contains( $text, 'no-cache column excludes cache writes' ) );
		expect( str_contains( $text, 'not a guaranteed upper bound' ) || str_contains( $text, 'or a guaranteed upper bound' ) );
	},
	/**
	 * Prevents known budget questionnaires returning to the copyable prompts.
	 *
	 * @return void
	 */
	'agent prompts estimate costs before setup without requiring a budget' => function (): void {

		$root = dirname( __DIR__ );
		foreach ( [ 'README.md', 'docs/agent-workflow.md', 'docs/agent-costs.md' ] as $path ) {
			$text = file_get_contents( "$root/$path" );
			foreach ( [ 'Ask for my time', 'Ask for my time/spending', 'Ask the user for their available time' ] as $old )
				expect( ! str_contains( $text, $old ), 'Budget questionnaire returned in ' . $path );
		}
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
	 * Checks optional external isolation, setup order, cleanup, and publication.
	 *
	 * @return void
	 */
	'workflow separates default controls from optional external verification' => function (): void {

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
			'Optional external verification must precede activation.',
		);
		expect(
			str_contains( $text, 'No separate isolation receipt or extra user confirmation is required for this default.' ),
			'Default WordPress controls must not require an extra isolation approval.',
		);
		expect(
			str_contains( $text, 'For a stronger mode, record the operator' ),
			'Stronger controls still require actual external verification.',
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
