# Catto Learning Course Components and HTML Authoring Specification

**Target LMS:** 0.9.2 (0.9 code line) **Date time:** 2026/10/17 SAST **Status:** Canonical specification for the implemented HTML course authoring/import workflow and the Course Components domain.

## 1. Purpose

New HTML courses intended for Catto Learning must use one predictable static structure. The importer parses HTML and assessment data; it does **not** execute course JavaScript during import.

Important content must therefore exist as real HTML. Assessment banks must exist in the `QUIZ` object. JavaScript may enhance the standalone course but must not be the sole source of teaching content.

Sections 2–17 describe the accepted authored HTML input syntax. Section 18 describes the Course Components domain, learner access and the structured interchange format as implemented in v0.8.8.13. Module terminology in the input syntax describes source documents, not database ownership or learner completion.

### 1.1 Trusted author content

**SVG graphics must not be stripped.** Inline `<svg>` graphics in imported course content and uploaded `.svg` course-media files are supported authoring formats. Authors do not need to convert SVG diagrams to PNG or remove SVG features to satisfy an importer security filter.

The platform owner authors and imports these courses. Course content is trusted. The importer and course editor do not run an HTML/SVG element allowlist, attribute/style/URL filtering, or malicious-code stripping. Authored SVG, HTML, inline styles, event attributes, embedded scripts and stylesheet imports are preserved in the content fields that map into the LMS. Saving and exporting that content uses the same policy. Uploaded course media, including `.svg`, is stored unchanged; MIME detection describes the file rather than deciding which content is trusted.

Course structure and data still have to be valid: titles, module identities, supported block types, question banks, answer indexes and grading/pool rules remain checked. A course carries only its certificate switch and its own accreditation or CPD line (`certificate_enabled`, `certificate_accreditation`), never a certificate design or its wording: packages are exported without one, an imported course is drawn in the default design for new courses, and import ignores the older certificate title, body, footer, signatory and `certificate_template_html`/`css` fields. The existing import permissions, file-size limits, course ownership and learner-access rules remain in force. This is a content-preservation policy, not a change to authentication or course access.

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

Learning outcomes stay inside the module content; there is no course-level outcomes field. A course landing page's Learning outcomes section is therefore written by ADMIN in the landing page editor and never read from, or written back to, the course.

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

## 18. Course Components contract

The owner-approved Course Components v2 specification (released in v0.8.6) supersedes module-centric runtime assumptions. This section is the current contract.

### Identity, sharing and author control

A Course Item is an independent reusable record. A course placement supplies its structural position, optional title/description overrides, public-preview flag and assessment role. Course Items and sections form a mixed ordered tree with at most three levels. An item or section may parent another Course Item or section. Assessments and diagnostics have the same sharing, Save, Save As and deletion lifecycle as other Course Items. The Course Content editor is a nested tree. Every move is saved at once by one validated operation that places the moved rows, each with its whole subtree, under a parent at a position (`CourseItemService::arrange` and `move`, through `POST /admin/courses/{id}/content/arrange`, `/{node_id}/move` and `/move-selection`). It refuses a destination outside the course, a destination inside the moved rows (a cycle), a result deeper than three levels and a position past the end of the destination's rows; positions are renumbered 1..n per parent and only changed rows are written. Rows move by drag and drop from a handle (before, after or inside a row), by keyboard on the handle, by the row ⋯ menu (Move to top, Move up, Move down, Move to bottom, Move into section…, Move out of section) and as a selection; without JavaScript the ⋯ menu and the Move into page are ordinary forms. Moving out places the row immediately after its former parent. "+ Add here" passes an exact parent and position to Create new item, Add existing item and Add section. Other placement and section forms have their own explicit Save actions. Removing a parent placement with children is blocked until ADMIN explicitly moves or removes those children.

Course Item keys are unique and case-sensitive, accept ASCII letters, numbers, dots, underscores and hyphens, begin with a letter or number, and contain at most 120 characters. ADMIN may change a key. Changing it never rewrites authored content. `[course-item:My-Key]` remains stored as source and is resolved at display time by the referenced item's type renderer. Broken draft references are allowed; unresolved or circular references block publication. The library shows standalone and embedded usages. Permanent deletion requires zero placements and zero live embedding references.

Every Course Item also has a name (owner, 2026-10-30), which only administrators see: never used twice, in any case, at most 160 characters, and what the Course Item Library lists and searches (with the title and key). Learners see the title, which several items may share. A name left empty is taken from the item's place: the course's code, then the module and the part (`GDN-101 Module 1 Presentation`), or the code and the item's title outside the modules (`GDN-101 Final assessment`), with the first free number after it when the place already gives that name (`GDN-101 Module 1 Video 2`), or the title while the item is placed nowhere. A course package (2.1) carries each item's name, and an import keeps it, with the first free number after it when another item already has it; an item that comes without one, and the parts Arrange into modules makes, are named after their places, and arranging renames the items still named after their old places; a name typed by hand stays. A Presentation's description is one plain line of up to 100 characters, shown in its player's top bar.

Save changes the shared item everywhere. Save As requires an explicit new key and title and creates independent content/configuration/question records. Resource-backed copies initially reference the same immutable Resource file. No automatic repair, cleanup, substitution, collision renaming, key-reference rewriting or inferred ADMIN intent is permitted. Assistance may be offered but must be explicitly chosen. There is no development backward-compatibility requirement for obsolete database behavior.

### File and content policy

HTML lessons retain trusted authored HTML, SVG, CSS, scripts and embedded markup. Inline images, SVG and YouTube embeds remain ordinary authored content unless explicitly defined or referenced as Course Items. Import must not atomise media fragments.

PDF, image/graphic, uploaded video, audio files, Markdown, Word, Excel, OpenOffice and other downloads reference the Resource Library. YouTube IDs and remote audio URIs are pointers, not Resources. Registered files occupy one flat `course-resources` directory; filenames and titles are unique and duplicates are rejected without renaming. Files are immutable. Upload or register a new external file, explicitly change references, then optionally delete an unreferenced old Resource. Poster/subtitle references also protect a Resource from deletion.

**Downloadable File** (`downloadable_file`) is the one generic item type for files learners download: ZIP archives now, and any other file type without a new item type. It has a title, a description and one Resource, and is placed, ordered, sectioned, drip-fed and reused like any other Course Item; one stored Resource serves every course that places it. ADMIN chooses an existing Resource or uploads a new file from the item form; an upload is added to the Resource Library once (classified `archive`, `pdf`, `document` or the general `file` from its name) and then referenced. Resources stay in the instance storage directory outside the public web root. Learners download only through `/learn/{slug}/item/{node_id}/download/{item_key}`, which requires a signed-in learner with a started, current enrolment in that course, a placement of that course that is unlocked, and a Downloadable File that is the placement's item or is embedded in its HTML lesson with `[course-item:key]`. The file is sent as an attachment with its stored MIME type, a path-free filename with an ASCII fallback, `nosniff` and private `no-store` caching. The general `/course-resources/{public_id}` route never serves a Resource through a Downloadable File, and public previews show the file card without a link. A Resource referenced by any Course Item cannot be deleted.

The former `document` item type only offered a download link and was folded into Downloadable File in v0.8.7.4; `document` remains a Resource classification for Word, Excel and OpenDocument files.

**Creating items (owner instruction, 2026/10/02).** Resource = reusable file; Course Item = reusable content object; Course = ordered placements of Course Items. Content is added to a course only through "+ Add here" in the Course Content tree (owner instruction, 2026/10/02; the separate Add item area is removed). Every slot — the start of the course, of each section and of each parent with children, after every row, inside an empty section and in an empty course — carries the exact parent and position, and offers Create new item (choose a type, configure it with its availability, then Create and add to course, which creates the item and places it there in one transaction), Add existing item (search and place a shared item) and Add section (offered only where the new section stays within three levels). Each opens its form in the editor's insert modal; a successful insertion closes it and refreshes the tree in place, and a refusal keeps the form open with the reason and the entered values. Insertion uses the same placement service as moves (`CourseItemService::addExisting`, `addSection` and `createAndPlace` with `insert_index`), which checks that the parent is in the course, the depth and the position. Without JavaScript each action opens the same form on its own page. The Course Item Library has Create item, which creates an unassigned item listed under Not currently in use. Resource-backed item forms (PDF, image, uploaded video, audio, Markdown, Downloadable File) offer Choose existing resource, listing only compatible files, and Upload new resource, whose Upload and select stores the file once in the Resource Library, selects it and redisplays the form with the entered values; a file chosen with Save is uploaded the same way. The server accepts a Resource only if it exists and suits the item type: PDF needs `pdf`, image `image_graphic`, uploaded video `uploaded_video`, audio `audio`, Markdown `markdown`, and Downloadable File accepts any classification. The Resource Library edits title, description and classification (never the file), refuses a classification that no longer suits an item using the file, shows usage, deletes only unused files, and offers Create course item, which lists only the item types that can use that file and goes straight to the form when only one can.

### Learning and availability

Deliberate Start Course begins learner-relative content availability timing, and takes the learner straight to the course's first part in reading order (2026-10-29), the place Begin course leads to afterwards. An administrator's preview enrolment runs for the period a buyer would get, the course's default price option or else its default access period, so its start page names that period; one not yet started follows price changes. Delays use whole weeks/days/hours/minutes stored as total minutes and are relative to the preceding structural element. A scheduled section controls all descendants. ADMIN may change content and availability during development even after testers start; the current schedule then applies to their next request. Public placements ignore delays, require no enrolment and record no progress. Anonymous assessment previews return an immediate, unrecorded result.

ADMIN may grant a draft or published course to active users with verified email addresses from either the course page or the person's administration profile. An ADMIN grant has a positive whole-day duration that begins when it is made; Start Course begins the learner's progress but never extends that grant's expiry. Company and purchased access keep their own timing rules (see Access sources below). Duplicate open grants are rejected, while an elapsed grant is marked expired and may be granted again. Both ADMIN contexts show test-grant status, grant time and expiry, including ended grants. ADMIN may explicitly revoke a current test grant; the existing enrolment-removal rule retains progress and results. ADMIN may deliberately send or resend a course invitation to a learner with current test access; granting alone does not send the invitation. The email links to the learner course and states the expiry in UTC. Replacement import is permitted after testers start and clears old course work, certificates and ordinary grants. Purchased enrolments remain linked to immutable commerce history, but their old course work is cleared. A replacement does not silently preserve or reconstruct test progress. ADMIN can grant friends access again after replacement.

Progress comes only from submitted non-practice graded assessments. Ordinary content has locked/unlocked state only; there is no passive completion, Mark as complete, required/optional ordinary content or section progress. Preceding graded assessments collectively contribute 50% and the final contributes 50%. Exactly one final is required in an assessed course; preceding graded assessments must be submitted, not necessarily passed. Final submission completes the enrolment. Assessmentless courses have no invented completion event. Diagnostics never grade or complete a course.

The course reader and assessment pages use the Core-owned full-screen white presentation layout, persistent outline, exit action and Previous/Next navigation. Themes cannot wrap or style this screen. In the outline a module's section reads "Module N: <title>" and any other section "Section: <title>" (presentation only), bold and slightly larger than items; inside a module each part shows its kind above its title and its time below it (Module sections and their parts, below). `CourseItemRenderer` renders each Course Item's description exactly once (a placement's override replaces it): within the media figure, PDF, assessment section or Downloadable File card, and above the content of an HTML lesson or Markdown item; item pages print none of their own, in learner mode and public preview alike. Reader actions such as Download and Previous/Next are the shared primary action (`cl-ui-action cl-ui-action--primary`), blue with white text in every state even where the course's own presentation CSS styles links. Outline entries are indented by their depth with a guide line, so what a section or module holds sits visibly inside it. Previous and Next follow one reading order everywhere (`CourseNavigation`): every Course Item placement and every section with an introduction or outline; a heading-only section is not a stop. Sections and Course Item placements each opt into the public preview with their own Public preview setting (sections since v0.8.7.9); the public outline, section pages and Previous/Next show only what is public, by the same rule. Embedded items do not add sequence positions unless separately placed.

### Module sections and their parts (course structure, 2026-10-28)

The owner chose the course structure on 2026-10-28 (decisions 1A to 8A): a module is a section of its own holding its parts, and each part is a Course Item placed in it.

- **A module is a numbered section.** A section whose "This section is a module: number it" setting is ticked (`course_sections.numbered`) is numbered by the site in course order among the numbered sections, so moving one renumbers the rest. Numbering starts at 1, or at 0 for a course whose first module sets it up, such as a practice environment and a readiness check (the course's "Number the modules from" setting on its Details tab, `courses.first_module_number`, carried by course packages). It reads "Module N: <title>" in the outline, on its page and on each of its parts' pages; the stored title never holds the number. Its introduction opens the module and usually lists its outcomes (`ol.cl-section-outcomes`). Any other section is unnumbered and reads as it did, for example the course's Review.
- **Its parts, in this order:** a Presentation (`slide_deck`, the module's opening, CatWalk: `docs/CATWALK.md`), a Video (`youtube`, optional, with an optional start time in seconds for a long video of which only part is relevant; someone else's video can disappear, so the daily `video_check` job asks YouTube whether each course video still plays and lists one that does not under Needs your attention), the Lesson (`html_lesson`, the main event), the Summary (an HTML lesson whose role is Summary, `type_config.presentation_role` `summary`), Flash cards (`flashcards`), Practical work (`practical`), a Review study aid (optional, an HTML lesson whose role is a review study aid, `presentation_role` `review_study_aid`: the points to fix in memory before the self assessment) and the module's Assessment. The kind of each part (`CourseItemService::part()`, `PART_LABELS`) is worked out from its item, never stored, and shown only inside a module.
- **Times.** Each item has an optional "Time to study (minutes)" (`course_items.estimated_minutes`, 1 to 1440). Without one it is estimated: about 25 seconds a slide, 45 seconds a flash card, 90 seconds an assessment question, and a lesson or practical work at 120 words a minute, to the next five minutes; a video without a stated time has none. A module shows the sum of its parts ("In all: 2 hours 5 minutes") on its page and in the outline. Times are shown only inside modules.
- **Flash cards.** Up to 60 cards of plain text: a front (up to 200 characters), a back (up to 400) and an optional hint (up to 150, never the answer itself); 10 to 20 is the guidance. The player shows one card at a time: Turn over, then I knew it or Again, and a card marked Again comes back later in the round until every card is known. Nothing is recorded: progress stays assessment-only. Without JavaScript the cards are a list, each answer behind Show the answer. The editor also takes a pasted list, one card a line, `front | back | hint`.
- **Practical work.** The tasks (HTML, a `section.task` per task with "What you produce") and a model answer (`type_config.answer_html`, required), shown behind "Show the model answer". Nobody marks it: the learner checks their own work against the model.
- **PDFs.** The lesson, summary, review study aid, flash cards and practical work each download as a PDF ("Download as PDF", `/learn/{slug}/item/{node_id}/pdf`, and `/courses/{slug}/preview/{node_id}/pdf` for a public placement), to whoever may read the part, drawn by `DocumentPdfRenderer` with the module and part named at the top (`Course\Parts\CoursePartPdfs`). Drawings print as pictures, flash cards as a table, and practical work prints its tasks, then the model answers from a new page. A PDF is kept by the hash of what it prints (`storage/course-pdfs/`), so it is drawn again only when the part changes. A presentation, a video and an assessment have no PDF.
- **Arranging an older course.** A course of the older shape, one HTML lesson per module with the module's assessment beneath it, can be arranged into module sections in one step: Course Content offers "Arrange into modules" (`CourseRestructurer`, `POST /admin/courses/{id}/content/arrange-modules`). Each module lesson becomes a numbered section holding its parts: the outcomes move into the section's introduction, the lesson's first YouTube video becomes a Video part before it, its closing `<section><h2>Summary</h2>` becomes a Summary part after it, its practical tasks and stock answers become Practical work after that, the lesson's Review Study Aid block (`details.review-aid`) becomes a Review study aid part, opening with its subtitle, and the module's assessment follows; the course's own review study aid gets an unnumbered Review section. For a course already arranged whose module lessons still end with their summary, the same action offers "Move the summaries into parts of their own", and for one whose lessons still hold their review study aid, "Move the review study aids into parts of their own", each placed before its module's assessment. The importer and the arranger parse lessons without the HTML namespace and write them back through `CourseHtml::html`, so line breaks, pictures, scripts, styles and SVG come back exactly as authored. Nothing is deleted, keys are kept (a new part takes the lesson's key with `-video` or `-practical` added, numbered when that key is taken), and a course already arranged is not offered it. An HTML import is arranged as it is imported (HTML import mapping, below).

### Access sources, purchases, bundles and reviews

A learner has one enrolment per course: the library, the reader, progress, results and certificates all use it, and it is shown once. Behind it are its access sources, each with its own access period, activation and expiry: an individual purchase, each bundle that includes the course, free access, and the enrolment's own ADMIN, company-credit or seed term (recorded as its `origin` source once a commerce source joins it). Purchased access starts when the learner starts the course or automatically at the activation deadline (90 days after payment); a source granted to a learner who has already started runs from its grant. The course stays open while any source is valid and ends when none is; one source ending or being refunded never shortens another. Learning history survives the end of access.

A bundle is a commerce and catalogue offer that contains published courses; it is not a course and has no content, reader, progress or certificate of its own. Each course a bundle grants is opened, studied and graded exactly as if bought on its own. Removing a course from a bundle, or retiring or deleting the course, never changes an order that included it: the order keeps the courses it was sold with, and a bundle with an unpublished course cannot be bought.

Course reviews belong to the course: only learners with a genuine enrolment may rate and review it (`/learn/{slug}/review`), nothing is public until ADMIN approves it, and the course rating uses approved versions only. A learner is asked at the moment they finish (decision 8A): the final assessment's result page holds the review form, which comes back to it, until they have reviewed the course; and three days after completing it, if they still have not, they are emailed once (`Course\ReviewRequests`, the `review_requests` job; `course_enrolments.review_requested_at`), never at an address on Addresses never emailed and never about a course completed more than 30 days before. Course popularity is a stored snapshot calculated by `popularity:recalculate`, never on a request.

### Landing pages

A course's catalogue page (`/courses/{slug}`, `CourseController::detail`, owner 2026-10-30, the accepted mockup `REPORTS/course-page-mockup/` with its head kept as it was) helps someone choose the course. Its head is the cover with the title and the favourite star over it; under the cover come the subtitle, the rating, **Watch course presentation** (when the course has a trailer in its free preview: the trailer plays in a window as wide as the page's box, `overlay.modal` `size: 'page'`, its slides scaled to the screen's height; its Get this course button closes the window and shows Course access; without JavaScript the button opens the trailer in the free preview) and the number of modules, the study time and "Starts immediately". Then About this course; How each module works, one paragraph worked out from the parts the modules hold and the parts as a strip (`Course\CoursePageView`: a part is "every module" only when every module has it, "most" or "some" otherwise; narration only when a module's presentation has a recording; the module's usual length in plain words); the modules, numbered, each with its study time and, for one in the free preview, a link to it, and what follows them (a course review and the final assessment, half and half); What you get, counted (presentations, videos, flash cards, practical tasks, module and final questions, each only when the course has some); and the reviews; beside them Course access, the one buy box, and At a glance; then the free preview offered again. A course without numbered modules lists what it holds, as before.

A course may have an optional marketing landing page (`/landing/{slug}`, Phase L). For a course of numbered modules its Course contents section shows the modules, the paragraph and the parts as the course's page does (2026-10-30); otherwise it reads the course outline live through `LandingPageRepository::outline()`: section and item titles, item kinds, the free-preview flag and, when the section asks for them, items' own descriptions as plain text. It never reads or shows lesson HTML, section introductions, assessments, questions, answers, files or download addresses. A Presentation section (one a page, CatWalk, 2026-10-28) plays one of the presentations placed in the course, usually its trailer (a deck of the `trailer` kind, ending with a call to action whose buttons lead to the course page and its free preview); its narration is public while the page is published. Saving or publishing refuses a presentation that is not in the course. Nothing on the landing page changes the course.

### Certificates and certificate designs

A certificate is drawn in a **certificate design**: a look, the wording, a signature and small print, made by ADMIN at Courses → Certificate Designs (`/admin/certificates/designs`, `CERTIFICATE.DESIGN.VIEW` to see and `CERTIFICATE.DESIGN.MANAGE` to change). Designs are stored as certificate templates of the shared document template engine (`src/Document/`), but nobody edits template HTML, CSS, versions or page settings: those are compiled from the design.

- **Six installed designs.** Classic (the default for new courses), Modern, Minimal, Legal Seal, Gold Frame and Professional CPD, one for each installed look (`CertificateLooks`). Every certificate is A4 landscape on one page.
- **One form.** A design's page (`/admin/certificates/designs/{id}`, or `/new?look=` for a new one) has its name (not printed), the look chosen by picture or an uploaded background, an optional logo, the wording, the signatory's name and position with an optional signature picture, and the small print. The preview above the form, at the full page width, is the PDF exactly as it prints, for a sample learner; it redraws a moment after any change (`certificate-preview` Stimulus controller), and without JavaScript the Update preview button draws it into the same frame.
- **Wording.** The wording and small print are written in CKEditor (`public_html/js/ck-certificate.js`) in paragraphs with the certificate's text styles (Certificate title, Learner name, Course title, Small capitals, Small text), bold, italic and alignment. Anything that changes per learner is a field inserted with Insert field and shown as a blue label (Learner name, Course title, Completion date, Date issued, Accreditation line, Course provider, Certificate number, Verification address); without JavaScript a field is typed as `[Learner name]`. Nothing else is kept: pasted formatting is dropped (`CertificateWording`). A line holding Result, Grade, Accreditation line, Course provider or Verification address is left out when that value is empty, so a course without marks prints no result line. The wording must include the Learner name field.
- **Your own background.** A PNG or JPEG in A4 landscape proportions, at least 1240 px wide (a Canva download at 3508 × 2480 px is ideal), becomes the design's look when uploaded. It is scaled to 2480 × 1754 and stored opaque in `document_assets` (logos and signatures keep their transparency). Its words go in the centre, on the right or on the left, in a chosen typeface and title colour. Pictures are stored once by SHA-256 and never changed.
- **The signature picture.** Any PNG or JPEG of a signature, at any size: on upload its white or near-white paper becomes transparent, the empty margins around the ink are trimmed, and it is stored as a PNG of at most 1200 × 400 px (`CertificateImage`). On the certificate it is as tall as the look's foot allows above the signatory's line, name and position (at most 20 mm, at most 64 mm wide), centred on the line and resting 1 mm above it. The name and position may wrap, so they are measured in the PDF engine before the design is compiled (`CertificateDesignCompiler::signatureProbe()`); they are plain text, so the size is the same on every certificate issued. Nobody resizes or positions the picture by hand.
- **It must fit.** Saving draws the design with a sample learner and refuses it, saying so, when the words do not fit the look's box on one page. A signatory whose name and position leave less than 6 mm for the signature picture is refused with a message about the signatory.
- **Saving makes a new version.** Certificates issued from then on use it; certificates already issued keep the version they were issued with. Duplicate copies a design. Delete asks which design the courses using it (and, for the default, new courses) move to; the deleted design leaves the lists, and its versions stay for the certificates issued with them. New courses start with the design chosen as the default on the list.
- **The course chooses a design.** On its certificate page (`/admin/courses/{id}/certificate`, `COURSE.CERTIFICATE.MANAGE` on a course the person manages) a course says whether it gives a certificate, picks its design by picture, and sets its own optional accreditation or CPD line (`course.accreditation`). The preview there is this course's certificate for the person viewing it, exactly as issuing will draw it. Edit this design and New design open the design page and return to the course.
- **One rendering path.** Previews, issuing, the certificate page and the certificate PDF all go through `DocumentTemplateRenderer` and `DocumentPdfRenderer` with the bundled fonts in `resources/documents/fonts/`. `CertificateDocumentDataBuilder` builds the values: `certificate.number`, `certificate.issue_date`, `certificate.verification_url`, `learner.name` (the learner's verified name, exactly as on their identity document), `course.title`, `course.provider`, `course.completion_date`, `course.accreditation`, `result.percentage`, `result.grade` and `business.*`. An absent optional value is null.
- **Issuing (owner, 2026-10-19).** A certificate proves that the learner took the course, never that they passed. It is earned when the learner has completed every graded assessment of a course whose certificate is enabled, pass or fail, outside a preview (`CertificateAwards`, after every graded attempt), and issued only to a verified account (identity verification through Didit, `src/Identity/`), in the verified name, with the original completion date. A learner who earned it but is not verified sees "You have completed every assessment. Verify your identity to receive your certificate and transcript." with a Verify button on the course page and the result page; once verified, every certificate waiting is issued. `CertificateIssuer` draws it with the course's design as it is then and, in the same transaction, its transcript with the current transcript template, storing each one's values with its template id, version id and version number, and audits `certificate.issued` or `certificate.reissued`. A later graded attempt reissues both, keeping their numbers, their addresses and who the learner lets see them; a revoked certificate is never reissued. Each address is a random 32-digit hex code (`CertificateAddress`), and the certificate number has random digits of its own. A certificate carries no grade or percentage. If a document cannot be drawn, the issue fails with the reason.
- **An issued certificate never changes.** The certificate page and PDF are drawn again from the stored values and the recorded version, so renaming the learner or the course, editing, replacing or deleting the design, or moving the course to another design leaves them exactly as issued.
- **Learners and verification.** The learner opens the certificate from the course page (View certificate, Download certificate PDF, View transcript) or the account's course history. `/certificates/{public_id}` is its verification page: the certificate's details (number, name, course, completion and issue dates, accreditation, provider) and the certificate PDF shown on the page with a link to download it (`/certificates/{public_id}/certificate.pdf`). `/transcripts/{public_id}` is the transcript's page: every graded assessment's mark, the module average, the final mark and the overall grade (50% module assessments, 50% final), with its PDF. **Who sees them (owner, 2026-10-19):** each is private, seen only by its learner, until the learner makes it public at Account → My Certificates (`/account/certificates`, `CertificateSharing`), for each one separately and at any time; a public one is seen by anyone who has its address. There the learner can also give either one a new address, which ends the old one at once and changes the address printed on its PDF. To anyone else a private one answers not found, ADMIN included. Only the learner is shown the link between the certificate and its transcript; the pages ask not to be indexed, cached or sent as a referrer, and nothing on the site links to them. Viewers with `CERTIFICATE.DESIGN.VIEW` also see the design a certificate was drawn in. ADMIN finds every issued certificate at Courses → Issued Certificates (`CERTIFICATE.ISSUED.VIEW`), opens its PDFs there (never at its address, which ADMIN is not shown) and revokes one with a written reason (`CERTIFICATE.ISSUED.MANAGE`): the certificate and its transcript then say so, with the reason, to anyone who can see them, and have no PDF.

### HTML import mapping

Existing conforming HTML remains valid input. Teaching modules become HTML Course Items and placements. Separately represented graded assessments, diagnostics, finals and review components become their own Course Items. A module assessment is placed as a child of its source HTML module; final assessments remain top-level. The import then arranges the course into module sections (2026-10-28, Module sections and their parts, above), and the import review says so. Author identifiers/titles produce deterministic keys; existing/imported key collisions are reported and block commit pending explicit resolution. Importing a course a second time collides with its own Course Items: when every collision belongs to one course and nothing else uses those items, the review says the course is already on the site and chooses it under Replace existing course shell, which imports the file into it; keys are never renamed to make a second copy. Existing authored media remains embedded. Shortcodes may refer to definitions in the package or an existing Course Item by exact key. Missing references are import errors. Commit is transactional; technical failures do not leave half-created courses.

### Grading scale (owner instruction, 2026/09/25)

The course grade bands are the only definition of a course's grading scale. They set each graded and final assessment's band, the final course grade (fixed 50% module, 50% final) and pass or fail, shown on the transcript. They never decide whether a certificate is issued: completing every assessment does (owner, 2026-10-19). A band stores only where it starts; it runs to just below the next band's start, and the top band ends at 100%. ADMIN edits the bands on the course editor's Grades tab. Diagnostics use their own pass mark instead.

`[grading-scale]` in course or section introductions and HTML lessons is replaced at display time with the current bands, so authored copies of the scale cannot disagree with the grading. The list carries `cl-grading-scale` and the `scale-list` class the original course stylesheets style.

HTML import reads the scale from the file: the shown `ul.scale-list` first, then the `bandFor()`/`gradeBand()` grading function; codes come from that function, otherwise from a trailing "(A)"; bands whose label contains "fail" are not passing. With neither, the standard five bands are used and the import review says so. On the owner's instruction, and as a deliberate exception to preserving authored content, import also removes from the introduction the parts that only worked in the standalone file: the static scale list (replaced by `[grading-scale]`), the `#resetBtn` Reset all saved progress button, and sentences saying progress is saved in the browser. The import review lists each change.

### Structured interchange 2.0 and 2.1

The JSON package uses `format: catto-learning-course`, `schema_version: 2.1` (2.0 is still read), course metadata, `course_items`, `structure`, Resource metadata and assessment banks. Each item contains its exact `item_key`, `item_type`, title/description, source, type configuration, Resource public identifier where relevant and assessment configuration/questions. Each structure row has `node_key`, optional `parent_node_key`, `node_type`, position and relative delay; item rows reference `item_key` and carry placement overrides/public/final settings. Parents precede children. Export includes items reachable through shortcodes, preserving source. Question and option database IDs are excluded so import creates independent identities. Resource files must be registered explicitly on the target instance; export does not duplicate physical files. Existing keys are not silently reused or overwritten when a package also defines them.

Schema 2.1 (2026-10-23) adds the course's `code` to the course metadata and a `catalogue` block: `category` (the path of names joined by ` › `), `tags` (names), `cover` (SVG text), `position`, `related` (other courses' codes), `status` and `prices` (each `period`, `unit`, `price`, `was_price`, `currency`, `label`, `active`, `default`). A 2.1 package may hold a course with no content yet. An import applies the block after the content: the tags (a missing tag is made), the cover and the position, the related courses that are on the site (adding only), and the prices only for a course the import creates. A catalogue package (Administration → Courses → Import & Export Catalogue) is a zip of `catalogue.json` (categories and tags, version 2), one 2.1 package per course in `courses/` and the Resource Library files they use in `resources/<public id>/`; its files keep their public ids, so its courses find them.

### Course package 3.0

A course package 3.0 (`docs/COURSE-PACKAGE.md`, the schema `resources/course-package/package.schema.json`, the worked example `tests/Fixtures/course-package/GDN-101/`) is a zipped folder holding `course.json`, one folder per module (`module.json` with its presentation, video, flash cards and assessment, beside `lesson.html`, `summary.html`, `practical.html` and `practical-answer.html`), and optionally `introduction.html`, `cover.svg`, a review module, `final.json`, `trailer.json` and `diagnostic.json`. Import course accepts it as a `.zip`: `Course\Package\CoursePackage` checks every JSON file against the schema (`Course\Package\JsonSchema`) and the rules the schema cannot, refusing the package with every error named before anything is kept, then turns it into a 2.1 package that goes through the same review, replacement and key checks as any other import. Each module becomes a numbered section of its parts, keys are `<code>-<module>-<part>` in lower case (`gdn-101-m1-lesson`), pictures a lesson names are embedded, and the trailer is placed first in the course as a public preview so a landing page can play it. A trailer (a Presentation of the `trailer` kind) is for visitors: it is never in the outline or reading order of someone who has the course, so Start course never opens on it.

The 2.1 package carries the new parts too: a section's `numbered`, an item's `estimated_minutes`, the item types `slide_deck`, `flashcards` and `practical` with their `type_config`, and for a Presentation its `narrations` (each clip's `public_id`, `slide_id`, `content_type` and `duration_seconds`). A catalogue package holds the clips in `narrations/<public id>.<extension>`, and an import installs each under its slide with a public id of its own.

### Course code

Every course has a code (`courses.code`): capital letters and digits in parts joined by hyphens, 2 to 20 characters, such as `GDN-101`, unique on the site. It is the author's, the same on every site, and every import matches a course by it, never by title or slug. A course HTML file names its code in its head:

```html
<meta name="cattolms:course-code" content="GDN-101">
```

The import review shows the file's code, or suggests one from the title (its main words' initials and the first free number from 101), and offers for replacement the course already on the site with that code. The Details tab shows the code and lets ADMIN change it, with a warning that other sites and catalogue files know the course by it. A course made without one, by a script or a seed, has a random `C-` code.

### Category on import

The HTML format carries no course category. A 2.1 JSON package names one in its `catalogue` block, and the import review preselects it when the site has that category. The review chooses it in the shared category picker (UX rule 4.6): Uncategorised by default, any of the three levels, or a category made there with + New; the commit files the course in it (`category_id`, 0 for none). A catalogue package files each new course in its own category, made by the package's `catalogue.json` when the site lacks it.

### Publication

Drafts may be incomplete. Publication errors include unresolved item references, invalid assessment/final relationships, missing required Resources and schedules unlocking at or after expiry. Less than seven days remaining after unlock is a warning. ADMIN may explicitly override warnings; errors cannot be overridden. Saving a changed duration does not silently rewrite availability.
