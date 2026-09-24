# Catto Learning Development Roadmap

## Current delivery position — 2026/09/24

The owner approved this sequence, with a review break after each step: (1) update stale documentation, (2) finish tester administration, (3) implement company credit purchasing, and (4) implement payment administration and refunds. The documentation correction and initial ADMIN grant workflow are included in the owner-authorized v0.8.6.1 release. Tester administration, company credit purchasing, and payment administration/refunds are included in the owner-authorized v0.8.7 release. Future scope, versions and Git publication require another explicit owner instruction.

Tester administration uses the course-first and person-first grant workflow with visible status and expiry, explicit revocation using the existing enrolment-removal rule, and an ADMIN-chosen invitation email. The course and person lists show the most recent 100 test grants; the complete enrolment administration remains available separately. Grants to draft courses and immediate access expiry remain the active contract in `COURSE-SPECIFICATION.md` section 18. Company administrators can buy exact course/access-period credits directly or purchase a missing credit while approving a learner request. Confirmed payment issues a historical company credit lot; request-linked simulated payment also allocates one credit, enrols the learner and queues notices. ADMIN payment administration now records exact full EFT evidence before fulfilment, preserves late payments for review, supports explicit release of safe paid orders and records refund decisions, credit notes and Account Funds credits. Full individual item refunds revoke access immediately while preserving learning history; company refunds use unused units and historical LIFO price. Account Funds spending/payouts, gifts and real-provider programmes remain separate later work unless the owner expands scope.

## Course Components release and commerce update

The owner-approved Course Components v2 redesign is released as v0.8.6. The disposable database was dropped and recreated from `20260906110000_create_v085_course_components_baseline.php`, then populated with the requested 100,000-record seed dataset. The runtime uses reusable Course Items, separate course placements, sections, tracked shortcode references and immutable Resources. Module/content-block/media/progress tables were removed. No production-data migration or compatibility layer is intended.

Read `COURSE-SPECIFICATION.md` section 18 for the current domain and interchange contract. ADMIN intent is authoritative: do not silently repair, rename, rewrite, substitute or cascade related authored content; unresolved draft shortcodes are permitted and block publication. Save changes a shared item; Save As creates independent item data while sharing its initial Resource file. Progress is assessment-only and grading remains fixed at 50/50. Course presentation is Core-owned and bypasses theme wrappers. Reports belong in workspace `cattolms/REPORTS/`, never the code tree.

**Current LMS version:** 0.8.7 **Date time:** 2026/09/24 SAST **Current stage:** The approved tester, company credit purchasing and payment administration/refund sequence is released in v0.8.7. `COMMERCE-IMPLEMENTATION-PLAN.md` records the implemented boundaries. Account Funds spending, payouts and live payment gateways are not implemented. 0.5.8.3 remains installed on the VPS and needs an update only when the owner asks for it.

Only the project owner decides future release numbers.

**Reading the stage records below.** Sections 1 to 2, and historical paragraphs in later sections, record decisions and implementation states at their original dates; their “next”, “pending” and REAL/SEED instructions are not current tasks. The REAL/SEED universe, `seed_token`, constraint triggers, `SEED_*` roles and All/Real/Seed control were removed in v0.7. The current state is the delivery order above, `PROJECT-INSTRUCTIONS.md` section 5 and the current-status sections of `COMMERCE-IMPLEMENTATION-PLAN.md`. Historical text remains for decision provenance only.

## Current UI design system

The v0.8 platform UI expansion migrates recurring structures across account, administration, company, catalogue, commerce and learning into the canonical component registry. The gallery and family/ownership contracts are part of the normal QA gate. Extend this system for future UI work; do not restore historical raw wrappers, flat component names or compatibility CSS. See [UI-COMPONENTS.md](UI-COMPONENTS.md). This does not change the remaining commerce roadmap.

The purpose is consistency: independently vibe-coded LLM views had allowed equivalent controls to drift in markup, spacing, responsive behavior, accessibility and progressive enhancement. New views must compose the registry and extend its contract tests only when a reusable job is not already represented.

The v0.8.5 canonical page construction also unifies shared shell, navigation, page-context, identity and footer vocabulary across all five bundled themes. Authentication changes the core navigation model, not shell geometry. Theme-specific art and the top-header/sidebar distinction remain. See `THEME-SDK.md` and `tests/Browser/README.md`.

## 1. v0.5.7.5.1 ACL foundation

The accepted ACL architecture is deliberately smaller than the first 0.5.7.5 design:

- one shared business capability catalogue; no mirrored `REAL.*` / `SEED.*` permissions;
- resource-first/action-last uppercase dot notation;
- `SYSTEM.*` reserved for ADMIN-only platform infrastructure;
- normal roles plus separate `SEED_*` role family for future generated identities;
- normal and seed roles may share business permissions but cannot be mixed on one identity;
- seed-only role profiles omit course import/export/media-management capabilities that do not belong in generated test workflows;
- REAL/SEED data visibility will be enforced by seed-aware queries/schema, not permission-key switching;
- differentiated Course Editor and Course Owner defaults;
- separate request/enrolment and publication-request/publish capabilities;
- explicit company-creation permission;
- resource scope based on relationships + permissions rather than ordinary role-name branches;
- API/MCP transport scope plus the same ordinary business permission used by Web.

### Commerce permission reservation

The following permissions are reserved now so Commerce does not require another ACL schema/naming pass later:

```text
COMMERCE.CART.VIEW
COMMERCE.CART.MANAGE
COMMERCE.CHECKOUT.START
COMMERCE.ORDER.VIEW
COMMERCE.PAYMENT.VIEW
COMPANY.ORDER.VIEW
COMPANY.PAYMENT.VIEW
PLATFORM.ORDER.VIEW
PLATFORM.ORDER.MANAGE
PLATFORM.PAYMENT.VIEW
PLATFORM.PAYMENT.MANAGE
PLATFORM.PAYMENT.RECONCILE
PLATFORM.REFUND.VIEW
PLATFORM.REFUND.MANAGE
```

They are inactive capability reservations until Commerce routes/services exist.

Run the complete PHP 8.5.9 VPS QA gate before treating the ACL refactor as accepted.

## 1b. v0.5.7.6 pagination, counts and bounded entity pickers — complete

Accepted before Seed Database on purpose: **make the UI tell the truth about large datasets before generating large datasets.**

- one shared `Pagination` contract and one core-owned pagination control for every standalone list;
- every paginated dataset reports a real total from a count query using the identical membership rule as its row query;
- consolidated `/admin` and `/company` render bounded previews with a "View all" link and no per-section aggregate query;
- Activity, Credits and the person profile use bounded htmx entity lookups instead of whole-table dropdowns;
- the Administration course list is scoped to the courses the actor may actually manage;
- no schema change, no migration and no database reset.

Historical styling deferral is superseded by the current platform component system; all bundled themes consume its canonical ACL tables and accordions.

## 1c. v0.5.8 Seed Database — implemented, pending acceptance

Administrator-controlled disposable test data, so the interface can be exercised at volume.

- 31 seed-aware tables carrying an indexed `seed_token`, plus two metadata tables;
- database-enforced universe isolation through constraint triggers on 27 tables;
- one REAL and one shared SEED System Company;
- generation of a coherent graph across 29 tables in a single transaction;
- universe-aware reads across every list, count, aggregate scalar, entity picker, direct lookup, anonymous surface and machine interface;
- selective cleanup with a collateral-impact preview;
- seed mail delivered to one configured inbox, with generated domains on the reserved `.seed.invalid` TLD;
- ordinary writes inheriting provenance from the resource rather than the actor, so a seed identity can use the LMS normally and a genuine `ADMIN` stays attributable for what it does to generated data;
- a visible genuine-`ADMIN` All / Real / Seed control on every Administration list family, with a `total · real · seed` record split and the selection carried through pagination and filters;
- REAL-only course portability (decision D5), refused at the service layer;
- PostgreSQL integration coverage: schema, generation, rollback, isolation, database guards, live-write provenance, cleanup with cross-set collateral, and portability.

Pending acceptance means the VPS migration, `composer qa` including Integration, and the browser and volume pass. No part of the module is outstanding as coding work. See `HANDOFF.md` section 9 and `tests-v0.5.8.md`.

## 2. Seed Database — the approved specification

Kept as the specification the implementation answers to, not as outstanding work. Section 1c records what was built; anything below that reads as an instruction is a requirement that has been met.

Two clarifications the implementation settled, which override the wording below wherever they differ:

- generated email addresses use a per-company domain on the reserved `.seed.invalid` suffix rather than `APP_DOMAIN`, with delivery re-routed to `SEED_SYSTEM_COMPANY_DOMAIN`. `.invalid` can never resolve, so a generated address cannot reach a real inbox even by accident;
- `seed_data` and `seed_data_tables` store **historical** counts only. Current counts are derived from the physical rows on demand (decision D2), because a stored figure can drift from reality and a derived one cannot.

### Seed-set metadata

- `seed_data`: one historical row per generated set, including UUIDv7 token, timestamp, description, requested soft volume, original/lifetime/current counts.
- `seed_data_tables`: per-set/per-table original/lifetime/current counts.
- A seed-set row remains as history even after its generated records are removed.
- Multiple seed sets may coexist. Tokens are provenance/cleanup identifiers, **not** security boundaries; all SEED data may interact with other SEED data.

### Generation

- Administration requests a numeric target record volume across all seeded tables; it is a soft limit and may be exceeded slightly to maintain referential integrity.
- Use bulk inserts and one transaction; rollback the complete set on failure.
- Generate realistic South African people, companies, course categories/courses, assessments, enrolments/progress/results, requests, credits, sessions, certificates and audit activity appropriate to the schema.
- Use `APP_DOMAIN` for generated email addresses; never hard-code a deployment domain.
- Seed creation sends **zero email**. A login email is sent only when an operator explicitly requests a normal passwordless login for a chosen seed account.
- Do not seed Themes, role definitions, permission definitions, ACL mappings, API tokens or fake course-media files.

### Isolation

REAL/SEED is a data-universe boundary independent of capability permissions:

- only tables that can hold seed data receive nullable indexed `seed_token` references;
- normal users and REAL business processes see only `seed_token IS NULL` data;
- seed identities see only `seed_token IS NOT NULL` data;
- genuine `ADMIN` may see both and explicitly select All / REAL / SEED in administrative views;
- REAL business records may reference only REAL records; SEED records may reference only SEED records;
- generated identities receive `SEED_STUDENT` by default plus additional `SEED_*` roles as required;
- actions performed by seeded identities create seed/test records;
- seed cleanup deletes only records belonging to the selected seed token and updates historical/per-table counts.

### Table UI

For paginated seedable datasets:

- ordinary normal users see only REAL counts/data and no seed disclosure;
- seed identities see only SEED counts/data and no REAL disclosure;
- genuine ADMIN sees total, REAL and SEED counts plus an All / REAL / SEED filter;
- counts respect other active filters and business-resource scope.

After implementation, exercise the LMS with representative seed sets before Commerce begins.

## 2b. Build order — owner decision, 2026/09/06

Commerce was section 3 and everything else read "after Commerce foundations". That ordering was wrong and this document contradicted itself on it: promotions are placed on "category, sub-category and sub-sub-category pages" that did not exist, similar-courses is defined in terms of categories and tags, and the sitemap depends on a catalogue the taxonomy had not yet shaped.

**The approved order is now:**

1. **Course taxonomy and discovery (3b)** — everything discovery- and commerce-related sits on it. Building checkout against a flat category list means reworking every catalogue and search query when the hierarchy lands.
2. **Company course lifecycle (3d)** — Commerce transacts against credits and entitlements. Getting those five overloaded concepts separated first means Commerce is built against a settled model.
3. **Favourites (3c)** — small, and wishlist-to-cart is a natural commerce path.
4. **Commerce (3).**
5. Promotions (3e), ratings (3f), SEO (9), analytics (9b).

This does not contradict the recorded decision in 9b. That decision is that *analytics* must not postpone Commerce, and it stands.

### Pricing visibility and email capture — owner decision, 2026/09/06

**Prices and the catalogue are public. The cart works anonymously. The email address is captured at the first checkout step, not at the first price view.**

Considered and rejected: requiring sign-in before a price is shown, in order to capture an address for marketing. It captures addresses only from people who were already committed and loses the much larger group still deciding. Three specific reasons it was rejected:

- it fights section 9. A price behind a login is invisible to search engines and disqualifies the catalogue from product rich results, so organic discovery would be built and blocked in the same project;
- company administrators specifying training research anonymously, get approval, then buy. The wall blocks the research phase, which is when the decision is actually made;
- sign-in is a magic link. Putting an email round trip between a visitor and a price is a heavier interruption than a password form would be.

An address entered at checkout is a better lead than an anonymous browser, and abandonment from that point is recoverable. **Company and member pricing may require sign-in** — "sign in to see your company's price" makes the login worth something instead of a toll gate.

**This constrains the Commerce data model and must be settled before section 3 is built, not during it.** An anonymous cart needs an identity that is not a user id, plus a defined merge into the user's cart at sign-in. A login-first cart would simply be user-scoped. The two produce different tables.

---

## 2c. How a company pays for training — owner decision, 2026/09/07

The rules, in the owner's own terms:

- **A company that owns a course trains its own staff on it for free.** Ownership is the entitlement; no credit is involved and none should be looked for.
- **A company that does not own a course must hold a credit for it** before its staff can be enrolled.
- Two workflows reach an enrolment, and both are legitimate:
  1. the staff member requests the course, and the company administrator buys what is needed and approves, or rejects;
  2. the administrator buys credits and assigns courses to staff directly, telling them outside the LMS.

### The three decisions taken with it

**Approving a request the company cannot yet pay for takes the administrator into checkout.** Not "approve and wait", and not "you must buy this first, come back later". Approve is one action that ends in the staff member being enrolled: pay on the way through, and the enrolment happens on the way back. The rejected alternatives both leave a request in a state that means "yes, but nothing happened", which is the state the current code already produces by accident and which nobody watching the screen can tell apart from a failure.

**Enrolment always emails the learner**, whether it came from an approved request or from an administrator assigning directly, with the course name and a link to start it. The owner may tell staff in person as well; the platform does not rely on that having happened.

**An individual buying for themselves is enrolled on payment.** No credit is created. A credit is a company mechanism for buying access it will hand to someone else later; a person buying their own course has nobody to hand it to, and giving them a balance to understand would be machinery for its own sake.

### Current implementation gap, measured against those rules

The owned-course rule, direct assignment of an existing exact-match credit and `/company/credits/buy` purchase checkout are included in v0.8.7. A request that lacks the necessary credit remains pending while the company administrator buys the exact-match credit; confirmed simulated payment then allocates it, enrols the learner and queues notices. Direct company purchases may use EFT, but credits are not issued until ADMIN confirms bank settlement with independent evidence; request-linked purchases require immediate simulated card payment in this phase. Never restore the old state in which approval appears successful without enrolment.

---

## 2d. Money, tax and locale — owner decision, 2026/09/07

**Launch is South Africa, in rands.** Everything below follows from that plus the intention to launch elsewhere later, so nothing is allowed to hardcode the first country.

### Currency

- Prices are stored in **minor units** with an ISO 4217 code beside them. `course_price_variants` already does this: `price_minor_units BIGINT` and `currency_code CHAR(3) DEFAULT 'ZAR'`. Integer cents rather than a decimal, because a payment processor takes cents and floating-point money is a class of bug rather than a rounding preference.
- The platform's currency and country are **`.env` settings**, not constants. An ISO 4217 code and an ISO 3166 country code.
- **No currency symbol in `.env`, and no currency table.** The `intl` extension formats an amount from the ISO code and a locale, and gets the things a hand-kept symbol gets wrong: where the symbol sits, which character separates thousands, which separates decimals. `R 1 234,56` in South Africa, `£1,234.56` in the United Kingdom, `1.234,56 €` in Germany - one call, no table to maintain and no second country to get wrong. `intl` was added to the PHP image on 2026/09/07.

### Country

`.env` carries the country code. **No country table yet.** One is worth building when something actually reads an attribute from it - a dialling code, a tax rule, an address format - and today nothing does. Adding it now would be a table with one row and no reader.

### VAT — deferred, and the shape recorded

Not built. The owner is not VAT registered and will register on turnover. Recorded now so the decision is not re-taken under pressure later:

- **The database stores the price excluding VAT.** That is the number the owner sets and keeps.
- **The interface calculates VAT and displays the inclusive price.** A customer sees one number, and it is the number they pay.
- **The rate is configurable, never hardcoded.** It changes by statute and by country.
- **A tax invoice must be issuable** once registered, which means the VAT amount is stored per order line rather than recomputed later from a rate that may since have changed.

The last point is the one that reaches into Commerce before VAT exists: order lines carry a VAT amount from the start, set to zero until registration. Storing zero costs nothing; adding the column afterwards means every historical order has no VAT figure and no honest way to produce one.

### Free courses do not go through the cart

A course priced at zero is not a purchase. The learner enrols directly and the access period starts **at enrolment**, not when they first open the course. The cart holds priced items only - a cart containing something free is a checkout that can total zero, and a payment of zero is a state the processor, the order and the refund path all have to special-case for no benefit.

The period comes from the zero-priced variant where one exists, and from the course default otherwise.

### Refunds revoke access immediately

A refunded order removes the learner's access at once. Not at period end, and not left in place.

### Who may buy

Both, and they are separate paths:

- **An individual** buys their own course from the public catalogue and is enrolled on payment. No company, no credit - there is nobody to hand it to later.
- **Inside a company, only an administrator buys.** Staff favourite courses and request access; they cannot spend the company's money.

---

## 9c. Multiple languages — not started, not scheduled

The owner wants multi-language support. It is recorded here rather than built because it is two different problems that are routinely mistaken for one:

- **Interface translation.** Every label, button, validation message and email the platform emits. Mechanical, large, and bounded - it ends when the strings are extracted and translated.
- **Content translation.** Course titles, modules, assessments, categories, tags and certificates. Not mechanical at all: it is a schema question (a translations table, or a language column on every content row), an authoring question (who translates a course and how a partial translation behaves), and a discovery question (does a Portuguese learner see an English course at all).

Doing the first is worth little on its own if the catalogue stays in one language, and doing the second changes the shape of the course schema. Neither should be started inside another phase.

---

## 3. Commerce — individual foundation built; company and administration work remains

The individual foundation is implemented: guest and signed-in carts, staged checkout, Dummy card simulations, manual EFT instructions, persisted orders and invoices, payment attempts, idempotent fulfilment, free-course access and access deadlines. Local subsequent work adds company credit purchasing, ADMIN bank evidence confirmation, manual-review reconciliation and refund/credit-note/Account Funds records. `src/Commerce/` and its integration tests are the implementation source. The bullets below are the original broader domain target, not a claim that none of Commerce exists. Further commerce work requires a scope decision after owner review.

Implement one coherent commerce domain:

- cart and cart items;
- orders/order lines with immutable price/access snapshots;
- payment attempts/status/history with idempotency;
- simulated processor first: success, failure, pending, cancellation, retry and replay;
- successful payment creates exactly the intended entitlement/enrolment;
- failed payment creates none;
- company approval consumes an exact unused matching course/access-period credit first, otherwise creates the appropriate payable flow;
- Administration order/payment/refund/reconciliation views and commerce audit events;
- extend Seed Database to generate representative commerce transactions after the real Commerce model exists;
- gateway abstraction; PayFast only after simulator acceptance.

The reserved ACL keys in section 1 should be reused rather than renamed or duplicated.

## 3b. Course taxonomy and discovery

**Current public architecture:** the platform component library owns the persistent Tier 1 grid, flat Tier 2/Tier 3 workspace, complete tag browser and shared 24-card course results. Ordinary category browsing uses direct membership; keyword search includes descendants. The older stage records below describe how repository filtering developed, not a requirement for recursive public UI. `ux-ui-rules.md` sections 1.2a and 8 are the current implementation contract.

Built: `course_categories` carries `parent_id` and `level` with a three-level cap enforced by a CHECK constraint and a trigger, unique `name` and `slug`, and `ON DELETE RESTRICT` on the parent so nobody removes three levels of taxonomy by accident. `tags` and `course_tags` exist, with the slug as the canonical key so casing and spacing cannot produce three versions of one tag. Both are universe-free labels — see PROJECT-INSTRUCTIONS section 5.

Built since, 2026/09/06: **browsing by category.** `/courses/category/<slug>` is the same catalogue body told which branch it is showing. The descendant query is one predicate — self, children, grandchildren — shared by taxonomy rollup, browse count and browse rows, so a faceted count cannot disagree with its own list. The breadcrumb is a recursive ancestry walk bounded by the depth cap, and each child category offers the count of the whole branch behind it. Only active categories are addressable. The original universe-scoped count rules and their REAL/SEED test were removed in v0.7; current reads use one data population.

Built since, 2026/09/07: **tag management, tag pages and keyword search.** Administration → Courses → Course Tags is a searched, paginated list on the three shared controls, guarded by its own `COURSE.TAG.MANAGE`. The slug is derived from the name rather than typed, so casing and spacing cannot make three tags out of one, and a clash is refused rather than silently suffixed. Deleting a tag needs no replacement, unlike a category: a course with one fewer tag is still classified, a course with no category is not. `/courses/tag/<slug>` browses it, tag chips appear on every card, and the catalogue's client-side box that filtered rendered rows was replaced by the shared server-side search over title, subtitle and summary.

Category, tag and keyword are one `CatalogueFilter` handed to both the count and the rows, so a facet cannot reach one and miss the other, and combining them means the intersection. The tag predicate is an EXISTS rather than a join, because a course carries several tags and a join would return it once per tag.

Built since, 2026/09/07: **faceted search.** Every option in the category and tag rails carries the number of courses choosing it would return, and tags multi-select: several tags mean *any* of them, because intersecting tags collapses to nothing almost immediately and a facet whose options can only narrow gives a reader no way back.

The rule that makes it work: **a facet's own counts are taken with that facet relaxed.** Apply the tag facet to its own counts and choosing one tag drives every other tag to zero — no course carries a tag it does not carry — and the rail becomes a single live option and a dead end. Counted with the tag facet dropped but the category and keyword still applied, each figure means "how many more this would add". `CatalogueBrowseIntegrationTest` asserts both the relaxed figure and what the applied one would have done.

Built since, 2026/09/07: **the distribution charts and the public tag index.** Both taxonomy administration screens open with a bar chart of where the published catalogue actually is — per top-level branch, and per most-used tag — each naming its remainder rather than leaving it implied. The uncategorised and untagged rows are the point of those charts as much as the bars are: a course with no category is invisible to every browse path there is, and nothing else on the platform says how many exist. `/courses/tags` lists every active label weighted in five steps.

No charting library. A bar is a div with a width, the figures are text beside it, and the whole thing renders server-side with no script — which also makes it legible to a screen reader, which a canvas is not.

**Section 3b is complete.** The remaining discovery work belongs to later sections: search ranking and SEO (9), and analytics (9b).

### The built-in taxonomy needs to be designed, not generated — owner decision, 2026/09/06

v0.6 ships 369 categories: 16 top-level, 77 sub and 276 sub-sub. **That is too many, and they were invented to demonstrate the hierarchy rather than chosen to organise a catalogue.** They are a placeholder.

The owner will map out the categories and the structure the catalogue actually needs. Until then:

- do not extend the built-in set, and do not treat its shape as a decision that has been made;
- the schema, the three-level cap, the unique names and the management screens are settled and are not what needs revisiting;
- replacing the list is a data change, not a schema change — the baseline seeds it and nothing in the code depends on any particular category existing.

The practical consequence today is that the categories screen renders 369 rows in one unpaginated table. Pagination is the wrong answer for a tree, because a page beginning mid-branch with no parent in sight is worse than a long page; collapsible branches or filtering to one branch at a time are the right ones. Both are cheaper to build once the real taxonomy is known and its actual size is settled.

### Category hierarchy

**Exactly three levels, no more:**

```text
Category
  └── Sub-category
        └── Sub-sub-category
```

A course belongs to **one category path** and may be attached at level 1, 2 or 3.

Ordinary browsing lists direct courses at the active category. A keyword search scoped to `Technology` includes `Technology > Linux > Linux Administration`; Tier 3 is the narrowest scope. Do not implement unlimited depth in the first version — the depth cap is what keeps the descendant query bounded and the breadcrumb honest.

A draft may temporarily have no category. Publication should eventually require a valid active one.

### Tags

A course may carry **many tags**. Tags are cross-cutting classification, deliberately *not* a second hierarchy — that distinction is the whole reason both exist.

A relational tag model is required, and the design must decide: canonical names, normalisation, duplicate and synonym handling, administrative tag management, and an efficient course/tag lookup that survives seed volumes.

### Search and browsing

Search must cover at least title, subtitle, summary/description and tags, with combined filtering by keyword, category, tag, plus pagination and sorting.

Counts and row queries must use identical filter semantics — the v0.5.7.6 rule, which matters more here than anywhere else because a faceted count that disagrees with its list is indistinguishable from missing data.

---

## 3c. Favourites — implemented before Commerce

Learner and company favourites are implemented; the bullets below record their accepted purpose and behavior. Favourites do not grant course access.

**Terminology.** *Favourites* is the wishlist. *Library* and *Learning* mean courses the learner actually has access to. The two must not be conflated, and `Library` must not be reused as a wishlist name.

### Learner favourites

- empty heart = not favourited, filled = favourited;
- toggle from catalogue and search result lists;
- toggle from course detail;
- a dedicated paginated Favourites list, with removal directly from it;
- ordinary account and course scope rules throughout; the former REAL/SEED split no longer exists.

### Company favourites

A company needs the same concept: courses it may want for staff later but has not acquired.

This is a **company-to-course** relationship in its own right, not a user favourite belonging to whoever happens to administer the company. Modelling it as a user favourite would lose the company the moment that person changed.

---

## 3d. Company course lifecycle — the split is built

**The five-section split is done.** What is still owed is company credit purchasing and its integration with request approval.

| Section | Route | What it holds |
|---|---|---|
| Company Courses | `/company/courses` | Courses the company owns and offers. Free for its own staff. |
| Training Courses | `/company/training` | Courses it has bought access to, with seats bought, seats free, staff enrolled and staff completed. |
| Favourites | `/company/favourites` | Courses it may want for staff and has not bought. |
| Enrolments | `/company/enrolments` | Individual staff access and progress. |
| Course Credits | `/company/credits` | The entitlement ledger and its accounting. |

`COMPANY_OWNED_WHERE` and `COMPANY_TRAINING_WHERE` are two constants and never recombined; one resolver picks between them and is shared by the rows and the count. The old single predicate flat- tened a course a company both owned and had bought into one row, so the two figures could not even be recovered by subtraction.

`company_favourites` is a company-to-course table. It is not a user favourite: staff favouriting a course for themselves changes nothing here, and the company's interest outlives whoever added it — owner decision, 2026/09/07. The former cross-universe trigger and `seed_token` behavior were removed in v0.7.

### Still outstanding

- Buying credits, which is Commerce.

Assigning an existing credit to a staff member was built on 2026/09/07: the form is on `/company/enrolments`, the rules are the same two that approval uses, and the pickers are a company-scoped lookup rather than the platform-wide one.

### The original statement of the problem, kept for the record

Five distinct concepts were overloaded onto one Courses page.

| Concept | Meaning |
|---|---|
| **Company Courses** | Courses the company created/owns and offers or sells through the LMS. The provider view. **Not** courses its staff happen to be enrolled in, and not favourites |
| **Favourites** | Saved wishlist / possible future training purchase |
| **Training Courses** | Courses the company has acquired access or credits for, so staff may be trained now or later. A course-level entitlement view, **not** a list of individual enrolments |
| **Learning** | Individual staff enrolments, progress and results |
| **Course Credits** | Purchased, available and allocated entitlement, and its accounting |

Training Courses will likely want per-course metrics: credits purchased, credits available, credits allocated, staff enrolled, staff completed.

**Defect fixed in 0.5.8.3 Stage D, recorded here for history.** Company Courses showed `allCourses()` in platform-wide mode and `publishedCourses()` otherwise — every published course on the platform, which is none of the five meanings above. `CourseRepository::companyCourses()` now shares one `COMPANY_COURSES_WHERE` with its count. The information-architecture work above is still outstanding; only the wrong query was corrected.

---

## 3e. Promotions, recommendations and personalisation — after Commerce

Planning only.

### Promoted, featured and sponsored courses

Placements: homepage, category, sub-category and sub-sub-category pages.

The model must distinguish **editorially featured** from **promoted** from **sponsored/paid placement** — they carry different commercial and disclosure obligations, and collapsing them into one `is_featured` boolean forecloses that distinction permanently. Plan for course, placement, optional category context, priority, start/end time, promotion type and active state.

### Similar courses

Course detail pages should show related courses. Deterministic signals are enough for the first implementation: same category or sub-category, overlapping tags, broader parent category, excluding the current course. **No machine learning is required.**

### Learner interests

Onboarding should eventually ask about interests and map them onto the same taxonomy and tags. This follows the category/tag system rather than preceding it.

---

## 3f. Ratings, reviews and testimonials — after Commerce

Planning only.

- **Ratings** — structured, likely 1–5, tied to a legitimate learner/course relationship.
- **Reviews** — written feedback with moderation states `pending`, `approved`, `rejected`, `hidden`. **Nothing is published automatically.**
- **Public display** — average rating, rating distribution, approved reviews.
- **Curated testimonials** — selected approved feedback explicitly promoted for marketing use, with deliberate curation and attribution controls. A review is not a testimonial by default; treating every approved comment as marketing copy is both a consent problem and a quality problem.

---

## 3g. Industry types for companies — owner decision, 2026/09/09

Not started. Requested so that a company can be classified by what it does, which is the missing axis in every question anyone will eventually ask of the platform: which industries buy which courses, which industries a course sells into, and which industries are under-served by the catalogue.

**Shape.** A company carries an industry. One, not many - a company that genuinely spans two is rare enough to be a data-entry decision rather than a schema one, and a many-to-many here would make every report ambiguous about what it is counting.

**The list is curated, not free text.** A free-text field produces "Mining", "mining" and "Mining & Quarrying" as three industries inside a month, and no report can recover from that. It is a lookup table the platform ships and an administrator maintains, in the same way course categories are.

**Open, and for the owner to decide when this is scheduled:**

- whether the list follows a published standard - ISIC, NACE, SIC, or South Africa's own SIC 7 - or is written for this platform. A standard is defensible and immediately comparable with outside data; a hand-written list is shorter and reads better in a form. The choice constrains the reporting later, so it is worth making deliberately rather than by default.
- whether an industry is required on a company or optional. Required is better data and worse onboarding; optional produces a reporting bucket called "unspecified" that never empties.
- whether it is one level or two - Mining, then Coal, Gold, Platinum - which matters more for reporting than for the form.

An industry would classify a company the way a category classifies a course: it is shared vocabulary, not a second data universe. The old seed-token and reader-universe rules in the original proposal were removed in v0.7.

---

## 3h. Symfony UX tag-cloud trial — implemented

The `/courses/tags` trial is implemented: a Stimulus controller enhances the server-rendered tag browser with TagCloud.js, while normal links remain available without JavaScript. The current component and progressive-enhancement contracts are recorded in `ux-ui-rules.md` section 8.

The page was deliberately chosen because it is public, outside critical paths and has a real interaction to judge.

The criteria used to judge the trial were:

- does a Stimulus controller stay inside the platform's rules? The page must still render server-side first and still work with JavaScript off, because that is not negotiable for a public catalogue page and it is what tells us whether UX fits this codebase or fights it;
- what does it cost to ship? AssetMapper carries no npm toolchain, which is the whole reason it is the chosen path - a build step would be a second thing to keep working on the VPS;
- can a theme still restyle it? A Stimulus controller that hard-codes its own appearance would take the page out of the theme layer, and no amount of interactivity is worth that.

Later UX enhancements remain subject to the canonical component system and the owner-approved work order above.

## 4. Course/media portability

Structured JSON 2.0 course import/export and trusted standalone HTML import are implemented. Local files belong to the immutable Resource Library in private instance storage; Course Items reference Resources and the Core delivery route checks access before returning a file. Export describes Resource identities but does not bundle physical files. Authored SVG, HTML, CSS and embedded code are preserved. See `COURSE-SPECIFICATION.md` sections 1.1 and 18.

Future portability/media work, outside the approved immediate sequence, includes a self-contained package if separately specified, protected-file delivery optimisations such as Nginx X-Accel-Redirect where useful, fuller caption/transcript and range-request acceptance, and formal course-access terms before commercial launch. Do not replace the current Resource Library with the obsolete `course_media` model.

## 5. Learner identity and communication

Later additions:

- mandatory mobile number at the chosen rollout point;
- SMS verification and verified-mobile status/history;
- SMS provider abstraction;
- opt-in WhatsApp, Telegram and Signal communication channels with consent/preferences.

## 6. Social linking, sharing and referrals

Allow optional learner linking of Facebook, Instagram, Google, X, Threads and TikTok where provider APIs permit it.

Support voluntary marketing actions:

- share a course;
- invite a friend to view a course;
- share course-start/progress/completion milestones;
- share achievements/certificate announcements;
- referral/attribution tracking for traffic and conversions generated by learner sharing.

Social posting must remain voluntary and must not gate course completion.

## 7. Certificates

Add learner-facing PDF certificate download with stable certificate identity, revocation awareness and appropriate print/download presentation.

## 8. Company embedding / white-label delivery

Implement an Ecwid-style JavaScript embed for a company's domain. The embedded experience is company-scoped: company administrators manage only their company, learners, courses/credits/requests and approved catalogue content. Companies may expose selected general-catalogue courses alongside their own courses; their own learners may receive company-created courses without payment where configured. ADMIN retains platform-wide override. Consider Shadow DOM or an equivalent presentation boundary.

## 9. Search-engine discoverability

Late-project work, once the public catalogue is stable. `public_html/robots.txt` already exists and deliberately carries no `Sitemap:` line, because pointing at a sitemap that does not exist produces exactly the 404 the file was added to remove.

- `GET /sitemap.xml`, generated rather than a checked-in file: published courses go in and out  of the catalogue, and a static file goes stale silently.
- Bounded like every other query. Cap at the 50,000-URL sitemap limit and emit a sitemap index beyond it; do not reintroduce an unbounded catalogue query.
- Use a narrow slug/`updated_at` projection rather than `publishedCourses()`, which selects far more per row than a sitemap needs.
- Absolute URLs from `APP_URL`; `<lastmod>` from `courses.updated_at`.
- Cache the rendered document. It does not need to be live.
- Include only published courses that are publicly addressable; the retired REAL/SEED split supplies no sitemap filter.
- Add the `Sitemap:` line to `robots.txt` in the same change, not before.

## 9b. Analytics — after Commerce, and never ahead of it

Planning only. The technology choice is deliberately unresolved.

**Owner decision, recorded so it cannot drift:**

> Commerce is crucial. Analytics is useful but not blocking.
> **Analytics must not postpone Commerce.**

Analytics can be retrofitted; a missing commerce model cannot be worked around. Do not redesign or delay Commerce around analytics instrumentation, and do not make analytics a prerequisite for it.

Eventual coverage: page views, approximate time on page, navigation paths, acquisition/referrer, course searches, category and tag browsing, course views, favourite activity, promotion impressions and clicks, cart creation, cart additions and removals, checkout progression, checkout abandonment, purchases and conversions, and learner engagement.

**Undecided on purpose** — first-party, Google Analytics, another third-party platform, a PHP library, or a hybrid. Deciding now would constrain Commerce for no benefit.

---

## 10. Production readiness

Before production is declared:

- production backup/restore and non-destructive migration policy;
- payment/webhook hardening;
- queue/worker decisions where justified;
- monitoring/alerting;
- large-volume seed load testing;
- SQL/index/N+1 review;
- PHP 8.5.x modernisation/performance audit;
- accessibility and browser/mobile acceptance;
- privacy/retention policy appropriate to real user data.

---

## Deferred — Course-item revisions

**Status:** Post-launch candidate. Do not implement during the initial Course Components work.

Owner decisions retained for later:

- Revisions apply to **Course Items only**. Courses do not have revisions or versions.
- A revision is a complete snapshot of a Course Item.
- Record created date/time, last-updated date/time and editable descriptive notes.
- Revisions remain editable; they are not immutable.
- No auto-save. An editor may leave without saving.
- An existing revision may be edited in place or used as the source for a new revision.
- A course editor may inspect/preview revisions and choose which revision a particular course uses.
- Different courses may use different revisions of the same shared Course Item.
- Revision statuses are ADMIN-managed data, not a fixed hard-coded workflow.
- `deprecated` means old/out of date; it does not automatically delete or lock the revision.
- Status may be blank.
- No automatic cleanup/deletion.
- ADMIN may permanently delete a revision only when no course currently uses it.
- A future comparison tool should allow two revisions to be selected and differences displayed.
- Revisions are entirely internal. Learners/public visitors do not see revision identifiers, dates, statuses, notes or revision concepts.
- Updating an existing Course Item changes it for every course using that same Course Item. Where isolation is required under the initial architecture, ADMIN uses Save As to create an independent Course Item.
- Updating an existing course changes that course for current learners. To preserve an old offering, duplicate the course and edit the duplicate as a separate course.
- No tester-specific revision workflow is defined.

Do not introduce speculative revision schema/workflows now merely to prepare for this feature.

## Deferred — Forums as Course Items

Forums/forum discussion Course Items are not part of the initial Course Components implementation.

The architecture may later gain a forum/discussion Course Item when the forum feature itself is designed and implemented. Do not create placeholder forum workflows now.

## Deferred / undecided — SCORM

SCORM was discussed as a possible future Course Item type but has **not** been adopted as an implementation requirement. Do not implement it unless separately approved and specified.

## Deferred — Resource Library refinements

The initial Resource Library is intentionally simple: one directory, immutable file resources, database records, unique filenames/titles, Resource types, search/filtering, usage inspection and reference-safe deletion.

Folders, tags, richer digital-asset management and elaborate large-file handling should be driven by demonstrated need rather than implemented speculatively.
