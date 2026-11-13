# Network controls and the pinned plugins

The default fixture uses an MU-plugin to block WordPress HTTP and mail, disable automatic updates, and privately log refused calls. Generated configuration disables WordPress cron. Direct cURL and socket traffic is permitted and outside those hooks. Stronger isolation is an optional environment choice, not a prerequisite for the default benchmark. See [the network policy](methodology.md#network-policy).

## Source findings

Read-only inspection covered 5,222 non-minified PHP entries in the ten plugin archives pinned in [the reference recipe](../recipes/tsf-5.2.1.json). All ten archive hashes matched the recipe, as did the separate WordPress archive. These are findings for those exact versions, not claims about future releases. Paths and line numbers below are relative to each plugin's archive root.

**AIOSEO's AI assistant and SEOPress's manual Google indexing use transports outside WordPress HTTP. Neither endpoint is part of the declared reference frontend GET workload.** This source inspection did not execute plugins or trace network connections. It cannot prove that no other bypass exists or that a timed request made a connection.

| Plugin | Finding |
| --- | --- |
| TSF 5.2.1 | No literal direct cURL/socket transport candidate found in its 270 PHP entries. This is a scoped search, not proof of runtime absence. |
| Yoast 28.8 | Its Semrush and Wincher Guzzle clients inject `WP_Remote_Handler`, which calls `wp_remote_request()`. A vendor `curl_exec()` text match is an exception message, not a call. |
| AIOSEO 5.0.5, both configurations | Its authenticated AI assistant REST action uses direct cURL for an SSE completion stream. Separate development-asset socket checks are gated off in the pinned release. |
| Rank Math 1.0.281 | Its tracking integration explicitly selects the WordPress consumer, which calls `wp_remote_post()`. Alternative cURL/socket consumers exist in the bundled SDK; that does not establish their execution. |
| SEOPress 10.4 | Manual Google Instant Indexing creates a Google client with default Guzzle cURL/stream transport after module, engine, credential and authenticated AJAX checks. Its automatic submission path explicitly skips Google. |
| Slim SEO 4.11.5 | Its AI request uses `wp_safe_remote_post()`. No literal direct cURL/socket candidate found in its 184 PHP entries. |

### AIOSEO AI assistant

`app/Common/Api/Api.php:66` declares POST `ai/assistant`; route registration is at lines 263–280. `app/Common/Api/Ai.php:413` implements `generateAssistantCompletion()`, checks `edit_post` at line 425, initializes cURL at line 435, selects the external AI URL at line 438, and executes at line 481. Stored account tokens are read by `app/Common/Ai/Ai.php:321–328`; the callback does not reject a missing token before attempting the connection. An absent account alone is therefore not proof that invoking the action cannot send a request.

AIOSEO's asset-watcher socket in `app/Common/Traits/Assets.php:506` is gated at lines 487–503. The pinned archive has no `build/.env`. A deprecated helper also has a developer-host-gated socket in `app/Common/Traits/Helpers/Deprecated.php:90–102`.

### SEOPress manual Google indexing

`inc/functions/options-instant-indexing.php:405–421` handles authenticated AJAX with nonce and capability checks. The submission function starts at line 122, checks module/engine selection at lines 124 and 202–212, selects Google at line 309, loads service-account credentials at lines 61–70 and 316–320, and executes the batch at line 338. Lines 311–315 explicitly skip Google for automatic submissions.

`vendor/google/apiclient/src/Client.php:963–1004` creates the default Guzzle client. Its handler selection reaches `vendor/guzzlehttp/guzzle/src/Handler/CurlHandler.php:66` (`curl_exec`) or `vendor/guzzlehttp/guzzle/src/Handler/StreamHandler.php:368` (HTTP `fopen`). Bundled Monolog, SSH and socket implementations were not established as active plugin paths.

### WordPress transport integrations

Yoast's `src/config/semrush-client.php:43` and `src/config/wincher-client.php:58` inject the wrapper in `src/wrappers/wp-remote-handler.php:49`. Rank Math's `vendor/wp-media/wp-mixpanel/src/TrackingPlugin.php:36–44` selects `consumer => wp`, leading to `vendor/wp-media/wp-mixpanel/src/WPConsumer.php:76`.

WordPress's own cURL/Requests transport is not a bypass: `wp-includes/class-wp-http.php:277–280` applies `pre_http_request` and returns a supplied result before calling Requests at line 416.

## AIOSEO companions

| Companion | Finding |
| --- | --- |
| MonsterInsights 11.3.0 | API requests use `wp_remote_request()` in `includes/api/class-monsterinsights-api.php:211`; tracking uses `wp_remote_post()` in `includes/admin/class-monsterinsights-usage-tracking.php:175`. No direct transport candidate found. |
| OptinMonster 2.17.1 | `OMAPI/Api.php:278` and `OMAPI/OmuApi.php:146` use `wp_remote_request()`. No direct transport candidate found. |
| Broken Link Checker 1.3.1 | Link checks use `wp_safe_remote_get()` in `app/LinkStatus/LocalScan.php:175`. Its asset-watcher `fsockopen()` at `app/Core/Assets.php:547` is gated by development mode at lines 528–543. Development initialization requires `build/.env` (`app/BrokenLinkChecker.php:414–427`), absent from the pinned ZIP. |
| Universally 1.0.11 | Its HTTP client uses `wp_remote_request()` in `app/Http.php:108`. No direct transport candidate found. |

## Interpretation

Using WordPress HTTP lets plugins participate in its filtering, proxy and transport controls. Direct SDK calls need separate review; a streaming feature can have different transport requirements. A bundled alternative is not evidence of an active connection, and a bypass is not by itself a finding that a measured frontend response phoned home.

Keep actual refused-call logs private. If a future run needs to establish raw network behavior, propose a separate trace with its observation method and approval. Do not quietly add full isolation to the default run. In-response waiting stays in elapsed time; there is no synthetic per-connection penalty.
