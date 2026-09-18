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
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ page.title }} · {{ app.name }}</title>
    {% for url in platform.styles %}<link rel="stylesheet" href="{{ url }}">{% endfor %}
    {% for url in theme.external_styles %}<link rel="stylesheet" href="{{ url }}">{% endfor %}
    {% for url in theme.styles %}<link rel="stylesheet" href="{{ url }}">{% endfor %}
</head>
<body class="cl-body {{ page.body_class }}" data-cl-navigation="top">
    <a class="cl-skip-link" href="#cl-main">Skip to content</a>
    <div class="cl-shell">
        <header class="cl-header" data-theme-nav>
            <div class="cl-header-inner">
                <a class="cl-brand" href="{{ app.base_url }}">
                    <span class="cl-brand-mark" aria-hidden="true">CL</span>
                    <span class="cl-brand-copy"><strong>{{ app.name }}</strong></span>
                </a>
                <button class="cl-nav-toggle" type="button" data-cl-nav-toggle
                        aria-expanded="false" aria-controls="cl-primary-navigation">Menu</button>
                {% include '@platform/partials/navigation.html.twig' %}
            </div>
        </header>
        <div class="cl-page">
            <section class="cl-page-context" aria-label="Page context" data-theme-breadcrumb>
                <div class="cl-page-context-inner">
                    {{ ui('layout.breadcrumb', {items: breadcrumbs}) }}
                    {% include '@platform/partials/cart-summary.html.twig' %}
                </div>
            </section>
            <div class="cl-page-frame">
                <main class="cl-main" id="cl-main">
                    {% include '@platform/partials/flash-messages.html.twig' %}
                    {% block page_content %}{% block page_body %}{% endblock %}{% endblock %}
                </main>
                {% include '@theme/partials/footer.html.twig' %}
            </div>
        </div>
    </div>
    {% for url in platform.scripts %}<script src="{{ url }}"></script>{% endfor %}
    {% for url in theme.external_scripts %}<script src="{{ url }}"></script>{% endfor %}
    {% for url in theme.scripts %}<script src="{{ url }}"></script>{% endfor %}
</body>
</html>
```

For sidebar navigation, set `data-cl-navigation="sidebar"` and replace the header with
`aside.cl-sidebar[data-theme-nav]`, containing the same brand, toggle and core navigation include.
The page/context/frame/main/footer construction remains identical. Factory Reset and Factory Reset
Sidebar use this geometry; Radiant Learning, Light Default and Gilded Noir use the top-header geometry.
Do not branch between guest and authenticated shells. Core supplies both navigation states.

Core enhances `[data-cl-nav-toggle]` with expanded state and Escape/focus restoration. With JavaScript
disabled the menu remains visible; narrow top headers stay in normal flow so navigation cannot
cover page controls. At narrow widths the open enhanced menu also scrolls with the document. Scrollable sidebars keep nested navigation within their rail.
Themes retain decoration, header/sidebar dimensions and unique elements; do not hide core navigation
independently or install a second toggle handler.



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
cl-nav-group/cl-nav-subpanel hooks. A scrolling sidebar nests the third level within its scroll area;
other navigation can use the core popout. Preserve visible keyboard focus and accessible mobile
navigation controls. Navigation destinations are not a theme-maintained route catalogue.

The current menus provide Account (All sections, Dashboard, Profile, My Courses, Sessions,
Activity), Company (All sections, Dashboard, People, Course Requests, Course Credits and course/
learning sections), and Administration (Dashboard, People, companies, courses, Roles & ACL,
Themes, Settings and UI Components). Registries supply the exact labels, order and permission
filtering; these examples do not authorise hard-coded destinations in themes.

The standard footer destination array is core-owned. Factory Reset, Light Default and Radiant Learning delegate
to the site-footer partial. Factory Reset Sidebar retains its richer themed footer. Gilded Noir retains its individual footer composition using the same
standard destinations and platform action component. Do not replace its footer with another theme's.

Account, company and admin family models expose `sections` and `section` for composed workspace and
standalone contexts. Their content is already-rendered platform content. Core page bodies own the
canonical accordions and controls; a theme styles them instead of rebuilding the workspace.

## Platform UI components

[UI-COMPONENTS.md](UI-COMPONENTS.md) lists the complete namespaced registry and Twig slots. Components
live under `resources/views/ui/{layout,actions,forms,data,feedback,overlay,helpers,catalogue}`.
The registry exists to prevent page-by-page LLM-generated markup from drifting: equivalent controls
must share one implementation so spacing, responsive behavior, accessibility and progressive
enhancement remain consistent.
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
`UiOwnershipAudit` rejects canonical component display/flex/grid, dimensions, overflow, positioning
and visibility declarations in theme CSS, including responsive rules. Use `cl-ui-notice-heading`
for notice headings; a broad notice `strong` selector would also restyle inline prose emphasis.
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

Core JavaScript owns navigation toggles, Escape and mobile menu state. Theme JavaScript may enhance decorative transitions only. It cannot own ACL, grading,
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
Radiant Learning and Factory Reset Sidebar all need individual visual checks. The component ownership contracts scan bundled theme templates as well as platform views.

## Canonical shared page vocabulary

| Concept | Canonical class |
|---|---|
| body | `cl-body` |
| skip link | `cl-skip-link` |
| whole application shell | `cl-shell` |
| top header | `cl-header` |
| header inner wrapper | `cl-header-inner` |
| header actions | `cl-header-actions` |
| brand | `cl-brand` |
| brand mark | `cl-brand-mark` |
| brand text wrapper | `cl-brand-copy` |
| primary navigation | `cl-navigation` |
| mobile nav toggle | `cl-nav-toggle` |
| navigation menu | `cl-nav-menu` |
| navigation link/summary | `cl-nav-link` |
| navigation icon | `cl-nav-icon` |
| dropdown panel | `cl-nav-panel` |
| nested navigation group | `cl-nav-group` |
| nested group label | `cl-nav-group-label` |
| nested group caret | `cl-nav-group-caret` |
| nested subpanel | `cl-nav-subpanel` |
| nested sublink | `cl-nav-sublink` |
| POST navigation form | `cl-nav-form` |
| sidebar | `cl-sidebar` |
| page region beside/below navigation | `cl-page` |
| breadcrumb/cart/context band | `cl-page-context` |
| context inner wrapper | `cl-page-context-inner` |
| visual box containing main + footer | `cl-page-frame` |
| primary content | `cl-main` |
| flash stack | `cl-flash-stack` |
| page heading section | `cl-page-head` |
| page heading inner wrapper | `cl-page-head-inner` |
| page heading content | `cl-page-head-content` |
| page heading actions | `cl-page-head-actions` |
| identity root | `cl-identity` |
| full identity band | `cl-identity-head` |
| compact/nav identity | `cl-identity-compact` |
| identity avatar | `cl-identity-avatar` |
| identity text/meta | `cl-identity-meta` |
| identity name | `cl-identity-name` |
| identity email | `cl-identity-email` |
| identity role/status area | `cl-identity-roles` |
| ordinary page content section | `cl-page-section` |
| footer root | `cl-footer` |
| footer inner wrapper | `cl-footer-inner` |
| footer navigation | `cl-footer-nav` |
| footer social links | `cl-footer-social` |

Existing canonical component classes such as `cl-ui-*`, `cl-cart-*`, `cl-container-wide`, `cl-narrow`, `cl-medium` and other established core classes remain part of the same core vocabulary.

Theme-specific elements may use theme-owned classes such as `gn-ribbon`, `gn-footer-art`, or future third-party theme classes.


`cl-identity-details`, `cl-footer-grid` and `cl-footer-section` name the inner identity and rich-footer
structures shown in the canonical samples. `cl-page-family` is a div around a decorated page family.
Page heading and full identity are semantic sections; ordinary content uses `section.cl-page-section`.
Never replace meaningful sections with generic divs to obtain a layout.

The full identity band owns its padding and row layout; compact identity metadata has no band
padding. Themes can set `--cl-identity-avatar-size` on the compact identity (Gilded Noir uses 46px).

Gilded Noir uses the shared compact identity in its top navigation. Its footer retains three
semantic sections, social presentation, `gn-footer-art`, `gn-footer-veil` and `gn-footnote`, with
shared `cl-footer*` names on common concepts. Do not substitute the standard footer for it.
