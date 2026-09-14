# Catto Learning LMS Theme SDK 3.1

**Schema:** 3.0 · **Template API:** 1.0 · **Reference LMS:** 0.5.8.2  
**Date time:** 2026/08/24 17:45 SAST  

This file is self-contained. A human or AI theme author can build a compliant theme without the LMS source code or any other project document.

This document needs to be updated for CattoLMS v0.7 - it is out of date

## 1. Boundary

Themes are immutable **presentation** packages.

- **Core owns:** routes, authentication/ACL, data, business rules, functional page bodies, forms/business controls, progress/assessment state, modal/dropdown behaviour, standard navigation/footer destinations and palette persistence.
- **Theme owns:** shell/layout, typography, CSS, visual icons, responsive presentation, presentation-only JS and assets.

Never reimplement permissions, keep a second hard-coded route catalogue, replace core controls, or make a workflow depend on theme JS.

### Platform UI components

Reusable functional structures are owned once by core under `resources/views/ui/`, exposed by the
presentation-only `PlatformUi` service. Catalogue/category/tag pages compose those components and
the existing shared search, pagination, course-card and favourite controls. A theme must not supply
alternative category, tag or course-results DOM.

Core CSS owns the category grid's 4/3/2 responsive columns, 64–96px category icons, flat Tier 2/Tier 3
rails, course grid's 4/3/2/1 reflow, 24-card public pagination, touch dimensions and required overflow.
Themes style palette, fonts, borders, radii, shadows and decoration without replacing these
functional rules, behaviour hooks, htmx targets or accessibility attributes. Use the canonical
selectors; every component remains usable under Factory Reset without theme-specific markup.

## 2. Package

Minimum standalone theme:

```text
theme.json
base.html
public/css/theme.css
```

A full production theme should also provide `pages/` wrappers, reusable partials and assets appropriate to its design. Theme templates are Twig (`.html.twig`) and contain no PHP. `public/css/theme.css` must exist and be non-empty; core loads it automatically, so do not list it in `theme.json.styles`.

`base.html` must preserve:

```html
<repeat group="{{ @platform.styles }}" value="{{ @resourceUrl }}">
  <link rel="stylesheet" href="{{ @resourceUrl }}">
</repeat>
{{ @content | raw }}
<repeat group="{{ @platform.scripts }}" value="{{ @resourceUrl }}">
  <script src="{{ @resourceUrl }}"></script>
</repeat>
```

Use `@theme.asset_url` for package assets. ZIP files may use archive root or one enclosing directory. Package paths must be relative/safe; external resources must be HTTPS.

### Manifest

```json
{
  "format": "catto-learning-theme",
  "schema_version": "3.0",
  "template_api": "1.0",
  "theme": {
    "name": "Example Theme",
    "slug": "example-theme",
    "version": "1.0.0",
    "author": "Example",
    "created_at": "2026-08-19T04:00:00+02:00",
    "description": "A complete Catto Learning theme."
  },
  "parent": null,
  "styles": [],
  "scripts": [],
  "external": {"styles": [], "scripts": []}
}
```

Every standalone theme defines its own `base.html`; there is no Factory Reset fallback. A child may inherit from exactly one installed standalone parent **name+version**, may omit `base.html`, and must still supply a non-empty `theme.css` with a real override. Child-of-child inheritance is unsupported.

## 3. Page families

Supported wrappers under `pages/`:

```text
home  catalogue  course-detail  auth  account  library  course-player
assessment  certificate  commerce  company  admin  error  content
```

A minimal valid theme may omit wrappers. A **full theme** should deliberately cover all 14 families. Every wrapper must preserve:

```html
{{ @content | raw }}
```

because detail/editor pages may have no specialised workspace object.

## 4. Core template objects

| Object | Purpose |
|---|---|
| `@app` | application name/base URL |
| `@platform.styles`, `@platform.scripts` | required core resources |
| `@theme` | theme identity/resources/asset URL |
| `@page` | family/title/subtitle/body class |
| `@user` | logged-in state, name, roles |
| `@navigation` | permission-filtered primary hierarchy |
| `@footer_navigation` | standard footer links |
| `@breadcrumbs` | ordered breadcrumb objects |
| `@flash` | flash messages |
| `@content` | rendered platform functional HTML |

Relevant arrays may be empty; a theme must remain safe in that case.

## 5. Navigation and icons

Render `@navigation`. Do not hard-code Account, Company or Administration routes.

Primary item fields:

```text
key, label, href, icon, active, method, csrf, children
```

Child fields:

```text
key, label, href, icon, active, method, children
```

`active` is supplied at every level, not only on primary items: a child is active when it leads to
the page being read, and a group is active when the page is one of its own children. Render it —
a theme that marks only the primary item leaves a reader three levels deep with no indication of
where they are.

Put the class on the element itself. Two shipped themes had their child anchor carry no class of
its own, and the state was added to the `nav-icon` inside the link instead, where nothing styles it.

A **group** takes `nav-group-current`, not `active`. The palette paints anything carrying
`data-nav-item` and `active` as a filled pill, which is right for a link and turns a group — a
container of several links — into a solid block with its heading inside it.

A child may itself hold `children`. That third level is not optional decoration: Administration is
grouped rather than flat, and a theme that renders only two levels shows twelve sections as one
undifferentiated list. Render a group as a heading plus its members.

Core supplies the classes and the behaviour, so a theme needs no JavaScript and no state of its own:

```html
<div class="nav-group" data-nav-item="{{ @child.key }}">
  <button class="nav-group-label" type="button" aria-haspopup="true">
    icon, then {{ @child.label }}, then a span.nav-group-caret
  </button>
  <div class="nav-subpanel">
    one anchor per {{ @child.children }} member
  </div>
</div>
```

`catto-platform.css` keeps `.nav-subpanel` hidden and reveals it on `:hover` and `:focus-within`, so
the members fly out to the side of the parent panel and the whole control works with scripting off.
Two things about it are load-bearing:

- **The heading is a `button`, not a `span`.** The members are `display:none` until the group opens,
  so unless something inside the group can take focus first, Tab can never reach them and the level
  becomes mouse-only. A span renders identically and silently removes keyboard access.
- **Add `nav-flyout-left` to the group** when the parent panel is right-aligned in a top bar. The
  default opens to the right, which leaves the viewport on a right-aligned menu. A left rail wants
  the default.

Below 1080px core returns the members to an indented list, because a narrow viewport has nowhere to
fly out to and a finger has no hover. A theme adds colour: the flyout panel carries its own
background and text colour rather than inheriting, so a dark menu must restate them on
`.nav-subpanel` or it will be handed the light page's ink.

Logout is POST and must preserve its supplied CSRF token.

Canonical hierarchy:

```text
Home /                         Catalogue /courses
Help /help                     Contact /contact

Account /account
  All sections /account
  Dashboard /account/dashboard
  Profile                                [group]
    Personal Particulars /account/profile
    Email Addresses /account/emails
    Social Media /account/social
  My Courses /account/courses
  Sessions /account/sessions
  Activity /account/activity

Company /company                         [permission-gated]
  All sections /company
  Dashboard /company/dashboard
  People                                 [group]
    People /company/people
    Course Requests /company/requests
    Enrolments /company/enrolments
    Performance /company/performance
  Courses                                [group]
    Courses Created /company/courses
    Favourites /company/favourites
    Courses Bought /company/training
    Credits /company/credits

Administration /admin                    [permission-gated]
  All sections /admin
  Dashboard /admin/dashboard
  Courses                                [group]
    Courses /admin/courses
    Course Categories /admin/courses/categories
    Course Tags /admin/courses/tags
    Course Requests /admin/course/requests
    Enrolments /admin/course/enrolments
  People & Companies                     [group]
    People /admin/people
    Course Consumers /admin/companies
    Course Creators /admin/companies/creators
  Credits                                [group]
    Credits /admin/course/credits
  Insights                               [group]
    Activity /admin/activity
    Course Performance /admin/reports
    Company Enrolments /admin/reports/companies
  System                                 [group]
    Themes /admin/themes
    Roles & ACL /admin/roles
    Seed Database /admin/seed
    Settings /admin/settings
```

Both the Company and the Administration menus are three levels. A theme that renders only two still
produces working links, because a group's own `href` is its first member.

A `[group]` row is a child carrying `children`; its own `href` is its first member, so a theme that
renders only two levels still produces a working link rather than a dead one.

Core may omit unauthorised items. Account children must not contain a second `/account` dashboard duplicate.

**Icon ownership:** core owns the stable semantic `key` and may provide `icon` as a fallback hint; the theme owns actual icon artwork. Themes may map `key` to an SVG sprite, icon font or CSS icon. Example:

```html
<svg aria-hidden="true"><use href="{{ @theme.asset_url }}/img/icons.svg#nav-{{ @item.key }}"></use></svg>
<span>{{ @item.label }}</span>
```

If custom icons are used, cover every received key and retain readable labels.

## 6. Footer and breadcrumbs

**The footer is core-owned markup.** Since 2026/09/11 a theme's `partials/footer.html.twig` includes
core's rather than writing its own:

```twig
{% include '@platform/partials/site-footer.html.twig' %}
```

Keep the file — the Theme Package format expects a theme to carry one — but the markup, the standard
links and the social marks come from core. This replaced five inline footers that had drifted apart
and none of which gave the footer a surface of its own. A theme changes how the footer looks by
changing the palette it is coloured from.

The footer sits **outside** the theme's boxed page container, where the theme has one: it is the end
of the page rather than part of its content.

`@footer_navigation` is still supplied and is what the core footer renders. Items are
`{key,label,href}`. Public links include Home, Catalogue, Help, Contact and Privacy;
Account/Company/Administration appear when authorised. `@footer_social` accompanies it — items are
`{key,label,href}`, keyed to `SocialPlatform`, and a key with no configured address still renders
its mark but is not wrapped in a link.

`@breadcrumbs` items are `{label,href,current}`; ancestors are linked and the current item is normally unlinked.

## 7. Workspace APIs

The names below are literal. Plural/singular arrays are structurally stable for their family. Each populated section is:

```text
key, label, icon, route, template, description, content
```

`content` is already-rendered core HTML. Themes may arrange complete sections but must not replace their functional controls/data.

| Route type | Administration | Account | Company |
|---|---|---|---|
| consolidated | `@admin.sections` | `@account.sections` | `@company.sections` |
| standalone section | `@admin.section` | `@account.section` | `@company.section` |
| detail/editor fallback | `@content` | `@content` | `@content` |

Canonical Account sections: Dashboard, Profile (Personal Particulars, Email Addresses, Social Media), My Courses, Sessions, Activity.
Canonical Company sections: Dashboard, People, Course Requests, Learning, Course Credits, Courses.  
Canonical Administration sections are the navigation children listed above.

Safe pattern:

```html
<check if="{{ @admin.sections }}">
  <true>
    <repeat group="{{ @admin.sections }}" value="{{ @section }}">
      <details><summary>{{ @section.label }}</summary>{{ @section.content | raw }}</details>
    </repeat>
  </true>
  <false>
    <check if="{{ @admin.section }}">
      <true>{{ @admin.section.content | raw }}</true>
      <false>{{ @content | raw }}</false>
    </check>
  </false>
</check>
```

`@section` above is only the loop variable, not the literal `@admin.section` object. Use the equivalent structure for Account/Company.

## 8. Details/accordion contract

When styling native `<details>/<summary>`:

- preserve keyboard/accessibility behaviour;
- provide useful touch height/padding and wrapping;
- show clear open/focus states;
- if drawing a custom chevron, suppress the native marker so only one right-aligned indicator appears;
- do not add business JS to native details interaction.

## 9. Forms and contact honeypot

Style ordinary inputs, selects, textareas, checkboxes/radios, fieldsets, validation/help text and actions.

The Contact form contains a core anti-bot field:

```text
name="website"
container class="cl-honeypot"
```

It is **not** a human Website field. Bots are expected to fill it; core discards those submissions. `.cl-honeypot` must remain invisible/non-interactive. Core CSS hides it and theme CSS must not override that rule.

## 10. Tables and application surfaces

A complete theme must style, not replace, platform tables/forms. Cover headers/rows, overflow, actions, badges, filters, empty states, pagination and dense Admin/Company/Account screens. Do not hide pagination totals/page-size controls. Current platform direction is a consistent 25/50/100 page-size selector once the core pagination contract is applied.

## 11. Styling completeness

A production theme should intentionally style:

- canvas/frame, spacing, typography, links/focus;
- navigation, dropdown/drawer, footer, breadcrumbs;
- headings, helper text and prose;
- all button/action states;
- forms and validation;
- tables/filtering/pagination;
- cards/lists/metrics/callouts/empty states;
- tabs, accordions, alerts, badges, progress/timers;
- modals without breaking core Close/Cancel/Escape behaviour or allowing theme header/footer stacking contexts to paint above an open modal;
- catalogue, course detail, pricing/favourites/actions;
- course player, modules, outcomes and imported content wrapper;
- assessments, options, answers, results, certificates;
- Account, Company, Administration and Roles & ACL;
- login, registration, Contact, Privacy, Help and Error;
- desktop/mobile and relevant print states.

Home-page styling alone is not a complete theme. Review actual LMS pages with representative data.

## 12. Palettes

`palettes` is optional:

- none: fixed theme colours; no core switcher;
- one: core auto-deploys it; no switcher;
- two or more: core switcher appears;
- 3–5 recommended, >5 may warn;
- each palette has exactly five six-digit hex colours.

Do not ship a competing palette switcher in a palette-enabled theme.

## 13. JavaScript boundary

Theme JS may handle presentation such as responsive drawers or decorative transitions. It must not own ACL, routes, LMS state, grading/progress, payment state, business-form persistence, palette persistence, or core modal/dropdown business behaviour. The theme should degrade safely if presentation JS fails. If the theme gives `main`, headers, footers or framed shells their own `position`/`z-index` stacking contexts, it must ensure an open core `.modal-backdrop` remains above those layers; fix that in theme CSS rather than replacing core modal JavaScript.

## 14. Accessibility and resilience

Require visible focus, sufficient contrast, labels for controls, no hover-only critical action, responsive navigation/tables/forms, text labels beside primary icons, correct logout POST/CSRF semantics, and preservation of all required controls/data.

## 15. Validation and acceptance

Before publishing:

1. validate manifest/package paths and required base contracts;
2. syntax-check JS and validate SVG/XML;
3. ensure wrappers retain `{{ @content | raw }}`;
4. inventory CSS/JS/images/fonts and resolve every asset reference;
5. verify no legacy hard-coded navigation conflicts with core arrays;
6. verify the honeypot remains hidden;
7. review every first-level public/Account/Company/Admin destination at desktop/mobile widths;
8. review forms, tables, pagination, accordions, tabs, modals, alerts, empty/destructive states with representative data;
9. treat actual runtime rendering as the final visual authority.

Theme Manager inspection should disclose meaningful package inventory, including image assets.

## 16. Versioning and repair

New design/functionality gets a new theme version. A defective artifact may be repaired and reissued with the same name/version; uninstall the defective installed copy before reinstalling that exact release. Installed theme releases are immutable and coexist by identity/version.
### Breadcrumb cart

Include `@platform/partials/cart-summary.html.twig` at the right of the breadcrumb bar for guests and signed-in users. Core supplies `cart_summary` (items, count, total_label). The shared disclosure supports hover, focus and click; it links to `/cart` and `/checkout`. Account → COURSES contains My Orders (`/account/orders`) and My Courses (`/account/courses`). There is no top-level Purchases menu.

Themes must style `.cl-cart-count`, `.cl-cart-panel` and `.cl-cart-line` with their own palette and give the panel an opaque surface. Apply primary-button styling inside `.cl-cart-chrome` as well as the main content wrapper; breadcrumb actions must not fall back to Bootstrap’s blue. Core cart CSS supplies no palette colours.
