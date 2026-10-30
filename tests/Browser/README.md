# UI stabilisation browser checks

These tests run Chromium against the existing development instance. They create one temporary
administrator session. Component checks select theme previews without changing the active theme;
the canonical matrix temporarily activates themes and restores the original. Both use bundled manifests' actual versions. They fail if authentication redirects or the wrong theme loads.
No Node dependency or frontend build is added; use an existing Playwright installation.

Every fixture person has a date of birth (set by `DevelopmentFixture::createUser` as registration sets
it), so no check stops at the first-sign-in date of birth step (adults only, owner, 2026-10-20). Nothing
about identity is asked before a purchase, so the checks that place orders need no setting changed.

What the checks make, and what they leave (owner, 2026-10-27: "for your own tests, you can remove that crap. but I
still need data which adheres to the business rules"). The development database holds the owner's own test data (from
Seed Database), so a check must neither break the rules nor leave anything behind:

- Every name is a real one nobody on the site has, never a string of digits (`tests/Support/CheckNames.php`): people
  (Lerato Dlamini), companies (Karoo Ridge Accounting, at karooridgeaccounting.test), courses (Payroll Basics),
  bundles, promo codes and categories.
- Every company is made with its founding Company Administrator (`DevelopmentFixture::company()`; the database refuses
  a company without one), and its staff are at its domain (`staff()`).
- Every `cleanup` removes everything the check made, its orders, payments, ledger, documents, refunds, wallets,
  reviews and activity included (`tests/Support/CheckScrub.php`): it follows the database's own links from the
  check's people, companies and courses, never through who last touched a row, puts back a design the check
  republished, and removes nothing at all if it would reach a row the check did not make. After every check, every
  table holds what it held before.

Run from the code repository on the host (adjust the instance/container paths if necessary). In the
development pod (`docs/OPERATIONS.md`, "Local development environment") Playwright is
installed on the host, so `CATTO_PLAYWRIGHT_MODULE` is `$HOME/tools/playwright/node_modules/playwright`;
the runners accept the local mkcert certificate (`ignoreHTTPSErrors`), which Playwright's Firefox does not trust:

The fixture's identity is an ADMIN who administers a test company of its own, so `/company` opens (there is no System Company, owner 2026-10-18); it also adds 110 people through Add person, for the paging step without JavaScript (three pages of 50), and publishes a course with one free preview lesson for the Bootstrap safety net's reader, so neither depends on what the database holds (owner, 2026-10-19). Cleanup removes all of it.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/ui-stabilisation-fixture.php create'
podman exec -u cattotest env_php_1 cat /tmp/catto-ui-stabilisation-state.json > /tmp/catto-ui-stabilisation-state.json
chmod 600 /tmp/catto-ui-stabilisation-state.json
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/ui-stabilisation.cjs
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/canonical-page.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/ui-stabilisation-fixture.php cleanup'
```

Always run cleanup after a failed browser check too. Delete the host copy of the session state
when finished. `CATTO_BASE_URL`, `CATTO_BROWSER_STATE` and `CATTO_BROWSER_OUTPUT` override the
component runner’s local URL, state file and screenshot/results directory. The canonical runner uses the default `/tmp/catto-ui-stabilisation-state.json`; keep that default when running both matrices. Default output is `/tmp/catto-ui-stabilisation`.
The native pagination navigation check requires the development People list to contain at least
three pages; the large development dataset supplies that fixture. The gallery supplies stable
multi-page pagination examples independently of the database size.

The matrix covers the component gallery, Reports, People, Company, Account, Catalogue and Help in
every bundled theme at 1440px and 390px. Checks measure heading content/action positions,
non-layout decoration, nowrap pagination groups and their actual shared centre line, horizontal
scrolling, opaque coloured company banners, footer identity/contrast, Reports/automatic stat-grid columns and document
overflow. Company and Account accordions are opened. Screenshots accompany the computed results.
Mutation tests recreate the flex pseudo-element, pagination wrapping and white-banner defects and
require the same browser assertions to reject them. Native Next and jump-to-page GET navigation
are exercised with JavaScript disabled. PHP ownership/rendering tests run inside `composer qa`;
run this browser matrix separately before the full QA gate after UI/theme changes.

## Canonical page construction

`canonical-page.cjs` covers every bundled theme in anonymous and authenticated states at 1440, 820
and 390px: 36 page/state/viewport combinations. It checks the shared landmarks, main/footer
siblings, semantic sections, navigation geometry, document overflow, mobile toggle and Escape,
keyboard access to nested menus, mobile Menu-label contrast, and desktop flyouts versus inline
sidebar/mobile groups.
Ten additional checks follow native catalogue links with JavaScript disabled. Full-page, open-navigation and footer
screenshots are saved beside `results.json` in `/tmp/catto-canonical-page`.

The combined sequence above runs this matrix before cleanup. To run it separately, create and copy the isolated identity first, run the following, then restore theme state and clean up:

```sh
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/canonical-page.cjs
```

Unlike the component matrix, this matrix temporarily activates each bundled theme so anonymous
requests exercise the actual shell. `canonical-page-theme.php` saves the original active theme and
the runner restores it in `finally`. Do not run multiple canonical matrices concurrently. If the
process is killed or the host reboots, restore the saved state **before** cleaning up the identity:

```sh
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/canonical-page-theme.php restore'
```

Only run that recovery command when `/tmp/catto-canonical-theme-state.json` exists inside the
container. A new run refuses to overwrite an unrestored state. Keep theme installation/publication
complete before starting either matrix. The matrices validate development source themes; they do
not build a release or regenerate distributable theme ZIPs.

The canonical construction fixes the former blank mobile row in Factory Reset Sidebar. Its frame
also accounts for margins and the desktop sidebar width, including on the gallery and Reports.
With JavaScript disabled, mobile top navigation stays in normal flow so its expanded links cannot
cover pagination or other page controls. The component matrix exercises native Next and jump
navigation as a regression for that behaviour.

## Bootstrap safety net

`bootstrap-safety-net.cjs` checks UX rule 7.10 in Chromium and Firefox, in every bundled theme
through the theme preview (nothing is activated), on the UI components page, the account page and
the catalogue, and on Core's reader (the public preview of the first catalogue course that has one,
whose `<html>` is always light), at 1440px. It reads the body's text and background, a link, the
text, background and border of a text field, a select and a text area, the accent of a checkbox, and
the keyboard focus outline four times:
- as served;
- with the operating system dark (themes keep their fixed look, and `<html>` carries
  `data-bs-theme="light"`);
- with Bootstrap's three files removed, as a browser without cascade layers sees the page;
- with every `--bs-*` token made invalid, as a browser without `light-dark()` sees it.

Each reading must equal the served one, and the page's colour scheme stays light. A theme page
must also give the canvas behind the scrollbar gutter a colour (html's, or the body's). It uses the UI matrix's identity, so create that fixture
and copy its state first (above), then run it and clean up as there:

```sh
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/bootstrap-safety-net.cjs
```

`CATTO_BROWSERS` narrows the browsers; results and failure screenshots go to
`/tmp/catto-bootstrap-safety-net` (`CATTO_BROWSER_OUTPUT`).

## Sign-up checks

`signup-checks.cjs` follows the sign-up check from the outside on the demo site (owner, 2026-10-26; UX rule 2.6h):
the Register page says the account will be held, carries its signed opening time and the hidden field (off the page)
and fills in the time zone; a person registers through it at 1440 and 390px and without JavaScript, sees the notice
again after sending, gets "Finish your registration" in Mailpit with the notice, and the link leads to the waiting page,
which promises nothing; then an administrator finds each attempt at Sign-up Attempts with its risk and "Registered,
held", opens one with its signals in words, sees the account on Needs your attention with its badge, its Sign-up check
and Recent sign-ins, People sorted by risk and the Sign-up checks settings. It registers as people with real names nobody on the site has (`signup-checks-fixture.php names`), at a domain that receives no mail,
and uses `section-pages.cjs`'s fixture to look as ADMIN:

```sh
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright CATTO_MAILPIT_URL=http://127.0.0.1:8025 node tests/Browser/signup-checks.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/signup-checks-fixture.php cleanup'
```

## Mailing

`mailing.cjs` follows news from following to stopping (mailing, step 2; owner, 2026-10-26): a learner asks for News
and Updates on My Subscriptions; an administrator writes an email in blocks (text, a button, a course, HTML), sees it in
the preview, sends a test to their own address and sends it (the check runs the `mailing` job rather than waiting for
the worker's minute); the learner's copy reaches Mailpit with `List-Unsubscribe`, `List-Unsubscribe-Post` and `List-Id`,
tracked links and the opening pixel; opening, clicking and the email app's one-click button are recorded and News stops at
once; the campaign's figures say so; Settings > Mailing saves and resets its sending discipline; without JavaScript a new
email and a block are made; and the mailing pages answer at 1440 and 390px in Chromium and Firefox. Its fixture makes an
administrator and a learner and removes them, their campaigns and their email answers afterwards:

```sh
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright CATTO_MAILPIT_URL=http://127.0.0.1:8025 node tests/Browser/mailing.cjs
```

## Dashboard amounts

`figure-fit.cjs` gives every small money amount on the ADMIN dashboard longer figures in turn (a million, a negative
million, a hundred million, a trillion, nothing) in every bundled theme at 1440 and 390px, with reduced motion and
without, and requires each to fit its card again within a moment (UX rule 2.6f, `fitFigures`). The amounts the
dashboard shows depend on the day's data, so the menu pages found a spill only by chance (Gilded Noir, whose transition
on every property made the refit read an amount's old size, 2026-10-26); this finds one on purpose. It changes text in
the browser only and uses `section-pages.cjs`'s fixture; `CATTO_THEMES` and `CATTO_WIDTHS` narrow a run:

```sh
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/figure-fit.cjs
```

## Card grids

`card-grids.cjs` checks the lists that can be shown as cards (owner, 2026-10-26; UX rule 2.6g) in every bundled theme at
1440 and 390px through the theme preview, each theme and width in a fresh browser: Administration's People, Companies,
Course Creators and Courses open as tables and the catalogue as cards; each grid shows whole rows (5 people or
companies, 4 courses at 1440px, at most 2 at 390px), its own page sizes and its Sort, and every picture it asks for
arrives; the choice is remembered without the address and the list comes back when chosen; the catalogue's list holds on
a tag's page; every view passes the shared page checks (`page-construction.cjs`); in the first theme every switch changes
only its own list without loading the page (Administration's lists, the catalogue, the Company workspace's people,
Subscribers, a company's people and courses, a presentation's slides) and keeps the focus on the button pressed; and, in
the first theme, the switch and the Sort work without JavaScript. It uses `section-pages.cjs`'s fixture (below) and writes screenshots and
`results.json` to `/tmp/catto-card-grids` (`CATTO_BROWSER_OUTPUT`); `CATTO_THEMES` and `CATTO_WIDTHS` narrow a run:

```sh
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/card-grids.cjs
```

## Section pages

`section-pages.cjs` opens every Account, Company and Administration menu page (60, Sign-up Attempts and Company Profile added 2026-10-26, and the fixture company's page) in every bundled
theme at 1440 and 390px through the theme preview, so nothing is activated. It checks the one
construction every page shares (UX rule 1.7, `PageStructureContractTest`): exactly one `h1`; only page
rows directly in main (flash stack, page head, identity band, page sections, a theme's page-family
wrapper, form windows); no words standing on the page texture; the first card's edge on the page
head's text edge; the same gap above the footer on every page of a theme; no sideways scrolling;
nothing standing out past a table's edge; and no action control cut off or squeezed by its cell
(UX rule 2.8). Screenshots and `results.json` go
to `/tmp/catto-section-pages` (`CATTO_BROWSER_OUTPUT`); `CATTO_THEMES` and `CATTO_WIDTHS` narrow a run.
Its identity is an ADMIN who administers a test company of its own, because there is no System Company (owner, 2026-10-18) and the Company workspace needs a company; cleanup removes both:

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/section-pages-fixture.php create'
podman exec -u cattotest env_php_1 cat /tmp/catto-section-pages-state.json > /tmp/catto-section-pages-state.json
chmod 600 /tmp/catto-section-pages-state.json
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/section-pages.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/section-pages-fixture.php cleanup'
```

## Course Content tree

`course-content-tree.cjs` drives the Course Content editor in Chromium and Firefox, then Chromium
with JavaScript disabled: drag before, after and inside rows, section and subtree moves, refused
drops (depth and into-itself), the three drop indicators, keyboard moves on the handle, ⋯ menu
moves without a page load, collapse state kept for the session, a failed save restoring the tree,
"+ Add here" through the insert modal (Add section, Add existing item and Create new item at exact
places, a refused key keeping the entry and rich text, Escape/Cancel/close changing nothing, an
empty course, dragging a just-inserted row), and the no-JavaScript ⋯ menu, Move into page and
"+ Add here" form pages. Each
scenario resets the fixture course (`reset`; `empty` removes every row) and reads the saved tree back
from the server.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/course-content-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/course-content-tree.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/course-content-fixture.php cleanup'
```

The runner reads the fixture state and resets the tree through `podman exec` (`CATTO_PHP_CONTAINER`,
default `env_php_1`). `CATTO_BROWSERS` (default `chromium,firefox`) and `CATTO_BROWSER_OUTPUT`
(default `/tmp/catto-course-content-tree`) override the browsers and screenshot directory.

## Category tree

`category-tree.cjs` drives `/admin/courses/categories` in Chromium and Firefox (10 scenarios each),
then Chromium without JavaScript and every theme: the page opening on the main categories with
every branch closed; branches opening and closing independently and remembered for the session;
dragging to reorder main categories, into another category and back out (with the counts above the
tree following), a sub-subcategory between subcategories and a whole subtree; a fourth level never
offered, a drop onto a category's own subcategory refused, and a forged move refused by the server
with the tree unchanged; a drop onto a closed category opening it; keyboard moves on the handle and
a ⋯ menu move; Add category, Add subcategory and a sub-subcategory through the modal; Manage
returning to the tree. Without JavaScript the disclosure buttons, a ⋯ menu move, Move into, the
Add subcategory page, the inline Add category form and Manage work as ordinary requests. Every theme
renders the tree open at 1440 and 390px without sideways scrolling. The shared sortable-tree code
is the Course Content tree's too, so run both checks after changing it.

The fixture builds `A > A1 > A1x, A > A2 · B > B1 · G · D > D1 > D1x` after the shipped taxonomy
(each a real category name no category on the site has, such as Workplace Skills); `reset` deletes every category
the run made, renumbers the shipped ones and rebuilds it before each scenario, and `cleanup` removes everything the run made.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/category-tree-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/category-tree.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/category-tree-fixture.php cleanup'
```

## Category picker

`category-picker.cjs` drives the shared category picker (`form.tree-select`) in Chromium and Firefox
(Chromium also covers saving, import and the catalogue), then without JavaScript and in every
theme: the picker opening on Uncategorised and the main categories with every branch closed;
branches opening and closing independently; choosing a sub-subcategory and seeing its full path;
reopening with only the way to the choice open; choosing Uncategorised; + New creating a main
category, a subcategory and a sub-subcategory at the place chosen in its own picker (the place
changed before saving, sub-subcategories never offered, the new category chosen afterwards, Cancel
keeping the previous choice, a refusal keeping what was typed); a forged fourth level refused by
the server; the keyboard opening the picker and a branch and choosing; a course saved with a
category at two levels and then Uncategorised; an import filed in a category created during the
import; and the catalogue's picker going to the category chosen. Without JavaScript the whole tree
is listed and a course is saved with a sub-subcategory. Every theme opens the picker at 1440 and
390px without it leaving the screen or the page scrolling sideways.

It uses `category-tree-fixture.php` (the same test tree, after the shipped taxonomy); `reset` also
deletes the courses and Course Items the run created or imported. The theme scenario requests
reduced motion and scrolls with `behavior: 'instant'`: a smooth scroll still running under
Playwright moves the anchored popover.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/category-tree-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/category-picker.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/category-tree-fixture.php cleanup'
```

## Course reviews

`course-reviews.cjs` checks course ratings and moderated reviews in Chromium and Firefox and
without JavaScript (9 checks): the public course page shows only the approved review, its rating
summary and the reviewer's first name and initial; the five-star control is one labelled radio
group that the arrow keys move through, with the chosen stars filled and a visible focus ring; a
submitted review shows as pending and stays off the public page until ADMIN approves it on
`/admin/course-reviews`; both pages fit a 390px phone without sideways scrolling; and the form
submits with JavaScript disabled. `course-review-fixture.php` creates a published course, an
entitled learner, a second learner with an approved review and an ADMIN; the runner resets the
learner's review through `podman exec` before each scenario.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/course-review-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/course-reviews.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/course-review-fixture.php cleanup'
```

## Popular courses

`popular-courses.cjs` checks the home page's rotating Popular Courses in Chromium and Firefox and
without JavaScript (13 checks): groups change every interval in order and cycle back with no
request to the server; Pause and Resume work from the keyboard and keep focus on the control; the
pointer over the grid or focus inside it holds the current group, and Tab never reaches a hidden
group; reduced motion starts paused and changes groups without the fade; a favourite star keeps
its state across a full cycle; a card link opens its course; one group is one row of four cards
at 1440px and the page does not scroll sideways at 390px; and without JavaScript the first group
is shown and nothing else. Time is Playwright's fake clock, so the 20-second interval is jumped
rather than waited for. `popular-courses-fixture.php` creates the signed-in learner whose favourites it toggles and twelve
published courses of its own, three groups of four, so the check does not depend on what the
database holds (owner, 2026-10-19); cleanup removes them.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/popular-courses-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/popular-courses.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/popular-courses-fixture.php cleanup'
```

## Billing profiles

`billing-profiles.cjs` checks billing profiles in Chromium and Firefox, without JavaScript and in
every bundled theme (10 checks): a learner's Account billing details and a company administrator's
company Billing details save and survive a reload; the learner's checkout is filled from the saved
profile, a correction there is saved to it, and the placed order shows the billing it was placed
with, still after the profile changes, with its invoice PDF opening; the company credit checkout is
filled from the company's details and its order is billed to the company; both forms save as
ordinary forms without JavaScript and fit a 390px phone; and an ADMIN sees both forms in every
bundled theme at 1440 and 390px without overflow. `cleanup` removes everything the run made, its orders included.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/billing-profiles-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/billing-profiles.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/billing-profiles-fixture.php cleanup'
```

## Promo codes

`promotions.cjs` checks promo codes at the checkout review in Chromium and Firefox: a valid code
typed in lower case and applied with Enter shows the subtotal, the promotion and the discounted total;
an unknown and an expired code say why and leave the applied code; removing it with the keyboard
restores the total; placing the order charges the discounted total and the order page and invoice
keep it; and the review fits a 390px phone. Without JavaScript the code is applied, removed and used
as ordinary forms. An ADMIN creates a promotion (the code stored upper case), has a rejected value
kept for correction, edits, deactivates it (checkout then refuses it), reactivates, finds it by search
and status, and deletes it while unused. The checkout promo section, the promotions list and the
editor render in every bundled theme at 1440 and 390px without sideways scrolling.
`promotions-fixture.php create` makes two paid courses, an ADMIN and two promotions; the script asks
it for a fresh learner per purchase and runs `create` and `cleanup` itself; `cleanup` removes everything the run
made, its orders, the promotions the administrator made on the pages and the automatic one included.

```sh
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/promotions.cjs
```

## Course bundles

`bundles.cjs` has an ADMIN create a bundle, add four courses through the course lookup, move one up,
remove one, set the price and publish it. In Chromium and Firefox a learner sees the bundle in
`/bundles` and on its page (the catalogue course cards, the price, the individual prices and the
saving), adds it to the cart with the keyboard, applies a promotion for all bundles at checkout,
places the order and finds every course in the library, with the order page and invoice showing the
bundle as the item bought; the bundle page and cart fit a 390px phone. A learner who already has one
of the courses is told so on the bundle page, and a company account (owner, 2026-10-18) reads why it
cannot buy and is offered no buttons. Without JavaScript a bundle is bought and an ADMIN
reorders its courses. The bundle catalogue and page, the cart and the ADMIN list and editor render in
every bundled theme at 1440 and 390px without sideways scrolling. The script creates and cleans up
`bundles-fixture.php` itself; `cleanup` removes everything the run made, its orders, bundle and promotion included.

```sh
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/bundles.cjs
```

## Certificates

`certificates.cjs` checks certificate designs and certificates: 11 scenarios in Chromium, Firefox,
without JavaScript and in every bundled theme. Each preview is a PDF in a frame; the check reads its
text with Ghostscript (`gs` on the host) and compares pictures by rasterising them. Headless Chromium
treats a PDF frame as a download, so in Chromium the check routes preview requests to keep their
bodies; a routed request loses its uploaded file, so the upload runs in Firefox, which returns the
body directly. Pages count as loaded at DOMContentLoaded, because a success message hides itself
after 4.5 seconds.

- **List.** The six installed designs are listed with their pictures, one of them (Classic on a new site, or whichever ADMIN chose) the default for new courses, the navigation leads there, and the page says nothing of states, versions, HTML or CSS.
- **Editor.** ADMIN picks Modern by picture, types wording and inserts the Date issued field with Insert field (CKEditor), adds a signatory; each change redraws the PDF preview, typing the name does not, and the field is stored as a field. Save keeps the look, wording and signatory.
- **Course page.** The course picks that design by picture and sets its accreditation line; the previews show this course's certificate with both, and Save keeps them. Edit this design carries `return_to`.
- **Learner.** A verified learner (the fixture gives the service Didit's answer, as Didit's webhook would: there are no Didit keys on a development machine) completes the final in the reader, opens View certificate from the course page, sees the details and the PDF with no result, and downloads one-page PDFs from the certificate page and the course page that print their name, the course, the design's wording, the accreditation line and the signatory; View transcript shows the mark and the overall grade, with its PDF.
- **Pass or fail, verified only.** The second learner gives the wrong answer: the course page says "You have completed every assessment. Verify your identity to receive your certificate and transcript." with Verify my identity and no certificate; once verified (`certificates-fixture.php verify`), the certificate is there and the transcript records 0.00%.
- **History.** Editing the design (opened from the course, returning there) changes the next learner's certificate and leaves the issued PDF's text exactly as it was; ADMIN sees the design it was drawn in.
- **Upload (Firefox).** The sample Canva-sized background (`design/certificate-samples/01-classic-background.png` in the workspace, or `CATTO_CERTIFICATE_BACKGROUND`) becomes the look; the preview differs from Classic, then follows the placement and typeface; after saving, Your own background is chosen and shows its picture (scrolled to first: the look pictures load lazily below the preview), also on the list.
- **Duplicate, default and delete.** A duplicate becomes the default for new courses and is deleted with Classic as the replacement; deleting the course's design moves the course to Minimal, and the issued certificate still prints exactly as it did.
- **No JavaScript.** A design without the Learner name field is refused with the typed words kept; typed `[Learner name]` and `[Course title]` fields preview and save; the course page saves a design.
- **Access.** A learner is refused the design pages, the course certificate page and the previews.
- **Themes.** The list, an editor, the course page and the certificate page fit every bundled theme at 1440 and 390px; at 1440 the editor's and the course page's preview is above the form at nearly the full width (UX rule 6.12).
- **Firefox.** CKEditor's Insert field fills the preview, and the certificate page shows and serves the same PDF.

`cleanup` removes the course, its enrolments and certificates and the identities, makes the recorded default design the default again, and removes the run's designs with their versions.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/certificates-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/certificates.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/certificates-fixture.php cleanup'
```

## Financial documents

`financial-documents.cjs` checks invoices, receipts and credit notes on the document template engine:
9 scenarios in Chromium, Firefox, without JavaScript and in every bundled theme. Previews and
downloads are PDFs whose text is read with Ghostscript (`gs` on the host); in Chromium preview
requests are routed so their bodies can be read, as in `certificates.cjs`.

- **Design page.** ADMIN opens Commerce → Financial Documents (nothing of templates, versions or HTML on it); the preview follows the Document and Sample order choices (the learner's receipt by EFT, the credit note of a refunded bundle, a company's credit note, an invoice a 100% promotion made free) and the form (a note, a typeface and a colour).
- **Learner.** The learner downloads the invoice (courses, the bundle with its courses, the promotion, the billing snapshot), the receipt and the credit note of their own order, and the invoice for nothing of a free order, which has no receipt.
- **History.** ADMIN saves a new invoice note: the learner's documents download exactly as before and the ADMIN order page names the version that drew them, while an order placed afterwards (`financial-documents-fixture.php order`) is drawn in the new design and names the next version.
- **ADMIN.** The order pages serve the EFT receipt (bank reference and amount) and the company's invoice (the company billed, its administrator as purchaser, the credits per unit).
- **Access.** Another learner is refused the design page, its previews, the learner's invoice and the ADMIN document route.
- **No JavaScript and keyboard.** Update preview draws the unsaved form into the frame and Save keeps it; a document is chosen with the arrow keys and Save is reached with Tab.
- **Themes.** The design page and an ADMIN order page fit every bundled theme at 1440 and 390px, with the preview an A4 portrait page above the form (UX rule 6.13).
- **Firefox.** The preview follows the sample order choice and a receipt downloads as in Chromium.

The fixture places its orders through the real commerce services (a card payment, a 100% promotion, an EFT confirmed by ADMIN, a company credit purchase and a refund), so their documents are issued as a checkout issues them. `cleanup` removes everything the run made, its orders, documents and refund included, and the design goes back to the version from before the run.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/financial-documents-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/financial-documents.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/financial-documents-fixture.php cleanup'
```

## Course landing pages

`landing-pages.cjs` checks course marketing landing pages (Phase L) in 13 scenarios:

- **Editor.** ADMIN opens Landing page from the course editor's tools, creates the page (the hero and eight sections, Publish waiting for the empty lists), writes the hero, fills Who this course is for, Learning outcomes and a question, hides and shows Benefits, adds a call to action from Add section (Pricing is refused as already on the page) and removes it from its ⋯ menu.
- **Order.** A section dragged by its handle and another moved with ↑ on its handle are saved and survive a reload; without JavaScript the ⋯ menu's Move to bottom moves a section; Firefox repeats a drag.
- **Preview and publication.** Preview opens in a new tab with the buttons disabled, `noindex` and no canonical address; Publish makes the page public and the course editor says so; Unpublish takes it down (not found) and keeps every section, and a page that has been public offers no Delete.
- **Visitor.** The published page shows the hero, the lists, the outline (never the fixture's lesson, bonds or question text), the approved review, both prices and a question that opens; its canonical address is set. Add to cart from the hero returns to the landing page, with JavaScript and without. A learner who has the course is offered Continue course and no Add to cart; a course with no active price says it is not on sale.
- **Themes.** The page, the editor and a section form fit every bundled theme at 1440 and 390px without sideways scrolling; the hero's Add to cart is whole and at least 32px tall, and the buy box's price has a contrast of at least 4.5:1 against its card.

The fixture makes two published courses (one with two prices, an outline with protected content and an approved review; one not on sale with a published landing page), an ADMIN, a course owner, a learner who has the first course and one who does not. The check places no order, so `cleanup` removes everything.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/landing-pages-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/landing-pages.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/landing-pages-fixture.php cleanup'
```

## Course parts and CatWalk

`course-parts.cjs` checks the course structure and CatWalk presentations (owner, 2026-10-28, decisions 1A to 7A) in Chromium and Firefox at 1440 and 390px (134 checks):

- **The outline.** The module's section reads "Module 1: <title>", each part shows its kind (Presentation, Lesson, Summary, Flash cards, Practical work) and the module its time.
- **A presentation.** CatWalk mounts it; Next reveals a slide's points one at a time and then moves on; the quick question marks the right and wrong answers and opens the explanation; Contents lists and jumps to every slide and Escape closes it; the last slide's Continue leads to the next part; nothing scrolls sideways. Two presentations in one lesson each answer only their own keys.
- **Flash cards, practical work and PDFs.** A card turns over, Again brings it back later and the set ends when every card is known; the model answer starts closed and opens; the lesson, summary, flash cards and practical work each download as a PDF.
- **Without JavaScript.** The presentation reads as one page of every slide and the flash cards as a list; in the editor, Add a slide and Remove slide send the form back without saving anything.
- **Narration (Chromium).** The editor's preview plays the deck and every slide has its recorder; a recording made with a stand-in microphone is kept, the author hears it, a visitor is refused it, and the reader offers Narration.

The fixture makes an ADMIN, a provider company and a course with a numbered module of every part (made through `CourseItemService`, as "+ Add here" does) and a second module; its clean-up removes everything it made, the narration files included.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/course-parts-fixture.php create'
podman cp env_php_1:/tmp/catto-course-parts-state.json /tmp/catto-course-parts-state.json
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/course-parts.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/course-parts-fixture.php cleanup'
```

## Legal pages and terms acceptance

`legal-pages.cjs` checks the public legal pages and the checkout acceptance box (77 checks):

- **Every theme, signed out and signed in, at 1440, 820 and 390px (36).** `/terms`, `/refunds` and `/privacy` answer in that theme without a redirect, with their title and last-updated date (no version since 2026-10-17); every entry of the contents list is a card with a heading; all prose is on a card; no label of a label and value list runs into its value; nothing scrolls sideways; and the Core footer shows Terms, Refunds and Privacy.
- **Every theme at the three widths, both checkouts (36).** The learner's review step and the company credit checkout show the one acceptance box: required, unticked, worded "I have read and accept the Terms and Conditions and the Refund Policy.", with both documents linked from the label in a new tab, whole, inside the form and before the button that places the order.
- **Chromium and Firefox (4).** Place my order without the tick places nothing; the Terms link opens in a new tab and leaves the checkout where it was; a form that lost its `required` attribute is refused by the server with the reason, for the learner and for the company; the details step has no identity number field.
- **Without JavaScript (1).** The three pages read on a phone, the footer link leads to the Terms and a contents entry jumps to its section.

Each bundled theme is activated in turn (`legal-pages-fixture.php theme <key>`), so signed-out requests are drawn in it, and the theme that was active before the run is put back even when a check fails. Do not run it beside the canonical matrix or any other check that switches themes. The script creates and cleans up its own fixture (a paid course, a learner, a company administrator and an ADMIN); it places no order, so cleanup removes everything. If a run is killed, run the cleanup yourself: it also restores the theme. Screenshots and `results.json` are written to `/tmp/catto-legal-pages` (`CATTO_BROWSER_OUTPUT`).

```sh
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/legal-pages.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/legal-pages-fixture.php cleanup'   # only after a killed run
```

## Wallets, refunds and commerce settings

`demo-site.cjs` checks a demo site (`APP_SITE_MODE=demo`, owner 2026-10-18). catto.test is a demo site: `env/.env` says `APP_SITE_MODE=demo`.

- **Chromium (6).** Signed out, the home page, catalogue, a course, sign-in and the Terms say they are a demo site and carry `noindex, nofollow` in their robots meta and `X-Robots-Tag`; the notice shows without JavaScript; checkout offers EFT and then the simulated card (Settings → Payment methods) and says the purchase is a simulation; an EFT order says it is a simulation, shows fake bank details labelled as fake and the account reference, and its invoice says the same (checked by `demo-site-fixture.php order`); a sign-in email starts with `[DEMO]` and says it comes from a demo site (Mailpit, `CATTO_MAILPIT_URL`). ADMIN sets Site mode to live: no notice, no demo robots rule, only EFT at checkout, and the earlier order still a simulation; Reset returns to `APP_SITE_MODE`. In development (`APP_ENV`), Symfony itself sends `X-Robots-Tag: noindex`, so the live check looks only for the absence of the demo site's own rule.
- **Firefox (1).** The notice and the checkout statement.
- **Every theme at 1440, 820 and 390px.** The home page, catalogue, a course and the order show the notice, with one `h1` and no sideways scrolling. Each theme is activated in turn and the one that was active is put back.

The script creates and cleans up its own fixture (two paid courses, an ADMIN and a learner); cleanup puts back the site mode and removes everything the run made, its orders and their documents included. Phone screenshots go to `/tmp/catto-demo-site` (`CATTO_BROWSER_OUTPUT`).

```sh
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/demo-site.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/demo-site-fixture.php cleanup'   # only after a killed run
```

`commerce-wallets.cjs` checks the launch commerce work of 2026-10-17, the money records of 2026-10-18 and Record EFT Payment, Unmatched Money and the EFT buyer's email of 2026-10-19:

- **Chromium and Firefox, a fresh learner each (14).** A private learner is offered no Request from my company. A company learner (owner, 2026-10-18) is offered only Request from my company, with the reason it cannot buy; it has no cart in the header, checkout sends it to My Cart with that reason, and its request reaches its company. The learner's R200.00 of wallet credit (the fixture makes it as a person would: the learner orders a R100.00 course by EFT, ADMIN records R300.00 from the bank and marks the overpayment dealt with) pays part of a R300.00 course and the rest is chosen as EFT; checkout keeps the form until both boxes are ticked; the order page quotes the account reference and the R100.00 still to pay. The check runs the commerce worker once (`commerce:maintain`) and reads the learner's "Order CL-… received: how to pay by EFT" in Mailpit: the account and order, R200.00 paid and R100.00 to pay, the account reference as the payment reference, the demo site's fake bank details labelled, the deadline, when the course starts and the invoice attached (owner, 2026-10-19). ADMIN records an EFT of R150.00 (Chromium: on Record EFT Payment, where the account is found from the reference on the statement and the order has its own button; Firefox: on the order's page): the course starts and R50.00 goes to the wallet, which My Wallet and its statement show, and the dashboard says the course started on payment. The ADMIN dashboard lists the overpayment; ADMIN opens it, sends the prepared email (Mailpit receives it, `CATTO_MAILPIT_URL`) and marks it dealt with. The learner asks for money back on the order page; ADMIN approves it at Refund Requests with an override reason, the wallet part goes back to the wallet, and ADMIN records the payout on the order. Start course on an ADMIN-assigned course needs its confirmation box, and a form without it is refused by the server.
- **Without JavaScript (1).** A refund request is sent and withdrawn, and Start course works, as plain forms.
- **Commerce settings (1).** A Stitch fee saved in its section of Administration → Settings is printed by the Refund Policy, a reminder longer than the credit expiry is refused with its reason, and Reset brings back the `.env` default.
- **Money records (1).** The dashboard's Money received figure opens the Money Ledger filtered to payments; a search by account reference finds the learner's payment and overpayment; an entry opens on its own page. The order's page lists its Money Ledger lines, Payment Events and Commerce Trail together; a payment event and a trail entry open on their own pages, whose way back is the order; Credits & Orders lists Record EFT Payment and Unmatched Money but not Payment Events or the Commerce Trail.
- **Unmatched money (1).** A deposit whose reference names nobody is put on Unmatched Money from Record EFT Payment; its page says what arrived; the dashboard and the list show it until ADMIN records that it was paid back.
- **Every theme at 1440, 820 and 390px (288).** My Wallet, the learner dashboard, the learner's order, the three legal pages, the ADMIN dashboard, Settings, Refund Requests, Wallets, a learner's wallet, the ADMIN order page, Orders & Payments, the Money Ledger, Commerce Trail, Payment Events, Record EFT Payment and Unmatched Money answer in that theme with one `h1` and no sideways scrolling. Learners cannot preview a theme, so each theme is activated in turn (`commerce-wallets-fixture.php theme <key>`) and the theme that was active is put back even when a check fails; do not run it beside another check that switches themes.

The script creates and cleans up its own fixture (three paid courses, an ADMIN, a learner per scenario with wallet credit from a real overpaid EFT order and a course ADMIN assigned, and a member of a client company); no fixture writes a record a person could not have produced (owner, 2026-10-19); cleanup puts back every commerce setting and removes everything the run made, its orders, ledger and wallets included. Phone screenshots are written to `/tmp/catto-commerce-wallets` (`CATTO_BROWSER_OUTPUT`).

```sh
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/commerce-wallets.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/commerce-wallets-fixture.php cleanup'   # only after a killed run
```

## Order links and error pages

`order-links.cjs` checks, in Chromium and Firefox, a buyer's order links opened by someone else and the error pages (owner, 2026-10-23: as ADMIN, a link in a buyer's email gave "403 Request failed This order belongs to another purchaser"): ADMIN opening the buyer's order lands on the same order in Administration, told whose it is, and the buyer's invoice link gives the same PDF from Administration; the buyer still opens their own order; anyone else, and an order that does not exist, finds no such order in their account (a 404 that names nobody); a signed-in reader refused a page keeps their header and identity band, with Go to your account and Sign out; a missing page is built like every other page (the page construction checks), in plain words. It uses the financial documents fixture, whose orders are placed through the real checkout:

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/financial-documents-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/order-links.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/financial-documents-fixture.php cleanup'
```

## Choosing the courses to export

`catalogue-export.cjs` checks Administration → Courses → Import & Export Catalogue's tick boxes in Chromium and Firefox and without JavaScript (owner, 2026-10-23: ticking by hand did not change the count, and Clear the ticks did nothing after ticking by hand): the count follows boxes ticked by hand; Clear the ticks, Tick every course and Tick the published courses tick in place, without reloading; without JavaScript each of the three reloads the page with the boxes ticked; and the package downloaded holds exactly the course ticked by hand. It uses the section pages fixture's ADMIN session:

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/section-pages-fixture.php create'
podman exec -u cattotest env_php_1 cat /tmp/catto-section-pages-state.json > /tmp/catto-section-pages-state.json
chmod 600 /tmp/catto-section-pages-state.json
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/catalogue-export.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/current && php tests/Browser/section-pages-fixture.php cleanup'
```

## One learner buys one course

`one-learner-scenario.cjs` builds an example site on a demo site with a clean database, only through the pages, and leaves it all to follow. It needs the site's catalogue package (`CATTO_CATALOGUE`, by default the private `CATALOGUE/cattolms-catalogue.zip` beside the code tree; the file published with the code holds only categories and tags):

1. ADMIN signs in with the link Mailpit receives for `APP_ADMIN_EMAIL` (never printed); the first sign-in makes the ADMIN account, which then gives its date of birth (adults only).
2. ADMIN sets the card provider's transaction fee to 3% (Settings → Commerce fees).
3. ADMIN imports the package at Courses → Import & Export Catalogue: the review lists every course as new, and Import brings the categories as a tree and the tags with their icons, and every course with its code, category, tags, cover, position, related courses and the package's 12-month price and was price, published.
4. ADMIN adds the learner Thandi Nkosi, a made-up person, under People, with the account notification email.
5. Thandi signs in with her own link and, her account having been made by ADMIN, gives her date of birth first (a date that makes her 17 is refused first). She puts one course (`CATTO_BOUGHT`, or the catalogue's first) in her cart; checkout asks nothing about her identity. She gives her details at checkout and pays with the simulated card, which stands in for the card provider on a demo site; the course starts at once.
6. ADMIN's order page is read for its ledger lines (the payment and the provider's 3% fee), payment events, trail and documents (invoice and receipt).
7. Thandi's order confirmation, with her receipt attached and her account reference, and the email saying her course has started, with its link, arrive in Mailpit (the commerce worker sends them within a minute; the script waits up to two and a half minutes), and every email sent is listed.
8. The catalogue package downloaded from Import & Export Catalogue holds the same categories, tags and courses, each with its address, category, position, related courses, tags and prices.

`CATTO_BASE_URL`, `CATTO_MAILPIT_URL` and `CATTO_ADMIN_EMAIL` point it at another site, such as a fresh test site installed with `deploy/podman/` (2026-10-23: run there against its own database and Mailpit, all 11 steps passed).

It refuses to run when one of the courses is already on the site. Screenshots, `results.json` and `summary.json` go to `/tmp/catto-one-learner-scenario` (`CATTO_BROWSER_OUTPUT`). Rebuild the database (`docs/OPERATIONS.md`) and make the theme you want active first.

```sh
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright CATTO_MAILPIT_URL=http://127.0.0.1:8025 node tests/Browser/one-learner-scenario.cjs
```

## A course's page

`course-page.cjs` (2026-10-30) opens a published course of two numbered modules with a cover, a price and a trailer in its free preview, in Chromium and Firefox at 1440 and 390px: the title over the cover, one buy box (under Course access), the modules in order with the paragraph on how a module is made and the free preview marked, no sideways scrolling; Watch course presentation opens the trailer in a window as wide as the page's box with its slides on screen, and Esc, the Close button, the × and the slides' Get this course (which then shows Course access) each close it; without JavaScript the button opens the trailer in the free preview. Its fixture makes the course through the services Course Content calls and removes it, and removes what it made if a step is refused.

```sh
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/course-page-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/course-page.cjs
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/course-page-fixture.php cleanup'
```

## Every page, by following links

`page-construction.cjs` holds the checks both share: one `h1`, only page rows in main, no words on the texture, the first card's edge on an unboxed page head's title, every painted notice, page head and identity band with the cards' edges (UX rule 1.7a), no sideways scrolling, nothing standing out of a table, and a pagination bar that fits a desktop line.

`presentation-editor.cjs` checks the presentation editor (owner, 2026-10-30) on the course parts fixture: the presentation's page (preview, list and grid, drags up and down and left and right, keyboard and ⋯ menu moves, Up next kept last, copy and delete) in Chromium and Firefox; Add a slide, a slide's page (the row of slides, Preview, Change layout keeping what was typed, a refused Save, Close asking first), the script saved in place and the details page in Chromium; a recording discarded, kept, still there after a reload and removed (a stand-in microphone); every new page built as every page is at 1440 and 390px; and without JavaScript the ⋯ menu, Add a slide, Preview and Change layout.

```sh
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/course-parts-fixture.php create'
podman cp env_php_1:/tmp/catto-course-parts-state.json /tmp/catto-course-parts-state.json
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/presentation-editor.cjs
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/course-parts-fixture.php cleanup'
```

`every-page.cjs` follows every link on the site from the home page, the catalogue, Administration, Account and Company, as an ADMIN who also runs a company and as a visitor, at 1440 and 390px, and checks each page with the same tests `section-pages.cjs` applies to the menu pages (`page-construction.cjs`): one h1, only page rows directly in main, no words on the page texture, the first card's edge on the page head's (or its picture's) edge, no sideways scrolling, nothing standing out of a table, no action cut off by its cell; and that no link leads to an error page (a failure names the page the link was on). Pages that differ only in an id are visited twice at most. Home, course landing pages, the course reader and its public preview are built another way on purpose and are not judged. It uses the section pages fixture's session:

```sh
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/section-pages-fixture.php create /tmp/catto-section-pages-state.json'
podman exec env_php_1 cat /tmp/catto-section-pages-state.json > /tmp/catto-section-pages-state.json
CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/every-page.cjs
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/section-pages-fixture.php cleanup /tmp/catto-section-pages-state.json'
```
