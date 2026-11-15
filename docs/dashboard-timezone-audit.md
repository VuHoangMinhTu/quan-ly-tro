# BeeHouse production timezone audit

Date: 2026-11-15, Vietnam time. Release candidate inspected: `63a95095d21e72512281715dd87704c41ad52274`. **Historical timezone: BLOCKED. Full Dashboard Analytics release with cash-by-time: NO-GO.**

## Production evidence

Evidence was collected from an authorized, host-key-verified production session.

| Check | Observed result |
|---|---|
| Backend checkout | `e279a12354410f2dac135ee14b19c6d1944ac02d`; tracked writer/model/app-config files matched HEAD. This identifies the deployed checkout, not an OPcache execution probe. |
| Operating system | `Asia/Ho_Chi_Minh`, UTC+07:00 |
| PHP CLI before Laravel | UTC |
| PHP-FPM | php-fpm8.3 running; the FPM binary's configuration inspection reported UTC. Readable php.ini and www.conf had no active timezone override matching the inspected directives. This was not a live worker request. |
| Laravel | `APP_TIMEZONE=Asia/Ho_Chi_Minh`; config cache present and contains the same timezone. Running Laravel's environment/config bootstrap from the deployed checkout set PHP's effective timezone to `Asia/Ho_Chi_Minh`. The live FPM worker/cache state was not independently probed. |
| MySQL | Global and audit session timezone `SYSTEM`; system timezone `+07`. `payments.paid_at` is `DATETIME`, which has no timezone or offset. |

Database evidence was collected with aggregate, bounded sample and schema SELECTs in a read-only transaction.

## Historical comparison

No eligible Payment records were found for comparison.

Eligibility required a present, non-deleted invoice with status UNPAID, PARTIALLY_PAID or PAID, excluding known incident records. The bounded sample searched near both 00:00 and 17:00 stored wall time to cover Vietnam-local and candidate UTC midnight. It returned zero rows, so no paid_at/created_at pair met the evidence threshold. Even with a match, created_at would show insertion time, not independently prove payment time.

The PayOS request schema did not retain an independently verifiable source transaction timestamp or webhook payload. No eligible PayOS Payment existed to correlate with a source event.

## Writers and reproduction

- Frontend PaymentForm uses `datetime-local`. Its default is `new Date().toISOString().slice(0, 16)`, which puts a UTC clock reading into an offset-free input. On edit it also slices the supplied timestamp, discarding its suffix. Submission forwards the field as entered.
- StorePaymentRequest and UpdatePaymentRequest validate `paid_at` with the date rule; they do not normalize it. PaymentController passes the validated data into relationship create or model update. Payment's Eloquent datetime cast parses input and formats it as `Y-m-d H:i:s` with no offset normalization before writing.
- PayOSService parses transactionDateTime with Carbon and falls back to `now()` when parsing throws. It hands the Carbon value to the same cast. Carbon can retain an explicit source offset, but the storage formatter drops it while keeping that offset's clock reading. Offsetless input uses the runtime default. The fallback has no independently verified payment instant.
- The application writer scan found manual create/update and PayOS webhook create. InvoicePaymentService derives the invoice's paid_at from payment rows; this is not a separate Payment writer. Git history shows the timezone config default and the original parse/form patterns in `6bfe938`, frontend validation evolution in `cce6356`, and PayOS channel/profile changes through `29014fb`. Source history cannot reconstruct past deploy configuration or input offsets.

Reproduction used the candidate's actual Eloquent Payment setter and PayOS parser, with the resulting strings round-tripped through separate SQLite memory storage. It ran 24 cases, including 02:30, 23:59, month rollover and year rollover. The manual frontend default was reproduced as the same UTC ISO truncation. No invoices, provider calls or production writes were involved.

| Intended Vietnam time | Stored with local input or equivalent +07 input | Stored with UTC prefill or equivalent explicit UTC input |
|---|---|---|
| Nov 15 02:30 | Nov 15 02:30 | Nov 14 19:30 |
| Nov 30 23:59 | Nov 30 23:59 | Nov 30 16:59 |
| Dec 01 02:30 | Dec 01 02:30 | Nov 30 19:30 |
| Jan 01 02:30 | Jan 01 02:30 | Dec 31 19:30 |

The manually entered local and PayOS local-offset cases matched when read as Vietnam wall time. Leaving the UTC-prefilled manual value unchanged writes the wrong clock value for an offset-free Vietnam form. With a correct explicit UTC instant, the parser can identify the instant, but persistence loses its offset. The resulting value becomes wrong when read under the assumed Vietnam storage convention. The same unchanged DATETIME could be correct under a UTC storage convention. Analytic day, month and year buckets can therefore be wrong when conventions mix. These are proven code paths, not proof of a misbucketed eligible production payment.

## Proposed decision and remediation

1. Define UTC as the storage convention for new payments, with an explicit format and a provable cutover marker. Accept offset-bearing ISO 8601 instants from the browser, converting the chosen Vietnam wall time to an instant before submission. Round-trip edited values through the intended display zone rather than slicing serialized strings.
2. On the backend, require or explicitly resolve a documented source zone, normalize validated instants to UTC before persistence, and explicitly interpret stored UTC on reads. For PayOS, confirm the provider's offsetless timestamp contract before allowing it; flag invalid times for reconciliation rather than silently treating ingestion time as receipt time. Review writer changes separately.
3. For verified UTC records, build Vietnam-local half-open boundaries, convert them to UTC for indexed filtering, and assign local-day buckets after conversion. Keep independently verified legacy cohort conventions separate. Do not infer an offset from created_at, payment source alone, current APP_TIMEZONE or proximity to midnight.
4. For uncertain legacy records, retain their original values and exclude them from claims of reliable cash-by-time until evidence exists. Production currently supplies zero eligible rows, so the audit cannot resolve historical convention. Before release, approve and test a reduced scope that marks historical cash-by-time unsupported, hides the cash chart and period cash KPI/comparison, and retains snapshot and invoice-cohort metrics with their existing meanings.

No config, cache, service, writer, historical payment, incident record or deployment was changed. Runtime evidence is limited to read-only configuration bootstrap and binary/config inspection; full historical comparison remains unavailable.
