# Commerce Implementation Plan

Date: 12 September 2026
Target: `code/cattolms-v0.8`
Status: Discovery complete; proposed implementation sequence. Commerce code is not yet implemented.

## 1. Scope and authority

Implement the five `COMMERCE/20260912-1908-CattoLMS-Commerce-*-v1.1-draft` documents: the specification, implementation plan, policy defaults, workflows, and test matrix. The current user instruction requests discovery and a plan before implementation.

The specification's settled business rules and explicit bespoke/Omnipay decision take precedence over stale passages saying engine selection remains undecided. New commerce rules supersede conflicting pre-commerce rules in the copied LMS documentation. Preserve the original five input files and record reconciliations here.

All implementation belongs in v0.8. Older version directories are historical references. Directory selection does not itself change application version metadata: the copied code still identifies as 0.7, consistent with the brief's instruction not to bump it. Do not commit, push, release, or reset the database as part of this plan.

## 2. Verified development baseline

- `code/current` now resolves to `code/cattolms-v0.8`, on both host and container.
- Existing Podman PHP and Nginx containers were restarted. The shared Symfony cache contained absolute v0.7 paths and caused duplicate class loading; it was archived to `runtime/storage/cache/symfony-before-v08-20260912` and regenerated.
- Copied `vendor/bin` launchers had lost execute permission. Application-owned executable copies now work; originals remain under `vendor/bin-before-v08-permission-fix` in v0.8.
- PHP is 8.5.10; Symfony FrameworkBundle is 8.1.6. Symfony reports `catto.code_root=/home/cattotest/code/cattolms-v0.8`. The local homepage returns HTTP 200.
- All five existing Phinx migrations are applied. No migration was executed during discovery.
- `COMPOSER_PROCESS_TIMEOUT=0 composer qa` passed in the development container: **562 tests, 8,890 assertions**, PHPStan level 6, architecture, runtime, UI contracts, and release validation. This is local Podman validation, not VPS validation.
- A Composer dry run for `symfony/workflow:8.1.*`, `league/omnipay:^3`, and `omnipay/dummy:^3` succeeded: 14 proposed additions, no existing package updates/removals. It resolved Workflow 8.1.0, league/omnipay 3.2.1, omnipay/common 3.5.1, and Dummy 3.0.0. This proves dependency resolution, not runtime compatibility; adapter tests must prove that next. No dependencies were installed or manifest/lock changes retained.

## 3. Reuse and change map

Paths below are relative to this code root.

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

## 4. Commerce architecture

Create `src/Commerce/{Contract,Domain,Application,Policy,Workflow,Event,Infrastructure,Http}`. Domain objects describe commerce state; they do not duplicate Course, User, Company or learning records. Repositories hydrate workflow subjects without introducing ORM entities.

Register services explicitly in `config/services.yaml`, excluding DTOs/value objects. Add attribute route discovery for Commerce controllers in `config/routes.yaml`. Import adapted workflows into `config/packages/workflow.yaml`; load policy defaults through a typed configuration service, with effective-dated overrides and purchased policy snapshots.

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

## 5. Persistence and transaction design

Use additive Phinx migrations grouped by feature. Never rewrite the baseline. Add tables for offers/policy history; carts/orders/item snapshots; documents and numbering; payment attempts/events; fulfilment records and entitlements; funds accounts/ledger/reservations, refunds, payouts, debt and disputes; credit units/invitations; gifts; retake allowances; completion and verification history.

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

## 6. Delivery sequence and acceptance

Security, audit, concurrency protection and tests accompany every phase; phase 7 completes administration rather than introducing safeguards late.

| Phase | Deliverable and acceptance |
| --- | --- |
| 1 — Kernel | Harden Money; offers, retirement, cart/checkout, orders, immutable item/billing snapshots, invoices, consent, effective-dated tax scaffolding. Tax stays disabled. Placement creates an invoice even when subsequent payment fails. |
| 2 — Omnipay Dummy | Install the checked dependencies; payment contract, capabilities, adapter, attempts/events and workflow. Prove success, failure, retry, exception normalization, mismatch rejection and duplicate confirmation without provider network calls. |
| 3 — Access integration | Idempotent FulfilmentService, individual entitlements, voluntary/90-day automatic activation, expiry, free-course immediate access and no checkout. Central access policy covers modules, assessments, existing assessment sessions, private media, Web/API/MCP paths. Progress and public academic history survive expiry. |
| 4 — Money lifecycle | Account Funds, full/mixed settlement, refunds/credit notes, immediate full-refund revocation, payouts/reservations, transfers behind the supplied gate, debt, disputes, reversals and reconciliation. No negative available funds or duplicate receipts/fulfilment. |
| 5 — Company credits | Purchase lots and units, batch purchase, provisional allocation, 24-hour consumption, 24-hour invitations, warning notices, reversal and conversion, LIFO refund valuation. Preserve consumed learner access after departure/company dispute. Keep the supplied expiry gate effective. |
| 6 — Gifts and learning products | Token + purchaser PIN, hashed secrets, claim throttling, correction/reissue; original gift activation deadline; extensions, reopenings, one purchased graded attempt and scoped review access; audited pause applications and expiry adjustments. |
| 6b — Academic history | Completion independent of passing, immutable completion certificates, all graded attempts including failures, exclusion of practice attempts, and one public learner-course verification identity. Preserve existing certificate links/snapshots. Address diagnostic auto-completion explicitly against the new required-assessments rule. |
| 7 — Administration | Complete purchaser/company/admin screens, private offers and discounts, manual EFT/PayShap confirmation, refunds, payouts, grants, debt waivers, deadline overrides, reconciliation, audit and manual review. Require actor/reason/evidence for financial overrides. |
| 8 — Acceptance | All supplied scenarios and workflow transitions, race-condition tests, new-commerce coverage target, complete existing QA, fresh-install and populated-database migration rehearsals, and browser checks across bundled themes. Update operations/handoff documentation and demonstrate Dummy-only end-to-end flows. |

The first usable milestone spans phases 1–3: choose an existing course offer, place an order/invoice, attempt Dummy payment, retry failure, confirm once, create exactly one entitlement, start learning and demonstrate automatic activation/expiry with a controlled clock.

## 7. Timed work and workflow reconciliation

Initially use a Symfony command, e.g. `commerce:process-deadlines`, scheduled by the existing Podman environment. This avoids requiring an additional queue service solely for clocks. Use injected `ClockInterface`, bounded batches, locks and idempotent transitions; schedule outbox dispatch/retries too. Calculate access from the actual deadline, not a delayed worker's execution time. Request-time checks enforce due access changes even if the worker is delayed.

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
