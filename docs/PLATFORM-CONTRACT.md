# Catto Learning platform contract

This is the single living implementation contract for the platform. New work must preserve these rules unless the platform owner explicitly changes them.

## Platform administration
- A Platform Administrator has complete control over database-backed platform data and may perform destructive actions during development.
- The platform must always retain at least one active Platform Administrator.
- Administration is one control centre: Dashboard, Courses, People, Companies, Enrolments & Requests, Credits & Orders, Activity, Reports, Themes and Settings.
- Dashboard is a summary; My Learning is the learner's complete course library. Do not duplicate full library management on Dashboard.

## People and companies
- Every user has one complete profile model: first name, last name, identification number, birthdate, gender, mobile number, display name, certificate name, primary email, secondary email, company, roles, status and sessions.
- Platform Administrators may edit the complete profile and roles of every user, including themselves, subject to the last-active-administrator rule.
- There is one permanent System Company. It cannot be deleted.
- Its initial domain is exactly APP_DOMAIN. Do not prepend a subdomain. Its name and domain remain editable in Administration.
- A user not assigned to an external company belongs to the System Company.

## Courses
- Every course has both an owning company and an owning person, plus zero or more Course Editors and learners.
- During development, Reset keeps the course shell but removes course content and learner test data so it can be re-imported.
- During development, Delete permanently scrubs the course and associated access, progress, attempts, results, certificates, requests and course credits. No typed-title confirmation is required; one normal confirmation is enough.
- The course list exposes Reset and Delete as separate direct actions.
- Course revisions may be created by cloning an existing course.

## Import/export
- Catto Learning JSON is the canonical portable course interchange format. HTML course import remains supported.
- Theme Export downloads a portable Theme Package 1.0 ZIP with authoritative `theme.json`. Theme Import validates/inspects that ZIP before importing it as a draft.

## Themes and visual design
- Default is the permanent filesystem recovery theme. Radiant Learning and imported themes use the same database-backed Theme Package 1.0 model.
- Theme packages use HTML/F3 templates; imported PHP/server-executable files are prohibited.
- Theme text/source/configuration is stored in PostgreSQL. Binary image/font assets are filesystem-backed with PostgreSQL metadata and hashes.
- The active theme must be unmistakably labelled Active; an already-active theme must not show an Activate action.
- Saving a theme edits draft state only. Draft preview must not change the live active snapshot; Apply draft / Activate is the explicit live publication action.
- Approved built-in palettes are Rose & Ice, Citrus, Ocean & Sun and Signal (`#D00000`, `#FFBA08`, `#3F88C5`, `#032B43`, `#136F63`). Custom package themes may define additional five-swatch palettes.
- Semantic colour roles may use palette swatches, custom colours or automatic safe-contrast foregrounds where supported.
- CSS gradients are permitted when they are an intentional part of a theme design; palette/semantic variables should still be used so alternate palettes remain coherent.
- CSS precedence is package CSS → Catto Learning functional overrides → Theme Studio generated design CSS → Advanced Custom CSS.
- Theme Studio is the normal editor; raw HTML/F3/CSS/JavaScript source and Advanced Custom CSS are explicitly advanced facilities.
- Themes may declare HTTPS CDN stylesheet/script resources in `theme.json`; undeclared external resources are not imported as package dependencies.

## UI behaviour
- Activity, Reports and Settings must use the full available Administration content width and must not collapse into narrow left columns.
- Quick action -> Add person opens the Add person form directly.
- Success/info flash messages are dismissible and auto-expire. Errors remain until dismissed or navigation changes.
- The student-preview bar uses the active palette; it must not use an unrelated hard-coded colour.
- Prefer a slender system UI font stack. Do not require external font files.

## Release gate
A release or corrective patch is not ready unless:
1. all PHP files changed by the work pass PHP syntax checks;
2. JavaScript changed by the work passes syntax checks;
3. architecture and release validators pass;
4. runtime-hazard checks pass, including local method resolution, malformed DB exec calls, composite-key Mapper misuse, theme/preview colour invariants, destructive course action rules, full-profile controls and bundled Linux UTF-8 symbols;
5. theme source assets and published assets are identical;
6. core routes and controller methods validate.

Live PostgreSQL/browser behaviour still requires VPS acceptance testing, but elementary undefined-method, malformed-query, template-variable and structural UI regressions must be caught before delivery.
