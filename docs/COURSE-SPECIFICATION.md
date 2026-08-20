# Catto Learning HTML Course Specification

**Target LMS:** 0.5.7.5  
**Status:** Canonical specification for the currently implemented HTML course authoring/import workflow.

## 1. Purpose

New HTML courses intended for Catto Learning must use one predictable static structure. The importer parses HTML and assessment data; it does **not** execute course JavaScript during import.

Important content must therefore exist as real HTML. Assessment banks must exist in the `QUIZ` object. JavaScript may enhance the standalone course but must not be the sole source of teaching content.

This document describes the current HTML importer. A future portable Catto Learning course-package format is roadmap work and does not replace this specification until implemented.

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

The importer does not execute standalone JavaScript.

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

Course `<style>` blocks are imported and scoped under the LMS course-presentation wrapper. Approved Google Fonts stylesheet links may also be preserved.

Course CSS must style course content only and must not depend on overriding the LMS shell/navigation or structural content-card container.

Prefer native `<details>/<summary>` for collapsible lesson content that must survive import.

## 15. Markup safety

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
7. verify importer preview metadata, module order, outcomes, summaries, assessment flags/counts, review, diagnostic/remediation, final assessment and detected presentation CSS.

**The Catto Learning importer preview is the final conformance authority.**