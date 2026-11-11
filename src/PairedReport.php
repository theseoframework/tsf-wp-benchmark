<?php
/**
 * Standalone reports for paired HTTP additions and their uncertainty.
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
 * Format both interval bounds without substituting zero for missing evidence.
 *
 * @param array $interval Recorded millisecond bounds.
 * @return string Formatted interval or explicit unresolved bounds.
 */
function report_paired_interval( array $interval ): string {

	return report_number( $interval['low_ms'] ?? null, ' ms' ) . ' to ' . report_number( $interval['high_ms'] ?? null, ' ms' );
}

/**
 * Show every recorded contrast for one resampling specification.
 *
 * @param array  $intervals Millisecond point estimates and interval bounds.
 * @param array  $names     Declared configuration names indexed by ID.
 * @param string $anchor    Anchor configuration ID.
 * @return string Escaped HTML table containing the complete contrast family.
 */
function report_paired_contrasts( array $intervals, array $names, string $anchor ): string {

	$escape = __NAMESPACE__ . '\\report_escape';
	$html   = '<div class="scroll"><table><thead><tr><th>Quantity</th><th>Point estimate</th><th>Interval</th></tr></thead><tbody>';
	foreach ( $intervals as $metric => $interval ) {
		if ( 'anchor_added' === $metric ) {
			$label = 'Estimated addition: ' . ( $names[ $anchor ] ?? $anchor );
		} else {
			[ $kind, $arm ] = explode( ':', $metric, 2 );
			$label          = ( 'gain' === $kind ? 'Direct anchor advantage over ' : 'Threshold margin for ' ) . ( $names[ $arm ] ?? $arm );
		}
		$html .= '<tr><td>' . $escape( $label ) . '</td><td>' . report_number( $interval['point_ms'] ?? null, ' ms' ) . '</td><td>' . report_paired_interval( $interval ) . '</td></tr>';
	}
	return $html . '</tbody></table></div>';
}

/**
 * Present paired estimates, all sensitivities, and retained timing diagnostics.
 *
 * Historical reports keep their own renderer. Ratios here are descriptive
 * ratios of average additions, never CPU or whole-page speed multipliers.
 *
 * @param array $analysis Version 2 analysis with paired inference and context.
 * @return string Complete escaped HTML without external scripts or assets.
 */
function render_paired_report( array $analysis ): string {

	$escape     = __NAMESPACE__ . '\\report_escape';
	$names      = array_column(
		$analysis['arms'],
		'name',
		'id',
	);
	$paired     = $analysis['paired'] ?? [];
	$points     = $paired['points_added_ms'] ?? [];
	$bootstrap  = $paired['bootstrap'] ?? [];
	$primary    = $bootstrap['primary-l3'] ?? reset( $bootstrap ) ?: [];
	$intervals  = $primary['intervals'] ?? [];
	$anchor     = $analysis['comparison']['anchor'];
	$threshold  = (float) $analysis['comparison']['threshold'];
	$context    = $analysis['analysis_context'] ?? [];
	$title      = $escape( $analysis['name'] );
	$family     = $primary['family_size'] ?? ( 1 + 2 * \count( $analysis['comparisons'] ) );
	$confidence = $primary['nominal_family'] ?? $analysis['comparison']['confidence'];
	$html       = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>';
	$html      .= '<style>body{font:17px/1.6 system-ui,sans-serif;color:#20262d;background:#fafafa;max-width:1250px;margin:2rem auto;padding:0 1rem}h1,h2,h3{line-height:1.2}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{text-align:left;vertical-align:top;padding:.55rem;border-bottom:1px solid #cbd1d8}th{background:#edf0f4}pre{white-space:pre-wrap;overflow-wrap:anywhere}section{margin:2.5rem 0}.notice{padding:1rem;border-left:5px solid #9a6800;background:#fff6df}.scroll{overflow-x:auto}code{overflow-wrap:anywhere}.muted{color:#4c5661}@media(prefers-color-scheme:dark){body{background:#15191e;color:#edf0f4}th{background:#282f37}.notice{background:#332b19}.muted{color:#b7bec7}}</style><body>';
	$html      .= '<h1>' . $title . '</h1><p><strong>Result: ' . $escape( str_replace(
		'_',
		' ',
		$analysis['verdict'],
	) ) . '</strong> · ' . $escape( $analysis['scope'] ) . ' experiment</p>';
	$html      .= '<p>Paired millisecond differences are the primary comparison. Every declared configuration, route, and pass remains in scope. An unresolved or unfavorable comparison remains a valid result.</p>';

	if ( ! empty( $context['retrospective'] ) ) {
		$html .= '<div class="notice"><strong>Retrospective analysis chosen after inspecting the original result.</strong><p>This is a reanalysis of retained observations, not independent confirmation or a new collection. It cannot retrospectively become a prespecified experiment. The historical analysis and its verdict are preserved separately.</p><p>Historical analysis version: ' . $escape( $context['source_analysis_version'] ?? 'See recorded provenance' ) . '. Historical verdict: ' . $escape( str_replace(
			'_',
			' ',
			$context['source_verdict'] ?? $paired['original_verdict'] ?? 'See historical report',
		) ) . '.</p></div>';
	}
	if ( ! empty( $analysis['reasons'] ) )
		$html .= '<div class="notice"><ul><li>' . implode(
			'</li><li>',
			array_map( $escape, $analysis['reasons'] ),
		) . '</li></ul></div>';
	$html     .= '<section><h2>Paired additions and direct comparisons</h2><p>Anchor: <strong>' . $escape( $names[ $anchor ] ?? $anchor ) . '</strong>. In each route/pass/block, subtract the pooled median of both same-block WordPress-only controls from each configuration median. Average equally over blocks, routes, and passes, retaining negative cells. These are estimated added HTTP response times, <strong>not plugin CPU self-time or multipliers on whole-page loading speed</strong>.</p>';
	$html     .= '<p>Direct advantage = comparator addition − anchor addition; positive values favor the anchor and the shared control cancels exactly. Threshold margin = comparator addition − ' . report_number( $threshold, '×' ) . ' anchor addition. A positive margin supports that threshold only jointly with a positive anchor addition. Descriptive ratios divide the average additions; they are a different estimand from the historical average of route ratios and have no fitted ratio confidence interval.</p>';
	$html     .= '<p>A ratio above 1 means the comparator added more response time on this aggregate; a ratio below 1 means it added less. This is not a ratio of whole-page loading speeds.</p>';
	$html     .= '<p>Anchor addition: <strong>' . report_number( $points['overall'][ $anchor ] ?? null, ' ms' ) . '</strong>. Primary approximate ' . report_number( 100 * $confidence, '%' ) . ' simultaneous interval: <strong>' . report_paired_interval( $intervals['anchor_added'] ?? [] ) . '</strong>.</p>';
	$html     .= '<div class="scroll"><table><thead><tr><th>Configuration</th><th>Paired addition</th><th>Direct anchor advantage</th><th>Primary advantage interval</th><th>Threshold margin</th><th>Primary margin interval</th><th>Descriptive ratio of average additions</th><th>Conclusion</th></tr></thead><tbody>';
	foreach ( $analysis['arms'] as $configuration ) {
		$arm = $configuration['id'];
		if ( \in_array( $arm, [ 'core-a', 'core-b' ], true ) ) continue;
		$comparison = $analysis['comparisons'][ $arm ] ?? [];
		$html      .= '<tr><td>' . $escape( $configuration['name'] ) . '</td><td>' . report_number( $points['overall'][ $arm ] ?? null, ' ms' ) . '</td>';
		if ( $arm === $anchor ) {
			$html .= '<td colspan="6">Anchor; addition interval shown above.</td></tr>';
			continue;
		}
		$html .= '<td>' . report_number( $comparison['gain_ms'] ?? null, ' ms' ) . '</td><td>' . report_paired_interval( $comparison['gain_interval'] ?? [] ) . '</td><td>' . report_number( $comparison['threshold_margin_ms'] ?? null, ' ms' ) . '</td><td>' . report_paired_interval( $comparison['threshold_interval'] ?? [] ) . '</td><td>' . report_number( $comparison['ratio_of_average_additions'] ?? null, '×' ) . '</td><td>' . $escape( str_replace(
			'_',
			' ',
			$comparison['verdict'] ?? 'inconclusive',
		) ) . '</td></tr>';
	}
	$html     .= '</tbody></table></div><p>Intervals are nominal ' . report_number( 100 * $confidence, '%' ) . ' simultaneous Bonferroni percentile intervals for a family of ' . (int) $family . ' quantities: one anchor addition, every direct advantage, and every threshold margin. Coverage is approximate and conditional on this session, the resampling assumptions, and the chosen method. It is not confidence about future sessions, other hosts, operating systems, or workloads.</p></section>';
	$html     .= '<section><h2>Limitations</h2><div class="notice"><p><strong>Timing diagnostics remain uncertainty evidence.</strong> They do not erase individual negative additions or substitute for response, output, isolation, feature, or environment validity. No clipping, selective routes, negative-cell deletion, or favorable-bootstrap-draw deletion is used.</p>';
	if ( ! empty( $paired['limitations'] ) )
		$html .= '<ul><li>' . implode(
			'</li><li>',
			array_map( $escape, $paired['limitations'] ),
		) . '</li></ul>';
	$html     .= '<p>Equal route weights describe the declared recipe, not a site’s traffic mix. Three passes, when that is the declared collection, provide very little information about between-session variation. A benchmark comparison does not establish the cause of a timing difference or the equivalence of all enabled features.</p></div></section>';
	$html     .= '<section><h2>All resampling specifications</h2><p>The primary method and every sensitivity are visible below for every comparator. Each specification has its own nominal simultaneous family; there is <strong>no joint coverage across the choice of sensitivity method</strong>. A favorable sensitivity is not selected as a replacement result. Common indices across sequential routes assess schedule dependence, not simultaneous temporal clustering. Resampling passes is a stress test with few observed passes, not calibrated future-session coverage.</p>';
	$labels    = [
		'primary-l3'    => 'Primary circular-block analysis',
		'independent'   => 'Independent-block sensitivity',
		'longer-l6'     => 'Longer-segment sensitivity',
		'shared-l3'     => 'Shared-schedule-index sensitivity',
		'pass-level-l3' => 'Pass-resampling sensitivity',
	];
	foreach ( $bootstrap as $method => $resampling ) {
		$html .= '<h3>' . $escape( $labels[ $method ] ?? $method ) . ': segment length ' . (int) $resampling['block_length'] . '</h3><p>Recorded model ID: <code>' . $escape( $method ) . '</code>; draws: ' . (int) $resampling['draws'] . '; seed: ' . (int) $resampling['seed'] . '; actual circular segment length: ' . (int) $resampling['block_length'] . '; indices shared across routes: ' . ( ! empty( $resampling['shared_indices'] ) ? 'yes' : 'no' ) . '; passes resampled: ' . ( ! empty( $resampling['hierarchical'] ) ? 'yes' : 'no' ) . '; family size: ' . (int) $resampling['family_size'] . '; tail probability: ' . $escape( $resampling['tail_probability'] ) . '.</p>';
		$html .= report_paired_contrasts( $resampling['intervals'], $names, $anchor );
	}
	if ( ! $bootstrap )
		$html .= '<p>No paired bootstrap intervals were produced. Inspect the reported blockers; missing intervals are unresolved evidence.</p>';
	$html     .= '</section><section><h2>Per-pass paired comparisons</h2><p>These are ordinary diagnostic ' . report_number( 100 * $confidence, '%' ) . ' intervals, outside the aggregate simultaneous family. They describe the retained pass, not an independent replication of the choice of method.</p>';
	foreach ( $points['passes'] ?? [] as $pass => $additions ) {
		$html     .= '<h3>Pass ' . (int) $pass . '</h3><div class="scroll"><table><thead><tr><th>Configuration</th><th>Paired addition</th><th>Direct anchor advantage</th></tr></thead><tbody>';
		foreach ( $additions as $arm => $addition )
			$html .= '<tr><td>' . $escape( $names[ $arm ] ?? $arm ) . '</td><td>' . report_number( $addition, ' ms' ) . '</td><td>' . ( $arm === $anchor ? 'Anchor' : report_number( $analysis['comparisons'][ $arm ]['pass_differences_ms'][ $pass ] ?? null, ' ms' ) ) . '</td></tr>';
		$html     .= '</tbody></table></div>';
		$html     .= report_paired_contrasts( $paired['per_pass_diagnostic_intervals'][ $pass ]['intervals'] ?? [], $names, $anchor );
	}
	$html .= '</section><section><h2>Influence ranges</h2><p>Each row gives point-estimate ranges after one cell or one pass is deleted with route/pass weighting retained. These are <strong>diagnostic ranges, not confidence intervals</strong>. Every original cell and pass remains in the primary analysis.</p>';
	foreach ( $paired['influence'] ?? [] as $scope => $contrasts ) {
		$html .= '<h3>Leave one ' . $escape( $scope ) . ' out</h3><div class="scroll"><table><thead><tr><th>Quantity</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>';
		foreach ( $contrasts as $metric => $range ) {
			$label = 'Estimated addition: ' . ( $names[ $anchor ] ?? $anchor );
			if ( 'anchor_added' !== $metric ) {
				[ $kind, $arm ] = explode( ':', $metric, 2 );
				$label          = ( 'gain' === $kind ? 'Direct anchor advantage over ' : 'Threshold margin for ' ) . ( $names[ $arm ] ?? $arm );
			}
			$html .= '<tr><td>' . $escape( $label ) . '</td><td>' . report_number( $range['minimum_ms'] ?? null, ' ms' ) . '</td><td>' . report_number( $range['maximum_ms'] ?? null, ' ms' ) . '</td></tr>';
		}
		$html .= '</tbody></table></div>';
	}
	$html .= '</section><section><h2>Timing regime and historical denominator diagnostics</h2><div class="notice"><p><strong>Alternating timing regimes and serial dependence require attention.</strong> Inspect the recorded odd/even differences and residual correlations below. Large alternating patterns can coexist with small average control bias. Their cause is not established here. Complete measured cells retain their observed mixture; pairing and sensitivity checks do not prove environmental bias absent.</p></div><p>Correlations, control bias, trends, and odd/even splits are descriptive diagnostics, without independent-observation significance tests. Historical denominator failures are counted without discarding or replacing draws; a ratio interval that excluded unfavorable denominators would hide that uncertainty.</p>';
	foreach ( $paired['diagnostics']['passes'] ?? [] as $pass ) {
		$denominator = $pass['original_denominator_bootstrap'] ?? [];
		$html       .= '<h3>Pass ' . (int) $pass['pass'] . '</h3><ul><li>Pooled WordPress-only median HTTP response: ' . report_number( $pass['pooled_control_median_ms'] ?? null, ' ms' ) . '.</li><li>Absolute odd/even mean split across cells: median ' . report_number( $pass['absolute_odd_even_mean_split_ms']['median'] ?? null, ' ms' ) . '; maximum ' . report_number( $pass['absolute_odd_even_mean_split_ms']['maximum'] ?? null, ' ms' ) . '.</li><li>Control B-minus-A bias: mean ' . report_number( $pass['control_bias_ms']['mean'] ?? null, ' ms' ) . '; minimum ' . report_number( $pass['control_bias_ms']['minimum'] ?? null, ' ms' ) . '; maximum ' . report_number( $pass['control_bias_ms']['maximum'] ?? null, ' ms' ) . '.</li><li>Nonpositive paired anchor cells: ' . (int) ( $pass['paired_anchor_nonpositive_blocks'] ?? 0 ) . ' / ' . (int) ( $pass['counts']['route_blocks'] ?? 0 ) . '.</li></ul>';
		$html       .= '<div class="scroll"><table><thead><tr><th>Residual lag</th><th>Pairs</th><th>Correlation</th></tr></thead><tbody>';
		foreach ( $pass['residual_serial_dependence'] ?? [] as $lag => $dependence )
			$html   .= '<tr><td>' . (int) $lag . '</td><td>' . (int) $dependence['pairs'] . '</td><td>' . report_number( $dependence['correlation'] ?? null ) . '</td></tr>';
		$html       .= '</tbody></table></div>';
		if ( $denominator ) {
			$html     .= '<p>Historical ratio bootstrap: ' . (int) $denominator['nonpositive_any_route_draws'] . ' / ' . (int) $denominator['draws'] . ' draws (' . report_number( 100 * $denominator['nonpositive_any_route_fraction'], '%' ) . ') had at least one nonpositive anchor route denominator.</p><div class="scroll"><table><thead><tr><th>Route</th><th>Nonpositive denominator draws</th></tr></thead><tbody>';
			foreach ( $denominator['nonpositive_draws_by_route'] ?? [] as $route => $count )
				$html .= '<tr><td>' . $escape( $route ) . '</td><td>' . (int) $count . ' / ' . (int) $denominator['draws'] . '</td></tr>';
			$html     .= '</tbody></table></div>';
		}
	}
	$html .= '</section>';
	$html .= render_environment( $analysis['environment'] );
	$html .= '<section><h2>Absolute HTTP responses and route additions</h2><p>Each declared route and configuration is retained below for every pass. Median and p95 are pooled absolute HTTP response descriptives. Paired addition is the mean of the same-block median differences for that route/pass; it is not the subtraction of the pooled medians. A timing warning describes uncertainty rather than silently removing a cell or route. Controls define the baseline.</p>';
	foreach ( $analysis['passes'] as $pass ) {
		$html     .= '<h3>Pass ' . (int) $pass['pass'] . '</h3>';
		if ( ! empty( $pass['reasons'] ) )
			$html .= '<div class="notice"><ul><li>' . implode(
				'</li><li>',
				array_map( $escape, $pass['reasons'] ),
			) . '</li></ul></div>';
		foreach ( $pass['routes'] as $route => $data ) {
			$html .= '<h4>' . $escape( $route ) . '</h4>';
			if ( empty( $data['arms'] ) ) {
				$html .= '<p>' . $escape( $data['reason'] ?? 'Unresolved route evidence.' ) . '</p>';
				continue;
			}
			$html .= '<p>Pooled WordPress-only median: ' . report_number( $data['core_median_ms'] ?? null, ' ms' ) . '; largest same-block control disagreement: ' . report_number( $data['control_variation_ms'] ?? null, ' ms' ) . '.</p><div class="scroll"><table><thead><tr><th>Configuration</th><th>Median HTTP response</th><th>p95 HTTP response</th><th>Paired addition</th><th>Validity and timing diagnostics</th></tr></thead><tbody>';
			foreach ( $data['arms'] as $arm => $values ) {
				$control = \in_array( $arm, [ 'core-a', 'core-b' ], true );
				$details = implode(
					' ',
					array_merge(
						$values['reasons'] ?? [],
						$values['timing_warnings'] ?? [],
					),
				);
				$html   .= '<tr><td>' . $escape( $names[ $arm ] ?? $arm ) . '</td><td>' . report_number( $values['median_ms'] ?? null, ' ms' ) . '</td><td>' . report_number( $values['p95_ms'] ?? null, ' ms' ) . '</td><td>' . ( $control ? 'Control' : report_number( $values['paired_added_ms'] ?? null, ' ms' ) ) . '</td><td>' . $escape( $details ?: 'No validity or timing warning recorded.' ) . '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		}
	}
	$html .= '</section><section><h2>Method and provenance</h2><p>Fixed-length circular segments resample complete paired schedule blocks, joining the end and start of each route. Approximate stationarity and the chosen segment length remain assumptions. Routes were collected sequentially; equal schedule indices are not simultaneous measurements. Aggregate fixed-pass resampling stays within pass strata. Percentiles use linear interpolation at (draws − 1) × probability with a deterministic recorded generator and seed.</p><p>Recipe: <code>' . $escape( $analysis['recipe_id'] ) . '</code> · Analysis: <code>' . $escape( $analysis['analysis_version'] ) . '</code> · Method: <code>' . $escape( $analysis['method'] ?? 'paired-block-1' ) . '</code></p>';
	foreach ( [
		'measurement'      => 'Declared measurement settings',
		'analysis_context' => 'Collection and reanalysis context',
		'provenance'       => 'Analysis provenance',
	] as $key => $label ) {
		$html .= '<h3>' . $escape( $label ) . '</h3><pre>' . $escape( json_encode(
			$analysis[ $key ] ?? [],
			\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
		) ) . '</pre>';
	}
	$html .= '<details><summary>Complete paired estimates, sensitivities, influence, and diagnostics</summary><pre>' . $escape( json_encode(
		$paired,
		\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
	) ) . '</pre></details></section></body></html>';
	return $html;
}
