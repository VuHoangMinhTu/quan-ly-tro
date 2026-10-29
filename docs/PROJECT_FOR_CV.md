# BeeHouse — Project Summary for a CV

## Project

**BeeHouse — Multi-Tenant Boarding House Management System**

BeeHouse is a React and Laravel application for managing boarding houses and dormitory accommodation. Multiple landlords share one relational database while retaining ownership-scoped access to their properties, tenants, contracts, invoices, and payment configuration. The application connects operational workflows—occupancy, contract handover/activation, meter readings, and billing—with versioned document rendering, bank QR details, and PayOS payment reconciliation. The UI is in Vietnamese; the backend exposes a JSON API authenticated with Laravel Sanctum.

## Verified stack

React 19 · Vite 8 · React Router 7 · Tailwind CSS 4 · TanStack Query 5 · Axios · React Hook Form/Zod · PHP 8.3+ · Laravel 13/Eloquent · Sanctum 4 · MySQL · Socialite · PayOS PHP SDK · PhpSpreadsheet · PHPUnit 12 · Node.js test runner · Playwright browser regression scripts.

Document previews/PDF export use HTML/CSS and browser printing. The operator-reported hosting model is Ubuntu VPS/Nginx/PHP-FPM/MySQL; provisioning is not supplied in this repository. See [Architecture](ARCHITECTURE.md) for the distinctions and source references.

## Evidence-backed CV bullet bank

The following bullets describe **system capabilities**, not independently verified personal contributions. Select only the work you actually performed and can explain. Rewrite into first-person achievement statements only where your role is supported by your own work history; repository features and commit authorship alone do not establish sole authorship.

- Multi-landlord shared-database design with ownership-scoped Eloquent queries and property-specific OWNER/MANAGER delegation.
- Contract activation workflow with occupancy/bed conflict checks, transactional updates, confirmed handover records, and immutable HTML/source snapshots.
- Contract-aware invoice generation combining agreed rent, utility consumption, tiered/service pricing, manual adjustments, and protected financial edit rules.
- Landlord-specific PayOS integration with signature-verified callbacks, duplicate-reference handling, locked payment updates, and reconciliation of uncertain remote requests.
- Tenant onboarding through validated Excel preview/confirmation and a shared searchable, filterable, server-paginated tenant selection dialog.
- Layered regression coverage using Laravel/PHPUnit, Node-based form/session tests, and isolated Playwright browser scenarios for cross-session cache behavior and workflow stability.

**Optional test evidence:** the local backend run at `fde531f` passed 632 tests and 4,382 assertions using SQLite in memory. Attribute this as a verification result, not production load capacity or an individual achievement unless you own that testing work. Do not attach production-user counts, revenue, uptime, or benchmark claims without independent evidence.

## Backend points worth preparing

| Topic | What to explain | Source entry point |
| --- | --- | --- |
| Tenant isolation | Shared tables, indirect ownership paths, and why frontend filters do not authorize a request | [BoardingHouseAccessService](../backend-api/app/Services/BoardingHouseAccessService.php) |
| Transactions and locking | Activation/generation decisions share locks; external network calls need reconciliation beyond transactions | [ContractService](../backend-api/app/Services/ContractService.php), [PayOSService](../backend-api/app/Services/PayOSService.php) |
| Eligibility vs arithmetic | A valid current contract is required before a new periodic invoice can be generated | [InvoiceContractEligibility](../backend-api/app/Services/InvoiceContractEligibility.php), [BillingService](../backend-api/app/Services/BillingService.php) |
| Historical integrity | Current occupancy, original signing data, effective service terms, and issued payment snapshots are different data sources | [ContractDocumentService](../backend-api/app/Services/ContractDocumentService.php), [InvoicePaymentInfoService](../backend-api/app/Services/InvoicePaymentInfoService.php) |
| Unique codes | Per-landlord/type/year sequences with application checks and database uniqueness | [DocumentCodeService](../backend-api/app/Services/DocumentCodeService.php) |
| Testing boundaries | Why SQLite tests and synthetic browser APIs cannot prove MySQL lock behavior or actual provider delivery | [Testing strategy](ARCHITECTURE.md#16-testing-strategy) |

## Interview questions to rehearse

1. **Why use a shared database rather than database-per-tenant?** Describe the implemented ownership keys/scopes, operational simplicity, and the risk of forgetting a scope. Do not invent a formal architecture decision record.
2. **How do OWNER and MANAGER differ?** Explain house assignments, owner-only endpoints, and why an account can own one workspace while managing another house.
3. **How does the SPA authenticate, and what happens when users switch accounts?** Distinguish opaque Sanctum bearer tokens from JWT/cookie sessions; explain request cancellation, new QueryClients, late responses, and local-storage trade-offs.
4. **Why are Contract and RoomTenant separate?** Distinguish the agreement holder, roommates, current occupancy, and multiple historical stays.
5. **What prevents two contracts from occupying the same room or bed?** Walk through activation locks, overlap/capacity checks, and the limits of SQLite verification.
6. **Why snapshot contract documents and issued payment details?** Explain which current-profile edits should affect a draft and which must not rewrite finalized history.
7. **How is a monthly invoice calculated?** Explain eligibility, generated/manual items, meter boundaries, effective service prices, and why period-overlapping PER_PERSON stays differ from today's headcount.
8. **What if a PayOS request times out or a webhook is repeated?** Explain uncertain outcomes, request reuse/reconciliation, signature/merchant/amount checks, duplicate references, and why a browser return URL cannot record funds.
9. **How does Excel import avoid partially applying unreviewed data?** Describe bounded parsing, preview classification, expiring owner-bound sessions, selected-row confirmation, and database transactions.
10. **What would you improve next, and what evidence supports it?** Discuss unpaginated tenant lists, bundle splitting, compact-screen UX, MySQL-backed concurrency tests, and CI as follow-ups—not completed optimizations.

## Claims to avoid

- Sole implementation of the whole system without evidence of your personal responsibilities.
- Microservices, database-per-tenant, JWT, a dedicated Laravel Policy layer, or infrastructure not present in source.
- A certified digital-signature service, government CT01 submission, or server-side PDF generation.
- Live customer scale, production performance, deployed CI, or a verified uptime claim based on local fixtures.

Use the [README gallery](../README.md#-application-preview) to demonstrate the UI, then use [ARCHITECTURE.md](ARCHITECTURE.md) to support the technical explanation.
