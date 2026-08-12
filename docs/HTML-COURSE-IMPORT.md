# HTML course import markers

## Course metadata

Every new course must provide title, subtitle and summary explicitly in the masthead:

```html
<header class="masthead" data-level="Intermediate">
  <h1>Course Title</h1>
  <p class="course-subtitle">Course Subtitle</p>
  <p class="course-summary">One-paragraph course summary.</p>
</header>
```

The importer maps these fields directly:

```text
.masthead @data-level      -> courses.level
.masthead h1               -> courses.title
.masthead .course-subtitle -> courses.subtitle
.masthead .course-summary  -> courses.summary
```

Do not combine title and subtitle inside the `h1`. New courses must set `data-level` explicitly on `.masthead`; the importer never scans teaching content for words such as beginner/intermediate/advanced.

Catto Learning treats teaching modules, review modules, diagnostics and the final assessment as different objects.

## Standard module with assessment

```html
<section class="module acc-item" id="mod-m1" data-assessment-required="true">
  ...course content...
  <div class="quiz" data-quiz="m1"></div>
</section>
```

`QUIZ.m1` supplies the assessment questions. `data-assessment-required="true"` is the default and may be omitted.

## Standard module without assessment

```html
<section class="module acc-item" id="mod-m2" data-assessment-required="false">
  ...course content...
</section>
```

Do not provide a `QUIZ.m2` question bank unless you intentionally want an optional/non-required assessment attached to the module.

## Review module with assessment

```html
<section class="review acc-item" id="mod-review" data-assessment-required="true">
  ...review content...
  <div class="quiz" data-quiz="review"></div>
</section>
```

`QUIZ.review` supplies the review assessment questions.

## Review module without assessment

```html
<section class="review acc-item" id="mod-review" data-assessment-required="false">
  ...review content...
</section>
```

`data-assessment-required="false"` is also the default for `mod-review`, but writing it explicitly is preferred in generated course HTML.

## Final assessment

```html
<section class="final acc-item" id="final">
  <div class="quiz" data-quiz="final"></div>
</section>
```

`QUIZ.final` supplies the final-assessment questions. The final assessment is course-level and is not imported as a teaching module.

## Diagnostic assessment

```html
<section class="diag acc-item" id="diagnostic">
  ...optional diagnostic introduction/result UI...
  <div class="quiz" data-quiz="diag"></div>
</section>
```

`QUIZ.diag` supplies the diagnostic questions. Diagnostics are course-level, do not contribute to the grade, and are not imported as teaching modules. The importer also recognises the question-bank keys `diagnostic`, `prereq`, `prerequisite`, and `readiness`.

## LMS course category assignment

Course categories are LMS catalogue metadata rather than mandatory canonical HTML metadata. During import, select an existing active category or, as a Platform Administrator, use **+ New** beside the Category selector to create and select a new category without leaving the import preview. Full taxonomy management is available at **Administration → Courses → Categories**.
