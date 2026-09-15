# Catto Learning 0.8.3 — Development Handoff

**Date:** 2026/09/15 SAST
**Update:** v0.8.3 final UI and account correction
**Git publication:** `main`, `dev-v0.8`, annotated tags `v0.8`, `v0.8.1`, `v0.8.2` and `v0.8.3`

The v0.8.3 update completes the canonical platform UI and account workflows. The
individual purchase flow remains implemented: guest carts, staged checkout, profile capture,
Omnipay Dummy simulations, manual EFT instructions, payment retries, immutable orders/invoices, PDF
downloads, optional invoice email and access timing. Settings now have independent accordion forms,
with bank details stored in `app_options`; cart colours and surfaces belong to the active theme.

Run additive migrations, publish the modified core/theme assets, and run `commerce:maintain` every minute or with `--watch`. Configure the bank account in Administration → Settings. No bank details belong in `.env`. The current v0.8.3 Podman QA gate passes 636 tests and 43,787 assertions, with PHPStan and all validators green. Passwordless registration, authenticated-email sessions and secondary-email promotion are covered by integration tests; public login, registration, catalogue, reports and the UI gallery were smoke-checked in Chromium at desktop and mobile widths.

The broader commerce plan remains open: real processors, bank confirmation administration, company commerce, funds, refunds, debt, gifts and academic-history changes are future work. Invoice email delivery retries may resend a message if an SMTP acknowledgement is lost; payments and fulfilment remain idempotent. The seed memory issue described in the historical handoff below has been fixed by streaming name reservations and rolling back test fixtures. Current QA is 634 tests and 43,707 assertions, with all quality gates passing.

## Current platform UI architecture

The design system expansion uses namespaced components in `resources/views/ui/`, with one
`PlatformUi` registry and authored Twig slots. Layout, actions, fields, forms, datasets, tables,
statistics, lists, metadata, notices, badges, empty states, progress, modals, accordions and icons
serve the migrated platform pages. The complete API is in [UI-COMPONENTS.md](UI-COMPONENTS.md).
The component gallery is `/admin/system/ui-components` under the existing System settings ACL.
No compatibility component names or old structural CSS aliases remain. Gilded Noir keeps its own
visual design and uses the shared structure. Earlier historical UI instructions below are records,
not current implementation requirements; use the component guide and UX rules for changes.

This standardisation exists to stop page-by-page LLM generation from recreating the same UI job
with subtly different markup. A new view must compose an existing component, or extend the registry
and its contract tests when the job is genuinely new. The resulting structure keeps spacing,
responsive behavior, accessibility and no-JavaScript fallbacks consistent across themes.

## Current catalogue component architecture

The public catalogue now composes canonical platform UI templates through `PlatformUi`. It keeps
all Tier 1 categories above a flat Tier 2/Tier 3 workspace; ordinary browsing lists direct courses,
and scoped keyword search includes descendants. Category, tag and search results use the same
24-card grid and existing course-card/search/pagination partials. The initial catalogue is bounded
to twelve cards. Tag discovery retains the complete linked vocabulary beside an optional bounded
sphere, with reduced-motion handling. Category htmx navigation updates grid state and search scope
from server-rendered out-of-band fragments; every destination also works as a normal GET.

Historical references below to descendant-inclusive *ordinary browsing*, recursive category
accordions, `open` query state, five-column grids and 25-card public pages are superseded by
`ux-ui-rules.md` section 8. They are not alternative implementations or current requirements.

## Historical v0.7 handoff

### Catto Learning 0.7 — Development Handoff

**LMS version:** 0.7  
**Date time:** 2026/09/12 SAST  
**Runtime target:** PHP >=8.5.9 <9.0 (supported floor) · verified on PHP 8.5.10 / PostgreSQL 16.15  
**Status:** released. `main` and `dev-v0.7` are in sync at the head of the interface work, and the
owner declared the release on 2026/09/12. Gate green — 562 tests, 8,890 assertions, PHPStan level 6,
architecture, runtime hazard, UI contract and release validation — with every route returning its
expected status and the 500,000-row seed maximum completing inside the deployed 128 MB limit. Owner
browser acceptance is ongoing rather than outstanding: the interface work below was done against his
direct review, theme by theme.

One qualification on "gate green", because it will be met on a second run rather than a first:
`SeedGeneratorMemoryTest` now fails intermittently with a 128 MB exhaustion in
`SeedNameFactory::reserve`, and passes in isolation every time. It is arithmetic rather than a
regression — that factory primes its "already used" sets from every name in the database so a later
set cannot repeat one, the development database is at ~156,000 users, and every `qa` run leaves its
generated rows behind because generated rows are ordinary rows now. See the known gaps below.

**v0.7 cannot be upgraded into**: it inherits v0.6's single baseline, so installing it is
`composer smoke:install` and a discarded database.

Read `PROJECT-INSTRUCTIONS.md` first. This file records the current implementation boundary and next
development work.

## 0. v0.7 — where things stand

**Framework.** Symfony 8.1.6 owns the kernel, routing, the container, sessions and error pages;
Twig with `strict_variables` renders. Fat-Free and PHP-DI are gone from the tree and from the code.
The 7.4 → 8.1.6 step needed one change: `Voter::voteOnAttribute()` gained a `?Vote` parameter.

**Interface.** One page shape across the platform — page head, identity band, body on cards in a
boxed container — with the slanted texture as the canvas and nothing rendered directly on it. The
footer is core-owned markup every theme includes. Navigation marks the current entry at all three
levels and keeps its scroll position across a page load. `docs/ux-ui-rules.md` is the rule book and
now carries all of this; read the relevant section before changing any screen.

**The identity in the bar, and what a faded notice leaves behind** — the last two pieces before the
release, both reported from the browser rather than found by testing.

The Gilded Noir chip was printing the full name where the display name is what the reader chose to
be called: `RichardC`, not `Richard Royston Catto`. They are different values and neither
abbreviates the other, so `BaseController` now publishes `identity_display_name` and the chip reads
it, keeping `identity_name` as the fallback because the column is nullable. Core's band keeps the
full name — it has the room, and the other four themes render it. Sign out became **Logout**, one
word, so the bar holds the identity beside it on one line. The avatar was 2rem in a row whose 46px
brand mark already sets the height, so it was small against space the bar had already paid for; it
is now the mark's size.

`IdentityDisplayNameContractTest` carries the rule worth keeping from that: the two branches of
`viewIdentity()` must define the same `identity_*` keys, whatever the set grows to. Twig runs with
`strict_variables`, so a key defined for a signed-in reader and forgotten for a signed-out one is
not a blank on the page — it is a 500 for everybody else.

The flash defect was one line of consequence from a missing removal. A dismissed message was taken
out of the document and the `.flash-stack` around it was not, and that stack is not inert: every
theme gives it a bottom margin, 24px in Gilded Noir. So a saved-settings notice on Administration
Settings faded, its margin stayed, and the page head never returned to the top of its boxed
container with nothing on screen to explain the gap. The stack now goes with the last message it
holds. `:empty` cannot express this in CSS — the whitespace text nodes between the messages survive
their removal — which is why the sweep lives in the dismissal. Rule 6.11, enforced by
`FlashDismissalContractTest`.

**Seed generator.** Streams rather than accumulating, so the advertised maximum is reachable. See
`PROJECT-INSTRUCTIONS.md` §3a for the four rules that keep it that way.

**Themes.** Factory Reset and Radiant Learning both received sustained investment and are no longer
"must keep rendering" only. Gilded Noir remains the acceptance theme.

### Known gaps

- **Footer social marks have nowhere to point.** No platform-level social settings exist, so the
  marks render at reduced opacity as non-links. They become links as soon as `platform_social`
  carries addresses; a Settings field per platform is the obvious home and is not built.
- **Sidebar themes still nest the third navigation level** rather than flying it out, because the
  sidebar is a scroll container and a scrolling ancestor clips an absolute popout. The owner has
  asked for popouts everywhere; the trade-off — the sidebar can no longer scroll — has not been
  chosen. See `PopoutClippingContractTest`.
- **The `v0.7.0` tag sits at the migration commit**, not at the head of the interface work that
  followed it. The release of 2026/09/12 did not move it: retagging is the owner's call, and moving
  an existing tag changes what it has always meant.
- **Both measurement instruments are dead in v0.7**, and the gate cannot see it.
  `tools/benchmark-pagination.php` imports `CattoLearning\Auth\DataUniverse` and fatals on the
  missing class; `tools/seed-benchmark-dataset.php` calls `SeedRepository::sets()`, which is gone,
  and still inserts the dropped `seed_token` and `company_type` columns. Neither was carried across
  when the universe was removed. `tools/check-architecture.php` scans `src/` only, so a banned
  symbol living under `tools/` fails nothing — widening it is the cheap guard. The repair itself is
  bounded: drop the universe argument, and drop set registration, `--list` and `--remove`, which
  have nothing to key on now. `docs/OPERATIONS.md` records this beside the numbers they produced.
- **Radiant Learning's flash messages neither close nor auto-hide.** It renders its own flash
  markup inline in `base.html.twig` with none of the three hooks rule 6.11 names, so the shared
  dismissal never finds them. The other four themes share one partial. A defect in that theme
  rather than an exception to the rule.
- **`SeedGeneratorMemoryTest` is drifting into the memory ceiling**, for the reason its own docblock
  predicted: the name factory primes from every name stored, and nothing removes the rows each `qa`
  run leaves behind. It failed two of three consecutive runs on 2026/09/12 at ~156,000 users and
  passed alone every time. Three ways out, none of them chosen: clear the accumulated rows, bound
  the priming, or give that test a scratch database.

## 0q. v0.6 — making it fit by making it smaller

The owner's correction, and the better answer: **shrink the output rather than cut it**. Truncation
now only catches what is left over.

**Padding was costing more than the words.** The theme spends `13px 16px` on every cell, so an
eleven-column table paid about 350px in padding before showing anything. Halving it and dropping the
type a step frees far more room than any amount of ellipsis, and hides nothing. The scale is a set of
custom properties so the whole thing moves together and can be tuned in one place.

**Then the output itself.** Measured column by column rather than guessed at:

- Every list printed timestamps to the microsecond with a timezone offset - `2026-09-06
  03:31:27.749417+02`, twenty-nine characters, the widest column on several screens after the email.
  Trimmed to the minute in one shared helper. Administration Courses needed it separately: it is the
  one list with no normaliser, returning repository rows straight to the template.
- Administration Courses printed the full slug under every title. Sixty characters of
  `/hydraulic-systems-theory-and-practice-0-9542b1018f6fb6ad`, which is not information a reader of a
  list wants.
- The Structure column - "4 modules / 20 questions" - is detail belonging to the course record, not
  to a list somebody scans. It was 154px, which was exactly what the widest row was over by.

**Where each table stands**, estimated from the rendered rows against a 1400px content area:

| Table | Before | Now |
|---|---|---|
| Administration Courses | ~2200px | ~1376px |
| Administration People | ~1545px | ~1031px |
| Course Requests | ~1757px | ~1205px |
| Enrolments | — | ~1020px |
| Companies, Credits, Reports, Company lists | — | 620-1005px |

One caveat worth stating: the seed generator writes 80-character email addresses
(`name.id.suffix@company-slug-suffix.seed.invalid`), where a real one is nearer 28. Those cells
truncate rather than wrap or scroll, and the full value is in a title attribute - but the figures
above are the realistic ones, not the seed ones.

## 0p. v0.6 — the one-row rule, actually applied

The rule was written on 2026/09/07 and was wrong on every screen. Recorded in full because the way it
failed is more useful than the fix.

**Core said it; every theme unsaid it.** Core had `.row-actions{flex-wrap:nowrap}` at specificity
(0,1,0). Gilded Noir has `.gn-main .row-actions{flex-wrap:wrap}` at (0,2,0), and theme CSS loads
after core. The core rule lost on every page, on every theme, and the build stayed green because
nothing asked. Functional layout belongs to the LMS, so core now states these few properties with
`!important` - not as a shortcut, but because it is the only way a platform rule outranks a theme
that redeclares it.

**Not wrapping is only half of fitting.** Content wider than its column has to go somewhere and there
are exactly three options: wrap, scroll, or shorten. The first two were the ones being complained
about, so a long value is now **truncated with an ellipsis**. That is what makes "no wrapping and no
horizontal scroll" achievable rather than contradictory.

**Two more ways a row escaped the rule**, both found by grep rather than by looking at the page:

- a cell laying its actions out with a hand-written `d-flex flex-wrap` instead of `.row-actions`, so
  no core rule named it;
- a table wrapped in Bootstrap's `table-responsive`, which core never styles, so none of the rules
  reached it at all.

**Five buttons do not fit a row**, and shrinking them until they do produces five things nobody can
read. Administration Courses now shows *Manage* and a `⋯` menu holding Preview, Export, Reset and
Delete - a native `details`, so it opens with no JavaScript.

Also in this round, all of it reported from the browser rather than found by testing: the taxonomy
distribution charts are gone; creating a category and creating a tag are modals behind a button
instead of a panel taking half the screen, so both tables use the full width; the tag row is one line
including its delete control; and **Course Requests had no universe selector** - it was never added to
the counted-datasets map when it became its own section, and the control is built only for a section
listed there.

`TableRowContractTest` covers all three failure modes, and each is verified by reintroducing it: the
missing `!important`, a wrapping row-action container, and a table using the wrapper core does not
style.

## 0o. v0.6 — assigning a course, and the last pre-Commerce gap closed

The owner's second workflow is built: an administrator assigns a course to a staff member and tells
them outside the LMS. The form is on `/company/enrolments`, and both ways onto a course now end at
the same place under the same two rules — **owned costs nothing and consumes no credit**, and
**anything else takes one free seat**. With neither, the assignment is refused rather than recorded:
until Commerce exists there is nothing to pay with, and a silently recorded enrolment nobody paid for
is the exact state this platform already shipped once in "approved" requests that enrolled nobody.

Three things worth keeping:

- **The company comes from the context, never from the request.** A course id and a person id arrive
  from a form; whose entitlement is spent does not.
- **A membership check, because both ids are form input.** Without it an administrator could put
  anybody on the platform onto a course using their own company's seats, and nothing about the
  request would look wrong. Removing the check fails a test by name.
- **The whole thing is one transaction, and the email is sent after it commits.** Between finding a
  free seat and taking it, a second administrator could take the same one; `availableCredit()` locks
  the credit row `FOR UPDATE` and holding the enrolment in the same transaction is what makes that
  lock mean anything. Telling a learner they are on a course a rolled-back transaction never enrolled
  them on is worse than telling them nothing.

**A company-scoped lookup.** The pickers could not use the Administration lookup: it is guarded by
`PLATFORM.*` permissions a company administrator does not hold, and granting them would let a company
administrator search every person and course on the platform. `/company/lookup/@type` is guarded by
company permissions and scoped to the company in context. The shared lookup partial takes the
endpoint as a parameter, defaulting to the Administration one.

The course picker offers only what can actually be assigned: courses the company owns, plus courses
where it holds a credit **with a free seat**. A fully allocated credit buys nothing more, and
offering it would produce an assignment that fails at the last step.

Verified live: assigning with no entitlement is refused with "no free seat … buy a credit before
assigning it", and assigning somebody outside the company with "not an active member of this
company".

**Next.** Commerce: cart and orders first, with a VAT amount on every order line from the first
migration.

## 0n. v0.6 — money, before there is any

The owner's money decisions are recorded in ROADMAP section 2d and the parts that constrain the
schema are in place. Commerce itself is not started.

**`intl` is now in the PHP image**, and `Support/Money` is the only place in the platform that turns
cents into something a person reads. The formatter it replaced tested the currency code against
`'ZAR'` and prefixed an R - correct for exactly one country, and wrong in two ways for the next: it
puts the symbol on the wrong side for several currencies and uses the wrong thousands separator for
most of Europe. `R 1 234,56`, `£1,234.56`, `1.234,56 €` now come from one call.

**A typed price is parsed as digits, never as a float.** `9.995` cannot be held exactly in binary: it
lands slightly *below* 9.995, so a float round yields 999 cents rather than 1000. Nothing errors and
the price is simply a cent light - the kind of thing found by reconciling a day's takings against a
processor that did not lose it. `MoneyTest` pins that case by name.

**Locale, currency, country and VAT rate are `.env` settings.** No currency symbol is configured and
no currency table exists: intl derives symbol, placement and separators from the code and the locale.
No country table either - one is worth building when something reads an attribute from it, and today
nothing does.

**Prices are stored excluding VAT** and the rate defaults to zero. The one thing that reaches forward
into Commerce is that order lines must carry a VAT amount from the first migration, zero until
registration: adding the column afterwards leaves every historical order with no VAT figure and no
honest way to produce one.

**A container trap, now in CLAUDE.md.** `podman-compose build php` followed by `up -d` leaves the
container running the image it was created from, so a newly installed extension is silently absent -
`php -m` said no `intl` against a freshly built image that had it. `down` then `up -d` is what
actually recreates it.

## 0m. v0.6 — the Company split, favourites, and what an approval really did

**Company Courses meant two opposite things.** Owned *or* credited, behind one screen. One is stock
the company sells; the other is stock it consumes, bought a fixed number of seats at a time. A single
list answers neither question, and where a company both owned and had bought the same course the
union counted it once — so the two figures could not even be recovered by subtraction. Verified in
the seed data: one company owns 2 and has bought 2, and the old combined list showed 3.

They are now **Company Courses** and **Training Courses**, two routes, two predicates that are never
recombined, and one resolver shared by the rows and the count. A training row carries what an
entitlement row is for: seats bought, seats still free, staff enrolled, staff completed — scalar
sub-selects, because credits, allocations and enrolments are three independent one-to-many
relationships on the same course and joining them multiplies before anything is counted.

**Company favourites is its own table.** A company-to-course relationship, not a favourite belonging
to whoever administers the company that week — owner decision, 2026/09/07. Staff favouriting a course
for themselves changes nothing here, which the test asserts in both directions. It carries no
`seed_token` for the same reason `course_tags` does not: the company already carries the universe and
the row cascades with it.

That last decision needed a **purpose-written cross-universe trigger**. The generic one compares each
referenced row against *the row's own* `seed_token`, and this table deliberately has none — so every
row would look REAL and a seed company favouriting a seed course would be rejected. The guard here
compares the two referenced rows with each other, which is the actual rule, and the test proves both
halves.

**"Approved" was hiding a failure.** A request approved with no credit available is recorded as
approved and puts *nobody* on a course, and the screen said "Approved" either way — so an
administrator walked away believing their staff member was enrolled. The stored word and the readable
one are now different: **Approved — awaiting payment** for the decided-but-unfulfilled state,
**Enrolled** for the one that worked, in one shared map rather than a ternary in each of the two
request templates. A status filter sits above the list, so the ones waiting can be found. Verified:
1,875 of 3,750 seed requests are awaiting payment.

**A validator that forbade its own purpose.** `release:validate` banned the words `CREATE TABLE` in
any non-baseline migration. Its own comment says the rule is "must not drop or recreate a table the
baseline owns" — but as written it made it impossible to add a *new* table, which is the ordinary
reason an additive migration exists. It now judges by which table is named: a migration may create
tables the baseline does not own, and may drop only what it created itself. A migration that rebuilds
`users` still fails it by name.

**Next.** Assigning an existing credit to a staff member without going through a request — the last
piece of the owner's second workflow that does not need Commerce. Then Commerce.

## 0l. v0.6 — one page header, everywhere

Every screen now carries the same header in the same place: back link, kicker, title, lead, and at
most one action. Four answers - where am I, what is this page called, what is it for, how do I get
back - in a fixed position, whatever theme is installed.

It existed already on two screens and was liked there. Seventeen pages had **no header at all**, and
the rest had each written their own markup, so the answers moved from page to page. It is one core
partial now, `partials/page-head.html`, used by the three workspace shells (which covers every
Administration, Account and Company section at a stroke) and by every standalone page.

**Two deliberate exceptions.** The home page and the course-facing screens. A course carries its own
title, progress and navigation, and a full platform header above that competes with the thing the
reader came for - so `learn-course` uses compact mode, a back link and a title on one line, and
`learn-module` uses none at all because its existing course toolbar already *is* that. The catalogue,
course detail, the assessment runner and the error page are heroes or have no context to describe.
Every exception is named in `PageHeadContractTest` with its reason, and listing a page that does
carry a header fails the test too - the list cannot rot in either direction.

**Three template traps, all found by breaking pages.** They are worth recording because none is
visible by reading the markup:

- **Historical, and the reason the markup still looks this way:** F3 split an include's `with`
  attribute on every comma, quoted or not, so a lead containing a comma silently became two broken
  parameters. That is why the header's parameters are `set` tags. Twig parses `with` as a real
  expression and has no such hazard, so new includes need not follow the pattern.
- **A `set` attribute is evaluated as one expression, not as interpolated text.** Literal words mixed
  with template tokens compile to a PHP syntax error and take the whole page down. A mixed value has
  to be written as a concatenation.
- **A value must have balanced tokens**, for the same reason: an unbalanced one is a parse error at
  render rather than a wrong string at review.

All three are asserted, and the assertions fail when reintroduced.

**Two things I broke and fixed on the way.** A conversion pass wrapped values that already carried
tokens, producing doubled ones; the repair for that was a regex on the tail, which then stripped
legitimate closing tokens from fifteen files. The second repair counts tokens per value rather than
matching on the tail, which is the version that holds. And `/admin` had been returning 500 since the
Requests split - the consolidated workspace builds a preview per registered section and the new
section had none. Caught by route-smoking every page rather than only the ones I had touched.


**Corrections after review.** Four defects in the first pass, all found by the owner:

- The catalogue was listed as an exception on the grounds that it is its own hero. It is not; it now
  carries the header like everything else, and the heading varies by what is being browsed.
- **Eleven screens ended up with two headings.** Twenty-one pages had the header *prepended* rather
  than *substituted*, so each kept its own heading block underneath. Each has now had its own block
  removed, and its wording moved into the shared header - the old wording was the accurate one, and
  the placeholder text written when the header was added was a guess. Where the second heading
  belongs to an artefact rather than to the page - a certificate, a module preview - the page keeps
  its heading and the platform header is compact instead.
- **Every Company route carried a band of white space above its heading.** The theme sets
  `.gn-main { padding-top: 0 }` so a full-bleed header sits flush against the navigation, and that
  only applies while the header is the *first* thing in the content area. The Company shell opened
  `<section class="cl-company-section">` first and put the header inside it, so the wrapper became
  the first child and the header started below it. Account had it outside, which is why only Company
  showed the gap. The header is now hoisted above the wrapper and the two workspaces render an
  identical structure.

A contract for that last one was written and then **removed rather than kept**: it passed with the
nesting deliberately reintroduced, and the same logic run standalone flagged three files the test did
not, so it was not proving what it claimed. It would also have been wrong as stated - three screens
legitimately open with a preview banner above the header. The remaining five assertions in
`PageHeadContractTest` are each verified by reintroducing the defect they describe.

## 0k. v0.6 — one row, one line; and the Courses group became five routes

**A table row never wraps.** Core rule, not a per-screen fix: row actions, cells and the table
itself. A row that wraps stops being a row - the reader is scanning a grid, and the moment one cell
pushes its neighbours onto a second line the columns no longer line up with the headings above them,
which is the whole reason a table was chosen over a list. Three separate rules, because there are
three separate ways a row wraps: the buttons wrapping among themselves, a long value wrapping inside
its column, and the table being narrower than its content. The last is why `.table-wrap` scrolls
sideways: given too little room the table slides rather than folds. Cells that legitimately hold
prose keep their wrapping and are named rather than assumed.

**The Courses group is five screens on five routes, with one tab strip.** Courses, Course Categories,
Course Tags, Course Requests, Enrolments. Every one renders the strip in the same position -
immediately above its own heading - which is what makes it read as one interface rather than five
pages that link to each other.

Requests and Enrolments had shared `/admin/enrolments` behind in-page tab buttons. That is the part
worth recording: **a tab that is not a route cannot be linked to, bookmarked or reloaded**, and the
browser's back button cannot return the reader to the one they were looking at. They are separate
sections now, each with its own route, permission, search, pagination, sort state and universe
control.

Two contracts loosened rather than deleted. The `wants()` swap guard and the "one screen, two
pagers" checks existed because the two lists shared a screen; separate routes is a *stronger* form of
the same guarantee, so the checks now assert it per file and would still fail if the screens were
recombined without independent state. The `business-any` permission form went with them - it existed
solely for the combined screen needing either of two permissions, and PHPStan reported the match arm
as dead the moment it did.

**One defect, caught in browser testing and now covered.** The Requests section was registered,
routed and rendering, and `sectionCapabilities()` still recognised only the old combined key - so it
received an empty capability set, its own guard closed, and the screen returned 200 with no table and
no error. `AdministrationSectionContractTest::testEverySectionGuardIsGivenItsCapability` scans the
service for capability guards and fails if the controller never supplies one; removing the requests
line fails it by name.

## 0j. v0.6 — sorting, finished

Sorting was built in the Pagination 2.0 round, wired to Administration People, recorded as "the
remaining lists follow the same pattern" — and then not extended, while other work started. A reader
on Administration Companies found a paginated table whose headings did nothing. That is now done.

**Every paginated list sorts, in both workspaces:** Administration People, Companies, Courses,
Enrolments, Course Requests, Credits, Activity, Course performance and Company Enrolments; Company
People, Requests, Enrolments, Credits and Courses.

Three things had to change beyond adding whitelists:

- **The header model covers every column in a table now, sortable or not.** People had been rendering
  its row as two filtered repeats with hand-written cells between them, which does not survive being
  copied to eight more tables. A heading row is one repeat over one model; a non-sortable column
  renders as an ordinary heading, and a column can gain sorting without its markup changing.
- **Administration Course Requests was a card list, not a table.** It had no column to click at all.
  It is a table now, matching the Company one.
- **Computed columns sort by sub-select, not by alias.** The deferred join applies one order
  expression to both halves and the key query selects ids, so an alias from the projection is not
  available to it. Sorting Companies by People, Credits by Assigned or the reports by Enrolments all
  restate the figure as a correlated sub-query. That costs a pass over the parent table — bounded by
  companies or courses, never by the people or enrolments inside them.

**The check that was missing.** `PaginationUiContractTest` now asserts that any partial including the
shared pagination control also draws its headings from the shared sort model, and that a generated
heading row carries no hand-written cells. Reverting Companies to static headings fails it by name.
No test had previously asked whether a list that pages can also be sorted, which is exactly why the
gap survived a green gate.

**Still not sortable, deliberately:** Roles on People, Progress on Company Enrolments, Completion on
the reports, and Actor, Subject, Result, IP, Location and Source on Activity. The first four are
ratios or aggregated strings; the Activity ones come from JSON metadata or from joins that query only
adds when a filter needs them. Every one of them is a column the reader can see, so they are worth
revisiting — but each needs its own expression rather than a whitelist entry.

## 0i. v0.6 — distribution charts, the tag index, and the end of 3b

Both taxonomy administration screens now open with a bar chart of where the published catalogue
actually is: per top-level branch on Course Categories, per most-used tag on Course Tags, counted in
the reader's universe.

**The remainder row is the point of the chart as much as the bars are.** Uncategorised on one,
untagged on the other. A course filed under nothing is invisible to every browse path there is, and
until now nothing on the platform said how many of them existed. Live, the category chart adds to
exactly 834 across sixteen branches plus the remainder — the whole published seed catalogue.

**No charting library.** A bar is a div with a width. A library would add a network dependency, a
script that has to run before anything is legible, and a canvas a screen reader cannot read; this
renders server-side and the numbers are text beside the bars whether they draw or not. Bars scale
against the largest row rather than the total, because a catalogue spread evenly over sixteen
branches would draw sixteen bars at six percent each and say nothing. The share is computed in the
service — a template that divides is a template that will one day divide by zero.

`/courses/tags` is the public tag index: every active label, weighted in five steps rather than a
continuous scale, because a cloud sized by raw proportion is unreadable the moment one tag runs away
with the catalogue and nobody can tell one point of type size from the next anyway. Empty tags are
listed and greyed rather than hidden — a term nobody has used is exactly what a reader of that page
wants to see.

**On the gate, honestly.** `/admin/courses/categories` 500'd during this round on a repository method
I had written into the service but not the repository, because a multi-part edit script aborted after
its first insertion. `composer qa` had been green — but only because I had not re-run it since making
that edit. Renaming the method afterwards confirms PHPStan reports it as an undefined call, so the
gate does cover this; the lesson is about running it, not about extending it.

**A test that would have passed on nothing.** The distribution's partition assertion — branches plus
the remainder equal the whole catalogue — held with the remainder zeroed out, because every course in
the fixture happened to be categorised. The fixture now files one course under nothing, and zeroing
the remainder fails it 837 against 838.

**Section 3b is complete.** Category hierarchy, browsing, tags, tag pages, faceted search with
relaxed-facet counts, distribution charts and the tag index are all in. The remaining discovery work
belongs to later sections: search ranking and SEO (9), and analytics (9b).

**Next.** By the owner's build order that is 3d, company course lifecycle — separating the five
overloaded concepts Commerce will transact against — then 3c favourites, then Commerce.

## 0h. v0.6 — faceted search

Every option in both rails now carries the number of courses choosing it would return, and tags
multi-select.

**Several tags mean any of them, not all.** Two reasons pointing the same way: a course carries three
tags out of a hundred, so intersecting them collapses to nothing almost immediately; and a facet
whose options can only ever narrow leaves the reader no way back except starting again. OR within a
facet, AND across facets, is the convention for exactly that reason.

**The rule the whole thing rests on: a facet's own counts are taken with that facet relaxed.** Apply
the tag facet to its own counts and choosing one tag drives every other tag to zero — no course
carries a tag it does not carry — so the rail becomes one live option and a dead end. Counted with
the tag facet dropped but the category and keyword still applied, each figure means "how many more
courses this would add", which is the question the reader is actually asking. The test asserts both
halves: the relaxed figure, *and* that the applied one would have removed the other option entirely.
Verified live — Cloud offers 26 courses across the catalogue and 2 inside Information Technology.

Three things that only showed up once it was on screen:

- **A chosen tag is always offered.** Arrive on a link to an uncommon tag and it is not in the
  popular rail: the filter is on, nothing on screen says so, and there is no control to turn it off.
  Selected tags are merged into the rail whether or not they made the list.
- **Facet links compose against the category base, never the tag path.** From
  `/courses/tag/databases`, "clear tags" was linking to `/courses/tag/databases` — the tag came
  straight back. The tag path stays a canonical entry point; touching a facet moves the reader to the
  query form, which is the only form that can express a combination anyway.
- **A zero-count option is hidden unless it is selected.** Otherwise narrowing to nothing hides the
  control that would undo it.

**Next.** The administration distribution charts, and tag clouds beyond the browse rail.

## 0g. v0.6 — tags, and one filter for the catalogue

**Administration → Courses → Course Tags.** A searched, paginated, flat list on the three shared
controls — flat because a tag is cross-cutting classification and not a second hierarchy, which is
the whole reason both tags and categories exist. Its own permission, `COURSE.TAG.MANAGE`, because
delegating the catalogue's structure and delegating its labels are different decisions.

The slug is derived from the name rather than typed. It is the canonical key, so deriving it is what
stops "Cyber Security", "cyber security" and "Cyber  Security" becoming three tags meaning one thing,
and a clash is refused rather than suffixed: two tags whose slugs collide *are* the same tag, and the
operator should merge or rename rather than end up with `cyber-security-2`. Deleting a tag asks for
no replacement, unlike deleting a category — a course with one fewer tag is still classified; a
course with no category is not.

**`/courses/tag/<slug>`** browses it, chips on every card link into it, and an inactive tag is
unaddressable exactly as an inactive category is.

**One filter object.** Category, tag and keyword are now a single `CatalogueFilter` handed to both
`publishedCourses()` and `publishedCoursesCount()`. Three more positional arguments on two methods
would have worked; an object cannot be half-applied, and half-applied is precisely how a faceted
count comes to disagree with its own list. It is also the shape the rest of faceted search needs —
another facet is a property, not a fourth argument on four methods. The tag predicate is an EXISTS
rather than a join, because a course carries several tags and a join returns it once per tag; the
test that would catch that regression is the one that puts two tags on one course and asserts the
count does not move.

The catalogue's own search box used to be a client-side filter over rendered cards — the exact thing
CLAUDE.md forbids, sitting on the most public screen on the platform. It is the shared server-side
control now, over title, subtitle and summary.

**Two defects found on the way.**

- `SeedGenerator::tagCourses()` built its association rows and then ended on a comment explaining why
  they were not counted, *without ever writing them*. Four seed sets and 1,250 generated courses had
  left `course_tags` completely empty, and nothing noticed because no tag surface existed yet and the
  association carries no seed token to audit. It writes through `SeedRepository::attachCourseTags()`
  now — its own method, so the one insert outside the frozen catalogue stays visible rather than
  becoming a hole in `insertMany()`. `SeedGenerationIntegrationTest` asserts every generated course
  carries a tag, and that the associations stay out of the set's manifest.
- The live database's associations were backfilled in the same shape the generator writes: 3,750
  rows across 1,250 courses. Additive only; no seed data was removed.

**A rule worth recording.** `tags()` and `tagsCount()` are both universe-free, because both describe
the same population — the tags that exist — and a label belongs to no universe. How many *courses*
carry a tag is a different question and is `courseCountsForTags()`, which does take a `DataUniverse`.
Folding the count into the list would have made a scoped list with an unscoped total, which is the
shape `DataUniverseScopeTest`'s pairing rule exists to catch. The rule was right; the fix was to
split the read, not to widen the exemption.

**Next.** Facet counts shown beside each option and multi-select within a facet, then the
administration distribution charts.

## 0f. v0.6 — browsing by category

`/courses/category/<slug>` is the catalogue body told which branch it is showing: a breadcrumb back
up, the category's own heading and description, a rail of child categories to narrow with, and the
ordinary paginated course grid.

**Browsing a level includes every level beneath it.** Browsing Technology returns courses in
Technology, in Linux and in Linux Administration. That predicate — self, children, grandchildren —
is stated exactly once, in `CourseRepository::inBranchOf()`, and shared by the taxonomy rollup, the
browse count and the browse rows. Sharing it is the point rather than tidiness: a faceted count that
disagrees with its own list is indistinguishable from missing data, so the two halves have to mean
the same thing by construction and not by two authors writing the same SQL twice. The three-level cap
is what lets it be two nested lookups rather than a recursive walk; the breadcrumb *is* recursive,
written that way so it would follow the cap if the cap ever moved.

Verified against the live seed set: the root reports 834 courses and its sixteen top-level branches
sum to exactly 834; Information Technology reports 60 with its five children summing to 56, the
remaining four being filed at level one. Rows and counts agree on every page.

**The universe trap this walks straight into.** A category carries no `seed_token` — it is a shared
label. What is counted under it is not. So every count here takes an explicit `DataUniverse`, and
`CategoryBrowseIntegrationTest` files a genuine course in the same leaf as a generated one and
asserts the same branch reports different populations in REAL and in SEED. Flattening the predicate
to a direct `category_id` match was tried against those tests: four of six fail, so they are not
vacuous.

Two smaller decisions. An unknown or deactivated slug browses the whole catalogue rather than
returning 404 — a stale link should show a reader courses. And an **inactive** category is not
addressable at all, because a public URL that still resolves into a branch the operator removed is a
way to browse a taxonomy that is supposed to be gone.

One defect removed on the way past: `categoryBySlug()` selected an unscoped course count beside the
row. Nothing read it — its only caller asks whether a slug is taken — so the count is gone rather
than scoped. An unused figure that can only ever be wrong is not worth keeping correct.

**Next.** Tag management and tag pages, then faceted search over title, subtitle, summary and tags.

## 0e. v0.6 — the third navigation level, and five theme packages

Administration outgrew a flat menu, so a menu child may now carry children of its own. The first
attempt drew that level inline: a heading, a rule above it and the members indented beneath. It read
well and it made the panel twelve rows longer than it needed to be, so the level is now a **flyout** —
the heading and its separator stay in the parent panel and the members open to the side on hover.

**No JavaScript is involved.** `.nav-subpanel` is `display:none` and core reveals it on `:hover` and
`:focus-within`. That second selector is why the heading is a `<button>` rather than a `<span>`: the
members are hidden, so nothing inside the group is focusable, so unless the heading itself can take
focus, Tab can never reach them and the whole level is mouse-only. A span renders identically and
silently removes keyboard access, which is exactly the kind of regression a contract test exists for
— `NavigationContractTest::testTheThirdNavigationLevelIsAFlyoutReachableWithoutAMouse` pins the
button, the two selectors, and that `platform-overrides.js` never learns about this control.

Two other decisions worth keeping:

- **The panel carries its own background and text colour.** It is positioned over page content, and
  a theme's near-black menu would otherwise hand it the light page's ink and render the heading
  invisible against itself. That is not hypothetical: it is precisely how the first inline version
  shipped, correct markup that nobody could see.
- **Direction is a class, not markup logic.** `nav-flyout-left` on the group flips the panel, and a
  theme whose menu is right-aligned in a top bar needs it or the flyout leaves the viewport. A left
  rail wants the default.

Below 1080px the members return to the indented list: a narrow viewport has nowhere to fly out to
and a finger has no hover.

**All five theme packages were behind.** None of them rendered `@child.children` at all — they
flattened Administration to two levels, which is the failure the grouping exists to prevent. Each now
renders the flyout using the core classes, adds the surface colours its own panel needs, and is
bumped by one patch level:

| Theme | Was | Now |
|---|---|---|
| Factory Reset (bundled) | 1.0.3 | 1.0.4 |
| Factory Reset Sidebar | 1.0.2 | 1.0.3 |
| Gilded Noir | 1.1.7 | 1.1.8 |
| Light Default | 1.1.2 | 1.1.3 |
| Radiant Learning | 3.2.3 | 3.2.4 |

Factory Reset Sidebar also had a second defect: it pinned its parent at Factory Reset **1.0.2**,
which has not shipped for two releases, and the exact-parent rule means it simply could not be
installed. It now pins 1.0.4.

The bundled bump carries its usual cascade — `themes/factory-reset/theme.json`, the package in
`extras/themes/`, and the `active_theme` seed in the baseline, which `validate-release.php` checks
agree. `THEME-SDK.md` section 5 now documents the third level, the flyout contract and the grouped
Administration hierarchy, which it had never described.

**Next.** Browser acceptance of the flyout in all five themes, including keyboard traversal and the
narrow-viewport fallback.

## 0d. v0.6 — Seed set breakdown

Generation has always written a row per table into `seed_data_tables`, and nothing ever displayed
it: the history list showed the whole-set requested, generated and current totals, so a set that
came out short gave no way to see which tables were short. The token in that list is now a link to
`/admin/seed/@token`, which shows every table the set touched with two figures.

They answer different questions and are deliberately kept apart. **Written** is what generation
recorded and never changes. **Remaining** is counted from the physical rows now, so it falls when a
cleanup runs, when another set's cleanup takes dependent rows with it, or when a row is deleted by
hand — the same derived count decision (D2) the set list already follows. Tables with nothing in
either column are omitted, so the page shows the shape of the set rather than the shape of the
catalogue.

The screen is `SYSTEM.SEED.VIEW`, like the section it belongs to; the cleanup link on it stays
behind `SYSTEM.SEED.MANAGE`.

One defect fell out of building it. `seed_token` is a UUID column, so PostgreSQL casts rather than
compares, and a hand-typed token that is not a UUID failed inside the database and printed
`SQLSTATE[22P02]` in front of the reader. `Uuid::isValid()` now rejects it in `findSet()`, which
covers the cleanup routes too, and the pattern is anchored with `\z` rather than `$` because `$`
also matches before a trailing newline.

## 0c. v0.6 — seed data, taxonomy and one canonical baseline

**One migration.** The 0.5.8 baseline and the five migrations after it are collapsed into
`20260906120000_create_v06_baseline.php`. This is a deliberate, owner-authorised rebase, so **v0.6
installs by reset and has no upgrade path from 0.5.8.3** — see OPERATIONS.md.

**Categories and tags are labels, not business records.** `course_categories` left the seed-aware
inventory, which drops from 31 tables to 30 and from 66 guarded cross-universe references to 65. The
amendment to owner decision D3 is recorded in `SeedTableCatalog` with its reasoning. `tags` and
`course_tags` are new and were never universe-aware. What stays universe-scoped is everything
counted under a label — see PROJECT-INSTRUCTIONS section 5.

**Three-level category hierarchy.** `parent_id` and `level`, capped by a CHECK constraint, with a
trigger enforcing that a child sits exactly one level below its parent — that cannot be a CHECK
because it reads another row. `ON DELETE RESTRICT` on the parent. Unique `name` and `slug`. Seeded
with a worked branch of each depth so a fresh install exercises it. The management, browsing,
breadcrumb and tag-cloud surfaces are **not** built yet.

**Generated names are real names.** Ten editable word lists in `storage/seeds/`, roughly 7,700
entries, combining to 1.5 billion person names, 117 million company names and 6.3 million course
titles. Uniqueness is a guarantee rather than a probability: every combination drawn is remembered,
repeats are redrawn, and the factory is primed with the names already in the database so a second
set never repeats a person from the first. Nothing is appended to any name.

The old generator produced 237 distinct names across 1,282 people and eighteen course titles across
317 courses. The cause was not only small lists: `next()` returned the low bits of a linear
congruential generator modulo the pool size, and those bits alternate with a period of two, so
**half of every even-sized list was unreachable** — 17 of 34 first names, 14 of 28 surnames. It was
also used for numeric draws, so every generated assessment had its correct answer in one of two
positions. Replaced with a seeded `Random\Randomizer`, reproducible per set and isolated.

**A seed request delivers what it says.** A request for 100,000 now writes exactly 100,000 rows with
zero delta on every table, and 7,500 people — 7.5%, inside the agreed 5–10% band. It previously
promised 100,335 and delivered 87,510. Every figure is a share of the request, and the audit trail
is computed last as the remainder, which is what absorbs the rounding drift. Content depth is
tiered: 15% of courses are built out and 10% of enrolments carry a full history, which is what makes
a thousand courses affordable inside the budget.

`users.middle_names` holds every name between the first name and the surname, and appears on the
account profile, the Administration person profile and the Company People editor.

**Next:** the category, tag and search surfaces; the Benchmarks Administration section.

## 0b. v0.6 — Pagination 2.0

Work is under way in `code/cattolms-v0.6`. `code/cattolms-v0.5.8.3` is the accepted VPS version and
is not being changed.

**The control.** `partials/pagination.html` is still the only pagination control on the platform and
now offers First, Previous, an adaptive numbered window with ellipses
(`1 … 47 48 49 50 51 … 4000`), Next, Last, a jump-to-page number input and rows-per-page. The window
is `Pagination::window()`, which shifts rather than clips near either end so the same number of
pages is always offered. Every target URL is composed by
`PlatformAdministrationService::paginationPayload()`, which is static and dependency-free so the
render tests feed the partial exactly what production produces — the public catalogue was the last
surface assembling a payload of its own and now uses it too. Search, filters, the data universe and
the chosen page size travel on every link as ordinary query values. Each control is htmx-enhanced
over the standard results region and pushes the URL; every one is also a real link or a real GET
form, so the whole control works with no JavaScript.

**The queries.** Pagination stays on OFFSET, so any page remains reachable. Every paginated read is
now a *deferred join*: `PageQuery::deferred()` selects the page's identifiers with the same WHERE,
the same ORDER BY and the same LIMIT/OFFSET, and only then joins outwards for those rows. The order
expression is stated once and used by both halves, so a page cannot hold the right rows in the wrong
sequence.

**The orderings.** Every list now ends its ORDER BY in a unique column. Several did not, and an
ordering that is not total is free to show a row on two consecutive pages and its neighbour on
neither. Two generated sort keys were added because the previous expressions could not be indexed at
all: `users.sort_name` replaces a `concat_ws()` chain that also reached onto another table, and
`course_requests.status_rank` replaces a CASE over the status text. Fifteen ordering indexes and one
missing foreign-key index accompany them, all additive.

**The indexes.** Three separate problems, all of them invisible until the benchmark existed.

*Ordering.* Fifteen indexes now mirror the ORDER BY of each paginated list, including two partial
and expression indexes for the catalogue and the generated sort keys.

*Every foreign key.* Thirty-four foreign keys had no index leading with their own columns.
PostgreSQL indexes the parent side of a foreign key automatically and the child side never, so each
of those made every parent delete or key update a sequential scan of the child table, once per
parent row. Removing a seed batch of 100,000 people had to scan `auth_login_tokens`,
`course_credit_allocations` and every RESTRICT-guarded `created_by_user_id` column 100,000 times
each, and did not finish in ten minutes; it now takes 90 seconds for 920,000 rows. The same cost
was being paid in smaller multiples by every ordinary delete on the platform. The rule applied is
the blunt one — every foreign key is indexed — rather than a curated list.

*Two query shapes an index cannot rescue.* The Companies list joined three independent one-to-many
relationships to the same company and de-duplicated with `COUNT(DISTINCT)`, which is a cartesian
product: with 20,000 people in one company its first page did not complete in ten minutes. It is
three scalar sub-selects now. The Activity log matched JSON metadata against row ids as `id::text`,
a cast that defeats the primary key entirely, at 2.3 seconds a page; the cast expression is indexed
rather than the comparison rewritten, because casting the metadata value to `bigint` instead would
turn one non-numeric historical audit value into an error page.

**Where it landed.** At 100,000 rows in every paginated table, the deepest page of every list costs
between 3ms and 170ms, and most lists cost about the same on their last page as on their first. The
one shape that still grows with depth is the plain OFFSET walk — Administration People is 4ms on
page 1 and about 120ms on page 4,000 of 4,000 — which is the honest price of being able to jump to
an arbitrary page. Numbers and tools are in `OPERATIONS.md`.

**Deliberately unchanged.** Counts stay exact and pagination stays on OFFSET. Keyset pagination and
approximate counts were the alternatives; the benchmark says neither is needed yet.

**Refinements after first use.** Three, all in the shared control rather than per screen:

- *The control hides itself on a single page.* Every button, numbered page and jump target on a
  one-page list points at the page the reader is already on, and a row of dead controls reads as a
  broken one. `Pagination::has_pages` gates the navigation; the row count stays outside it, because
  how many records exist is still worth knowing. The threshold is one page rather than 25 records,
  so it follows the chosen page size instead of ignoring it.
- *The control renders above and below every table.* A reader who has just scrolled 150 rows should
  not scroll back to change page, and a reader who has just arrived should not scroll down to learn
  there are more. `PaginationUiContractTest` counts the includes, so a list that grows a third copy
  or loses one fails the build.
- *Column headings sort.* `Support/SortOrder` resolves a requested key and direction against the keys
  a dataset offers, and `partials/sortable-header.html` is the one heading control. The request
  supplies a *key*, never a column: an ORDER BY takes an expression rather than a value, so no
  binding exists for it and a whitelist is the only safe shape. An unknown key falls back to the
  dataset default rather than erroring, because a stale bookmark should still show a list. Sorting
  never replaces the tiebreaker — the repository appends its unique column to whatever the reader
  chose, so a sorted list still pages through every row exactly once. Administration People is
  wired; the remaining lists follow the same `*_SORTS` whitelist pattern.

**Next.** Browser acceptance of the new control across the Administration, Company and Account
workspaces, and the remaining sortable lists.

## 0a. v0.5.8.3 — Stage C and Stage D

**Stage C, editable SEED System Company Settings.** Administration Settings now edits the shared
SEED System Company. The row is the one the baseline created with the reserved infrastructure
token and it is edited in place: the token is never rewritten, `is_system` is never cleared, and
the administrator never becomes a member of it.

The interesting half is mail consistency. The SEED domain is both what Administration displays and
where seed mail is delivered, so it resolves through `RuntimeSettings` — `app_options` row, then
`.env`, then a built-in default — and `SeedMailRouter` is built from that rather than from `Env`.
Had the container gone on reading `.env`, a saved domain would have been displayed everywhere and
used nowhere, with no symptom until someone waited for a message that had gone elsewhere.

Guarded by `SYSTEM.SEED.MANAGE`, so `SEED_ADMIN` — which holds no `SYSTEM.*` authority — cannot
reach it. Every legality check runs before the first write, and both writes are in one transaction.

**Stage D, Platform ADMIN selected-company context.** The Company workspace had two modes: own
company, or the "All companies" overview. There was no way to say "administer company X", so an
action taken from the platform-wide view had no company to act on.

Three modes now, resolved by `src/Company/SelectedCompanyContext.php`:

| Mode | Who | Scope |
|---|---|---|
| `own` | every ordinary Company Administrator | their own active company |
| `platform` | a platform administrator with nothing selected | the All-companies read overview |
| `selected` | a platform administrator who chose one | company X |

The selection lives in the session and is **re-validated on every read** against permission,
company status and universe agreement. It is never read from a request parameter: selecting is
`POST /company/context` with a CSRF token, because which company is being administered is an
authorisation boundary rather than a display preference. A stale or tampered selection is
discarded, never honoured. Administering a company creates no `company_users` membership in either
universe.

**Company Courses was also wrong and is corrected here.** The section describes itself as
"Company-owned and company-available courses" but ran `allCourses()` platform-wide and
`publishedCourses()` otherwise, so every company was shown the entire platform catalogue as though
it were theirs. `CourseRepository::companyCourses()` and `companyCoursesCount()` now share one
`COMPANY_COURSES_WHERE`:

> courses the company **owns** (`courses.owner_company_id`), plus courses it **holds credits for**
> (`course_credits.company_id`).

The consolidated workspace preview uses the same rule as the standalone section, so the two cannot
describe different populations.

## 0. v0.5.8.2 corrective round

The first authoritative VPS run of v0.5.8 failed. What it found, and what changed, is in
`CHANGELOG.md` under 2026-08-24. In short:

| Defect | Fix |
|---|---|
| Integration suite could not load: two classes declared `count()` | Renamed to `rowCount()`; `tools/check-test-suite.php` added to `composer qa` |
| Render tests could not write to a shared `/tmp` directory | Per-run private directory with a named diagnostic on failure |
| Renders left output buffers open, reported as 39 risky tests | Buffer depth recorded and unwound |
| `/admin/companies?universe=seed` 500, and a GET deactivating ADMIN membership | Read path resolves without writing; `assignUser()` validates before mutating and is transactional |

**Two rules this round established, worth keeping:**

1. A GET/read path must not mutate business membership. `AdministrationUniverseRouteIntegrationTest`
   asserts `company_users` is unchanged after loading every Administration section in every
   universe.
2. A PHPUnit test cannot guard against a class that stops PHPUnit from starting. Guards of that
   kind belong in `tools/check-*.php`, which run before the suites.

Still outstanding and unchanged: nothing in v0.5.8 has been through a green `composer qa` on
PostgreSQL, and the Integration suite has now grown to 100 tests that have still never executed.

## 1. ACL simplification decision

An independent review correctly identified that the first 0.5.7.5 ACL design over-engineered REAL/SEED isolation by duplicating every business capability as `REAL.*` and `SEED.*` permissions.

The approved correction is now the project architecture:

- **one shared business permission catalogue** describes what an identity may do;
- `SYSTEM.*` remains a separate ADMIN-only namespace for themes, settings, Roles & ACL, maintenance and future seed infrastructure;
- permission keys use resource-first/action-last uppercase dot notation, e.g. `COMPANY.PERSON.MANAGE` and `COURSE.PUBLICATION.REQUEST`;
- normal roles use `STUDENT`, `COMPANY_ADMIN`, `COURSE_EDITOR`, `COURSE_OWNER`;
- future seed identities use `SEED_STUDENT`, `SEED_COMPANY_ADMIN`, `SEED_COURSE_EDITOR`, `SEED_COURSE_OWNER`, `SEED_ADMIN`;
- normal and seed roles may hold the same business permission keys, but their role families cannot be mixed;
- seed-only roles deliberately exclude course import, export and media-management capabilities without creating duplicate SEED permission keys;
- `ADMIN` remains immutable, receives the complete catalogue and is the only eventual REAL/SEED crossover identity;
- Course Editor and Course Owner have different default capability profiles;
- platform/company request authority is separate from enrolment authority;
- company registration has an explicit `COMPANY.CREATE` capability;
- requesting publication is separate from publishing;
- duplicate `API.*` ACL permissions are retired; API/MCP uses transport scope plus the same ordinary business permission as Web;
- ordinary resource scope is determined from permissions and business relationships, not mutable role names.

The baseline migration was rebased for the corrected ACL model, so development installation still requires a database reset.

## 2. Commerce permissions reserved now

The permission catalogue already reserves the capabilities expected by the Commerce stage, although no Commerce routes/tables/services exist yet:

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

These are reservations only. Do not expose empty Commerce UI merely because the ACL keys exist.

## 3. Seed Database, as built

Present in this version, and section 9 is the authoritative boundary:

- `seed_data` and `seed_data_tables`;
- `seed_token` on 31 application tables, each with a partial index;
- generation and cleanup, both transactional;
- REAL/SEED query scope and per-universe counts on every Administration data family;
- seed-aware referential integrity enforced by PostgreSQL constraint triggers;
- the Seed Database Administration section, and the genuine-ADMIN All / Real / Seed control.

`SEED_*` roles and `SYSTEM.SEED.VIEW` / `SYSTEM.SEED.MANAGE` are no longer preparatory: they are
the identities and the authority the module actually uses.

## 4. The approved Seed Database model

Implemented as specified below. Kept here because it remains the specification the code answers
to, not because any of it is outstanding:

- Administrator supplies a description and numeric soft target volume for the complete generated set;
- each set receives a UUIDv7 token and historical/per-table counts;
- only tables capable of containing test data receive nullable indexed `seed_token` fields;
- multiple sets coexist; seed-set tokens are provenance/cleanup identifiers, not visibility boundaries;
- generated company domains use the reserved `.seed.invalid` suffix (RFC 2606), which can never
  resolve, while delivery for any seed address is re-routed to `SEED_SYSTEM_COMPANY_DOMAIN`;
- the local part of every generated address is unique across all seed identities;
- seed creation sends **zero email**; a login email is sent only when an operator explicitly requests a passwordless login for a particular seed account;
- generated identities receive `SEED_STUDENT` plus appropriate `SEED_*` roles;
- REAL and SEED visibility is enforced by repository/service queries and database integrity, not by duplicated permission names;
- REAL data can never reference SEED data and vice versa;
- genuine `ADMIN` sees both universes and receives a visible All / Real / Seed control with a
  `total · real · seed` record split; ordinary REAL and SEED identities never see the other
  universe, and neither is offered the control;
- generation and cleanup use transactions and bulk SQL;
- no Themes, role definitions, permission definitions, ACL mappings, API tokens or fake media files are generated.

After Seed Database is accepted, use representative seed data to exercise the existing LMS before beginning Commerce.

Ordinary writes inherit provenance from the resource they belong to, never from the acting
identity: a genuine `ADMIN` operating on a generated aggregate writes a SEED business row and
stays the recorded actor on it (decision D4). Course portability is REAL-only (decision D5).

**D4 amendment, owner's instruction 2026/09/09.** A genuine immutable `ADMIN` straddles both
universes and works with generated data without restriction. `course_favourites.user_id` joins the
actor allowlist so an administrator can bookmark a generated course: the catalogue exists to be
reviewed, and one that cannot be bookmarked cannot be. The exception is that column alone. A
favourite grants no access, carries no entitlement, and the row still takes its universe from the
course, so it is removed with the seed set that owns it and leaves nothing behind. An enrolment, a
company membership or a course ownership would outlive the generated data or confer something on
the identity holding it, so those remain forbidden exactly as D4 approved them.
`20260909180000_admin_may_favourite_seed_courses` narrows that table's constraint trigger to the
course reference; `SeedTableCatalog` carries the full record.

## 5. Verification state

The authoritative acceptance gate remains the VPS with Composer/PostgreSQL:

```bash
cd /usr/local/lib/php/catto-learning/current
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer migrations:status
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer themes:sync
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:unit
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:architecture
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:integration
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer qa
```

Do not claim that gate passed until those commands actually complete successfully on the VPS.

## 6. Existing non-ACL acceptance work

Keep unrelated accepted behaviour intact:

- Gilded Noir 1.1.3 remains the repaired accepted package for the home two-column composition and modal/footer stacking fixes;
- `storage/logs/application.log` records dynamic request method/path without query strings;
- core/theme assets use content fingerprints;
- themes remain filesystem-authoritative and consume core-owned navigation/workspace data;
- browser rendering remains the final visual acceptance authority.

Shared pagination was the largest item of that bounded stabilisation work and is complete in 0.5.7.6 (see section 8). Any remaining theme re-sync, responsive/browser or presentation defects should continue to be handled as bounded stabilisation work rather than folded into Seed Database without cause.

## 7. Documentation layout rule corrected

The six established project documents remain the core briefing set, but they are **not** a maximum file count. Additional audits, design proposals, reviews and working notes may be stored in the repository root or `docs/` as useful. Tests and release validators must require the core briefing documents to exist without rejecting additional Markdown files.

## 8. v0.5.7.6 implementation boundary

Complete in this version:

- one shared `Pagination` value object and one core-owned pagination control used by every standalone paginated list;
- real count queries for every paginated dataset, each using the identical membership rule as its row query;
- bounded previews on the consolidated `/admin` and `/company` workspaces, with no aggregate query per section;
- bounded htmx entity lookups replacing whole-table dropdowns on Activity, Credits and the person profile;
- the Administration course list scoped to the courses the actor owns, edits or administers.

Explicitly **not** part of this version: any Seed Database schema, `seed_token` column, generation, cleanup or REAL/SEED query filtering. Section 3 still applies unchanged.

No database schema change, no new migration and no database reset. The baseline migration differs from 0.5.7.5.1 only in its header metadata.

### Deploy-time hazards specific to this stage

Two caches key on the unchanged `current/...` paths and will keep serving the previous version after the symlink is repointed:

- Symfony's compiled container and Twig's compiled templates, because `public_html/index.php` sets the code root to the literal `current` path and never resolves it, so those cache paths do not change between versions;
- PHP's opcache, for the same reason.

Run `bin/console cache:clear` as the web server's user and reload PHP-FPM as part of every deployment. `OPERATIONS.md` carries the commands.

### Known gaps carried forward

- Gilded Noir v1.1.4 styles the pagination control and the entity lookup but has no `.acl-*` rules and no `.universe-switch` rules, so the Roles and ACL permission editor and the data-universe control both fall back to core CSS inside the dark skin. Core defines both completely, including the active state and focus ring, so each is usable without a theme update. Updating the theme requires a version number from the project owner.
- No automated test issues a real HTTP request to an HTML page; `tests/Integration/HttpRouteSmokeTest.php` covers API routes only. Browser rendering remains the visual acceptance authority.

## 9. v0.5.8 implementation boundary

Complete in this version:

- `seed_token` on 31 application tables with partial indexes, plus `seed_data` and
  `seed_data_tables`;
- one cross-universe integrity trigger function applied to 27 tables, comparing universes rather
  than set tokens so different seed sets may reference one another;
- one REAL and one shared SEED System Company, with a universe-scoped unassigned-user sweep;
- the Seed module: frozen table catalogue, volume plan, generator, repository and service;
- universe-aware reads everywhere, enforced by `DataUniverseScopeTest` rather than by review;
- the Seed Database Administration section with generation, history, cleanup preview and cleanup;
- seed mail routing to one configured inbox.

Completed in the second and third implementation rounds, and no longer outstanding:

1. **Seed provenance on ordinary writes.** `SeedProvenance` resolves a new row's universe from the
   resource it belongs to, and roughly twenty create paths across nine repositories use it. A row
   with two parents goes through `forPair()`, which refuses a cross-universe pairing by name
   rather than leaving the trigger to report a column.
2. **The visible All / Real / Seed control.** `resources/views/partials/universe-switch.html`,
   included by the seven Administration list families, showing a `total · real · seed` split and
   three links. Only a genuine non-seed `ADMIN` receives the model, so nobody else has anything to
   render.
3. **Integration tests for the Seed module.** Six classes under `tests/Integration/` covering
   schema, generation, rollback, read isolation, the PostgreSQL guards, live-write provenance,
   cleanup with cross-set collateral, login-token invalidation and decision D5.
4. **`MaintenanceRepository`, `LoginTokenRepository`** and the decision D5 refusals. The prune is
   deliberately universe-agnostic and documented as such; seed cleanup invalidates login tokens by
   identity, including a token raised against a set address before the account was resolved; export
   and import refuse a generated course and a generated identity at the service layer.

**Still outstanding**, and not coding work:

- the migration, the triggers and the whole Integration suite have never met PostgreSQL;
- browser acceptance of the universe control under Factory Reset and Gilded Noir;
- the volume pass itself, which is what this release exists to make possible.

`docs/tests-v0.5.8.md` in the workarea splits these three ways: implemented and automated,
implemented but requiring VPS execution, and manual browser/volume acceptance.

### Deploy-time requirements specific to this stage

This version rebases the baseline, so a destructive development reset is required. Two new
environment values must be set before migrating: `SEED_SYSTEM_COMPANY_NAME` and
`SEED_SYSTEM_COMPANY_DOMAIN`. The domain must differ from `APP_DOMAIN` or the migration stops with
an explanatory error, because `companies.domain` is unique platform-wide.
