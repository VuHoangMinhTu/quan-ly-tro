# BeeHouse Payment Timezone Fix

Status: planned for a separate release. The reduced Dashboard release ships with cash-by-time unsupported. Invoice cohort totals, settled-to-date amounts and outstanding balances remain available.

## New payments

1. Use UTC as the storage convention for new payment instants. Define a durable version or provenance marker for normalized rows and a verified cutover. Specify how edits to existing payments preserve or intentionally change provenance; an edited legacy row must not silently become verified.
2. Display and edit timestamps in `Asia/Ho_Chi_Minh`. Interpret the user's selected wall time in that zone and submit ISO 8601 with an explicit offset. Apply the same conversion to default and edit values.
3. Require an unambiguous instant at the backend boundary, convert it explicitly to UTC before persistence and explicitly interpret normalized stored values as UTC on read. Keep `created_at` separate from the payment instant.
4. Document PayOS timestamp semantics from the source contract before implementation. Preserve explicit source offsets when parsing, then normalize the resultant instant. Assign a documented zone to offsetless timestamps only when the provider guarantees it. Quarantine ambiguous or invalid times for reconciliation instead of substituting ingestion time as receipt time.

## Legacy handling

Preserve original stored values. Do not apply a blanket seven-hour correction. Classify legacy records only with independent source evidence, such as an offset-bearing provider event or verified manual entry. Rows without an evidenced convention remain unverified. If a future version reports only a verified cohort, show that coverage and its starting date clearly. Never classify solely by payment source, proximity to midnight, current APP_TIMEZONE or created_at.

## Re-enable gate

- Test manual create/update and PayOS normalization. Include 02:30, 23:59, offset-bearing and offsetless inputs, UTC/Vietnam day edges, month and year rollover, and mixed legacy/normalized storage.
- Verify a real MySQL round trip and reconcile source timestamps against stored values. Confirm payment, invoice, reconciliation and permissions tests before rollout.
- Update Analytics to query only verified storage cohorts, convert Vietnam-local half-open boundaries to each verified storage convention, and ensure the grouping uses the same conversion. Review compare periods for equal evidence coverage.
- Set `DASHBOARD_CASH_SERIES_ENABLED=true` only after the writer fix, provenance policy and verified Analytics query are reviewed and pass staging checks. The current switch preserves the old analytics query for testing and is not itself a mixed-timezone solution. Keep the flag off when the evidence is insufficient.
- Verify the supported capability end to end. Rolling back the feature flag must remove cash metrics and cash aggregates from the new Dashboard. Preserve compatibility for legacy API callers with a separate review.

No Payment or PayOS writer, schema, or historical data changes are part of the reduced Dashboard release. See [production timezone audit](dashboard-timezone-audit.md) for evidence and limits.
