# UI stabilisation browser checks

These tests run Chromium against the existing development instance. They create one temporary
administrator session. Component checks select theme previews without changing the active theme;
the canonical matrix temporarily activates themes and restores the original. Both use bundled manifests' actual versions. They fail if authentication redirects or the wrong theme loads.
No Node dependency or frontend build is added; use an existing Playwright installation.

Run from the v0.8 repository on the host (adjust the instance/container paths if necessary). In the
Linux Mint development pod (`docs/OPERATIONS.md`, "Local development environment") Playwright is
installed on the host, so `CATTO_PLAYWRIGHT_MODULE` is `$HOME/tools/playwright/node_modules/playwright`;
the runners accept the local mkcert certificate (`ignoreHTTPSErrors`), which Playwright's Firefox does not trust:

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/ui-stabilisation-fixture.php create'
podman exec -u cattotest env_php_1 cat /tmp/catto-ui-stabilisation-state.json > /tmp/catto-ui-stabilisation-state.json
chmod 600 /tmp/catto-ui-stabilisation-state.json
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/ui-stabilisation.cjs
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/canonical-page.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/ui-stabilisation-fixture.php cleanup'
```

Always run cleanup after a failed browser check too. Delete the host copy of the session state
when finished. `CATTO_BASE_URL`, `CATTO_BROWSER_STATE` and `CATTO_BROWSER_OUTPUT` override the
component runner’s local URL, state file and screenshot/results directory. The canonical runner uses the default `/tmp/catto-ui-stabilisation-state.json`; keep that default when running both matrices. Default output is `/tmp/catto-ui-stabilisation`.
The native pagination navigation check requires the development People list to contain at least
three pages; the large development dataset supplies that fixture. The gallery supplies stable
multi-page pagination examples independently of the database size.

The matrix covers the component gallery, Reports, People, Company, Account, Catalogue and Help in
all five bundled themes at 1440px and 390px. Checks measure heading content/action positions,
non-layout decoration, nowrap pagination groups and their actual shared centre line, horizontal
scrolling, opaque coloured company banners, footer identity/contrast, Reports/automatic stat-grid columns and document
overflow. Company and Account accordions are opened. Screenshots accompany the computed results.
Mutation tests recreate the flex pseudo-element, pagination wrapping and white-banner defects and
require the same browser assertions to reject them. Native Next and jump-to-page GET navigation
are exercised with JavaScript disabled. PHP ownership/rendering tests run inside `composer qa`;
run this browser matrix separately before the full QA gate after UI/theme changes.

## Canonical page construction

`canonical-page.cjs` covers all five themes in anonymous and authenticated states at 1440, 820
and 390px: 30 page/state/viewport combinations. It checks the shared landmarks, main/footer
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
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/course-content-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/course-content-tree.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/course-content-fixture.php cleanup'
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
(named "Tree Alpha <suffix>" and so on); `reset` deletes every category the run made, renumbers
the shipped ones and rebuilds it before each scenario, and `cleanup` also removes the identity.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/category-tree-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/category-tree.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/category-tree-fixture.php cleanup'
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
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/category-tree-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/category-picker.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/category-tree-fixture.php cleanup'
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
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/course-review-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/course-reviews.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/course-review-fixture.php cleanup'
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
rather than waited for. The cards are whatever the development database's popularity snapshot
holds, so run `php bin/console popularity:recalculate` first on a fresh database.
`popular-courses-fixture.php` creates the signed-in learner whose favourites it toggles.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/popular-courses-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/popular-courses.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/popular-courses-fixture.php cleanup'
```

## Billing profiles

`billing-profiles.cjs` checks billing profiles in Chromium and Firefox, without JavaScript and in
every bundled theme (10 checks): a learner's Account billing details and a company administrator's
company Billing details save and survive a reload; the learner's checkout is filled from the saved
profile, a correction there is saved to it, and the placed order shows the billing it was placed
with, still after the profile changes, with its invoice PDF opening; the company credit checkout is
filled from the company's details and its order is billed to the company; both forms save as
ordinary forms without JavaScript and fit a 390px phone; and an ADMIN sees both forms in all five
themes at 1440 and 390px without overflow. Orders are immutable, so each run leaves its two orders,
their purchasers and the retired fixture course in the disposable development database.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/billing-profiles-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/billing-profiles.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/billing-profiles-fixture.php cleanup'
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
editor render in all five themes at 1440 and 390px without sideways scrolling.
`promotions-fixture.php create` makes two paid courses, an ADMIN and two promotions; the script asks
it for a fresh learner per purchase and runs `create` and `cleanup` itself. Orders are immutable, so
each run leaves its orders, their learners, the retired courses and the promotion they used.

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
of the courses is told so on the bundle page. Without JavaScript a bundle is bought and an ADMIN
reorders its courses. The bundle catalogue and page, the cart and the ADMIN list and editor render in
all five themes at 1440 and 390px without sideways scrolling. The script creates and cleans up
`bundles-fixture.php` itself; orders are immutable, so each run leaves its orders, their learners, the
bundle they bought, its retired courses and the promotion used.

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

- **List.** The six installed designs are listed with their pictures, Classic is the default for new courses, the navigation leads there, and the page says nothing of states, versions, HTML or CSS.
- **Editor.** ADMIN picks Modern by picture, types wording and inserts the Date issued field with Insert field (CKEditor), adds a signatory; each change redraws the PDF preview, typing the name does not, and the field is stored as a field. Save keeps the look, wording and signatory.
- **Course page.** The course picks that design by picture and sets its accreditation line; the previews show this course's certificate with both, and Save keeps them. Edit this design carries `return_to`.
- **Learner.** A learner passes the final in the reader, opens View certificate from the course page, sees the details and the PDF, and downloads one-page PDFs from the certificate page and the course page that print their name, the course, the design's wording, the accreditation line and the signatory.
- **History.** Editing the design (opened from the course, returning there) changes the next learner's certificate and leaves the issued PDF's text exactly as it was; ADMIN sees the design it was drawn in.
- **Upload (Firefox).** The sample Canva-sized background (`design/certificate-samples/01-classic-background.png` in the workspace, or `CATTO_CERTIFICATE_BACKGROUND`) becomes the look; the preview differs from Classic, then follows the placement and typeface; after saving, Your own background is chosen and shows its picture (scrolled to first: the look pictures load lazily below the preview), also on the list.
- **Duplicate, default and delete.** A duplicate becomes the default for new courses and is deleted with Classic as the replacement; deleting the course's design moves the course to Minimal, and the issued certificate still prints exactly as it did.
- **No JavaScript.** A design without the Learner name field is refused with the typed words kept; typed `[Learner name]` and `[Course title]` fields preview and save; the course page saves a design.
- **Access.** A learner is refused the design pages, the course certificate page and the previews.
- **Themes.** The list, an editor, the course page and the certificate page fit all five themes at 1440 and 390px; at 1440 the editor's and the course page's preview is above the form at nearly the full width (UX rule 6.12).
- **Firefox.** CKEditor's Insert field fills the preview, and the certificate page shows and serves the same PDF.

`cleanup` removes the course, its enrolments and certificates and the identities, makes the recorded default design the default again, and deletes the run's designs as the list does (archived and renamed). Published versions and stored pictures are immutable and stay.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/certificates-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/certificates.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/certificates-fixture.php cleanup'
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
- **Themes.** The design page and an ADMIN order page fit all five themes at 1440 and 390px, with the preview an A4 portrait page above the form (UX rule 6.13).
- **Firefox.** The preview follows the sample order choice and a receipt downloads as in Chromium.

The fixture places its orders through the real commerce services (a card payment, a 100% promotion, an EFT confirmed by ADMIN, a company credit purchase and a refund), so their documents are issued as a checkout issues them. `cleanup` saves the recorded design again as the current one and signs everyone out; orders are immutable, so each run leaves its orders, their purchasers and the retired courses.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/financial-documents-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/financial-documents.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/financial-documents-fixture.php cleanup'
```

## Course landing pages

`landing-pages.cjs` checks course marketing landing pages (Phase L) in 13 scenarios:

- **Editor.** ADMIN opens Landing page from the course editor's tools, creates the page (the hero and eight sections, Publish waiting for the empty lists), writes the hero, fills Who this course is for, Learning outcomes and a question, hides and shows Benefits, adds a call to action from Add section (Pricing is refused as already on the page) and removes it from its ⋯ menu.
- **Order.** A section dragged by its handle and another moved with ↑ on its handle are saved and survive a reload; without JavaScript the ⋯ menu's Move to bottom moves a section; Firefox repeats a drag.
- **Preview and publication.** Preview opens in a new tab with the buttons disabled, `noindex` and no canonical address; Publish makes the page public and the course editor says so; Unpublish takes it down (not found) and keeps every section, and a page that has been public offers no Delete.
- **Visitor.** The published page shows the hero, the lists, the outline (never the fixture's lesson, bonds or question text), the approved review, both prices and a question that opens; its canonical address is set. Add to cart from the hero returns to the landing page, with JavaScript and without. A learner who has the course is offered Continue course and no Add to cart; a course with no active price says it is not on sale.
- **Themes.** The page, the editor and a section form fit all five themes at 1440 and 390px without sideways scrolling; the hero's Add to cart is whole and at least 32px tall, and the buy box's price has a contrast of at least 4.5:1 against its card.

The fixture makes two published courses (one with two prices, an outline with protected content and an approved review; one not on sale with a published landing page), an ADMIN, a course owner, a learner who has the first course and one who does not. The check places no order, so `cleanup` removes everything.

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/landing-pages-fixture.php create'
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/landing-pages.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/landing-pages-fixture.php cleanup'
```
