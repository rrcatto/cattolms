# Commerce Implementation Plan

Original plan: 12 September 2026; current-status update: 18 October 2026 (launch work, released in v0.9.4)
Target: `code/cattolms-v0.9` (`code/cattolms-v0.8` until 2026/10/16)
Status: Individual and company purchasing, payment administration and refunds (v0.8.7), billing profiles (Phase F), promo codes (Phase G), course bundles (Phase H) and independent entitlement sources are released through v0.8.8.4, financial documents on the document template engine (Phase K) in v0.8.9, and the purchase box shared by course and landing pages (Phase L) in v0.9.0. The public legal pages, terms acceptance and the Refund Policy rule are released in v0.9.1. The launch work of 2026/10/17 (released in v0.9.4, for the launch on 2 November 2026) adds immediate start on payment, account references, the money ledger and wallets (replacing Account Funds), EFT payments of any amount, refund requests, refund deductions and recorded payouts, Commerce Settings and the transactions that need attention. Exports of the ledger, debt, gifts and real gateways (Stitch included) are not implemented.

## Current implementation

The commerce code lives in `src/Commerce/` (Contract, Domain, Application, Policy, Workflow, Infrastructure, Http) and `src/Bundle/`, with tests in the `commerce` PHPUnit group (`composer test:commerce`) and the browser checks in `tests/Browser/`. What exists:

- **Individual purchase.** Guest and signed-in carts of course offers and bundles; staged checkout (details with the billing profile, payment method, review with the promo code); persisted orders with immutable item and order snapshots; invoices, receipts and credit notes rendered only from the snapshot; payment attempts through the Omnipay Dummy adapter or a manual EFT instruction; idempotent fulfilment; free-course direct access; the `commerce:maintain` worker for payment deadlines, access activation and expiry, and invoice email.
- **Company purchase.** The Company Administrator, the only company role with `COMPANY.CREDIT.MANAGE` and `COMPANY.BILLING.MANAGE`, buys exact course/access-period credits; paid lines create immutable credit lots; a linked request is fulfilled only after confirmed payment. Direct EFT company orders issue no credit before ADMIN confirms the full amount.
- **Payment administration** (`/admin/commerce/orders`). EFT confirmation of any amount with immutable bank evidence (too little waits, too much is credited to the wallet), reasoned manual-review release, and refunds that create credit notes and ledger rows, as wallet credit or money back, with the payout recorded. Company refunds use unused purchased units at historical LIFO value.
- **Billing profiles** (Phase F). One reusable profile per person (`user_billing_profiles`) and per company (`company_billing_profiles`), filled into both checkouts and copied into each order's immutable `billing` snapshot. VAT stays disabled; the tax number is informational.
- **Promo codes** (Phase G, `/admin/promotions`). Percentage or fixed discounts, a half-open validity window, minimum spend, total and per-customer limits, and course and bundle scopes. The browser sends only the code; the server calculates, revalidates under the promotion's row lock at placement, holds a use while the order is unpaid and redeems it once when paid. The discount is an order adjustment allocated over lines by largest remainder and bounds each line's refund. Company credit purchases take no promotions.
- **Course bundles** (Phase H, `/bundles`, `/admin/bundles`). Bundles of published courses with their own price and access period, sold as one line that snapshots its courses, price and access period. Never sold to companies.
- **Financial documents (Phase K, v0.8.9, 2026/10/08).** Invoices, receipts and credit notes are issued and drawn only by the shared document engine (`src/Document/`, Phase I), the way certificates are (Phase J). `Commerce\Document\FinancialDocuments` issues each one at the moment it always was issued (an invoice when an order is placed, a receipt when a payment is confirmed, a credit note when a refund is approved): `FinancialDocumentDataBuilder` builds every value it prints from the immutable order, billing, promotion and bundle snapshots, the payment (the amount it received, how, with what reference and, for an EFT, the bank date) or the refund (the refunded line as it was paid and the amount credited), plus the business and EFT details at that moment; the current template draws it, and `commerce_documents` stores the values (`document_data`) with `template_id`, `template_version_id` and `template_version_number` (a trigger checks they name a published version of a template of the document's kind). A document is drawn again from those only; its PDF is made the first time it is viewed, downloaded or emailed and kept (`commerce_document_files`). `InvoicePdfRenderer` and its hard-coded HTML are gone. ADMIN changes the design of all three at Commerce → Financial Documents (`/admin/commerce/documents`, `PLATFORM.DOCUMENT.VIEW`/`MANAGE`): a logo, a typeface, an accent colour and each document's closing note, under a live PDF preview of made-up orders, never template HTML; each save publishes a new version of the templates it changes, and documents already issued keep theirs. The order page in ADMIN names the design version each document was drawn in.
- **What a visitor can do about a course (Phase L, v0.9.0).** `CoursePurchaseOptions` decides it once from the facts checkout refuses on (an open enrolment, an order holding the course, the course's status, its active prices) for the course page and a course's landing page: open the course, see the order, buy it, or read that it is not on sale. A purchase button on a published landing page sends `landing_page_id` with `/cart/add`, which records `landing_page_cta_clicked` once the cart accepts it; the purchase itself is still `checkout_started` and `course_purchased`. Landing pages never show promotions.
- **Legal documents, terms acceptance and the refund rule (v0.9.1, 2026/10/13).**
  - **Pages.** `/terms`, `/refunds` and `/privacy` are public Core pages (`LegalController`, `resources/views/pages/{terms,refunds,privacy}.html.twig`). `CattoLearning\Legal\LegalDocuments` holds the date each document's text last changed (no versions); the seller's details all come from the Site Owner (`Configuration\SiteOwner`, owner 2026-10-18). A page's last-updated date is the later of its text's date and the Site Owner's last change. The Core footer lists the three documents from that registry. Changing a document's text means changing its date in `LegalDocuments` in the same change. An order's snapshot keeps the accepted documents' dates (`consent.documents`) and the Site Owner's details (`site_owner`).
  - **Acceptance.** Both checkouts include `partials/commerce-terms-acceptance.html.twig`: one required tick box whose label names and links the Terms and Conditions and the Refund Policy. `CheckoutService::place`, `OrderService::place` and `CompanyCreditPurchaseService::place` refuse without it.
  - **Recorded versions.** The order's immutable snapshot records the Terms version as `terms_version` (on the order and on each individual or bundle line) and the Refund Policy version as `consent.refund_policy_version`, beside `consent.accepted_at` and `consent.session_id`. `config/commerce.yaml` no longer has a `terms_version`; the development label is gone. Prices, lines and every other snapshot value are unchanged.
  - **No identity number at checkout.** The details step asks for first name, last name, cell number and billing details only. The account profile keeps its optional identification number field.
  - **The refund rule.** `Commerce\Domain\RefundRule` states it and `RefundAdministrationService::approve` enforces it: a refund is refused once the learner has started the course (`course_enrolments.started_at`; any course, for a bundle) or 7 days have passed since the purchase, and a company credit refund once 7 days have passed since the credits were bought. The purchase is the order's confirmed payment (`CommerceRepository::paidAt`: the latest `paid` payment event). Which company credits are refundable is unchanged: unused units only, newest lot first.
  - **Override.** ADMIN may approve a refused refund with a written override reason of 5 to 2,000 characters. `commerce_refunds.policy_refusal` (what the rule refused) and `commerce_refunds.override_reason` are set together and are append-only like the rest of the row; the `refund.approved` audit event carries both. The ADMIN order page states the rule, shows per line whether a refund is inside it, asks for the override reason only when it is not, and marks an approved refund that used one. An override cannot refund a company credit a learner has started: the lot accounting and its trigger still refuse allocated units.
  - **Unchanged mechanics.** A refund is per order line and bounded by what the line was paid; a full refund of a line revokes that line's sources and leaves a course another source still covers; a bundle is refunded whole; an order a promotion made free has nothing to refund; the refunded value is still a credit note and an Account Funds entry. The Refund Policy describes payout through Stitch or by EFT, which is not built.
  - **Two corrections.** The customer's order page says a refunded or partly refunded order was refunded (it said the payment was being processed), and the company assign form says the access period starts when the staff member presses Start course.
- **Launch work: immediate start, wallets, refunds and settings (2026/10/17, released in v0.9.4).** The owner approved the launch list of workspace `REPORTS/commerce-wallets-design-2026-10-17.md` (section 17).
  - **Immediate start.** A course checkout has a second required box, `accept_start` (`OrderService::IMMEDIATE_START`, recorded in `consent` with `immediate_service`); `FulfilmentService` starts the access on payment, for every course of a bundle too. The 90-day automatic start is gone. Start course on an assigned course needs `confirm_start`.
  - **Account references.** `users.account_reference` and `companies.account_reference`, made by the database (`next_account_reference()`: the prefix setting, the date, a sequence number, an ISO 7064 MOD 37,36 check character; `Support\AccountReference` formats, reads and checks them). Shown on the dashboards, profile, order pages and EFT instructions; searchable in People, Companies and Orders.
  - **Ledger and wallets.** `commerce_ledger_entries` (append-only; kinds and effects in `Wallet\LedgerKind`), `commerce_wallets`, `commerce_wallet_lots`, `commerce_wallet_allocations` (guard trigger), `commerce_wallet_reminders`; `WalletService` and `WalletRepository`. Spending is oldest first; returns keep the original expiry; expiry and the reminder email run in `CommerceMaintenance`. My Wallet, Company Wallet and Administration → Wallets (with corrections) show balances and statements. `commerce_fund_accounts` and `commerce_fund_entries` are gone.
  - **Payments in parts.** The wallet part of an order is a paid payment of gateway `wallet` made at placement, with its receipt; `PaymentService::confirm` settles an order when its payments reach the total, credits an overpayment to the wallet, leaves a part-paid order waiting, and credits a payment that arrives after cancellation to the wallet. `OrderService::cancelDue` gives what a cancelled order received to the wallet. The company wallet can pay for credits.
  - **Refunds.** `RefundRule` refuses a started course outright (no wallet credit either) and anything outside the refund window setting. `RefundAdministrationService::quote()` works out the wallet part, the deductions (Stitch transaction fee; Stitch refund fee for money back; EFT refund fee tiers for EFT money back) and the net; `approve()` takes a destination and a request id; `recordPayout()` records money back paid outside the LMS (`commerce_refund_payouts`, the `refund_paid` ledger row). `RefundRequestService` and `commerce_refund_requests` hold requests from learners and Company Administrators; the rule's verdict at request time is kept. The credit note states the settlement.
  - **Settings and attention.** `Settings\CommerceSettings` (`COMMERCE_*` defaults, `app_options` overrides, sections of Administration → Settings) holds the fees, the reference prefix, the wallet expiry and reminder, the refund window, the payment due period and the payment link lifetime. `TransactionAttention` and `AttentionRepository` list underpaid and overpaid orders, cancelled orders' payments, refund requests and refunds to pay on the ADMIN dashboard, each with an email form.
  - **Not built for launch (owner, 2026-10-17).** Exports (CSV, XLSX, ODS, PDF) by 30 November, dashboard money figures and charts, Start course confirmation through the API and MCP, cash-outs (never), the Stitch integration.
- **Entitlement sources.** Behind the one enrolment a learner sees per course, `commerce_entitlements` rows are independent sources (individual purchase, each bundle, free access, an ADMIN/company/seed `origin`), each with its own period, activation and expiry. `AccessService` keeps the course open while any source is valid; a refund revokes only the refunded line's sources.

| Area | Current state | Next boundary |
| --- | --- | --- |
| Individual checkout and access | Carts of courses and bundles, Dummy card simulation, manual EFT, orders, documents, entitlement sources | Preserve and extend the existing services and tests; do not rebuild this foundation |
| Tester grants | Course-first and person-first grants with status, expiry and history; ADMIN revokes in context or emails an invitation | Preserve the released workflow |
| Company credits | Exact course/access-period credit lots from paid lines; linked requests fulfilled after confirmed payment | Do not issue credits from unconfirmed EFT orders; bundles are not company products |
| Payment operations | EFT confirmation of any amount with evidence, manual-review release, refunds as wallet credit or money back with deductions and recorded payouts, refund requests, the Refund Policy rule with an ADMIN override, the transactions that need attention | Exports of the ledger and statements, and real gateways (Stitch) remain later work |
| Legal documents and consent | Public Terms, Refund Policy and Privacy Policy with a last-updated date (no versions); required acceptance at both checkouts and the immediate start box at course checkout; the acceptance time in the order snapshot | The Stitch integration, identity verification and the public transcript the documents describe are planned (`ROADMAP.md` sections 2c and 10) |
| Billing profiles (Phase F) | Reusable person and company profiles; structured `billing` snapshot on every order and document | Editable document templates would read the snapshot; VAT stays disabled |
| Promo codes (Phase G) | ADMIN promotions with course and bundle scopes, server-side calculation, placement revalidation under lock, redemption when paid | Automatic promotions, stacking, referral codes, gift vouchers and loyalty are not designed |
| Course bundles (Phase H) | One line per bundle with an immutable composition, price and access-period snapshot; a `bundle` source for every course | Company bundles, bundle popularity, subscriptions and several access periods per bundle are not designed |
| Entitlement sources | Several sources per enrolment; access while any is valid; refunds revoke only their line's sources | ADMIN removal still ends every source of a course together |
| Later commerce | Real gateway, funds spending, payouts, debt, disputes, gifts and broader academic history are not implemented | Scope each separately with the owner |

The owner sets the order of further commerce work; versions, commits and pushes each need explicit instruction.

## 1. Scope and authority

The five `COMMERCE/20260912-1908-CattoLMS-Commerce-*-v1.1-draft` documents remain the detailed domain input for unfinished commerce work. The sections below preserve the original design map and acceptance rules; their September 12 discovery statements are historical. Use the current-status map and owner-approved order above to decide what to build now.

The specification's settled business rules and explicit bespoke/Omnipay decision take precedence over stale passages saying engine selection remains undecided. New commerce rules supersede conflicting pre-commerce rules in the copied LMS documentation. Preserve the original five input files and record reconciliations here.

All implementation belongs in `code/current` (`code/cattolms-v0.9`), which currently identifies as v0.9.2. Older version directories are historical references. Releases and pushes require explicit owner instruction. The schema is the one canonical baseline: a schema change for this plan is made there and the development database is rebuilt (`PROJECT-INSTRUCTIONS.md` section 2).

## 2. Original discovery baseline — historical, 12 September 2026

The following bullets describe the pre-commerce discovery day. They are retained as a validation record, not as the current code or test status.

- `code/current` now resolves to `code/cattolms-v0.8`, on both host and container.
- Existing Podman PHP and Nginx containers were restarted. The shared Symfony cache contained absolute v0.7 paths and caused duplicate class loading; it was archived to `runtime/storage/cache/symfony-before-v08-20260912` and regenerated.
- Copied `vendor/bin` launchers had lost execute permission. Application-owned executable copies now work; originals remain under `vendor/bin-before-v08-permission-fix` in v0.8.
- PHP is 8.5.10; Symfony FrameworkBundle is 8.1.6. Symfony reports `catto.code_root=/home/cattotest/code/cattolms-v0.8`. The local homepage returns HTTP 200.
- All five existing Phinx migrations are applied. No migration was executed during discovery.
- `COMPOSER_PROCESS_TIMEOUT=0 composer qa` passed in the development container: **562 tests, 8,890 assertions**, PHPStan level 6, architecture, runtime, UI contracts, and release validation. This is local Podman validation, not VPS validation.
- A Composer dry run for `symfony/workflow:8.1.*`, `league/omnipay:^3`, and `omnipay/dummy:^3` succeeded: 14 proposed additions, no existing package updates/removals. It resolved Workflow 8.1.0, league/omnipay 3.2.1, omnipay/common 3.5.1, and Dummy 3.0.0. This proves dependency resolution, not runtime compatibility; adapter tests must prove that next. No dependencies were installed or manifest/lock changes retained.

## 3. Original reuse and change map — historical, 12 September 2026

Paths below are relative to this code root. “Currently” in this original table means 12 September 2026; most rows have since been implemented, and the current-implementation section above takes precedence.

| Existing component | Implementation decision |
| --- | --- |
| `Support/Money.php` and `MoneyTest` | Reuse and harden. Currently addition does not reject mixed currencies, negative inputs can be clamped, and some conversions use floats. Commerce needs strict validation, integer arithmetic, checked overflow, explicit rounding, and exact decimal gateway serialization. Represent ledger direction separately from a non-negative amount. |
| `course_price_variants`, `CourseService`, `CourseRepository` | Keep ordinary course prices/access periods. Add offer metadata and typed commercial products referencing these variants for access, gifts, credits, extensions, retakes, and private offers. Retire referenced variants instead of the current hard delete. Snapshot purchase terms independently of live prices. |
| `course_enrolments`, `LearningService` | Preserve enrolment identity/progress. Add separate access entitlements linked to enrolments, with their own state, beneficiary, source, frozen duration, activation deadline, start and expiry. Existing `status` and `started_at` currently conflate learning with access. |
| `course_credits`, `course_credit_allocations` | Existing credit rows are quantity-bearing lots, not individual tokens. Extend them as purchase/grant lots and add individual credit-unit identities beneath them. Link existing allocations to units; add deadlines, invitations, state/events, and historical purchase valuation. Avoid a second independent credit pool. |
| `PlatformAdministrationService::decideRequest()` and `AdministrationRepository::availableCredit()` | Preserve exact course/period matching and existing row-locking. Delegate allocations to the commerce service. If no credit exists, an approved request leads into purchase; approval alone must not manufacture paid access. |
| `PlatformAdministrationService::removeCompanyPerson()` and company enrolment removal | Currently remove learner access on membership removal. Change ordinary company actions to preserve consumed entitlements and restrict reversal to an unconsumed provisional allocation. ADMIN exceptions remain reasoned and audited. |
| `AssessmentService`, `AssessmentRepository`, `CertificateIssuer` | Retain attempt/session history and grading infrastructure. Add atomic retake allowances. Certificates are issued on a passing result through the document template engine; each issue stores its values and template version, and a reissue replaces them under the same number and public id. Replace new issuance with immutable completion records. Add the full transcript and shared learner-course verification identity. |
| `Database`, `DbalDatabase`, `TransactionManager` | Reuse the shared DBAL connection and transaction boundary; no Doctrine ORM. Financial SQL stays in repositories. |
| `PermissionCatalog`, `AclService`, `SelectedCompanyContext` | Activate reserved commerce permissions and add missing funds, payouts, offers, overrides and disputes capabilities. Enforce permission plus purchaser/company/resource scope. |
| `AuditRepository`, `Event/EventDispatcher` | Reuse normal activity reporting. Add protected commerce audit/payment/ledger events. The existing plugin dispatcher catches listener failures, so it must not be responsible for essential fulfilment. |
| Workspace registries, `ThemeRenderer`, core Twig pages | Extend the existing account/company/admin workspaces, shared controls and navigation. Follow `docs/ux-ui-rules.md`; retain server-rendered forms and progressive enhancement. |

## 4. Commerce architecture — original design, partially implemented

`src/Commerce/` now contains Contract, Domain, Application, Policy, Workflow, Infrastructure and Http code for individual purchasing. The remaining domain work should extend this existing structure. Domain objects describe commerce state; they do not duplicate Course, User, Company or learning records. Repositories hydrate workflow subjects without introducing ORM entities.

The current Commerce controller uses attribute routes and the application has service wiring and transitions. Further services should follow the existing Symfony wiring and routing conventions; policy defaults, effective-dated overrides and purchased policy snapshots remain broader plan requirements where not yet implemented.

The payment boundary is:

```text
Checkout / OrderService
  -> PaymentService
  -> PaymentGatewayInterface (Catto request/result DTOs)
  -> OmnipayPaymentGatewayAdapter
  -> Omnipay Dummy
```

PHP-HTTP/Guzzle remains infrastructure beneath Omnipay. Only `Infrastructure/Payment` imports Omnipay. Keep merchant attempt IDs distinct from provider references; report supported operations explicitly. Dummy runs through this same contract and is rejected in production configuration.

Omnipay Dummy supports deterministic successful/failed purchases. Use its documented fixtures for those cases. Pending, signed-notification, reversal and other unsupported scenarios use a deterministic test double at the Catto interface, without pretending Dummy implements them. Browser return parameters never constitute payment proof. Future real gateways remain separate work after acceptance.

Sources checked: [Omnipay Dummy](https://github.com/thephpleague/omnipay-dummy), [Omnipay dependency manifest](https://github.com/thephpleague/omnipay/blob/master/composer.json), and [Symfony Workflow](https://symfony.com/doc/current/workflow.html). Implement against the installed 8.1 component API, rather than assuming every feature in current documentation is available.

## 5. Persistence and transaction design — implemented foundation plus remaining rules

While the system is not live, schema changes for new commerce features go into the one canonical baseline migration and the development database is rebuilt (`PROJECT-INSTRUCTIONS.md` section 2); additive migrations begin only after production is declared. The current code has carts (course and bundle lines, an applied promotion), orders and item snapshots (billing, promotion and bundle composition), documents and numbering, payment attempts and events, audit and outbox, entitlement sources and bundle grants, billing profiles, promotions and their redemptions, bundles and their offers, manual bank evidence, refunds, credit notes and a refund-credit Account Funds ledger. Account Funds spending/reservations, payouts, debt, disputes, purchased credit invitations, gifts, retake allowances and broader completion/verification history remain later work according to owner decisions. Do not recreate existing commerce tables.

Use BIGINT minor units with currency and TIMESTAMPTZ. Snapshot purchaser/billing identity, course/revision policy, duration, tax, discounts, consent wording/version and terms (implemented: `terms_version` and `consent.refund_policy_version` are the versions of the published Terms and Conditions and Refund Policy from `LegalDocuments`). Issued document content is immutable; lifecycle/payment summaries are separate mutable projections. Allocate permanent invoice numbers transactionally, with uniqueness and no reuse of issued numbers.

Payment sequence:

1. Commit the order, item snapshots, invoice and attempt before invoking a gateway. A payment failure cannot roll back the invoice.
2. Call the gateway outside a long-lived database transaction. Preserve an ambiguous timeout as unresolved rather than inviting an unsafe duplicate charge.
3. Verify and normalize the result; lock the attempt/order. Deduplicate provider events and merchant requests using persistent unique keys.
4. Record payment evidence and one receipt per successful payment. Fulfil only after full settlement and review clearance. Enforce a unique fulfilment key per order-item unit, not merely per payment event.
5. Commit entitlement/credit creation with its fulfilment record. Queue notifications through a transactional outbox; retry delivery independently.

Distinct successful attempts on the same order are real money received, not duplicate events: retain receipts, prevent repeated fulfilment, and account for overpayment. Review/late-payment paths must retain that evidence.

Lock funds accounts in a consistent order. Record linked ledger movements for offsets, transfers, refunds and reservations; prevent spending reserved funds and reconcile all projections. Track debt separately. Define explicit release/return movements for cancelled or failed settlement so funds cannot disappear. Protect financial history from update/delete and parent cascades; corrections append compensating records.

Backfill legacy enrolments, lots and allocations with explicit legacy/grant provenance; do not invent historical payments, paid prices or consent. Preserve existing deadlines/history. Reconcile quantities and allocations before enforcing unit-level constraints; report inconsistent data rather than discarding it. New default policies must not retroactively start or expire legacy access.

## 6. Original broad delivery sequence and acceptance — superseded for scheduling

The table below is the original full-domain sequence, not the next-work order. Phases 1–3 have an implemented individual-purchase foundation but are not a claim of complete coverage of every listed product and policy. The owner sets the order of further work. Security, audit, concurrency protection and tests accompany each step; they do not wait for a later administration phase.

| Phase | Deliverable and acceptance |
| --- | --- |
| 1 — Kernel | Harden Money; offers, retirement, cart/checkout, orders, immutable item/billing snapshots, invoices, consent, effective-dated tax scaffolding. Tax stays disabled. Placement creates an invoice even when subsequent payment fails. |
| 2 — Omnipay Dummy | Install the checked dependencies; payment contract, capabilities, adapter, attempts/events and workflow. Prove success, failure, retry, exception normalization, mismatch rejection and duplicate confirmation without provider network calls. |
| 3 — Access integration | Idempotent FulfilmentService, individual entitlements, voluntary/90-day automatic activation, expiry, free-course immediate access and no checkout. Central access policy covers modules, assessments, existing assessment sessions, private media, Web/API/MCP paths. Progress and public academic history survive expiry. |
| 4 — Money lifecycle | Account Funds, full/mixed settlement, refunds/credit notes, immediate full-refund revocation, payouts/reservations, transfers behind the supplied gate, debt, disputes, reversals and reconciliation. No negative available funds or duplicate receipts/fulfilment. |
| 5 — Company credits | Purchase lots and units, batch purchase, provisional allocation, 24-hour consumption, 24-hour invitations, warning notices, reversal and conversion, LIFO refund valuation. Preserve consumed learner access after departure/company dispute. Keep the supplied expiry gate effective. |
| 6 — Gifts and learning products | Token + purchaser PIN, hashed secrets, claim throttling, correction/reissue; original gift activation deadline; extensions, reopenings, one purchased graded attempt and scoped review access; audited pause applications and expiry adjustments. |
| 6b — Academic history | Completion independent of passing, immutable completion certificates, all graded attempts including failures, exclusion of practice attempts, and one public learner-course verification identity. Preserve existing certificate links/snapshots. Diagnostics do not complete a course under the current Course Components contract. |
| 7 — Administration | Complete purchaser/company/admin screens, private offers and discounts, manual EFT/PayShap confirmation, refunds, payouts, grants, debt waivers, deadline overrides, reconciliation, audit and manual review. Require actor/reason/evidence for financial overrides. |
| 8 — Acceptance | All supplied scenarios and workflow transitions, race-condition tests, new-commerce coverage target, complete existing QA, fresh-install and populated-database migration rehearsals, and browser checks across bundled themes. Update operations/handoff documentation and demonstrate Dummy-only end-to-end flows. |

The individual-course milestone, company credit purchasing and ADMIN bank-confirmation/refund paths were released in v0.8.7; billing profiles, discounts (promo codes), bundles and entitlement sources followed in v0.8.8.4. All are covered by the commerce integration tests. The broader original phases above remain a domain map, not a claim that funds spending, payouts, gifts or real gateways are present.

## 7. Timed work and workflow reconciliation

The current command is `commerce:maintain`, scheduled by the local Podman worker and documented in `OPERATIONS.md`. It advances overdue orders/access and retries requested invoice delivery. Extend that bounded maintenance path for new deadlines where appropriate. Use injected `ClockInterface`, locks and idempotent transitions. Calculate access from the actual deadline, not a delayed worker's execution time. Request-time checks enforce due access changes even if the worker is delayed.

Adapt the supplied YAML before implementing it:

- Reactivating a cancelled order also needs a permitted invoice lifecycle transition; preserve its issued contents and number.
- Repeated partial payments/refunds require transitions/events while already partially paid/refunded.
- Partial payout approval releases the unapproved reservation immediately. Processing failures need retry/rejection and exact release semantics.
- Suspension/restoration must remember prior state and re-evaluate deadlines; it cannot blindly restore access or availability.
- Expired entitlements still need refund/revocation history. A gift claimed after its activation deadline inherits elapsed access time, potentially already expired; claiming/reissuing never restarts the clock.
- An unredeemed company invitation expires rather than auto-consuming as if an existing learner had been allocated. Its full invitation window may cross the lot deadline.
- Approval of a full refund revokes access immediately; subsequent ledger credit and eventual bank payout remain distinct steps.
- The specification requires reasons for every manual financial action; apply that stricter rule despite the narrower wording in the payout section.

These are implementation reconciliations to satisfy the specification, not changes to the settled business policy. Preserve the legal/accounting gates already listed in the brief: company-credit expiry, unrestricted funds transfers, dormant funds and future VAT activation. They do not prevent Dummy development. The consent wording gate was settled on 13 October 2026: the purchaser accepts the published Terms and Conditions and Refund Policy, and the order records the version of each.

## 8. Test and handoff strategy

Commerce tests live under the existing Unit, Architecture and Integration suites; `composer test:commerce` runs the `commerce` group. Use Symfony MockClock, FakeMailer, payment contract fixtures and PostgreSQL integration tests. Enforce Omnipay dependency boundaries, browser CSRF, callback authentication, purchaser/company scope and mandatory audit reasons.

Prove concurrent double-confirmation, duplicate checkout, double allocation, spend-versus-payout, retake consumption and invoice numbering with separate database connections. Test crashes/retries around gateway confirmation and notification delivery, not only sequential happy paths. Target at least 90% new domain/application line coverage while directly testing every financial invariant.

Existing fixtures delete records during cleanup; protected commerce history needs rollback-based fixtures or a disposable isolated PostgreSQL test database for committed/concurrent scenarios. Use the canonical migration configuration against that isolated environment rather than adding a second migration convention. Do not weaken immutability to make cleanup convenient.

Some existing tests encode rules the new specification expressly changes. Replace those assertions with tests proving the new rule and preservation of unrelated behavior; do not simply skip them. Record the mapping in each phase report. The existing seed-memory test has a documented accumulation risk, although this baseline passed; commerce testing must not worsen shared data accumulation.

Each phase report records changed files, migrations/backfills, tests, commands/results, remaining gaps and readiness for the next phase. Re-run the full `composer qa` gate after each coherent phase. No real provider integration begins until the complete Dummy-backed acceptance phase passes.
