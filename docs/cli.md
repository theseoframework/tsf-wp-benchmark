# Command reference

Run from the repository using an existing PHP runtime. Options use `--name VALUE`, with paths quoted when they contain spaces. All run files must stay outside the served site.

Unknown, repeated, or empty options are rejected before execution. There is no `--dry-run` option; use `plan` to inspect the proposal without changing a site.

| Command | Purpose | What changes |
| --- | --- | --- |
| `doctor` | Discover installed tools and engine capabilities | Nothing |
| `plan --recipe FILE --target PRIVATE_JSON --out NEW_DIRECTORY` | Save the concrete recipe, target, runner fingerprint, and proposal | A new private run directory; no site setup |
| `approve --run DIRECTORY --operator NAME` | Record the user's explicit approval of that plan | Approval receipt bound to the plan hash |
| `prepare --run DIRECTORY` | Obtain or reuse pinned archives and verify their hashes | Approved package cache and preparation evidence; no WordPress fixtures |
| `isolation --run DIRECTORY --operator NAME --evidence DESCRIPTION` | Record the operator's verification of enforced outbound isolation | Private isolation receipt; no firewall changes |
| `run --run DIRECTORY` | Create fresh fixtures, activate/configure plugins, qualify the setup, and measure | Approved fixture files/tables and run evidence |
| `cleanup --run DIRECTORY` | Remove verified run-owned fixture resources | Owned resources only; evidence remains |
| `report --run DIRECTORY [--out NEW_DIRECTORY]` | Analyze observations and render the report | Summary JSON, request CSV, and HTML report; use a fresh output for changed analysis |
| `verify --run DIRECTORY` | Recalculate and compare the saved analysis and report | Nothing |
| `export --run DIRECTORY --out NEW_DIRECTORY` | Create a shareable evidence bundle from the allowed public artifacts | A new export directory; no automatic publication |

Prefix each command with `php benchmark.php`. Use `php benchmark.php help` for the installed runner's command list.

## Normal order

```text
doctor -> plan -> user approval -> approve -> prepare
       -> enforced isolation and operator verification -> isolation
       -> run -> cleanup -> report -> verify -> export
```

`approve` and `isolation` record decisions and evidence. They cannot make an unapproved action authorized or create network isolation. The agent must not invent either receipt.

An existing analysis is never overwritten with different results. Use `report --run SOURCE --out NEW_DIRECTORY` to preserve it and produce another analysis in a public evidence bundle, then `verify --run NEW_DIRECTORY` to check that bundle. Evidence records both the collection runner and the analysis runner.

Public evidence bundles remain immutable. Re-export and alternate analysis verify the source manifest first and write to a fresh destination; they do not add lock files or rewrite the source.

Downloads are disabled in the target example. Enabling them is part of the setup proposal and requires approval. Already cached packages can be supplied through `package_cache`; exact hashes still apply.

Run directories are private. The target and plan can contain database credentials, and isolation evidence can describe local configuration. Do not publish a whole run directory. Share the reviewed export, without adding private logs or configuration files.

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

After the user approves a dedicated Local shell site, `scripts/local-target.php` can save its runtime/database details to a new private target file outside the web root. It must run through WP-CLI with that approved site's WordPress context loaded.

The benchmark engine itself can run without WordPress:

```text
localwp cli BENCHMARK-SITE -- eval-file /absolute/repository/benchmark.php doctor --skip-wordpress --use-include
```

Alternatively, open the approved site's Local Site Shell and run `php /absolute/repository/benchmark.php doctor`. Keep commands compatible with the installed Local CLI, and do not silently enable or install a missing component.

Local 10.2.0 on Windows requires separate WP-CLI arguments after `--`; do not quote the entire command as one string. Quote individual paths containing spaces and check that quoting on the installed shell. Complete the manual first-site permission check in [the agent workflow](agent-workflow.md) before automated setup.
