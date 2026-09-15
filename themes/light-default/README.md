# Light Default 1.1.1

A light, compact Catto Learning theme: a full-width sticky translucent top
navigation bar outside a rounded boxed content frame, floating on a slanted
textured canvas, with palette-coloured heading bands throughout.

## Changelog

### 1.1.1

- The sticky navigation bar now spans the full page width instead of being
  constrained to the boxed frame. The responsive drawer therefore hands over
  later, at 1099px.
- Added the palette band system. The reference design's role map assigns its
  hero and section-header band to a *light* palette colour with dark text; that
  role maps to `--cl-color-4` after the platform's luminance sort, so page
  headings now carry real palette colour on every surface.
- `.cl-hero`, `.cl-page-head` and `.section-head` all open on a coloured band,
  so the h1 area of each page stands out.
- More palette colour in tab strips (solid accent for the active tab),
  accordion summaries (accent when open), table headers, card headers, stat
  cards and status chips.
- Full pagination styling for `.pagination-row` and for `.pagination` /
  `.page-link`.

- **Package format** Theme Package 4.0
- **Template API** 2.0
- **Type** Standalone (no parent)
- **Reference LMS** 0.5.7.5.1

## Layout

```text
sticky top navigation bar          full page width, outside the frame
└─ textured canvas                 45° repeating gradient, page background
   ├─ boxed frame                  rounded, white, bordered, drop shadow
   │  ├─ context strip             breadcrumbs from @breadcrumbs
   │  ├─ flash messages
   │  └─ platform page body        {{ @content | raw }}
   └─ dashed footer panel          @footer_navigation
```

The texture never continues inside the frame: the frame paints its own opaque
surface. The texture is applied to an inner canvas element rather than to
`<body>`, because the LMS writes `body{background:…!important}` when palettes
are enabled and would otherwise overpaint it.

## Colour

Four palettes are declared and the LMS owns selection and persistence. Because
two or more palettes are present, the core palette picker appears
automatically. The theme ships no competing colour UI and adds no styling to the
core picker.

The neutral chrome is fixed; the selected palette drives brand mark, primary
actions, active tabs and menu items, focus rings, badges, progress and
highlights. `--cl-color-1..5` fallbacks are declared at `:root` only so the
theme still renders correctly if the generated palette stylesheet is absent —
the platform's own `<body>` values always take precedence.

## Contract notes

- Navigation renders entirely from `@navigation`. No route catalogue, no
  permission logic and no role checks exist in theme code. `POST` items (sign
  out) keep their method and CSRF token.
- The Administration, Account and Company wrappers arrange the
  already-rendered `sections` arrays into a tab deck. Section content, forms,
  tables and controls are inserted untouched, and each section keeps its core
  standalone route.
- Tab switching uses the platform's own `data-tab-group` / `data-tab-target`
  contract, so no theme JavaScript is involved and behaviour matches the tab
  strips core renders itself. A `<noscript>` rule reveals every panel when
  scripting is unavailable.
- Accordions are native `<details>`/`<summary>`. Where core already supplies an
  indicator (Administration sections, ACL groups) the theme styles that element
  instead of drawing a second chevron. Help items have no core indicator and
  receive one.
- The sticky bar sits at `z-index: 60` and no theme element uses `transform`,
  `filter` or `contain` on a modal ancestor, so core modals always paint above
  the chrome and tall dialogs scroll normally.
- `.cl-honeypot` is untouched, so the Contact anti-bot field stays hidden.
- Theme JavaScript covers only the responsive navigation drawer.

## Files

```text
theme.json
base.html
README.md
partials/  navigation.html  flash.html  footer.html
pages/     all 14 page families
public/css/theme.css
public/js/theme.js
public/img/icons.svg
```

`public/css/theme.css` is loaded automatically and is intentionally absent from
`theme.json` `styles`.

## Typography

Root font size is `87.5%`, which scales every Bootstrap `rem` down
proportionally while still respecting the reader's own browser preference.
Body copy is approximately 13px. Inter is declared as an external HTTPS
stylesheet with a system sans-serif fallback stack.

## Icons

`public/img/icons.svg` is a sprite keyed to the semantic navigation `key`
values supplied by core. Icons are decorative; every destination also renders a
readable text label, so an unknown future key simply paints no glyph while its
label continues to work.
