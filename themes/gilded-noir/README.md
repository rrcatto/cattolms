# Gilded Noir 1.1.7

Gilded Noir is a complete standalone **Catto Learning LMS Theme Package 3.0**. It is based on the accepted Gilded Noir 1.1.2 design and keeps the light ivory application plate, black structure, restrained gold detailing, compact data UI and complete component styling.

## Package contract

This ZIP is self-contained. A theme generator/designer needs this README plus the separately supplied **Catto Learning LMS Theme SDK 3.1**; the Catto Learning application source tree is not required.

The package supplies `base.html`, `public/css/theme.css`, presentation-only JavaScript, SVG icon sprite, artwork, partials, and all 14 supported page-family wrappers. Every wrapper preserves `{{ @content | raw }}` so the LMS retains ownership of functional markup and business controls.

Gilded Noir renders the core-owned `@navigation`, `@footer_navigation` and `@breadcrumbs` arrays. It does not hard-code the platform menu hierarchy. Core also supplies stable Administration, Account and Company objects:

- `@admin.sections` / `@admin.section`
- `@account.sections` / `@account.section`
- `@company.sections` / `@company.section`

These objects are literal API names. Themes may style/rearrange complete rendered sections but must not replace platform forms, permissions, routing or business logic.

## Page families

`home`, `catalogue`, `course-detail`, `auth`, `account`, `library`, `course-player`, `assessment`, `certificate`, `commerce`, `company`, `admin`, `error`, `content`.

## 1.1.6 Seed Database icon, and standing down where core searches

Catto Learning 0.5.8.3 added a Seed Database section to Administration and real server-side search
to the Companies table. Three theme changes follow from that.

**The missing navigation icon.** Core emits a semantic key per navigation item and the theme owns
the artwork. `nav-admin-seed` had no symbol, so the Seed Database item rendered a blank space. Added,
along with a neutral `nav-fallback` symbol so a future core key has something to point at. The
fallback is not yet wired: an SVG `use` reference cannot fall back on its own, and making it
automatic needs core to resolve the key first.

**The theme's table search now stands down where core has a real one.** The client-side box this
theme adds above substantial tables only hides rows that are already on the page. Where core now
searches the whole dataset it marks the region `data-server-search`, and the theme adds nothing
there. Offering both put a real search directly above one that silently disagreed with it, and
reported "N of 25 rows" about a dataset holding far more.

**The table search survives an htmx swap.** It captured its row list once when built, so after core
replaced a table region the box held references to rows no longer in the document and quietly
stopped matching anything. It now rebuilds on `htmx:afterSwap`, removing the stale box first so a
swapped region never ends up with two.

**The platform name now propagates to the footer.** The footer lead carried a hard-coded
`Catto Learning Academy` eyebrow directly above a correct `{{ @app_name }}` heading, so renaming the
platform in Administration produced a footer that disagreed with itself. The eyebrow is removed
rather than rewritten: it sat immediately above the heading that already prints the platform name,
so pointing it at `@app_name` would simply have printed the name twice, and inventing a replacement
tagline is not the theme's decision to make. If a kicker line is wanted above the name, it needs a
setting to read from.

No user-visible string in this theme now names the platform. The only remaining occurrences are in
source comments.

Styling was added for the core-owned company management controls introduced in the same release —
the Administering company banner, the dataset search bar and the Switch Company picker region. Core
defines each of them completely, so this is surface treatment only and none of it is required for
them to work.

## 1.1.4 pagination and bounded entity lookups

Catto Learning 0.5.7.6 added two core-owned functional controls that this theme previously
left unstyled, so they fell back to core CSS inside the dark plate:

- the shared pagination row on every standalone Administration, Company and catalogue list
  (`.pagination-row`, `.pagination-summary`, `.pagination-pages`, `.pagination-page-size`);
- the bounded entity lookup that replaced the whole-table `<select>` pickers on Activity,
  Credits and the person profile (`.entity-lookup`, `.lookup-results`, `.lookup-result`).

Section 30 of `public/css/theme.css` supplies presentation for both using the existing Gilded
Noir tokens. Core keeps ownership of the markup and behaviour: no core class is renamed and no
functional rule is overridden. The disabled Previous/Next edge state is a `<span>` rather than a
button, so it carries its own treatment instead of inheriting `:disabled`.

## 1.1.3 visual changes

- New abacus/scales/parchment artwork is the full-width home hero image.
- The hero photograph occupies its own image stage so copy does not cover the focal objects.
- Only the hero's top corners are rounded; the lower edge is square.
- The polished sphere artwork moves to the footer.
- The serpent is retained as a restrained Account/Company/Administration header texture.
- Current Account, Company, Roles & ACL and standard footer-navigation contracts are styled without changing the 1.1.2 component system.
- Additional SVG symbols cover all current Account, Company and Administration child menu keys.

## Theme assets

Browser assets resolve through `@theme.asset_url`. The package does not assume any fixed installation path. The theme loads `@platform.styles` and `@platform.scripts`; those resources are required for core LMS behaviour under every theme.

## Colour ownership

This theme intentionally declares no core palette list. Its black/gold/silver/ivory colour system is fixed by the theme.

## Installation

Import the ZIP through **Administration → Themes**, inspect it, install it, then activate it. Catto Learning installs theme versions side-by-side. A repaired same-version package may replace a defective installation only after the defective copy has been uninstalled.
## 1.1.3 final same-version repair

This is the final repaired 1.1.3 package. The fixes are made at the actual theme CSS defects rather than with platform workarounds:

- the desktop home information blocks are explicitly assigned to separate grid columns; responsive layouts reset both to one column;
- the System name remains large, legible sans-serif text over the hero artwork;
- the dark information band retains the requested thin equal diagonal texture;
- when a core modal opens inside `.gn-main`, the theme promotes that existing stacking context so the footer/header cannot paint above the dialog; core still owns modal open/close/Cancel/Escape behaviour.

No other front-page redesign is introduced by this repair. The theme version remains 1.1.3.
