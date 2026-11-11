# Statistical review and adoption of the paired default

The original timing eligibility rules are too absolute for general use. One negative block should widen uncertainty and appear in diagnostics; it should not automatically erase otherwise valid comparisons. The existing observations support useful paired comparisons even though the original ratio analysis remains inconclusive.

The review below is an **exploratory analysis chosen after inspecting the original result**, identified as `paired-block-review-1`. Its numerical findings and the original version 1.0.0 verdict remain historical evidence. Following explicit approval, the paired approach is now the default as `analysis_version=2.0.0`, method `paired-block-1`.

Applying the new default to these same observations is still retrospective and exploratory. Adoption does not turn the data into independent confirmation or establish a claim about future sessions. The new report preserves the original verdict and source fingerprints, records its retrospective status, and writes to a fresh root export. Historical version 1.0.0 verification continues to replay the original analyzer.

## What stays strict

Keep hard failures for incomplete observations, invalid responses, changing selected output, missing required features, broken isolation, and blocking environment conditions. Statistical methods cannot repair those defects. Keep every declared route, configuration, pass, and observed block; retain unfavorable outcomes and negative additions.

Treat control disagreement, negative individual additions, drift, and influence as **reported uncertainty and diagnostics**, rather than automatic timing vetoes. Assess comparisons separately: an uncertain comparison must not suppress an otherwise estimable comparison with another configuration. A claim covering all configurations still needs evidence for all of them.

## Why the original result remains unresolved

The original `analysis_version=1.0.0` requires every block addition to be positive and the pooled addition to exceed the largest same-block control disagreement. Both are extreme-value gates: collecting more blocks creates more opportunities to fail. Neither is a confidence interval or a calibrated test of an overall effect.

Removing those gates alone is insufficient. The original statistic divides each route's pooled added-time estimate by TSF's pooled added-time estimate, then averages the nine ratios. Repeating its exact 5,000-draw block resampling without the eligibility gates gives:

| Pass | Draws with at least one nonpositive TSF route denominator | Fraction |
| --- | ---: | ---: |
| 1 | 3,153 / 5,000 | 63.06% |
| 2 | 587 / 5,000 | 11.74% |
| 3 | 292 / 5,000 | 5.84% |

These draws were counted, not discarded or replaced. A ratio interval fitted only to favorable denominators would hide the problem. A legitimate ratio confidence set can be unbounded when its denominator is insufficiently resolved. [Franz, *Ratios: A short guide to confidence limits and proper use*](https://arxiv.org/abs/0710.2024)

The original numerical analysis and rendered report replay with their historical provenance. The public export's manifest still verifies every original artifact. A new analysis source fingerprint is recorded separately; it is not substituted into the historical report.

## Adopted approach: paired differences first

For each pass, route, and balanced schedule block:

1. Take each configuration's median of its six measured requests.
2. Take the pooled median of the twelve requests from the two WordPress-only controls in that same block.
3. Form paired differences, then average with equal block, route, and pass weights.

All 26,244 measured requests contribute to those cells. All 13,122 warmups remain validated evidence and retain their original exclusion from measured timing estimates. No trimming, winsorization, denominator clipping, route selection, or removal of negative cells is applied.

Write T for TSF's cell median, C for a comparator's cell median, and B for the cell's pooled control median:

```text
A = average(T - B)                         estimated TSF addition
G = average(C - T)                         direct TSF advantage
H = average((C - B) - 1.5 * (T - B))       margin over the 1.5x threshold
```

G and its simultaneous interval are the primary comparison; G cancels the shared control entirely. H tests the threshold without dividing. Positive A and H jointly address a **ratio of average additions** above 1.5. That ratio is descriptive, not a fitted ratio confidence interval. These quantities do not establish the old **average of per-route ratios**. Tiny per-route TSF additions no longer cause disproportionately large ratios. This is an explicit change of question, not a repair that leaves the old statistic identical.

The quantity is still estimated added HTTP response time, **not plugin CPU self-time or a multiplier on whole-page loading speed**. Fixed equal route weights describe this nine-route recipe, not a site's traffic mix. [Kalibera and Jones, *Quantifying Performance Changes with Effect Size Confidence Intervals*](https://arxiv.org/abs/2007.10899)

## Uncertainty and dependence

The primary exploratory analysis used 50,000 deterministic circular-block bootstrap draws, preserving all configurations and controls together within each route's block. Consecutive segment length is three schedule blocks. Resampling stays within each of the three pass strata. Routes ran sequentially, so the same schedule row in two routes is not a simultaneous temporal cluster. The adopted default retains this primary resampling design, with at least 50,000 draws for reference-sized analysis and the confidence level declared by the recipe. Smoke analysis retains the configured draw count and remains smoke-only.

The reference aggregate family contains 13 quantities: A, six G, and six H. The historical review's two-sided Bonferroni percentile intervals use tail probability `0.05 / (2 * 13)`; the adopted default derives that tail from the recipe's confidence level. Quantiles use linear interpolation at `(draws - 1) * probability`, and the recorded Park–Miller generator and seed make replay deterministic. The review's intervals are **approximate, nominal 95% simultaneous intervals conditional on this session**, not guaranteed coverage after method exploration. Bonferroni addresses multiplicity assuming the underlying intervals have appropriate coverage; it cannot repair a poor resampling model. [NIST, Bonferroni's method](https://itl.nist.gov/div898/handbook/prc/section4/prc473.htm)

All planned sensitivities are reported: independent blocks (length one), length six, common schedule indices across routes, and hierarchical pass resampling. Circular resampling joins the end and beginning of a route and assumes approximate stationarity; it cannot remove trends. Eighteen blocks per route/pass provide limited information about dependence. This uses fixed-length circular segments, not random-length stationary-bootstrap segments. [Politis and White, *Automatic Block-Length Selection for the Dependent Bootstrap*](https://public.econ.duke.edu/~ap172/Politis_White_2004.pdf)

There are only three fresh passes. Hierarchical resampling is a useful stress test, but its top level has only ten unordered pass-weight combinations. It is not a calibrated 95% prediction about future sessions, machines, macOS, themes, or workloads. Per-pass intervals in the JSON are ordinary diagnostic 95% intervals, not part of the aggregate simultaneous family. No method is selected because it gives the most favorable interval.

## Historical review results from the retained observations

These are the retained `paired-block-review-1` numerical findings. All values below concern the fixed nine-route HTML cohort, all three passes, WordPress 7.1.3, Twenty Twenty-Five 1.5, and PHP 8.4.27 on the recorded Windows setup. RSS, sitemaps, admin, page-builder, commerce, and media-heavy workloads are not represented.

TSF's paired estimated addition is **7.214 ms**, with primary approximate simultaneous interval **5.864–8.569 ms**.

| Configuration | Estimated addition, ms | Direct TSF advantage, ms | Primary simultaneous interval for advantage, ms | Margin over 1.5x, ms |
| --- | ---: | ---: | ---: | ---: |
| Yoast SEO | 52.043 | 44.829 | 42.950–46.785 | 41.222 |
| AIOSEO with default companions | 97.209 | 89.995 | 88.167–91.916 | 86.388 |
| AIOSEO standalone | 57.904 | 50.690 | 48.985–52.397 | 47.083 |
| Rank Math | 42.851 | 35.638 | 34.013–37.302 | 32.031 |
| SEOPress | 26.498 | 19.284 | 17.801–20.814 | 15.677 |
| Slim SEO | 20.185 | 12.971 | 11.466–14.501 | 9.364 |

All six threshold-margin intervals also lie above zero. For the closest comparator, Slim SEO, the primary margin interval is **7.407–11.362 ms**. Its ratio of average additions is a descriptive **2.80x**; this is neither a fitted ratio confidence interval nor a reproduction of the old mean-of-route-ratios claim.

| Pass | TSF estimated addition, ms | Slim SEO estimated addition, ms | Direct difference, ms |
| --- | ---: | ---: | ---: |
| 1 | 7.296 | 19.883 | 12.587 |
| 2 | 8.123 | 20.448 | 12.326 |
| 3 | 6.223 | 20.223 | 14.000 |

No single-cell deletion changes the overall TSF addition beyond **7.112–7.290 ms**, or its direct advantage over Slim beyond **12.859–13.062 ms**. No single-pass deletion changes that advantage beyond **12.456–13.293 ms**. Every route's paired TSF addition remains positive under each single-block deletion. These are influence ranges, not confidence intervals; the primary estimates retain every cell and pass.

### All planned sensitivities

Each row below has its own nominal aggregate family; no joint coverage is claimed over the choice of analysis method.

| Resampling | TSF addition interval, ms | TSF advantage over Slim interval, ms | Slim 1.5x margin interval, ms |
| --- | ---: | ---: | ---: |
| Length 3, fixed passes: primary | 5.864–8.569 | 11.466–14.501 | 7.407–11.362 |
| Length 1, fixed passes | 5.857–8.600 | 11.355–14.579 | 7.271–11.427 |
| Length 6, fixed passes | 5.998–8.442 | 11.592–14.399 | 7.532–11.237 |
| Length 3, common schedule indices across routes | 5.779–8.638 | 11.821–14.271 | 7.651–11.241 |
| Length 3, passes also resampled | 5.294–8.995 | 11.025–14.937 | 6.717–12.104 |

Every comparator's direct advantage and threshold margin remains positive across all five analyses. This supports a meaningful conditional comparison in the observed session; it does not provide independent confirmation of a broad marketing claim.

Under the adopted default, reference-sized threshold support requires positive simultaneous lower bounds for A and H in all five analyses and positive diagnostic lower bounds at the recipe's confidence level (95% for the reference recipe) for A and H in every pass. Opposing statistical decisions between passes are inconsistent; an unresolved pass makes that comparator inconclusive. Direct G intervals remain available independently of threshold qualification. Each comparator is assessed separately; an uncertain comparator does not veto another comparator. A complete-cohort claim requires support for every declared comparator. Timing diagnostics do not recreate the old global worst-block/all-positive-block vetoes. Data, output, isolation, environment, and cleanup failures remain hard blockers.

## A timing pattern that must remain visible

This is not solely a story about one bad batch. Within six-request cells, odd/even measurement sequences have a median absolute mean difference of approximately **69.4, 71.8, and 73.6 ms** across the three passes and all configurations. Residual lag-nine correlations are approximately **−0.710, −0.756, and −0.800**; lag eighteen becomes positive. These are descriptive, not independent-observation significance tests. The alternation also survives an alternative centering calculation.

That pervasive two-regime behavior has no established cause. A worker, scheduling, or collector effect is a hypothesis, not a proven explanation. Each six-request cell contains three odd and three even sequence positions; retaining complete cells respects the observed mixture. The comparison describes that mixture and does not establish results for either regime separately.

Mean control B-minus-A disagreements are only approximately **−0.379, −0.005, and −0.259 ms** by pass, despite large individual disagreements. Pooled control medians rise from approximately **154.574 to 163.012 ms** between the first and third passes. Pairing, pass stratification, influence checks, and dependence sensitivities address some uncertainty; they do not prove that environmental bias is absent. Temperature and effective-clock telemetry were unavailable.

Before a future independently approved collection, investigate that alternating pattern and record any plumbing changes. No WordPress rerun or configuration change was made for this review.

## Replay and historical implementation

The original supplementary analyzer was deliberately separate from `benchmark.php report`. Its historical entry point accepts a manifest-verified public export and writes only to a new sibling or otherwise separate directory:

```sh
php scripts/review-statistics.php --run .local/my-public-evidence --out .local/my-statistical-review
```

It performs no requests, installation, site startup, or plugin edits. It verifies original numerical replay, historical rendered output, cleanup, and the public manifest; retains the old verdict; and records the alternative method, source fingerprints, all sensitivity intervals, influence ranges, denominator failures, and dependence diagnostics in JSON and HTML. It refuses an existing output or an output inside the original evidence bundle.

The historical method identity is `paired-block-review-1`. Its regression coverage includes weighting, control cancellation, negative-cell retention, pairing, circular resampling, deterministic replay, explicit-deletion influence, and rejection of invalid evidence. Those checks verify computations and contracts; they do not validate confidence-interval coverage under the observed Windows timing regime.

For the adopted default and a full comparison from existing evidence, use:

```sh
php benchmark.php report --run .local/my-public-evidence --out .local/my-paired-evidence
php benchmark.php verify --run .local/my-paired-evidence
```

This dispatches the current version 2.0.0 `paired-block-1` analysis without WordPress requests, installation, site startup, or plugin edits. It preserves the old evidence and writes the complete new comparison, provenance, diagnostics, and sensitivities in a fresh export. Existing outputs are never overwritten. Verifying an old version 1.0.0 export still uses its historical analysis and rendered-output contract.

The adopted method uses **paired millisecond differences as the primary comparison, with uncertainty and timing diagnostics rather than timing vetoes**. Threshold contrasts support conditional verdicts for the declared observed session; descriptive ratios do not replace their uncertainty checks. The old mean-of-route-ratios analysis remains historical evidence, including its unresolved denominators and original inconclusive verdict. No WordPress collection was rerun to adopt or apply the new default.
