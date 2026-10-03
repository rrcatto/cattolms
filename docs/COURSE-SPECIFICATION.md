# Catto Learning Course Components and HTML Authoring Specification

**Target LMS:** 0.8.6.1 release (0.8 code line) **Date time:** 2026/09/23 SAST **Status:** Canonical specification for the implemented HTML course authoring/import workflow.

## 1. Purpose

New HTML courses intended for Catto Learning must use one predictable static structure. The importer parses HTML and assessment data; it does **not** execute course JavaScript during import.

Important content must therefore exist as real HTML. Assessment banks must exist in the `QUIZ` object. JavaScript may enhance the standalone course but must not be the sole source of teaching content.

Sections 2–17 describe the accepted authored HTML input syntax. Section 18 describes the Course Components domain and structured interchange format implemented by the active update. Module terminology in the input syntax describes source documents, not database ownership or learner completion.

### 1.1 Trusted author content

**SVG graphics must not be stripped.** Inline `<svg>` graphics in imported course content and uploaded `.svg` course-media files are supported authoring formats. Authors do not need to convert SVG diagrams to PNG or remove SVG features to satisfy an importer security filter.

The platform owner authors and imports these courses. Course content is trusted. The importer and course editor do not run an HTML/SVG element allowlist, attribute/style/URL filtering, or malicious-code stripping. Authored SVG, HTML, inline styles, event attributes, embedded scripts and stylesheet imports are preserved in the content fields that map into the LMS. Saving and exporting that content uses the same policy. Uploaded course media, including `.svg`, is stored unchanged; MIME detection describes the file rather than deciding which content is trusted.

Course structure and data still have to be valid: titles, module identities, supported block types, question banks, answer indexes, grading/pool rules and certificate placeholder names remain checked. The existing import permissions, file-size limits, course ownership and learner-access rules remain in force. This is a content-preservation policy, not a change to authentication or course access.

Inline SVG is supported, including `viewBox`, gradients, clipping paths, masks, symbols/`use`, `foreignObject`, namespace declarations and authored styling. The HTML importer uses an HTML5 parser so SVG names and attributes retain their proper case and namespace. For example:

```html
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 80" aria-label="Process diagram">
  <defs><linearGradient id="process-fill"><stop stop-color="#186477"/><stop offset="1" stop-color="#f7c59f"/></linearGradient></defs>
  <rect x="10" y="10" width="180" height="60" rx="8" fill="url(#process-fill)"/>
  <text x="100" y="46" text-anchor="middle">A course diagram</text>
</svg>
```

Place diagrams inside `.module-body`, `.outcomes`, or the extracted summary/review content, according to where they should appear. SVG in the discarded standalone page shell is not teaching content. Give IDs referenced by gradients, masks, clips and `use` unique names across the rendered lesson. Already-stripped imported content cannot be reconstructed; reimport the original source to restore it.

## 2. Course-level metadata

Every new course must provide:

```html
<header class="masthead" data-level="Intermediate">
  <h1>Course Title</h1>
  <p class="course-subtitle">Course Subtitle</p>
  <p class="course-summary">One-paragraph course summary.</p>
</header>
```

Importer mapping:

```text
.masthead @data-level      → course level
.masthead h1               → course title
.masthead .course-subtitle → course subtitle
.masthead .course-summary  → course summary
```

Rules:

- Set `data-level` explicitly; do not rely on words in teaching content.
- Do not combine title and subtitle in the `h1`.
- Do not put `<br>` in the title.
- Do not append a subtitle to the title with a spaced hyphen or em dash.

## 3. Canonical section types

| Type | Required opening identity | Question bank |
|---|---|---|
| Standard module with assessment | `<section class="module acc-item" id="mod-m1" data-assessment-required="true">` | `QUIZ.m1` |
| Standard module without assessment | `<section class="module acc-item" id="mod-m2" data-assessment-required="false">` | none |
| Review with assessment | `<section class="review acc-item" id="mod-review" data-assessment-required="true">` | `QUIZ.review` |
| Review without assessment | `<section class="review acc-item" id="mod-review" data-assessment-required="false">` | none |
| Diagnostic assessment | `<section class="diag acc-item" id="diagnostic">` | `QUIZ.diag` |
| Final assessment | `<section class="final acc-item" id="final">` | `QUIZ.final` |

Normal teaching modules use sequential keys `m1`, `m2`, `m3`, ... . Diagnostic and final sections are course-level assessments and must not use the `module` class or `mod-` ids.

## 4. Overall document order

Recommended order:

```text
Masthead / introduction
Diagnostic assessment (optional)
Module 1
Module 2
...
Final teaching module
Review module (optional)
Final assessment
Footer
QUIZ / REVIEW data
Standalone interaction JavaScript
```

Minimal skeleton:

```html
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Course Title</title>
<style>/* course presentation CSS */</style>
</head>
<body>
<div class="wrap">
  <header class="masthead" data-level="Beginner">
    <h1>Course Title</h1>
    <p class="course-subtitle">Course Subtitle</p>
    <p class="course-summary">Course summary.</p>
  </header>

  <!-- optional diagnostic -->
  <div class="modules">
    <!-- mod-m1 ... mod-mN -->
  </div>
  <!-- optional review -->
  <!-- final assessment -->
</div>

<script>
const QUIZ = {};
const REVIEW = {};
</script>
<script>/* optional standalone UI behaviour */</script>
</body>
</html>
```

## 5. Standard teaching modules

Required identities for every normal module:

- element is `<section>`;
- class contains the bare word `module`;
- id is `mod-mN` using sequential module keys;
- `.m-name` contains the module title;
- `.m-titles small` contains the subtitle;
- `.module-body` contains teaching content;
- `.outcomes` contains learning outcomes;
- `data-assessment-required` explicitly states `true` or `false`.

Example:

```html
<section class="module acc-item" id="mod-m1" data-assessment-required="true">
  <button class="acc-trigger module-trigger" type="button" aria-expanded="false">
    <span class="m-titles">
      <span class="eyebrow">Module 01</span>
      <span class="m-name">Module Title</span>
      <small>Module subtitle</small>
    </span>
  </button>

  <div class="acc-panel">
    <div class="module-body">
      <div class="outcomes">
        <span class="eyebrow">Learning outcomes</span>
        <ol>
          <li>Outcome one.</li>
          <li>Outcome two.</li>
        </ol>
      </div>

      <h4>Topic heading</h4>
      <p>Static teaching content...</p>

      <details class="block">
        <summary>Worked example</summary>
        <p>Static collapsible content...</p>
      </details>

      <div class="subs">
        <section class="sub s-summary acc-item">
          <div class="sub-inner">
            <ul><li>Summary point.</li></ul>
          </div>
        </section>
        <section class="sub s-assess acc-item">
          <div class="sub-inner"><div class="quiz" data-quiz="m1"></div></div>
        </section>
      </div>
    </div>
  </div>
</section>
```

If the module is unassessed:

```html
<section class="module acc-item" id="mod-m2" data-assessment-required="false">
```

Do not create `QUIZ.m2` or an assessment placeholder for an intentionally unassessed module.

## 6. Learning outcomes

Use real HTML:

```html
<div class="outcomes">
  <span class="eyebrow">Learning outcomes</span>
  <ol><li>Outcome...</li></ol>
</div>
```

The LMS supplies the visible Learning Outcomes heading after import. The importer removes one source label matching `Learning outcomes`/`Learning outcome` from stored outcomes content.

Do **not** generate this label using CSS pseudo-elements.

## 7. Module summary and `.subs`

The importer extracts:

```text
.s-summary .sub-inner
```

as the LMS module summary, then removes the entire `.subs` block from main module content.

Therefore `.subs` may contain only standalone summary/assessment controls. Never place substantive teaching material, worked examples, tables or explanations there if they must survive import.

Use static content outside `.subs`; use `<details>/<summary>` for collapsible teaching material.

## 8. Review module

There is one canonical review module: `mod-review`. Do not add the `module` class.

Assessed review:

```html
<section class="review acc-item" id="mod-review" data-assessment-required="true">
  <div class="review-inner">
    <p>Static review material...</p>
    <div id="reviewCards"></div>
    <div class="quiz" data-quiz="review"></div>
  </div>
</section>
```

Use `QUIZ.review`.

Unassessed review:

```html
<section class="review acc-item" id="mod-review" data-assessment-required="false">
  <div class="review-inner">
    <p>Static review material...</p>
    <div id="reviewCards"></div>
  </div>
</section>
```

Do not create `QUIZ.review`. Declare `const REVIEW = {};` if no structured review cards are required.

Author the review after the final normal teaching module.

## 9. Final assessment

The final assessment is course-level, not a teaching module:

```html
<section class="final acc-item" id="final">
  <div class="final-inner">
    <div class="quiz" data-quiz="final"></div>
  </div>
</section>
```

Use `QUIZ.final`.

Do not:

- add class `module`;
- use an id beginning `mod-`;
- put essential teaching content in the final-assessment section.

## 10. Diagnostic assessment

Canonical diagnostic:

```html
<section class="diag acc-item" id="diagnostic">
  <div class="diag-inner">
    <p>This diagnostic does not contribute to the course grade.</p>
    <div class="quiz" data-quiz="diag"></div>
  </div>
</section>
```

Use `QUIZ.diag`.

Optional diagnostic pass percentage:

```javascript
const DIAG_PASS = 70;
```

If absent, the importer defaults to 50%.

Diagnostics are non-graded course-level assessments and may provide remediation guidance. The importer recognises legacy bank names `diagnostic`, `prereq`, `prerequisite` and `readiness`, but new courses must use `diag`.

### Diagnostic remediation

Use module-key strings in `u`:

```javascript
{
  "q": "Question?",
  "o": ["A", "B", "C", "D"],
  "a": 0,
  "p": 1,
  "e": "Explanation.",
  "u": ["m2", "m3"]
}
```

Do not use `mod-m2` or numeric shortcuts.

## 11. QUIZ data

Use one strict JSON-style JavaScript declaration containing all banks:

```javascript
const QUIZ = {
  "m1": [
    {
      "q": "Question text?",
      "o": ["Option A", "Option B", "Option C", "Option D"],
      "a": 1,
      "p": 1,
      "e": "Explanation of the correct answer."
    }
  ],
  "review": [],
  "diag": [],
  "final": []
};
```

Question fields:

| Field | Required | Meaning |
|---|---:|---|
| `q` | yes | Question stem |
| `o` | yes | Answer-option array |
| `a` | yes | Zero-based correct-option index |
| `p` | no | Points/difficulty; 1 introductory, 2 standard, 3+ advanced |
| `e` | no | Explanation |
| `u` | diagnostic only | Module keys recommended after an incorrect answer |

`a` is zero-based. New MCQs should normally use four sensible, unique options, although deliberate formats such as true/false are permitted.

Invalid questions with missing `q`, `o` or `a`, no options, or an invalid answer index are skipped by the importer.

Do not use comments or trailing commas inside canonical `QUIZ`/`REVIEW` declarations.

## 12. REVIEW data

Optional structured review cards:

```javascript
const REVIEW = {
  "m1": {
    "t": "Module 1 key points",
    "pts": ["First point.", "Second point."]
  }
};
```

Static review prose must exist in `.review-inner`; do not rely on JavaScript to generate it.

## 13. Static HTML and JavaScript

The importer parses rather than executes JavaScript. Scripts and event attributes inside extracted lesson fragments are retained. Standalone page-shell scripts outside those fragments are not copied as an application shell: the LMS supplies its own navigation, progress and assessment UI. `QUIZ` and `REVIEW` declarations are read as data, not executed.

Bad:

```html
<div id="example"></div>
<script>example.innerHTML = "Important lesson content";</script>
```

Good:

```html
<div id="example"><p>Important lesson content.</p></div>
```

JavaScript may enhance standalone interaction but must not be the only source of content that must import.

## 14. CSS and supported presentation

Course `<style>` blocks are imported, with ordinary selectors scoped under the LMS course-presentation wrapper to retain the existing course layout contract. Author-provided `@import` rules and external stylesheet links are preserved, including Google Fonts and other author-chosen sources. There is no stylesheet-domain allowlist. Referenced resources must be available at their authored URLs; HTML/JSON import does not bundle local sibling files automatically.

Course CSS must style course content only and must not depend on overriding the LMS shell/navigation or structural content-card container.

Prefer native `<details>/<summary>` for collapsible lesson content that must survive import.

## 15. Markup validity (not content security filtering)

- Keep HTML ids unique.
- Entity-encode literal `&`, `<` and `>` where required.
- Never place a literal `</script>` sequence inside JavaScript string data; use `<\/script>` if genuinely required.
- Keep JavaScript declarations syntactically valid.
- Other ordinary HTML closing tags inside strings do not require special escaping merely because they are closing tags.

## 16. Conformance checklist

Course level:

- [ ] `.masthead` has explicit `data-level`.
- [ ] Clean title, subtitle and summary use the canonical fields.
- [ ] Teaching module keys are sequential `m1...mN`.
- [ ] One `QUIZ` declaration contains all assessment banks.
- [ ] `REVIEW` exists when a review module exists.
- [ ] Diagnostic uses `diagnostic` / `diag` / `QUIZ.diag`.
- [ ] Final uses `final` / `QUIZ.final`.
- [ ] Review, if present, is exactly `mod-review`.
- [ ] No duplicate HTML ids.

Every teaching module:

- [ ] `<section class="module ..." id="mod-mN">`.
- [ ] Explicit `data-assessment-required`.
- [ ] `.m-name`, subtitle, `.module-body` and `.outcomes` exist.
- [ ] Important content is static HTML.
- [ ] Summary is in `.s-summary .sub-inner` when required.
- [ ] No substantive content is trapped inside `.subs`.
- [ ] Required assessment has matching non-empty `QUIZ.mN`.
- [ ] Unassessed module has no orphan required question bank.

Every question:

- [ ] `q`, `o`, `a` exist.
- [ ] options are sensible and unique.
- [ ] `a` points to a valid option.
- [ ] `p`, when supplied, is a positive integer.
- [ ] diagnostic `u` entries reference valid module keys.

## 17. Validation

Before delivery:

1. syntax-check JavaScript;
2. validate `QUIZ` and `REVIEW` data;
3. reconcile every module's assessment flag with its bank;
4. verify correct-answer indexes;
5. check duplicate HTML ids and structural HTML errors;
6. run the Catto Learning importer;
7. verify SVG graphics, gradients, clipping, authored styles and interactions in the rendered lesson;
8. verify importer preview metadata, module order, outcomes, summaries, assessment flags/counts, review, diagnostic/remediation, final assessment and detected presentation CSS.

**The Catto Learning importer preview is the final conformance authority.**

## 18. Course Components contract (active development update)

The owner-approved Course Components v2 specification supersedes module-centric runtime assumptions. The application version is 0.8.6.1. Consult HANDOFF.md for the implemented contract and validation record.

### Identity, sharing and author control

A Course Item is an independent reusable record. A course placement supplies its structural position, optional title/description overrides, public-preview flag and assessment role. Course Items and sections form a mixed ordered tree with at most three levels. An item or section may parent another Course Item or section. Assessments and diagnostics have the same sharing, Save, Save As and deletion lifecycle as other Course Items. The Course Content editor is a nested tree. Every move is saved at once by one validated operation that places the moved rows, each with its whole subtree, under a parent at a position (`CourseItemService::arrange` and `move`, through `POST /admin/courses/{id}/content/arrange`, `/{node_id}/move` and `/move-selection`). It refuses a destination outside the course, a destination inside the moved rows (a cycle), a result deeper than three levels and a position past the end of the destination's rows; positions are renumbered 1..n per parent and only changed rows are written. Rows move by drag and drop from a handle (before, after or inside a row), by keyboard on the handle, by the row ⋯ menu (Move to top, Move up, Move down, Move to bottom, Move into section…, Move out of section) and as a selection; without JavaScript the ⋯ menu and the Move into page are ordinary forms. Moving out places the row immediately after its former parent. "+ Add here" passes an exact parent and position to Create new item, Add existing item and Add section. Other placement and section forms have their own explicit Save actions. Removing a parent placement with children is blocked until ADMIN explicitly moves or removes those children.

Course Item keys are unique and case-sensitive, accept ASCII letters, numbers, dots, underscores and hyphens, begin with a letter or number, and contain at most 120 characters. ADMIN may change a key. Changing it never rewrites authored content. `[course-item:My-Key]` remains stored as source and is resolved at display time by the referenced item's type renderer. Broken draft references are allowed; unresolved or circular references block publication. The library shows standalone and embedded usages. Permanent deletion requires zero placements and zero live embedding references.

Save changes the shared item everywhere. Save As requires an explicit new key and title and creates independent content/configuration/question records. Resource-backed copies initially reference the same immutable Resource file. No automatic repair, cleanup, substitution, collision renaming, key-reference rewriting or inferred ADMIN intent is permitted. Assistance may be offered but must be explicitly chosen. There is no development backward-compatibility requirement for obsolete database behavior.

### File and content policy

HTML lessons retain trusted authored HTML, SVG, CSS, scripts and embedded markup. Inline images, SVG and YouTube embeds remain ordinary authored content unless explicitly defined or referenced as Course Items. Import must not atomise media fragments.

PDF, image/graphic, uploaded video, audio files, Markdown, Word, Excel, OpenOffice and other downloads reference the Resource Library. YouTube IDs and remote audio URIs are pointers, not Resources. Registered files occupy one flat `course-resources` directory; filenames and titles are unique and duplicates are rejected without renaming. Files are immutable. Upload or register a new external file, explicitly change references, then optionally delete an unreferenced old Resource. Poster/subtitle references also protect a Resource from deletion.

**Downloadable File** (`downloadable_file`) is the one generic item type for files learners download: ZIP archives now, and any other file type without a new item type. It has a title, a description and one Resource, and is placed, ordered, sectioned, drip-fed and reused like any other Course Item; one stored Resource serves every course that places it. ADMIN chooses an existing Resource or uploads a new file from the item form; an upload is added to the Resource Library once (classified `archive`, `pdf`, `document` or the general `file` from its name) and then referenced. Resources stay in the instance storage directory outside the public web root. Learners download only through `/learn/{slug}/item/{node_id}/download/{item_key}`, which requires a signed-in learner with a started, current enrolment in that course, a placement of that course that is unlocked, and a Downloadable File that is the placement's item or is embedded in its HTML lesson with `[course-item:key]`. The file is sent as an attachment with its stored MIME type, a path-free filename with an ASCII fallback, `nosniff` and private `no-store` caching. The general `/course-resources/{public_id}` route never serves a Resource through a Downloadable File, and public previews show the file card without a link. A Resource referenced by any Course Item cannot be deleted.

The former `document` item type only offered a download link and was folded into Downloadable File (migration `20261002110000`); `document` remains a Resource classification for Word, Excel and OpenDocument files.

**Creating items (owner instruction, 2026/10/02).** Resource = reusable file; Course Item = reusable content object; Course = ordered placements of Course Items. Content is added to a course only through "+ Add here" in the Course Content tree (owner instruction, 2026/10/02; the separate Add item area is removed). Every slot — the start of the course, of each section and of each parent with children, after every row, inside an empty section and in an empty course — carries the exact parent and position, and offers Create new item (choose a type, configure it with its availability, then Create and add to course, which creates the item and places it there in one transaction), Add existing item (search and place a shared item) and Add section (offered only where the new section stays within three levels). Each opens its form in the editor's insert modal; a successful insertion closes it and refreshes the tree in place, and a refusal keeps the form open with the reason and the entered values. Insertion uses the same placement service as moves (`CourseItemService::addExisting`, `addSection` and `createAndPlace` with `insert_index`), which checks that the parent is in the course, the depth and the position. Without JavaScript each action opens the same form on its own page. The Course Item Library has Create item, which creates an unassigned item listed under Not currently in use. Resource-backed item forms (PDF, image, uploaded video, audio, Markdown, Downloadable File) offer Choose existing resource, listing only compatible files, and Upload new resource, whose Upload and select stores the file once in the Resource Library, selects it and redisplays the form with the entered values; a file chosen with Save is uploaded the same way. The server accepts a Resource only if it exists and suits the item type: PDF needs `pdf`, image `image_graphic`, uploaded video `uploaded_video`, audio `audio`, Markdown `markdown`, and Downloadable File accepts any classification. The Resource Library edits title, description and classification (never the file), refuses a classification that no longer suits an item using the file, shows usage, deletes only unused files, and offers Create course item, which lists only the item types that can use that file and goes straight to the form when only one can.

### Learning and availability

Deliberate Start Course begins learner-relative content availability timing. Delays use whole weeks/days/hours/minutes stored as total minutes and are relative to the preceding structural element. A scheduled section controls all descendants. ADMIN may change content and availability during development even after testers start; the current schedule then applies to their next request. Public placements ignore delays, require no enrolment and record no progress. Anonymous assessment previews return an immediate, unrecorded result.

ADMIN may grant a draft or published course to active users with verified email addresses from either the course page or the person's administration profile. An ADMIN grant has a positive whole-day duration that begins when it is made; Start Course begins the learner's progress but never extends that grant's expiry. Company and purchased access retain their own existing timing rules. Duplicate open grants are rejected, while an elapsed grant is marked expired and may be granted again. Both ADMIN contexts show test-grant status, grant time and expiry, including ended grants. ADMIN may explicitly revoke a current test grant; the existing enrolment-removal rule retains progress and results. ADMIN may deliberately send or resend a course invitation to a learner with current test access; granting alone does not send the invitation. The email links to the learner course and states the expiry in UTC. Replacement import is permitted after testers start and clears old course work, certificates and ordinary grants. Purchased enrolments remain linked to immutable commerce history, but their old course work is cleared. A replacement does not silently preserve or reconstruct test progress. ADMIN can grant friends access again after replacement.

Progress comes only from submitted non-practice graded assessments. Ordinary content has locked/unlocked state only; there is no passive completion, Mark as complete, required/optional ordinary content or section progress. Preceding graded assessments collectively contribute 50% and the final contributes 50%. Exactly one final is required in an assessed course; preceding graded assessments must be submitted, not necessarily passed. Final submission completes the enrolment. Assessmentless courses have no invented completion event. Diagnostics never grade or complete a course.

The course reader and assessment pages use the Core-owned full-screen white presentation layout, persistent outline, exit action and Previous/Next navigation. Themes cannot wrap or style this screen. In the outline a section reads "Section: <title>" (presentation only), bold and slightly larger than items. `CourseItemRenderer` renders each Course Item's description exactly once (a placement's override replaces it): within the media figure, PDF, assessment section or Downloadable File card, and above the content of an HTML lesson or Markdown item; item pages print none of their own, in learner mode and public preview alike. Reader actions such as Download and Previous/Next are the shared primary action (`cl-ui-action cl-ui-action--primary`), blue with white text in every state even where the course's own presentation CSS styles links. Outline entries are indented by their depth with a guide line, so what a section or module holds sits visibly inside it. Previous and Next follow one reading order everywhere (`CourseNavigation`): every Course Item placement and every section with an introduction or outline; a heading-only section is not a stop. The public preview, which shows only public items and no sections, applies the same rule to what it shows. Embedded items do not add sequence positions unless separately placed.

### HTML import mapping

Existing conforming HTML remains valid input. Teaching modules become HTML Course Items and placements. Separately represented graded assessments, diagnostics, finals and review components become their own Course Items. A module assessment is placed as a child of its source HTML module; final assessments remain top-level. Author identifiers/titles produce deterministic keys; existing/imported key collisions are reported and block commit pending explicit resolution. Existing authored media remains embedded. Shortcodes may refer to definitions in the package or an existing Course Item by exact key. Missing references are import errors. Commit is transactional; technical failures do not leave half-created courses.

### Grading scale (owner instruction, 2026/09/25)

The course grade bands are the only definition of a course's grading scale. They set each graded and final assessment's band, the final course grade (fixed 50% module, 50% final), pass or fail, and whether a certificate is issued. A band stores only where it starts; it runs to just below the next band's start, and the top band ends at 100%. ADMIN edits the bands on the course editor's Grades tab. Diagnostics use their own pass mark instead.

`[grading-scale]` in course or section introductions and HTML lessons is replaced at display time with the current bands, so authored copies of the scale cannot disagree with the grading. The list carries `cl-grading-scale` and the `scale-list` class the original course stylesheets style.

HTML import reads the scale from the file: the shown `ul.scale-list` first, then the `bandFor()`/`gradeBand()` grading function; codes come from that function, otherwise from a trailing "(A)"; bands whose label contains "fail" are not passing. With neither, the standard five bands are used and the import review says so. On the owner's instruction, and as a deliberate exception to preserving authored content, import also removes from the introduction the parts that only worked in the standalone file: the static scale list (replaced by `[grading-scale]`), the `#resetBtn` Reset all saved progress button, and sentences saying progress is saved in the browser. The import review lists each change.

### Structured interchange 2.0

The JSON package uses `format: catto-learning-course`, `schema_version: 2.0`, course metadata, `course_items`, `structure`, Resource metadata and assessment banks. Each item contains its exact `item_key`, `item_type`, title/description, source, type configuration, Resource public identifier where relevant and assessment configuration/questions. Each structure row has `node_key`, optional `parent_node_key`, `node_type`, position and relative delay; item rows reference `item_key` and carry placement overrides/public/final settings. Parents precede children. Export includes items reachable through shortcodes, preserving source. Question and option database IDs are excluded so import creates independent identities. Resource files must be registered explicitly on the target instance; export does not duplicate physical files. Existing keys are not silently reused or overwritten when a package also defines them.

### Publication

Drafts may be incomplete. Publication errors include unresolved item references, invalid assessment/final relationships, missing required Resources and schedules unlocking at or after expiry. Less than seven days remaining after unlock is a warning. ADMIN may explicitly override warnings; errors cannot be overridden. Saving a changed duration does not silently rewrite availability.
