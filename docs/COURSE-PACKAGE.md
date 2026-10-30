# CattoLMS Course Specification 3.0

**Course package 3.0** · 28 October 2026 · For anyone writing a CattoLMS course package, by hand or with an AI assistant, or converting an HTML course to one.

**Status:** this is the contract for the package CattoLMS imports at Administration → Courses → Import course. Where this document and the import review disagree, the import review is the final authority.

In this repository:

- this document,
- `resources/course-package/package.schema.json`, the JSON Schema for every JSON file in a package,
- `tests/Fixtures/course-package/GDN-101/`, a complete worked course of one module, written to this specification.

Read the whole document before writing anything. Then read the worked example, which shows every rule applied.

---

## 1. What a course must do

Every person who takes one of these courses should come away impressed, sure they got their money's worth, and knowing more than when they started. A course should give them a skill, or put them on the path to one. Depending on the course, it should help them do their job better or find work with what they learned. And they should want to say so in a review.

That means:

1. **Substance first.** The HTML lesson is the main event in every module. It carries the depth that makes the course worth its price. Everything else in the module helps the learner get into the lesson, remember it and use it.
2. **Each part has one job.** A module has up to seven parts, and each does something the others do not. The presentation does not summarise the lesson, the summary does not repeat the flash cards, and the flash cards do not copy the summary's sentences.
3. **Accurate and current.** Learners notice mistakes, and reviews punish them. Every fact, figure, case, statute and date must be real and checkable (section 5).
4. **Usable.** Practical work asks learners to do what someone in the job does, and shows them a model answer to check their own work against.
5. **Finishable.** Every part states how long it takes. A module should take a learner a week of part-time study, not a month.

## 2. The package at a glance

A course is a folder, zipped for import. The folder and the zip are named after the course code.

```text
GDN-101/
  course.json                    the course: code, titles, summary, level, catalogue, module order
  introduction.html              the course's introduction page
  cover.svg                      optional: the course card picture, 16:10
  styles.css                     optional: styles for this course's lessons
  trailer.json                   optional: the course trailer, a presentation that sells the course
  diagnostic.json                optional: a readiness check before Module 1, never graded
  final.json                     the final assessment
  modules/
    m1/
      module.json                the module: title, introduction, outcomes, times, slides, video, flash cards, questions
      lesson.html                the lesson, the main content
      summary.html               the summary
      practical.html             the practical tasks
      practical-answer.html      a model answer for every task
      pictures/                  optional: pictures this module's files use
    m2/
      ...
    review/                      optional: the review module, before the final assessment
      module.json
      lesson.html
      ...
```

Every file name above is fixed: CattoLMS looks for exactly these names. Folder names under `modules/` are the module keys: `m1`, `m2` and so on in order, and `review`.

CattoLMS builds the course from the package. Each module becomes a section that holds its parts in this order:

| # | Part | Comes from | Required |
| --- | --- | --- | --- |
| | Section: numbered title, introduction, outcomes, outline with times | `module.json` | Yes |
| 1 | Presentation | `module.json` `presentation` | Yes |
| 2 | Video | `module.json` `video` | No |
| 3 | Lesson | `lesson.html` and `module.json` `lesson` | Yes |
| 4 | Summary | `summary.html` and `module.json` `summary` | Yes |
| 5 | Flash cards | `module.json` `flashcards` | Yes |
| 6 | Practical work | `practical.html`, `practical-answer.html` and `module.json` `practical` | Yes |
| 7 | Module assessment | `module.json` `assessment` | Yes |

The site numbers the modules itself ("Module 3: Evidence"), so a title never contains its number. It also makes the keys, the placements and the PDFs, and gathers every module's flash cards into one set for the review. You never write keys, ids or positions.

Learners can download the lesson, the summary, the flash cards and the practical work as PDFs, so these must print well (section 9.4).

## 3. How to work

A long course cannot be written in one answer, and should not be. Work in this order, one step per conversation where the course is long:

1. **Plan.** Read the whole source course, if there is one. Write down the module list with each module's title, outcomes and the questions it will answer. Keep this plan and reuse it in every later conversation so the modules stay consistent with each other.
2. **The course files.** Write `course.json`, `introduction.html` and, if there is one, `cover.svg`.
3. **One module at a time.** For each module, write its `module.json`, `lesson.html`, `summary.html`, `practical.html` and `practical-answer.html`, then check the package so far (section 16). Finish a module, checked, before starting the next.
4. **The review module**, if the course has one (section 11).
5. **The final assessment**, `final.json`, once every module is written, so it can draw on all of them (section 12).
6. **The trailer** and the diagnostic, if the course has them (sections 13 and 14).
7. **Check and zip.** Check the whole folder, fix every error, read every warning, then zip the folder (`GDN-101/` becomes `GDN-101.zip`, with the folder inside it).

Escape HTML correctly inside JSON strings only where JSON holds text. Lessons, summaries and practical work are separate HTML files, never strings inside JSON, so you write them as ordinary HTML.

## 4. House style

These rules apply to every word a learner sees: JSON text, HTML files and slides.

1. **South African English** (`en-ZA`), with British spelling: "organise", "colour", "programme" (a plan), "program" (software).
2. **Plain words.** Short sentences in the active voice, everyday words, one idea per sentence. Define a technical or legal term the first time it appears, and use it the same way afterwards.
3. **Punctuation.** Use hyphens or commas, never em dashes. No semicolons: write two sentences. Use straight quotes (`"` and `'`), never curly ones.
4. **South African context.** Use South African examples, institutions and law where the subject allows. Money is in rand, written as the site writes it: `R1,250` or `R1,250.50`. Dates are written `28 October 2026`.
5. **"Module", never "unit"** or "chapter".
6. **Address the learner as "you".** Never refer to a lecturer, instructor or tutor: the courses are self-study, and nobody marks the learner's work.
7. **No claims the course cannot keep.** Never promise a job, a qualification, accreditation or a pass. Never name another training provider.

## 5. Accuracy

1. **Everything checkable.** Every statute, case, regulation, figure, date and quotation must be real. Give statutes their full name and number (the Consumer Protection Act 68 of 2008) and the section where it matters. Give cases their name and citation as reported.
2. **Never invent.** Do not invent a case, a citation, a statistic, a quotation or a source. When you cannot confirm a fact, leave it out or say plainly that it is an example.
3. **Current.** Law, tax, rates and software change. State facts as they stand on the `content_checked` date in `course.json`, and set that date to the day the content was last checked.
4. **Converting a course.** Keep the substance of the source. Do not shorten a lesson to save effort, and do not drop examples, diagrams or cases. Correct anything you find wrong or out of date, and list each correction in your answer so it can be checked.
5. **Sources.** A lesson that relies on law, regulation or published figures ends with a "Sources" section listing them.

## 6. `course.json`

```json
{
  "format": "cattolms-course",
  "schema_version": "3.0",
  "course": {
    "code": "GDN-101",
    "title": "Growing Vegetables in Small Spaces",
    "subtitle": "Food from a balcony, a stoep or a small yard",
    "summary": "A practical course on growing vegetables in containers and small beds in South Africa...",
    "level": "Beginner",
    "language": "en-ZA",
    "content_checked": "2026-10-28",
    "certificate": {"enabled": true, "accreditation": ""}
  },
  "catalogue": {"category": "Home and Garden › Gardening", "tags": ["Gardening", "Food growing"]},
  "modules": ["m1", "m2", "m3"]
}
```

| Field | Required | Rule |
| --- | --- | --- |
| `format` | Yes | Always `"cattolms-course"`. |
| `schema_version` | Yes | Always `"3.0"`. |
| `course.code` | Yes | The course code: capital letters and digits in parts joined by hyphens, 2 to 20 characters, such as `GDN-101`. The same course always keeps the same code. When converting a course, take the code from its `<meta name="cattolms:course-code">`. |
| `course.title` | Yes | The course's name, without its subtitle. Up to 240 characters. |
| `course.subtitle` | No | One line under the title. Up to 320 characters. |
| `course.summary` | Yes | One plain-text paragraph for the catalogue: who it is for and what they will be able to do. 20 to 1,000 characters. |
| `course.level` | Yes | `"Beginner"`, `"Intermediate"` or `"Advanced"`. |
| `course.language` | No | `"en-ZA"` unless the course is in another language. |
| `course.content_checked` | Yes | The date the content was last checked, `YYYY-MM-DD`. |
| `course.certificate` | No | `enabled` (true unless the course gives no certificate) and `accreditation`, a CPD or accreditation line that is really held, otherwise empty. |
| `catalogue.category` | No | The category path, names joined by ` › `. ADMIN can change it on import. |
| `catalogue.tags` | No | One to three tags. |
| `catalogue.related` | No | Codes of related courses. |
| `modules` | Yes | The module keys in order: `"m1"` to `"mN"`. The review module is not listed here: its folder's presence adds it. |
| `parts` | No | Groups the modules into parts, for a long course (below). |
| `grade_bands` | No | The grading scale (below). Without it, CattoLMS uses its standard five bands. |

**Parts.** A long course may group its modules into parts. Each part becomes a section holding its module sections. Every module appears in exactly one part, in the same order as `modules`:

```json
"parts": [
  {"title": "The legal system", "modules": ["m1", "m2", "m3"]},
  {"title": "Working skills", "modules": ["m4", "m5"]}
]
```

**Grade bands.** Each band has a `code`, a `label`, the percentage it starts `from`, and whether it is `passing`. The bands start at descending percentages and the lowest starts at 0:

```json
"grade_bands": [
  {"code": "A", "label": "First Class (A)", "from": 75, "passing": true},
  {"code": "B", "label": "Second Class (B)", "from": 60, "passing": true},
  {"code": "C", "label": "Third Class (C)", "from": 50, "passing": true},
  {"code": "F", "label": "Fail (F)", "from": 0, "passing": false}
]
```

The course grade is fixed: the module assessments together count 50% and the final assessment 50%.

**What CattoLMS sets, not the package:** prices, the access period, the course's position in the catalogue, publication, which certificate design it uses, and which sections are public. Never put prices in a package.

### 6.1 `introduction.html`

The course's opening page: what the course is about, who it is for, what the learner will be able to do by the end, how a module works, how long the course takes and how it is graded. 300 to 800 words. Write `[grading-scale]` on a line of its own where the grading scale should appear: CattoLMS replaces it with the course's bands.

### 6.2 `cover.svg`

The picture on the course's card and at the top of its page: an SVG drawing in 16:10 proportions (`viewBox="0 0 1600 1000"`), with no text in it (the title is shown over it), no scripts and no external links.

### 6.3 `styles.css`

Optional. CattoLMS styles every element and block in section 9.3 itself, so most courses need no CSS. Use `styles.css` only for something particular to this course, such as a diagram's colours. Its rules apply to this course's lessons only. They must not depend on CSS grid or flexbox for anything that has to print, and must not style anything outside the lesson.

## 7. A module: `module.json`

```json
{
  "key": "m1",
  "title": "Soil, compost and containers",
  "subtitle": "What your plants grow in, and how to make it good",
  "introduction": [
    "Most failed vegetable gardens fail in the soil...",
    "In this module you learn to read your soil, feed it with compost, and choose and fill containers."
  ],
  "outcomes": [
    "Describe what soil is made of and test your own soil's texture with a jar test.",
    "..."
  ],
  "presentation": { "...": "section 8" },
  "video": { "...": "section 9.2" },
  "lesson": {"title": "Soil, compost and containers", "minutes": 60},
  "summary": {"minutes": 5},
  "flashcards": {"cards": [{"front": "...", "back": "..."}]},
  "practical": {"title": "Test, plan and start", "minutes": 90},
  "assessment": {"pass_mark": 50, "questions": [{"q": "...", "o": ["..."], "a": 1, "p": 2, "e": "..."}]}
}
```

| Field | Required | Rule |
| --- | --- | --- |
| `key` | Yes | The folder's name: `m1`, `m2` and so on, or `review`. |
| `kind` | No | `"module"` (the default) or `"review"` for the review module. |
| `title` | Yes | The module's title without a number: "Soil, compost and containers", never "Module 1: Soil...". Up to 160 characters. |
| `subtitle` | No | One line saying what the module covers. |
| `introduction` | Yes | Two or three short paragraphs of plain text, one string each: why this module matters to the learner, and what they will do in it. It opens the module's section, above its outline. |
| `outcomes` | Yes | Three to seven learning outcomes. Each starts with a verb the learner could be checked on ("Explain", "Calculate", "Draft", "Decide"), never "Understand" or "Know". Every outcome is taught in the lesson, practised in the practical work or the flash cards, and tested in the assessment. |
| `presentation` | Yes | The module's opening presentation (section 8). |
| `video` | No | An existing YouTube video that supports the lesson (section 9.2). |
| `lesson` | Yes | `title` (usually the module's title) and `minutes`, the time a learner takes to study it. |
| `summary` | Yes | `minutes`. |
| `flashcards` | Yes | The cards (section 9.5). |
| `practical` | Yes | `title` and `minutes`. |
| `assessment` | Yes | The module assessment (section 9.7). |

**Times.** Give each part's minutes honestly, for a learner new to the subject. The outline shows every part's time and the module's total. CattoLMS estimates the flash cards' and the assessment's times from their size (about 45 seconds a card and 90 seconds a question). A module of 2 to 6 hours of study in all is right.

## 8. The presentation

The presentation opens the module. **Its one job is to get the learner ready for the lesson:** a hook from the subject's real world (a case, a number, a problem), what the module will let them do, one quick question to get them thinking, and what to watch for in the video. It does not summarise the lesson.

Slides are written as data: a layout and its words. CattoLMS draws them and plays them with its presentation engine, CatWalk: the learner moves through the slides on a phone or a computer, points can appear one at a time, and quick questions answer instantly without counting towards any mark. Slides are plain text: no HTML, no Markdown.

```json
"presentation": {
  "title": "What you will learn in this module",
  "kind": "intro",
  "minutes": 3,
  "slides": [
    {"layout": "title", "title": "Welcome", "kicker": "Soil, compost and containers", "heading": "Good food starts below the surface", "sub": "Three things to get right before you plant anything"},
    {"layout": "number", "title": "The small part that matters", "figure": "5%", "label": "of a good garden soil is organic matter. It holds water and feeds the life in the soil."},
    {"layout": "points", "title": "By the end of this module", "heading": "You will be able to", "points": ["Test your soil's texture with a jar", "Make compost that is ready in months", "Choose and fill a container for each crop"]},
    {"layout": "check", "title": "A quick question", "question": "Why does garden soil do badly in a pot?", "options": ["It has too few nutrients", "It packs down and drowns the roots", "It is too acidic"], "answer": 1, "explanation": "Garden soil compacts in a container..."},
    {"layout": "next", "title": "Up next", "heading": "Now watch the video", "text": "Watch for the three things a compost heap needs.", "action_label": "Watch the video"}
  ]
}
```

| Field | Required | Rule |
| --- | --- | --- |
| `title` | Yes | The presentation's name, shown in its top bar and the outline. |
| `kind` | Yes | `"intro"` for a module, `"review"` for the review module, `"trailer"` for `trailer.json`. `"overview"` is a short deck that gives the gist of something, for later use. |
| `minutes` | Yes | Its running time. |
| `theme` | No | `"light"` (the default in modules) or `"dark"` (the default for a trailer). |
| `slides` | Yes | The slides, in order. |

Every slide has a `layout`, a `title` of up to 40 characters (used in the presentation's contents list and read out by screen readers), and may have:

- `chapter`: groups slides under a label in the progress bar.
- `tone`: `"base"`, `"alt"` or `"accent"`, for variety in the background.
- `narration`: what the narrator says on this slide, in plain text. The course owner records the narration in CattoLMS from this script, and it is the slide's transcript too. The voice explains, the slide shows the key words: never write narration that reads the slide aloud word for word.

### 8.1 Layouts

There are twelve layouts. A layout not on this list is refused.

| Layout | Use it for | Fields | Limits |
| --- | --- | --- | --- |
| `title` | Opening the presentation or a chapter | `heading`, `kicker` (optional), `sub` (optional) | Heading up to 10 words |
| `statement` | One big idea or a quotation | `text`, `source` (optional) | Up to 25 words |
| `points` | A short list that builds one point at a time | `heading`, `points`, `ordered` (optional, true for a numbered list), `build` (optional, false shows all at once) | 2 to 5 points, up to 12 words each |
| `compare` | Two things side by side | `heading`, `columns`: exactly two, each with `heading` and either `points` or `text` | Up to 4 points per column |
| `picture` | A diagram, photo or chart | `heading` (optional), `picture` (a file in the module's `pictures/`), `alt`, `caption` (optional) | One picture |
| `number` | One striking figure | `figure`, `label`, `source` (optional) | Figure up to 12 characters |
| `timeline` | Events in order | `heading`, `events`: each `when` and `what` | 3 to 6 events |
| `steps` | A process to follow | `heading`, `steps` | 3 to 6 steps, up to 15 words each |
| `case` | A short scenario | `heading`, `facts`, `question`, `outcome` (shown last, after the learner has thought about it) | Facts up to 60 words |
| `check` | One quick question | `question`, `options`, `answer` (the right option, counting from 0), `explanation` | 2 to 4 options. Never graded |
| `next` | Handing back to the course | `heading`, `text`, `action_label` (optional, such as "Watch the video") | Last slide only |
| `cta` | Asking a visitor to act | `heading`, `text`, `actions`: `"enrol"` and/or `"preview"` | Trailers only, last slide only |

### 8.2 Rules for a good presentation

1. **5 to 9 slides** for a module (`intro`), 8 to 15 for the review, 8 to 12 for a trailer. Never more than 25.
2. **One idea per slide.** If a slide needs two headings, it is two slides.
3. **Few words.** Up to 35 words of visible text per slide, not counting narration. Headings up to 10 words.
4. **Make them think.** A module's presentation has at least one `check` slide. A review has one for every three or four modules it covers.
5. **Set up the video, do not repeat it.** The last slide is a `next` slide that says what to watch for in the video, or, without a video, what to look for in the lesson.
6. **Use the real thing.** Real figures, real cases and real documents from the course, never filler.
7. **Vary the rhythm.** No more than two `points` slides in a row. Mix in a `number`, `statement`, `picture` or `case`.

## 9. The other parts

### 9.1 The section: title, introduction, outcomes

The section opens the module. Its title is the module's title, the site adds the number, and under it come the introduction, the outcomes and the outline of the parts with their times. Sections are not public: ADMIN decides which ones visitors may preview, so the package never makes a section public.

### 9.2 The video

**Its one job is a second way into the topic:** a person explaining it, in a few minutes. It is a small, optional part. The lesson stays the main content, so a module without a good video simply has none.

Videos are existing public YouTube videos by other people, found for the module:

1. **Relevant.** It covers this module's topic, at this course's level, and agrees with the lesson.
2. **Reliable.** It comes from a reputable source: a university, a professional body, a government department, a recognised educator, or an established channel known for accuracy. For law, tax and regulation it must be South African and current.
3. **Short.** 3 to 15 minutes. A longer video may be used from a starting point (`start_seconds`) when only part of it is relevant.
4. **Real and working.** Open the video and confirm it plays, that its owner allows it to be embedded, and that it is not age-restricted or private. Record the date you checked in `checked`. Never guess or invent a video id.
5. **Not an advertisement** for another training provider's course.

```json
"video": {
  "youtube_id": "EXAMPLE0001",
  "title": "How to make compost at home",
  "channel": "The channel's name as YouTube shows it",
  "minutes": 8,
  "watch_for": "Watch for the balance of greens and browns, and how the heap is kept moist.",
  "checked": "2026-10-28"
}
```

`youtube_id` is the 11 characters after `v=` in the video's address. `watch_for` is shown under the video: one or two sentences linking it to the lesson. (The example's id, `EXAMPLE0001`, is a placeholder, and the checker warns about it.)

### 9.3 The lesson: `lesson.html`

**Its one job is to teach the module fully.** It is the substance of the course: a learner who read only the lesson should still be able to meet every outcome. Keep the depth of the source course, typically 3,000 to 8,000 words for a module, with worked examples, diagrams, real cases and documents.

The file is a fragment of HTML: no `<!doctype>`, `<html>`, `<head>` or `<body>`, and no `<h1>` (the page has its own). Start sections with `<h2>`, subsections with `<h3>`, and smaller headings with `<h4>`.

**Use only these elements and blocks.** CattoLMS styles them itself, on screen and in the PDF:

| Purpose | Markup |
| --- | --- |
| Text | `<p>`, `<strong>`, `<em>`, `<a href="https://...">`, `<ul>`, `<ol>`, `<blockquote>`, `<code>` |
| A table | `<table>` with `<caption>`, `<thead>` and `<th>` headings, then `<tbody>` |
| A picture or diagram | `<figure>` holding an inline `<svg role="img" aria-label="...">` or `<img src="pictures/name.png" alt="..." width="..." height="...">`, with a `<figcaption>` |
| A tip | `<aside class="tip"><p>...</p></aside>` |
| A warning | `<aside class="warning"><p>...</p></aside>` |
| A note | `<aside class="note"><p>...</p></aside>` |
| Terms and their definitions | `<dl class="terms"><dt>Term</dt><dd>Definition.</dd></dl>` |
| A worked example | `<details class="example"><summary>Worked example: ...</summary>...</details>` |
| A case | `<div class="case"><h4>Case name and citation</h4><p>Facts...</p><p><strong>Held:</strong> ...</p></div>` |
| A document, such as a letter, form or contract | `<div class="document"><p>...</p></div>` |
| Steps to follow | `<ol class="steps"><li>...</li></ol>` |
| Sources | A last `<h2>Sources</h2>` with a `<ul>` |

**Rules:**

1. **No JavaScript.** No `<script>` and no `on...` attributes. Everything must work and print without it. Worked examples and anything a learner opens are `<details>` blocks. They print opened.
2. **No `style="..."` attributes** and no `<style>` elements. Put any CSS the course needs in `styles.css`.
3. **Diagrams as inline SVG** where you can, drawn simply: shapes, lines, paths and text. Avoid filters, `foreignObject`, scripts and animation, which do not print. Give each SVG a `viewBox`, and give ids inside it names unique in the lesson.
4. **Pictures** are PNG or JPEG files in the module's `pictures/` folder, referenced as `pictures/name.png`, with `alt`, `width` and `height`.
5. **Links** go to other websites only, with full addresses, and open in a new tab.
6. **Ids** are unique in the file and never start with `cl-` or `cw-`.

### 9.4 What prints

The lesson, the summary, the flash cards and the practical work are offered as PDFs. The PDF engine draws headings, paragraphs, lists, tables, pictures and simple SVG well. It does not draw CSS grid or flexbox layouts, or anything that needs JavaScript. A part written to section 9.3 prints correctly. The import review warns when something will not.

### 9.5 The summary: `summary.html`

**Its one job is to pull the module together in one screen.** 6 to 10 points in a `<ul>`, up to 300 words, each a complete sentence stating something the learner should now know or be able to do. No new material. Same element rules as the lesson.

### 9.6 Flash cards

**Their one job is recall.** The learner reads the front, answers in their head, turns the card over and says "I knew it" or "Again". The cards they miss come back. Recall practised like this, spread over days, is one of the best-supported ways of remembering anything.

```json
"flashcards": {
  "cards": [
    {"front": "Which soil particle is the smallest?", "back": "Clay. Its particles are smaller than 0.002 mm, which is why clay holds water and nutrients but drains slowly."},
    {"front": "What does a pH of 7 mean?", "back": "Neutral: neither acidic nor alkaline.", "hint": "The middle of the scale."}
  ]
}
```

1. **10 to 20 cards** per module.
2. **The front asks.** A question, or a term to define, never a statement. Up to 200 characters.
3. **The back answers briefly.** The answer first, then at most one sentence of why. Up to 400 characters.
4. **`hint`** is optional: a nudge, never the answer.
5. **What deserves a card:** definitions, distinctions, the steps of a procedure, key figures and rules, and the "which applies here?" judgements the job needs. Not trivia.
6. **Plain text.** The cards print as a list in the PDF and are gathered into one set for the review.

### 9.7 Practical work: `practical.html` and `practical-answer.html`

**Its one job is to put the lesson to use:** tasks that someone doing this for real would do, producing something the learner keeps.

`practical.html` opens with a short paragraph saying what the tasks are for and what the learner needs. Then 2 to 5 tasks, each one a block:

```html
<section class="task">
  <h3>Task 1: Do a jar test on your soil</h3>
  <p>What to do, step by step...</p>
  <p class="produce"><strong>What you produce:</strong> a labelled sketch of the jar with the depth of each layer.</p>
  <p class="time"><strong>Time:</strong> 20 minutes, then a day for the jar to settle.</p>
</section>
```

`practical-answer.html` has **one model answer for every task**, in the same order, each headed exactly like its task:

```html
<section class="answer">
  <h3>Task 1: Do a jar test on your soil</h3>
  <p>A model answer, or a checklist the learner checks their own work against...</p>
</section>
```

1. **Realistic.** Use South African documents, prices, places and situations. A bookkeeper reconciles a real-looking bank statement, a shop owner prices a real stock list, a gardener plans a real balcony.
2. **Checkable alone.** There is no tutor. The model answer must let the learner judge their own work: a worked solution, a model document, or a checklist of what a good answer contains, with the reasons.
3. **The answer stays hidden until the learner chooses to see it,** and prints on its own pages at the end of the PDF. Never put the answer in `practical.html`.

### 9.8 The module assessment

**Its one job is to show whether the learner can use what the module taught.** It counts towards the course grade.

```json
"assessment": {
  "pass_mark": 50,
  "questions": [
    {
      "q": "Your tomato seedlings in a pot are yellowing, and the potting mix stays wet for days after watering. What do you check first?",
      "o": ["Whether the pot has drainage holes that are not blocked", "Whether the plant needs more fertiliser", "Whether the pot is in full sun", "Whether the seed was fresh"],
      "a": 0,
      "p": 2,
      "e": "Mix that stays wet points to poor drainage. Roots without air turn yellow and rot, whatever the fertiliser or sun."
    }
  ]
}
```

| Field | Required | Rule |
| --- | --- | --- |
| `pass_mark` | No | The percentage to pass, 50 by default. |
| `questions` | Yes | 15 to 30 questions. |
| `q` | Yes | The question, plain text. |
| `o` | Yes | 2 to 6 options, all different, usually four. |
| `a` | Yes | The right option, counting from 0. |
| `p` | No | Points and difficulty: 1 introductory, 2 standard, 3 advanced. |
| `e` | Yes | Why the right answer is right, and where useful why a tempting wrong one is wrong. Shown after the learner answers. |

1. **Mostly scenarios.** At least half the questions put the learner in a situation and ask what they would do or decide. Recall questions are for the flash cards.
2. **Three tiers.** Mix introductory, standard and advanced questions, most of them standard.
3. **Plausible options.** Every wrong option is a mistake a learner could really make. Never "all of the above", "none of the above" or a joke option. Options of similar length, so the longest is not always right. Put the right answer in different positions.
4. **One right answer**, beyond argument, and found in the lesson.
5. **Every outcome tested** by at least two questions.

## 10. Pictures in a module

Pictures a module uses go in its own `pictures/` folder: PNG or JPEG for photographs, SVG for drawings. A lesson refers to `pictures/name.png`, and a `picture` slide to `"picture": "name.svg"`. Name files in lower case with hyphens. Keep each picture under 1 MB.

## 11. The review module

A course of six modules or more should end with a review module before the final assessment: `modules/review/`, with `"key": "review"` and `"kind": "review"` in its `module.json`. It is not numbered.

Its parts:

| Part | Required | Rule |
| --- | --- | --- |
| `introduction`, `outcomes` | Yes | What the review is for: bringing the course together before the final. |
| `presentation` | Yes | Kind `"review"`, 8 to 15 slides, with a `check` slide for every three or four modules covered. |
| `lesson.html` | Yes | How the modules connect: the big picture, the common mistakes, and how the parts of the course work together in practice. Not a repeat of each module. |
| `summary.html` | No | The course in one screen. |
| Flash cards | No | CattoLMS gathers every module's cards for the review, so leave `flashcards` out. |
| `practical.html` and `practical-answer.html` | No | A capstone task that uses several modules together, with its model answer. Recommended. |
| `assessment` | No | A graded review assessment, counted with the module assessments. |
| `video` | No | As in a module. |

## 12. The final assessment: `final.json`

```json
{
  "pass_mark": 50,
  "questions": [
    {"q": "...", "o": ["...", "...", "...", "..."], "a": 2, "p": 2, "e": "...", "m": "m3"}
  ]
}
```

1. **40 to 60 questions** across the whole course, at least four per module, in proportion to each module's weight. A short course may have fewer, but never fewer than 10.
2. **`m`** names the module a question tests, such as `"m3"`.
3. **New questions.** Never copy a module assessment's questions. Test the same outcomes with new scenarios, and include questions that need two modules together.
4. Every other rule of section 9.8 applies.
5. **`ask`** (optional): how many questions each attempt asks, drawn from the bank. Without it, every question is asked.

## 13. The trailer: `trailer.json`

Optional, and recommended: a presentation of kind `"trailer"` that sells the course on its landing page. The same fields as a module presentation (section 8), 8 to 12 slides, ending with a `cta` slide. Show real content from the course and real outcomes. Make no claim about jobs, accreditation or results that the course does not deliver, and name no other provider.

## 14. The diagnostic: `diagnostic.json`

Optional: a readiness check before Module 1, never graded. It tells learners which modules to study most carefully.

```json
{
  "pass_mark": 50,
  "questions": [
    {"q": "...", "o": ["...", "...", "...", "..."], "a": 1, "e": "...", "u": ["m2", "m3"]}
  ]
}
```

`u` lists the modules to study if the learner gets the question wrong. 8 to 20 questions.

## 15. Converting an existing HTML course

A course written for an earlier version of CattoLMS is a single HTML file. Most of what a package needs is already in them, in these places:

| In the HTML course | Goes to |
| --- | --- |
| `<meta name="cattolms:course-code" content="...">` | `course.code` |
| `.masthead h1`, `.course-subtitle`, `.course-summary`, `data-level` | `course.title`, `course.subtitle`, `course.summary`, `course.level` |
| The introduction above the modules | `introduction.html` |
| The grading list `ul.scale-list` or the `bandFor()` function | `grade_bands` |
| Each `section.module` (`id="mod-m1"` and so on) | `modules/m1/` and so on |
| `.m-name` and the `.m-titles small` line | `title` (without the "Module 01" eyebrow) and `subtitle` |
| `.outcomes` | `outcomes` |
| `.video` (the iframe's `embed/<id>`, the `.video-cap` text) | `video`: the id, the title and channel from the caption, `watch_for` from its "Watch for" sentence. Check that it still plays |
| `.module-body`, outside `.outcomes`, `.video` and `.subs` | `lesson.html`, with its classes changed to section 9.3's blocks |
| `.s-summary .sub-inner` | `summary.html` |
| `.block.review-aid` (Review Study Aid: definitions, distinctions) | The flash cards, rewritten as questions and answers. Anything in it worth reading as text goes into the summary or the lesson |
| `.block.practical` (each `li` with `.ex-t`, `.ex-out`) | `practical.html`, one `section.task` per task, `.ex-out` becoming "What you produce" |
| `.block.answers` (Practical Stock Answers) | `practical-answer.html`, one `section.answer` per task |
| `QUIZ.m1` and so on | `assessment.questions` (the same `q`, `o`, `a`, `p` and `e` fields, and every question needs an `e`) |
| `QUIZ.review` and `section.review` | The review module |
| `QUIZ.final` | `final.json` (add `m` to each question) |
| `QUIZ.diag` and `DIAG_PASS` | `diagnostic.json` and its `pass_mark` |
| Inline `<svg>` diagrams | Kept as they are in the lesson, inside a `<figure>`, simplified where they would not print |

**What is new and must be written:** each module's presentation, the flash cards where the course has no review aid, practical work and model answers where it has none, the explanations missing from questions, the times, and the trailer.

**What is dropped:** the standalone page's shell: its accordions, buttons, progress saving, navigation and scripts. CattoLMS supplies all of that.

## 16. Checking a package

CattoLMS checks a package when it is uploaded at Import course, before anything is kept: every JSON file against `resources/course-package/package.schema.json` (`Course\Package\JsonSchema`), then the rules the schema cannot check (`Course\Package\CoursePackage`): the files a module needs, answers that point at real options, slide rules, counts, matching tasks and answers, and pictures that exist. **Errors** refuse the package, each one named. **Warnings** are this specification's guidance and appear on the import review: fix them, or be sure the course is right as it is.

Check each rule in the checklist below before uploading.

## 17. Checklist

Course:

- [ ] `course.json` has the code, title, summary, level, `content_checked` date and the module list.
- [ ] `introduction.html` says what the course is, who it is for, how a module works and how it is graded, with `[grading-scale]`.
- [ ] `final.json` has at least 10 questions, normally 40 to 60, each with its module in `m`.
- [ ] No prices, no lecturers, no promises of jobs or accreditation.

Every module:

- [ ] The folder name, `key` and the `modules` list agree.
- [ ] The title has no number. The introduction says why the module matters.
- [ ] Three to seven outcomes, each starting with a checkable verb.
- [ ] A presentation of 5 to 9 slides with at least one `check` and a `next` slide last.
- [ ] A video only if it is relevant, reliable, short and confirmed to play.
- [ ] A lesson with the full substance, using only section 9.3's elements, no scripts and no styles.
- [ ] A one-screen summary.
- [ ] 10 to 20 flash cards that ask and answer.
- [ ] 2 to 5 realistic tasks, each with a model answer under the same heading.
- [ ] 15 to 30 assessment questions, mostly scenarios, each with an explanation.
- [ ] Every part's minutes, and a total of 2 to 6 hours.
- [ ] Every fact real, current and checkable. No invented cases, figures or videos.
- [ ] The import review reports no errors.

## Appendix: every slide layout

```json
{"layout": "title", "title": "Welcome", "kicker": "Module subject", "heading": "A heading of up to ten words", "sub": "A line under it"}
{"layout": "statement", "title": "The big idea", "text": "One idea or quotation of up to twenty-five words.", "source": "Who said it, where"}
{"layout": "points", "title": "Three things", "heading": "What to remember", "points": ["First point", "Second point", "Third point"], "ordered": false, "build": true}
{"layout": "compare", "title": "Greens and browns", "heading": "Two kinds of compost material", "columns": [{"heading": "Greens", "points": ["Vegetable peels", "Fresh grass cuttings"]}, {"heading": "Browns", "text": "Dry leaves, straw and shredded cardboard."}]}
{"layout": "picture", "title": "The jar test", "heading": "What the layers tell you", "picture": "jar-test.svg", "alt": "A jar of settled soil in three layers: sand at the bottom, silt, then clay", "caption": "Sand settles first, clay last."}
{"layout": "number", "title": "Organic matter", "figure": "5%", "label": "of a good garden soil is organic matter.", "source": "A typical figure for loam"}
{"layout": "timeline", "title": "A compost heap", "heading": "From scraps to compost", "events": [{"when": "Week 1", "what": "The heap heats up"}, {"when": "Weeks 2 to 6", "what": "Turned every week or two"}, {"when": "Months 2 to 6", "what": "Dark, crumbly and ready"}]}
{"layout": "steps", "title": "Filling a container", "heading": "Fill a container in four steps", "steps": ["Check the drainage holes", "Fill with potting mix", "Water until it drains", "Plant and mulch"]}
{"layout": "case", "title": "Thandi's balcony", "heading": "Why are the seedlings dying?", "facts": "Thandi filled two pots with soil from a building site...", "question": "What went wrong?", "outcome": "Building-site soil is compacted subsoil..."}
{"layout": "check", "title": "Quick question", "question": "Which of these belongs with the browns?", "options": ["Coffee grounds", "Dry leaves", "Fresh grass cuttings"], "answer": 1, "explanation": "Dry leaves are rich in carbon, a brown. Coffee grounds and fresh grass are greens."}
{"layout": "next", "title": "Up next", "heading": "Now watch the video", "text": "Watch for how the heap is kept as damp as a wrung-out sponge.", "action_label": "Watch the video"}
{"layout": "cta", "title": "Start growing", "heading": "Grow your first crop this season", "text": "Every module ends with something you do in your own space.", "actions": ["enrol", "preview"]}
```
