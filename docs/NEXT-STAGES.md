2026/08/06 14:54

# Planned stages after 0.3.0

## 0.4.0 — Products, access periods and individual commerce

- Course products and course-plus-period variants.
- Cart, orders, payments and entitlements.
- Administrator-only simulated payment gateway.
- PayFast integration and verified callbacks.

## 0.5.0 — Company staff, requests and course-credit pools

- Direct staff account administration without invitations.
- Optional plain staff-added notification without a login token.
- Staff course requests and company administrator approval or rejection.
- Pools of specific course-and-period credits.
- Assignment and reassignment before commencement.
- Permanent credit consumption when the learner clicks **Start course**.
- Payment only when no matching unused credit exists.

## 0.6.0 — Provider marketplace and reporting

- Public course-provider profiles.
- Provider-owned courses and provider course creators.
- Contract, commission, sales-attribution and payout records.
- Student, company and provider reports.
- CSV/PDF report exports.

## 0.7.0 — Additional gateways and integration expansion

- Peach Payments, Yoco, Ozow, iKhokha and Paystack plugins.
- Expanded REST API and MCP tools.
- Non-iframe embedded course portals.
- Assessment of remote MCP transport and OAuth requirements.

---

2026/08/08 02:55

# Planned stages after 0.4.0

- Commerce orders and payment gateway workflows.
- Expanded company purchasing and course-request cart processing.
- Provider contracts, commissions, statements and payouts.
- Indexed system-error viewer and deeper learning analytics.
- Primary-email change workflow with verification and company-domain enforcement.
- Remote MCP transport and broader REST API coverage.

---

2026/08/08 18:29

# Revised roadmap after 0.4.0 usability and commerce review

`docs/NEXT-STAGES.md` is the authoritative development roadmap and must be included in every future Catto Learning source build. It must be updated whenever material objectives, sequencing or platform requirements change.

## Immediate — 0.4.1 cumulative stabilisation

Complete these defects before expanding the platform:

- Make all modal/popover edit forms dismissible without saving: **Esc**, visible **Close (×)** and **Cancel** controls, with focus returned to the element that opened the form.
- Fix legacy HTML course import so supported self-contained courses may use the restricted JavaScript object-literal form already used by existing `QUIZ` banks, including unquoted object keys, without requiring the source course to be rewritten as strict JSON.
- Add regression coverage using **Spreadsheets for Small Business** and Linux course sources.
- Add first-class course-level **diagnostic/readiness assessments** so legacy `diag`, `prereq`, `prerequisite` and `readiness` question banks are imported into PostgreSQL rather than silently omitted. Diagnostics must remain outside graded module/final results, course progress and certificate eligibility. Preserve diagnostic pass thresholds, result guidance and source remediation mappings to suggested modules.
- Give diagnostic attempts their own assessment mode and learner presentation before Module 1, with unlimited/non-graded behaviour where imported source material specifies that intent.
- Replace the meaningless aggregate “recoverable HTML parsing warnings” message with parser diagnostics that distinguish legacy-parser HTML5 compatibility notices from genuine structural parsing warnings, exclude JavaScript/CSS raw-text bodies from DOM structural validation, and show useful examples for genuine document-markup warnings.
- Preserve diagnostics and their remediation metadata through structured course export/import and revision workflows. Where the source explicitly defines a diagnostic **pass-out** (for example Spreadsheets for Small Business: 80% permits the learner to skip the course with a recorded pass), preserve that as a course-completion action; readiness-only diagnostics such as Linux Server `prereq` remain guidance-only.
- Enforce dual course ownership: every course has both an owning company and an owning person; new imports default to System Company plus the importing administrator.
- Preserve source-course presentation CSS without allowing it to override LMS shell/card layout; course-specific classes belong inside an isolated course-presentation wrapper.
- Normalise imported Learning Outcomes so the source heading is not stored twice, and keep one canonical Course Administration screen at `/admin/courses`.
- Keep the development route surface deliberately small: account pages live under `/account/*`; remove obsolete aliases/templates/actions instead of carrying compatibility shims before production launch.
- Verify default-theme front-page hero publishing and rendering on the live filesystem theme.
- Expand the certificate editor so ordinary certificate data is editable without raw HTML/CSS and every supported placeholder is documented with its data source, ownership and snapshot behaviour.
- Re-run execution-based import, certificate, theme, company-edit and learner workflow smoke tests before declaring the 0.4.1 line stable.

## 0.5.0 — Sellable and operable platform

### Course/assessment authoring follow-through

- **Completed 2026-08-10:** administrator authoring controls for course-level diagnostics: create/delete/reorder, visibility, pass/fail guidance, attempt limits, optional completion rules and remediation-module mappings without editing imported source files.
- Diagnostics remain non-graded by default; completion-on-pass occurs only when explicitly selected by an administrator or preserved from explicit imported source intent.

### Commerce foundation

- **Completed 2026-08-11:** make courses commercially sellable through administrator-created **per-course access-period price variants** rather than an implicit free-enrolment model. Access periods are not hard-coded globally: each course may define the variants it needs (for example 3, 6, 9 and 12 months, or only 6 and 12 months).
- **Completed 2026-08-11:** store each variant's access period, price in integer minor currency units, currency code, active status and display/order metadata.
- **Completed 2026-08-11:** require exactly one active variant to be marked **default**. Its price is the published catalogue price. When additional active variants exist, the catalogue must clearly indicate that other access-period/price options are available.
- **Completed 2026-08-11:** if a course has only one active price variant, present that value simply as the course price without implying a range or alternative options.
- **Completed 2026-08-11:** administrators can add, edit, reorder, activate/deactivate and remove variants independently for each course; pricing is never hard-coded in application code.
- **Completed 2026-08-11:** the course detail page shows every active access-period variant and price and carries the selected access period into the current request flow; cart integration follows in the commerce workflow build.
- Add cart, orders, order lines, payment records, payment status and resulting learner entitlements/enrolments.
- Add an administrator-only simulated payment gateway first so the complete purchase-to-learning workflow can be tested without real money.
- Record order, payment and enrolment events in the audit/activity stream.
- Keep the payment-gateway boundary plugin-based; add PayFast only after the simulated end-to-end workflow passes acceptance tests.

### Activity Centre and operational visibility

- **Completed 2026-08-10:** consolidate the former learner Dashboard and Administration Overview into one Administration Dashboard; rename Learning to Enrolments & Requests; add Radiant Learning Account/Administration dropdown navigation; split Activity from Reports.
- **Completed 2026-08-10:** add a full-width **Activity Centre** under Administration → Activity.
- **Completed 2026-08-10:** show audited business events in a searchable/filterable table with timestamp, actor, organisation, event, subject/entity, result and source/interface.
- **Completed 2026-08-11:** allow an administrator to open an event and inspect its structured metadata and related records.
- **Completed 2026-08-11:** update the activity table while the page is open by polling for events newer than the last displayed event; no WebSocket dependency.
- Activity uses the central audit stream, already covering company/person/course/request/credit/start/assessment activity; commerce order/payment events will join the same stream when those workflows are built.
- **Completed 2026-08-11:** filters for date range, actor, company, event family, course and free-text metadata search are implemented. Result/entity-ID filtering can be added when operational use demonstrates a need.

### Development/demo data

- Add an administrator-only deterministic demo-data generator and matching **Clear demo data** operation.
- Generated records must be clearly tagged as demo/test data and removable without touching genuine records.
- Default demonstration set: approximately 10 learners, 3 external client companies, one company administrator per company, company staff, course assignments/requests/credits, learner progress, assessment attempts/results and certificates.
- Once commerce tables exist, also generate realistic carts/orders/payments, including successful, pending and failed transactions, so financial and activity views can be exercised.
- Seed several test courses and access variants at different prices and access periods.

### Theme Package 1.0 / Theme Design System — **Rebuilt and regression-hardened 2026-08-12 in 0.5.4**

- Portable ZIP theme packages use an authoritative `theme.json` manifest and may contain HTML/F3 templates, CSS, JavaScript, JSON/text support files, declared HTTPS CDN links and binary image/font assets. Imported PHP/server-executable theme code is prohibited.
- Theme source text is stored in PostgreSQL; binary assets are stored on the filesystem with PostgreSQL metadata and hashes.
- Added staged theme import/inspection, Theme Package 1.0 validation, portable export and a Catto Learning Theme SDK with JSON Schema, starter theme and scaffold/ZIP generator.
- Theme Studio provides a Preview/Code workspace, unsaved source preview, typed-hex palette management with live swatches, semantic colour roles, contrast warnings, Inter/Plus Jakarta Sans/Satoshi typography controls, dedicated navigation/breadcrumb/layout controls, reusable front-page hero controls, binary asset management, advanced source editing and Advanced Custom CSS.
- CSS precedence is package CSS → platform functional overrides → visual-editor generated CSS → Advanced Custom CSS.
- Save draft and Activate are materially separate: draft previews cannot change the live site until the administrator applies/activates the draft.
- Radiant Learning, Default Learning and Aurum Learning are seeded using the same package model as imported themes; `themes/default` remains the emergency recovery copy. The universal hideable palette chooser is core behaviour and is regression-tested across themes.

**Still planned:** marketing landing pages may reuse the same design primitives and hero component for campaign-specific copy, CTA configuration, attribution links and independently selected hero presentation.

## Certificate data model and editor objectives

- Provide a Basic Certificate Settings form before the advanced HTML/CSS template editor.
- Clearly document each placeholder in the UI: meaning, source table/field or generated value, who controls it, and whether it is snapshotted when the certificate is issued.
- Expose course-controlled values such as certificate title/body/footer and signatory name/title directly in the course certificate editor.
- Add a documented `{{certificate_footer}}` placeholder (the database already has certificate footer text, but the current placeholder set does not expose it).
- Derive learner-controlled `student_name` from the learner profile certificate name with documented fallback behaviour.
- Derive provider identity from the owning Course Provider company, falling back to the System Company for platform-owned courses, and allow an optional per-course provider-name override.
- Use the actual course result/completion records for score, grade and completion date, and store immutable certificate snapshots on issue.
- Generate certificate number and verification URL server-side; use an absolute verification URL in issued/printed certificates.

## 0.6.0 — Company purchasing and production payments

- Connect company course requests and approvals to the same product/access-period model used by individual purchases.
- Consume matching company course credits before creating a payable cart item.
- Complete company order, credit-pool and entitlement reporting.
- Add the PayFast gateway with verified callbacks, idempotency and reconciliation after the simulated gateway workflow is stable.
- Add administrator payment/reconciliation views and exception handling.

## 0.7.0 — Provider marketplace and reporting

- Public course-provider profiles.
- Provider-owned courses and Course Editors.
- Provider contracts, commissions, sales attribution, statements and payouts.
- Student, company, provider and platform financial/learning reports.
- CSV/PDF report exports.
- Indexed system-error viewer and deeper learning analytics.

## 0.8.0 — Additional gateways, integrations and marketing expansion

- Peach Payments, Yoco, Ozow, iKhokha and Paystack plugins.
- Expanded REST API and MCP tools.
- Non-iframe embedded course portals using the approved JavaScript widget / Shadow DOM approach.
- Marketing landing-page builder using reusable theme/page components and independently configurable hero sections.
- Remote MCP transport and OAuth assessment.
- Primary-email change workflow with verification and company-domain enforcement.

---

2026/08/09

# 0.4.0 course presentation and administration QA follow-through

Completed in the 0.4.0 stabilisation line:

- Preserve legacy self-contained course presentation CSS when importing HTML courses. Source CSS is stored per course in PostgreSQL and scoped under the LMS course-content wrapper so it can style lesson content without restyling the LMS shell/navigation.
- Preserve approved Google Fonts stylesheet links used by imported courses while rejecting arbitrary external stylesheet imports.
- Add a Course Administration **Course presentation** control that can import/refresh presentation styling from an original HTML source file for courses that were imported before presentation preservation existed. Refreshing presentation must not alter course IDs, modules, assessments, enrolments, learner results or grades.
- Preserve course presentation CSS through structured JSON export/import and revision cloning.
- Show Course **Category** as a dedicated Administration-list column alongside status, structure and update date.
- In administrator module preview, visibly highlight and tag the stored correct MCQ option and show its answer explanation where available.

Still planned before/with broader integration work:

- Add a simple LMS UI for creating, naming, scoping and revoking MCP/API credentials so normal administration does not require CLI token commands.
- Design MCP access for ChatGPT and Claude around least-privilege read-only administration tools first; do not expose arbitrary PostgreSQL or shell access.
- Add human-readable connection instructions inside the LMS once the remote MCP authentication/transport approach for both clients is finalised.
