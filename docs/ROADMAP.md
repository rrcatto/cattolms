# Catto Learning Development Roadmap

**Current LMS version:** 0.5.7.6  
**Current stage:** pagination, counts and bounded entity pickers complete; Seed Database next

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

Deferred from this stage: Gilded Noir has no `.acl-*` rules, so the Roles and ACL editor still falls back to core CSS in the dark skin. That needs a theme version number from the project owner.

## 2. Seed Database — next

Implement Administrator-controlled, live-safe test-data infrastructure before Commerce.

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

## 9. Production readiness

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
