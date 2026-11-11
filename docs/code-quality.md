# Explain why results differ

Start with a completed evidence bundle and the exact tested plugin packages. A timing result identifies a difference; it does not identify its cause by itself.

## Work in this order

1. **Check the experiment.** Confirm versions, setup completion, active features, routes, cache state, repetitions, and environment limitations.
2. **Describe the difference.** Identify the routes and metric involved, uncertainty, and whether repeated passes agree.
3. **Compare responsibilities.** List the relevant enabled features and work actually performed by each plugin. Identify account-gated or inactive features, defaults, companion plugins, precomputation, and deferred work.
4. **Trace relevant code.** Examine algorithms, repeated work, database access, object creation, sanitization, caching, hooks, and conditional loading. Cite exact source locations and tested versions.
5. **Separate evidence from hypotheses.** Explain what the code establishes and what needs measurement. Record alternatives and counterexamples.
6. **Propose a fair follow-up.** Use controlled experiments with unchanged outcomes, explicit scope, and comparable setup. Obtain approval before setup changes or new tools.

## Keep the explanation fair

- Do not assume speed means missing features. TSF's efficiency may come from how work is organized, cached, reused, or avoided when unnecessary. Verify each explanation.
- Do not assume that more code, a larger archive, or a particular architecture proves worse performance or quality.
- Distinguish request work from setup/indexing, scheduled work, browser-side work, and network-dependent features. A server-response benchmark does not measure all of these.
- Built-in caching is a legitimate design choice. Explain the warm and cold behavior instead of calling a faster result invalid merely because caching exists.
- A smaller measured workload, uncompleted setup, or silently disabled feature is a comparability problem. Describe it concretely.
- Treat readability, maintainability, correctness, security, and runtime efficiency as related but distinct questions. Avoid unsupported security claims.
- Show where TSF performs poorly or the result is inconclusive. Do not write an explanation whose conclusion was chosen in advance.

## Profiling follow-ups

If a profiler is already available, a separate approved diagnostic run can help trace where time is spent. Keep it separate from the reference benchmark and identify its instrumentation overhead. Do not mix profiled timings into unprofiled comparisons.

Do not install Xdebug or another profiler, alter tested plugin code, or configure a new site merely because the user asked for an explanation. Present the proposed follow-up and obtain the necessary approval.

End with a short table: observation, supporting evidence, likely explanation, remaining uncertainty, and a useful next test. Source inspection alone should never be presented as measured attribution.
