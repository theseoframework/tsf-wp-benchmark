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

A temporary loopback-only HTTP probe reported PHP 8.4.27, the required extensions, active OPcache shared memory, and no effective Xdebug modes. The probe was removed afterward. The dedicated site's PHP template was backed up and its OPcache increased from 128 MiB to 2 GiB, with 64 MiB of interned strings and 100,000 requested cached-file slots. HTTP verified these settings after stopping and starting only that site. Capacity still needs checking after warming the actual fixtures.

The direct CLI Imagick DLL startup warning was resolved by adding Local's bundled PHP, ImageMagick, and Ghostscript directories to the benchmark process's PATH. The system PATH was not changed. CLI startup then completed cleanly with Imagick and mysqli loaded. Local's existing PCRE JIT setting remains disabled and PHP's memory limit remains 256 MiB; both conditions must be recorded for comparisons.

Local's `serialize_precision=17` made JSON fingerprints differ from the other PHP runtime's default. The dedicated site now uses PHP's native `-1` default so the planned run can be verified with either available runtime. Replay on other environments must preserve the declared serialization setting too.

The operator reported that OS prompts were inaccessible during computer control and dismissed the Windows Firewall prompt with Cancel. Loopback HTTP and the official CLI remained usable. First-site setup now requires a manual permission check and a handoff for later OS prompts. These setup checks are not plugin performance results; live plugin qualification, complete collection, and cleanup remain untested.

## Still required before publishing reference results

1. An approved real WordPress run through Local's official CLI or an existing native stack, including the plugins' live setup APIs and cleanup.
2. macOS execution using the [Mac checklist](macos-validation.md). Windows checks do not certify this path.
3. An A/A check of monitoring overhead on the selected server stack.
4. Fresh repeated TSF 5.2.1 comparison evidence and an independent operator's reproduction.

No new plugin performance claim follows from the synthetic tests. The historical 1.52× result used TSF 5.2.0; this repository's new portable recipe must establish its own result.

The provided CI matrix covers Windows, macOS, and Linux with PHP 8.2 and 8.4. It has not run merely because its workflow file exists.
