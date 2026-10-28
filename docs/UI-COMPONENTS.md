# Platform UI component guide

**LMS:** 0.9.6 · **Updated:** 2026-10-20 · **Registry:** 38 components in `src/View/Ui/PlatformUi.php`

CattoLMS reusable UI structures are platform components. A page may not independently implement a job already represented by a canonical component. Extend the component and its contract test instead of forking markup.

The purpose is to prevent UI drift from page-by-page LLM generation. Recreating equivalent controls from scratch had produced subtle differences in markup, spacing, responsive behavior, accessibility and no-JavaScript fallbacks. This registry makes one implementation authoritative; the ownership contracts fail the build when a duplicate structure appears.

## Ownership and API

`src/View/Ui/PlatformUi.php` is the sole allowlist of logical names, template paths, property names and defaults. `PlatformUiExtension` exposes three Twig functions:

```twig
{{ ui('feedback.badge', {label: 'Active', tone: 'success'}) }}
{% embed ui_template('layout.surface') with {props: ui_props('layout.surface', {variant: 'compact'})} %}
    {% block surface_body %}
        {{ ui('layout.section-head', {heading: 'Example'}) }}
    {% endblock %}
{% endembed %}
```

`ui()` and `ui_props()` share validation. Unknown names/properties and unsupported semantic variants throw. Objects, rendered Markup and resources are rejected. Values are escaped by the same strict Twig environment as runtime. Variable HTML belongs in authored Twig blocks, never controller-built HTML or a generic content property. No component accepts class, wrapper_class, style, css or an arbitrary template path. There are no flat-name aliases.

## Shared layout contracts

Edit `public_html/css/catto-platform.css`, the canonical Twig component and the offending bundled stylesheet directly. Do not introduce override sheets or page-specific patches. Section heads have an explicit first `.cl-ui-section-head-content` child with left-aligned content and right-aligned actions. Heading decoration must stay outside normal layout. Pagination and each internal group are horizontal and nowrap, with overflow contained in the bar. Company context resolves an opaque `--cl-context-surface` and readable `--cl-context-ink` against the current theme/palette.

Theme ownership checks cover canonical components, course cards, pagination, company context and shared search. They reject broken CSS delimiters and functional geometry, with a bounded exception for absolute, non-interactive section-heading decoration. GN's heading nib, canvas and footer, and Sidebar's shell/footer retain their individual presentation. [Browser checks](../tests/Browser/README.md) measure actual geometry, contrast and native navigation across all bundled themes.

## Component inventory

Paths are relative to `resources/views/ui/`. Slots in this table are the only variable markup areas.

| Logical name | Canonical template | Slots |
|---|---|---|
| `layout.surface` | `layout/surface.html.twig` | `surface_body` |
| `layout.section-grid` | `layout/section-grid.html.twig` | `body` |
| `layout.section-head` | `layout/section-head.html.twig` | `section_actions` |
| `layout.toolbar` | `layout/toolbar.html.twig` | `toolbar_primary`, `toolbar_actions` |
| `layout.breadcrumb` | `layout/breadcrumb.html.twig` | — |
| `layout.tabs` | `layout/tabs.html.twig` | — |
| `action.link` | `actions/link.html.twig` | — |
| `action.button` | `actions/button.html.twig` | — |
| `action.group` | `actions/group.html.twig` | `body` |
| `action.row` | `actions/row-actions.html.twig` | `body` |
| `form.grid` | `forms/form-grid.html.twig` | `body` |
| `form.field` | `forms/field.html.twig` | `control`, `help_content` |
| `form.actions` | `forms/form-actions.html.twig` | `primary_actions`, `secondary_actions` |
| `form.choice` | `forms/choice-field.html.twig` | `control`, `label_content` |
| `form.tree-select` | `forms/tree-select.html.twig` | — |
| `form.picture-choice` | `forms/picture-choice.html.twig` | feature cards: items with an SVG `icon` instead of an `image` (Register's account type) |
| `form.compact-action` | `forms/compact-action.html.twig` | `body` |
| `data.table` | `data/data-table.html.twig` | `columns`, `header`, `body`, `footer`, `empty` |
| `data.dataset` | `data/dataset-layout.html.twig` | `toolbar`, `results`, `preview` |
| `data.stat-grid` | `data/stat-grid.html.twig` | `body` |
| `data.card-grid` | `data/card-grid.html.twig` | `body`: whole rows of `data.card`, `columns` 4 (courses) or 5 (people, companies) |
| `data.card` | `data/card.html.twig` | `picture`, `title`, `details`, `actions`; `picture` is `round` (a person), `square` (a company) or `wide` (a course cover) |
| `data.stat-card` | `data/stat-card.html.twig` | — |
| `data.person` | `data/person.html.twig` | — |
| `data.list` | `data/item-list.html.twig` | `body` |
| `data.list-item` | `data/list-item.html.twig` | `body`, `actions` |
| `data.key-value-list` | `data/key-value-list.html.twig` | an item may carry `was`, a value it replaced, struck through before it (a marked-down price) |
| `feedback.badge` | `feedback/badge.html.twig` | — |
| `feedback.notice` | `feedback/notice.html.twig` | `body` |
| `feedback.empty` | `feedback/empty-state.html.twig` | `body`, `actions` |
| `feedback.progress` | `feedback/progress.html.twig` | — |
| `overlay.modal` | `overlay/modal.html.twig` | `modal_body`, `modal_actions`, `additional_forms`; `upload: true` makes its form send files (an icon upload) |
| `overlay.accordion-section` (FAQ, Help) | `overlay/accordion-section.html.twig` | optional `icon` (an SVG, before the heading, in the theme's accent colour, filled when open) and `eyebrow` (a category line above the heading), as on `/faq` and `/help`; a link to its `id` opens it; an open one's title bar darkens |
| page head picture | `partials/page-head.html.twig` | `ph_hero` (captured SVG markup) puts the kicker, title, lead and the page's actions over a picture (`.cl-picture-head`, as on `/help`, `/faq`, `/courses`, the tag browser, `/contact`, `/terms`, `/refunds` and `/privacy`; a course page's header uses the same `.cl-picture-head` with its cover). Every one is the same size: full width at 32:15, square on a phone. Pictures live in `partials/artwork/` |
| `overlay.accordion-section` | `overlay/accordion-section.html.twig` | `actions`, `body` |
| `icon` | `helpers/icon.html.twig` | — |
| `catalogue.course-grid` | `catalogue/course-grid.html.twig` | — |
| `catalogue.workspace` | `catalogue/catalogue-workspace.html.twig` | `surface_body` |
| `catalogue.tag-browser` | `catalogue/tag-browser.html.twig` | `surface_body` |
| `catalogue.tag-chip` | `catalogue/tag-chip.html.twig` | — |

## Semantic contracts

- Surfaces: standard or compact; optional section spacing, stable ID, sticky placement and semantic tone. Section grids: auto or one to four columns; form grids: auto, one, two or `wide`. A wide grid has twelve columns; `form.field` and `form.choice` take `span` (`quarter`, `third`, `half` or `two-thirds`), default to half the row, and use `full_width` for the whole row. Spans collapse at tablet and phone widths. Headings: h2 or h3. Tabs are native links, one per `{key, label, href}` item, with `aria-current="page"` on the current key; each tab is its own GET URL, so they work without JavaScript. The strip stays on one row and scrolls inside itself when narrow.
- Actions: primary, secondary, quiet or danger; small, normal or large; optional sprite icon. Links retain href; buttons retain native type, form, name/value and disabled state. Explicit behaviour properties support the existing htmx, confirmation, modal and Stimulus hooks without arbitrary attribute maps. `action.row` is for record actions; `action.group` is for page/section actions. A menu row takes a `label` (default Actions), an optional `aria_label`, a sprite `icon` and `compact`, which shows the icon alone, as the ⋯ trigger on Course Content rows does. A submit button may post its form somewhere else with `form_action` (a path on this site) into a named frame with `form_target` (a lower-case name), as native `formaction`/`formtarget`; `skip_validation` adds `formnovalidate`.
- Fields own labels, required markers, help/error text and full-width placement. The control slot holds native inputs/selects/textareas. Match `for` with the control ID and associate help/error IDs exclusively through `ui_field_attrs(props)`; errors also mark the control aria-invalid. Choice fields wrap native checkbox/radio controls. `form.actions` owns one save row and its secondary-action region; `form.compact-action` is only for an immediate one-value operation. Modal footers are not nested inside form action rows.
- A picture choice (`form.picture-choice`) is a fieldset with a `legend`, an optional `help` and `id`, and one native radio per `{value, label, image, note?}` item, the picture inside the radio's label with empty alt text; `image` is a path on this site and `selected` is the checked value. The choice is sent with the form's own Save, so it works without JavaScript (certificate looks, word placement and a course's certificate design). An item may carry an SVG `icon` (checked by `View\Artwork\UploadedSvg`: nothing that runs, no link out) instead of the `image`, `{value, label, icon, note?}`: when every item does, the choice is a grid of feature cards (`cl-ui-picture-choice--cards`), each with the icon in the theme's accent (filled when chosen), the label as a bold title and the note as its description, as Register's account type (owner, 2026-10-20). `size: 'small'` draws smaller cards, the icon beside the title, for a choice under a larger one (the contact form's topics and Who is it for?). `columns: 4` puts at most four cards in a row, further rows beneath, left-aligned (two on a tablet, one on a phone), as the catalogue's categories; a form that should open the chosen card at once carries `data-choose-submits` and a `<noscript>` submit button.
- A table cell that holds a sentence, such as what happened on a Money Ledger line, is `td.cell-text`: it wraps within its own column instead of ending in an ellipsis (UX rule 2.4).
- A person with an account is always `data.person` (owner, 2026-10-21): their name linked to their profile (`href`: `/admin/people/{id}` in Administration, `/company/people/{id}` in the Company workspace). The page's own person is named once with `full: true`: the linked name, the account reference and the email (`email`), each on its own line. Every other mention, and every table, is the linked name alone. No page prints a person's name on its own.
- A list shown as cards (owner, 2026-10-26; UX rule 2.6g) is `data.card-grid` (`columns` 4 or 5) of `data.card` (`picture` `round`, `square` or `wide`; slots `picture`, `title`, `details`, `actions`). Its list's dataset puts `partials/view-switch.html.twig` in the `toolbar` slot with the list's `<dataset>_view_switch`, and in grid view `partials/grid-sort.html.twig`; `Support\ListView` decides each list's view, columns and grid page sizes, and `PlatformAdministrationService::listSizes()` pages a grid in whole rows. The catalogue's choice travels in `catalogue.workspace`'s `workspace.view` and `workspace.view_switch`.
- A stat card (`data.stat-card`) takes `size: 'large'` for the one figure a page is opened for, big and bold in a border of the theme's accent (`cl-ui-stat-card--large`, owner 2026-10-21: a wallet's balance). It takes an optional `href`: the whole card is then the link to what it counts, as the dashboard's money figures are (`cl-ui-stat-link`, owner 2026-10-18). `icon` names a sprite symbol drawn above the value in the theme's accent (`cl-ui-stat-icon`; the dashboards' cards use the `figure-*` symbols, owner 2026-10-21: "Dashboard cards also need svg icons"). `size: 'small'` is a money amount in a row of figures: its value is drawn at 0.88 of the theme's own size on one line (owner, 2026-10-21: "-2pt, possibly -4pt"), and `platform-overrides.js` (`fitFigures`) shrinks it further only when it still does not fit its card. A dashboard's grid (`data.stat-grid`) takes `columns: 4` or `columns: 5`: four or five to a row, five falling to three (never four and a lone card) where five would be narrower than 10.5rem.
- A column chart (`partials/column-chart.html.twig`) draws amounts over time, one column per slice with the empty ones kept, a column with an amount linking to what it adds up; `partials/distribution-chart.html.twig` draws one bar per row, a row optionally linking (`href`) and saying more under its total (`note`). The ADMIN dashboard's money uses both, for one period (`MoneyRecords::dashboard`).
- A tree select (`form.tree-select`) is the one hierarchical picker (categories everywhere). It is one form-control row showing the chosen option's full path, opening a native `popover` that holds nested options: `{value, label, depth, path}`, an optional `icon` (a category's SVG icon, drawn before the label, owner 2026-10-20) and an optional `children` list one level deeper (depth 0 only at the top and without children, at most depth 3, children only at depths 1 and 2). Beside an option that has children sits a disclosure button (`aria-expanded`, `aria-controls` naming its `role="group"` branch list) that only opens or closes the branch; it never chooses. Branches are rendered open and the buttons hidden, so without JavaScript the whole tree is listed; core script shows the buttons and, every time the list opens, closes every branch except those leading to the chosen option. The chosen option carries `aria-current` (submit mode) or `checked` (field mode) and `autofocus`. In the default `mode: submit` each option is a submit button carrying its value, so choosing one submits the enclosing form without JavaScript (the catalogue). `mode: field` renders the options as radios in a `radiogroup`, so the choice is sent with the form's own Save; core script shows the chosen path and closes the list on a pointer choice, Enter or Space, and leaves it open while arrow keys move the choice. `window.CattoTreeSelect.initialise(root)` wires up pickers swapped into the page. The panel anchors below its toggle where CSS anchor positioning exists. Stat grids take `density: compact` for many small counts, keeping two or more per row on a phone. Tables own the wrapper, table, head and body. Rows/cells remain authored slots and column headers delegate to sortable-header. A cell that holds a row's actions is `td.cell-actions`: Core never makes its column narrower than its control (UX rule 2.8).

On a phone (700px and narrower, UX rule 2.9):
- **Columns.** A table shows its essential columns. A header with `sh.wide` (set from `PHONE_HIDDEN_COLUMNS`, or `wide: true` on a template header) gets `data-phone="hide"`, and Core hides that column.
- **Text.** Everything in the table is 7pt.
- **Tooltip.** `data.table` renders an empty `.cl-ui-table-tip` that Core's script fills with the whole row.
- **Actions.** `action.row` takes `narrow_menu: true` for several actions kept in a row. It renders the buttons for wide screens and the Actions menu for phones, and Core shows one of the two. Do not use it where the actions hold an element with an `id`, because the actions are rendered twice. Datasets own the stable region/results IDs and delegate search and paired pagers to existing partials. A preview slot supports bounded workspace previews.
- Badges: neutral, info, success, warning, danger or permanent; small or normal. Notices: info, success, warning or danger, with heading/body and optional canonical flash dismissal. Empty states support heading, summary, body and actions. Progress validates finite min/max/value, rejects an invalid range, clamps value, and derives the only inline width from that validated number.
- Modals: normal or wide, one title/body/footer and optional native form. Without JavaScript the same content is inline; core adds visibility, focus trapping/restoration and Escape dismissal. Accordions are native details/summary. Explicit workspace semantics retain existing lazy section GET navigation and server-rendered content.
- Icons use the platform SVG sprite. Decorative icons are hidden from assistive technology; meaningful icons require a label. Catalogue icon artwork remains trusted data from the existing icon service, not a general-purpose HTML property.

The registry defaults are the authoritative property reference. Add a property there only when a concrete caller needs a reusable semantic or existing behaviour contract, and test it.

## Existing canonical controls

Page head, dataset search, pagination, sortable header, entity lookup, course card, favourite, identity, site footer and cart chrome remain shared partials. Components delegate to them rather than replacing them. Category and tag pages still share the persistent/flat catalogue workspace, 24-card results policy, bounded initial twelve courses and progressive GET/htmx navigation.

Supported uses added in v0.8.8.x, each through the existing component rather than a new one:

- **Course card for bundles.** `partials/course-card.html.twig` (the owner of `cl-course-card`) accepts optional `href`, `link_label` and `facts` (the key-value rows). The bundle catalogue passes bundles through `catalogue.course-grid` with these, so a bundle is presented by the course card; `UiComponentContractTest` forbids a second card or grid.
- **Totals.** Cart, checkout review and order pages show Subtotal / Promo / Total with `data.key-value-list`, built once by `Commerce\Domain\OrderTotals`; a promotion the cart applied by itself reads "Promo SPRING25 (20% off, applied automatically)".
- **Prices.** `partials/price.html.twig` draws a price and, when it is marked down (owner, 2026-10-20), the was price struck through before it (`<s class="cl-price-was">`, read as "Was … now …") and, with `price_saving`, "Save R … (n%)" (`Support\WasPrice::labels`). The purchase box, cart, cart menu, checkout review, bundle line, bundle page, company credits list and the ADMIN pricing tab use it; course and bundle cards pass `was` to their Price row of `data.key-value-list` and carry a "Special: save n%" `feedback.badge` (`.cl-course-card-special`). The Administration course list's Price cell shows the current price first, then the struck-through was price and the period. `partials/promotion-announcements.html.twig` announces the automatic promotions that cover a course or bundle (in the purchase box and on the bundle page).
- **Promo code.** The checkout's promo code field is a `form.compact-action` (one value, one immediate action); Remove is an `action.button` with an `aria_label` naming the code.
- **Entity lookup types.** `/admin/lookup/{type}` serves `people`, `companies`, `courses` and `bundles`; the bundle editor adds courses and the promotion editor adds courses and bundles through `partials/entity-lookup.html.twig` (pass `lk_required`). Its search runs on keyup; a selection fills the hidden input.
- **A live preview from the form being edited.** The certificate design editor and a course's certificate page have Save and Update preview in one form. Update preview is an `action.button` with `form_action` (the preview route), `form_target` (the preview frame's name) and `skip_validation`, so without JavaScript it draws the unsaved form into the frame; the `certificate-preview` Stimulus controller presses it a moment after any input. The preview frame is `partials/certificate-preview.html.twig`; on both pages it comes first, at the full width, above the form (`cl-certificate-editor`, UX rule 6.12). The financial document design page (`/admin/commerce/documents`) works the same way with the same controller: Document and Sample order selects and the preview frame first, then the form (`cl-document-design`, `cl-document-preview-frame` as an A4 portrait page, UX rule 6.13).
- **Generated documents on a page.** A certificate appears only as its PDF, in the browser's own PDF viewer: previews in `partials/certificate-preview.html.twig` (an `iframe` named for the form's target, with an Open the PDF link), and an issued certificate on its page in an `<object class="cl-certificate-pdf">` whose fallback is a download link. Never inject rendered document HTML into a page with `|raw`. Certificate wording is edited in CKEditor with the certificate profile (`public_html/js/ck-certificate.js`, loaded by `load_certificate_editor`), which allows only the certificate's text styles, bold, italic, alignment classes and field labels; the textarea it replaces stays usable without JavaScript.
- **A course's category.** `partials/admin/course-category-field.html.twig` is the course category field on the create and edit forms and the import review: the tree select in field mode (Uncategorised, sent as 0) and, for category managers, + New, an in-place panel (shown by core script) with the name, a second tree select for where the category goes (Top level, main categories and subcategories), an optional description and the path it will have. After a create the server draws the field again (`/admin/courses/categories/field`) and core script swaps it in, so no script builds options.
- **Sortable trees.** Course Content and the course categories render the shared tree markup - `li.cl-tree-node[data-node-id]` rows with `data-depth` and `data-height`, each holding a `.cl-tree-row` with a `.cl-tree-handle` (hidden until the script runs), an optional `.cl-tree-toggle` disclosure and `.cl-tree-name`/`.cl-tree-meta`, and an `ol.cl-tree-children[data-parent]` for a row that can hold others - styled once in core CSS and driven by `assets/lib/sortable_tree_controller.js`, which each tree's Stimulus controller extends. Row actions are canonical `action.link`, `action.button` and a compact `action.row` ⋯ menu of move forms (`data-tree-direction`), so every move works without JavaScript. The category tree's disclosure buttons are submit buttons of a GET form, so its branches open without JavaScript too; it starts closed (`cl-tree--categories`), Course Content starts open.
- **The purchase box.** `partials/course-purchase.html.twig` draws `CoursePurchaseOptions` wherever a course is offered: the course page's Course access card (full: every price as a `form.choice` radio) and a landing page's hero, pricing and call to action (compact: the default price above the buttons). It is the one place Add to cart, Buy now, Request from my company, Start/Continue course and "not on sale" are drawn; a preview passes `purchase_inert` and its buttons are disabled.
- **A review list.** `partials/course-review-list.html.twig` is the list of approved reviews; the course page's paged `data.dataset` and a landing page's latest few both include it.
- **A flat sortable list.** A landing page's sections use the sortable tree markup with one level (`li.cl-tree-node[data-depth="1"]` rows without child lists, `cl-tree--landing`) and `landing_sections_controller.js`, which extends the shared sortable tree with a maximum depth of one; the hero sits above the list in `.cl-landing-fixed` without a handle. The ⋯ menu's Move to top / up / down / bottom forms move a section without JavaScript.
- **Terms acceptance.** `partials/commerce-terms-acceptance.html.twig` is the one required tick box both checkouts end with. It is a `form.choice` whose `label_content` slot carries the links to the Terms and Conditions and the Refund Policy; the `label` property stays the plain wording. Use the slot only where a choice's label needs a link; every other choice passes `label` alone.
- **A legal document.** `partials/legal-document-head.html.twig` opens `/terms`, `/refunds` and `/privacy` with the last-updated date from `LegalDocuments` (no version), the page's contents list (`legal_sections`) and the other two documents; each numbered section below is a `layout.surface` with that id and a `layout.section-head`.
- **Head metadata.** `partials/head-meta.html.twig` is included by every theme's head and draws the view model's `meta`: the description, and where a page sets them its canonical address, robots rule and Open Graph tags.
- **Ordered lists with actions.** The bundle editor's course list uses Move up / Move down / Remove buttons as ordinary forms (`cl-bundle-courses`), the same pattern as course price variants; it is not a second drag-and-drop tree.

- **An icon for a category or tag.** `partials/admin/icon-choice.html.twig` is its own card after the details card: the current icon, the shared search control (`ds_name: 'icon'`, `ds_help`), which reloads the page's own address with `icon_q` and swaps `#icon-results` into `#icon-region` as one types, the icon library's icons that fit the name (or those the search finds) as `form.picture-choice` cards named `library_icon`, an upload of one's own and Save icon, posted to `/admin/courses/categories/{id}/icon` or `/admin/courses/tags/{id}/icon`. `View\Artwork\IconLibrary` holds the library (Lucide, `resources/icons/lucide.json`). The shared search control takes an optional `ds_help` for its help line.
- **A sortable table.** Administration → Courses in the catalogue's order: each row `tr[data-course-id]` carries its number (`[data-position]`) and a hidden `.cl-row-handle`, which `assets/controllers/course_order_controller.js` shows; it moves rows while dragging or with the keys and saves each move, and the ⋯ menu's forms move a course without JavaScript.

## Action icons

Every button and button-styled link carries an icon before its label that says what it does (owner, 2026-10-21: "add an svg icon to the left of the Button title", table rows included). Every `ui('action.button')` and `ui('action.link')` call names its `icon`, every Actions menu (`action.row` with `menu` or `narrow_menu`) names its trigger's, and every section registry action (`actions()`) names one; `ActionIconContractTest` fails a call without one. `action.button`'s tick or arrow when no icon is named is only a safety net. The only button without an icon is a pagination page number, which is its own label. A label never repeats its icon (UX rule 3.3), so the reader's paging reads Previous and Next, not "← Previous" and "Next →". The reader's Download link, drawn by `CourseItemRenderer`, carries `action-download` the same way.

One meaning has one symbol everywhere. The symbols are in `public_html/img/nav-icons.svg`, drawn from Lucide 1.52.0 (ISC, `resources/icons/lucide.json`); add a symbol there, named by its meaning, only for a meaning not listed here.

| Meaning (examples) | Symbol |
| --- | --- |
| Add, create, new, add here | `action-add` |
| Remove, delete, uninstall, clean expired records | `action-remove` |
| Edit, change, edit Course Content, landing page | `action-edit` |
| Save, save details, change status | `action-save-changes` |
| Mark done, mark as answered, apply, answered | `action-done` |
| Cancel, cancel changes, close | `action-cancel` |
| Clear (a filter, a tag, a selection) | `action-clear` |
| Search, find, find the account | `action-search` |
| Filter, show reviews, show events, show courses | `action-filter` |
| Open a record or a related page, Go (pagination) | `action-open` |
| View, show, details, view certificate | `action-view` |
| View all ..., all messages, every deposit, read every review | `action-view-all` |
| Preview, preview as learner, start public preview | `action-preview` |
| Inspect, analyse course, inspect package | `action-inspect` |
| Manage | `action-manage` |
| An issued document (invoice, receipt, credit note) | `action-document` |
| Not answered, to sort out | `action-inbox` |
| Back, back to ..., all certificate designs, course overview | `action-back` |
| Previous, newer events | `action-previous` |
| Next, continue, review my order, older events | `action-next` |
| A landing page's jump to a part of the page | `action-jump-down` |
| Open another site (the Didit console) | `action-external` |
| Switch company | `action-switch` |
| Browse by tag | `action-tags` |
| Duplicate, save as | `action-copy` |
| A Move menu | `action-move` |
| Move to top, up, down, to bottom | `action-move-top`, `action-move-up`, `action-move-down`, `action-move-bottom` |
| Move into, move here | `action-move-into` |
| Move out | `action-move-out` |
| Expand all, collapse all | `action-chevron` |
| An Actions menu (⋯) | `action-more` |
| Download, a PDF | `action-download` |
| Upload | `action-upload` |
| Import, install a theme package, load the starter catalogue | `action-import` |
| Export | `action-export` |
| Send, submit, request from my company | `action-send` |
| Email (sign-in link, verification, invitation, test email) | `action-email` |
| Reply by email | `action-reply` |
| Ask a question | `action-ask` |
| Report a problem | `action-report` |
| Approve, release paid order | `action-approve` |
| Reject, refuse | `action-reject` |
| Enable, activate | `action-enable` |
| Disable, deactivate | `action-disable` |
| Revoke | `action-revoke` |
| Restore, withdraw a request, mark not done | `action-restore` |
| Reset, use the .env value | `action-reset` |
| Update preview, re-sync, new address, take again | `action-refresh` |
| Show a list as a list, or as a grid of cards | `action-view-list`, `action-view-grid` |
| Sort a grid of cards | `action-sort` |
| Publish, make public | `action-publish` |
| Unpublish, hide, return to draft | `action-hide` |
| Make private | `action-private` |
| Retire | `action-archive` |
| Who can see it | `action-share` |
| Make default, make primary | `action-default` |
| Grant access, assign course | `action-grant` |
| Verify my identity | `action-verify` |
| Register, create an account | `action-register` |
| Checkout, pay, continue to payment, payment method | `action-pay` |
| Add to cart | `action-cart` |
| Buy now, buy credits, place my order | `action-buy` |
| I'll sort it out later | `action-later` |
| Record a payment, a refund payment or a correction | `action-record-money` |
| Put it on the unmatched money list | `action-list-add` |
| Start, begin, resume, continue a course | `action-start` |
| Skip for now | `action-skip` |
| Finish assessment now | `action-finish` |
| Rate this course | `action-rate` |
| Rotate left, rotate right | `action-rotate-left`, `action-rotate-right` |
| Generate | `action-generate` |
| Pause rotation, resume rotation | `action-pause`, `action-play` |
| A menu destination: home, catalogue, my library, help, contact, sign in, sign out | `nav-home`, `nav-catalogue`, `nav-learning`, `nav-help`, `nav-contact`, `nav-signin`, `nav-signout` |

## Core classes under Bootstrap 6

Only Bootstrap 6's reboot, grid and utilities files are loaded (`THEME-SDK.md`, "Bootstrap underneath"), so a class a component or page needs for a field, a lead or a coloured word is Core's, in `catto-platform.css`, drawing 5.3.8's look in plain colours that themes restyle:

| Job | Core class | 5.3.8 class it replaces |
|---|---|---|
| Text field, text area, file field | `cl-input`, larger `cl-input-lg` | `form-control`, `form-control-lg` |
| Select | `cl-select` | `form-select` |
| Checkbox or radio | `cl-check` | `form-check-input` |
| A label outside `form.field` | `cl-label` | `form-label` |
| Lead paragraph | `cl-lead` | `lead` |
| Display heading | `cl-display-2`, `cl-display-4`, `cl-display-5` | `display-2`, `display-4`, `display-5` |
| Fixed-width band | `cl-container` | `container` |
| Coloured text | `cl-text-muted`, `cl-text-secondary`, `cl-text-primary`, `cl-text-danger`, `cl-text-warning`, `cl-text-warning-strong`, `cl-text-light-muted` | `text-body-secondary`, `text-secondary`, `text-primary`, `text-danger`, `text-warning`, `text-warning-emphasis`, `text-white-50` |
| Subtle background | `cl-bg-subtle` | `bg-body-tertiary` |
| Bordered box; a rule below a row | `cl-box`; `cl-border-bottom` | `border rounded`; `border-bottom` |
| Monospace | `cl-mono` | `font-monospace` |

Content blocks (`ContentBlockRenderer`) draw Core markup too: an accordion is `details.cl-content-accordion` with `summary.cl-content-accordion-summary`, which opens with or without JavaScript; a callout is `cl-ui-notice cl-ui-notice--info` and a warning `cl-ui-notice cl-ui-notice--warning`; a table block scrolls inside `cl-content-table`.

Utilities keep Bootstrap's names on 6.0's scale (`d-flex`, `mb-5`, `gap-3`, `lg:col-8`). Core states each one its own and the themes' markup uses as an `!important` rule with 5.3.8's value, so a utility new to a template needs its rule in `catto-platform.css` too (`BootstrapSixContractTest`).

## Themes and review

Themes own palette, typography, borders, radii, shadows and decorative chrome. Core owns component structure, required spacing, responsive geometry, htmx targets, focus and native form/navigation semantics. Gilded Noir retains its canvas, gold treatment, navigation identity and individual footer. Theme templates cannot implement another card/table/field/modal for the same job.

Review `/admin/system/ui-components` (Administration → System → UI Components), guarded by `SYSTEM.SETTING.VIEW`, and real account/admin/company/catalogue/checkout/learning pages at desktop, tablet and mobile widths. The gallery uses static sample data and no sample mutation endpoints.

## Enforcement

`UiComponentContractTest` scans core and bundled theme templates for canonical ownership and obsolete structures. Form, modal, feedback, data and gallery contracts exercise escaping, variants, native semantics, paired pagination IDs and ACL registration. Existing search/pagination, table width/row, action parity, clipping, flash, namespace, attribute and served HTML contracts remain in the QA gate. Run `composer qa` before handoff; browser checks supplement the automated contracts.

## Field accessibility and dynamic authoring

`ui_field_attrs(props)` accepts only the bounded `form.field` property contract. It returns escaped `aria-describedby` and `aria-invalid` attributes derived from the same `for`, `help` and `error` properties that render the label and messages. It cannot output classes, styles, event handlers or arbitrary attributes. Required remains a native control attribute and a component label marker.

```twig
{% embed ui_template('form.field') with {props: ui_props('form.field', {
    label: 'Name', for: 'name', help: 'Use your full name.', required: true
})} %}
    {% block control %}
        <input id="{{ props.for }}" name="name" required {{ ui_field_attrs(props) }}>
    {% endblock %}
{% endembed %}
```

The component captures `help_content` before rendering the control, so a custom help slot has the same ID relationship as plain help text. Do not repeat accessibility conditionals in callers. For a choice nested inside a field, preserve the outer field properties under an explicit local name when the choice introduces its own `props`.

The assessment and diagnostic editors include `partials/question-editor.html.twig`. Saved questions and inert question/option `<template>` prototypes use the same two specialised partials, composed from platform surfaces, toolbars, form grids, fields, choices and actions. JavaScript clones the prototypes and updates names, IDs, labels, description references and radio values. It never authors component HTML. Empty editors render an initial question on the server; native form submission and editing existing questions work without JavaScript. Add/remove controls are enhancements.

Links reject button-only properties such as `disabled`, `form`, `name`, `value`, `close_modal` and `stimulus_action`. Buttons reject `href`, `new_window`, `rel` and `navigation_key`. Unknown-property exceptions name the component and offending properties. Common htmx and modal-open hooks remain.

`UiOwnershipAudit` runs in unit tests and `tools/validate-ui-contracts.php`. It examines JavaScript markup literals and class-producing operations in `public_html/js` and `assets/controllers`, plus canonical theme geometry and malformed Stimulus attributes. Comments and selector-only references do not count as generated UI. There are no file exemptions. Notice headings use `cl-ui-notice-heading`; inline emphasis in notice prose must stay inline.

## Canonical page construction (2026-09-18)

The component registry and Twig APIs are unchanged. Themes compose shared page concepts using the canonical classes in [Theme SDK](THEME-SDK.md#canonical-shared-page-vocabulary). Core owns one navigation partial, page-head partial, full/compact identity partials and flash stack. The standard footer remains shared; richer themed footers use the same vocabulary and retain unique decoration.

Each shell places `main.cl-main` and `footer.cl-footer` beside one another inside `div.cl-page-frame`, after `section.cl-page-context`. The shell and framing are divs. Page heads, identity bands and meaningful content regions remain sections; ordinary content uses `cl-page-section`. Page-head content/actions have explicit wrappers. Navigation uses the core model for both authentication states; Gilded Noir places compact identity in the top navigation. A theme must not emit another guest shell or duplicate the identity band and hide it with CSS.

`CanonicalPageConstructionTest` renders both states through every bundled theme and checks actual landmarks, semantic sections, menu nesting, logout method/CSRF and GN decoration. The browser test `tests/Browser/canonical-page.cjs` checks served pages at 1440, 820 and 390px, navigation controls, no-JavaScript links, overflow and main/footer position.

### One construction for every page (v0.9)

Main holds rows: the flash stack, the page head (the only `h1`), the identity band and one or more `section.cl-page-section`, with a theme's `div.cl-page-family` and form windows as the only other children. The page's width is chosen once (`ThemeRenderer::pageWidth`, the `cl-width-*` body class) and every row follows it ([Theme SDK](THEME-SDK.md#page-width-and-rows-v09)). Each section's content sits on a `layout.surface` that opens with its `layout.section-head`; a `layout.section-grid` wraps two or more cards and never one. The section pages (`admin-section`, `account-section`, `company-section`) and the three "All sections" pages render their content in page sections; the "All sections" pages have no card repeating their page head.

The page head takes a list of actions: a page sets `ph_actions` to captured markup (`{% set ph_actions %}…{% endset %}`) for its own main actions, and `ph_action_href` remains for a sub-page's single secondary link. A section's own actions are defined once, in its registry's `actions()` (`AdministrationSectionRegistry`, `AccountSectionRegistry`, `CompanySectionRegistry`: a link or a button that opens a form window the section draws, with the `can_*` flag it needs), and drawn by `partials/section-actions.html.twig`: in the page head when the section is the page, and in the section's card head when it is one of several (`section_standalone` false; the Company workspace fetches a section with htmx, which draws it that way). `PageStructureContractTest` checks the rows, the grids, the widths, the menu page heads and the actions; `tests/Browser/section-pages.cjs` opens every Account, Company and Administration menu page in every bundled theme at 1440 and 390px.

### Shared page ownership in v0.8.5

The registry has 38 components (`data.card-grid` and `data.card` show a list as cards, owner 2026-10-26; `data.person` names a person with an account, owner 2026-10-21; the tabbed course editor added `layout.tabs`; the catalogue's `form.tree-select` replaced the category grid, tile, filter rail and filter pill, and since v0.8.8.12 is the category picker everywhere; certificate designs added `form.picture-choice`); canonical shell partials do not introduce a parallel component API. Theme shells compose the following core partials:

| Concern | Core source |
| --- | --- |
| Navigation model rendering | `resources/views/partials/navigation.html.twig` |
| Page context and full identity placement | `resources/views/partials/page-head.html.twig` |
| Full identity | `resources/views/partials/account/identity-head.html.twig` |
| Compact header identity | `resources/views/partials/account/identity-compact.html.twig` |
| Flash messages | `resources/views/partials/flash-messages.html.twig` |
| Standard footer | `resources/views/partials/site-footer.html.twig` |

Themes preserve top-header/sidebar shell geometry and decoration; core owns functional component geometry. Gilded Noir and Factory Reset Sidebar retain distinctive footer markup using canonical shared footer classes. Update `CanonicalPageConstructionTest`, existing component contracts and the [browser checks](../tests/Browser/README.md) whenever their supported structure changes.

## Course Components presentation ownership

Course Content editing, the grouped Course Item Library, Resource Library and type-specific authoring use the canonical `ui()` registry and explicit Save/Cancel. Multiple course groups may remain expanded; unused items appear in Not currently in use. Content is added to a course only through "+ Add here" in Course Content (insert modal, or form pages without JavaScript); the Course Item Library's Create item makes an unplaced item. Learner course and assessment presentation is a deliberate Core exception: `layout/course-presentation.html.twig` renders the white full-screen reader without theme navigation/footer/wrappers. `layout/course-item.html.twig` is the one mandatory Course Item page construction: HTML lessons, sections, assessments, diagnostics, assessment sessions, results and public assessment previews all render their title and body inside its `article.cl-course-item` surface. Themes must not style or replace either layout. Reader pages do not receive palette state or palette controls. Start, begin, previous, next and public-preview controls compose the canonical `action.button` and `action.link` components; the Core reader supplies their shared blue primary treatment and white text. Ordinary content has no completion controls or progress indicators.
