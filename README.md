# Catto Learning LMS 0.5.4

Catto Learning is an API-first learning platform built with PHP 8.4+ / 8.5, Fat-Free Framework and PostgreSQL.

Version 0.5.4 is the corrected pre-production Theme Package 1.0 baseline. It restores the permanent visitor palette chooser, hardens Radiant navigation/dropdowns, adds editable Default Learning and Aurum Learning package themes, reorganises Theme Studio around a preview/code workspace plus focused accordions, and adds release-time UI regression contracts while retaining the 0.5.2 course-category, publication, pricing, Activity Centre and SMTP-settings foundations.

## Main navigation

Radiant Learning uses Home, Catalogue, Help, Account and Administration navigation. Platform administration starts at `/admin`; learner course access starts at `/account/library`.

## Theme Package 1.0

A portable theme package is a ZIP containing an authoritative `theme.json` manifest plus declared HTML/F3 templates, CSS, optional JavaScript, JSON/text support files and optional binary assets.

Key rules:

- ordinary themes never execute imported PHP;
- at least one CSS file and a base HTML/F3 template are required;
- text/template/CSS/JS source is stored in PostgreSQL;
- images and fonts are stored on the filesystem with hashes and metadata in PostgreSQL;
- external CDN resources must be explicitly declared and use HTTPS;
- import validates and inspects a package before it is stored as a draft;
- export regenerates a portable ZIP and authoritative `theme.json`;
- Radiant Learning, Default Learning and Aurum Learning are seeded using the same Theme Package 1.0 model as imported themes;
- the filesystem theme under `themes/default` is the emergency Recovery Theme; its editable package equivalent is **Default Learning**;
- every rendered theme receives the core hideable palette chooser and per-browser palette preference.

## Theme Studio

Theme Studio provides:

- palette create/rename/duplicate/edit/delete;
- semantic colour roles with custom-colour overrides, **Automatic safe contrast** foreground defaults and contrast warnings;
- Inter, Plus Jakarta Sans, Satoshi and system typography controls;
- navbar position, sizing, ordering, visibility, sticky and separator controls;
- breadcrumb/context-bar controls;
- content width, spacing, card and footer presentation controls;
- front-page hero image/presentation controls;
- declared CDN stylesheet/script management;
- binary theme asset management;
- integrated Preview / Code workspace with page selection and unsaved-source preview;
- raw source editing as an advanced facility;
- typed hexadecimal palette editing with live swatch preview (no operating-system colour picker);
- dedicated Identity, Colours, Typography, Navigation, Layout, Hero, Assets and Advanced CSS accordions;
- Advanced Custom CSS;
- desktop/tablet/mobile draft previews.

CSS precedence is: **package CSS → Catto platform functional overrides → visual-editor generated CSS → Advanced Custom CSS**.

Saving Theme Studio changes updates only the **draft**. The active site continues to use its last activated snapshot until **Apply draft / Activate** is explicitly selected.

## Theme SDK

See:

- `docs/THEME-PACKAGE-SPECIFICATION.md`
- `docs/THEME-SDK.md`
- `resources/schema/catto-learning-theme-1.0.schema.json`
- `theme-sdk/starter-theme/`
- `tools/generate-theme.php`

Create a starter theme with:

```bash
php tools/generate-theme.php "My Theme"
```

Add `--zip` when PHP `ZipArchive` is available to generate an importable ZIP directly.

## Commerce pricing foundation

Each course defines its own access-period price variants. Prices are stored in integer minor currency units, exactly one active variant is the default catalogue price, and published courses may not be left without an active default. Free courses may use a zero-price active default variant.

## Architecture

Normal persistence uses F3 `DB\SQL\Mapper` classes ending in `M`. Complex joins, reports, locking and PostgreSQL-specific queries use F3 `DB\SQL` inside repositories. Web controllers, REST endpoints and MCP tools use shared services and repositories.

## Database baseline

Catto Learning 0.5.4 uses one clean baseline migration: `database/migrations/20260812024500_create_v054_baseline.php`. It creates the complete current schema and required seed data, including the System Company and Radiant Learning, Default Learning and Aurum Learning Theme Package 1.0 records.

## Configuration

See `docs/CONFIGURATION.md` for the database-over-`.env` settings precedence model and administrator-managed SMTP configuration.

Copyright © 2026 Richard Catto. Released under the MIT License.
