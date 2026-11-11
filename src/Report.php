<?php
/**
 * Self-contained HTML reports for benchmark observations and limitations.
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
 * Escape an analysis value for an HTML text or quoted attribute context.
 *
 * @param mixed $value Scalar report value.
 * @return string Escaped UTF-8 text with malformed sequences substituted.
 */
function report_escape( mixed $value ): string {
	return htmlspecialchars(
		(string) $value,
		\ENT_QUOTES | \ENT_SUBSTITUTE,
		'UTF-8',
	);
}

/**
 * Format a resolved number without turning missing evidence into a zero.
 *
 * @param ?float $value  Observation, or null when unresolved.
 * @param string $suffix Trusted unit suffix supplied by the renderer.
 * @return string Formatted number or an explicit unresolved label.
 */
function report_number( ?float $value, string $suffix = '' ): string {
	return null === $value ? 'Unresolved' : number_format( $value, 3, '.', '' ) . $suffix;
}

/**
 * Present environment limitations alongside the measurements they qualify.
 *
 * Measurement-window telemetry takes precedence over setup-inclusive coverage.
 * Mixed-unit values stay separate, and unavailable sensors remain visible.
 *
 * @param array $environment Public environment and telemetry evidence.
 * @return string Escaped HTML section with conditions and monitoring coverage.
 */
function render_environment( array $environment ): string {

	$escape     = __NAMESPACE__ . '\\report_escape';
	$conditions = $coverage = [];
	$collect    = function ( array $node, string $scope = '' ) use ( &$collect, &$conditions, &$coverage ): void {

		foreach ( $node['conditions'] ?? [] as $condition ) {
			if (
				   \is_array( $condition )
				&& isset(
					$condition['severity'],
					$condition['message'],
				)
			) {
				$condition['scope'] = $condition['scope'] ?? $scope;
				$conditions[]       = $condition;
			}
		}

		if (
			   isset( $node['measurement_coverage'] )
			&& \is_array( $node['measurement_coverage'] )
		) {
			$coverage[ ( $scope ?: 'Run' ) . ' / measurement window' ] = $node['measurement_coverage'];
		} elseif ( isset( $node['coverage'] ) && \is_array( $node['coverage'] ) ) {
			$coverage[ ( $scope ?: 'Run' ) . ' / full collection window' ] = $node['coverage'];
		}
		foreach ( $node as $key => $child ) {
			if (
				   \is_array( $child )
				&& ! \in_array(
					$key,
					[ 'conditions', 'coverage', 'measurement_coverage' ],
					true,
				)
			) {
				$collect(
					$child,
					'' === $scope ? (string) $key : "$scope / $key",
				);
			}
		}
	};
	$collect( $environment );
	$html = '<section><h2>Environment and limitations</h2><p>Unavailable measurements mean unverified conditions. Thermal pressure, CPU temperature, estimated clocks, and measured clocks are distinct observations.</p>';

	if ( $conditions ) {
		$html     .= '<div class="scroll"><table><thead><tr><th>Scope</th><th>Condition</th><th>Meaning</th></tr></thead><tbody>';
		foreach ( $conditions as $condition )
			$html .= '<tr><td>' . $escape( $condition['scope'] ) . '</td><td>' . $escape( $condition['severity'] ) . '</td><td>' . $escape( $condition['message'] ) . '</td></tr>';
		$html     .= '</tbody></table></div>';
	} else {
		$html .= '<p>No structured environment conditions were supplied. Check the recorded evidence below; absence of a warning does not verify the environment.</p>';
	}
	foreach ( $coverage as $scope => $metrics ) {
		$html .= '<h3>Monitoring: ' . $escape( $scope ) . '</h3><div class="scroll"><table><thead><tr><th>Measurement</th><th>Observed values</th><th>Available / missing</th><th>Source and limitations</th></tr></thead><tbody>';
		foreach ( $metrics as $name => $metric ) {
			if ( ! \is_array( $metric ) ) continue;
			$observed = 'Unavailable';

			if (
				   \is_array( $metric['numeric'] ?? null )
				&& empty( $metric['mixed_units'] )
			) {
				$numeric  = $metric['numeric'];
				$observed = 'Min ' . report_number( $numeric['min'] ) . '; median ' . report_number( $numeric['median'] ) . '; max ' . report_number( $numeric['max'] );

				if ( ! empty( $metric['units'] ) )
					$observed .= ' (' . implode( ', ', $metric['units'] ) . ')';
			} elseif ( ! empty( $metric['states'] ) ) {
				$observed = implode(
					', ',
					array_map(
						fn( $state ): string => \is_bool( $state ) ? ( $state ? 'true' : 'false' ) : (string) $state,
						$metric['states'],
					),
				);
			} elseif ( ! empty( $metric['mixed_units'] ) ) {
				$observed = 'Mixed units; see individual readings.';
			}
			$details = implode(
				'; ',
				array_merge(
					$metric['sources'] ?? [],
					$metric['reasons'] ?? [],
				),
			);
			$html   .= '<tr><td>' . $escape( str_replace(
				'_',
				' ',
				(string) $name,
			) ) . '</td><td>' . $escape( $observed ) . '</td><td>' . (int) ( $metric['available'] ?? 0 ) . ' / ' . (int) ( $metric['unavailable'] ?? 0 ) . '</td><td>' . $escape( $details ) . '</td></tr>';
		}
		$html .= '</tbody></table></div>';
	}
	$html .= '<details><summary>Recorded environment details</summary><pre>' . $escape( json_encode(
		$environment,
		\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
	) ) . '</pre></details></section>';

	return $html;
}

/**
 * Render a standalone report with all declared routes, passes, and limitations.
 *
 * No fonts, scripts, tracking, or assets are fetched. Added-response ratios and
 * whole-response ratios are labelled separately to prevent a CPU-time claim.
 *
 * @param array $analysis Deterministic output from analyze().
 * @return string Complete escaped HTML document.
 */
function render_report( array $analysis ): string {

	if ( '2.0.0' === $analysis['analysis_version'] )
		return render_paired_report( $analysis );

	$escape = __NAMESPACE__ . '\\report_escape';
	$names  = array_column(
		$analysis['arms'],
		'name',
		'id',
	);
	$title  = $escape( $analysis['name'] );
	$html   = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>';
	$html  .= '<style>body{font:17px/1.6 system-ui,sans-serif;color:#20262d;background:#fafafa;max-width:1100px;margin:2rem auto;padding:0 1rem}h1,h2,h3{line-height:1.2}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{text-align:left;padding:.55rem;border-bottom:1px solid #cbd1d8}th{background:#edf0f4}pre{white-space:pre-wrap;overflow-wrap:anywhere}section{margin:2.5rem 0}.notice{padding:1rem;border-left:5px solid #9a6800;background:#fff6df}.scroll{overflow-x:auto}code{overflow-wrap:anywhere}.muted{color:#4c5661}@media(prefers-color-scheme:dark){body{background:#15191e;color:#edf0f4}th{background:#282f37}.notice{background:#332b19}.muted{color:#b7bec7}}</style><body>';
	$html  .= '<h1>' . $title . '</h1><p><strong>Result: ' . $escape( str_replace(
		'_',
		' ',
		$analysis['verdict'],
	) ) . '</strong> · ' . $escape( $analysis['scope'] ) . ' experiment</p>';
	$html  .= '<p>' . $escape( $analysis['metric'] ) . '</p><p>Threshold: ' . report_number(
		(float) $analysis['comparison']['threshold'],
		'×',
	) . '. Every declared route and every pass remains visible. A failed or inconclusive result is a valid finding.</p>';
	$html  .= '<p>Anchor: <strong>' . $escape(
		$names[ $analysis['comparison']['anchor'] ],
	) . '</strong>. A ratio above 1 means the comparator added more response time than the anchor; a ratio below 1 means it added less. This is not a ratio of whole-page loading speeds.</p>';

	if ( $analysis['reasons'] )
		$html .= '<div class="notice"><ul><li>' . implode(
			'</li><li>',
			array_map( $escape, $analysis['reasons'] ),
		) . '</li></ul></div>';
	$html     .= '<section><h2>Across passes</h2><div class="scroll"><table><thead><tr><th>Comparator</th><th>Added-time ratios by pass</th><th>Conclusion</th></tr></thead><tbody>';
	foreach ( $analysis['comparisons'] as $arm => $comparison ) {
		$html .= '<tr><td>' . $escape( $names[ $arm ] ?? $arm ) . '</td><td>' . implode(
			' / ',
			array_map(
				fn( $value ): string => report_number( $value, '×' ),
				$comparison['pass_ratios'],
			),
		) . '</td><td>' . $escape( str_replace(
			'_',
			' ',
			$comparison['verdict'],
		) ) . '</td></tr>';
	}
	$html .= '</tbody></table></div><p class="muted">Repeated experiments need consistent effects, not identical milliseconds. Evidence replay regenerates these numbers from the same observations and analysis seed.</p></section>';
	$html .= render_environment( $analysis['environment'] );
	foreach ( $analysis['passes'] as $pass ) {
		$html .= '<section><h2>Pass ' . (int) $pass['pass'] . ': ' . $escape( str_replace(
			'_',
			' ',
			$pass['verdict'],
		) ) . '</h2>';

		if ( $pass['reasons'] )
			$html .= '<div class="notice"><ul><li>' . implode(
				'</li><li>',
				array_map( $escape, $pass['reasons'] ),
			) . '</li></ul></div>';
		$html     .= '<div class="scroll"><table><thead><tr><th>Comparator</th><th>Added-time ratio</th><th>' . report_number(
			100 * $analysis['comparison']['confidence'],
			'%',
		) . ' simultaneous interval</th><th>Whole-response ratio</th><th>Conclusion</th></tr></thead><tbody>';
		foreach ( $pass['comparisons'] as $arm => $comparison ) {
			$html .= '<tr><td>' . $escape( $names[ $arm ] ?? $arm ) . '</td><td>' . report_number( $comparison['ratio'], '×' ) . '</td><td>' . report_number(
				$comparison['confidence_low'],
				'×',
			) . ' – ' . report_number(
				$comparison['confidence_high'],
				'×',
			) . '</td><td>' . report_number(
				$comparison['full_response_ratio'],
				'×',
			) . '</td><td>' . $escape( str_replace(
				'_',
				' ',
				$comparison['verdict'],
			) ) . '</td></tr>';
		}
		$html .= '</tbody></table></div>';
		foreach ( $pass['routes'] as $route => $data ) {
			$html .= '<h3>' . $escape( $route ) . '</h3>';

			if ( ! $data['arms'] ) {
				$html .= '<p>' . $escape( $data['reason'] ) . '</p>';
				continue;
			}
			$html .= '<p>WordPress-only median: ' . report_number( $data['core_median_ms'], ' ms' ) . '. Control variation: ' . report_number(
				$data['control_variation_ms'],
				' ms',
			) . '.</p><div class="scroll"><table><thead><tr><th>Arm</th><th>Median response</th><th>p95 response</th><th>Estimated addition</th><th>Qualified</th></tr></thead><tbody>';
			foreach ( $data['arms'] as $arm => $values ) {
				$html .= '<tr><td>' . $escape( $names[ $arm ] ?? $arm ) . '</td><td>' . report_number( $values['median_ms'], ' ms' ) . '</td><td>' . report_number( $values['p95_ms'], ' ms' ) . '</td><td>' . report_number( $values['added_ms'], ' ms' ) . '</td><td>' . ( \in_array(
					$arm,
					[ 'core-a', 'core-b' ],
					true,
				) ? 'Control' : ( $values['qualified'] ? 'Yes' : 'Unresolved: ' . $escape( implode(
					' ',
					$values['reasons'],
				) ) ) ) . '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		}
		$html .= '</section>';
	}
	$html .= '<section><h2>Method</h2><p>Whole measurement blocks are resampled together, preserving shared controls. Percentile bounds use Bonferroni correction across comparator configurations. They describe sampling uncertainty within this experiment, not every website or machine. Custom experiments are legitimate and retain their actual declared scope.</p><pre>' . $escape( json_encode(
		$analysis['measurement'],
		\JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
	) ) . '</pre><p>Recipe: <code>' . $escape( $analysis['recipe_id'] ) . '</code> · Analysis: <code>' . $escape( $analysis['analysis_version'] ) . '</code></p></section></body></html>';

	return $html;
}
