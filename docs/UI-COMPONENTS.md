# Platform UI component guide

**LMS:** 0.8.8.10 · **Updated:** 2026-10-07 · **Registry:** 35 components in `src/View/Ui/PlatformUi.php`

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
| `form.choice` | `forms/choice-field.html.twig` | `control` |
| `form.tree-select` | `forms/tree-select.html.twig` | — |
| `form.picture-choice` | `forms/picture-choice.html.twig` | — |
| `form.compact-action` | `forms/compact-action.html.twig` | `body` |
| `data.table` | `data/data-table.html.twig` | `columns`, `header`, `body`, `footer`, `empty` |
| `data.dataset` | `data/dataset-layout.html.twig` | `toolbar`, `results`, `preview` |
| `data.stat-grid` | `data/stat-grid.html.twig` | `body` |
| `data.stat-card` | `data/stat-card.html.twig` | — |
| `data.list` | `data/item-list.html.twig` | `body` |
| `data.list-item` | `data/list-item.html.twig` | `body`, `actions` |
| `data.key-value-list` | `data/key-value-list.html.twig` | — |
| `feedback.badge` | `feedback/badge.html.twig` | — |
| `feedback.notice` | `feedback/notice.html.twig` | `body` |
| `feedback.empty` | `feedback/empty-state.html.twig` | `body`, `actions` |
| `feedback.progress` | `feedback/progress.html.twig` | — |
| `overlay.modal` | `overlay/modal.html.twig` | `modal_body`, `modal_actions`, `additional_forms` |
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
- A picture choice (`form.picture-choice`) is a fieldset with a `legend`, an optional `help` and `id`, and one native radio per `{value, label, image, note?}` item, the picture inside the radio's label with empty alt text; `image` is a path on this site and `selected` is the checked value. The choice is sent with the form's own Save, so it works without JavaScript (certificate looks, word placement and a course's certificate design).
- A tree select (`form.tree-select`) is one form-control row opening a native `popover` that lists `{value, label, depth 0–3, path}` options indented by depth; the closed control shows the chosen path, the chosen option carries `aria-current` and `autofocus`, and in the default `mode: submit` each option is a submit button carrying its value, so choosing one submits the enclosing form without JavaScript (the catalogue category picker). `mode: field` renders the options as radios in a `radiogroup`, so the choice is stored and sent with the form's own Save (the category Parent picker); core script shows the chosen path, closes the list on a pointer choice, Enter or Space, and leaves it open while arrow keys move the choice. The panel anchors below its toggle where CSS anchor positioning exists. Stat grids take `density: compact` for many small counts, keeping two or more per row on a phone. Tables own the wrapper, table, head and body. Rows/cells remain authored slots and column headers delegate to sortable-header. Datasets own the stable region/results IDs and delegate search and paired pagers to existing partials. A preview slot supports bounded workspace previews.
- Badges: neutral, info, success, warning, danger or permanent; small or normal. Notices: info, success, warning or danger, with heading/body and optional canonical flash dismissal. Empty states support heading, summary, body and actions. Progress validates finite min/max/value, rejects an invalid range, clamps value, and derives the only inline width from that validated number.
- Modals: normal or wide, one title/body/footer and optional native form. Without JavaScript the same content is inline; core adds visibility, focus trapping/restoration and Escape dismissal. Accordions are native details/summary. Explicit workspace semantics retain existing lazy section GET navigation and server-rendered content.
- Icons use the platform SVG sprite. Decorative icons are hidden from assistive technology; meaningful icons require a label. Catalogue icon artwork remains trusted data from the existing icon service, not a general-purpose HTML property.

The registry defaults are the authoritative property reference. Add a property there only when a concrete caller needs a reusable semantic or existing behaviour contract, and test it.

## Existing canonical controls

Page head, dataset search, pagination, sortable header, entity lookup, course card, favourite, identity, site footer and cart chrome remain shared partials. Components delegate to them rather than replacing them. Category and tag pages still share the persistent/flat catalogue workspace, 24-card results policy, bounded initial twelve courses and progressive GET/htmx navigation.

Supported uses added in v0.8.8.x, each through the existing component rather than a new one:

- **Course card for bundles.** `partials/course-card.html.twig` (the owner of `cl-course-card`) accepts optional `href`, `link_label` and `facts` (the key-value rows). The bundle catalogue passes bundles through `catalogue.course-grid` with these, so a bundle is presented by the course card; `UiComponentContractTest` forbids a second card or grid.
- **Totals.** Cart, checkout review and order pages show Subtotal / Promo / Total with `data.key-value-list`, built once by `Commerce\Domain\OrderTotals`.
- **Promo code.** The checkout's promo code field is a `form.compact-action` (one value, one immediate action); Remove is an `action.button` with an `aria_label` naming the code.
- **Entity lookup types.** `/admin/lookup/{type}` serves `people`, `companies`, `courses` and `bundles`; the bundle editor adds courses and the promotion editor adds courses and bundles through `partials/entity-lookup.html.twig` (pass `lk_required`). Its search runs on keyup; a selection fills the hidden input.
- **A live preview from the form being edited.** The certificate design editor and a course's certificate page have Save and Update preview in one form. Update preview is an `action.button` with `form_action` (the preview route), `form_target` (the preview frame's name) and `skip_validation`, so without JavaScript it draws the unsaved form into the frame; the `certificate-preview` Stimulus controller presses it a moment after any input. The preview frame is `partials/certificate-preview.html.twig`.
- **Generated documents on a page.** A certificate appears only as its PDF, in the browser's own PDF viewer: previews in `partials/certificate-preview.html.twig` (an `iframe` named for the form's target, with an Open the PDF link), and an issued certificate on its public page in an `<object class="cl-certificate-pdf">` whose fallback is a download link. Never inject rendered document HTML into a page with `|raw`. Certificate wording is edited in CKEditor with the certificate profile (`public_html/js/ck-certificate.js`, loaded by `load_certificate_editor`), which allows only the certificate's text styles, bold, italic, alignment classes and field labels; the textarea it replaces stays usable without JavaScript.
- **Sortable trees.** Course Content and the course categories render the shared tree markup - `li.cl-tree-node[data-node-id]` rows with `data-depth` and `data-height`, each holding a `.cl-tree-row` with a `.cl-tree-handle` (hidden until the script runs), an optional `.cl-tree-toggle` disclosure and `.cl-tree-name`/`.cl-tree-meta`, and an `ol.cl-tree-children[data-parent]` for a row that can hold others - styled once in core CSS and driven by `assets/lib/sortable_tree_controller.js`, which each tree's Stimulus controller extends. Row actions are canonical `action.link`, `action.button` and a compact `action.row` ⋯ menu of move forms (`data-tree-direction`), so every move works without JavaScript. The category tree's disclosure buttons are submit buttons of a GET form, so its branches open without JavaScript too; it starts closed (`cl-tree--categories`), Course Content starts open.
- **Ordered lists with actions.** The bundle editor's course list uses Move up / Move down / Remove buttons as ordinary forms (`cl-bundle-courses`), the same pattern as course price variants; it is not a second drag-and-drop tree.

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

### Shared page ownership in v0.8.5

The registry has 35 components (the tabbed course editor added `layout.tabs`, which also renders the Courses and Companies group navigation; the catalogue's `form.tree-select` replaced the category grid, tile, filter rail and filter pill; certificate designs added `form.picture-choice`); canonical shell partials do not introduce a parallel component API. Theme shells compose the following core partials:

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
