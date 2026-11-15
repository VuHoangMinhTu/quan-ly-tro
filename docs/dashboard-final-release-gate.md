# Dashboard Analytics final release gate

Decision: **NO-GO** pending verification of historical Payment timestamps. All browser, fixture and database operations for this gate used the local isolated Laravel/MySQL test instance. There was no authorized environment with independently trustworthy source events matched to stored historical payments for this run.

## Timezone evidence

- Isolated Laravel browser server starts with `APP_TIMEZONE=Asia/Ho_Chi_Minh`. The CLI PHP server is used here; no staging PHP-FPM runtime was inspected. The application default in Git is UTC when APP_TIMEZONE is absent. Git history for config/app.php does not establish deployed configuration at the time each payment was written.
- The isolated MySQL server and session both report `SYSTEM`, with system timezone `SE Asia Standard Time`. Information schema reports `payments.paid_at` is `DATETIME`, which has no offset. These values describe the test instance only.
- PaymentForm initializes datetime-local with `new Date().toISOString().slice(0, 16)` and submits the form value without timezone. A prefilled UTC wall-clock value and a manually entered Vietnam wall-clock value can therefore arrive in the same offset-free shape. PaymentController passes validated input to Eloquent; Payment casts paid_at as datetime. PayOSService parses transactionDateTime through Carbon before using the same Eloquent model. Git revisions 6bfe938, cce6356 and 29014fb document code evolution, not the timezone of stored rows. InvoicePaymentService copies the latest payment datetime to the paid invoice; it does not independently validate the timestamp.
- Test fixtures verify known time conventions. The missing evidence is an authorized read-only sample of manual and PayOS payments near midnight in Vietnam tied to independently recorded local input or offset-bearing PayOS events, plus the deployment timezone chronology. Do not create a production payment or rewrite stored values to obtain evidence.
- If evidence remains incomplete, propose an explicitly approved fallback: suppress historical cash charts, period cash KPIs and cash comparisons in the new Dashboard and return an unsupported cash capability with null values. Keep snapshot and invoice cohort metrics available. Implement and verify the fallback separately before deployment if chosen; this gate does not change cash semantics.

## Browser and diff

Chromium against Laravel and isolated MySQL verified login as owner A, actual menu logout, login as B in the same tab, and no A house label rendered in B. A live Laravel Analytics 200 was held until B loaded and then released. Manager access was removed from the isolated database while the manager remained signed in. A visibility refetch returned 404 and exposed a stale house name in the legacy selector. The fix removes the revoked house from cached selector options when you receive a scoped 404; unit and browser regression checks cover it. An expired bearer returned 401. No production credentials or fixture secrets are tracked.

Diff reviewed against origin/main e279a12. PR #5 head 46f0ab3 is not an ancestor and no paths from its patch intersect this release patch. No writer, migration, invoice incident artifacts, database dumps or credentials in the candidate. The production build excludes the demo entry and fixture strings. The legacy endpoint still passes compatibility coverage. The new guard retains Sanctum validation and suppresses last-used writes; the Analytics suite verifies SELECT-only API behavior.

The three SQLite skips already exist in origin/main. They are the utility, monthly and recalculate data-provider cases of BillingV2MysqlConcurrencyTest. Their explicit guard requires an isolated MySQL instance with a dedicated Billing V2 harness, including port 13368 and schema beehouse_billing_v2_test. The isolated MySQL Analytics run uses port 33396 and deliberately runs the Dashboard suite, not those Billing V2 concurrency cases.

## Deployment plan after the gate is cleared and approval is given

1. Push release/dashboard-analytics, open a PR against main and run GitHub Actions on the exact reviewed commit.
2. Review the diff and CI, including production bundle exclusion, old/new API compatibility, financial timestamp evidence and rollback readiness. Merge only after approval.
3. Deploy the backward-compatible backend first. With authorized read-only smoke requests, check GET /api/dashboard, both new endpoints, 401 and foreign-house 404, plus current/previous totals against verified samples.
4. Deploy the frontend after backend smoke passes. Verify login, snapshot, chart filters, previous period, empty/error states and desktop/mobile.
5. If UI fails, roll frontend back to the preceding artifact while leaving the backward-compatible backend in place. If the backend fails, restore the previous frontend first if already deployed, then roll back the backend artifact. Verify the legacy Dashboard and monitoring after rollback. No data rollback is required for these read-only changes.
