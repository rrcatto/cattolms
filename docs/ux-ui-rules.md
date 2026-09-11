# Catto Learning UX/UI Rules

**LMS:** 0.7 (in development)  
**Date time:** 2026/09/10 02:25 SAST  
**Status:** permanent. This document accumulates; rules are amended or superseded here, never dropped.

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

**0.2 Every form you fill in and save ends in one action row.** `.cl-form-actions`, separated from
the fields by a hairline. A reader asking "how do I save this" should find the answer in the same
place on every form rather than scanning for whichever button happens to be primary-coloured. The
primary action comes first; a destructive one is `.cl-form-actions-secondary` and is pushed to the
far end, so Save and Remove are never a pair of equal-looking buttons.

A **modal** already has one: `.modal-foot` is the same rule wearing the modal's own name, with
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

**1.2 Before building any UI element, find the existing one and reuse it.** If none exists, build one
shared implementation and use it from the first caller onwards. If the shared control genuinely
cannot serve a case, say so and ask — do not fork it.

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

**2.3 No horizontal scrolling on a table that fits.** `.table-wrap` provides `overflow-x:auto` as a
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
→ Enforced by the `.row-menu-panel` grid rules in `catto-platform.css` and by
`tools/validate-ui-contracts.php`.

**3.2 Button labels are short.** "Manage", "Edit", "Remove" — not "Manage this company's people".
The context is the row.

**3.3 Every button carries an SVG icon to the left of its label.** The icon comes from the core
sprite; the theme styles it.

---

## 4. Forms

**4.1 Form fields have white space around them.** Fields are laid out with grid `gap`, never with
per-element margins that collapse. A submit button is separated from whatever precedes it whether or
not anything between them is hidden — see 0.3.

**4.2 Fields on the same row align vertically.** Labels sit on a common baseline and inputs on a
common top edge, whatever the label's length or whether one field carries help text. First name and
Middle names side by side must line up exactly.
→ Enforced by the `.field` / `.field-row` rules in `catto-platform.css` and `FormSpacingContractTest`.

**4.3 A form works without JavaScript.** htmx is progressive enhancement: every control degrades to
an ordinary form submission.

**4.4 A field marked full width is full width.** Both spellings work: `field-wide` and `field full`.
Core states both, because forty-two fields across eleven templates use the second and only one theme
out of five ever defined it — so a field the markup declared as full width rendered at half width
everywhere else.
→ Enforced by `FormSpacingContractTest`.

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

**6.2 Course card.** A course card carries, in this order: the course icon or image, the course name,
the description, the category breadcrumb with every ancestor category clickable, the tags under a
"Tags" label, the price, the level, the number of modules, the favourite star, and a "View course"
button. It does **not** show the access period. Cards lay out five across, up to five rows per page.
→ Enforced by `CourseCardContractTest`.

**6.3 Row menu.** The stacked action panel described in section 3.

**6.4 Dataset search, pagination, sortable header.** The three shared table controls in section 2.

---

## 7. Themes

**7.1 Gilded Noir is the acceptance theme.** Test there first; a defect there is a defect. Light
Default is the other theme that matters. Factory Reset must keep working because it is the recovery
theme. Factory Reset Sidebar and Radiant Learning must keep building and rendering, but do not
warrant investment.

**7.2 The footer is visually distinct from what sits above it.** In a sidebar theme the footer must
not be the same colour as the sidebar. In Light Default the footer background is dark.

**7.3 Every theme's footer carries social media icons.**

**7.4 A boxed-container theme carries the box's rounded border through to its footer.** Radiant
Learning: rounded border on the container body, and a rounded footer spanning the page width.

**7.5 A theme never sources a functional page body.** Page bodies are core-owned in
`resources/views/pages/`. Themes own chrome and presentation only.
→ Enforced by `tools/check-runtime-hazards.php`.

**7.6 Anything user-visible that names the platform reads it from settings.** Never a literal.

---

## 8. Catalogue and public pages

**8.1 The catalogue shows the data that exists.** There is one kind of data and no universe switch.

**8.2 `/courses` lists categories, not every course.** The course list belongs behind a category or
a tag.

**8.3 `/courses/tags` is an animated three-dimensional tag cloud.** Clicking a tag shows that tag's
courses as paginated course cards, five across, up to five rows per page.

**8.4 Public pages render for a signed-out visitor.** A catalogue or tag page must not report the
visitor as logged out or refuse to render because there is no session.

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
