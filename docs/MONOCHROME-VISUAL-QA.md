# Monochrome visual QA — first screen group

Historical first-group report. The remaining groups below have since been implemented and verified; see `MONOCHROME-COMPLETION.md` for the final scope and results.

## Evidence

Runner: `tests/e2e/monochrome-visual.browser.mjs`. Reuses the existing synthetic-session server, fake accounts and disposable built frontend; browser blocks all requests outside that local origin. No Laravel/database/production access.

Before: built immutable `90887ec` in detached worktree `tmp/ui-baseline`. Assets in `tmp/ui-before-dist`. Screenshots in `tmp/monochrome-visual/before/`.

After: current branch frontend, screenshots/results in `tmp/monochrome-visual/after/`.

At each 360/390/768/1024/1440/1920 width the runner checks and captures guest entry, login, dashboard, house list and tenant list. Before entry naturally renders old login (there was no public landing), not a fabricated old landing.

## Results of current screen group

- All six widths passed document overflow and uncaught-page-error checks.
- Guest landing has one H1, valid register CTA, keyboard-visible skip link, mobile menu open/close, self-hosted Vietnamese font and reduced-motion context.
- Synthetic login works. Authenticated direct `/` returns dashboard. Direct page loads and refresh/navigation to house/tenant pages work.
- Screenshots were actually inspected: headline/preview/layout on desktop; typography/body sizes adjusted after screenshot review. A focused skip link in the first capture was corrected by blurring through normal click before final default-state screenshots.
- Sidebar/header, dashboard, house list and tenant foundation reflect monochrome tokens. Existing form/auth/query semantics were not replaced.
- Whole frontend unit suite: 207 tests pass. Lint passes with 3 existing warnings (RoomTenantForm/RoomForm incompatible-library, InvoiceListPage set-state-in-effect). Production build passes with the existing large-chunk warning.
- Existing browser regressions for floor/room creation, tenant picker (room/KTX contracts and CT01) and invoice creation pass with synthetic requests. An initial development-server run exposed font requests blocked by the dependency junction; Vite now scopes its filesystem allow-list to the actual dependency directory and stores optimizer cache in this worktree's ignored tmp folder. Subsequent reruns check that asset-serving issue is gone.
- Main JS reduced from roughly 1.02MB to 847KB (gzip ~222KB) by lazy document/editor/signing routes. This is build output, not a field-performance/Lighthouse claim.

## Limits

Fixtures and counts are synthetic. This does not certify backend operations, Google provider availability, all protected modules, print/PDF visual parity, or WCAG conformance. Tests cover keyboard skip-link/menu basics and reduced-motion setting, not a full screen-reader/axe audit. Screens for rooms/contract wizard/templates/invoices/services/utilities still need their dedicated next-group QA. No commit/push/deploy performed.
