# Agent usage and cost estimates

Read this alongside [workload and time choices](run-budget.md) before proposing a run. Ask for the user's available time, workload, model/billing method, and any spending cap. These are estimates, not a quote or a promise of statistical accuracy.

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

USD per million tokens, checked 2026-11-11. Prices can change; verify the linked provider page before a new proposal. Model names, tokenizers, reasoning settings, context size, cache behavior, service tier, and regional endpoints can all change the bill.

| Model / API mode | Ordinary input | Cache read | Cache write | Output | Same numerical supervisor usage | Actual benchmark evaluation |
| --- | ---: | ---: | --- | ---: | ---: | --- |
| GPT-6.1 Sol Standard, ≤272k input | $2 | $0.10 | $2.50/30m; $5/12h | $10 | $14.79 with actual per-request context bands | Windows, high effort |
| GPT-6.1 Sol Standard, >272k input | $4 | $0.20 | $5/30m; $10/12h | $15 | Included in row above | 96 of the 320 calls crossed this band |
| GPT-6.1 Sol Fast | 2× Standard | 2× Standard | 2× Standard | 2× Standard | $29.58 | Speed tier not independently logged |
| Grok 4.7 Standard, <200k / ≥200k input | $2 / $4 | $0.50 / $1 | No separate category listed | $6 / $12 | $72.08 | **Not evaluated**; macOS run planned |
| Claude Sonnet 5.5 Standard | $2 | $0.10 | $2.50/5m; $4/1h | $10 | $10.66 | **Not evaluated** |
| Claude Opus 5.5 Standard | $4 | $0.20 | $5/5m; $8/1h | $20 | $21.31 | **Not evaluated** |

These dollar totals are **hypothetical token-price conversions**, not charges paid, predicted model token consumption, or evidence that one agent is cheaper at completing this task. They reuse the same numeric request/token counts, logged zero writes, and the applicable context bands. Different models will tokenize and reason differently. They exclude tool fees, taxes, residency premiums, unrecorded writes, and other billing adjustments. Fast mode was reported by the operator; both Standard and Fast scenarios are shown rather than asserting a verified API tier.

OpenAI's long-context band applies to the whole request, including cached input; Fast doubles Standard API rates. Reasoning effort changes consumption, not a separately listed high-effort token tariff. [OpenAI model/rates](https://developers.openai.com/api/docs/models/gpt-6.1-sol), [pricing](https://developers.openai.com/api/docs/pricing). Grok's 200k threshold and prices are from [xAI's Grok 4.7 documentation](https://docs.x.ai/developers/models/grok-4.7.md); Cursor/Build's Grok Fast is a different billing mode. Claude rates and cache TTLs are from [Claude pricing](https://platform.claude.com/docs/en/about-claude/pricing).

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

## Budgeting a future run

A fresh-chat rerun of this finished repository has **not been token-benchmarked**. Use a provisional budget, not the development totals above as a typical expectation:

| Planning scenario | Assumed agent calls × average input | Input budget | Assumed cached share | Output budget | Sol Standard short-context token estimate |
| --- | --- | ---: | ---: | ---: | ---: |
| Lean supervision: smaller/faster workload on a qualified stack | 25 × 20k | 0.5M | 90% | 10k | $0.25 |
| Full Windows reference: setup/run/report allowance | 200 × 40k | 8M | 90% | 100k | $3.32 |
| Troubleshooting reserve | 320 × 80k | 25.6M | 90% | 250k | $9.92 |

**All three are unevaluated planning scenarios**, not measured token ranges or spending caps enforced by this tool. They exclude cache writes/tool charges and assume requests stay in the short-context band; Fast doubles these illustrative API figures. For a fresh-chat full Windows rerun, provisionally reserve roughly **3–12M input and 50k–150k output**, with a separate troubleshooting reserve. The basis is the observed 156 collection-window model calls: a hypothetical 20k–60k average fresh-chat context would alone consume 3.12–9.36M input before setup and review. The 25-call scenario does not cover this host's full 2½-hour run when its harness requires frequent inference round-trips. Smaller scopes and faster stacks may need less, but have not been token-benchmarked. Replace these provisional figures with actual usage after the next independent run. A huge thread, failed setup, or subagents can exceed them substantially. Stop and explain the situation before exceeding a user's agreed budget; do not silently weaken the experiment.

Estimate calls from setup/review work plus `ceil(collection_seconds / agent_roundtrip_interval_seconds)` if the harness needs repeated monitoring calls. Estimate tokens from those calls and the observed context/output sizes. Do not assume a tenfold increase in PHP samples causes tenfold LLM usage, or that waiting automatically costs zero tokens under every harness. Follow the host's progress requirements, use compact log excerpts, and avoid loading raw request files or the entire transcript repeatedly. PHP handles request collection and statistical analysis.

## Subscription costs are different

| Billing route | What to record | Cost for another benchmark |
| --- | --- | --- |
| Signed-in Codex on ChatGPT Pro $200 | Subscription, speed/effort, included allowance and remaining limits | Possibly no additional cash charge while included; still consumes allowance. Actual per-run allocation **not evaluated**. |
| Cursor Ultra | Plan generation, selected model, included pool, prepaid/on-demand settings | **Not evaluated**. Legacy/current Ultra differ; a subscription name alone does not establish third-party inclusion. |
| Direct API | Final provider usage/cost records and pricing snapshot | Metered cost; credits, discounts, taxes and tools can alter payment. |

Existing subscriptions can make incremental cash cost much lower than API-equivalent usage, but do not divide the monthly fee by one run and call that a measured tariff. Report monthly price, allowance consumed, extra charges, and optional allocation assumptions separately. Current Codex subscription usage has no Sol long-context surcharge and no separate cache-write credit charge. [Official subscription pricing](https://learn.chatgpt.com/docs/pricing)

Some eligible Pro subscriptions can claim API credits, subject to activation and restrictions; that is separate from signed-in Codex, and Codex API-key use is excluded from that benefit. Do not assume this operator claimed it. [OpenAI API-credit benefit](https://developers.openai.com/api/docs/guides/chatgpt-api-credits). Cursor's current and legacy Ultra allowances are described separately in [current pricing](https://cursor.com/docs/models-and-pricing) and [legacy plans](https://prod.cursor.com/docs/account/pricing/legacy-cursor-plans).

## Record the next comparison

For the planned **Grok 4.7 / macOS** run, record the exact provider/model/version, effort/speed, agent app/version, billing route, stack/hardware, workload, agent-call count, uncached/cache-read/cache-write/output/reasoning counts, extra tool charges, elapsed phase times, and verdict. Mark absent fields **not evaluated**. Use a fresh chat where possible, but preserve the same approved recipe when claiming a matched reproduction. Different OS/stack/model runs compare end-to-end operator cost; they do not isolate model efficiency alone. Never upload raw chat/account logs with benchmark exports.
