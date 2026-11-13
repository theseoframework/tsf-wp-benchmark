# What the benchmark measures

The purpose is to make the workload, measurements, and analysis inspectable. A favorable result for TSF is not a prerequisite for a successful experiment.

## Suggested reference recipe

The suggested recipe uses pinned public packages and deterministic fixture content. Its nine HTML routes are the blog homepage, static homepage, posts page, single post, single page, category, tag, search, and 404.

The arms are TSF, Yoast SEO, Rank Math, SEOPress, Slim SEO, AIOSEO with default companions, AIOSEO standalone, and two WordPress-only controls. AIOSEO's two configurations are identified separately; they are not two vendors that may be double-weighted in a vendor average.

Complete setup and indexing, opt out of telemetry, and validate the declared active features. Preserve built-in metadata caching. Prevent full-page caching from bypassing the work being measured. Different SEO plugins do not need byte-identical output, but missing or disabled functionality must not pass as a speed improvement.

RSS, sitemap resources, and admin screens belong to separate workload cohorts. They do not contribute to the frontend HTML aggregate. Version 0.1 implements only frontend HTML collection; the runner rejects these other cohorts rather than presenting them as supported.

## Fixture workload

The recipe declares its fixture inputs rather than relying on an existing site's content. The reference starts with 120 posts, 30 pages, one shared benchmark category and tag, 12 repetitions of the declared paragraph content per entry, and 10 items per archive page. Home and posts pages come from that same declared dataset. Dates and content are deterministic.

This is an ordinary synthetic HTML workload, not a page-builder, commerce, or media-heavy website. No uploaded logo or image attachment is provisioned, so image lookup and image-dependent schema paths are not represented fully. The shared content and taxonomy are visible choices that users may reasonably question. Change `fixtures`, the route selection, or the theme for a different workload and preserve the differences in the resulting experiment. A large number of short requests does not automatically represent a content-heavy page.

The configurable fixture fields and setup bounds are listed in [the command reference](cli.md). Counts and HTML size limits constrain setup resources; they are not a substitute for controls or repeatability.

## Measurements

The collector runs on the server host and makes serial HTTP requests through loopback. A monotonic timer measures each request. Provisioning, CLI startup, output validation, telemetry parsing, and report generation are outside the request interval.

Reference measurements have profiling disabled and warmed OPcache shared memory. Record the actual HTTP configuration and the connection-reuse policy. Use the same policy for every arm.

### Network policy

New targets default to `wordpress-http`: each fixture blocks WordPress HTTP and mail before tested plugins load, disables automatic updates, and privately logs refused calls. It requires no host firewall, network disconnection, or process sandbox. Raw cURL and socket calls are outside those hooks and are permitted under this policy. Keep that limitation in the report; diagnose suspected bypasses separately. Do not export refused URLs, which may contain credentials or site identifiers.

Stronger `offline` or `external-firewall` policies are optional, require approval for their concrete setup, and retain the operator's verification and worker checks. Users may choose an isolated stack, including a container, instead of native processes; record its resource limits and environment differences. Do not install Docker or change a firewall merely to run the default experiment. Record the policy in the approved plan and evidence. Historical targets without an explicit policy retain their original external-isolation requirement; absence is not permission to relax an old plan. Compare sessions with different policies as different environment conditions, even when the package recipe is unchanged.

Any outbound waiting inside a measured HTTP response is already in its elapsed time. Do not add an invented per-attempt penalty or subtract observed waiting afterward. Setup and separate background activity are outside that interval, although background work can affect the host. A blocked request measures plugin behavior under the declared block, not its behavior with a live remote service.

Guard filtering and refused-call logging inside a measured response also contribute to that time. Apply the same controls to every configuration and retain their scope in the report; this is not a measurement of normal internet-connected service latency. Guard probes run outside the timed intervals.

Nine arms use eighteen balanced request orders. Each arm appears equally often at each position, and every ordered predecessor pair occurs equally often. Each order has three warmups and six measured requests per arm and route. That produces 8,748 measured requests per pass. Three separately initialized passes provide repeatability evidence.

Do not treat every serial request as an independent experiment. Preserve blocks and shared controls in uncertainty analysis. Extra samples do not repair broken controls, biased fixtures, changing settings, or missing features.

## Paired comparisons: the default analysis

The default is `analysis_version=2.0.0`, method `paired-block-1`. It was deliberately adopted after the exploratory review of the completed Windows collection. Historical version 1.0.0 reports retain their original method and verdict when verified; adopting a new method does not rewrite that evidence.

For each pass, route, and balanced schedule block, take each configuration's median measured request time and the pooled median of the two matched WordPress-only controls. Write T for the comparison anchor's median, C for a comparator's median, and B for the pooled control median:

```text
A = average(T - B)                                      estimated anchor addition
G = average(C - T)                                      direct anchor advantage
H = average((C - B) - threshold * (T - B))              threshold margin
```

Average the cells with equal block, route, and pass weights. Keep every observed cell, including negative additions. The reference anchor is TSF and its threshold is 1.5. G, with its simultaneous confidence interval, is the primary comparison; it cancels the shared control entirely. H evaluates the declared threshold without dividing by a noisy addition. Show absolute response milliseconds, estimated added milliseconds, whole-response ratios, control disagreement, dependence, and influence alongside these comparisons.

The ratio of average comparator and anchor additions is descriptive. It differs from version 1.0.0's average of per-route ratios, where a tiny route denominator can dominate the score. Neither quantity measures exclusive plugin CPU self-time or a multiplier on whole-page loading speed.

### Uncertainty, verdicts, and scope

The primary analysis uses deterministic circular-block bootstrap resampling with segment length three, preserving configurations and controls together and keeping the observed passes fixed. Equal schedule row numbers in sequential routes are not simultaneous temporal clusters. Report all five analyses: this primary method, independent blocks (length one), length six, common schedule indices across routes, and hierarchical pass resampling. No sensitivity is selected because it is favorable.

Reference-sized analysis uses at least 50,000 draws; smoke analysis retains the recipe's configured draw count. The recipe's confidence level determines the two-sided Bonferroni percentile intervals. The reference aggregate family contains 13 quantities: one A, six G, and six H. Each sensitivity has its own nominal simultaneous family; there is no joint coverage claim over the choice of analysis. These are approximate intervals conditional on the observed session. Eighteen blocks per route/pass limit dependence estimation, and the hierarchical sensitivity has only three passes to resample. It cannot establish calibrated uncertainty for future sessions, machines, or workloads.

Reference-sized threshold support requires positive simultaneous lower bounds for A and H across all five analyses, plus positive diagnostic lower bounds at the recipe's confidence level (95% for the reference recipe) for A and H in every pass. Opposing statistical decisions between passes are inconsistent; an unresolved pass makes that comparator inconclusive. Direct G intervals remain available independently of threshold qualification. Interpret a supported threshold verdict only as conditional evidence for this observed session. An uncertain comparator does not veto another comparator's result. A claim covering all comparators requires support for every declared comparator. The 10- and 40-iteration choices remain smoke-only; the 100-iteration choice is reference-sized, not automatically accurate.

Incomplete observations, invalid responses, changing selected output, missing required features, failure of the declared network policy, blocking environment conditions, and unverified cleanup remain hard failures. Reachable raw sockets are a disclosed limitation under `wordpress-http`, not proof that its WordPress hooks failed. Control disagreement, individual negative additions, timing drift, and influence remain visible diagnostics and uncertainty inputs. They no longer trigger version 1.0.0's global worst-block or all-positive-block timing vetoes. Stable output fingerprints do not guarantee stable timing.

### Historical version 1.0.0 analysis

The original method computed each route's plugin median minus its pooled WordPress-only median, divided the comparator addition by the anchor addition, then averaged the route ratios. Before bootstrapping, it required route additions above the largest same-block control disagreement and positive additions in every block. Those extreme-value gates could let one unusual block veto a comparison; they were neither confidence intervals nor calibrated tests of an overall effect.

For example, the first Windows pass's blog homepage had a WordPress-only median of **163.858 ms** and TSF median of **169.877 ms**, an estimated addition of **6.020 ms**. The largest control disagreement was **24.234 ms**, and two blocks had negative additions. All 27 TSF route/pass estimates were positive, but all failed the old control-variation guard; 26 also failed the old all-positive-block guard. The original complete-cohort verdict remains **inconclusive** under that version. Removing the gates alone does not solve unstable ratio denominators, as the [statistical review](statistical-review.md) shows.

The earlier archived 1.52x comparison with Slim SEO used **TSF 5.2.0**. It is not a matched result for TSF 5.2.1. The completed Windows collection used released TSF 5.2.1 and all declared competitors; its paired reanalysis is recorded separately. Independent confirmation still requires a fresh matched collection.

## Repeatability and evidence replay

- **Evidence replay:** the same observations, analysis version, and random seed regenerate the same numerical result without WordPress.
- **Repeated experiment:** fresh collection follows the same recipe. Milliseconds may differ. Show each pass, control variation, uncertainty, order effects, and whether conclusions agree.

Never select only the fastest pass. Disagreement is a result to investigate. Preserve all raw samples and explain interruption or failure. Do not selectively retry requests or keep measuring until a desired threshold is reached.

Small runs can check setup or answer a narrow question. Label them as smoke tests or limited investigations; discourage using them to make broad claims about all plugins or workloads. The full recipe and repeated passes are the default.

## Challenge the experiment

Users may change versions, themes, content, route weights, plugin settings, cache conditions, sampling, or aggregation. Keep a record of the actual recipe before collection, show differences from the suggested reference, and explain what the changed experiment can answer.

A custom experiment is not automatically less legitimate. Evaluate its controls, scope, evidence, and repeatability. A later alternative analysis should have its own identity and preserve the original result.

The report must allow **supported in this run**, **inconclusive**, and **not reproduced** outcomes. No expected winner is an acceptance test for the runner.

## Adoption and retrospective evidence

The [statistical review](statistical-review.md) motivated the approved, versioned default change. Reanalysis of those existing Windows observations remains retrospective and exploratory because the method was chosen after inspecting them. It is not independent confirmation, even when every sensitivity and pass check supports a conditional comparison. The pervasive alternating timing pattern remains unexplained.

Use `php benchmark.php report --run OLD --out NEW` to create the current analysis in a fresh evidence directory. Preserve the original summary, rendered report, observations, export, and verdict. The schema 2 root summary's `analysis_context` records the source analysis version and verdict, source summary and public-manifest hashes, retrospective status, and current default version; collection and analysis source fingerprints remain distinct. `verify` dispatches by the saved analysis version, so historical version 1.0.0 evidence still replays its historical method and provenance, with the current replay fingerprint recorded separately.
