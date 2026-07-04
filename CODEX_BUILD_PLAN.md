# LiteGig — Codex Build Plan

**Goal:** take LiteGig from a hardened demo to a **production-ready local-gig operations app** that real requesters and runners can use daily — *minimal in surface area, rich in capability, and mobile-first.* This document is the single source of truth for Codex. Work top to bottom by milestone; do not skip the guardrails.

> Owner: Tanzir · Status: Draft v1 · Design refresh: **complete** (new premium system + logo — see §7) · Backend/features: to build

---

## 1. How Codex should use this document

- **Build by milestone (M0 → M4).** Each milestone is releasable. Do not start Mn+1 until Mn's Definition of Done is met.
- **One feature = one PR.** Keep PRs under ~400 lines of diff where possible. Every PR title references the feature ID here (e.g. `[M1-03]`).
- **Never break what works.** The static landing/docs, the browser demo (`vercel-demo/index.html`), and the PHP app (`litegig.php`) must keep working at every commit.
- **Match the design system in §7 exactly.** No new colors, **zero border-radius**, near-black + one green accent, mono numerics. Reuse the tokens.
- **Mobile is the primary target, not an afterthought.** Every screen is designed and tested at 390px first, then scaled up. Touch targets ≥ 44px.
- **Acceptance criteria are contracts.** A feature is not done until its checklist passes plus tests (§9).
- **Security is not a phase.** Apply §8 controls as you build each feature, not at the end. LiteGig already ships strong security — do not regress it.
- **Ask before choosing infra.** §4 recommends Path A. If Tanzir hasn't confirmed a database/hosting change, open a short decision issue before writing it.

---

## 2. Current state (audit)

LiteGig today is four artifacts sharing one brand:

| Artifact | File(s) | What it is | Production gap |
|---|---|---|---|
| Landing page | `index.html` | Static marketing page (GitHub Pages) | Redesigned (premium); merge conflicts resolved; copy still demo-oriented |
| Docs | `docs.html` | Static docs (GitHub Pages) | Redesigned (premium); content thin for real operators |
| Browser demo | `vercel-demo/index.html` | Single-file SPA, all state in `localStorage`, seeded sample data | Redesigned (premium); not a real backend — no persistence, no auth boundary |
| PHP app | `litegig.php` (~2,790-line single file), `tools/security_scan.php` | PHP 8 + **SQLite** app for shared hosting | Design tokens applied, but a **single-file monolith**; UI depth, mobile polish, and features are thin for end users |

**What already exists (concept-complete):** email/password auth (first registered user becomes admin), **schema-driven task types** (admin-defined fields: text, textarea, number, price, date, select, address/geo, file), request creation, custody lifecycle (`created → accepted → picked_up → delivered → paid → completed/rated`), immutable **event log**, comments, **ratings**, **private attachments** (auth-gated download), **CSV export**, keyword search + status/type filters, geo distance (`haversine_km`), configurable fee percent, sample-data seeding, **CSRF**, output escaping, prepared SQL, session regeneration, login/critical **rate limiting**, **audit log**, CSP + security headers, `.env` config.

**What's missing for real end users:** a runner-facing way to **discover and browse open requests**; profiles + reputation from ratings; **notifications** (email/SMS) on status changes; real or receipted **payments** and reconciliation; scheduling/time windows; cancellation/dispute/exception handling; pagination + saved filters; a genuinely **mobile-first, installable** runner experience; empty/loading/error states everywhere; admin depth (user management, config UI); reporting/analytics; accessibility pass; and a maintainable, modular codebase with migrations and backups.

---

## 3. Product vision & scope guardrails

**Vision:** one lean, self-hosted app where a small community or business can **post local jobs, let runners accept and complete them, track custody end-to-end, settle payment, and rate each other** — usable primarily from a phone, deployable on cheap shared hosting.

**"Minimal but feature-rich" means:**
- Few screens, high density. Reuse the app shell; add **views**, not new apps.
- Every feature must earn its place against a real job (post, discover, accept, pick up, deliver, pay, rate, resolve, report).
- Prefer **configuration over new code paths** (task-type schemas, fee %, SLA, notification templates are data, not hardcoded).

**Explicitly out of scope (v1):** live GPS tracking, in-app real-time chat (use comments/events), route optimization, native app-store apps (use an installable **PWA**), automated dispatch/matching AI, marketplace-scale multi-tenant billing. Note these as "Later" so they aren't accidentally built.

**Primary users & top jobs:**
- **Requester:** post a job (single, schema-driven), find a runner, confirm pickup/delivery, confirm/settle payment, rate the runner, see their history.
- **Runner:** browse/accept nearby open jobs, run a mobile job sheet, capture pickup/delivery + proof, collect/confirm payment, rate the requester.
- **Admin/ops:** manage users, task types, fees, config; oversee exceptions/disputes; review audit; export reports.

---

## 4. Target architecture

### Path A — Recommended: strengthen the PHP app (keep the self-host identity)

LiteGig's whole value is "runs on cheap PHP + SQLite hosting." Keep that. Make it maintainable and feature-complete.

```
Browser (requester / runner PWA / admin)        Mobile-first, installable PWA
        │  HTTPS · session cookie · CSRF
        ▼
litegig.php (router)  ──►  split into modules:
  ├─ routing / controllers (action_* handlers)
  ├─ models (users, requests, events, ratings, attachments, payments)
  ├─ views / components (shared premium UI partials)
  └─ services (auth, rate-limit, notifications, export, geo)
        ├──► Data: SQLite → migratable to MySQL/MariaDB when write concurrency grows
        ├──► Private storage: attachments + proof photos (outside web root)
        ├──► Notifications: email (SMTP) + optional SMS gateway + delivery OTP
        └──► Cron: cleanup, notification retries, aging/exception sweeps
```

- **DB:** keep **SQLite** for small deployments; add a thin data layer + **versioned migrations** so a switch to **MySQL/MariaDB** is a config change, not a rewrite. Enforce access **server-side** (never trust the client) — RBAC + per-row ownership checks (requester sees own; runner sees assigned + open pool; admin sees all; public sees redacted tracking only).
- **Code:** split the 2,790-line `litegig.php` into modules behind the same entrypoint (see §6 M0). This is the prerequisite for everything after.
- **Frontend:** server-rendered HTML with the §7 design system, progressively enhanced. Ship a **PWA manifest + service worker** so the runner view is installable and tolerant of flaky mobile networks.
- **Deploy:** shared host (cPanel) for the app; static landing/docs on GitHub Pages; browser demo on Vercel. Keep those responsibilities separate.

### Path B — Alternative: rebuild on a managed stack

If self-hosting is dropped: rebuild on Postgres + a small framework with Row-Level Security and serverless functions. Heavier ops, loses the shared-host selling point. **Default to Path A unless Tanzir says otherwise.** The data model (§5) and backlog (§6) are backend-agnostic.

---

## 5. Data model (core entities)

Model these regardless of backend. Names are guidance; today's schema is close but thin.

- **user** — `id, role (admin|requester|runner|both), name, email, phone, password_hash, avatar?, rating_avg, rating_count, status, created_at`.
- **task_type** — admin-defined schema: `id, name, description, fields[] (key,label,type,required,options,placeholder), fee_rule, active`.
- **request** — `id, code, task_type_id, requester_id, runner_id?, title, metadata(json per schema), pickup_addr, dropoff_addr, geo, price_cents, fee_cents, status, scheduled_window?, sla_due_at?, created_at`.
- **request_event** — immutable log: `id, request_id, type (created|accepted|picked_up|delivered|paid|completed|rated|comment|cancelled|disputed|reopened|note), actor_id, note, photo_url?, geo?, created_at`. **Status is derived from / validated against events + a transition ruleset — never free-set.**
- **rating** — `id, request_id, rater_id, ratee_id, stars, comment, created_at` (drives `user.rating_avg`).
- **attachment** — `id, request_id, owner_id, filename, mime, size, private_path, created_at` (auth-gated download only).
- **payment** — `id, request_id, method (manual|gateway), amount_cents, fee_cents, status (pending|confirmed|refunded), confirmed_by, confirmed_at, receipt_no` (LiteGig records manual confirmations; gateway is optional/flagged).
- **notification** — outbound log: `id, user_id, channel (email|sms), template, payload, status, retries, sent_at`.
- **audit_log** — every privileged mutation: `actor, action, entity, before, after, at`.

---

## 6. Feature backlog by milestone

Legend: **[Must]** ship for a usable product · **[Should]** strong value · **[Later]** post-v1. IDs are stable references for PRs.

### M0 — Production hardening + design-system rollout *(no new features)*
Make what exists feel finished, consistent, and mobile-clean.
- **[M0-01][Must]** **Roll the §7 design system fully into `litegig.php`.** Tokens/logo/favicon/squared corners are applied, but audit every rendered view — forms, tables (`.table`), lists, nav, flashes, state path, badges, pills — for spacing rhythm, hairline borders, subtle depth, mono numerics, and 44px touch targets. Nothing rounded, nothing 800-weight.
- **[M0-02][Must]** **Empty / loading / error states everywhere** the app currently assumes data (no requests, no runners nearby, failed action, offline). No dead ends.
- **[M0-03][Must]** **Split `litegig.php` into modules** (routing, controllers, models, views/partials, services) behind the same entrypoint. Enables everything after. Add shared view partials so the three surfaces stay visually in sync.
- **[M0-04][Must]** **Mobile-first pass** on the PHP app: single-column at ≤ 640px, sticky action bar for the primary next-step, thumb-reachable controls, no horizontal scroll, inputs that don't zoom iOS (≥16px).
- **[M0-05][Should]** **Extract design tokens** to one shared source (`styles/tokens.css`) imported by landing, docs, and demo; keep the PHP `:root` in lockstep.
- **[M0-06][Should]** **Copy pass** on landing/docs: position for a real operator, not just a demo.
- **DoD:** all three surfaces + PHP app render cleanly at 390px and desktop; Lighthouse ≥ 90 (perf/a11y/best-practices) on landing + one app screen; no rounded corners, no 800-weight, one accent.

### M1 — Core account & request experience *(the foundation of real usage)*
- **[M1-01][Must]** **Runner discovery:** an "Open requests" pool where runners browse/accept unassigned jobs, filtered by task type, distance, and fee. (Today a runner can accept but has no discovery surface.)
- **[M1-02][Must]** **Profiles + reputation:** user profile with name, avatar, and rating summary shown on requests and in the pool. Ratings already exist — surface them.
- **[M1-03][Must]** **Request lifecycle UI depth:** clear next-action affordance per role at every state; comment thread on each request rendered from the event log; attachment upload/preview inline.
- **[M1-04][Must]** **Server-side RBAC + ownership matrix:** requester sees own; runner sees assigned + open pool; admin sees all; public tracking is redacted. Add an authorization allow/deny **test matrix** (§9).
- **[M1-05][Must]** **Notifications baseline (email):** on accept, pickup, delivery, payment, and new comment. Templated, logged, retried. Respect per-user preferences.
- **[M1-06][Should]** **Public tracking page** by request code: timeline + status + redacted PII, branded, mobile-clean, shareable.
- **DoD:** a requester posts a job → a different runner discovers and accepts it → both parties get notified and can comment → custody moves to delivered, entirely against the real backend, primarily from a phone.

### M2 — Operational depth
- **[M2-01][Must]** **Search, filter, pagination, saved views** across the request list and open pool; keep queries indexed (no N+1, no full scans).
- **[M2-02][Must]** **Scheduling & time windows:** optional pickup/delivery window on a request; surface "due soon"/overdue on lists.
- **[M2-03][Must]** **Exceptions:** cancel, decline, re-open, and **dispute** flows with reason taxonomy; every transition writes an event and (where relevant) notifies.
- **[M2-04][Must]** **Runner job sheet (mobile):** a focused mobile view of the runner's active jobs with one-tap pickup/deliver, proof capture (photo), and confirm-payment.
- **[M2-05][Should]** **Distance/geo matching:** use `haversine_km` to sort the open pool by proximity and show distance on cards.
- **[M2-06][Should]** **Request editing** (pre-acceptance) with an audit trail.
- **DoD:** a runner can work a full day from the mobile job sheet — discover, accept, pick up with proof, deliver, confirm payment, handle a cancellation — without touching a desktop.

### M3 — Payments, notifications & installable PWA
- **[M3-01][Must]** **Payment receipts + reconciliation:** manual confirmation produces a **receipt** (number, amount, fee, parties, timestamp); an admin/requester view of outstanding vs. settled. Fee % already configurable — show the split.
- **[M3-02][Should]** **Optional payment gateway** (e.g. Stripe) behind a feature flag; never store card data; idempotent webhooks; keep manual mode as default for self-host.
- **[M3-03][Must]** **SMS notifications + delivery OTP:** SMS on key transitions via a pluggable gateway; a delivery OTP the requester shares to confirm handoff. Templated, logged, retried.
- **[M3-04][Must]** **Installable PWA:** manifest + service worker; the runner job sheet works offline-tolerant (queues actions, syncs on reconnect); "Add to Home Screen."
- **[M3-05][Should]** **Notification preferences** per user and per event type.
- **DoD:** a runner installs LiteGig to their home screen, completes a job offline in a dead zone, and it syncs; the requester gets an SMS and a receipt.

### M4 — Admin, analytics & production polish
- **[M4-01][Must]** **Admin console:** users/roles, task-type management (exists — polish), fee/config, notification templates, audit review.
- **[M4-02][Must]** **Reporting:** volume by status/type/runner, completion & first-attempt rates, average rating, earnings & fees, outstanding payments; date filters; CSV/Excel export.
- **[M4-03][Must]** **Accessibility (WCAG 2.1 AA):** contrast, visible focus, keyboard paths, labeled inputs, status not by color alone, 44px targets.
- **[M4-04][Should]** **i18n + timezone:** externalized strings, correct local time/currency formatting.
- **[M4-05][Should]** **Migrations + MySQL option + backups:** versioned reversible migrations; documented SQLite→MySQL switch; daily backups.
- **[M4-06][Must]** **Observability:** structured logs, error tracking, health endpoint, uptime monitor wired before real users.
- **[Later]** live GPS tracking, real-time chat, dispatch matching, e-commerce order import, native apps.
- **DoD:** an admin can answer "how are we doing this week?" and "who's owed what?" from the product; on-call can see errors and health.

---

## 7. Design system (use exactly — already applied to landing, docs, demo, and the PHP app shell)

The UI was refreshed to a **minimal, premium** aesthetic: near-monochrome, one green accent, generous whitespace, squared corners, and soft depth instead of heavy borders. Build all new UI with these tokens.

```css
/* Neutrals */
--bg:#f7f8f9;        --panel:#ffffff;     --panel-2:#f6f7f9;   --soft:#f2f4f5;
--fg:#14171c;        /* body text + primary buttons/active */
--ink:#0b0d12;       /* headings, near-black */
--muted:#6b7280;     --faint:#9aa1ac;
--line:#ececef;      --line-2:#e0e2e7;    /* hairline borders */
/* One accent — green */
--accent:#0f7a52;    --accent-ink:#0c6042;   --accent-soft:#eaf4ef;
/* Status/data only, muted */
--ok:#0f7a52;  --warn:#9a5b00;  --danger:#c0362c;
/* Depth (premium comes from soft shadow + space, not hard boxes) */
--shadow-sm:0 1px 2px rgba(11,13,18,.05), 0 1px 3px rgba(11,13,18,.05);
--shadow:0 14px 36px rgba(11,13,18,.10), 0 3px 8px rgba(11,13,18,.05);
--shadow-lg:0 30px 80px rgba(11,13,18,.26);
--mono:ui-monospace,SFMono-Regular,"JetBrains Mono",Menlo,Consolas,monospace;
```

**Rules:**
1. **Zero border-radius.** Everything squared. No pills — status chips are squared, uppercase, letter-spaced. (This was the #1 fix — do not reintroduce rounded corners.)
2. **One accent.** Green `--accent` is the brand pop (logo node, eyebrows, active/done states, links where an affordance is needed). Primary buttons are **near-black** (`--fg`), not green. `--ok/--warn/--danger` are for status only.
3. **Mono numerics.** Prices, counts, IDs, KPI values, timestamps → `--mono`. Prose stays Inter.
4. **Depth over lines.** Group with hairline `--line` borders + `--shadow-sm`; reserve `--shadow`/`--shadow-lg` for floating layers (modals, dropdowns). Avoid heavy dark boxes.
5. **Restrained weight.** Headings 650–700 with tight negative tracking; labels 600 uppercase `--muted`. No 800/900 weights.
6. **Type:** Inter for UI/prose; tight tracking on headings (`-.02em`).
7. **Logo/favicon:** the geometric **"L" bracket with a green gig-node** (ink `#0b0d12` bracket, `#0f7a52` node). Sources in `brand/` (`logo-mark.svg`, `logo-mono.svg`, `logo-lockup.svg`, `favicon.svg`, PNG exports, `apple-touch-icon.png`, `icon-512.png`). Inline mark:
   ```html
   <svg viewBox="0 0 32 32" width="28" height="28"><rect x="6" y="6" width="4.2" height="20" fill="#0b0d12"/><rect x="6" y="21.8" width="20" height="4.2" fill="#0b0d12"/><rect x="17.8" y="6" width="8.2" height="8.2" fill="#0f7a52"/></svg>
   ```
   Do **not** reintroduce the old "LG" text glyph.
8. **Sticky, blurred top bar; generous section spacing; 44px+ touch targets; visible `2px`/`3px` focus rings** on every interactive element.

Add new components to the shared token/partial layer once the app is modularized (M0-03/M0-05) so all surfaces stay in sync.

---

## 8. Non-functional requirements (apply continuously)

- **Security (already strong — keep it):** server-side authz + per-row ownership; parameterized queries; CSRF on state-changing POSTs; secrets in `.env`/secret store, never client; password hashing; login + critical-action rate limiting; **private attachment/proof storage outside web root** with auth-gated download; PII redaction on public tracking; idempotency on event/scan/webhook; full audit trail; CSP + security headers; dependency + secret scanning in CI. Do **not** put PII in URLs. Rotate seeded demo credentials before production.
- **Access-control tests:** every role × every sensitive action has an allow/deny test.
- **Accessibility:** WCAG 2.1 AA — contrast, visible focus, keyboard paths, status not by color alone, labeled inputs, 44px targets.
- **Performance:** app interactive < 2.5s on mid mobile; lists paginated + indexed; images/proof lazy-loaded; avoid N+1.
- **Reliability:** offline-tolerant runner actions with sync + conflict handling; cron jobs idempotent and retryable.
- **Data:** migrations versioned and reversible; daily backups; soft-delete + audit for corrections (no hard deletes of operational records).
- **i18n/locale:** strings externalized; correct currency and local time.

---

## 9. Testing strategy

- **Unit:** status-transition rules, fee/price math, geo distance, schema field validation, rating aggregation.
- **Integration:** each action incl. the **authz allow/deny matrix**, idempotent event capture, attachment access control, CSV export correctness.
- **E2E (happy paths):** post → discover → accept → pickup(+proof) → deliver → pay(+receipt) → rate; public tracking redaction; cancellation/dispute.
- **Regression guard:** landing, docs, and demo must render (smoke test) on every PR.
- **Non-functional:** axe a11y checks in CI on key screens; basic load test on list + accept endpoints; **mobile viewport (390px) snapshot** on key screens.
- **Definition:** no feature merges without tests covering its acceptance criteria; CI green required.

---

## 10. Deployment & release

- Static landing/docs → GitHub Pages; browser demo → Vercel; PHP app → PHP 8+ shared host with PDO SQLite (or MySQL).
- Migrations run with a gate; rollback plan per release; daily backups on before real users.
- Rotate/disable all seeded demo credentials; keep `.env`, databases, logs, and uploads outside public paths.
- Health endpoint + uptime monitor; error tracking wired before first real users.
- Release checklist: migrations applied, authz matrix green, backups on, secrets set, demo creds disabled, a11y/perf budgets met, monitoring live, PWA installable.

---

## 11. Codex execution guardrails (Definition of Done)

A PR is done when **all** hold:
- [ ] Implements exactly one backlog item; PR title references its ID.
- [ ] Meets every acceptance checkbox for that item.
- [ ] Applies §7 design tokens (no new colors, **zero radius**, near-black primary + green accent, mono numerics, no 800-weight) and §8 security controls.
- [ ] Works and looks right at **390px first**, then desktop; 44px touch targets; visible focus.
- [ ] Tests added/updated per §9; CI green; demo smoke test passes.
- [ ] No secrets, PII, or access decisions in client code; server-side authz enforced.
- [ ] Docs/changelog updated; user-facing strings externalized for i18n.
- [ ] Landing, docs, demo, and the PHP app still build and render.

**First three PRs to open:** `[M0-01]` finish the design-system rollout in `litegig.php`, `[M0-03]` modularize the monolith, then `[M0-02]` empty/loading/error states — and open the **Path A vs B decision issue** before any database change in M4-05.

---

*Appendix — the design refresh (premium tokens, geometric "L+node" logo, favicon set, squared corners, mobile-first landing/docs/demo, and merge-conflict cleanup on `index.html`) is already committed to the working tree. This plan supersedes older notes where they conflict on scope for the production build.*
