# Compare The SEO Framework with other SEO plugins

Open this repository in Codex, Cursor, VS Code Copilot, or Claude. Copy this prompt:

```text
Help me run a trustworthy SEO plugin benchmark using this repository: https://github.com/theseoframework/wp-plugin-benchmark
Read the repository instructions and docs/agent-workflow.md first.
Check which native WordPress stacks I already have. Offer Local with its
official CLI or a suitable existing stack, and explain the proposed setup.
If using Local, confirm I have manually created and opened my first site.
Hand control back for OS permission prompts before continuing automation.
Offer TSF's reference recipe and let me question or change the experiment.
Compare TSF with Yoast SEO, Rank Math, SEOPress, Slim SEO, and AIOSEO
both standalone and with the reference recipe's default companion bundle.
Show me the downloads, site changes, network isolation, expected workload,
and cleanup. Wait for my approval before installing or setting anything up.
Then run the approved experiment, check its consistency, and explain the
results and limitations. Keep the evidence even if TSF does not win.
```

## First time using Local?

Before agent-assisted site setup:

1. Open Local yourself and create a disposable WordPress site.
2. Review and complete any OS permission prompts yourself.
3. Open that site's homepage and WordPress admin to confirm Local works.
4. Return to your agent and continue. Tell it explicitly if this site should be the benchmark target.

**Pause computer control while handling permission prompts.** On Windows, UAC or firewall prompts can be inaccessible while an agent controls the desktop. If a prompt appears later, the agent should hand control back and wait, rather than repeatedly retry setup. Review the requested access; this benchmark needs loopback access, not public-network access.

If you already have a working Local site, you do not need another first-site check. An existing site is used for benchmarking only with your explicit approval. Users of another native stack can skip this Local step.

## What happens next

1. **Check:** your agent finds available tools without changing your sites.
2. **Choose:** you choose the stack and benchmark recipe.
3. **Approve:** your agent explains the setup. You approve it in your agent's interface; your OS may also ask for permission.
4. **Test:** after packages are cached and isolation is confirmed, the runner creates the fixtures, checks them, and measures all planned passes.
5. **Review:** cleanup is verified, then you get the report and an evidence export you can share.

```mermaid
flowchart LR
    A[Check installed tools] --> B[Choose stack and recipe]
    B --> C[Explain setup]
    C --> D{Your approval}
    D -->|Yes| E[Prepare and isolate]
    E --> F[Measure and repeat]
    F --> G[Clean up and report]
    D -->|Change it| B
```

**Nothing is installed or set up without your approval.** Existing development sites are not automatic test targets. Local and an existing native stack use the same benchmark method.

The main benchmark compares **The SEO Framework against Yoast SEO, Rank Math, AIOSEO, SEOPress, and Slim SEO**. AIOSEO is measured separately with and without the default companion bundle pinned in the reference recipe: MonsterInsights, OptinMonster, Broken Link Checker, and Universally. Both configurations stay visible in the report.

The reference recipe covers nine frontend HTML routes and three complete passes, with two WordPress-only controls. Small smoke tests check the plumbing; they do not establish a broad performance claim.

## Understand the result

The report explains what ran, whether the environment was suitable, and whether repeated passes agreed. It distinguishes whole-response time from **estimated plugin-added time**. CPU load and available thermal readings have sources and coverage; missing sensors stay explicitly unavailable.

The reference recipe is an invitation to reproduce and challenge the experiment. Change the workload or settings when useful, and keep those changes visible. No result is predetermined.

- [Agent workflow](docs/agent-workflow.md)
- [Method, comparisons, and repeatability](docs/methodology.md)
- [Requirements and environment limitations](docs/environment.md)
- [Command reference](docs/cli.md)
- [macOS validation checklist](docs/macos-validation.md)
- [What has been tested](docs/validation.md)

## Compare other supported SEO plugins

You can also compare supported SEO plugins with each other, such as Yoast SEO against Slim SEO. Choose a comparison anchor: the plugin whose estimated added time is the denominator in the reported ratios. TSF can be omitted from this custom experiment.

Use this alternate prompt and replace the bracketed plugin names:

```text
Help me compare [plugin A] with [plugin B] using this repository: https://github.com/theseoframework/wp-plugin-benchmark
Read the repository instructions and docs/agent-workflow.md first.
Use supported SEO plugins and help me choose the comparison anchor and
configurations. Record these choices in a custom recipe.
Use the same methodology as the TSF reference: two WordPress-only controls,
balanced request orders, warmups, repeated passes, output validation,
environment checks, and reproducible evidence. Keep the full declared
workload; do not replace it with a small smoke test.
Check installed native WordPress stacks and explain the proposed setup,
downloads, site changes, network isolation, expected workload, and cleanup.
Wait for my approval before installing or setting anything up.
Then run the approved experiment and explain its consistency, results,
and limitations. Keep all evidence regardless of which plugin wins.
```

The supported plugins are those listed above. Other plugins need verified setup and output checks before they can be compared. The same measurement and evidence rules apply whichever plugin is the anchor; changing the participants does not make a result universal.

## Bonus: explain the differences

```text
Investigate why the plugins in this benchmark perform differently.
Read docs/code-quality.md and the evidence before making claims.
Compare relevant code paths, algorithms, repeated work, database access,
caching, setup, and enabled features. Explain where TSF is efficient and
where it is not. Do not assume that speed means missing features, or that
more code means worse quality. Separate measured facts from hypotheses,
show source locations, and suggest fair follow-up tests. Make no plugin
changes and install nothing without my approval.
```

## Develop the runner

The runner uses PHP and existing native tools. It does not require Node, Python, Composer packages, or `wp-plugin-performance`.

```sh
php benchmark.php doctor
php tests/run.php
```

The test suite checks the runner without installing WordPress. Windows and macOS integration checks must be recorded separately; a test passing on one OS does not certify the other.

For PHP coding checks, use an already installed PHP_CodeSniffer with the TSF standard:

```sh
phpcs
```

The repository ruleset applies TSF conventions to PHP 8.2+. It documents the few CLI and test-fixture exceptions, including native filesystem operations and vendor API signatures. VS Code's PHPCS extension discovers this ruleset automatically when configuration search is enabled. These development tools are optional for running the benchmark; Local with its CLI supplies the required runtime.

## License

TSF WordPress Benchmark is free software licensed under [GNU GPLv3](license.txt).
Copyright © 2026 Sybre Waaijer, CyberWire B.V.
