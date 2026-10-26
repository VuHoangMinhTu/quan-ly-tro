# Handover audit — 2026-10-26

Scope: HEAD `7fe24bd` Toast/Handover changes. No Dashboard benchmark, production access, backend business changes, checkout/reset/stash, or changes to pre-existing staged `tmp/` artifacts.

## Method

Actual ContractDetailPage, ToastProvider and Axios/React Query code under Vite development profiling; headless local Chrome; actual Laravel HTTP kernel and authorization against a disposable SQLite fixture. Each latency case has one excluded warmup and 20 measured runs. API time is request-start to response-start, summed across both writes for Confirm. Kernel time excludes framework bootstrap. Notification visibility is measured using DOM observation and animation frames after the user click. No full page reload occurred during mutations.

Run `node tests/performance/handover.browser.mjs` from the repository root. Local PHP, Chrome and Playwright paths can be supplied using `HANDOVER_AUDIT_PHP`, `HANDOVER_AUDIT_CHROME`, `PLAYWRIGHT_PACKAGE_PATH`. Ports 18937/18938 must be free. The fixture refuses the application's database, binds to loopback, requires a random audit key, and allowlists Handover routes. Synthetic databases remain under the system temporary directory. `HANDOVER_AUDIT_INTERACTIONS_ONLY=1` reruns interactions while retaining the prior latency summary.

## Current measurements

All times below are median / p95 in milliseconds.

| Case | API | Laravel kernel | Click → Toast | Mutation / refetch GET |
|---|---:|---:|---:|---:|
| Save, 13 items | 254.91 / 293.92 | 81.95 / 93.69 | 273.3 / 315.3 | 1 / 1 |
| Save, 20 items | 262.42 / 292.69 | 88.15 / 104.59 | 280.4 / 309.0 | 1 / 1 |
| Save 422, 20 items | 244.19 / 316.96 | 72.94 / 109.68 | 272.5 / 343.5 | 1 / 0 |
| Confirm, 20 items | 505.16 / 598.64 | 152.43 / 185.98 | 536.2 / 628.3 | 2 / 1 |
| Confirm rejected, 20 items | 508.40 / 561.00 | 159.46 / 172.71 | 730.9 / 808.1* | 2 / 0 |
| Save, 50 items | 278.03 / 295.90 | 102.42 / 120.73 | 299.5 / 318.5 | 1 / 1 |

*Failure is induced by a competing confirmation between PUT and POST. Click-to-Toast includes the fixture reset request used to induce that race; API/kernel measurements exclude that helper request. It is not a normal end-to-end failure latency baseline.*

Save uses one PUT, never Confirm. Confirm intentionally saves using PUT then confirms using POST, suppressing the intermediate success toast. Successful cache invalidation initiates one GET for the active Handover query; validation failures do not retry. Refetch-related ContractDetailPage render count: one on success, zero on failure; parent count zero.

Save query count stays 5–6 across item sizes: four SELECTs, one report UPDATE, and optionally the auth token last-used UPDATE. Save 422 has two SELECTs and no report write. Each successful write uses a transaction. Items are persisted together as JSON, with no per-item queries/N+1. Confirm uses the same bounded reads and updates status in a transaction. Repeated confirmation is rejected by status validation; draft replacement does not append duplicates. Validation/authorization remain intact.

Payloads for 13 / 20 / 50 items: 1666 / 2632 / 6772 bytes. Save React commit median/p95: 2.9/5.6, 4.4/7.7, 10.0/13.8 ms respectively. Initial form render observed around 8–9 / 10–14 / 19–31 ms (development profiler, not a production percentile). The latest interaction metrics JSON contains input-to-next-frame measurements and add/remove timings; add/remove includes Playwright action overhead.

Toast-only commits are about 0.2–0.3 ms, with zero parent/detail/handover renders, zero API requests and zero measured layout shift. Close, four/seven-second expiry, deduplication, scroll visibility and mobile 390×844 geometry passed. A 500-cycle show/clear heap check left no active toast; the roughly 131 KiB retained heap delta alone does not prove absence of every memory leak. Timer/listener cleanup was also inspected.

## Proven regression and fix

Rental item keys included the editable item name. Typing remounted the input and lost focus after `S` in all three form sizes. Changed that key to the existing index convention used by the other Handover branch. Afterward `Synthetic name` is entered fully in all three sizes. No performance-driven business refactor was needed.

Không có baseline trước sửa Toast. `handover-before-input-metrics.json` records the pre-key-fix run, not a baseline for the earlier Toast feature. Its early post-toast GET/render observation window was too short; use current metrics for refetch counts. Do not infer an API speedup from cross-run noise.

## Validation

- Frontend related suite: 32 passed; Handover's four tests rerun after the key fix.
- Backend ContractHandoverApiTest: 9 passed, 71 assertions.
- Browser: success/422/confirm rejection, repeated clicks, one toast, no automatic validation retry, persistence after reload, 13/20/50-item editing, mobile, timers and isolated Toast rendering.
- Frontend build passed. Lint passed with three existing warnings in InvoiceListPage, RoomForm and RoomTenantForm; production build retains its existing large-chunk warning.
- Pint passed for the audit PHP fixture. No backend application code changed.

Limits: SQLite/local development profiling is not a MySQL/production benchmark; mobile is browser viewport emulation, not a physical-device frame-rate test. Raw summaries: `handover-current-metrics.json`.
