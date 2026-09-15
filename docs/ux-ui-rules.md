# Catto Learning UX/UI Rules

**LMS:** 0.8 (development)
**Date time:** 2026/09/10 02:25 SAST
**Status:** current requirements. Superseded implementation instructions are replaced in place.

This is the owner's interface rule book. Every rule below was stated by the owner, and it is written
down here so that stating it once is enough — a rule is not re-litigated on the next surface, and a
new page is checked against this list before it is handed over.

**How to use it.** Before building or changing any interface surface, read the section that covers
it. After changing a shared control, re-check every surface that uses it. Where a rule is machine-
checkable it names the test or tool that enforces it; a rule with an enforcement entry is not
allowed to regress silently.

**Rule numbering is stable.** Add new rules at the end of their section with the next free number.
Never renumber, because reports and commit messages cite these numbers.

---

## 0. The test every screen has to pass

**0.1 A UI is simple, intuitive and obvious. If it is not obvious, it is broken.** The owner's rule,
2026/09/10, and it outranks everything below it: the rest of this document is mostly ways of being
obvious. "The control exists in the markup" is not a defence. If a reader looking for the way to do
something cannot find it, the screen has failed, and the bug is the screen's, not the reader's.

The report that produced this rule was "there is no save image button". There was one. It sat flush
against the grey help text above it, because a hidden element between the field and the button
stopped the spacing rule matching, and the button below it in a separate form did have spacing — so
the eye landed on *Remove image* and read that as the card's action. Everything about that is
invisible in the HTML and obvious on the screen.

**0.2 Every form you fill in and save ends in one action row.** `.cl-ui-form-actions`, separated from
the fields by a hairline. A reader asking "how do I save this" should find the answer in the same
place on every form rather than scanning for whichever button happens to be primary-coloured. The
primary action comes first; a destructive one is `.cl-ui-form-actions-secondary` and is pushed to the
far end, so Save and Remove are never a pair of equal-looking buttons.

A **modal** already has one: `.cl-ui-modal-foot` is the same rule wearing the modal's own name, with
Cancel beside the primary action. Do not nest an action row inside it.

Three shapes are **not** forms in this sense, and a separator would break each of them:

- an **inline row control** — a button occupying the last cell of a row of fields, as the grade-band
  and price rows on the course editor do. The button is aligned with the fields on purpose;
- a **compact act control** — a single select or input with its action button against it, like
  Change status or Reassign courses and delete. It is one control, not a form;
- a **list control** — pagination's Go and Apply, the dataset search, the activity Filter. These sit
  in a toolbar, and a hairline through a toolbar is nonsense.

The test is what the reader is doing: filling several things in and then committing them is a form;
picking one value and acting immediately is a control.

**0.3 Spacing that separates a control from the text above it is load-bearing**, not decoration. Use
`~` rather than `+` when a hidden element can sit between: `[hidden]` still counts as a sibling, and
an adjacency rule silently stops matching the moment something is hidden between the two.

---

## 1. Consistency — the rule the others hang off

**1.1 One pattern per job, reused verbatim.** When a UI need already has a working implementation in
this codebase, use *that* implementation. Do not write a second one, do not improve it in passing,
and do not invent a variant because the new surface feels slightly different. The owner's words:
*"you create a working pattern and you duplicate it every time you need it, instead of inventing
something new."*
→ Enforced by `SharedControlContractTest`, `PaginationUiContractTest`, `tools/validate-ui-contracts.php`.

**1.2 Before building any UI element, find the existing component and reuse it.** If the shared
component needs a supported semantic variant, extend it and its contract tests. If the job is new,
add its canonical implementation and migrate its callers. Do not fork it.

**1.2a Reusable CattoLMS UI structures have one canonical platform implementation.** Pages assemble
shared components and must not reproduce their markup. When a new reusable pattern is needed, add
or extend a platform UI component and its contract test instead of coding a page-local variant.

`src/View/Ui/PlatformUi.php` is the fixed registry for namespaced layout, action, form, data,
feedback, overlay and catalogue components plus the sprite icon helper. `ui()` renders scalar or
structured properties. `ui_template()` and `ui_props()` compose authored Twig block slots. No raw HTML
property, arbitrary structural class/style, dynamic template path, deprecated name or compatibility
alias is accepted. See [UI-COMPONENTS.md](UI-COMPONENTS.md) for the complete API and slot inventory.
The existing page head, search, pagination, course card, favourite, sortable header and entity lookup
remain canonical shared controls. Pages and themes consume them; they never fork their structure.
→ Enforced by the component family contracts, `UiComponentContractTest`, `SharedControlContractTest`
and `CoreStyleNamespaceContractTest`.

**1.3 A change to a shared control changes every surface.** Check them all, and say which were
checked when reporting the work.

**1.4 Equivalent surfaces expose equivalent actions.** If a row has a Manage action in the standalone
list, the same row has a Manage action in the workspace accordion, the dashboard preview and every
theme. An action that exists on one surface and is missing on its equivalent is a defect, not a
design difference.
→ Enforced by `RowActionParityTest`.

**1.5 Accordions, not tabs.** Grouped settings and multi-section screens use the `<details>`
accordion pattern. `/admin?tab=…` is not to be reintroduced.

**1.6 Never restyle an accepted design for variety.** Visual change happens because the owner asked
for it or because a rule here demands it.

**1.6a Elements sitting in a row line up with each other.** A control that sits a few pixels above
or below the one beside it breaks the interface — the owner's words: *"This type of misalignment of
UI elements breaks a UI."* Watch the rows that mix element kinds, which is where it happens: a plain
button, a button inside a form because it posts, and a `<summary>` styled as a button because it
opens a panel. A `<summary>` is a list-item box rather than an inline-flex one, and a form is a
block, so left alone the three sit at three different heights. Core normalises this on `.cl-ui-row-actions`
and the canonical row-menu summary; a new row of controls should need nothing further, and if it does, fix it in core
rather than on the page.

**1.6b Preserve theme individuality within the shared functional component system.** Gilded Noir
consumes the same components as every theme. Its engraved canvas, gold actions, typography, navigation
identity chip and individual footer remain theme-owned. Themes decorate canonical selectors; they
must not replace component DOM, required spacing, GET/POST semantics, behaviour hooks or accessibility.

**1.7 Every page is built the same way.** Page head, then the identity band, then the body on cards:

```text
page-head partial
identity partial (where the theme presents it)
layout.section-grid
  layout.surface
    layout.section-head
    composed form/data/feedback components
```

The eyebrow names the section and comes from the section registry, so the card, the menu entry and
the page head cannot disagree about what a section is called. A page with several cards repeats the
card, not the grid.
→ Enforced by `tools/validate-ui-contracts.php` and the section contract tests.

**1.8 Nothing renders on the page canvas.** The canvas is the slanted texture, and text read
directly off a texture is hard work. Every block of words sits on a surface — a card, a table
wrapper, a notice, the pagination control, the footer. Core gives a surface to the containers that
are otherwise bare (`.cl-ui-toolbar`, `.cl-ui-section-head`, `.cl-ui-notice`, `.dataset-search`) when they are not
already inside a card, so this holds without each template having to remember it.

The check is the delivered HTML, not the template: walk every text node's ancestors and look for one
that paints a background. A partial can look carded in source and still render bare once a wrapper
decides otherwise.

**1.9 The page body sits in a boxed container.** Each theme puts the body in one box with a surface,
a border and a radius — `.rl-content` in Radiant Learning, `.main > .content` in Factory Reset — so
the texture shows *around* the page rather than behind its text. The footer sits outside that box:
it is the end of the page, not part of its content. A course page opts out, because its reading
column already sets its own width and a second box would inset the text twice.

---

## 2. Tables

**2.1 Every column declares its width, and the widths total 100.** Column widths are declared beside
the label in the service that builds the column model, using the `'Label|NN'` form — for example
`'Person|36'`. A table whose widths do not total 100 fails the build.
→ Enforced by `TableColumnWidthContractTest`.

**2.2 Space is allocated to need, not shared out evenly.** A name or title column carries the most
text and gets the most width; a numeric column gets what its digits need and no more; the actions
column gets what its control needs. Sizing every column the same is the defect this rule exists to
prevent.

**2.3 No horizontal scrolling on a table that fits.** `.cl-ui-table` provides `overflow-x:auto` as a
last resort for genuinely wide data; it is not a substitute for sizing the columns. If a table
scrolls sideways at a normal desktop width, its widths are wrong.

**2.4 Rows do not wrap into unreadable stacks.** Cells that can hold long values truncate or wrap
within their own column; a cell never forces the row to grow to several lines because a neighbouring
column was starved.

**2.5 Column width is measured, not guessed.** The widths in force were set by measuring the
rendered content of every table on every page: the 90th percentile of each column's content, floored
at the header label's own length, normalised to 100. A row menu counts as its trigger, not as the
items it opens. Re-measure when the data changes shape; do not nudge one column and leave the rest.

**2.6 Every table header is the shared sortable header partial.** `partials/sortable-header.html.twig`
renders the label, the sort state and the declared width. A hand-written `<th>` in a data table is a
defect.

**2.7 Every table paginates and searches through the shared controls.** `partials/pagination.html.twig`
and `partials/dataset-search.html.twig`, always both, never a second implementation. Search is
server-side over the whole dataset, never a filter over rendered rows. Bounded fixed lists — Roles,
Themes, a course's own modules — are exempt from pagination, because a control that can only ever say
"Page 1 of 1" is noise.

---

## 3. Row actions

**3.1 Row actions stack vertically. They are never laid out side by side.** This applies in the row
menu panel, on every table, in every theme.
→ Enforced by the `.cl-ui-row-panel` grid rules in `catto-platform.css` and by
`tools/validate-ui-contracts.php`.

**3.2 Button labels are short.** "Manage", "Edit", "Remove" — not "Manage this company's people".
The context is the row.

**3.3 Actions may carry an optional sprite icon beside their label.** The canonical action calls
the `icon` helper. Decorative icons are hidden from assistive technology; meaningful icons need a label.

---

## 4. Forms

**4.1 Form fields have white space around them.** Fields are laid out with grid `gap`, never with
per-element margins that collapse. A submit button is separated from whatever precedes it whether or
not anything between them is hidden — see 0.3.

**4.2 Fields on the same row align vertically.** Labels sit on a common baseline and inputs on a
common top edge, whatever the label's length or whether one field carries help text. First name and
Middle names side by side must line up exactly.
→ Enforced by the `form.field` / `form.grid` rules in `catto-platform.css` and `FormSpacingContractTest`.

**4.3 A form works without JavaScript.** htmx is progressive enhancement: every control degrades to
an ordinary form submission.

**4.4 Full width has one semantic API.** Pass `full_width: true` to `form.field` or `form.choice`.
The canonical component emits `cl-ui-field--full`. Old field-wide/full spellings and their CSS are
removed; callers may not construct field wrappers directly.
→ Enforced by `FormSpacingContractTest` and `FormComponentContractTest`.

**4.5 A label without a value is not rendered.** A section header, a field label or a card heading
that would render blank is omitted entirely rather than emitted empty.

---

## 5. Navigation

**5.1 Submenus pop out.** Every theme renders a nested submenu as a popout panel, not as an inline
list that pushes the page around. This applies to the sidebar themes as well as the top-bar themes.

**5.2 A popout is never covered by the page.** The navigation panel's stacking context must place it
above page content — `z-index` on the panel alone is not enough if an ancestor creates a stacking
context. Check it against a page with cards and tables, not against an empty page.

**5.2a A menu panel that flies out may not scroll.** `overflow-y: auto` on the panel forces the used
value of `overflow-x` to `auto` as well, so the third level — drawn outside the panel's right edge —
is clipped away silently. Where the panel must scroll because the menu is taller than the screen,
the third level nests inline instead; those are the two halves of one choice, and a theme may not
take both. Radiant Learning had `max-height` with `overflow-y: auto` on the panel *and* a flyout
third level above 821px, so no submenu was reachable at any width.
→ The mechanism is described in `PopoutClippingContractTest`.

**5.2b Never re-declare `position` on a panel later in the same stylesheet.** A second bare rule at
equal specificity replaces the first, and a panel that was `position: absolute` becomes part of the
flow: opening a menu then grows the header instead of drawing over the page. Radiant Learning
declared `.rl-menu-panel{position:relative}` forty lines below `.rl-menu-panel{position:absolute}` to
anchor its flyout — which it did not need, because an absolutely positioned element is already a
positioning context for its descendants.

**5.3 Navigation icons are core-owned.** One symbol per semantic key in
`public_html/img/nav-icons.svg`. Never add menu artwork to a theme; add the symbol to the core
sprite. Icons are stroked, not filled, and take `currentColor`.
→ Enforced by `NavigationIconContractTest`.

**5.4 Navigation is permission-filtered by core.** A theme renders the `navigation` array it is
given and never maintains its own route list.

**5.5 The entry leading to the page being read is marked, at every level.** Core decides it — each
child and grandchild carries `active` — and a theme renders it. Marking only the top-level item
leaves a reader who has opened Administration → System → Themes with no indication of where they
are. Only the path is compared, never the query string: paging or searching a list is a view of a
page, not a different page, and must not unmark the entry the reader arrived through.

**5.5a A group is marked with `nav-group-current`, never `active`.** The palette paints anything
carrying `data-nav-item` and `active` as a filled pill, which is right for a link. A group is a
container holding several links, so the same class turns the whole group into a slab of colour with
its heading inside it. The group says it is current under its own class and core marks the heading,
not the block.

**5.5b Put the state on the link, not on what is inside it.** A child anchor that carries no class
of its own is easy to mark by rewriting "the first class attribute on the line" — which is the
`nav-icon` inside the anchor. The state then lands on the SVG, where nothing styles it, and the
entry is never marked at all.

**5.6 A flyout anchors to its own group.** The group is the positioning context, so `left: 100%` is
that group's right edge and `top: 0` its own top: the flyout opens level with the entry being
pointed at, and touching it. Anchored to the *panel* instead, every flyout opens at the panel's
top-right corner whichever group is hovered — they all line up with each other instead of with their
own entry, and reaching one from a group part way down the list means travelling up and across,
which leaves the group, drops `:hover`, and closes the flyout while the pointer is still moving
toward it. There must be no gap between a group and its flyout for the same reason; draw the
separation with a transparent border, which is part of the element and still hoverable.

**5.7 The navigation keeps its scroll position across a page load.** Every link is an ordinary link,
so following one is a full document load and a navigation that is its own scroll container comes
back at the top — the menu moves out from under the pointer and the reader has to scroll back before
clicking anything else. The position is remembered per tab and restored as soon as the element
exists, not on `DOMContentLoaded`: restoring later is the same jump one step further on.

---

## 6. Named UI elements

An element named here is a platform element. It is defined once in core CSS, available to every
theme, and every theme may restyle it but none may re-invent it.

**6.1 Slanted texture.** Bands slanting at 45 degrees from top left to bottom right. Two band rows,
each about 10px thick, alternating in colour: a light grey and a darker grey. It originated in Light
Default and is now a named element available in all themes.
Core class: `.cl-slanted-texture`. Colours come from `--cl-texture-light` and `--cl-texture-dark`,
and the band thickness from `--cl-texture-band`, so a theme re-colours it by setting three custom
properties rather than by writing its own gradient.
→ Enforced by `SlantedTextureContractTest`.

**6.1a Gilded Noir keeps its own texture instead.** *GN texture*: gold hairlines raked **up from the
left** at about 14 degrees — shallower than the platform's 45 — in two bands of unequal width, a
narrow line against a wide gap. It sits on the theme's fixed `.gn-canvas` over the theme's own dark
ground. Gilded Noir therefore suppresses the platform canvas rather than carrying both: two textures
on one page fight each other, and the owner chose this one for this theme.

**6.2 Course card.** A course card carries, in this order: the course icon or image, the course name,
the description, the category breadcrumb with every ancestor category clickable, the tags under a
"Tags" label, the price, the level, the number of modules, the favourite star, and a "View course"
button. It does **not** show the access period. Public catalogue cards use one canonical grid: four columns on large desktops, three on smaller desktops, two on tablets and one on phones. Public results paginate in pages of 24.
→ Enforced by `CourseCardContractTest`.

**6.3 Row menu.** The stacked action panel described in section 3.

**6.4 Dataset search, pagination, sortable header.** The three shared table controls in section 2.

**6.5 Page canvas.** The slanted texture, on the body of every page in every theme. `ThemeRenderer`
puts `cl-page-texture` on the body, and core paints it with a rule qualified by the element
(`body.cl-page-texture`) so it outranks a theme's own `body{background:…}` on specificity alone,
without `!important` and without depending on stylesheet order. A palette re-colours the canvas by
setting the texture's two band colours — never by painting a flat colour over it, which is what a
`background` on the shell or on `body[data-cl-palette-managed]` does.

**6.6 Identity band.** Who the reader is, directly under the page head on every page. Included by
`partials/page-head.html.twig` rather than by each page, so a page cannot forget it and cannot put
it anywhere else. A signed-out reader gets the same band — same height, same shape — with an account
mark and a way in; a header that appears for some readers and not others makes the page jump about
depending on who is looking at it.
Core classes: `.cl-identity-head`, `.cl-identity-head-inner`, `.cl-identity-head-meta`.

**6.7 Site footer.** Core-owned markup in `partials/site-footer.html.twig`, included by every
theme's own footer partial. It was five inline footers that disagreed with each other and none of
which had a surface. A theme changes how it looks by changing the palette, not by rewriting it.
It carries the footer navigation and the social marks, on a light pastel surface with a dark border.
→ Enforced by `NavigationContractTest`, `tools/validate-ui-contracts.php` and `validate-release.php`,
each of which checks both halves: the theme delegates, and the core footer renders the array.

**6.8 Social marks.** Brand glyphs in the core sprite as `social-<key>`, keyed to `SocialPlatform`
so a mark and a link cannot disagree about which service they name. These are the one exception to
rule 5.3's stroked style: a brand mark is a solid shape, and outlining one leaves the letterforms
inside Facebook and LinkedIn with no interior. The sprite carries
`symbol[id^="social-"] { fill: currentColor; stroke: none }` for exactly that reason.

**6.9 Course showcase.** The home page shows nine courses and changes them every fifteen seconds,
swapping only the showcase region rather than reloading the page. Up to ninety published courses the
window pages through them in order and wraps, so every course is seen once per lap; beyond ninety it
lands somewhere random instead, because paging would take too many laps to be a showcase. The nine
arrive with the page, so a reader without JavaScript sees a full showcase that simply does not move.
Core class: `#course-showcase`, partial `partials/course-showcase.html.twig`.

**6.10 Tag cloud.** The tags page shows its tags on arrival. Nothing is folded away behind a
disclosure or an accordion: the page exists to show the tags, so making the reader open something
first is the one thing it must not do. The animated sphere is decoration over the list, never a
replacement for it.

**6.11 Flash message.** A transient notice sits above the page head and takes its space with it when
it goes. Success hides itself after 4.5 seconds, info after 6.5, and every message carries a close
control; on the way out the *container* goes too once it holds nothing, or its bottom margin is left
holding open a gap the reader can no longer account for and the page head never returns to the top
of its boxed container. Owner's instruction, 2026/09/12, from Administration Settings after a save.
Core hooks the themes must emit: `.flash-stack` around the messages, `data-flash-message` on each,
`data-flash-close` on its control — the dismissal in `platform-overrides.js` finds the stack through
them. Note that `:empty` cannot do this in CSS: the whitespace text nodes between the messages
survive their removal, so the stack is never empty in the selector's sense.
→ Enforced by `FlashDismissalContractTest`.

---

## 7. Themes

**7.1 Gilded Noir is the acceptance theme.** Test there first; a defect there is a defect. Light
Default is the other theme that matters. Factory Reset must keep working because it is the recovery
theme. Factory Reset Sidebar must keep building and rendering.

Radiant Learning is no longer in that last category. From 2026/09/10 the owner worked in it and in
Factory Reset directly, and both received sustained investment: the boxed page container, the
third-level flyout, the page canvas and the identity band were all shaped there. Treat a defect in
either as a defect.

**7.2 The footer is visually distinct from what sits above it.** In a sidebar theme the footer must
not be the same colour as the sidebar. In Light Default the footer background is dark.

**7.3 Every theme's footer carries social media icons.**

**7.4 A boxed-container theme carries the box's rounded border through to its footer.** Radiant
Learning: rounded border on the container body, and a rounded footer spanning the page width.

**7.5 A theme never sources a functional page body.** Page bodies are core-owned in
`resources/views/pages/`. Themes own chrome and presentation only.
→ Enforced by `tools/check-runtime-hazards.php`.

**7.6 Anything user-visible that names the platform reads it from settings.** Never a literal.

**7.7 Core owns the `cl-` component namespace.** A theme may decorate canonical selectors,
qualified by its own theme root, but must not introduce competing structures or override functional
layout. Unqualified component rules can unexpectedly restyle newly introduced core elements; the
old footer defect came from theme rules targeting markup that did not yet exist. Palette variables
are the preferred boundary for shared surfaces.

**7.8 The palette is applied before the page paints, not after.** The reader's choice is rendered
into the markup as body classes from a cookie, so the first paint is already the chosen palette.
The switcher still sets the data attributes afterwards, and every palette rule matches both forms,
so nothing changes on screen when it does. Applying the palette only from script — which waited for
`DOMContentLoaded` and then for a fetch of `/theme/palette` to return — meant every page painted in
the theme's own colours and repainted a round trip later. That is the flash.

When duplicating a palette selector to match both forms, rewrite each selector in the list
individually. Replacing the prefix across a comma-separated list turns `body[…] .sidebar, body[…]
.navbar` into a rule that matches bare `body`, and paints the entire page in navigation colours.

**7.9 Palette colours are ordered darkest to lightest.** The platform reads the five positionally:
colour 1 is the darkest and drives navigation and ink, colour 3 is the middle tone the footer takes,
colour 5 is the lightest and drives the page canvas. A palette handed over in the order someone
happened to write it puts a mid tone where the ink belongs. The shipped palettes are Carnival Cotton
Candy, Coastal Blue and Forest & Sand.

---

## 8. Catalogue and public pages

**8.1 The catalogue shows the data that exists.** There is one kind of data and no universe switch.

**8.2 `/courses` keeps the Tier 1 category grid visible.** Page head, shared catalogue search,
all available top-level categories, then one dynamic workspace. Tiles form four desktop, three
tablet and two mobile columns, with generated category icons between 64 and 96 pixels. The initial
workspace shows at most twelve featured/default courses. It never preloads the whole catalogue.

Selecting a Tier 1 tile reveals its Tier 2 children as a flat rail. Selecting Tier 2 retains that
rail and reveals Tier 3 immediately below it. Selecting Tier 3 retains both rails and marks the
active root and branch. The service supplies this non-recursive navigation model; Twig does not
reconstruct the hierarchy. Ordinary category results show courses filed directly in that category.
Search includes the active category and all descendants; clearing search returns to direct browsing.
Category links and card breadcrumbs use `/courses/category/{slug}`. Recursive public accordions,
lazy-open fragments and the old `open` query parameter are obsolete.

**8.3 `/courses/tags` exposes the complete tag vocabulary as server-rendered links.** An optional
TagCloud.js sphere animates at most 80 labels beside the vocabulary on desktop; the two areas stack
on narrower screens. The vocabulary is a visible, keyboard-scrollable pane, never a disclosure.
Reduced motion disables the animation, and script failure leaves every real tag link usable.
Selecting `/courses/tag/{slug}` marks that tag, retains the browser and scopes search to the tag.
Tag chips on cards and in the browser use the same canonical component.

**8.4 Public pages render for a signed-out visitor.** A catalogue or tag page must not report the
visitor as logged out or refuse to render because there is no session.

**8.5 One workspace and one course grid serve category, tag and search results.** The canonical
course grid loops over the existing course-card partial. Shared pagination appears above and below
paged results. The public catalogue policy is fixed at 24 cards; unrelated administration/company
page-size policies stay unchanged.

**8.6 Progressive enhancement preserves ordinary GET navigation.** Categories, tags, search,
pagination and course cards all have real URLs or GET forms. The durable `catalogue-workspace`
contains `catalogue-region`, whose inner `catalogue-results` is swapped by htmx. Category links
request server-rendered out-of-band updates to the persistent category grid and shared search form,
keeping active state and scope correct. Search and pagination push meaningful URLs; full GET,
reload and browser history reproduce the same state.

**8.7 Core owns component geometry and behaviour.** Functional dimensions, touch targets, state
classes, overflow, responsive grids, htmx IDs and semantics live in core templates and
`public_html/css/catto-platform.css`. Themes may tint and decorate the canonical selectors through
palette, typography, borders, radii and shadows. They must not replace functional catalogue markup
or redefine the component layout. Factory Reset and the principal bundled themes share the same DOM.

---

## 9. Account and profile

**9.1 Profile is a popout submenu under Account** containing Personal Particulars, Email Addresses
and Social Media, each its own page.

**9.2 Personal Particulars carries the profile image.** Upload, resize, store in PostgreSQL as a
256x256 PNG.

**9.2.1 The reader orients, sizes and positions it, and sees the result before it is stored.**
Quarter-turn rotation, a zoom, a drag to choose what the square holds, and a live preview. Built as
a Stimulus controller over a form that already works: without JavaScript the file posts and the
server takes a centred square.

**9.2.2 An uploaded photograph is turned the way the camera says it was held.** A phone stores a
landscape bitmap and records the orientation in EXIF; a decoder that ignores the tag delivers every
upright photograph lying on its side. All eight EXIF values are handled, mirrored ones included.
→ Enforced by `ProfileImageTest`.

**9.3 Email addresses are login vectors.** The primary address is the one actively used to sign in;
the secondary is an alternative. Both are unique platform-wide. A user cannot change the address
they signed in with.

**9.4 Social Media holds the profile's outbound links:** LinkedIn, YouTube, GitHub, Google,
Facebook, Instagram, X, Threads, TikTok, WhatsApp, Telegram, Signal.

---

## 10. Data and companies

**10.1 One kind of data, all of it disposable** until the owner declares production. Do not ask to
preserve development data.

**10.2 A generated company's domain is `<name>.invalid`,** and mail to it is redirected to the
system domain.

**10.3 There is exactly one System company.**

**10.4 Company type is not a choice between alternatives.** A company can be both a client and a
course provider, so these are independent flags, and they are inferred from what the company does —
creating courses makes it a provider, buying courses makes it a client — rather than selected on a
form.

---

## 11. Routing and page identity

**11.1 A catch-all route is matched last.** A route with a placeholder that can also match a literal
route beside it declares a negative priority. Declaration order across controller files is decided by
the filename, so moving a controller between directories is enough to make one route swallow
another — and the symptom is a truthful 404 answering the wrong question.
→ Enforced by `RoutePrecedenceContractTest`.

**11.2 Every page renders through `BaseController`.** A controller that calls the view renderer
directly gets no identity model, so the page renders as though nobody is signed in. That is what
`/courses/tags` did.

---

## 12. Rendering hygiene

**12.1 HTML is indented with tabs, block structure only.** Never reflow an inline run: a newline
between a label and its input adds a rendered space.
→ Enforced by `ServedHtmlIndentationTest`.

**12.2 An attribute built from an expression is escaped by Twig.** Never build `style="…"` or any
other attribute by concatenating it inside a `{{ }}`; emit the attribute with `{% if %}` around it
and interpolate only the value. Three separate defects have come from this.
→ Enforced by `TemplateAttributeContractTest`.

**12.3 Template comments are plain prose.** No angle brackets, no comment delimiters, no template
tokens, not even as an example.

**12.4 A page is checked in a browser before it is handed over.** A passing validator is not proof a
UI change works.

---

## Changelog

2026/09/12 SAST — third pass

- 6.11: a flash message takes its container with it, so the page head returns to the top of the box.

2026/09/12 SAST — second pass

- 1.6a: elements in a row line up, and the mixed-element rows are where it goes wrong.
- 1.6b: Gilded Noir is insulated from cross-theme UI work; navigation is the exception.
- 6.1a: GN texture — the theme keeps its own and suppresses the platform canvas.
- 6.9: the nine-course home showcase, paging to ninety and sampling beyond it.
- 6.10: the tags page shows its tags on arrival, behind nothing.

2026/09/12 SAST

- Recorded the page shape the platform now uses throughout: 1.7 how every page is built, 1.8 that
  nothing renders on the page canvas and that the check is the delivered HTML, 1.9 the boxed
  container the body sits in.
- Navigation gained 5.5 to 5.7: marking the current entry at every level, why a group takes
  `nav-group-current` rather than `active`, why the state goes on the link and not on the icon
  inside it, why a flyout anchors to its own group, and keeping the scroll position across a load.
- Named elements 6.5 to 6.8: the page canvas, the identity band, the core-owned site footer and the
  social marks — including why the marks are the one exception to the stroked-icon rule.
- Themes gained 7.7 to 7.9: a theme may not define a rule in the `cl-` namespace, the palette is
  applied before the page paints rather than after, and palette colours are ordered darkest to
  lightest because the platform reads them positionally.
- 7.1 corrected. Radiant Learning was listed as not warranting investment; from 2026/09/10 the owner
  worked in it and in Factory Reset directly and both were shaped extensively, so a defect in either
  is a defect.

2026/09/10 09:15 SAST

- 0.2 now states which shapes are exempt and why, after applying it to every form on the platform:
  a modal footer already is the action row, and inline row, compact act and list controls are
  controls rather than forms.

2026/09/10 07:45 SAST

- Added section 0 — the owner's overriding rule that a UI which is not obvious is broken, the single
  action row every form ends in, and the spacing point that produced the report.

2026/09/10 07:10 SAST

- Added 9.2.1 and 9.2.2 after the owner reported two upright photographs importing rotated, and
  asked to orient, size and preview an image before storing it.

2026/09/10 04:50 SAST

- Added 2.5 (widths are measured), 4.4 (both full-width spellings), section 11 (routing and page
  identity), and the comment-stripping half of 12.3. All four came out of applying the rules above
  to every screen rather than from a new instruction.

2026/09/10 02:25 SAST

- Created. Records every interface rule the owner has given through 2026/09/10, including the
  slanted texture definition, the table column-width rule, the row-action stacking rule, the course
  card contract, the profile submenu and the company type inference.

## Commerce checkout (owner instruction, 2026/09/13)

- “Add to cart” stays on the course; “Buy now” adds the selection and opens checkout. Guests can build a cart before signing in.
- Put the count-bearing cart icon and hover summary on the right of the breadcrumb bar, with “Proceed to checkout now” and Checkout to its left. Keep the disclosure usable by keyboard and touch.
- Use My Cart (`/cart`). Account → COURSES contains My Orders (`/account/orders`) and My Courses (`/account/courses`); do not add a Purchases navigation item.
- Checkout collects sign-in, profile details, payment method and invoice-email preference before review and “Place my order”. Orders retain downloadable PDF invoices.
- A failed payment offers another method or paying later. Unpaid orders cancel after seven days; EFT uses the unique order number as its reference.

- Cart colours, opaque dropdown surfaces and button treatments belong to each theme. Core cart CSS owns geometry and interaction only. Gilded Noir uses its existing gold primary-button treatment and dark navigation surface, including buttons outside the main content area.

- Administration → Settings uses one shared-pattern accordion per section. Each editable section has its own save action; keep the saved section open. Bank details belong in `app_options`, never `.env`, and EFT orders display the current details and unique order reference.

## 13. Platform design system enforcement

**13.1 CattoLMS reusable UI structures are platform components. A page may not independently implement
a job already represented by a canonical component. Extend the component and its contract test
instead of forking markup.**

**13.2 The gallery is Administration → System → UI Components**, at `/admin/system/ui-components`,
protected by the existing System settings view permission. It demonstrates every component family
and supported visual/state variant using static data.

**13.3 A modal has one canonical footer.** Without scripts its same forms are accessible inline.
Core enhances it with visibility, focus trapping/restoration and Escape dismissal. Accordions are
native disclosures; workspace expansion can fetch the same server-rendered section progressively.

**13.4 Dataset composition delegates to the existing shared search and pagination.** Paired pagers
have unique control IDs, stable results targets and ordinary GET URLs. No page invents its own pager.
