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

## Inspect before trusting timings

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

`opcache.file_cache` enables a second-level disk cache. `opcache.file_cache_only` disables shared-memory caching; Windows fallback can also put a worker into that state. `opcache_get_status()` describes the in-memory cache. [OPcache configuration](https://www.php.net/manual/en/opcache.configuration.php), [runtime status](https://www.php.net/manual/en/function.opcache-get-status.php)

The full suite uses many isolated WordPress and plugin trees. Its OPcache memory or cached-file requirements can exceed a desktop stack's defaults, including Local's. Check capacity after warming the actual fixtures. If capacity is insufficient, explain the proposed site-specific setting change and obtain approval. Do not silently reset the cache between competitors or claim that a configured memory size proves the cache stayed healthy.

Users may deliberately compare these conditions with a custom recipe. Show that choice and its scope rather than silently repairing the environment.

## CPU, memory, power, and temperature

Collect an idle baseline and low-rate observations during and after the experiment. Use native providers on the server host; monitoring the computer that merely launches a remote run is insufficient.

Preserve timestamps, units, sources, and availability reasons. CPU utilization, load average, thermal pressure, temperature, and clock estimates are different quantities. Do not substitute one for another or mathematically adjust timings using them.

- **Windows:** native performance providers can report CPU and memory activity. Sensor access and counter permissions vary. Firmware thermal zones are not necessarily CPU die temperatures, and `Win32_TemperatureProbe.CurrentReading` is not populated by current implementations. [Microsoft documentation](https://learn.microsoft.com/en-us/windows/win32/cimwin32prov/win32-temperatureprobe)
- **macOS:** native tools provide CPU, memory, and power information. The system thermal state describes pressure, not degrees Celsius. Availability varies by system. [Apple thermal-state documentation](https://developer.apple.com/documentation/foundation/processinfo/thermalstate-swift.enum)

Unsupported readings stay null with a reason, not zero or an invented healthy state. Additional approved, already installed sensor providers can improve coverage. Do not install drivers, elevate automatically, change power plans, or adjust fans.

The sampler runs separately at a low rate. Validate its overhead before relying on it. High CPU use during benchmark requests can be the benchmark's own work; do not automatically classify it as unrelated interference.

## Outbound isolation

Obtain the approved pinned packages first. Before activation, enforce an offline test host or explicitly approved outbound blocking while keeping loopback available.

WordPress HTTP and mail guards add protection but do not constrain arbitrary cURL or socket calls. A statement that outbound traffic was blocked requires evidence of the chosen external restriction. Do not rely on opt-out checkboxes alone.

Do not silently change a host firewall, disconnect a network adapter, alter Local's router mode, install a certificate, or stop unrelated services. Describe the change and its impact before approval.
