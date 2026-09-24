# Commerce Implementation Plan

Original plan: 12 September 2026; current-status update: 24 September 2026
Target: `code/cattolms-v0.8`
Status: Individual purchase foundation, tester administration, company credit purchasing, and payment administration/refunds are included through v0.8.7.

## Current implementation and approved next steps

Individual commerce exists in the v0.8.7 code: guest and signed-in carts; staged checkout; persisted orders, invoices and payment attempts; Omnipay Dummy card outcomes; manual EFT instructions; account order/document pages; idempotent fulfilment into access entitlements; free-course direct access; and scheduled deadline/outbox maintenance. The implementation is in `src/Commerce/`, `src/Commerce/Http/CommerceController.php`, the `20260912210000_add_commerce_foundation.php` and `20260913010000_add_checkout_details.php` migrations, and `tests/Integration/CommercePurchaseIntegrationTest.php`. A manual EFT instruction alone does not confirm payment; the ADMIN confirmation workflow records independent bank evidence. Dummy payment is not a live processor.

The owner approved four ordered work steps, with a break for review after each: documentation correction; completion of tester administration; company credit purchasing; payment administration and refunds. The documentation correction and initial tester grant workflow are included in the owner-authorized v0.8.6.1 release; the remaining three phases are included in v0.8.7. Future release/version choices and Git pushes require explicit owner instruction. Company purchase uses exact course/access-period credits, published paid offers and the current Dummy-backed payment foundation. Direct purchase supports quantities and multiple course/access-period variants; request approval with missing credit starts a linked purchase, and confirmed simulated card payment allocates the matching credit and enrols the learner. Direct EFT company orders remain unpaid and issue no credits until ADMIN records an exact full bank receipt with independent reference, received time, identity and reason. Late or changed paid orders remain in manual review for an explicit, reasoned release. ADMIN refund decisions credit an append-only Account Funds ledger and issue a credit note; full individual item refunds revoke that item's access immediately, and company credit refunds use unused purchased units and historical LIFO value. Account Funds spending, payouts, debt, gifts, real processors and longer-range items remain later decisions/work; do not silently bundle them into this phase.

| Area | Current state | Next required boundary |
| --- | --- | --- |
| Individual checkout and access | Implemented for guest/signed-in carts, Dummy card simulation, manual EFT instructions, orders, invoices and access entitlements | Preserve and extend the existing services and tests; do not rebuild this foundation |
| Tester grants | Course-first and person-first grants show status/expiry and history; ADMIN can revoke in context or deliberately email an invitation | Preserve the released workflow |
| Company credits | Company checkout buys exact-match course/access-period credits; paid lines create credit lots; a linked request receives one allocation, enrolment and notices on successful immediate payment | Do not issue credits from unconfirmed EFT orders |
| Payment operations | ADMIN can confirm exact full EFT payment with immutable evidence, review/release paid exceptions, and approve individual or unused company-credit refunds; credit notes and Account Funds entries are append-only, with immediate full individual item access revocation | Account Funds spending, payout, partial bank settlement and real gateways remain later work |
| Later commerce | Real gateway, funds, payouts, debt, gifts and broader academic history are not implemented | Scope separately after the approved steps |

## 1. Scope and authority

The five `COMMERCE/20260912-1908-CattoLMS-Commerce-*-v1.1-draft` documents remain the detailed domain input for unfinished commerce work. The sections below preserve the original design map and acceptance rules; their September 12 discovery statements are historical. Use the current-status map and owner-approved order above to decide what to build now.

The specification's settled business rules and explicit bespoke/Omnipay decision take precedence over stale passages saying engine selection remains undecided. New commerce rules supersede conflicting pre-commerce rules in the copied LMS documentation. Preserve the original five input files and record reconciliations here.

All implementation belongs in `code/current` (`code/cattolms-v0.8`), which currently identifies as v0.8.7. Older version directories are historical references. The owner authorized the v0.8.7 release; later releases and pushes still require explicit instruction. Do not reset the database merely to implement this plan.

## 2. Original discovery baseline — historical, 12 September 2026

The following bullets describe the pre-commerce discovery day. They are retained as a validation record, not as the current code or test status.

- `code/current` now resolves to `code/cattolms-v0.8`, on both host and container.
- Existing Podman PHP and Nginx containers were restarted. The shared Symfony cache contained absolute v0.7 paths and caused duplicate class loading; it was archived to `runtime/storage/cache/symfony-before-v08-20260912` and regenerated.
- Copied `vendor/bin` launchers had lost execute permission. Application-owned executable copies now work; originals remain under `vendor/bin-before-v08-permission-fix` in v0.8.
- PHP is 8.5.10; Symfony FrameworkBundle is 8.1.6. Symfony reports `catto.code_root=/home/cattotest/code/cattolms-v0.8`. The local homepage returns HTTP 200.
- All five existing Phinx migrations are applied. No migration was executed during discovery.
- `COMPOSER_PROCESS_TIMEOUT=0 composer qa` passed in the development container: **562 tests, 8,890 assertions**, PHPStan level 6, architecture, runtime, UI contracts, and release validation. This is local Podman validation, not VPS validation.
- A Composer dry run for `symfony/workflow:8.1.*`, `league/omnipay:^3`, and `omnipay/dummy:^3` succeeded: 14 proposed additions, no existing package updates/removals. It resolved Workflow 8.1.0, league/omnipay 3.2.1, omnipay/common 3.5.1, and Dummy 3.0.0. This proves dependency resolution, not runtime compatibility; adapter tests must prove that next. No dependencies were installed or manifest/lock changes retained.

## 3. Original reuse and change map — reassess each row against v0.8.6

Paths below are relative to this code root. “Currently” in this original table means 12 September 2026; the current-status map above takes precedence where implementation has since landed.

| Existing component | Implementation decision |
| --- | --- |
| `Support/Money.php` and `MoneyTest` | Reuse and harden. Currently addition does not reject mixed currencies, negative inputs can be clamped, and some conversions use floats. Commerce needs strict validation, integer arithmetic, checked overflow, explicit rounding, and exact decimal gateway serialization. Represent ledger direction separately from a non-negative amount. |
| `course_price_variants`, `CourseService`, `CourseRepository` | Keep ordinary course prices/access periods. Add offer metadata and typed commercial products referencing these variants for access, gifts, credits, extensions, retakes, and private offers. Retire referenced variants instead of the current hard delete. Snapshot purchase terms independently of live prices. |
| `course_enrolments`, `LearningService` | Preserve enrolment identity/progress. Add separate access entitlements linked to enrolments, with their own state, beneficiary, source, frozen duration, activation deadline, start and expiry. Existing `status` and `started_at` currently conflate learning with access. |
| `course_credits`, `course_credit_allocations` | Existing credit rows are quantity-bearing lots, not individual tokens. Extend them as purchase/grant lots and add individual credit-unit identities beneath them. Link existing allocations to units; add deadlines, invitations, state/events, and historical purchase valuation. Avoid a second independent credit pool. |
| `PlatformAdministrationService::decideRequest()` and `AdministrationRepository::availableCredit()` | Preserve exact course/period matching and existing row-locking. Delegate allocations to the commerce service. If no credit exists, an approved request leads into purchase; approval alone must not manufacture paid access. |
| `PlatformAdministrationService::removeCompanyPerson()` and company enrolment removal | Currently remove learner access on membership removal. Change ordinary company actions to preserve consumed entitlements and restrict reversal to an unconsumed provisional allocation. ADMIN exceptions remain reasoned and audited. |
| `AssessmentService`, `AssessmentRepository`, `CourseRepository::createCertificate()` | Retain attempt/session history and grading infrastructure. Add atomic retake allowances. Certificates currently depend on passing and are updated on enrolment conflict; replace new issuance with immutable completion records. Add the full transcript and shared learner-course verification identity. |
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

Use additive Phinx migrations for new commerce features. The current code has carts, orders/item snapshots, documents/numbering, payment attempts/events, audit/outbox, entitlements, manual bank evidence, refunds, credit notes and a refund-credit Account Funds ledger. Account Funds spending/reservations, payouts, debt, disputes, purchased credit invitations, gifts, retake allowances and broader completion/verification history remain later work according to owner decisions. Do not recreate existing commerce tables.

Use BIGINT minor units with currency and TIMESTAMPTZ. Snapshot purchaser/billing identity, course/revision policy, duration, tax, discounts, consent wording/version and terms. Issued document content is immutable; lifecycle/payment summaries are separate mutable projections. Allocate permanent invoice numbers transactionally, with uniqueness and no reuse of issued numbers.

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

The table below is the original full-domain sequence, not the next-work order. Phases 1–3 have an implemented individual-purchase foundation but are not a claim of complete coverage of every listed product and policy. The owner-approved order at the top of this document governs the next three coding steps and their review breaks. Security, audit, concurrency protection and tests accompany each step; they do not wait for a later administration phase.

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

The individual-course milestone, company credit purchasing and ADMIN bank-confirmation/refund paths are released in v0.8.7 and covered by Commerce integration tests. The broader original phases above remain a domain map, not a claim that funds spending, payouts, gifts or real gateways are present.

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

These are implementation reconciliations to satisfy the specification, not changes to the settled business policy. Preserve the legal/accounting gates already listed in the brief: company-credit expiry, unrestricted funds transfers, dormant funds, final consent wording and future VAT activation. They do not prevent Dummy development.

## 8. Test and handoff strategy

Place Commerce tests under the existing Unit, Architecture and Integration suites and add a focused `composer test:commerce` selector. Use Symfony MockClock, FakeMailer, payment contract fixtures and PostgreSQL integration tests. Enforce Omnipay dependency boundaries, browser CSRF, callback authentication, purchaser/company scope and mandatory audit reasons.

Prove concurrent double-confirmation, duplicate checkout, double allocation, spend-versus-payout, retake consumption and invoice numbering with separate database connections. Test crashes/retries around gateway confirmation and notification delivery, not only sequential happy paths. Target at least 90% new domain/application line coverage while directly testing every financial invariant.

Existing fixtures delete records during cleanup; protected commerce history needs rollback-based fixtures or a disposable isolated PostgreSQL test database for committed/concurrent scenarios. Use the canonical migration configuration against that isolated environment rather than adding a second migration convention. Do not weaken immutability to make cleanup convenient.

Some existing tests encode rules the new specification expressly changes. Replace those assertions with tests proving the new rule and preservation of unrelated behavior; do not simply skip them. Record the mapping in each phase report. The existing seed-memory test has a documented accumulation risk, although this baseline passed; commerce testing must not worsen shared data accumulation.

Each phase report records changed files, migrations/backfills, tests, commands/results, remaining gaps and readiness for the next phase. Re-run the full `composer qa` gate after each coherent phase. No real provider integration begins until the complete Dummy-backed acceptance phase passes.
