# Choose the workload and allow enough time

Choose the routes, plugin configurations, and sampling budget before planning a run. Offer 10, 40, or 100 iteration targets. The current reference-sized experiment took about **2 hours 24 minutes** to provision and collect on the tested Windows host, plus a separate worker preflight and other setup and cleanup work. The middle choice projects to about an hour; a smoke test can be much shorter.

These are workload choices using existing recipe fields, not new CLI presets. There are no `--iterations`, `--scope`, or `--frontend` options. Keep the exact recipe and obtain approval for its concrete plan before setup. See the [agent workflow](agent-workflow.md) and [command reference](cli.md).

## Choose the scope

There are two independent choices:

| Choice | Available workload | What the conclusion covers |
| --- | --- | --- |
| Routes | All nine reference frontend HTML routes, or selected HTML routes | Only the declared routes and fixture content |
| Configurations | All nine reference configurations, or a supported subset | Only the declared plugins and configurations |

The reference's nine routes are the blog homepage, static homepage, posts page, post, page, category, tag, search, and 404. Its nine configurations include two WordPress-only controls and seven plugin configurations; the two AIOSEO configurations are distinct. A smaller comparison still needs both controls, a comparison anchor, and at least one comparator: four configurations minimum.

**All currently implemented workloads are frontend HTML.** RSS, sitemap resources, and authenticated admin collection are not implemented and are rejected. Do not describe all nine HTML routes as testing every plugin feature or all WordPress workloads. A route subset is a limited investigation, even when it contains many requests.

Changing routes, participants, fixture content, or sampling creates a custom experiment. Preserve those differences; do not silently reduce the workload during collection. See [the method](methodology.md) for the fixture and comparison limits.

## Choose a sampling budget

In this guide, an **iteration** means one measured request per configuration and route, within each pass. Warmups are additional requests. The runner's `measurement.samples` field counts measured rounds within each balanced order, so it is not the total iteration count.

For `A` configurations, there are `O = A` balanced orders when `A` is even and `O = 2 × A` when it is odd. To meet a requested iteration target, set `samples = ceil(target / O)`. The actual count is `O × samples` per configuration, route, and pass. Complete balanced orders explain why a target of 10 becomes 18 with the reference's nine configurations.

The following choices retain all nine configurations and all nine HTML routes. The saved collection recipe requests 5,000 bootstrap draws; the default paired analyzer uses at least 50,000 for reference-sized analysis and records the effective count separately. Smoke analysis retains its configured draw count. Resampling adds offline analysis work, not HTTP requests.

| Requested iterations | Purpose | Actual measured iterations per configuration, route, and pass | `passes` | `warmups` per order | `samples` per order | Measured requests | Warmups | Total HTTP observations |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 10 | Smoke test | 18 | 1 | 1 | 1 | 1,458 | 1,458 | 2,916 |
| 40 | Limited investigation | 54 | 2 | 3 | 3 | 8,748 | 8,748 | 17,496 |
| 100 | Reference-sized repeated collection | 108 | 3 | 3 | 6 | 26,244 | 13,122 | 39,366 |

The 100 row is the published reference recipe's sampling policy. The other rows are custom choices. The 40 choice keeps the reference's three warmups per order and uses two fresh passes. Both 10 and 40 are classified as `smoke_only` by the current analysis rules; the 40 choice offers more observations, not eligibility for a broad speed claim. Use the existing `measurement.passes`, `measurement.warmups`, and `measurement.samples` fields to make these choices. Keep `seed` declared and deterministic. When changing the number of configurations, recalculate the rounded count rather than reusing the nine-configuration values.

For example, apply this fragment to a complete private copy of the recipe for the 40 choice; it is not a standalone recipe:

```json
{
  "id": "custom-40-html",
  "reference": false,
  "measurement": {
    "passes": 2,
    "warmups": 3,
    "samples": 3,
    "seed": 521,
    "bootstrap": 5000
  }
}
```

Retain the complete pinned packages, fixture inputs, routes, and comparison settings unless their changes are deliberate. Use a descriptive custom name and record the differences. A changed recipe requires a fresh plan; do not edit an approved run in place.

**No iteration count guarantees accuracy.** The smaller choices check setup and support limited investigation; neither establishes a broad comparison. The reference-sized choice provides repeated passes but can still be inconclusive. The historical version 1.0.0 analysis of the completed collection was inconclusive under its control-variation and positive-block gates. Version 2.0.0 uses paired differences and uncertainty instead; the retrospective comparison preserves that historical result and records its changed metric. More requests cannot repair biased fixtures, missing features, broken controls, or unrepresented workloads. Do not label any choice automatically accurate or definitive, or extend a run until a preferred result appears.

The [methodology](methodology.md) distinguishes current paired inference from historical timing gates. A smoke-only sampling classification and an uncertain added-time estimate are separate limitations.

## Duration evidence and projections

The [completed Windows reference collection](validation.md#complete-windows-reference-collection) on 2026-11-11 used WordPress 7.1.3, Twenty Twenty-Five 1.5, PHP 8.4.27, nginx 1.26.1, and the owned two-worker supervisor. Retained collection state records 17:38:26 to 20:02:32 UTC: **2 h 24 min 6 s**. The three recorded telemetry collection windows lasted 42 min 11 s, 43 min 25 s, and 44 min 29 s.

Those windows total **2 h 10 min 5 s**, including warmups, HTTP collection, response validation, evidence writes, context controls, and route runtime checks. The remaining **14 min 1 s** covers provisioning, setup, between-pass checks, finalization, and three 30-second idle baselines. These are elapsed runner costs, not plugin response times. The sum of saved HTTP timing intervals alone was about 2 h 7 min 32 s.

Telemetry recorded 16 logical processors and 68,624,494,592 bytes of physical RAM, approximately 63.91 GiB, on Windows AMD64. The processor model, read afterward from the same computer's Windows registry, was **AMD Ryzen 7 5800X3D 8-Core Processor**; that identification was not part of the original run telemetry. Sustained clock was not recorded. These facts do not establish an above-average computer or a speed comparison with another machine. Apple M4 performance was not evaluated; no Windows-to-M4 duration factor is available. CPU temperature, thermal pressure, effective clock, and paging-rate telemetry were unavailable.

The table below estimates **`run` collection time only**, retaining nine configurations. It uses the observed workload rate and keeps the per-pass setup allowance when routes are removed. Only the 100-iteration, nine-route cell was measured; every other cell is a projection.

| Requested iterations | One selected HTML route | Three selected HTML routes | All nine HTML routes |
| --- | --- | --- | --- |
| 10 | About 6 min | About 8 min | About 15 min |
| 40 | About 16 min | About 29 min | About 1 h 7 min |
| 100 | About 29 min | About 57 min | **2 h 24 min observed** |

Selected routes and plugins have different costs. The observed route mean HTTP times ranged from about 176 to 209 ms, and other hosts, disks, runtimes, background activity, content, and cache conditions may differ substantially. These rounded values are planning estimates, not deadlines, uncertainty intervals, or measured results for the smaller choices. Only the 100 tier has been collected on Windows. A future 10-tier Mac run is useful for setup, noise, and descriptive comparisons, but its different sampling policy is not a matched reproduction of the 100-tier Windows run. Record both workloads explicitly.

### Recalculate the request budget

Use the actual recipe values:

```text
P = passes; R = routes; A = configurations
O = A when A is even, otherwise 2 × A
S = samples per order; W = warmups per order

measured requests = P × R × A × O × S
warmup requests   = P × R × A × O × W
total requests    = P × R × A × O × (S + W)
```

At unchanged sampling, route collection scales approximately with `R / 9`; configuration request count scales with `A × O / 162`. The latter is not simply the configuration count: four configurations have four orders, while five have ten. If retaining an iteration target, also recalculate `S = ceil(target / O)`. Fewer configurations change setup and average request cost, so these request-count multipliers alone do not establish an elapsed duration.

The duration table uses this rough calibration, in minutes:

```text
14.017 × (P / 3)
+ 130.083 × (P / 3) × (R / 9)
  × (A × O / 162) × ((S + W) / 9)
```

The setup term was observed for nine configurations. The variable term also includes some route checks that do not grow with samples, so the formula is approximate even on the same host. Setup, worker replacement, and long-run resource behavior need separate qualification when the workload changes materially.

### Allow additional time

The owned Windows supervisor's separate preflight took approximately **9 min 20 s** in the retained run, based on its artifact timestamps. It collected 2,200 recycling responses, 1,200 measured calibration responses, and 240 warmups: 3,640 diagnostic requests excluded from plugin samples. Treat this as a separate qualification allowance for the exact approved plan, not a cost that shrinks with a smaller plugin sample budget. Other native stacks use their own applicable qualification.

Downloads, installation if needed, first-site permission checks, isolation, configuration changes, approval, cleanup, reporting, verification, and export are additional. Their duration was not established by this calibration. Cached packages can save download time, but setup and fresh fixture passes still run. Do not promise a first-time completion time from the collection table.

## Use the existing commands and qualify the native setup

Begin with read-only discovery using an existing runtime:

```sh
php benchmark.php help
php benchmark.php doctor
```

For a custom choice, save the complete edited recipe and dedicated target privately, then create a new proposal:

```sh
php benchmark.php plan --recipe .local/my-recipe.json --target .local/target.json --out .local/my-run
```

These example paths are repository-relative. Private configuration and run files must remain outside the served site. `plan` saves a proposal without setting up WordPress. Follow the [approved command sequence](cli.md#normal-order) for approval, package preparation, enforced isolation, any required preflight, collection, cleanup, report, verification, and export. Do not manufacture approval or isolation receipts to make commands proceed.

Offer Local's official CLI or the user's existing native stack. Inspect the existing configuration first and respect deliberate choices. Before approval, explain the current configuration's limits and propose useful site-specific improvements, such as sufficient shared OPcache capacity, inactive profiling, or suitable worker management. Qualify effective HTTP behavior and preserve the actual chosen settings in the evidence; do not silently tune the host or present the tested host's 2 GiB OPcache allocation as a universal requirement.

For new Local Windows plans, prefer the [qualified owned supervisor](cli.md#owned-windows-worker-supervisor) when the installed binaries are available, and include its processes and private ports in the proposal. It preserves 500-request recycling and does not patch Local files. Existing native stacks and macOS retain their own worker management. Do not raise the CGI lifetime limit or modify Local to make a collection finish.

Local 10.2+ supplies the official CLI, disabled by default; inspect the installed version's help and bundled tools before choosing commands. CLI enablement and site setup need approval. Some Local CLI commands start the application, so do not invoke them merely for installation discovery. On the tested Local 10.2.0 for Windows, WP-CLI arguments must be separate after `--`; quoting the entire command failed. Quote individual paths containing spaces and check the installed shell. The [Local entry points](cli.md#local-entry-points) describe the supported invocation and Site Shell fallback; do not install a separate bridge or runtime automatically.

Complete the manual first-site check and hand control back for OS permission prompts as described in the [agent workflow](agent-workflow.md). Explain the chosen workload, estimated collection time, separate qualification, and unknown setup time before asking for approval. Successful collection remains distinct from a supported comparative conclusion.
