# Session isolation browser regression

Runs the actual built React application in an ephemeral Chromium context, with an in-memory synthetic HTTP API bound only to `127.0.0.1`. No real account, database, PayOS, email, production URL, existing browser profile or environment file is used by the test server. All application requests outside the test origin fail the test.

Prerequisites: Node.js compatible with the project, installed frontend dependencies, and an available local `playwright` package plus Chromium executable. The runner first resolves Playwright from frontend dependencies; otherwise provide `PLAYWRIGHT_PACKAGE_PATH`. There is no automatic installation/download and no change to package.json/lockfiles.

From the project root in PowerShell:

```powershell
# Build to a dedicated temporary directory with a same-origin synthetic API.
Push-Location front-end
try {
  $previousApiBase = $env:VITE_API_BASE_URL
  $env:VITE_API_BASE_URL = '/api'
  node node_modules/vite/bin/vite.js build --outDir ../tmp/e2e-session-dist
} finally {
  $env:VITE_API_BASE_URL = $previousApiBase
  Pop-Location
}

# If Playwright/browser are bundled externally, provide their local paths.
# Do not point either path at a user browser profile or a remote executable.
$env:PLAYWRIGHT_PACKAGE_PATH = 'C:/path/to/node_modules/playwright'
$env:E2E_CHROMIUM_PATH = 'C:/path/to/chromium/headless_shell.exe'
node --test --test-reporter=spec tests/e2e/session-isolation.browser.mjs
```

If Playwright is installed in the frontend and its matching browser is installed normally, omit both path environment variables. `E2E_DIST` optionally selects an existing dedicated build under the project's `tmp/` directory; paths outside that directory are rejected. Never replace the loopback server with production or a tunnel to production.

Test coverage: A→logout→B in the same tab; rooms/invoices/tenants; no cached A while B is loading; route changes and old A URLs; authenticated refresh; logout refresh; a pending A request aborted normally; delayed 200/401 physically delivered after B login using test-only XHR abort fault injection. DOM leak markers and browser preferences are checked throughout. Each page action/barrier has an 8s timeout, each scenario has a 60s limit, and the runner closes its browser/server.

See `docs/session-isolation-e2e.md` for the actual measured result and the distinction between the frontend API double and separate Laravel ownership feature tests. No browser results should be presented as full-stack MySQL verification.

## Dashboard measurements and regression

The session browser suite now also checks dashboard fresh remounts, a 30-second poll with Playwright's clock and an A/B dashboard switch. Use an optimized build for that regression.

`dashboard-measure.browser.mjs` prints three runs of request counts and browser timings with a fixed **synthetic API delay of 75ms**. It uses the same loopback/network guard, fresh contexts and viewport before/after. Visibility notifications are synthetic browser events. This does not measure Laravel or MySQL latency.

```powershell
# Keep the Playwright/browser path variables from above.
# Before/after artifacts must already exist in project tmp/; never switch to a remote API.
$env:E2E_DIST = "$PWD/tmp/dashboard-before-dist"
$env:DASHBOARD_METRICS = 'before'
node tests/e2e/dashboard-measure.browser.mjs
$env:E2E_DIST = "$PWD/tmp/dashboard-after-dist"
$env:DASHBOARD_METRICS = 'after'
node tests/e2e/dashboard-measure.browser.mjs
node --test --test-reporter=spec tests/e2e/session-isolation.browser.mjs
```

The captured baseline build predates this dashboard optimization but includes the earlier session-isolation fix. Do not rebuild the before directory from optimized source and present it as a baseline. Retain baseline artifacts or reproduce the original dashboard files in a separate checkout.

Backend query/latency measurements live in `backend-api/tests/Feature/DashboardPerformanceTest.php`. Run only with the project's testing configuration, DB_CONNECTION sqlite, DB_DATABASE :memory:, DB_URL empty and no cached production configuration. `DASHBOARD_METRICS=after` prints numeric metrics; additionally `DASHBOARD_COMPARE_BASELINE=1` alternates the frozen before implementation and current implementation in the same test process. Fixture setup and one warm-up per implementation are excluded. The baseline fixture is not production code and must never be container-bound outside this test. See `docs/dashboard-optimization.md` and raw JSON files for results, including timings that did not improve.
