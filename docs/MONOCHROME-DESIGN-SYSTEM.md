# BeeHouse Monochrome Premium

Implementation branch: `codex/beehouse-monochrome-premium`, isolated worktree based on `90887ec`.

## Direction

Vietnamese-first, editorial hierarchy, generous marketing whitespace, quieter operational screens. Real logo preserved. Landing illustrations are HTML/CSS with synthetic data, never screenshots of customer records. No invented pricing, testimonials, adoption counts, contacts or free-trial claims.

## Tokens and components

Source: `front-end/src/styles/design-system.css` and landing-scoped `pages/landing.css`.

| Token | Value / use |
| --- | --- |
| Ink | #111111, main type and primary actions |
| Paper / canvas | #FFFFFF / #F5F5F5 |
| Muted / border | #666666 / #E5E5E5 |
| Status | Existing semantic red/amber/green retained; labels always accompany color |
| Font | Existing self-hosted Be Vietnam Pro, Vietnamese and Latin subsets, 400/500/600 |
| Typography | Landing H1 fluid 30–73 px; H2 28–48 px; app headings 23–27 px; body 14–16 px; operational metadata 12–13 px |
| Spacing | 8 px base; 16/24/32 px app gutters; 48–110 px marketing section rhythm |
| Radius | 7 px inputs/actions, 10–14 px panels/dialogs |
| Shadows | Subtle operational borders; showcase and dialogs use intentional depth |
| Interaction | Visible focus; native links/buttons; 44+ px touch targets; restrained color/transform transitions |
| Motion | Reduced-motion disables nonessential animations and transitions |
| Breakpoints | 640 / 768 / 1024 / 1280 px; QA additionally at 360/390/1920 |

Shared components: PageHeader (title, eyebrow, description, actions), EmptyState (icon, explanation, next step), LoadingState (live status plus decorative skeletons). Existing form state, validation, dialogs, toast semantics and permission filtering are retained. Table numbers use tabular numerals. Screen-only application styling avoids changing document print rules.

Sidebar: dark #111, white active item, original logo, preserved mobile dialog/focus trap and permission-based template links. Header: quiet workspace label, current user menu unchanged. Dashboard: heading hierarchy, neutral metrics, consistent panel borders. House list: new shared heading, meaningful empty state and skeleton. Tenant/room/forms currently receive the shared styling foundation; bespoke redesign of their layouts remains a separate unfinished group.

## Research and skill provenance

- `openai/skills`: README and recursive tree reviewed. No `frontend-skill` found in the referenced current repository; README marks it deprecated in favor of OpenAI plugins. No substitute was silently installed.
- UI UX Pro Max: README and `.claude/skills/ui-ux-pro-max/SKILL.md` reviewed through GitHub. Applied priority guidance for contrast, native semantics, 44px touch targets, consistent typography, responsive reflow, and reduced motion. Python/CLI design-system search was NOT run; recommendations here are direct guidance plus the user's explicit palette, not claimed generated database matches. No third-party scripts were executed or globally installed.
- Vercel `react-best-practices`: SKILL.md reviewed; applied deferred loading of heavy editor/print/signing screens using React lazy/Suspense. Next.js-only SSR/RSC rules were excluded from this React/Vite SPA.
- Vercel `web-design-guidelines`: SKILL.md and live command.md reviewed; applied skip links, semantic sections, focus indication, explicit image sizing, heading hierarchy, responsive overflow checks and reduced-motion support.
- Built-in skill-installer was read for safe project installation options. Skills were applied as reviewed references without global installation or dependency changes. Some raw GitHub downloads were intermittently reset; GitHub contents API was used to read the key skill documents. The optional Python generators/scripts were not fully audited and therefore were not run.

Compatible with the existing Windows/React 19/Vite 8/Tailwind 4 setup. No new runtime library, backend logic or database schema changes. Dependencies are reused through a node_modules junction; Vite/browser tests run in this worktree only.

## Routing and SEO

Guest `/` renders landing; current session token at `/` routes to `/dashboard`, where the existing protected gate verifies it. Login/register/Google callback remain their existing routes. Direct protected URLs and expired-token behavior remain controlled by ProtectedRoute. Heavy document routes are lazy, with an accessible loading fallback.

Vietnamese static description/Open Graph title/description/type/locale and theme color are in index.html; landing sets its document title. No analytics or remote font service. This remains a client-rendered SPA: static metadata is available in the response, but social bots without JS do not receive rendered marketing content. Recommended follow-up: build-time prerender of the public `/` route only, preserving private routes and auth; not added silently to the stack. Do not claim full crawler/prerender validation.

## Completion update

The second screen group is complete: all four wizard stages, template library/editor, invoice filters/totals/mobile item rows, fixed/unit/person/tiered service pricing, meter readings/modal, login/register split layout, tenant/residence headings and account styling. Business handlers, permissions and document content remain unchanged. All six viewports have dedicated captures. See MONOCHROME-COMPLETION.md for final results and limitations. Live OAuth, exhaustive accessibility automation and further bundle splitting remain unverified follow-ups.
