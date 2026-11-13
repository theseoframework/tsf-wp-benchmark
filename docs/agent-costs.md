# Agent usage and cost estimates

Read this alongside [workload and time choices](run-budget.md) before proposing a run. **State estimated time and cost; do not ask the user for a time or spending budget.** Recommend a workload, show the alternatives, and let the user change it. Honor limits the user volunteers. These are estimates, not a quote or a promise of statistical accuracy.

WordPress requests run in PHP; they do not each require an LLM request. Agent tokens pay for discovery, planning, troubleshooting, progress checks, and explaining results. A long test can run with few agent calls, but frequent polling and large conversation histories can make supervision expensive.

## Recorded Windows development run

**Benchmark agent: GPT-6.1 Sol high**, running in Codex. The local session metadata records `gpt-6.1-sol` with effort `high` throughout the supervisor implementation/test turn. This identifies the historical operator, not the agent that must run future tests. ChatGPT Pro $200 was the operator-reported billing plan; an API invoice was not available.

This was a first integration: it included writing the supervisor, repairing the harness, failed attempts, regression tests, repeated qualification, full collection, cleanup, and report replay. It also inherited a very long chat. **It is not a measured cost for simply rerunning the finished tool.**

| Scope on 2026-11-11, UTC | Model calls | Total input | Cached input (within input) | Uncached input | Output (includes reasoning) | Reasoning (within output) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Windows discovery, setup and test; 13:51–20:21 cutoff | 522 | 124,577,872 | 123,785,600 | 792,272 | 349,824 | 189,942 |
| Supervisor implementation and test; 16:23–20:21 cutoff | 320 | 75,664,040 | 75,217,664 | 446,376 | 224,181 | 127,053 |
| Final collection window only; 17:38:26–20:02:32 | 156 | 37,166,344 | 36,941,568 | 224,776 | 74,384 | 54,223 |

Rows overlap; **do not sum them**. Scope is this root chat's de-duplicated per-response usage records, not an account-wide bill. Earlier delegated-agent usage was not separately audited. The supervisor turn launched no subagents. Earlier unrelated mega-chat requests and this cost-documentation follow-up are excluded; inherited history processed during the selected windows is included because it consumes tokens. The broad discovery row includes 83 ultra-effort calls and 439 high-effort calls; the two narrower rows are all high effort.

The supervisor row contains **75,888,221 total tokens**, including input and output. Cache-write input was logged as zero. Summed per-response usage matches the final turn counter. Cached input was 99.41% of input: that reduces cost considerably, but repeated history is not free or necessarily negligible. The records do not isolate how much cached text came from the old conversation versus instructions, tools, or new results.

The aggregate [usage record](agent-usage-2026-11-11.json) includes token totals split by the pricing context bands, so the conversions below can be recomputed. It contains no transcript, private session identifiers, local paths, credentials, or fixture secrets. Raw logs remain private. Do not read unrelated chats or authentication files to collect usage.

## API-equivalent scenarios

USD per million tokens, checked 2026-11-14. Prices can change; verify the linked provider page before a new proposal. Model names, tokenizers, reasoning settings, context size, cache behavior, service tier, and regional endpoints can all change the bill.

| Model / API mode | Ordinary input | Cache read | Cache write | Output | Same numerical supervisor usage | Actual benchmark evaluation |
| --- | ---: | ---: | --- | ---: | ---: | --- |
| GPT-6.1 Sol Standard, ≤272k input | $2 | $0.10 | $2.50/30m; $5/12h | $10 | $14.79 with actual per-request context bands | Windows, high effort |
| GPT-6.1 Sol Standard, >272k input | $4 | $0.20 | $5/30m; $10/12h | $15 | Included in row above | 96 of the 320 calls crossed this band |
| GPT-6.1 Sol Fast | 2× Standard | 2× Standard | 2× Standard | 2× Standard | $29.58 | Speed tier not independently logged |
| Grok 4.7 direct xAI API Standard, <200k / ≥200k input | $2 / $4 | $0.50 / $1 | No separate category listed | $6 / $12 | $72.08 | macOS setup/discussion usage recorded separately below; completed benchmark not evaluated |
| Claude Sonnet 5.5 Standard | $2 | $0.10 | $2.50/5m; $4/1h | $10 | $10.66 | **Not evaluated** |
| Claude Opus 5.5 Standard | $4 | $0.20 | $5/5m; $8/1h | $20 | $21.31 | **Not evaluated** |

These dollar totals are **hypothetical token-price conversions**, not charges paid, predicted model token consumption, or evidence that one agent is cheaper at completing this task. They reuse the same numeric request/token counts, logged zero writes, and the applicable context bands. Different models will tokenize and reason differently. They exclude tool fees, taxes, residency premiums, unrecorded writes, and other billing adjustments. Fast mode was reported by the operator; both Standard and Fast scenarios are shown rather than asserting a verified API tier.

OpenAI's long-context band applies to the whole request, including cached input; Fast doubles Standard API rates. Reasoning effort changes consumption, not a separately listed high-effort token tariff. [OpenAI model/rates](https://developers.openai.com/api/docs/models/gpt-6.1-sol), [pricing](https://developers.openai.com/api/docs/pricing). The Grok row above converts the historical Sol records using the direct [xAI API's 200k threshold](https://docs.x.ai/developers/models/grok-4.7.md). Cursor uses a different 256k threshold and speed/context combinations; use its own rate table for Cursor exports, as in the Mac record below. Claude rates and cache TTLs are from [Claude pricing](https://platform.claude.com/docs/en/about-claude/pricing).

For normalized usage with disjoint input categories:

```text
ordinary_input = total_input - cache_reads - cache_writes
USD = sum_per_request(
    ordinary_input * input_rate
  + cache_reads * read_rate
  + writes_by_TTL * write_rate_by_TTL
  + output * output_rate
) / 1,000,000 + separately billed tools
```

Cache writes replace ordinary-input billing; do not add them twice. OpenAI reasoning is already part of output. Provider schemas differ: normalize Claude's separate raw cache/input fields before using this formula. Preserve unknown fields as unknown, not zero. Missing TTL/tier data prevents an exact invoice reconstruction. [OpenAI cache accounting](https://developers.openai.com/api/docs/guides/prompt-caching), [reasoning usage](https://developers.openai.com/api/docs/guides/reasoning). When exposed, xAI's final per-request `cost_in_usd_ticks / 10^10` gives billed cost; do not sum streaming running totals. [xAI cost tracking](https://docs.x.ai/developers/cost-tracking.md)

## Estimate a future run before asking for setup approval

Use the model and billing details exposed by the current agent harness. Do not guess its model from the app name or ask the user to research prices. Look up the exact model's current official provider rates, including context bands, cache rules, speed tier and tool fees, and cite the source with the estimate. Read only the usage/session information for this authorized run; account credentials and unrelated chats are not needed. If the model or rates are unavailable, show the token range and an explicitly named example model's dollar estimate instead of claiming a price for the unknown model.

An estimate does not require measured usage. Say **actual token usage: not evaluated** when counters are unavailable, then still provide an estimated dollar range from the assumptions below. If billing is unknown, give an API-equivalent estimate and say subscription charges are unknown. Do not make billing or budget questions a setup prerequisite. For a known subscription, distinguish included allowance consumption from extra cash charges; never promise that a run is free.

A fresh-chat rerun of this finished repository has **not been token-benchmarked**. Use a provisional budget, not the development totals above as a typical expectation:

| Planning scenario | Assumed agent calls × average input | Input budget | Assumed cached share | Output budget | Sol Standard short-context token estimate |
| --- | --- | ---: | ---: | ---: | ---: |
| Lean supervision: smaller/faster workload on a qualified stack | 25 × 20k | 0.5M | 90% | 10k | $0.25 |
| Full Windows reference: setup/run/report allowance | 200 × 40k | 8M | 90% | 100k | $3.32 |
| Troubleshooting reserve | 320 × 80k | 25.6M | 90% | 250k | $9.92 |

**All three are unevaluated planning scenarios**, not measured token ranges or spending caps enforced by this tool. They exclude cache writes/tool charges and assume requests stay in the short-context band; Fast doubles these illustrative API figures. For a fresh-chat full Windows rerun, provisionally estimate roughly **3–12M input and 50k–150k output**, with a separate troubleshooting reserve. The basis is the observed 156 collection-window model calls: a hypothetical 20k–60k average fresh-chat context would alone consume 3.12–9.36M input before setup and review. The 25-call scenario does not cover this host's full 2½-hour run when its harness requires frequent inference round-trips. Smaller scopes and faster stacks may need less, but have not been token-benchmarked. Replace these provisional figures with actual usage after the next independent run. A huge thread, failed setup, or subagents can exceed them substantially. Explain a material overrun and revise the estimate; obtain approval for additional setup work or spending outside the approved scope. Honor any user-supplied cap without silently weakening the experiment.

The same full-run token range gives these **illustrative API-equivalent estimates**, using the rates above. Neither cache case is a measured cache forecast or a guaranteed upper bound. All input is short-context; cache writes and tool fees are excluded and must be added when applicable.

| Model / tier | Assuming 90% cache reads | Assuming no cache reads | Usage evaluation for a routine rerun |
| --- | ---: | ---: | --- |
| GPT-6.1 Sol Standard | $1.37–$4.98 | $6.50–$25.50 | Not evaluated |
| GPT-6.1 Sol Fast | $2.74–$9.96 | $13.00–$51.00 | Not evaluated |
| Grok 4.7 Standard | $2.25–$8.70 | $6.30–$24.90 | Not evaluated |
| Claude Sonnet 5.5 Standard | $1.37–$4.98 | $6.50–$25.50 | Not evaluated |
| Claude Opus 5.5 Standard | $2.74–$9.96 | $13.00–$51.00 | Not evaluated |

For example, Grok's lower cached case is `(3M × (10% × $2 + 90% × $0.50) + 50k × $6) / 1M = $2.25`. Its upper case uses 12M input and 150k output. Calculate the selected model's own range this way; do not reuse Sol's dollar figure for another model. For requests reaching a provider's long-context band, apply that band to every applicable token in each request, rather than to just the tokens above the threshold. The no-cache column excludes cache writes, so it is not a worst-case bill.

Give the user a short proposal: **recommended workload; observed/projected collection time plus setup and review; estimated input/output range; estimated API-equivalent dollars with cache assumptions; subscription billing unknown or known; separate troubleshooting allowance.** Offer 10/40/100 and route/configuration alternatives without turning these estimates into a budget questionnaire. Use the relevant Windows or Mac observations in the time guide; do not scale tokens directly from hardware speed or HTTP sample count.

Estimate calls from setup/review work plus `ceil(collection_seconds / agent_roundtrip_interval_seconds)` if the harness needs repeated monitoring calls. Estimate tokens from those calls and the observed context/output sizes. Do not assume a tenfold increase in PHP samples causes tenfold LLM usage, or that waiting automatically costs zero tokens under every harness. Follow the host's progress requirements, use compact log excerpts, and avoid loading raw request files or the entire transcript repeatedly. PHP handles request collection and statistical analysis.

## Subscription costs are different

| Billing route | What to record | Cost for another benchmark |
| --- | --- | --- |
| Signed-in Codex on ChatGPT Pro $200 | Subscription, speed/effort, included allowance and remaining limits | Possibly no additional cash charge while included; still consumes allowance. Actual per-run allocation **not evaluated**. |
| Cursor / Grok subscription | Plan generation, selected model, eligible pool and reset period, speed/context, prepaid/on-demand settings | Mac setup rows are marked Included; exact pool debit and routine rerun cost remain unverified. Legacy/current plans differ. |
| Direct API | Final provider usage/cost records and pricing snapshot | Metered cost; credits, discounts, taxes and tools can alter payment. |

Existing subscriptions can make incremental cash cost much lower than API-equivalent usage, but do not divide the monthly fee by one run and call that a measured tariff. Report monthly price, allowance consumed, extra charges, and optional allocation assumptions separately. Current Codex subscription usage has no Sol long-context surcharge and no separate cache-write credit charge. [Official subscription pricing](https://learn.chatgpt.com/docs/pricing)

Some eligible Pro subscriptions can claim API credits, subject to activation and restrictions; that is separate from signed-in Codex, and Codex API-key use is excluded from that benefit. Do not assume this operator claimed it. [OpenAI API-credit benefit](https://developers.openai.com/api/docs/guides/chatgpt-api-credits). Cursor's current and legacy Ultra allowances are described separately in [current pricing](https://cursor.com/docs/models-and-pricing) and [legacy plans](https://prod.cursor.com/docs/account/pricing/legacy-cursor-plans).

### Show the applicable allowance percentage

When the eligible allowance is known from the harness or supplied by the user, also state `100 × estimated eligible usage cost / eligible allowance`, with its reset period and source. This is the proposed run's share of that pool, not the account's current used or remaining percentage. Actual remaining balance needs separate evidence. If a platform exposes a percentage directly, prefer its observed before/after change; a dollar conversion is not automatically the platform's pool weighting. If the denominator is unknown, say so without asking a budget question.

Legacy Cursor keeps Cursor Models and Other Models in separate monthly pools. Grok draws from Cursor Models; the extra Other Models amount cannot simply be added to Grok's denominator. Current plans instead share a weekly Cursor-model pool across products. Confirm the plan generation before presenting an allowance as an entitlement. [Legacy pools](https://cursor.com/docs/account/pricing/legacy-cursor-plans), [current plans](https://cursor.com/docs/models-and-pricing)

For a clearly labelled illustration using the operator-reported **$2,000 Cursor Models allowance** and the published **1 / 4 / 15 / 50** relative usage tiers, the normalized allowances and this setup's regular-rate shares are:

| Tier label | Relative units | Hypothetical eligible allowance | $12.87588 setup share |
| --- | ---: | ---: | ---: |
| Plus | 1 | $40 | 32.19% |
| Premium | 4 | $160 | 8.05% |
| Super | 15 | $600 | 2.15% |
| Ultra | 50 | $2,000 | 0.64% |

These are scaling assumptions, **not published dollar entitlements**: the dollar anchor is operator-reported, and current tier multipliers must not silently be applied to legacy billing periods. The operator also reports a $400 API allowance; verify its allocation rather than assuming it is available for Grok. On legacy Cursor, Other Models is a separate pool. The current Plus tier fixes effort at medium, so its row is not a claim that it can run this high-effort configuration. Keep pool period, model eligibility and actual billing distinct from this arithmetic.

## Recorded Grok 4.7 high Mac setup and discussion

The operator supplied the setup transcript and later its Cursor usage export. This scope includes Local setup, troubleshooting, code edits and discussion; timed benchmark collection did not complete. The [aggregate usage record](agent-usage-2026-11-13.json) retains the totals and pricing assumptions without account or request identifiers.

| Category | Recorded tokens | Regular-rate API-equivalent cost |
| --- | ---: | ---: |
| Input without cache | 445,507 | $0.891014 |
| Cache reads | 22,042,880 | $11.021440 |
| Cache writes | 0 | $0 |
| Output | 160,571 | $0.963426 |
| **Total** | **22,648,958** | **$12.875880** |

All 18 numeric rows reconcile. Cache reads are **98.02% of input** (22,488,387 tokens). One additional Free row has blank token fields: those tokens remain unknown, not zero. Usage rows may aggregate multiple model requests, so 18 rows do not establish 18 inference calls. Reasoning tokens were not separately supplied and are not added again. The numeric rows span 1 h 48 min 7 s; that is a timestamp span including setup/discussion and waits, not active inference time or benchmark runtime.

The regular scenario assumes Cursor standard speed and no individual request above 256k input tokens, at $2 input / $0.50 cache read / $6 output per million. The same recorded totals cost **$25.751760** if all requests are Fast within 256k, or standard above 256k; all-long-context Fast is **$38.627640**. Mixed requests require their actual breakdown. Max Mode is No, but that flag alone does not establish Fast mode or individual context sizes. Cursor documents Fast as a default on several paid plans, so do not silently infer standard speed from the model name. [Cursor Grok settings and rates](https://cursor.com/docs/models/grok-4-7)

The export labels each numeric row **Included**; no extra cash payment or exact allowance debit can be reconstructed from it. Under the operator-reported $2,000 eligible pool and the regular-rate assumption, this scope represents **0.64%**. At the all-Fast short-context rate it is **1.29%**. Do not use $2,400 as an interchangeable Grok pool or call these observed dashboard percentages.

The operator observes roughly **30% more output at xhigh than high**. As an output-only planning scenario with input held fixed, that changes 160,571 to about 208,742 output tokens and the regular equivalent to **$13.164908**: **+$0.289028, or 2.24% overall**. This is an operator-informed assumption, not a measured paired effort test or a 30% whole-bill surcharge. Future input/call-count changes must be measured separately.

This is useful preliminary setup evidence, not the expected cost of a routine completed benchmark. The earliest row contains the largest uncached-input amount, but phase costs cannot be separated from the discussion using timestamps alone; repeated cached context contributes most of this price scenario. For the intended 40-tier retry, preserve this record and capture setup, collection supervision and review separately. Its approximately one-hour label came from the Windows projection, not a measured Mac duration or a stopping deadline. Keep the declared sampling policy fixed.

## Record the next comparison

For the next independent run, record the exact provider/model/version, effort/speed, agent app/version, billing route when known, stack/hardware, workload, agent-call count, uncached/cache-read/cache-write/output/reasoning counts, extra tool charges, elapsed phase times, and verdict. Mark absent actual fields **not evaluated**, separately from the estimate given before setup. Use a fresh chat where possible, but preserve the same approved recipe when claiming a matched reproduction. Different OS/stack/model runs compare end-to-end operator cost; they do not isolate model efficiency alone. Never upload raw chat/account logs with benchmark exports.
