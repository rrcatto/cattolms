# CattoLMS Theme SDK

**Package schema:** 4.0 · **Template API:** 2.0 · **LMS:** 0.8

Themes are presentation packages. Symfony owns routing, authentication/ACL and application
composition; DBAL repositories own persistence; platform Twig owns functional page bodies and
components. Themes own their shell, typography, palette, decorative imagery and visual treatment.

## Package structure

A standalone package contains `theme.json`, `base.html.twig` and a non-empty
`public/css/theme.css`. Optional `pages/*.html.twig` wrappers cover home, catalogue, course-detail,
auth, account, library, course-player, assessment, certificate, commerce, company, admin, error and
content. Optional partials and public assets belong to the same package. ZIPs may contain the
package at their root or under one enclosing directory. Paths must be safe relative paths.

```json
{
  "format": "catto-learning-theme",
  "schema_version": "4.0",
  "template_api": "2.0",
  "theme": {
    "name": "Example Theme",
    "slug": "example-theme",
    "version": "1.0.0",
    "author": "Example",
    "created_at": "2026-09-15T12:00:00+02:00",
    "description": "A CattoLMS presentation theme."
  },
  "parent": null,
  "styles": [],
  "scripts": [],
  "external": {"styles": [], "scripts": []}
}
```

Core loads `public/css/theme.css`; do not list it again in styles. A child names one installed
standalone parent by its name and version, may inherit the base, and must supply a non-empty CSS
override. Child-of-child inheritance is unsupported. `ThemePackageValidator` enforces the manifest,
paths and template boundary. Installed files are authoritative; the database registry is rebuildable.

## Twig composition

Platform pages extend the resolved theme layout and supply `page_body`. A base retains that block,
the required resource arrays, navigation, flash messages and appropriate footer. For example:

```twig
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ page.title }}</title>
    {% for resourceUrl in platform.styles %}<link rel="stylesheet" href="{{ resourceUrl }}">{% endfor %}
</head>
<body>
    {% include '@theme/partials/navigation.html.twig' %}
    {% include '@platform/partials/flash-messages.html.twig' %}
    <main>{% block page_body %}{% endblock %}</main>
    {% include '@platform/partials/site-footer.html.twig' %}
    {% for resourceUrl in platform.scripts %}<script src="{{ resourceUrl }}"></script>{% endfor %}
</body>
</html>
```

Family wrappers extend the base and retain the `page_body` block, optionally surrounding it with
family decoration. They do not replace core forms, table rows or catalogue results. Use
`theme.asset_url` for package assets and `platform.icon_sprite` for the platform navigation sprite.
Twig escapes text and attributes; keep explanatory comments in Twig comments.

## View objects and navigation

`ThemeRenderer` supplies `app`, `platform`, `theme`, `page`, `user`, `navigation`, `footer_navigation`,
`breadcrumbs`, flash data and the family objects. Arrays may be empty. Strict Twig variables are
intentional: use the documented objects and conditional defaults for optional values.

Render the permission-filtered navigation hierarchy, including active state at every level. Entries
carry key, label, href, icon_id, active, method, csrf and children as appropriate. Preserve POST and
CSRF for logout. Keep data-nav-item on destination links. Nested navigation groups use the core
nav-group/nav-subpanel hooks. A scrolling sidebar nests the third level within its scroll area;
other navigation can use the core popout. Preserve visible keyboard focus and accessible mobile
navigation controls. Navigation destinations are not a theme-maintained route catalogue.

The current menus provide Account (All sections, Dashboard, Profile, My Courses, Sessions,
Activity), Company (All sections, Dashboard, People, Course Requests, Course Credits and course/
learning sections), and Administration (Dashboard, People, companies, courses, Roles & ACL,
Themes, Settings and UI Components). Registries supply the exact labels, order and permission
filtering; these examples do not authorise hard-coded destinations in themes.

The standard footer destination array is core-owned. Factory Reset and its related themes delegate
to the site-footer partial. Gilded Noir retains its individual footer composition using the same
standard destinations and platform action component. Do not replace its footer with another theme's.

Account, company and admin family models expose `sections` and `section` for composed workspace and
standalone contexts. Their content is already-rendered platform content. Core page bodies own the
canonical accordions and controls; a theme styles them instead of rebuilding the workspace.

## Platform UI components

[UI-COMPONENTS.md](UI-COMPONENTS.md) lists the complete namespaced registry and Twig slots. Components
live under `resources/views/ui/{layout,actions,forms,data,feedback,overlay,helpers,catalogue}`.
`ui()` renders structured presentation properties; `ui_template()` and `ui_props()` compose authored
slots. Unknown names and properties are errors. No raw-HTML, arbitrary class/style, dynamic template
path, deprecated name or compatibility alias is supported.

Canonical classes include `cl-ui-surface`, `cl-ui-section-head`, `cl-ui-action`, `cl-ui-field`,
`cl-ui-form-actions`, `cl-ui-table`, `cl-ui-stat-card`, `cl-ui-list`, `cl-ui-key-value-list`,
`cl-ui-badge`, `cl-ui-notice`, `cl-ui-empty-state`, `cl-ui-progress`, `cl-ui-modal` and
`cl-ui-accordion-section`. Themes decorate these existing elements; they cannot emit duplicate
functional structures. Existing shared search, pagination, sortable headers, entity lookup, course
cards and favourites remain canonical.

Core owns responsive columns, stable IDs/htmx targets, minimum functional spacing, native GET/POST,
required overflow and accessible states. Themes own colours, typography, borders, radii and shadows.
In particular, Gilded Noir keeps its engraved canvas, paper surfaces, gold actions and navigation
identity chip while consuming the same components.

A filled multi-field form ends in one `form.actions` row. An immediate one-value operation uses
`form.compact-action`. Modal content has one canonical footer; do not nest form action rows inside
it. Native accordions retain summary keyboard behaviour and one indicator. With scripts disabled,
modal forms remain available inline. Core handles enhanced visibility, Escape, focus trapping and
focus restoration. Theme stacking contexts must never cover an open modal.

Tables use the core width/row system. Row menus must escape their cells without clipping. Do not
hide totals, pagers, actions or columns to create a different functional screen. Search and paired
pagination retain real GET URLs; themes do not rebuild either control. Catalogue grids retain core
responsive columns, persistent Tier 1 tiles, flat Tier 2/Tier 3 rails and the shared results workspace.

The Contact `website` field inside `cl-honeypot` is deliberately invisible and non-interactive.
Never style it as a human-facing field or override its hidden treatment.

## Palette and script boundary

Palettes are optional. Each palette has exactly five six-digit hex colours. No palettes means fixed
colours; one is applied automatically; multiple palettes use the core palette switcher. Do not ship
a competing switcher or palette persistence. Core cart chrome exposes `cl-cart-count`,
`cl-cart-panel` and `cl-cart-line`; give them opaque theme surfaces and suitable action colours.

Theme JavaScript can enhance a drawer or decorative transition. It cannot own ACL, grading,
progress, payment state, persistence or core modal/dropdown behaviour. All essential navigation and
forms work without JavaScript. Fonts and other declared external resources use HTTPS. Required core
resources remain present even when a theme adds its own resources.

## Development validation

Use `composer themes:install -- --force` to replace bundled installed themes during development,
then publish the core assets and compiled AssetMapper output to the instance web root. ThemeManager
owns theme installation and publication. Do not manually maintain a parallel theme structure.
Version changes and release packaging require the owner's instruction.

Run `composer qa`, inspect `/admin/system/ui-components`, and review actual login, account,
administration, company, catalogue/tag, checkout and learning pages at desktop/tablet/mobile sizes.
Check labels/help/errors, empty states, actions, modal focus and stacking, accordion expansion,
pagination, htmx replacement and plain GET/POST fallbacks. Gilded Noir, Light Default, Factory Reset
and Radiant Learning need individual visual checks; Factory Reset Sidebar must remain structurally
sound. The component ownership contracts scan bundled theme templates as well as platform views.
