<?php
/**
 * TSF WordPress Benchmark
 * Copyright (C) 2026 Sybre Waaijer, CyberWire B.V. (https://cyberwire.nl/)
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * Version 2 paired effects, conditional uncertainty, and comparison decisions.
 *
 * @package TSF_WP_Benchmark
 */

namespace TsfBenchmark;

// These pure calculations are also used by the preserved exploratory workflow.
require_once \dirname( __DIR__ ) . '/scripts/statistical-review.php';
require_once \dirname( __DIR__ ) . '/scripts/statistical-diagnostics.php';

/**
 * Classify a threshold contrast jointly with a positive anchor addition.
 *
 * Direct timing differences remain estimable when this ratio interpretation is
 * unresolved. Bounds describe the observed session, not future-host coverage.
 *
 * @param array  $intervals Contrast intervals from one declared resampling model.
 * @param string $arm       Comparator identifier.
 * @return string Threshold verdict conditional on this session.
 */
function paired_threshold_verdict( array $intervals, string $arm ): string {

	$a = $intervals['anchor_added'] ?? null;
	$h = $intervals[ 'threshold:' . $arm ] ?? null;
	if ( ! $a || ! $h || 0 >= $a['low_ms'] ) return 'inconclusive';
	if ( 0 < $h['low_ms'] ) return 'supported';
	if ( 0 > $h['high_ms'] ) return 'not_reproduced';
	return 'inconclusive';
}

/**
 * Require agreement across declared dependence models or repeated passes.
 *
 * @param string[] $verdicts Threshold verdicts; none are selectively omitted.
 * @return string Agreement, unresolved evidence, or opposing decisions.
 */
function paired_agreement( array $verdicts ): string {

	if ( ! $verdicts ) return 'inconclusive';
	$unique = array_values( array_unique( $verdicts ) );
	if ( 1 === \count( $unique ) ) return $unique[0];
	if ( \in_array( 'inconsistent', $unique, true ) || ( \in_array( 'supported', $unique, true ) && \in_array( 'not_reproduced', $unique, true ) ) )
		return 'inconsistent';
	return 'inconclusive';
}

/**
 * Combine independently reported comparator decisions for an all-arm claim.
 *
 * @param array $comparisons Every declared comparator and its own verdict.
 * @return string Complete-cohort verdict without suppressing individual results.
 */
function paired_cohort_verdict( array $comparisons ): string {

	$verdicts = array_column( $comparisons, 'verdict' );
	if ( \in_array( 'inconsistent', $verdicts, true ) ) return 'inconsistent';
	if ( \in_array( 'not_reproduced', $verdicts, true ) ) return 'not_reproduced';
	return $verdicts && [ 'supported' ] === array_values( array_unique( $verdicts ) ) ? 'supported' : 'inconclusive';
}

/**
 * Analyze the complete experiment without worst-block or positive-cell vetoes.
 *
 * Version 1 remains the observation validator and historical descriptive reader.
 * Its timing eligibility warnings are retained as diagnostics, not inferential
 * gates. Output stability is checked per comparator, so an unrelated uncertain
 * comparator cannot erase other paired comparisons.
 *
 * @param array $recipe      Validated experiment definition.
 * @param array $samples     All measured observations and retained warmups.
 * @param array $environment Recorded limitations and blocking conditions.
 * @return array Version 2 report, including all planned sensitivities.
 */
function analyze_paired( array $recipe, array $samples, array $environment = [] ): array {

	$result                     = analyze_legacy( $recipe, $samples, $environment );
	$anchor                     = $recipe['comparison']['anchor'];
	$comparators                = array_values( array_diff( array_column( $recipe['arms'], 'id' ), [ 'core-a', 'core-b', $anchor ] ) );
	$smoke                      = 'smoke' === $result['scope'];
	$threshold                  = (float) $recipe['comparison']['threshold'];
	$confidence                 = (float) $recipe['comparison']['confidence'];
	$seed                       = $recipe['measurement']['seed'];
	$draws                      = $smoke ? $recipe['measurement']['bootstrap'] : max( 50000, $recipe['measurement']['bootstrap'] );
	$timing_only                = [
		'At least one declared route, arm, control, or environment condition is unqualified; the full-cohort claim remains unresolved.',
		'Bootstrap denominators were unresolved; no bounded ratio interval is reported.',
	];
	$result['schema']           = 2;
	$result['analysis_version'] = '2.0.0';
	$result['method']           = 'paired-block-1';
	$result['metric']           = 'Equal-weight mean of paired cell-median differences across all blocks, fixed routes, and passes. Ratios of average additions are descriptive; threshold inference uses linear contrasts. This is added HTTP response time, not plugin CPU self-time or whole-page speed.';
	$result['verdict']          = $smoke ? 'smoke_only' : 'inconclusive';
	$result['measurement']['effective_bootstrap'] = $draws;
	$result['paired']                             = [
		'points_added_ms'               => [
			'overall' => [],
			'passes'  => [],
		],
		'bootstrap'                     => [],
		'per_pass_diagnostic_intervals' => [],
		'influence'                     => [],
		'diagnostics'                   => [],
		'limitations'                   => [
			'Approximate intervals are conditional on the observed session and the declared fixed route weights, not every website or machine.',
			'The ratio of average additions differs from version 1.0.0 arithmetic mean of route ratios. No bounded ratio confidence interval is claimed.',
			'Circular resampling assumes approximate stationarity, joins the end and start of a route, and cannot eliminate drift or systematic bias.',
			'All five dependence models are reported. Each has its own nominal simultaneous contrast family; no joint coverage over method selection is claimed.',
			'Pass-level resampling is sensitivity only. Few passes do not establish calibrated future-session coverage.',
			'Per-pass intervals are ordinary diagnostic intervals, outside the aggregate simultaneous family. Threshold conclusions require pass agreement.',
			'All negative additions and complete cells remain. Timing warnings and influence checks do not authorize deleting observations.',
			'A supported threshold requires a positive anchor lower bound and positive threshold margins across sensitivities and repeated passes.',
			'Temperature, effective clocks, missing features, and other recorded environment limitations remain relevant even when intervals exclude zero.',
		],
	];
	$matrix_valid                                 = true;
	$pass_valid                                   = [];
	foreach ( $result['passes'] as &$pass ) {
		$pass['reasons']                      = array_values( array_diff( $pass['reasons'], $timing_only ) );
		$pass_valid[ $pass['pass'] ]          = ! $pass['reasons'];
		if ( $pass['reasons'] ) $matrix_valid = false;
		foreach ( $pass['routes'] as &$route ) {
			if ( ! $route['arms'] ) $matrix_valid = false;
			foreach ( $route['arms'] as &$arm ) {
				$arm['timing_warnings'] = array_values( array_filter( $arm['reasons'], fn( string $reason ): bool => str_starts_with( $reason, 'Estimated added time' ) || str_starts_with( $reason, 'At least one block' ) ) );
				$arm['reasons']         = array_values( array_diff( $arm['reasons'], $arm['timing_warnings'] ) );
				$arm['pooled_added_ms'] = $arm['added_ms'];
				$arm['paired_added_ms'] = array_sum( $arm['block_added_ms'] ) / \count( $arm['block_added_ms'] );
				$arm['added_ms']        = $arm['paired_added_ms'];
				$arm['qualified']       = ! $arm['reasons'];
			}
			unset( $arm );
		}
		unset( $route );
	}
	unset( $pass );
	$blocking = array_values( array_diff( $result['reasons'], [ 'This is a setup/small-sample run. It cannot establish a broad comparative performance claim.' ] ) );
	$paired   = &$result['paired'];
	if ( $matrix_valid && ! $blocking ) {
		$cells                            = review_build_cells( $recipe, $samples );
		$points                           = review_points( $cells );
		$first_pass                       = reset( $cells );
		$first_route                      = reset( $first_pass );
		$blocks                           = \count( $first_route );
		$paired['points_added_ms']        = $points;
		$cases                            = [
			'primary-l3'    => [ min( 3, $blocks ), false, false ],
			'independent'   => [ 1, false, false ],
			'longer-l6'     => [ min( 6, $blocks ), false, false ],
			'shared-l3'     => [ min( 3, $blocks ), true, false ],
			'pass-level-l3' => [ min( 3, $blocks ), false, true ],
		];
		if ( 6 > $blocks )
			$paired['limitations'][]      = 'This schedule has fewer than six blocks; requested segment lengths are capped to the available blocks, limiting dependence assessment.';
		foreach ( $cases as $name => [ $length, $shared, $hierarchical ] )
			$paired['bootstrap'][ $name ] = review_bootstrap( $cells, $anchor, $threshold, $draws, $length, $shared, $hierarchical, $seed, true, $confidence );
		foreach ( $cells as $pass => $routes )
			$paired['per_pass_diagnostic_intervals'][ $pass ] = review_bootstrap( [ $routes ], $anchor, $threshold, $draws, min( 3, $blocks ), false, false, $seed + $pass, false, $confidence );
		$paired['influence']                                  = review_influence( $cells, $anchor, $threshold );
		$paired['diagnostics']                                = review_diagnostics( $recipe, $samples );
	}
	$result['comparisons'] = [];
	foreach ( $comparators as $arm ) {
		$pass_ratios = $pass_differences = $pass_verdicts = [];
		$qualified   = ! $blocking && $matrix_valid;
		foreach ( $result['passes'] as &$pass ) {
			$id   = $pass['pass'];
			$good = ! $blocking && $matrix_valid && $pass_valid[ $id ];
			foreach ( $pass['routes'] as $route ) {
				if ( ! ( $route['controls_stable'] ?? false ) || ! ( $route['arms'][ $anchor ]['stable'] ?? false ) || ! ( $route['arms'][ $arm ]['stable'] ?? false ) )
					$good = false;
			}
			$qualified                   = $qualified && $good;
			$values                      = $paired['points_added_ms']['passes'][ $id ] ?? [];
			$ratio                       = isset( $values[ $anchor ] ) && 0 < $values[ $anchor ] ? $values[ $arm ] / $values[ $anchor ] : null;
			$gain                        = isset( $values[ $arm ] ) ? $values[ $arm ] - $values[ $anchor ] : null;
			$intervals                   = $paired['per_pass_diagnostic_intervals'][ $id ]['intervals'] ?? [];
			$verdict                     = $good ? paired_threshold_verdict( $intervals, $arm ) : 'inconclusive';
			$pass_ratios[]               = $ratio;
			$pass_differences[ $id ]     = $gain;
			$pass_verdicts[]             = $verdict;
			$full                        = $pass['comparisons'][ $arm ]['full_response_ratio'] ?? null;
			$pass['comparisons'][ $arm ] = [
				'ratio'               => $ratio,
				'gain_ms'             => $gain,
				'gain_interval'       => $intervals[ 'gain:' . $arm ] ?? null,
				'threshold_interval'  => $intervals[ 'threshold:' . $arm ] ?? null,
				'confidence_low'      => null,
				'confidence_high'     => null,
				'full_response_ratio' => $full,
				'qualified'           => $good,
				'verdict'             => $smoke ? 'smoke_only' : $verdict,
			];
		}
		unset( $pass );
		$decisions                     = $pass_verdicts;
		foreach ( $paired['bootstrap'] as $case )
			$decisions[]               = paired_threshold_verdict( $case['intervals'], $arm );
		$intervals                     = $paired['bootstrap']['primary-l3']['intervals'] ?? [];
		$overall                       = $paired['points_added_ms']['overall'];
		$ratio                         = isset( $overall[ $anchor ] ) && 0 < $overall[ $anchor ] ? $overall[ $arm ] / $overall[ $anchor ] : null;
		$available                     = array_values( array_filter( $pass_ratios, fn( $value ): bool => null !== $value ) );
		$result['comparisons'][ $arm ] = [
			'ratio_of_average_additions' => $ratio,
			'gain_ms'                    => $intervals[ 'gain:' . $arm ]['point_ms'] ?? null,
			'threshold_margin_ms'        => $intervals[ 'threshold:' . $arm ]['point_ms'] ?? null,
			'gain_interval'              => $intervals[ 'gain:' . $arm ] ?? null,
			'threshold_interval'         => $intervals[ 'threshold:' . $arm ] ?? null,
			'pass_differences_ms'        => $pass_differences,
			'pass_ratios'                => $pass_ratios,
			'minimum'                    => $available ? min( $available ) : null,
			'maximum'                    => $available ? max( $available ) : null,
			'qualified'                  => $qualified,
			'verdict'                    => $smoke ? 'smoke_only' : ( $qualified ? paired_agreement( $decisions ) : 'inconclusive' ),
		];
	}
	foreach ( $result['passes'] as &$pass )
		$pass['verdict'] = $smoke ? 'smoke_only' : paired_cohort_verdict( $pass['comparisons'] );
	unset( $pass );
	$result['verdict'] = $smoke ? 'smoke_only' : paired_cohort_verdict( $result['comparisons'] );
	return $result;
}
