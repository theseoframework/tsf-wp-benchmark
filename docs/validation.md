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

## Compact report presentation

New reports use a compact overview with two comparison charts, visible qualifications, and deferred detailed sections. Detailed tables are created when their disclosure opens and released when it closes. Per-route disclosures load independently. An expanded HTML fallback remains available without JavaScript.

An isolated headless Chrome check on Windows measured 183 initial elements and no detailed table rows, versus 4,248 elements and 818 rows in the preserved expanded report. Opening one route produced ten table rows; closing its parent released the added DOM. Desktop and mobile layouts had no horizontal overflow, runtime exceptions, or external report requests. The charts and expanded fallback remained available with JavaScript disabled. No user browser windows or Edge were controlled.

The saved-summary render command copied the numerical summary byte-for-byte, and its expanded fallback matched the original report byte-for-byte. It did not recalculate statistics or run WordPress. All 113 existing regression cases and PHP coding-standard checks pass; no new test cases were added. These are presentation checks, not new plugin performance measurements.

## Native Apple M4 Mac validation

The native adapter completed two full collections on a Mac Mini with an Apple M4, 16 GiB RAM and macOS 26.5.2. Existing Local PHP 8.4.27 and nginx 1.26.1 binaries served WordPress 7.1.3 with Twenty Twenty-Five 1.5 in an owned loopback-only FPM pool: two workers, normal 500-request recycling, shared OPcache at 2 GiB and 100,000 cached-file slots. Local's installation files and the existing site's content and SEO settings were unchanged. The Mac's official Local CLI path was not used or validated.

Each collection retained all nine configurations, all nine HTML routes and three fresh passes: 26,244 measured requests and 13,122 warmups, with 108 measured requests per configuration, route and pass. The first collection took 29 min 4 s; its CPU/thermal collector failed to start, and its report retains that limitation. That complete dataset was preserved before the monitored repeat; the two collections were not pooled or substituted for each other.

The failure exposed the installed setuid `top` binary being denied by inherited process isolation. The collector now falls back to the existing regular `iostat` and `vm_stat` binaries without elevation or permission changes. A live startup check returned actual CPU readings and normal shutdown before the repeat began. The existing suite passes all 113 cases on the Mac and Windows. Two existing Mac test fixtures were corrected for JXA entry-point leakage and compiled-in mysqli; the existing parser case also checks the fallback's CPU and memory semantics.

The monitored collection took **29 min 10 s**, followed by analysis, replay, export and cleanup. All three measured windows have CPU and OS thermal-pressure coverage: 784 readings, CPU utilization from 2% to 23% of host capacity, a 12% median in each pass, OS thermal state `nominal`, and Low Power Mode false. CPU die temperature, effective clock, exact available memory and interval swap deltas remain unavailable. Free plus speculative pages are a separately labelled memory lower bound; they do not establish all reclaimable memory or the absence of memory pressure.

A separate diagnostic retained twelve alternating monitoring-off/on pairs on the tiny token-protected FPM handler: 1,200 measured requests and 240 warmups. Its median paired change was +0.009677 ms, and its median on/off ratio was 1.007891. This is control-handler evidence, not a WordPress/plugin no-impact finding, and no timing correction was applied. It remains separate from the frozen run export, so the runner's own integrated-calibration flag remains false.

The monitored session estimates TSF's mean added response time at **0.915616 ms** and Slim SEO's at **2.001969 ms**, a descriptive ratio of 2.186472. The direct difference is 1.086353 ms; its primary simultaneous interval is 1.041344 to 1.130575 ms. All six comparator threshold checks support 1.5 across the declared dependence/pass models, conditional on this session. The method and recipe were fixed before the Mac observations; this is new data from the same operator, rather than independent-operator confirmation or a claim about whole-page speed.

Native report verification passed. The complete export also replayed exactly on Windows with `php -d serialize_precision=17 benchmark.php verify --run /path/to/public-export`. The Mac retained Local's serialization precision of 17 and PCRE JIT disabled; do not assume every runtime setting matches the Windows collection. No added-response comparison is a CPU benchmark or a universal Windows-to-Mac speed factor.

Both batches' 54 owned fixture trees and all owned tables were removed, with normal FPM/nginx shutdown and both owned ports verified free. The temporary helper and stage were removed only after restoring every archived file byte-for-byte. Original content and critical/SEO option hashes matched. Both complete datasets, failed setup attempts and calibration evidence remain retained locally.
## Remaining validation

1. The macOS official Local CLI path, Intel Macs and other Mac hardware using the [Mac checklist](macos-validation.md). The M4 native FPM checks above do not certify these paths.
2. An independent operator's fresh experiment. Replaying saved evidence verifies analysis, not independent collection.

No new plugin performance claim follows from the synthetic tests. The historical 1.52× result used TSF 5.2.0 and is separate from this repository's completed Windows collection with TSF 5.2.1. The retrospective Windows result and fresh Mac collections are recorded above; an independent operator's confirmation remains pending.

The [published CI run](https://github.com/theseoframework/tsf-wp-benchmark/actions/runs/43486934444) exercised Windows, macOS and Linux with PHP 8.2 and 8.4. Windows and Linux passed; both Mac jobs reported 112 passing cases and one failing cleanup-host fixture case. The committed fixture collided with compiled-in mysqli. The local correction and deterministic regression are documented in [the bugfix ledger](bugfixes.md#macos-ci-cleanup-fixture). A fresh macOS CI run is required to confirm the corrected published source; the earlier native M4 checks do not turn that failing commit green.

## Mac setup feedback and default network controls

The supplied Mac setup transcript informed the guidance changes; it omitted command output and token counters, and did not complete timed collection. The operator later supplied 18 numeric usage rows and one Free row with unknown tokens. Their [recorded setup/discussion usage](agent-costs.md#recorded-grok-47-high-mac-setup-and-discussion) is preliminary agent-cost evidence, not a completed performance reproduction or a paid invoice. Setup now permits approved first-site creation through the Mac Local CLI, warns that every new site may request OS authentication or file access, and retains the Windows manual first-site/UAC handoff. This guidance has been reviewed against the official CLI documentation, not certified by a new live CLI setup.

New plans default to WordPress HTTP/mail guards, automatic-update and cron suppression, and private refused-call metadata. Raw cURL/socket traffic is permitted and disclosed. No host firewall or separate isolation receipt is required by default; existing stronger plans retain their recorded policy and verification requirements. The historical Windows and M4 timing datasets used external restrictions and remain separate from the new policy. No new live collection under the default policy was performed.

All **125 offline regression cases pass on Windows PHP 8.4.4**. This includes ten new network-control cases and two new documentation/cost cases, plus deterministic coverage of the Mac mysqli collision within the existing cleanup case. Network tests use synthetic WordPress hooks and socket doubles, not real outside connections. Checks cover effective HTTP/mail/update/cron blocks, private-log failure and export exclusion, valid third-party filter argument shapes, policy drift, legacy receipts, and the absence of default raw TCP canaries. Full PHP_CodeSniffer checks pass without warnings or errors, and all 59 PHP files pass syntax checks.

The original Windows version 1.0.0 public export and the monitored M4 version 2.0.0 export still verify with their historical results and source identities preserved. The M4 compact and expanded presentation checks also pass with an unchanged renderer fingerprint. The current replay-runner fingerprint differs from the historical collection runner, as expected after code changes; it does not replace that identity. These checks do not install WordPress, certify the new default on macOS, or establish a new plugin timing result. No tested plugin code, host network configuration, installation, or Git state was changed.

The later operator-supplied Grok usage rows reconcile to 22,648,958 known tokens across 18 numeric rows, with one Free row retaining unknown token counts. Independent arithmetic and the existing documentation regression case verify category totals, conditional Cursor price scenarios, separate eligible-pool percentages and the output-only effort estimate. All eight documentation cases and targeted PHP_CodeSniffer checks pass. This follow-up changed documentation, aggregate usage data and that existing test case only; it did not rerun WordPress or the complete runtime suite.
