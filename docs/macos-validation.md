# Validate on macOS

This checklist is for an operator validating the repository on a Mac. Windows test results do not establish that a Mac integration works. Record the actual commands, versions, results, and limitations instead of marking this checklist complete in advance.

## 1. Run checks without setting up WordPress

Use an existing PHP runtime:

```sh
php benchmark.php doctor
php tests/run.php
```

If PHP is available only through Local, use its supported CLI/WP-CLI entry point. Do not install Homebrew, PHP, Xcode, or command-line tools just to satisfy a missing command without approval.

Verify PHP version, required extensions, command invocation, paths containing spaces, file permissions, and artifact generation. Confirm unavailable monitoring fields have clear reasons.

## 2. Check the approved native integration

Follow [the agent workflow](agent-workflow.md). Approve a dedicated target before setup. Check:

- Local discovery does not start or alter unrelated sites.
- The approved CLI can create the first dedicated Mac site using supported flags and the chosen runtime; newer defaults are disclosed.
- Each new site's authentication/file-access prompts are handed to the user without putting passwords in chat, command arguments, or logs.
- The selected PHP and actual HTTP PHP/SAPI agree with the declared environment.
- Xdebug effective modes and OPcache shared-memory state are detected correctly.
- Default `wordpress-http` blocking and private HTTP/mail logs work, with raw sockets explicitly outside their scope.
- If stronger external isolation was chosen, its restriction works and loopback remains reachable without unapproved host-network changes.
- Setup/indexing and all declared SEO feature checks pass.
- CPU/memory/power observations come from the Mac itself.
- Thermal pressure is labelled correctly, with no invented temperature values.
- A complete run, evidence replay, and fresh repeated passes work.
- Cleanup removes only owned fixture resources and preserves evidence.

Record hardware, OS, Local/CLI versions when used, HTTP runtime, test results, unavailable capabilities, and cleanup state. Do not claim native macOS support is validated until these checks have actually run.
