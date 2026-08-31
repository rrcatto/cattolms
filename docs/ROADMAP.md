# Catto Learning Development Roadmap

**Current LMS version:** 0.5.8.3  
**Date time:** 2026/08/25 14:29 SAST  
**Current stage:** v0.5.8.2 accepted and tagged 2026/08/25. v0.5.8.3 adds Stage C (editable SEED System Company Settings) and Stage D (Platform ADMIN selected-company context), awaiting VPS verification and browser acceptance; then volume acceptance; Commerce next

Only the project owner decides future release numbers.

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

Deferred from this stage: Gilded Noir has no `.acl-*` rules and no `.universe-switch` rules, so the Roles and ACL editor and the data-universe control both fall back to core CSS in the dark skin. Core styles each one completely, including its active state and focus ring, so neither depends on a theme update to work. Enhancing the theme needs a version number from the project owner.

## 1c. v0.5.8 Seed Database — implemented, pending acceptance

Administrator-controlled disposable test data, so the interface can be exercised at volume.

- 31 seed-aware tables carrying an indexed `seed_token`, plus two metadata tables;
- database-enforced universe isolation through constraint triggers on 27 tables;
- one REAL and one shared SEED System Company;
- generation of a coherent graph across 29 tables in a single transaction;
- universe-aware reads across every list, count, aggregate scalar, entity picker, direct lookup,
  anonymous surface and machine interface;
- selective cleanup with a collateral-impact preview;
- seed mail delivered to one configured inbox, with generated domains on the reserved
  `.seed.invalid` TLD;
- ordinary writes inheriting provenance from the resource rather than the actor, so a seed
  identity can use the LMS normally and a genuine `ADMIN` stays attributable for what it does to
  generated data;
- a visible genuine-`ADMIN` All / Real / Seed control on every Administration list family, with a
  `total · real · seed` record split and the selection carried through pagination and filters;
- REAL-only course portability (decision D5), refused at the service layer;
- PostgreSQL integration coverage: schema, generation, rollback, isolation, database guards,
  live-write provenance, cleanup with cross-set collateral, and portability.

Pending acceptance means the VPS migration, `composer qa` including Integration, and the browser
and volume pass. No part of the module is outstanding as coding work. See `HANDOFF.md` section 9
and `tests-v0.5.8.md`.

## 2. Seed Database — the approved specification

Kept as the specification the implementation answers to, not as outstanding work. Section 1c
records what was built; anything below that reads as an instruction is a requirement that has
been met.

Two clarifications the implementation settled, which override the wording below wherever they
differ:

- generated email addresses use a per-company domain on the reserved `.seed.invalid` suffix rather
  than `APP_DOMAIN`, with delivery re-routed to `SEED_SYSTEM_COMPANY_DOMAIN`. `.invalid` can never
  resolve, so a generated address cannot reach a real inbox even by accident;
- `seed_data` and `seed_data_tables` store **historical** counts only. Current counts are derived
  from the physical rows on demand (decision D2), because a stored figure can drift from reality
  and a derived one cannot.

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

## 3. Commerce — after Seed Database acceptance

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

## 3b. Course taxonomy and discovery — after Commerce foundations

Planning only. Nothing in this section is implemented, and none of it may be built without its own
work order.

### Category hierarchy

**Exactly three levels, no more:**

```text
Category
  └── Sub-category
        └── Sub-sub-category
```

A course belongs to **one category path** and may be attached at level 1, 2 or 3.

Browsing a level includes every descendant. Given `Technology > Linux > Linux Administration`,
browsing `Technology` returns courses in all three; browsing `Technology > Linux` narrows it; level
3 is the narrowest scope. Do not implement unlimited depth in the first version — the depth cap is
what keeps the descendant query bounded and the breadcrumb honest.

A draft may temporarily have no category. Publication should eventually require a valid active one.

### Tags

A course may carry **many tags**. Tags are cross-cutting classification, deliberately *not* a
second hierarchy — that distinction is the whole reason both exist.

A relational tag model is required, and the design must decide: canonical names, normalisation,
duplicate and synonym handling, administrative tag management, and an efficient course/tag lookup
that survives seed volumes.

### Search and browsing

Search must cover at least title, subtitle, summary/description and tags, with combined filtering
by keyword, category, tag, plus pagination and sorting.

Counts and row queries must use identical filter semantics — the v0.5.7.6 rule, which matters more
here than anywhere else because a faceted count that disagrees with its list is indistinguishable
from missing data.

---

## 3c. Favourites — after Commerce foundations

Planning only.

**Terminology.** *Favourites* is the wishlist. *Library* and *Learning* mean courses the learner
actually has access to. The two must not be conflated, and `Library` must not be reused as a
wishlist name.

### Learner favourites

- empty heart = not favourited, filled = favourited;
- toggle from catalogue and search result lists;
- toggle from course detail;
- a dedicated paginated Favourites list, with removal directly from it;
- ordinary REAL/SEED isolation throughout.

### Company favourites

A company needs the same concept: courses it may want for staff later but has not acquired.

This is a **company-to-course** relationship in its own right, not a user favourite belonging to
whoever happens to administer the company. Modelling it as a user favourite would lose the company
the moment that person changed.

---

## 3d. Company course lifecycle — after Commerce foundations

Planning only. Five distinct concepts, currently overloaded onto one Courses page. The future
Company information architecture must separate them.

| Concept | Meaning |
|---|---|
| **Company Courses** | Courses the company created/owns and offers or sells through the LMS. The provider view. **Not** courses its staff happen to be enrolled in, and not favourites |
| **Favourites** | Saved wishlist / possible future training purchase |
| **Training Courses** | Courses the company has acquired access or credits for, so staff may be trained now or later. A course-level entitlement view, **not** a list of individual enrolments |
| **Learning** | Individual staff enrolments, progress and results |
| **Course Credits** | Purchased, available and allocated entitlement, and its accounting |

Training Courses will likely want per-course metrics: credits purchased, credits available, credits
allocated, staff enrolled, staff completed.

**Known defect this section will fix.** The current Company Courses section shows `allCourses()` in
platform-wide mode and `publishedCourses()` otherwise — every published course on the platform,
which is none of the five meanings above. It is recorded here rather than patched in isolation
because the correct query depends on which of these concepts the page is meant to show.

---

## 3e. Promotions, recommendations and personalisation — after Commerce

Planning only.

### Promoted, featured and sponsored courses

Placements: homepage, category, sub-category and sub-sub-category pages.

The model must distinguish **editorially featured** from **promoted** from **sponsored/paid
placement** — they carry different commercial and disclosure obligations, and collapsing them into
one `is_featured` boolean forecloses that distinction permanently. Plan for course, placement,
optional category context, priority, start/end time, promotion type and active state.

### Similar courses

Course detail pages should show related courses. Deterministic signals are enough for the first
implementation: same category or sub-category, overlapping tags, broader parent category, excluding
the current course. **No machine learning is required.**

### Learner interests

Onboarding should eventually ask about interests and map them onto the same taxonomy and tags.
This follows the category/tag system rather than preceding it.

---

## 3f. Ratings, reviews and testimonials — after Commerce

Planning only.

- **Ratings** — structured, likely 1–5, tied to a legitimate learner/course relationship.
- **Reviews** — written feedback with moderation states `pending`, `approved`, `rejected`,
  `hidden`. **Nothing is published automatically.**
- **Public display** — average rating, rating distribution, approved reviews.
- **Curated testimonials** — selected approved feedback explicitly promoted for marketing use, with
  deliberate curation and attribution controls. A review is not a testimonial by default; treating
  every approved comment as marketing copy is both a consent problem and a quality problem.

---

## 4. Course/media portability

- canonical `.clcourse` import/export package;
- private filesystem media metadata;
- audio narration and inline audio/video blocks;
- Nginx X-Accel-Redirect for protected media;
- MP3/MP4 first, transcripts/captions and range requests;
- enable SVG course media only after an approved sanitisation policy/library is selected;
- formal course-access terms/acceptance before commercial launch.

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

Late-project work, once the public catalogue is stable. `public_html/robots.txt` already exists
and deliberately carries no `Sitemap:` line, because pointing at a sitemap that does not exist
produces exactly the 404 the file was added to remove.

- `GET /sitemap.xml`, generated rather than a checked-in file: published courses go in and out
  of the catalogue, and a static file goes stale silently.
- Bounded like every other query. Cap at the 50,000-URL sitemap limit and emit a sitemap index
  beyond it; do not reintroduce an unbounded catalogue query.
- Use a narrow slug/`updated_at` projection rather than `publishedCourses()`, which selects far
  more per row than a sitemap needs.
- Absolute URLs from `APP_URL`; `<lastmod>` from `courses.updated_at`.
- Cache the rendered document. It does not need to be live.
- REAL-only. A SEED course appearing in the sitemap would be the most damaging form of universe
  leak, so this must respect the Seed Database scope rules.
- Add the `Sitemap:` line to `robots.txt` in the same change, not before.

## 9b. Analytics — after Commerce, and never ahead of it

Planning only. The technology choice is deliberately unresolved.

**Owner decision, recorded so it cannot drift:**

> Commerce is crucial. Analytics is useful but not blocking.
> **Analytics must not postpone Commerce.**

Analytics can be retrofitted; a missing commerce model cannot be worked around. Do not redesign or
delay Commerce around analytics instrumentation, and do not make analytics a prerequisite for it.

Eventual coverage: page views, approximate time on page, navigation paths, acquisition/referrer,
course searches, category and tag browsing, course views, favourite activity, promotion impressions
and clicks, cart creation, cart additions and removals, checkout progression, checkout abandonment,
purchases and conversions, and learner engagement.

**Undecided on purpose** — first-party, Google Analytics, another third-party platform, a PHP
library, or a hybrid. Deciding now would constrain Commerce for no benefit.

---

## 10. Production readiness

Before production is declared:

- production backup/restore and non-destructive migration policy;
- payment/webhook hardening;
- queue/worker decisions where justified;
- monitoring/alerting;
- large-volume seed load testing;
- SQL/index/N+1 review;
- PHP 8.5.9 modernisation/performance audit;
- accessibility and browser/mobile acceptance;
- privacy/retention policy appropriate to real user data.
