# Validation status

## Windows, PHP 8.4.4

Checked on 2026-11-11 without installing software or creating a WordPress site:

- The regression suite passes: protocol, statistics, instructions, plugin setup contracts, configurable fixtures, consent, filesystem boundaries, archive extraction, cleanup evidence, privacy, and exact report replay.
- A real synthetic loopback HTTP server verifies timing, connection targeting, fresh-response markers, and refusal to follow redirects. It is not WordPress performance evidence.
- Native Windows telemetry produced CPU, memory, and power readings and stopped cleanly. Temperature availability was reported accurately. Collector startup is excluded through the premeasurement idle window.
- All PHP files pass syntax checks. The Windows and macOS telemetry scripts pass their available syntax checks.
- A full-size synthetic analysis with 39,366 observations and 5,000 bootstrap draws completed under a 128 MiB limit: about 13 seconds and 42 MiB peak memory on this machine. These numbers describe the analysis engine, not plugin performance.
- Public export was replayed independently and tested for accidental inclusion of private configuration and credentials.
- A full repository review added regressions for concurrent commands, atomic writes, private recipe identity, malformed archives, plugin setup, cleanup parsing, and telemetry. Unsuppressed PHP warnings and notices now fail the regression suite. See the [review ledger](review.md).
- The final integrity pass exercised native Windows junctions, corrupt ZIP entries, replaced ownership paths, malformed cleanup receipts, and nested evidence fingerprints. Recovery files and unrelated resources remain intact in these adversarial cases.
- PHP_CodeSniffer 3.13.6, already installed for VS Code, checks all public PHP with the repository's TSF-based ruleset. The PHP target is 8.2+, and CLI/test exceptions are limited to their documented diagnostic codes and paths. The earlier manual formatting checks did not replace this complete PHPCS run.

## Windows Local setup smoke check

Local 10.2.0's official CLI created and started a dedicated disposable site using PHP 8.4.27. Target export through WP-CLI 2.12.0 succeeded with separate command arguments; the fully quoted command failed. A PHP capability script also ran successfully from a path containing spaces.

A temporary loopback-only HTTP probe reported PHP 8.4.27, the required extensions, active OPcache shared memory, and no effective Xdebug modes. The probe was removed afterward. The dedicated site's PHP template was backed up and its OPcache increased from 128 MiB to 2 GiB, with 64 MiB of interned strings and 100,000 requested cached-file slots. HTTP verified these settings after stopping and starting only that site. The subsequent complete collection below verified capacity after warming the actual fixtures.

The direct CLI Imagick DLL startup warning was resolved by adding Local's bundled PHP, ImageMagick, and Ghostscript directories to the benchmark process's PATH. The system PATH was not changed. CLI startup then completed cleanly with Imagick and mysqli loaded. Local's existing PCRE JIT setting remains disabled and PHP's memory limit remains 256 MiB; both conditions must be recorded for comparisons.

Local's `serialize_precision=17` made JSON fingerprints differ from the other PHP runtime's default. For collection, the dedicated site was configured to use PHP's native `-1` default so the planned run could be verified with either available runtime. Replay on other environments must preserve the declared serialization setting too. The original site template was restored after collection.

The operator reported that OS prompts were inaccessible during computer control and dismissed the Windows Firewall prompt with Cancel. Loopback HTTP and the official CLI remained usable. First-site setup now requires a manual permission check and a handoff for later OS prompts. These initial setup checks are not plugin performance results; subsequent live qualification is described below.

## Owned Windows supervisor qualification

The benchmark-owned nginx/PHP supervisor passed real WordPress 7.1.3 qualification on Local's installed nginx 1.26.1 and PHP 8.4.27 binaries. No Local installation or configuration files were changed by the supervisor. Inbox Windows PowerShell 5.1 controlled the owned process job.

- All 2,200 recycling-test responses passed validation, without retries or HTTP failures. Six PHP worker PIDs were observed in the fixed two-worker pool, with the 500-request retirement setting verified over HTTP.
- Shared OPcache remained enabled across recycling, with no full cache, restart, or change to its start time.
- Twelve alternating monitoring-off/on pairs provided 1,200 measured requests and 240 warmups, separate from plugin samples. The earlier qualified runner's median paired on/off ratio was 1.0101 (about +1.0%); seven pairs increased and five decreased. After the live setup corrections, the final frozen runner repeated qualification: 0.9886 (about -1.1%), five pairs increased and seven decreased. Both complete calibration datasets are retained. This variation does not prove zero overhead or provide a precise correction factor for plugin timings.
- CPU, memory, and power observations were available. CPU die temperature, thermal pressure, effective clock, and paging-rate providers were unavailable and remain labelled unknown.
- The preflight's independently owned WordPress files and tables were removed, and its server job stopped without forced termination.
- The final offline suite passed 102 checks, including kill-on-close descendant ownership and preservation of an unrelated process under both installed PowerShell versions. Native startup and automatic shutdown also passed in a non-ASCII operation path under inbox PowerShell. PHPCS passed without errors or warnings.

A separate live diagnostic completed every declared plugin's native setup and all nine HTML routes: both controls, TSF, Yoast, both AIOSEO variants, Rank Math, SEOPress, and Slim SEO. All nine diagnostic fixture trees and table prefixes were removed and its server job stopped normally. No diagnostic request is reused as a plugin timing sample. The integration defects and regression coverage are recorded in [the bugfix ledger](bugfixes.md).

These checks qualify worker management, not a plugin ranking. The complete plugin collection is a separate experiment. Failed integration attempts, native diagnostics, calibration observations, and the exact collection source are retained privately.

## Complete Windows reference collection

The final frozen runner completed the declared reference workload on real WordPress 7.1.3, Twenty Twenty-Five 1.5, PHP 8.4.27, and nginx 1.26.1: three fresh passes, nine configurations, and nine HTML routes. All 26,244 measured requests and 13,122 warmups were retained. No response-validation failures or environment blockers were recorded, and the owned server job stopped normally without forced termination. No timed request was selectively retried.

Shared OPcache remained enabled without full-cache or restart conditions. After warming the third pass, about 1,147 MiB (1.12 GiB) was used out of the approved 2 GiB allocation. This is the tested host's configuration, not a blanket recommendation for other computers. Xdebug's effective modes remained off. RSS, sitemaps, and admin screens are outside this HTML recipe.

The native Local CGI failure and every failed setup attempt remain separate from this completed collection. Private evidence retains raw observations, monitoring, runtime probes, setup receipts, source maps, exact verified collection-source archives, and failure logs. Collection success establishes that the runner completed its workload; comparative timing conclusions require the report's controls, uncertainty, and repeated-pass checks.

Ownership-verified cleanup removed all 27 fixture trees and left zero owned tables. The dedicated shell site was stopped, its earlier PHP template tuning was restored byte-for-byte, and the two temporary PHP outbound rules were removed. Local's launcher retained its original hash. No software was installed for the supervisor, and no Git commands were used.

The generated report and 337-file public export replayed exactly under the installed PHP 8.4.27 and 8.4.4 runtimes. The actual export was also reviewed for private artifact names, machine paths, and every fixture authorization secret, with no findings.

The historical `analysis_version=1.0.0` comparative verdict was **inconclusive**. Across the 27 route/pass combinations, observed control variation was about 14–37 ms, exceeding TSF's estimated added time of about 0.7–15.3 ms and failing the old timing gates. Favorable point estimates did not establish the old complete-cohort 1.5× claim. That verdict and all raw observations remain preserved; the experiment was not extended or selectively retried.

## Exploratory statistical review

The retained Windows observations were reanalyzed offline using the separately identified `paired-block-review-1` method. The original summary, report, raw observations, and 337-file public export were preserved. Original numerical and historical rendered-output replay, manifest integrity, and cleanup checks pass; the new source fingerprint is recorded separately.

The [review](statistical-review.md) records why worst-block/all-positive timing gates are too absolute, why the old per-route ratio still has unresolved bootstrap denominators, and which paired differences remain estimable. It retains all configurations and routes, reports every planned dependence/pass sensitivity, and documents pervasive alternating request timings without assigning a speculative cause. Its historical `paired-block-review-1` findings remain exploratory and do not supply independent confirmation.

At the time of that review, the offline suite passed 113 checks, including nine supplementary-analysis regressions, and coding-standard checks passed for the PHP runner, source, scripts, and tests. No WordPress site was launched or changed for the statistical review. Those checks verify computations and contracts, not the confidence intervals' coverage under the observed timing regime.

## Paired default and retrospective reanalysis

Following explicit approval, the default is now `analysis_version=2.0.0`, method `paired-block-1`. It uses equally weighted block medians across routes and passes, keeps negative cells, and makes direct paired millisecond differences and simultaneous intervals primary. The reference-sized analysis uses at least 50,000 draws, the recipe's confidence level, all five resampling analyses, and per-pass checks. Threshold support is conditional on this observed session. The 10- and 40-iteration workloads retain their smoke-only classification.

`php benchmark.php report --run OLD --out NEW` generates the complete new comparison offline in a fresh root export. Its schema 2 summary records retrospective status, the original analysis version and verdict, source-summary/public-manifest hashes, and collection/analysis source fingerprints. The original version 1.0.0 summary, report, raw observations, and 337-file export remain unchanged. `verify` continues to replay that historical version with its original provenance, separately recording the current replay fingerprint.

Applying the adopted method to the completed Windows observations remains retrospective and exploratory. Preserving all sensitivities and passing their numerical checks does not establish calibrated future-session coverage, explain the alternating timing regime, or provide independent confirmation. No new WordPress performance collection or software installation is part of this reanalysis. See [the statistical review](statistical-review.md) for the retained numerical findings and caveats.

## Adopted default comparison replay

The complete nine-route Windows observations were regenerated with the adopted version 2.0.0 default. All six comparator configurations satisfy the declared 1.5x added-time threshold under all five resampling specifications and the per-pass checks, conditionally on this observed session. TSF's paired estimated addition is 7.214 ms; Slim SEO's is 20.185 ms. This is retrospective analysis of the same collection, not independent confirmation, exclusive CPU time, or a whole-page speed multiplier. The historical version 1.0.0 inconclusive result and original export remain unchanged and replay successfully.

All 113 existing regression cases pass, with no new cases added. One existing historical timing-gate case explicitly uses the version 1 analyzer. PHP coding-standard checks pass. The fresh public export has 338 files including its manifest; every packaged file was verified restorable byte-for-byte. A separate 32-file analysis source archive identifies the frozen implementation. Numerical replay, final rendered-output equivalence, both public manifests, and the original ZIP fingerprint were verified. No WordPress collection, TSF change, installation, or Git command was performed.

## Remaining validation

1. macOS execution using the [Mac checklist](macos-validation.md). Windows checks do not certify this path.
2. An independent operator's fresh experiment. Replaying saved evidence verifies analysis, not independent collection.

No new plugin performance claim follows from the synthetic tests. The historical 1.52× result used TSF 5.2.0; this repository's new portable recipe must establish its own result.

The provided CI matrix covers Windows, macOS, and Linux with PHP 8.2 and 8.4. It has not run merely because its workflow file exists.
