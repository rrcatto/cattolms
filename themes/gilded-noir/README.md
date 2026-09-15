# Gilded Noir

Gilded Noir is a complete standalone Catto Learning LMS theme. It keeps the light ivory application plate, black structure, restrained gold detailing, compact data UI and complete styling for the platform's canonical UI components.

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

## Functional component styling

The platform owns navigation, search, pagination, tables, forms and component behavior. Gilded Noir
styles the canonical classes for surfaces, section heads, toolbars, action groups, form grids,
datasets, tables, badges, notices, progress, modals and accordions. Shared server-rendered search
and pagination continue to work after htmx swaps; the theme JavaScript only controls its navigation
drawer and scrolled header.

## Theme assets and individuality

Browser assets resolve through `@theme.asset_url`, while `@platform.styles` and `@platform.scripts`
provide shared LMS behavior. The theme intentionally owns its black, gold, silver and ivory palette,
typography, engraved canvas, hero artwork, serpent section texture and sphere footer. Functional
structure remains identical across themes so responsive and progressive-enhancement behavior is
consistent without flattening Gilded Noir's visual identity.
