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

Offer the user:

- **Local with its official CLI**, if available or if the user wants to install it.
- **Their existing native stack**, with an explicitly designated, disposable benchmark target.

Never infer that an existing development site may be overwritten. A stack is a set of runtimes; using that stack does not require reusing a populated site.

Suggest the TSF reference recipe and explain its nine HTML routes, competitors, fixture content, three passes, and estimated-added-time metric. Let the user inspect, criticize, or change it. Explain proposed changes and preserve them as a custom recipe. Do not silently replace pinned inputs with newer releases. Inspect `recipe.fixtures` rather than assuming that two recipes have the same content or number of posts.

Use `examples/target.native.json` as a starting point for an existing native stack. Keep the filled target, credentials, and run directory under `.local/`, outside the served site. Use absolute filesystem paths in local configuration, but keep them out of public exports.

For Local, first obtain approval for any installation, CLI enablement, and creation of a dedicated shell site. Do not create a shell site simply to discover how Local works.

Before automated Local site creation, confirm that the user has manually created their first disposable site, completed OS permission prompts, and opened its homepage and WordPress admin successfully. If not, hand control back for that first-site setup and wait for confirmation before resuming. An already working Local site satisfies this check; it does not authorize using that site's content for benchmarking. If a benchmark shell site has already been created, let the user finish its permissions and opening checks rather than creating another site.

Pause computer control when any UAC, firewall, or other OS permission prompt appears. Explain the prompt and let the user review it directly; Windows secure-desktop prompts can be inaccessible while the agent controls the desktop. Do not repeatedly issue site-creation or setup commands while the prompt is pending. After the user reports completion, check the existing site's status before retrying anything, and verify loopback access. Do not instruct the user to grant broad inbound network access as a prerequisite.

After this manual check and approval of the dedicated target, export its configuration:

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
4. How outbound access will be blocked before tested plugins activate.
5. What the experiment measures, how many passes run, and which limitations remain.
6. What cleanup removes and what evidence remains.

The agent's approval control may look different in each application. Accept an explicit user response approving this concrete plan. Do not claim the repository can press an approval button or bypass an OS prompt.

Only after approval, record it:

```sh
php benchmark.php approve --run .local/my-run --operator "Operator name"
```

An approval record is a local record of the user's decision, not authentication or a substitute for that decision. Changing the approved plan requires renewed approval for the changes. Do not create an approval record just to make a test proceed.

## 4. Cache packages and confirm isolation

```sh
php benchmark.php prepare --run .local/my-run
```

`prepare` obtains or reuses the approved pinned archives and verifies their hashes. It does not create WordPress fixtures or activate plugins. Set `downloads` in the private target only when the user approved obtaining the listed archives. A prefilled `package_cache` can supply the archives without downloads.

Enforce the approved outbound restriction, preserve loopback access, and have the operator verify the actual restriction. WordPress HTTP and mail guards are additional controls; they cannot prevent arbitrary socket or cURL calls by themselves. If isolation cannot be established, stop before activation and explain what is needed. Do not change a firewall or disable a network adapter without the user's approval.

Only after that verification, record what was checked:

```sh
php benchmark.php isolation --run .local/my-run --operator "Operator name" --evidence "Describe the enforced restriction and how loopback and outbound blocking were verified"
```

The receipt records the operator's verification. It does not enforce a firewall itself. The runner also performs worker-level canary checks before activation; those checks supplement the verified policy rather than proving that every possible destination is blocked.

## 5. Provision, qualify, and measure all passes

```sh
php benchmark.php run --run .local/my-run
```

`run` creates fresh owned WordPress fixtures only after isolation is recorded. It activates the approved plugins, completes the declared setup recipes, validates expected features and HTTP runtime settings, then measures all passes.

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

Run directories contain private target and approval information. Do not share the entire directory. Use `export` to create a fresh public evidence directory, then review that export before sharing it. Do not append private logs, target files, or approval records to it. Report the tested OS, any untested integrations, environment limitations, and cleanup status. Exporting does not publish anything automatically.

## Runtime and platform notes

Local 10.2+ supplies an official CLI, disabled by default. Enablement and site creation require the user's approval and may prompt for OS authentication. Inspect the installed version's help before choosing creation flags or runtime settings; do not promise unattended setup. Installation or CLI enablement does not approve firewall, router, or other network changes, and Local does not establish outbound isolation by itself. Never use `stop --all` to clean up one run. [Local CLI](https://localwp.com/help-docs/advanced/local-cli/), [bundled services](https://localwp.com/help-docs/getting-started/installing-local/)

WP-CLI can invoke a PHP entry point without bootstrapping WordPress:

```text
localwp cli BENCHMARK-SITE -- eval-file /absolute/path/to/benchmark.php doctor --skip-wordpress --use-include
```

Use the installed CLI's supported quoting on Windows. Test paths containing spaces. `--use-include` requires WP-CLI 2.8 or later. Do not upgrade automatically. [WP-CLI eval-file](https://developer.wordpress.org/cli/commands/eval-file/)

On Local 10.2.0 for Windows, pass WP-CLI arguments separately after `--`; quoting the entire command makes WP-CLI treat it as one command name. Quote individual paths containing spaces, and verify that case on the installed shell before relying on it.

The native adapter does not install a web server or database. If the existing stack cannot serve an isolated target with the required permissions, explain that and offer Local or a user-prepared target.
