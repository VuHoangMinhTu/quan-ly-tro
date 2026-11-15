# Dashboard release QA

Base: origin/main `e279a12`. Candidate: `release/dashboard-analytics`. Deployment status: **BLOCKED** until historical Payment timestamp conventions are verified.

## MySQL benchmark

MySQL 8.0.41, localhost, dedicated datadir, temporary table with DECIMAL(15,2) data. Fixture combines one invoice and one payment per row, distributed over 24 houses across current and previous years. Queries select 20 houses, 366 daily buckets, and include a separate previous-period total. Values for every bucket matched at scale 2. Timings are medians of three measured PDO executions after warm-up, including fetch and previous-period totals. This isolates the aggregate expressions and is not a measurement of end-to-end HTTP or production join latency.

| Invoice/payment pairs | Invoice CASE → GROUP BY (ms) | Payment CASE → GROUP BY (ms) |
|---:|---:|---:|
| 1,000 | 91.20 → 1.11 | 10.95 → 1.28 |
| 10,000 | 1,229.60 → 9.03 | 132.31 → 7.04 |
| 100,000 | 16,117.55 → 292.80 | 2,410.86 → 140.48 |

At 100,000 pairs, EXPLAIN ANALYZE aggregate nodes took 24,252 → 144 ms for invoice and 1,678 → 51.8 ms for payment. GROUP BY emitted 12 invoice cohort rows and 366 payment-day rows. The payment plan scanned 100,000 rows and passed 41,665 through the scope/range predicate. Timing variation between repeated PDO and EXPLAIN runs is expected. Existing Laravel query count regression covers 1 and 21 houses and keeps the 366-day request at at most seven SELECTs; prior measurement was six. Compare adds four aggregates. No new indexes.

The MySQL candidate groups by local calendar day for multi-bucket ranges and folds daily results into the requested buckets with BigDecimal. The filter stays on the original indexed date column. For payment DATETIME, UTC storage uses a fixed +07:00 projection; Vietnam storage uses the date as written. For other configured storage zones the payment query retains bounded CASE expressions. Single-bucket and non-MySQL paths retain their prior aggregation.

## Timezone release blocker

The application defaults APP_TIMEZONE to UTC. The local browser fixture explicitly uses Asia/Ho_Chi_Minh. PaymentController accepts a date through Form Request and persists through the Eloquent datetime cast. PayOSService parses its incoming transaction timestamp with Carbon and writes through the same cast. MySQL DATETIME stores no zone or offset. The current writer code and local fixtures cannot establish the timezone of historical values across writers and deploys. Required before release: confirm the permitted staging configuration and compare stored samples around Vietnam midnight against trusted manual input and PayOS event timestamps. Do not edit historical rows as part of QA.

## Compatibility and isolation

The original GET /api/dashboard stays on its original guard. The new guard uses Sanctum's token validation and turns off the last-used database write. Neither existing Dashboard route declares a token-ability policy; the current scope comes from server-side house ownership and manager assignments. Read-only tests exercise expired tokens, ability metadata, owner/manager scope, revocation and SELECT-only requests. The production build uses the normal app entry, and demo assets are checked separately for exclusion.

Integrated checks: full SQLite suite 789 passed and 3 skipped; six MySQL Analytics tests passed (106 assertions); 260 frontend tests, build, lint and targeted Pint passed. Chromium used Laravel and a separate MySQL fixture database for day/week/month/quarter/year/custom, compare, house scope, refresh, and desktop/mobile (390 px). A delayed real response verified loading without old-period amounts; a controlled HTTP 500 verified the error display. The old endpoint bootstraps the lists, whose captions indicate the all-house scope. Browser coverage used one owner session; manager revocation was exercised at the API layer, not in the browser.
