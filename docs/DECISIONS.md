# Confirmed product and technical decisions

## Platform

- Project: Catto Learning LMS.
- Licence: MIT, copyright Richard Catto.
- PHP 8.4, Fat-Free Framework 3.9, PostgreSQL 16 and Bootstrap 5.3.8.
- Versioned application code under `/usr/local/lib/php/catto-learning/<version>/`.
- Live `.env` under `/home/<site-user>/.env`.
- Web entry point and published theme assets under `/home/<site-user>/public_html/`.
- No required cron jobs.

## Naming conventions

- F3 mapper-model classes append `M`, for example `UsersM`, `CoursesM` and `ArchivesM`.
- `M` is not expanded to `Mapper` in class names.
- HTTP controller classes append `Controller`.
- Service classes append `Service`.
- Repository classes append `Repository`.
- Additional descriptive suffixes may be used where they accurately identify responsibility.

## Persistence architecture

- Ordinary single-table persistence uses F3 `DB\SQL\Mapper` as far as practical.
- Mapper models are located under `src/Infrastructure/Persistence/M/` and remain inside repositories or persistence infrastructure.
- Joins, aggregates, reports, row locks, bulk operations and specialised PostgreSQL queries use F3 `DB\SQL`.
- Database operations do not appear in controllers, templates, MCP tools or plugins.
- Application services coordinate business rules and call repositories.
- Mapper objects are converted to arrays before leaving repositories and are never placed in the F3 template hive.
- `AppContext` does not expose the raw database connection.

## API-first architecture

- The platform exposes a versioned HTTPS API for phone applications and third-party systems.
- The server-rendered web application, REST API and MCP server use the same application services, repositories, validation and permission rules.
- Internal web controllers call services directly; they are not required to make loopback HTTP calls to their own API.
- API clients authenticate with opaque scoped Bearer tokens whose hashes are stored in PostgreSQL.
- Tokens may expire, be revoked and are attributable in the audit log.

## MCP

- An MCP server is a core subsystem of the LMS.
- The initial transport is local STDIO.
- MCP authentication uses a scoped LMS API token.
- MCP tools call shared application services and do not access PostgreSQL directly.
- Administrative tools require both the relevant API scope and the platform-administrator role.
- MCP writes are audit logged with the acting account and token.
- Remote HTTP MCP transport and OAuth are deferred until remote MCP access is required.

## Development route policy

- Until the LMS is released to real users, the development installation has no backward-compatibility obligation.
- Every user-facing business function has one canonical route and one canonical screen.
- Obsolete aliases, redirects, duplicated page implementations and unused templates are deleted rather than retained for hypothetical bookmarks.
- Current account routes are `/account/library`, `/account/profile` and `/account/sessions`.
- Current course-administration index is `/admin/courses`; Administration links to it rather than rendering a second course list.

## Authentication

- Canonical identity table: `users`.
- Unknown email creates a user only after a valid magic link is consumed.
- Magic-link lifetime defaults to 1,800 seconds.
- Sliding login-session lifetime defaults to 86,400 seconds.
- Users can inspect and revoke their own sessions.
- `APP_ADMIN_EMAIL` bootstraps the platform administrator whenever no platform administrator exists.
- Outgoing email uses a real configured transport and monitored sender address selected by the installation operator.

## Themes and plugins

- F3 templates are used.
- Theme and plugin source remains filesystem code.
- Each theme controls its own hero images and presentation.
- Runtime theme selection is stored in PostgreSQL and changed from the administrator control panel.

## Courses

- Courses, modules, HTML content, MCQs, answers, grades, progress and certificates are stored in PostgreSQL.
- Media binaries are stored privately outside `public_html/`; metadata is stored in PostgreSQL.
- CKEditor 5 with source mode is used for content editing.
- Course states are draft, published, retired and archived.
- Published courses are editable in place; changes immediately affect all learners.
- Existing browser-side progress, grading and unlocking logic is replaced by server-side LMS logic.
- Existing self-study HTML courses are converted with development-assisted processing; the deployed LMS has no AI-service dependency.
- Imported source-course presentation CSS is retained as course-specific CSS scoped beneath the course-presentation wrapper; it must not be allowed to style the LMS shell or structural content-card container.
- Learning Outcomes are stored as outcomes content only; the LMS owns the single visible Learning Outcomes heading.

## Course structure and results

- A course consists of separately stored modules.
- `is_review` classifies a module as review/revision content; it does not determine grading.
- `assessment_required` independently determines whether a module must have an assessment and whether that module contributes to assessed-module progress and the course module grade.
- Normal modules may therefore be unassessed, and review modules may be assessed when explicitly configured.
- For legacy HTML imports, `id="mod-review"` marks the review module and `data-assessment-required="false"` marks any normal module whose assessment is not required.
- A course may have a final assessment, an overall grade and a certificate.
- Progress and grades are server-authoritative.

## Access and company course credits

- A course access period begins only when the learner clicks **Start course**.
- A company credit is tied to a specific course and access period.
- Before commencement, a company administrator may unassign a course and return the unused credit to the company pool.
- Once commenced, the credit is permanently consumed and the course cannot be unassigned, returned or reassigned.
- Approving a staff course request uses an unused matching credit first; only a shortage is added to the company administrator’s cart.
- Payment is required before a newly purchased course becomes available to the learner.

## Company staff workflow

- Company administrators create and manage staff directly; there is no invitation or acceptance process.
- A staff account uses the exact company-domain email entered by the administrator.
- The administrator may optionally send a plain account notification without a login token.
- Staff use the normal passwordless login process when they choose to log in.
- Staff can browse courses and request training.
- Company administrators can approve or reject requests and receive optional notification emails.
- Approval uses an existing matching credit or adds the named learner’s course to the company administrator’s cart.
- Staff are notified when a request is approved or rejected.