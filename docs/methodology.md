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

Nine arms use eighteen balanced request orders. Each arm appears equally often at each position, and every ordered predecessor pair occurs equally often. Each order has three warmups and six measured requests per arm and route. That produces 8,748 measured requests per pass. Three separately initialized passes provide repeatability evidence.

Do not treat every serial request as an independent experiment. Preserve blocks and shared controls in uncertainty analysis. Extra samples do not repair broken controls, biased fixtures, changing settings, or missing features.

## Added-time ratio

For each route:

```text
core median = median of the two matched WordPress-only controls
estimated added time = plugin median HTTP time - core median
route ratio = competitor estimated added time / TSF estimated added time
reference score = arithmetic mean of the nine route ratios
```

A 1.5x score concerns estimated plugin-added response time under those conditions. It does not mean the whole WordPress page loads 1.5x faster. Baseline subtraction is also not a measurement of exclusive plugin CPU self-time.

Show absolute response milliseconds, estimated added milliseconds, and whole-response ratios alongside this score. Ratios with a denominator near control noise cannot establish a precise speed claim. An unresolved route makes the complete-cohort claim inconclusive; it is not silently omitted.

The earlier archived 1.52x comparison with Slim SEO used **TSF 5.2.0**. It is not a matched result for TSF 5.2.1. A new reference result requires fresh matched collection with the released 5.2.1 package and all declared competitors.

## Repeatability and evidence replay

- **Evidence replay:** the same observations, analysis version, and random seed regenerate the same numerical result without WordPress.
- **Repeated experiment:** fresh collection follows the same recipe. Milliseconds may differ. Show each pass, control variation, uncertainty, order effects, and whether conclusions agree.

Never select only the fastest pass. Disagreement is a result to investigate. Preserve all raw samples and explain interruption or failure. Do not selectively retry requests or keep measuring until a desired threshold is reached.

Small runs can check setup or answer a narrow question. Label them as smoke tests or limited investigations; discourage using them to make broad claims about all plugins or workloads. The full recipe and repeated passes are the default.

## Challenge the experiment

Users may change versions, themes, content, route weights, plugin settings, cache conditions, sampling, or aggregation. Keep a record of the actual recipe before collection, show differences from the suggested reference, and explain what the changed experiment can answer.

A custom experiment is not automatically less legitimate. Evaluate its controls, scope, evidence, and repeatability. A later alternative analysis should have its own identity and preserve the original result.

The report must allow **supported in this run**, **inconclusive**, and **not reproduced** outcomes. No expected winner is an acceptance test for the runner.
