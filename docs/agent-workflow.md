# Run the benchmark with a user

Use simple language. Explain the next action and its purpose. Keep a short progress list visible; do not make the user read implementation details to approve setup.

## 1. Check without changing anything

Read the recipe, this workflow, and [the environment requirements](environment.md). Run:

```sh
php benchmark.php doctor
```

If `php` is unavailable, inspect existing Local or native-stack tooling. Do not install PHP automatically. Local 10.2+ with its official CLI enabled supplies native PHP, MySQL, nginx, and WP-CLI; a separate PHP or WP-CLI installation is not expected. Verify the bundled versions and required extensions after approved setup. Do not start Local merely to discover whether it is installed: some CLI commands launch it.

Report what is available and what remains unknown. Check the server host; a remote collector's CPU load does not describe the server.

## 2. Offer a target and a recipe

Before planning, read [the workload/time tables](run-budget.md) and [agent cost guidance](agent-costs.md). Proactively estimate phase durations, token usage, and API-equivalent dollars using the current model's verified rates and explicit cache/context assumptions. Use observable model and billing information; label unknown subscription charges separately rather than requiring a billing questionnaire. Do not ask for time or spending budgets.

If the eligible subscription allowance is known, also estimate the run's percentage of that specific pool and reset period. Keep separate model pools separate, distinguish operator assumptions from verified entitlements, and do not invent a remaining account balance. Use [the recorded Mac setup usage](agent-costs.md#recorded-grok-47-high-mac-setup-and-discussion) as preliminary setup/discussion evidence, not a completed-run price.

Recommend a workload suited to the user's goal and let them choose or adjust routes and configurations. For broad comparisons, recommend 100 (reference-sized repeated collection); also offer 10 (smoke) and 40 (limited two-pass investigation). Show rounded request counts, relevant observed/projected durations, and separate setup/qualification allowances. Both 10 and 40 are smoke-only; neither qualifies a broad performance claim, and 100 is not automatically accurate. All currently supported routes are frontend HTML; admin, feeds, and sitemap cohorts are not implemented.

Honor an already chosen scope without asking again. Respect any deadline or spending cap the user supplies. If it cannot accommodate setup and qualification, offer a smaller valid scope or postpone; do not omit controls, validation, recycling tests, or necessary isolation. Record estimate assumptions and unknowns, separating subscription use from API-equivalent cost.

Offer the user:

- **Local with its official CLI**, if available or if the user wants to install it.
- **Their existing native stack**, with an explicitly designated, disposable benchmark target.

Never infer that an existing development site may be overwritten. A stack is a set of runtimes; using that stack does not require reusing a populated site.

For a new Local Windows plan, prefer the [owned worker supervisor](cli.md#owned-windows-worker-supervisor) when the installed nginx/PHP binaries are available. Include its processes and private ports in the initial proposal. The stock Windows CGI pool has failed ordinary recycling in real WordPress tests; do not wait for another full experiment to fail before selecting the qualified supervisor. It preserves 500-request recycling and changes no Local files. Existing native stacks and macOS keep their own worker management.

Suggest the TSF reference recipe and explain its nine HTML routes, competitors, fixture content, three passes, and estimated-added-time metric. Let the user inspect, criticize, or change it. Explain proposed changes and preserve them as a custom recipe. Inspect `recipe.fixtures` and `environment.reference_php`; the current recipe specifies PHP 8.4.27. Prefer that runtime when available. A newer PHP 8.4 or another user-selected runtime is a disclosed custom environment, not a silent replacement. Do not silently replace pinned packages. Use a compatible installed MySQL/MariaDB and record its exact version; the recipe does not pin a database version.

Use `examples/target.native.json` as a starting point for an existing native stack. Keep the filled target, credentials, and run directory under `.local/`, outside the served site. Use absolute filesystem paths in local configuration, but keep them out of public exports.

For Local, first obtain approval for any installation, CLI enablement, and creation of a dedicated shell site. Do not create a shell site simply to discover how Local works.

For approved Local downloads, follow [the installer-source guidance](environment.md#local-installer-sources): use WinGet or Homebrew metadata to locate the official CDN installer and hash without installing those package managers. Check the version, architecture, hash, and signing identity before execution. Keep Local attribution and its releases-page fallback; do not treat an email form as the only download path or bypass installation approval.

On macOS, the approved Local CLI may create the first dedicated shell site. Enable the CLI through Local's profile icon -> Preferences -> Advanced -> Install Local CLI -> Apply, if approved and needed. Inspect `localwp new --help` and installed runtime choices; create only the approved site with supported options matching the chosen environment. Do not guess flags or silently accept newer defaults.

On Windows, first confirm that the user has manually created a disposable site, handled OS prompts, and opened its homepage and admin. Hand control back for this check if needed. An already working Local site satisfies it without authorizing reuse of its content.

Every new site can request OS authentication, file permissions, or runtime installation, even after a working first site. Explain the chosen creation step and expected prompts before starting it. OS passwords belong only in the system prompt, never in chat, command arguments, or logs. If a shell site already exists, let the user finish its prompts and opening checks rather than creating another.

Pause computer control when any UAC, firewall, or other OS permission prompt appears. Explain the prompt and let the user review it directly; Windows secure-desktop prompts can be inaccessible while the agent controls the desktop. Do not repeatedly issue site-creation or setup commands while the prompt is pending. After the user reports completion, check the existing site's status before retrying anything, and verify loopback access. Do not instruct the user to grant broad inbound network access as a prerequisite.

After the applicable site check and approval of the dedicated target, export its configuration:

```text
localwp cli BENCHMARK-SITE -- eval-file /absolute/repository/scripts/local-target.php /absolute/repository/.local/target.json
```

The destination directory must already exist outside the served site, and the destination file must be new. This helper reads the selected shell site's PHP and database configuration and saves it privately without printing credentials. Review the file before planning; downloads default to disabled.

If the official CLI is unavailable but an approved Local site already exists, the user can open its Local Site Shell and invoke the repository's PHP entry point there. Do not install Node or another bridge to work around a missing CLI.

## 3. Present the exact setup and wait

Create the run plan:

```sh
php benchmark.php plan --recipe recipes/tsf-5.2.1.json --target .local/target.json --out .local/my-run
```

Explain these points before asking for approval:

1. Which stack and dedicated target will be used.
2. Which software or packages are missing, their sources, and what will be downloaded.
3. Which files, database objects, settings, and services setup will affect.
4. Which network controls apply before tested plugins activate, including raw sockets outside the default WordPress hooks.
5. What the experiment measures, how many passes run, and which limitations remain.
6. What cleanup removes and what evidence remains.
7. Estimated time by phase, model-priced token/API-equivalent cost, assumptions, and any limits the user supplied.

The agent's approval control may look different in each application. Accept an explicit user response approving this concrete plan. Do not claim the repository can press an approval button or bypass an OS prompt.

Only after approval, record it:

```sh
php benchmark.php approve --run .local/my-run --operator "Operator name"
```

An approval record is a local record of the user's decision, not authentication or a substitute for that decision. Changing the approved plan requires renewed approval for the changes. Do not create an approval record just to make a test proceed.

## 4. Cache packages and review network controls

```sh
php benchmark.php prepare --run .local/my-run
```

`prepare` obtains or reuses the approved pinned archives and verifies their hashes. It does not create WordPress fixtures or activate plugins. Set `downloads` in the private target only when the user approved obtaining the listed archives. A prefilled `package_cache` can supply the archives without downloads.

Recommend the native stack with default `wordpress-http` controls. The MU-plugin blocks and privately logs WordPress HTTP/mail and disables automatic updates; generated configuration disables WordPress cron. Keep telemetry opt-outs. Review the approved mode and its scope now; the runner installs and verifies the actual guards during fixture creation before plugin activation. No separate isolation receipt or extra user confirmation is required for this default. Arbitrary sockets and cURL remain outside the hooks; disclose that limit without blocking the run. Any wait inside a measured response remains in its timing, with no synthetic penalty.

Offer stronger `offline` or `external-firewall` controls if the user wants them, including a separately prepared container environment where suitable; Docker is not required. A container alone does not block outbound traffic; verify its network policy. Explain the changed environment and setup before approval. Verify the selected external restriction and loopback before activation. Do not default to a host firewall, whole-host disconnection, or changes to unrelated services. See [network controls and the macOS packet-filter warning](environment.md#outbound-isolation).

For a stronger mode, record the operator's verification of the enforced external restriction before collection:

```sh
php benchmark.php isolation --run .local/my-run --operator "Operator name" --evidence "Describe the chosen controls, checks, and remaining network scope"
```

The receipt is required for stronger modes and optional for documenting an existing review of the default; it must not create an extra default-mode approval prompt. It does not enforce a firewall itself. In `wordpress-http` mode, do not assert that raw outbound sockets are blocked. For stronger modes, worker-level canary checks supplement the verified external restriction; they do not prove every possible destination is blocked. Existing approved plans retain their recorded mode; changing it requires a new plan and approval.

## 5. Provision, qualify, and measure all passes

**Address the plumbing before trusting measurements.** Every host may expose different CLI, DLL/PATH, permissions, path-encoding, database, worker, OPcache, network, or telemetry issues. Start with the installed stack and preserve deliberate user settings. Propose useful isolated improvements when needed, explain their effect, and obtain approval before configuration changes. Prefer benchmark-owned configuration and process-only environment changes; never patch Local's installation files. Keep original settings/ownership evidence for restoration. A calibrated 2 GiB allocation on this host is not a default to impose on weaker machines.

Retain failed-attempt logs and explain what failed and what changed. Do not repeatedly relaunch, bypass security prompts, suppress PHP diagnostics, raise worker lifetimes, or keep retrying until a run passes. Explain material estimate overruns. Renew approval when the repair changes approved actions or scope, or a user-supplied limit requires it. A declined improvement remains an explicit limitation; a blocking runtime or isolation condition means stop, not a qualified reference result. See [environment qualification](environment.md).

For a Windows target using `worker_supervisor`, first run:

```sh
php benchmark.php preflight --run .local/my-run
```

Use [the owned worker-supervisor setup](cli.md#owned-windows-worker-supervisor) when the installed Local Windows CGI pool fails normal recycling. Discover the installed binary/DLL paths, include them in the private target before planning, and explain the extra owned processes and loopback ports in the setup proposal. Never patch Local's files or raise the CGI request limit to avoid testing recycling. Native servers must use the same approved network-control mode as the original PHP binaries. Compare monitoring-on/off results, record resource use and unavailable sensors, and explain a detectable observer cost before collection. A failed preflight retains its evidence and prevents the full run.

```sh
php benchmark.php run --run .local/my-run
```

`run` creates fresh owned WordPress fixtures under the approved network-control mode. The default uses the approved plan without a separate isolation receipt; stronger modes require the plan-bound external verification receipt. The runner verifies guards before activating plugins, completes the declared setup recipes, validates expected features and HTTP runtime settings, then measures all passes.

Inspect actual HTTP PHP, Xdebug, and OPcache state. Explain any failing condition and obtain approval before fixing settings. Keep diagnostic/profile runs separate from ordinary performance measurements.

Keep the declared workload and sampling policy unchanged. Preserve errors, interruptions, and unfavorable results. Never retry selected slow requests, trim routes to improve a ratio, or extend sampling until a preferred outcome appears.

Monitor environment conditions with the available native providers. Missing sensors remain unknown. Do not install sensor drivers, request elevation, change power settings, or tune fans automatically.

The runner's warmups are not measured samples. Small plumbing tests must not be presented as a reproduction of the full benchmark.

## 6. Clean up, explain, and verify

```sh
php benchmark.php cleanup --run .local/my-run
php benchmark.php report --run .local/my-run
php benchmark.php verify --run .local/my-run
php benchmark.php export --run .local/my-run --out .local/my-public-evidence
```

Cleanup must use the approved run's ownership record, not names guessed from a directory pattern. Preserve evidence. If a resource cannot safely be removed, name it and explain the remaining action; do not delete something broader. A report generated before verified cleanup remains provisional and records a blocker.

Explain the per-pass results and whether the conclusion is consistent. Distinguish estimated plugin-added response time from whole-response time and exclusive CPU time. Link the report and evidence. `verify` regenerates the numerical analysis and rendered report to check the saved result.

Record actual elapsed phase times and whatever agent usage the harness exposes. Use final per-response counts or verified cumulative deltas, avoiding duplicate snapshots, overlapping scopes, cached/input double counting, and reasoning/output double counting. Include delegated agents when available and state coverage gaps. Never reconstruct tokens from character counts and call them measured usage. Keep raw chat/account logs private; publish only reviewed aggregates. If usage or prices are unavailable, say **not evaluated**. Do not treat this first development run as the expected cost of a routine rerun.

Run directories contain private target and approval information. Do not share the entire directory. Use `export` to create a fresh public evidence directory, then review that export before sharing it. Do not append private logs, target files, or approval records to it. Report the tested OS, any untested integrations, environment limitations, and cleanup status. Exporting does not publish anything automatically.

## Runtime and platform notes

Local 10.2+ supplies an official CLI, disabled by default. Enablement and site creation require setup approval; each new site may prompt for OS authentication or additional access. Inspect the installed version's help before choosing creation flags or runtime settings; do not promise unattended setup. Installation or CLI enablement does not approve firewall, router, or other network changes. Local does not establish outbound isolation by itself. Never use `stop --all` to clean up one run. [Local CLI](https://localwp.com/help-docs/advanced/local-cli/), [bundled services](https://localwp.com/help-docs/getting-started/installing-local/)

WP-CLI can invoke a PHP entry point without bootstrapping WordPress:

```text
localwp cli BENCHMARK-SITE -- eval-file /absolute/path/to/benchmark.php doctor --skip-wordpress --use-include
```

Use the installed CLI's supported quoting on Windows. Test paths containing spaces. `--use-include` requires WP-CLI 2.8 or later. Do not upgrade automatically. [WP-CLI eval-file](https://developer.wordpress.org/cli/commands/eval-file/)

On Local 10.2.0 for Windows, pass WP-CLI arguments separately after `--`; quoting the entire command makes WP-CLI treat it as one command name. Quote individual paths containing spaces, and verify that case on the installed shell before relying on it.

The native adapter does not install a web server or database. If the existing stack cannot serve an isolated target with the required permissions, explain that and offer Local or a user-prepared target.
