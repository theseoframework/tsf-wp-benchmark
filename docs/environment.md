# Requirements and limitations

Use installed software first. Report missing capabilities and obtain approval before installation, setup, configuration changes, or privileged monitoring.

## Supported setup paths

| Path | Benefit | Limitations |
| --- | --- | --- |
| Local 10.2+ with its official CLI enabled | Supplies native PHP, MySQL, nginx, and WP-CLI on Windows and macOS | CLI is disabled by default; site creation can require OS authentication; installed CLI capabilities and bundled versions must be checked |
| Existing native PHP/web-server/database stack | Reuses what the operator already has | The operator must supply an approved disposable target, suitable permissions, and working runtime paths |

DDEV and Lima are not required. A VM is not inherently incapable of useful comparisons, but virtualization, filesystem sharing, and resource limits must be recorded and not silently treated as a native reference environment.

The engine requires 64-bit PHP 8.2+ with cURL, ZipArchive, mysqli, JSON/hash, and DOM/XML support, plus process execution for fixture tasks. There are no mandatory Composer, Node, or Python dependencies. With approved Local setup, a separate PHP or WP-CLI installation is not expected: use the bundled tools and verify their versions and required extensions. Check CLI and HTTP PHP separately. [Local CLI](https://localwp.com/help-docs/advanced/local-cli/), [bundled services](https://localwp.com/help-docs/getting-started/installing-local/)

An ordinary PHP web server and MySQL/MariaDB are required for live experiments. PHP's development server is useful for harness smoke tests; it is not interchangeable with the reference web-server/SAPI configuration.

On macOS, the approved Local CLI may create the first dedicated benchmark site. On Windows, the user should first create a disposable site manually and verify that its homepage and admin open. Every new site may request OS authentication, file access, or runtime installation, even when another site already works. Pause automation for the user to handle these prompts; Windows UAC and firewall dialogs can be inaccessible during desktop control. Keep OS passwords out of chat, arguments, and logs. A working site does not authorize using its content as the benchmark target.

Inspect the installed CLI's help and runtime choices before creation. Prefer the recipe's `environment.reference_php` (currently PHP 8.4.27); disclose newer PHP 8.4 or another selected runtime as a custom environment. Record the exact compatible MySQL/MariaDB version rather than inventing a database pin.

The optional [Windows worker supervisor](cli.md#owned-windows-worker-supervisor) reuses installed nginx/PHP binaries while owning its server processes and private ports. It preserves normal 500-request CGI recycling and requires a real WordPress preflight before collection. It adds a worker manager and HTTP process, whose resource cost must be recorded; it does not make every machine equally fast or qualify the host merely by starting successfully. Local's original idle services remain part of the baseline. No Local files are patched.

## Inspect before trusting timings

### Local installer sources

Prefer an existing suitable Local installation. When installation is approved, the agent can use public package catalogs as discovery metadata:

- Windows: [Microsoft's WinGet Local manifests](https://github.com/microsoft/winget-pkgs/tree/master/manifests/f/Flywheel/Local) declare installer versions, official CDN URLs, and SHA-256 hashes.
- macOS: [Homebrew's Local cask](https://github.com/Homebrew/homebrew-cask/blob/main/Casks/l/local.rb) declares Apple Silicon and Intel URLs and hashes; its [JSON API](https://formulae.brew.sh/api/cask/local.json) also exposes platform variations. Select the actual architecture rather than assuming the API's default URL matches the machine.

These community-maintained catalogs are not a Local release API or an endorsement of this benchmark. Read their metadata without installing WinGet or Homebrew. Fetch installers only from `https://cdn.localwp.com/`, verify the declared SHA-256 and platform signing identity before execution, and record the exact version, build, architecture, URL, hash, and metadata source. Respect Local's installation terms. Do not invent build numbers, execute package-manager scripts, or substitute a mirror. If metadata is unavailable or outdated, fall back to [Local's releases page](https://localwp.com/releases/). Downloads and installation still require explicit approval.

### Runtime qualification

| Condition | How to interpret it |
| --- | --- |
| Effective Xdebug or other profiling is active | Reference timing is not qualified; explain and obtain approval before disabling it |
| Xdebug is installed but its effective mode is off | Record it; installation alone is not evidence that profiling occurred |
| `WP_DEBUG`, `SCRIPT_DEBUG`, or `SAVEQUERIES` is enabled | Debug behavior or query recording differs from the reference; report the flags and obtain approval before changing them |
| WordPress debug flags were not recorded | Their state is unverified; do not assume that debugging was disabled |
| OPcache shared memory works and a disk cache is also configured | A second-level disk cache is valid; record both |
| OPcache is file-only, unavailable, full, or restarting | Explain the condition; it does not qualify as the warmed shared-memory reference |
| A worker cannot attach to shared memory | A Windows fallback can use file-only caching even if the declared configuration appears suitable; probe HTTP workers |
| Full-page caching or a proxy bypasses PHP | The experiment is not measuring the intended plugin work |
| Runtime, power state, or cache conditions change mid-run | Retain evidence and flag the affected comparison; do not hide affected samples |
| Thermal or CPU observations are missing | Report coverage as unavailable; never infer that the machine was idle or cool |

`XDEBUG_MODE` can override the configured `xdebug.mode` value without changing that value. Check effective modes, not only the INI file. [Xdebug documentation](https://xdebug.org/docs/upgrade_guide)

The generated reference fixtures use `WP_DEBUG=false`, `WP_DEBUG_LOG=false`, `WP_DEBUG_DISPLAY=false`, `SCRIPT_DEBUG=false`, and no `SAVEQUERIES` recording. Probe these flags in the actual HTTP request. The receipt records the debug-log setting as a Boolean, never its private file path. Ordinary PHP error logging remains enabled to a private run log; record its enabled state and error-reporting mask separately from WordPress debugging. [WordPress debugging documentation](https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/)

Dedicated fixtures use `WP_ENVIRONMENT_TYPE=production` to preserve ordinary plugin behavior. For example, Yoast disables saving indexables in a `local` environment. This setting does not expose the fixture publicly: loopback-only serving, secret request headers, disabled updates/cron, and the selected network controls remain independent controls. The HTTP runtime receipt must verify the effective environment.

`opcache.file_cache` enables a second-level disk cache. `opcache.file_cache_only` disables shared-memory caching; Windows fallback can also put a worker into that state. `opcache_get_status()` describes the in-memory cache. [OPcache configuration](https://www.php.net/manual/en/opcache.configuration.php), [runtime status](https://www.php.net/manual/en/function.opcache-get-status.php)

The full suite uses many isolated WordPress and plugin trees. Its OPcache memory or cached-file requirements can exceed a desktop stack's defaults, including Local's. Check capacity after warming the actual fixtures. If capacity is insufficient, explain the proposed site-specific setting change and obtain approval. Do not silently reset the cache between competitors or claim that a configured memory size proves the cache stayed healthy.

Users may deliberately compare these conditions with a custom recipe. Show that choice and its scope rather than silently repairing the environment.

## CPU, memory, power, and temperature

Collect an idle baseline and low-rate observations during and after the experiment. Use native providers on the server host; monitoring the computer that merely launches a remote run is insufficient.

Preserve timestamps, units, sources, and availability reasons. CPU utilization, load average, thermal pressure, temperature, and clock estimates are different quantities. Do not substitute one for another or mathematically adjust timings using them.

- **Windows:** native performance providers can report CPU and memory activity. Sensor access and counter permissions vary. Firmware thermal zones are not necessarily CPU die temperatures, and `Win32_TemperatureProbe.CurrentReading` is not populated by current implementations. [Microsoft documentation](https://learn.microsoft.com/en-us/windows/win32/cimwin32prov/win32-temperatureprobe)
- **macOS:** native tools provide CPU, memory, and power information. The system thermal state describes pressure, not degrees Celsius. Availability varies by system. [Apple thermal-state documentation](https://developer.apple.com/documentation/foundation/processinfo/thermalstate-swift.enum)

On macOS, an inherited process sandbox can refuse the installed setuid `top` binary. The collector then uses the existing, regular `iostat` and `vm_stat` binaries. CPU utilization and OS thermal pressure retain their own sources; CPU die temperature remains unavailable without an approved sensor provider. Free plus speculative memory is reported separately as a lower bound, excluding inactive and purgeable pages. It is not substituted for an exact available-memory counter.

Before a long collection, start and stop the actual collector under the approved runtime and isolation policy. Confirm that it produces non-warmup readings and exits normally; parser tests alone do not verify native process startup. Retain a separate monitoring-off/on comparison when validating observer cost, identify its workload, and do not use it as an automatic timing correction or a no-impact claim. Never change executable permissions or elevate merely to make `top` launch.
Unsupported readings stay null with a reason, not zero or an invented healthy state. Additional approved, already installed sensor providers can improve coverage. Do not install drivers, elevate automatically, change power plans, or adjust fans.

The sampler runs separately at a low rate. Validate its overhead before relying on it. High CPU use during benchmark requests can be the benchmark's own work; do not automatically classify it as unrelated interference.

## Outbound isolation

Obtain the approved pinned packages first. Recommend the native stack with default `wordpress-http` controls: the MU-plugin blocks WordPress HTTP and mail, disables automatic updates, and records refused requests privately outside the served site. Generated configuration disables WordPress cron. Keep telemetry opt-outs. These hooks do not constrain arbitrary cURL or sockets; disclose that limit without making it a blocker. Keep waits that occur inside a measured response in its timing; do not add synthetic penalties.

Both setup PHP and HTTP workers must be able to append to the run-owned private log. Check that permission for the chosen native stack; do not make the run directory publicly writable or expose it through the web server to resolve an access failure.

Users may choose stronger isolation, such as an offline target, an external firewall, or a separately prepared container environment. Docker is not required. A container alone does not block outbound traffic; verify its network policy. Explain the setup and methodological differences, obtain approval, and record the actual environment and `offline` or `external-firewall` restriction. Verify that chosen restriction and loopback access before activation. Existing approved plans retain their selected mode; a new default does not authorize weakening them. Raw request URLs and mail details stay private, outside public exports.

The [pinned-plugin source audit](network-audit.md) traces optional direct transports in AIOSEO's AI assistant and SEOPress's manual Google indexing, and distinguishes them from SDKs that route through WordPress HTTP. It is not a runtime connection trace.

Do not default to a host firewall or whole-host disconnection. A reported macOS attempt on 13 November 2026 lost iCloud Private Relay after the main packet-filter ruleset was reloaded; it returned after Apple's original rules were restored. Do not reload that ruleset automatically. If stronger controls are requested, explain their effect on existing network services before approval. Never silently disconnect an adapter, alter Local's router mode, install a certificate, or stop unrelated services.
