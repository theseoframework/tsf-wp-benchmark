# Command reference

Run from the repository using an existing PHP runtime. Options use `--name VALUE`, with paths quoted when they contain spaces. All run files must stay outside the served site.

Unknown, repeated, or empty options are rejected before execution. There is no `--dry-run` option; use `plan` to inspect the proposal without changing a site.

| Command | Purpose | What changes |
| --- | --- | --- |
| `doctor` | Discover installed tools and engine capabilities | Nothing |
| `plan --recipe FILE --target PRIVATE_JSON --out NEW_DIRECTORY` | Save the concrete recipe, target, runner fingerprint, and proposal | A new private run directory; no site setup |
| `approve --run DIRECTORY --operator NAME` | Record the user's explicit approval of that plan | Approval receipt bound to the plan hash |
| `prepare --run DIRECTORY` | Obtain or reuse pinned archives and verify their hashes | Approved package cache and preparation evidence; no WordPress fixtures |
| `isolation --run DIRECTORY --operator NAME --evidence DESCRIPTION` | Record required external verification for stronger modes, or optional default-mode review | Private isolation receipt; no firewall changes |
| `preflight --run DIRECTORY` | Qualify an approved Windows worker supervisor on real WordPress | One separately owned WordPress control, recycling/calibration evidence, and automatic fixture/server cleanup |
| `run --run DIRECTORY` | Create fresh fixtures, activate/configure plugins, qualify the setup, and measure | Approved fixture files/tables and run evidence |
| `cleanup --run DIRECTORY` | Remove verified run-owned fixture resources | Owned resources only; evidence remains |
| `report --run DIRECTORY [--out NEW_DIRECTORY]` | Analyze observations with the paired default and render the report | Summary JSON, request CSV, and HTML report; use a fresh output for changed analysis |
| `render --run PUBLIC_DIRECTORY --out NEW_DIRECTORY` | Refresh the presentation of a saved version 2 public summary | A separate compact report, expanded fallback, byte-identical summary, and presentation receipt; no statistical recalculation |
| `verify --run DIRECTORY` | Recalculate and compare the saved analysis and report using its recorded analysis version | Nothing |
| `export --run DIRECTORY --out NEW_DIRECTORY` | Create a shareable evidence bundle from the allowed public artifacts | A new export directory; no automatic publication |

Prefix each command with `php benchmark.php`. Use `php benchmark.php help` for the installed runner's command list.

## Normal order

```text
doctor -> plan -> user approval -> approve -> prepare
       -> [stronger modes: enforced external controls -> isolation]
       -> preflight (when using worker_supervisor)
       -> run -> cleanup -> report -> verify -> export
```

`approve` and `isolation` record decisions and evidence. They cannot make an unapproved action authorized or create network isolation. The agent must not invent either receipt. Default `wordpress-http` controls block and privately log WordPress HTTP/mail, with raw sockets outside their scope. The approved default plan needs no separate isolation receipt or extra approval prompt. The bracketed step is required only for `offline` and `external-firewall`, which require verified external restrictions. Old approved plans retain their recorded mode.

The default report is `analysis_version=2.0.0`, method `paired-block-1`. An existing analysis is never overwritten with different results. Use `report --run SOURCE --out NEW_DIRECTORY` to preserve it and produce the current analysis in a fresh root public evidence bundle, then `verify --run NEW_DIRECTORY` to check that bundle. Evidence records both the collection runner and the analysis runner.

New reports use the `compact-1` presentation, with comparisons and important limitations first and detailed evidence loaded when expanded. `details.html` retains the complete expanded view without JavaScript. A separate `presentation.json` binds the renderer version and its source fingerprint to the exact summary bytes. Presentation versions do not alter the recorded analysis version or its provenance.

`verify` dispatches by the saved analysis version. A historical version 1.0.0 summary and report retain their legacy calculations and provenance; verification does not silently replace them with the current default. Its current replay fingerprint is recorded separately from the historical provenance.

Historical reports without `presentation.json` retain the `expanded-1` renderer for exact HTML verification, including existing version 2 reports. Compact reports also verify their expanded fallback and presentation receipt. A historical renderer source fingerprint is preserved; the current fingerprint is reported separately, and matching rendered bytes remain the presentation check.

Public evidence bundles remain immutable. Re-export and alternate analysis verify the source manifest first and write to a fresh destination; they do not add lock files or rewrite the source.

Downloads are disabled in the target example. Enabling them is part of the setup proposal and requires approval. Already cached packages can be supplied through `package_cache`; exact hashes still apply.

Run directories are private. The target and plan can contain database credentials, and isolation evidence can describe local configuration. Do not publish a whole run directory. Share the reviewed export, without adding private logs or configuration files.

## Current paired analysis and historical review

To refresh only the layout of an existing version 2 public export:

```sh
php benchmark.php render --run .local/my-paired-evidence --out .local/my-report-view
```

This checks the original public manifest before and after rendering, copies `summary.json` byte-for-byte, and produces `report.html`, `details.html`, and `presentation.json` in a fresh directory outside the source. It performs no WordPress requests, statistical analysis, bootstrap draws, CSV regeneration, site startup, installation, or plugin changes. The receipt records `presentation_only: true`, the source manifest hash, and artifact hashes. The existing numerical estimates, verdict, historical provenance, and retrospective status remain unchanged.

The view is a presentation artifact, not a new public evidence export. It does not contain the raw evidence or a public `manifest.json`. Use `verify --run ORIGINAL_PUBLIC_DIRECTORY` for full numerical replay. The PHP helper `verify_presentation()` checks the view's receipt and rendered bytes without recalculating statistics; the CLI `verify` command rejects presentation-only views. Keep the original export alongside any shared view.

To regenerate the complete comparison from existing evidence without collecting again:

```sh
php benchmark.php report --run .local/my-public-evidence --out .local/my-paired-evidence
php benchmark.php verify --run .local/my-paired-evidence
```

This performs no WordPress requests, site startup, installation, or plugin changes. The new schema 2 root summary retains the original verdict and records the source analysis version, summary/public-manifest hashes, retrospective status, current default version, and distinct collection/analysis source fingerprints. It refuses an existing destination or one inside the source bundle.

The paired default makes direct millisecond differences and simultaneous intervals primary, retains negative cells, and reports the threshold contrast and all five dependence/pass sensitivities. Reference-sized analysis uses at least 50,000 draws and the recipe's confidence level. Smoke analysis retains the configured draw count and the existing smoke classification. Threshold verdicts remain conditional on the observed session and require every sensitivity and pass check. Read [the method](methodology.md) and [the statistical caveats](statistical-review.md).

Reanalysis of data inspected before adopting the method remains retrospective and exploratory. It is not independent confirmation. The original version 1.0.0 analysis, its inconclusive verdict, and its export remain preserved.

For the separately identified historical exploratory review of a verified public export:

```sh
php scripts/review-statistics.php --run .local/my-public-evidence --out .local/my-statistical-review
```

This supplementary tool uses `paired-block-review-1`, runs offline, and preserves the original analysis and verdict. It writes a new directory, refuses output inside the input bundle, and records paired effects, dependence sensitivities, influence, and original-denominator diagnostics. It remains distinct from the adopted version 2.0.0 report. Its descriptive ratio of average additions differs from the historical average of route ratios; neither retrospective entry point supplies independent confirmation.

## Fixture inputs

The recipe's `fixtures` object specifies `posts`, `pages`, `paragraphs`, `content_html`, `title`, `description`, `date`, and `posts_per_page`. The reference uses 120 posts, 30 pages, 12 repetitions of the declared content, and 10 items per archive page. The remaining values are visible in the recipe.

Posts accept 1–500, pages 3–500, repetitions 1–100, and archive items per page 1–100. The declared HTML accepts 1–65,536 UTF-8 bytes, with a 64 MiB maximum calculated fixture content budget. The nonempty title and description accept up to 512 and 2,000 UTF-8 bytes. The date must be a past UTC date in `YYYY-MM-DD HH:MM:SS` format, from 1970 onward. These bounds protect setup size; they do not establish statistical quality. Changes create a custom workload and require a new plan.

## Custom HTML routes

Version 0.1 supports frontend HTML only. RSS, sitemap XML/XSL/CSS, and authenticated admin collection are not implemented and are rejected. They need separate cohorts when supported.

A route's `id` names it in the report. Its optional `query` declares the WordPress context that must actually respond; it defaults to `id`. Supported contexts are `home-blog`, `home-static`, `blog-page`, `post`, `page`, `category`, `tag`, `search`, `404`, and `other`. For example, a custom page workload can use:

```json
{
  "id": "heavy-page",
  "query": "page",
  "path": "/?page_id={page_id}",
  "context": "static",
  "status": 200,
  "kind": "html"
}
```

The name alone does not make content heavy: change the declared fixture HTML or repetition count, and disclose the difference. The `query` value prevents a mislabeled response, such as a redirect or homepage, from being accepted as that page.

## Local entry points

After approval to use Local and the dedicated site, these commands help inspect and control that selected site. Some installed CLI commands start Local itself; do not use them for unsolicited application startup. Check the installed help before relying on flags.

| Command | Purpose |
| --- | --- |
| `localwp --help` | Inspect the installed command set |
| `localwp new --help` | Inspect supported site-creation flags and runtime choices |
| `localwp list --json` | Inspect site identities/status; select only the approved target |
| `localwp info BENCHMARK-SITE --json` | Read the selected site's runtime details |
| `localwp start BENCHMARK-SITE --no-progress` | Start only the approved site |
| `localwp stop BENCHMARK-SITE --no-progress` | Stop only that site; never use `--all` |
| `localwp cli BENCHMARK-SITE -- core version` | Verify the shell site's WordPress version; benchmark fixtures are verified separately over HTTP |
| `localwp cli BENCHMARK-SITE -- eval-file /absolute/repository/scripts/local-target.php /absolute/private/target.json` | Export a new private target after approval |

After setup approval, macOS can use `localwp new BENCHMARK-SITE` with only flags confirmed by the installed help to create its first site. Prefer the recipe's PHP 8.4.27 when available and record the actual database version. Disclose a newer PHP 8.4 or other selected runtime as a custom environment. Do not guess flags or silently accept newer defaults. The user enables the CLI through Local's profile icon -> Preferences -> Advanced -> Install Local CLI -> Apply.

Every new site may request OS authentication, file access, or runtime installation. Warn before creation, pause for the user to handle prompts, and inspect the existing attempt before retrying. Keep OS passwords out of chat and command arguments. On Windows, complete the manual first-site check and release desktop control for inaccessible UAC prompts as described in [the workflow](agent-workflow.md).

The tested Windows Local 10.2.0 supports JSON for `list`/`info`; its `start`/`stop` commands do not accept `--json`. WP-CLI arguments after `--` must be separate arguments. These details are observed compatibility notes, not a guarantee for every release or shell. Keep machine-specific paths in private configuration, not public instructions.

### Owned Windows worker supervisor

Local's default Windows CGI pool can return 502 while workers recycle. The optional `worker_supervisor` target runs a separate nginx instance and a PHP supervisor using the already installed binaries. It changes no Local program or configuration files. The existing Local site supplies the isolated database and document root; its original services remain idle during collection. Their resource use remains part of the host baseline.

Add these fields to the private target before planning:

```json
"worker_supervisor": {
  "nginx": "/absolute/path/to/installed/nginx.exe",
  "php_cgi": "/absolute/path/to/installed/php-cgi.exe",
  "workers": 2,
  "dll_paths": [
    "/absolute/path/to/php-directory",
    "/absolute/path/to/its/ImageMagick",
    "/absolute/path/to/its/ghostscript/bin"
  ]
}
```

Only list DLL directories that exist. Planning chooses and freezes two private loopback ports and executable hashes, then records the new HTTP origin. Ports can instead be supplied as integer `http_port` and `fastcgi_port` values from 1024 to 65535. An occupied port fails startup; the runner never kills its owner or silently chooses another port after approval.

The HTTP server uses one owned nginx process. PHP uses one supervisor and two active children by default, each recycling after **500 requests**. The request collector remains serial. Worker count accepts 1–4; changing it requires a new plan. Neither the lifetime request limit nor the sample count represents concurrent requests.

After isolation, `preflight` provisions an independent WordPress control from the pinned archive. It requires 2,200 valid homepage responses across actual worker replacements, then performs 12 alternating monitoring-on/off pairs with 10 warmups and 50 measured requests per condition. These diagnostic observations stay separate from the full plugin experiment. Complete failed responses are retained; nothing is selectively retried. Preflight also verifies runtime/cache conditions and cleans up its own control.

Windows job ownership covers the HTTP server, PHP supervisor, and descendants. Normal completion, startup failure, or death of the owning runner closes the job and terminates only those servers. Job startup and shutdown evidence stays private. The full `run` refuses to start until preflight passed for its exact plan. The supervisor currently supports Windows; macOS continues to use the approved native stack's own server and worker management.

The controller prefers Windows' built-in PowerShell 5.1; PowerShell 7 is optional. Configuration is read as UTF-8, and nginx uses relative command arguments to avoid Windows command-line code-page problems in paths containing non-ASCII characters. Native diagnostic streams are retained byte-for-byte even if their encoding is not UTF-8. PHP shutdown also signals the controller before process-resource destruction.

### Local CLI

After the user approves a dedicated Local shell site, `scripts/local-target.php` can save its runtime/database details to a new private target file outside the web root. It must run through WP-CLI with that approved site's WordPress context loaded.

The benchmark engine itself can run without WordPress:

```text
localwp cli BENCHMARK-SITE -- eval-file /absolute/repository/benchmark.php doctor --skip-wordpress --use-include
```

Alternatively, open the approved site's Local Site Shell and run `php /absolute/repository/benchmark.php doctor`. Keep commands compatible with the installed Local CLI, and do not silently enable or install a missing component.

Local 10.2.0 on Windows requires separate WP-CLI arguments after `--`; do not quote the entire command as one string. Quote individual paths containing spaces and check that quoting on the installed shell. Follow the platform-specific first-site and per-site permission checks in [the agent workflow](agent-workflow.md).
