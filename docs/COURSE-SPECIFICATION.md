# CattoLMS Course Specification 4.4

**Version 4.4** · 1 November 2026, 20:41 SAST · Authored by Claude Opus 5.5 · Replaces version 4.3 (1 November 2026, 19:14 SAST), version 4.2 (1 November 2026, 18:36 SAST), version 4.1 (1 November 2026, 16:15 SAST), version 4.0 (1 November 2026, 16:03 SAST) and version 3.0 (the course package, 28 October 2026): a course is one HTML file.

**New in 4.4:** Module 0, for a course whose first module only sets the learner up (section 8). The review study aid a module may end with (section 8.6). The review's presentation and self assessment as panels of their own (section 10). CattoLMS writes every course on the site out in this form, and importing such a file gives the course back.

**New in 4.3:** one way to make a panel that opens and closes, everywhere in the file: a `<details>` element with its `<summary>` (section 4). It works without a script, and on the site too, where the course's own script never runs.

**New in 4.2:** the file is built as the original Catto Learning course files are. Every module, and inside it every part (the presentation, the summary, the flash cards, the practical work, the model answers, the self assessment and its answer key), the readiness check, the review and the final assessment, is a panel of its own that opens and closes, all closed when the file opens (sections 4, 8, 10, 11 and the example in section 14). The readiness check goes between the introduction and the modules, and the review after them.

**New in 4.1:** a module is a week of study, about 15 hours (section 8). Every module has a video, found and checked online (section 8.2). Every lesson is illustrated with SVG drawings (section 8.3). How to show code in a lesson (section 8.3) and in questions (section 9.1), and why slides and flash cards cannot hold it (sections 9.2 and 9.3).

**For whoever writes a CattoLMS course, by hand or with an AI assistant such as Claude.** This one document is everything needed: give it, whole, to the assistant. Section 14 is a complete working course to copy from.

## 1. What you are making

**A course is one HTML file.** The same file does two jobs:

1. **On its own**, opened in a browser, it is the whole course: every module with its presentation, video, lesson, summary, flash cards, practical work and quiz, working without a server.
2. **Uploaded to CattoLMS** (Administration › Courses › Import HTML), it becomes the course on the site.

CattoLMS does not run the file. It reads these things from it and draws everything else itself:

| CattoLMS takes | From |
| --- | --- |
| The course's code, title, subtitle, summary, level and cover | The head and the masthead (section 6) |
| The course introduction and its grading scale | The elements between the masthead and the modules (section 7) |
| Each module and its parts | Each module's `.module-body` (section 8) |
| The presentations, flash cards and questions | Four data declarations in a script (section 9) |
| Your stylesheet | The `<style>` element, confined to the course content (section 5) |

**Everything else is the frame**, for the file on its own, and CattoLMS ignores it: the page wrapper, the panels that open and close (every `<details>` and `<summary>` around a module, a part, the readiness check, the review or the final assessment), `.module-body` itself, the outcomes' label, the `.subs` blocks and everything in them except the summary's `.sub-inner`, the sections of the readiness check and the final assessment, and your script. The site has its own frame.

**Set on the site, not in the file:** the course's category, tags, prices, page layout, certificate and place in the catalogue are chosen in Administration after import.

Each module becomes a numbered section on the site ("Module 1: Soil, compost and containers") holding its parts in this order:

| # | Part | Written in the file as | Required |
| --- | --- | --- | --- |
| | Section: title, subtitle and outcomes | `.m-name`, `.m-titles small`, `.outcomes` | Yes |
| 1 | Presentation | `PRESENTATIONS.m1` | Yes |
| 2 | Video | `.video` | Yes |
| 3 | Lesson | everything else in `.module-body` | Yes |
| 4 | Summary | `.s-summary .sub-inner` | Yes |
| 5 | Flash cards | `FLASHCARDS.m1` | Yes |
| 6 | Practical work | `.practical` and `.answers` | Yes |
| 7 | Assessment | `QUIZ.m1` | Yes |

After the modules come an optional review module (section 10) and the final assessment (section 11).

## 2. What a good course is

Every person who takes the course should come away knowing more, sure they got their money's worth, and wanting to say so in a review.

1. **Substance first.** The lesson is the main event of every module, typically 3,000 to 8,000 words with worked examples, SVG drawings, real cases and documents. The other parts help the learner into the lesson, remember it and use it.
2. **Each part has one job.** The presentation gets the learner ready for the lesson. The video gives a second way into the topic. The summary pulls the module together. The flash cards drill recall. The practical work puts the lesson to use. The assessment shows whether they can use it. No part repeats another.
3. **Accurate and current.** Every statute, case, figure, date and quotation must be real and checkable. Never invent one. When you cannot confirm a fact, leave it out or say plainly that it is an example. A lesson that relies on law or published figures ends with its sources (section 8.3).
4. **Precise.** State a rule exactly, with where it comes from: "The Companies Act 71 of 2008 (section 24) requires a company to keep its accounting records for seven years", never "records are kept for about five years". Courses in a series state the facts they share the same way, so one course never contradicts another.
5. **Usable.** Practical work asks the learner to do what someone in the job does, with a model answer to check their own work against.
6. **Self-study.** Nobody marks a learner's work and nothing is submitted: the learner checks their own work against the model answers. Never write "submit", "submission", "hand in", "marked by" or "assessed against a rubric" about the learner's work. Work the learner does is what they complete, and a checklist of it is a checklist, not a submission.
7. **A week a module.** Each module is one week of part-time study, about 15 hours of the learner's time (section 8). Fill the week with work worth doing, never with padding.

**House style**, for every word a learner sees, including slides, cards and questions:

1. South African English with British spelling ("organise", "colour"). `lang="en-ZA"`.
2. Plain words, short sentences, one idea per sentence. Define a technical term the first time it appears.
3. Hyphens or commas, never em dashes. No semicolons: write two sentences. Straight quotes only.
4. South African examples, institutions and law where the subject allows. Money as `R1,250` or `R1,250.50`. Dates as `28 October 2026`.
5. "Module", never "unit" or "chapter". Address the learner as "you". Never mention a lecturer, instructor or tutor: the courses are self-study.
6. Never promise a job, a qualification or a pass, and never name another training provider.

## 3. How to work

1. **Plan.** List the modules with each one's title, outcomes and the questions it answers. Keep the plan and follow it, so the modules fit together.
2. **Build the frame first:** the head with the whole stylesheet, the masthead, the introduction, the script for the file on its own (section 12), and the data declarations, empty.
3. **One module at a time.** Write its HTML and add its entries to `QUIZ`, `PRESENTATIONS` and `FLASHCARDS`. Open the file in a browser and check the module works before starting the next. A long course can be written over several turns, adding a module to the same file each time.
4. **The final assessment last**, so it can draw on every module.
5. **Check** against section 13, then hand over the one file.

## 4. The file at a glance

```html
<!doctype html>
<html lang="en-ZA">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="cattolms:course-code" content="GDN-101">
  <title>Course title</title>
  <style>/* section 5: styles for the frame and for every class the content uses */</style>
</head>
<body>
<div class="wrap">
  <header class="masthead" data-level="Beginner">...</header>                     <!-- section 6 -->
  <section class="intro">...</section>                                             <!-- section 7 -->
  <section class="diag" id="diagnostic">...</section>                    <!-- section 11, optional -->
  <div class="modules">
    <section class="module" id="mod-m1" data-assessment-required="true">...</section>   <!-- section 8 -->
    <section class="module" id="mod-m2" data-assessment-required="true">...</section>
  </div>
  <section class="review" id="mod-review" data-assessment-required="false">...</section>   <!-- section 10, optional -->
  <section class="final" id="final">...</section>                        <!-- section 11 -->
  <footer>...</footer>
</div>
<script>
const QUIZ = {...};            /* section 9 */
const PRESENTATIONS = {...};
const FLASHCARDS = {...};
const REVIEW = {};
</script>
<script>/* section 12: makes the file work on its own */</script>
</body>
</html>
```

**Every part is a panel that opens and closes.** A course file is long, so it is built as the original Catto Learning course files are: every module is a panel, and inside it every part is a panel of its own, in this order: the presentation, then the module's own content (the video, the outcomes and the lesson), then the summary, the flash cards, the practical work, the model answers, the self assessment and its answer key. The readiness check, the review and the final assessment are panels too. Every panel is closed when the file opens, and the learner opens the one they want by its heading.

**A panel is always a `<details>` element**, and there is no other way to make one. Its `<summary>` is the heading the learner selects, and everything after the `<summary>` is what it opens. Write no `open` attribute: every panel starts closed. A `<details>` opens and closes by itself, so it needs no script, and it works on the site too, where your script never runs: a panel left in a lesson by mistake still opens there. Never build a panel any other way, from a button and a script or from anything else.

There are three kinds, each built exactly as shown in the sections named:

| Panel | Built as | Shown in |
| --- | --- | --- |
| The readiness check, a module, the review, the final assessment | `<section class="module" id="mod-m1">` (or `diag`, `review`, `final`) holding `<details class="panel">`, whose `<summary class="panel-head">` shows the number, the titles, the mark and an arrow | sections 8, 10 and 11 |
| A part inside a module or the review: the presentation, summary, flash cards, self assessment and answer key | `<details class="sub s-present">` (and `s-summary`, `s-cards`, `s-assess`, `s-answers`) inside a `.subs` block, its `<summary>` holding the part's name and a line about it | section 8 |
| The practical work, the model answers and a review study aid | `<details class="block practical">`, `<details class="block answers">` and `<details class="block review-aid">` inside `<div class="blocks">` | sections 8.5 and 8.6 |

Section 8 shows a whole module and section 14 a whole course built this way. Never leave a part outside its panel, and never put a panel's content anywhere but where these sections show it.

**Reserved class names.** These names tell CattoLMS what something is. Use each only as this document describes, and never for anything else: `masthead`, `course-cover`, `course-subtitle`, `course-summary`, `modules`, `module`, `m-titles`, `m-name`, `module-body`, `outcomes`, `video`, `video-cap`, `subs`, `s-summary`, `sub-inner`, `practical`, `answers`, `block-inner`, `review`, `review-inner`, `review-aid`, `final`, `diag`, `scale-list`. The panels' classes (`panel`, `panel-head`, `sub`, `s-present`, `s-cards`, `s-assess`, `s-answers`, `block`, `blocks`) are used exactly as sections 4 and 8 to 11 show. Every other class name is yours to choose.

### 4.1 The names are fixed

**CattoLMS finds each part by the exact name this document gives it, and by no other.** A part under any other name is not found: it stays in the lesson as ordinary text, and the course loses that part. So never invent a new name for a part, never use a synonym, and never wrap a part in markup of your own instead of the markup shown. If something does not fit a part described here, it belongs in the lesson.

| Part | Write exactly | Never write instead (CattoLMS does not find these) |
| --- | --- | --- |
| Practical tasks | `<details class="block practical">` in `<div class="blocks">`, with `<div class="block-inner">`, each task a `<section class="task">` (section 8.5) | a block called `workshop`, `exercises`, `activity`, `lab` or `practice`, or a plain block headed "Practical Exercises" or "Workshop" |
| Model answers | `<details class="block answers">` after the practical work, with `<div class="block-inner">`, each answer a `<section class="answer">` headed exactly like its task | "stock answers" or "solutions" under another class, or a worked solution hidden inside each task |
| Summary | the panel `<details class="sub s-summary">` holding `<div class="sub-inner">`, inside a `.subs` block (section 8.4) | a section headed "Summary", "Recap" or "Key points" in the lesson |
| Outcomes | `<div class="outcomes">` holding a label and an `<ol>` (section 8.1) | "Objectives", "Aims" or "What you will learn" under another class |
| Video | `<div class="video">` holding a YouTube `<iframe>` (section 8.2) | a link to the video, or an iframe outside `.video` |
| Module | `<section class="module" id="mod-m1">` holding its panel, with `.m-name` and `.module-body` (section 8) | `unit`, `chapter`, `week` or `lesson` ids or classes |
| Questions | `QUIZ`, `PRESENTATIONS`, `FLASHCARDS`, `REVIEW` (section 9) | other variable names, or questions written only in the HTML |

Use the parts' own words in the panel headings the learner sees too: "Presentation", "Summary", "Flash cards", "Practical work", "Model answers", "Self Assessment" and "Answer key".

The import review warns about each of these it finds: a block under one of the names in the right-hand column, a solution hidden inside a task, practical tasks without their answers, wording about submitting work or having it marked, and grading weights other than 50% and 50%. A file with any of these warnings is not finished: fix it and import it again.

## 5. The stylesheet

**Write one `<style>` element in the head. It styles everything: the frame and the course content.** CattoLMS does not style your content. Apart from plain default text, a lesson on the site looks exactly as your stylesheet makes it, and a block whose class has no rule is plain text.

When the file is imported, CattoLMS keeps your stylesheet and confines it to the course content. It puts `.cl-course-presentation` in front of every selector, so `p { ... }` becomes a rule for paragraphs in the course only, and rules for `html`, `body` and `:root` apply to the box the course content sits in. The course therefore looks the same on the site as in the file, and its styles never change the site around it. Rules inside `@media` are confined too. `@import` (for Google Fonts) and `@font-face` are kept as written.

Rules:

1. **Give every kind of content block a class, and style every class you use.** Tips, warnings, cases, worked examples, documents, definitions, diagrams, tables: each gets a class and a rule. Use the same class for the same kind of block in every module.
2. **Style elements too.** Rules for `h2`, `p`, `table`, `th` and so on are confined to the course, so style them freely.
3. **Never style content through the frame.** The frame (section 1) is not imported, so a selector that names a frame element above the content, such as `.module-body p`, `#mod-m1 table`, `.page .tip` or `.practical .task`, matches nothing on the site. Write `p`, `table`, `.tip` and `.task`. Styling the frame itself, such as `.masthead h1`, `.panel-head`, `.sub > summary`, `.sub-label` or `.outcomes .eyebrow`, is fine: it is for the file alone.
4. **Put colours and fonts in variables on `:root`**, and the base font and colour on `body`. Both reach the course box.
5. **Style your script's buttons and widgets by their own classes**, such as `.btn`, `.slide` and `.card`, never by bare `button`, `input`, `label`, `fieldset`, `legend`, `select` or `form` rules. On the site the flash cards are CattoLMS's own, inside the course box, and a bare `button` rule would restyle their buttons too. (The presentation player keeps its own design whatever your stylesheet says.)
6. **Design for a light page.** The site shows courses on light pages. No dark mode is needed.
7. **Make it readable without the stylesheet.** The lesson, summary, flash cards and practical work are also offered as PDFs, drawn in CattoLMS's own plain print style. Never carry meaning by colour or layout alone: a warning box starts with the word "Warning".

## 6. The head and the masthead

In the head:

- `<meta name="cattolms:course-code" content="GDN-101">`: the course code. Capital letters and digits joined by hyphens. CattoLMS knows a course by its code, so importing the file again offers to replace the course with the same code.
- `<title>`: the course title.

The masthead:

```html
<header class="masthead" data-level="Beginner">
  <div class="course-cover"><svg viewBox="0 0 1600 1000" ...>...</svg></div>
  <h1>Growing Vegetables in Small Spaces</h1>
  <p class="course-subtitle">Food from a balcony, a stoep or a small yard</p>
  <p class="course-summary">One paragraph saying what the course is and who it is for.</p>
</header>
```

| Item | Rule |
| --- | --- |
| `data-level` | `Beginner`, `Intermediate` or `Advanced`. |
| `h1` | The title alone. Never add the subtitle to it with a dash, a colon or a `<br>`. |
| `.course-subtitle` | One line. |
| `.course-summary` | One paragraph, up to about 60 words: shown on the course's card and page. |
| `.course-cover` | Optional and recommended: one SVG drawing in 16:10 proportions (`viewBox="0 0 1600 1000"`), with no visible text in it (the site shows the title over it, and an `aria-label` describing the drawing is welcome), no scripts, under 200 KB. It becomes the picture on the course's card and page. One that breaks these rules is left out, and the import review says why. |

## 7. The introduction

Everything between the masthead and `<div class="modules">`, as siblings of both, is the course introduction: what the course covers, who it is for, how it works and how it is graded. It is ordinary HTML styled by your stylesheet.

**The grading scale.** The introduction should show the grading scale as a list with the class `scale-list`, each item a label followed by its range:

```html
<ul class="scale-list">
  <li><span>First Class (A)</span><b>75-100%</b></li>
  <li><span>Second Class (B+)</span><b>70-74%</b></li>
  <li><span>Second Class (B)</span><b>60-69%</b></li>
  <li><span>Third (C)</span><b>50-59%</b></li>
  <li><span>Fail</span><b>0-49%</b></li>
</ul>
```

CattoLMS makes these the course's grade bands (the lowest must start at 0) and shows the course's current scale in its place. Without the list it uses exactly these five bands and says so.

**How the grade is made is fixed:** the module assessments count for 50% of the final mark and the final assessment for the other 50%. Say exactly this where the introduction explains grading, and nothing else: no other weights, no marks for a capstone, project or assignment, and no rubric marks. A capstone or project is practical work like any other, with model answers or a checklist the learner uses themselves.

## 8. A module

```html
<section class="module" id="mod-m1" data-assessment-required="true">
  <details class="panel">
    <summary class="panel-head">
      <span class="node"><span class="num">01</span><span class="check">&#10003;</span></span>
      <span class="m-titles">
        <span class="eyebrow">Module 01</span>
        <span class="m-name">Soil, compost and containers</span>
        <small>Getting the ground right before you plant</small>
      </span>
      <span class="m-grade" data-grade></span>
      <span class="chev">&#8250;</span>
    </summary>
    <div class="module-body">

      <div class="subs">
        <details class="sub s-present">
          <summary><span class="sub-label">Presentation<small>What this module will let you do</small></span><span class="chev">&#8250;</span></summary>
          <div class="sub-inner"><div class="presentation" data-deck="m1"></div></div>
        </details>
      </div>

      <div class="video">...</div>                        <!-- section 8.2 -->
      <div class="outcomes">...</div>                     <!-- section 8.1 -->

      ... the lesson ...                                  <!-- section 8.3 -->

      <div class="subs">
        <details class="sub s-summary">...</details>      <!-- section 8.4 -->
        <details class="sub s-cards">
          <summary><span class="sub-label">Flash cards<small>Test your recall, one card at a time</small></span><span class="chev">&#8250;</span></summary>
          <div class="sub-inner"><div class="flashcards" data-cards="m1"></div></div>
        </details>
      </div>

      <div class="blocks">
        <details class="block practical">...</details>    <!-- section 8.5 -->
        <details class="block answers">...</details>
        <details class="block review-aid">...</details>   <!-- section 8.6, optional -->
      </div>

      <div class="subs">
        <details class="sub s-assess">
          <summary><span class="sub-label">Self Assessment<small>24 questions, 48 points</small></span><span class="chev">&#8250;</span></summary>
          <div class="sub-inner"><div class="quiz" data-quiz="m1"></div></div>
        </details>
        <details class="sub s-answers">
          <summary><span class="sub-label">Answer key<small>The answers and why, for after the assessment</small></span><span class="chev">&#8250;</span></summary>
          <div class="sub-inner"><ol class="key-list" data-key="m1"></ol></div>
        </details>
      </div>

    </div>
  </details>
</section>
```

**The module** is a `<section>` with the class `module` and the id `mod-m1`, `mod-m2` and so on in order, holding its panel, `<details class="panel">`. Its key is `m1`, `m2` and so on, and the data declarations and the `data-deck`, `data-cards`, `data-quiz` and `data-key` places use the same keys. `data-assessment-required="true"` makes its assessment count towards the grade, and `"false"` means it does not count. The panel's `<summary class="panel-head">` holds the module's number (`.num`, written `01`, `02` and so on), a tick shown once its assessment is marked (`.check`), its titles, its mark (`.m-grade`) and an arrow (`.chev`). Its `.m-name` is the title alone: the site numbers the modules itself, so a title never contains its number. Its `.m-titles small` is a one-line subtitle.

**Module 0.** A course whose first module only sets the learner up, such as a practice machine to build or software to install, may number it 0: its id is `mod-m0` and its key `m0`, and it usually has `data-assessment-required="false"` and no assessment. CattoLMS then numbers that course's modules from 0. Every other course starts at `mod-m1`.

**Inside the module, every part has its own panel**, built and ordered exactly as shown. The presentation panel comes first, then the video, the outcomes and the lesson, which are the module's own content and sit in the module's panel itself (CattoLMS takes the video and the outcomes from there, so neither goes in a panel of its own). After the lesson come the summary and flash cards panels, the practical work and model answers blocks, and the self assessment and answer key panels. Each part's `<summary>` shows its name and, in `<small>`, a line about it, then an arrow.

**`.module-body`** holds everything CattoLMS takes from the module. CattoLMS lifts out the outcomes, the video, the `.subs` blocks, the practical work and the answers, and **everything left is the lesson**, in the order written.

**A module is a week, about 15 hours.** Plan each module's work to fill it. As a guide:

| Part | The learner's time |
| --- | --- |
| Presentation and video | About half an hour |
| Lesson, read closely with every example worked through or tried | 4 to 5 hours |
| Practical work | 7 to 8 hours |
| Summary and flash cards | About an hour |
| Assessment, and going back over what it shows | About an hour |

The lesson and the practical work carry the module. When a module needs more time, give it more worked examples to try and more substantial practical tasks, never longer sentences.

**`.subs` blocks are for the file alone.** CattoLMS removes every `.subs` block from the lesson, taking only the summary from the first `.s-summary .sub-inner`. The panels for the presentation, the summary, the flash cards, the self assessment and the answer key go in `.subs` blocks, as shown, so none of them is left behind in the lesson. **The practical work and the model answers never go in a `.subs` block:** CattoLMS takes them from the lesson, so they sit in `<div class="blocks">` after the summary and flash cards, as shown, and a `.subs` block would remove them.

### 8.1 Outcomes

```html
<div class="outcomes">
  <span class="eyebrow">Learning outcomes</span>
  <ol>
    <li>Test your soil's texture with a jar</li>
    <li>Make compost that is ready in months</li>
  </ol>
</div>
```

Three to six outcomes, each something the learner can do, starting with a verb. The label is for the file alone: CattoLMS shows them at the top of the module section as "In this module you will learn to:". Only the list items are taken.

### 8.2 The video

```html
<div class="video">
  <h3>Video</h3>
  <iframe src="https://www.youtube-nocookie.com/embed/VIDEO_ID" title="How to make compost at home" allowfullscreen></iframe>
  <p class="video-cap">Watch for the balance of greens and browns, and how the heap is kept damp.</p>
</div>
```

`VIDEO_ID` is the 11 characters after `v=` in the video's YouTube address. The iframe's `title` becomes the video's name and `.video-cap` its caption: what to watch for, linking it to the lesson. Everything inside `.video`, its heading included, is lifted out of the lesson. The video sits in the module's own panel, after the presentation panel and before the outcomes, never inside a `.subs` block or a panel of its own: CattoLMS takes it from there, and a `.subs` block would remove it first.

**Every module has one video.** Search YouTube for it yourself: an existing public video by others, 3 to 15 minutes, on this module's topic at this course's level, from a reputable source (a university, a professional body, a government department or a recognised educator), current, and South African for law, tax and regulation. Choose the one that best prepares the learner for this module's lesson.

**Check every video before you use it**, in two steps:

1. Open `https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=VIDEO_ID&format=json`. An answer in JSON, giving the video's title and channel, means the video is there and may be played on other sites. An error 401 or 403 means its owner does not let it play on other sites, and 404 or 400 means it is gone, private or the id is wrong: choose another. The site asks the same address about every course video once a day and reports any that stop playing.
2. Open the video's own page (`https://www.youtube.com/watch?v=VIDEO_ID`) and confirm its length, its date, and that it teaches what this module teaches.

**Never guess or invent a video id.** If a thorough search finds no video that meets these rules for a module, write the module without one and say which module lacks a video when you hand the file over, so one can be chosen.

### 8.3 The lesson

Write the lesson as ordinary HTML inside `.module-body`: `<h2>` for its sections, `<h3>` and `<h4>` below them (never `<h1>`), paragraphs, lists, tables with `<caption>` and `<th>`, figures, and blocks with your own classes, each styled in your stylesheet.

1. **Teach fully.** A learner who read only the lesson should meet every outcome.
2. **Illustrate every lesson with SVG drawings** written into the page: at least one in every module, and one wherever a process, a structure, a comparison or a set of figures is easier seen than read. Draw what the lesson teaches, from its own content: never decoration. Each drawing:
   - sits in a `<figure>` with a `<figcaption>`, and has a `viewBox` and `role="img" aria-label="..."` saying what it shows
   - has its colours written on its shapes (`fill="#2f6b4f"`, `stroke="#1d2939"`), never given by a class in your stylesheet, a `<style>` inside the drawing or `currentColor`, because the PDFs draw each drawing as a picture on its own, without your stylesheet
   - writes its words as `<text>`, large enough to read when the drawing is shrunk to a phone's width
   - has no scripts, no `<foreignObject>` and no links to other files

   Photographs as PNG or JPEG written into the file as `data:image/png;base64,...` addresses (each under 1 MB), or as full `https://` addresses. Every picture has `alt` text.
3. **Static content.** Everything a learner must read is in the HTML itself, never written by a script. Anything that opens and closes is a `<details>` element.
4. **Ids** are unique in the whole file, ids inside SVG drawings too. Links go to other websites, with full addresses.
5. **Never a section headed "Summary"** in the lesson: the summary has its own place.
6. **Code** is shown as `<pre><code>...</code></pre>` for a listing of more than one line and `<code>...</code>` inside a sentence. Write `<`, `>` and `&` in code as `&lt;`, `&gt;` and `&amp;`, so the code shows instead of acting: `<pre><code>&lt;p class="intro"&gt;Hello&lt;/p&gt;</code></pre>`. Style `pre` and `code` in your stylesheet. Keep lines to about 80 characters: a longer line scrolls sideways on the site and wraps in the PDFs. Colour in code, if you want any, is written into the HTML as `<span>` elements with classes styled in your stylesheet: no highlighting script runs on the site.
7. **Sources last.** A lesson that relies on law, regulation or published figures ends with `<h2>Sources</h2>` and a list: each statute by its full name and number, each website by its full address.
8. **Everything in the content is kept as written:** every element, class and attribute, so classes on table rows, list items or spans are fine. A key-terms list, a template to fill in or a document is a block like any other, with a class of your own. A course file carries no downloads: write a template as a table.
9. **What prints.** In the PDFs, every `<details>` block prints opened, and code prints in a fixed-width type.

### 8.4 The summary

```html
<details class="sub s-summary">
  <summary><span class="sub-label">Summary<small>The module in brief</small></span><span class="chev">&#8250;</span></summary>
  <div class="sub-inner">
    <ul>
      <li>Roots need water, air and food from the soil, and most garden failures start there.</li>
    </ul>
  </div>
</details>
```

A panel, first in the `.subs` block after the lesson. CattoLMS takes what is in `.sub-inner`, and the `<summary>` is frame. Six to ten points, up to 300 words, each a complete sentence stating something the learner should now know or be able to do. No new material.

### 8.5 Practical work and model answers

```html
<div class="blocks">
<details class="block practical">
  <summary><span class="sub-label">Practical work<small>Two tasks in your own space</small></span><span class="chev">&#8250;</span></summary>
  <div class="block-inner">
    <p>What the tasks are for and what the learner needs.</p>
    <section class="task">
      <h3>Task 1: Do a jar test on your soil</h3>
      <p>What to do, step by step.</p>
      <p class="produce"><strong>What you produce:</strong> a labelled sketch of the jar.</p>
      <p class="time"><strong>Time:</strong> 20 minutes, then a day for the jar to settle.</p>
    </section>
  </div>
</details>
<details class="block answers">
  <summary><span class="sub-label">Model answers<small>Check your own work once you have done the tasks</small></span><span class="chev">&#8250;</span></summary>
  <div class="block-inner">
    <section class="answer">
      <h3>Task 1: Do a jar test on your soil</h3>
      <p>A model answer, or a checklist the learner checks their own work against.</p>
    </section>
  </div>
</details>
</div>
```

Always both blocks, each a `<details class="block">` panel with its `.block-inner`, together in `<div class="blocks">` after the summary and flash cards panels and never inside a `.subs` block. CattoLMS takes what is in each `.block-inner`: the tasks become the Practical work part, and the answers its model answer, hidden until the learner chooses to see it. Three to six realistic tasks, together about 7 to 8 hours of work (section 8), each producing something the learner keeps, and one model answer for every task, in the same order and headed exactly like it. Style `.task`, `.answer`, `.produce`, `.time` and `.check`.

1. **Every task has its model answer in `.answers`,** never inside the task. A worked solution tucked into a task, behind its own "Show solution" button, is not a model answer to CattoLMS: it stays in the task, visible to the learner at once. Put it in `.answers` as that task's answer.
2. **A line saying how the learner can check a result** belongs in the task, as `<p class="check"><strong>How to check:</strong> ...</p>`. It helps, but it never replaces the model answer.
3. **A capstone, project or month-end piece of work** is practical work too: its tasks in `.practical`, and a model answer or a checklist of what good work contains in `.answers`.

### 8.6 A review study aid (optional)

```html
<details class="block review-aid">
  <summary><span class="sub-label">Review Study Aid<small>The points to fix in memory before the self assessment</small></span><span class="chev">&#8250;</span></summary>
  <div class="block-inner">
    <h4>Definitions you must be able to give</h4>
    <ul>
      <li>Loam is a soil in which no one particle size dominates.</li>
    </ul>
  </div>
</details>
```

A module may end with a study aid that gathers what to fix in memory before its self assessment: the definitions, distinctions, rules and figures it relies on, all from the lesson and nothing new. It goes in `<div class="blocks">` after the model answers. CattoLMS makes it a Review Study Aid part before the module's assessment, its `<small>` line becoming the part's opening sentence.

## 9. The data

The questions, slides and cards are data, written in one `<script>` element after the page content:

```html
<script>
const QUIZ = {"m1": [...], "m2": [...], "final": [...]};
const PRESENTATIONS = {"m1": {...}, "m2": {...}};
const FLASHCARDS = {"m1": [...], "m2": [...]};
const REVIEW = {};
</script>
```

CattoLMS reads these four declarations as data without running them. Each must be written exactly as `const NAME = ` followed by **strict JSON**: double quotes, no comments, no trailing commas, nothing computed. Keys are the module keys (`m1`, `m2` and so on), plus `final` in `QUIZ` (and `diag` for a readiness check, section 11), and `review` where there is a review module. Never write `</script>` inside a string. Text in slides and cards is plain text, never HTML.

### 9.1 `QUIZ`: the questions

```json
{"q": "After a day, the soil in your jar is 7 cm sand, 2 cm silt and 1 cm clay. What does this tell you?",
 "o": ["It is a clay soil that will waterlog", "It is a loam that needs nothing added", "The test failed", "It is a sandy soil that will dry out quickly"],
 "a": 3, "p": 2,
 "e": "Sand is 70% of it, so it is a sandy soil. It drains fast, so it needs compost to hold water."}
```

| Field | Rule |
| --- | --- |
| `q` | The question. `q`, `o` and `e` are HTML: code in them is written as in a lesson, `<code>&lt;div&gt;</code>`, and a double quote inside a JSON string as `\"`. |
| `o` | 2 to 6 options, all different, usually four. |
| `a` | The right option, counting from 0. |
| `p` | The points the question is worth, which also marks its difficulty: 1 introductory, 2 standard, 3 advanced. A learner's score is the points of the questions they get right. |
| `e` | Why the right answer is right, and where useful why a tempting wrong one is wrong. Shown after answering. |

A question without `q`, `o` or a valid `a` is left out. Each module has 15 to 30 questions and the final assessment 20 to 40. Fewer still import: these numbers are what a good course needs.

1. **Mostly scenarios.** At least half put the learner in a situation and ask what they would do. Recall belongs in the flash cards.
2. **Plausible options.** Every wrong option is a mistake a learner could really make. Never "all of the above", "none of the above" or a joke. Options of similar length, the right one in different positions.
3. **One right answer,** beyond argument, found in the lesson. Every outcome tested by at least two questions.

CattoLMS sets how assessments run (pass mark, time limit, attempts), which can be changed on the site.

### 9.2 `PRESENTATIONS`: the slides

Each module's presentation opens the module. **Its one job is to get the learner ready for the lesson:** a hook from the real world, what the module will let them do, one quick question to get them thinking, and what to watch for in the video. It does not summarise the lesson. On the site, CatWalk plays it: the learner moves through the slides, points appear one at a time, and quick questions answer at once without counting towards any mark.

```json
"m1": {
  "title": "What you will learn in this module",
  "minutes": 3,
  "slides": [
    {"layout": "title", "title": "Welcome", "kicker": "Soil, compost and containers", "heading": "Good food starts below the surface"},
    {"layout": "points", "title": "By the end of this module", "heading": "You will be able to", "points": ["Test your soil with a jar", "Make compost", "Choose a container"]},
    {"layout": "check", "title": "A quick question", "question": "Why does garden soil do badly in a pot?", "options": ["Too few nutrients", "It packs down and drowns the roots", "It is too acidic"], "answer": 1, "explanation": "Garden soil compacts in a container and shuts out air."},
    {"layout": "next", "title": "Up next", "heading": "Now watch the video", "text": "Watch for the three things a compost heap needs.", "action_label": "Watch the video"}
  ]
}
```

| Field | Rule |
| --- | --- |
| `title` | The presentation's name, shown in the outline: a name of its own for each module. |
| `minutes` | Its running time, in whole minutes. |
| `kind` | Leave it out: CattoLMS sets it. |
| `theme` | `"light"` (the default) or `"dark"`. |
| `slides` | 5 to 9 slides for a module, 8 to 15 for the review, never more than 25. |

Every slide has a `layout` and a `title` of up to 40 characters (used in the presentation's contents list), and may have `chapter` (a label grouping slides in the progress bar), `tone` (`"base"`, `"alt"` or `"accent"`, for variety) and `narration` (optional: what a narrator says over the slide, up to 2,000 characters, which the course owner can record on the site and which is the slide's transcript: explain, never read the slide aloud). The layouts:

| Layout | Use it for | Fields (required in bold) | Limits |
| --- | --- | --- | --- |
| `title` | Opening the presentation or a chapter | **`heading`**, `kicker`, `sub` | Heading up to 10 words |
| `statement` | One big idea or a quotation | **`text`**, `source` | Up to 25 words |
| `points` | A short list that builds one point at a time | **`heading`**, **`points`**, `ordered` (true for numbers), `build` (false shows all at once) | 2 to 5 points, up to 12 words each |
| `compare` | Two things side by side | **`heading`**, **`columns`**: exactly two, each with `heading` and either `points` or `text` | Up to 4 points a column |
| `picture` | A diagram, photo or chart | `heading`, **`picture`**, **`alt`**, `caption` | `picture` is an SVG drawing's text, or a PNG or JPEG as a `data:image/png;base64,...` address, under 1.5 MB |
| `number` | One striking figure | **`figure`**, **`label`**, `source` | Figure up to 12 characters |
| `timeline` | Events in order | **`heading`**, **`events`**: each `{"when": "...", "what": "..."}` | 3 to 6 events |
| `steps` | A process to follow | **`heading`**, **`steps`** | 3 to 6 steps, up to 15 words each |
| `case` | A short scenario | **`heading`**, **`facts`**, **`question`**, **`outcome`** (shown last) | Facts up to 60 words |
| `check` | One quick question | **`question`**, **`options`**, **`answer`** (counting from 0), **`explanation`** | 2 to 4 options. Never marked |
| `next` | Handing back to the course | **`heading`**, **`text`**, `action_label` (the button's words: on the site it moves on to the next part) | The last slide only |

1. **One idea per slide, few words.** Keep what shows at once to about 35 words, though a `case` slide's facts may run to 60. Text shown only after a click (an explanation, an outcome) is not counted. Vary the rhythm: no more than two `points` slides in a row.
2. **At least one `check` slide** in each module's presentation.
3. **The last slide is a `next` slide** saying what to watch for in the video, or without a video, what to look for in the lesson.
4. **Use the real thing:** real figures, cases and documents from the course, never filler.
5. **Plain text, without `<` or `>`.** A slide cannot show code: name an element in words ("the img element", "an h1 heading") and leave code listings to the lesson.

A presentation that breaks a rule is left out, and the import review says which slide and why.

### 9.3 `FLASHCARDS`: the cards

```json
"m1": [
  {"front": "Which soil particle is the smallest?", "back": "Clay, smaller than 0.002 mm. It holds water but drains slowly."},
  {"front": "What does a pH of 7 mean?", "back": "Neutral: neither acidic nor alkaline.", "hint": "The middle of the scale."}
]
```

**Their one job is recall.** The learner reads the front, answers in their head, turns the card over and says whether they knew it. The cards they miss come back.

1. **10 to 20 cards** per module.
2. **`front` asks:** a question or a term to define, up to 200 characters.
3. **`back` answers briefly:** the answer first, then at most one sentence of why, up to 400 characters.
4. **`hint`** is optional, up to 150 characters: a nudge, never the answer.
5. Definitions, distinctions, the steps of a procedure, key figures and rules. Not trivia. Plain text, without `<` or `>`: name an element in words ("the img element") rather than writing its tag.

A set of cards that breaks a rule is left out, and the import review says why.

### 9.4 `REVIEW`

`REVIEW` holds the review module's points to remember (section 10). Without a review module, write `const REVIEW = {};`.

## 10. The review module (optional)

A course of six modules or more should end with a review before the final assessment. It is a panel after `<div class="modules">`, before the final assessment:

```html
<section class="review" id="mod-review" data-assessment-required="true">
  <details class="panel">
    <summary class="panel-head">
      <span class="node"><span class="num">R</span></span>
      <span class="m-titles"><span class="eyebrow">Revision</span><span class="m-name">Review</span><small>How the modules connect</small></span>
      <span class="chev">&#8250;</span>
    </summary>
    <div class="subs">
      <details class="sub s-present">
        <summary><span class="sub-label">Presentation<small>The course in review</small></span><span class="chev">&#8250;</span></summary>
        <div class="sub-inner"><div class="presentation" data-deck="review"></div></div>
      </details>
    </div>
    <div class="review-inner">
      <p>How the modules connect: the big picture and the common mistakes.</p>
      <div id="reviewCards"></div>
    </div>
    <div class="subs">
      <details class="sub s-assess">
        <summary><span class="sub-label">Self Assessment<small>20 questions, 40 points</small></span><span class="chev">&#8250;</span></summary>
        <div class="sub-inner"><div class="quiz" data-quiz="review"></div></div>
      </details>
      <details class="sub s-answers">
        <summary><span class="sub-label">Answer key<small>The answers and why, for after the assessment</small></span><span class="chev">&#8250;</span></summary>
        <div class="sub-inner"><ol class="key-list" data-key="review"></ol></div>
      </details>
    </div>
  </details>
</section>
```

What is in `.review-inner` becomes the review's study aid, followed by the points in `REVIEW`, one entry per module, which your script shows in `#reviewCards` and CattoLMS draws itself:

```json
const REVIEW = {"m1": {"t": "Soil, compost and containers", "pts": ["A jar test shows your soil's texture.", "Never fill a pot with garden soil."]}};
```

`PRESENTATIONS.review` is its presentation and `QUIZ.review` its assessment, which counts towards the grade with `data-assessment-required="true"` (with `"false"`, or no assessment, it does not). Its presentation and its self assessment with the answer key are panels of their own, before and after `.review-inner`, which holds the study aid alone: CattoLMS imports only what is in `.review-inner`, so none of the file's own places are left in the review on the site. The review is not numbered.

## 11. The final assessment

```html
<section class="final" id="final">
  <details class="panel">
    <summary class="panel-head">
      <span class="node"><span class="num">F</span><span class="check">&#10003;</span></span>
      <span class="m-titles"><span class="eyebrow">Assessment</span><span class="m-name">Final Assessment</span><small>40 questions from every module</small></span>
      <span class="m-grade" data-grade></span>
      <span class="chev">&#8250;</span>
    </summary>
    <div class="final-inner">
      <div class="quiz" data-quiz="final"></div>
    </div>
  </details>
</section>
```

A panel, last, after `<div class="modules">` and the review. Its questions are `QUIZ.final`, drawing on every module. Only the questions are imported: the section is for the file on its own.

**A readiness check (optional)** is a panel between the introduction and `<div class="modules">`:

```html
<section class="diag" id="diagnostic">
  <details class="panel">
    <summary class="panel-head">
      <span class="node"><span class="num">D</span></span>
      <span class="m-titles"><span class="eyebrow">Diagnostic</span><span class="m-name">Readiness Check</span><small>Ten questions on what this course assumes you know</small></span>
      <span class="chev">&#8250;</span>
    </summary>
    <div class="diag-inner">
      <p>This check is not scored and does not affect your grade. A wrong answer names the module to study first.</p>
      <div class="quiz" data-quiz="diag"></div>
    </div>
  </details>
</section>
```

Its questions in `QUIZ.diag` become a diagnostic before Module 1 that never counts towards the grade, named by its `.m-name`. Each may have `"u": ["m2"]`, the modules to study again after a wrong answer. Write `const DIAG_PASS = 70;` in the data script for the percentage that means "ready" (50 when left out). CattoLMS leaves the panel itself out of the course introduction.

## 12. The script for the file on its own

The file on its own needs a script, after the data script, that makes it work:

1. **The panels** need no script: every `<details>` opens and closes by itself. The script only opens the panel a link names, such as `#mod-m3` or `#final`, when the file opens.
2. **The parts.** Each `.presentation[data-deck]` shows its module's slides one at a time with Previous and Next (a `check` slide shows whether the answer is right and its explanation). Each `.flashcards[data-cards]` shows a card and turns it over. Each `.quiz[data-quiz]` lists its questions and marks them with their explanations and a score, and the module's (or the final assessment's) heading shows the mark in `.m-grade` and its tick. Each `.key-list[data-key]` lists the right answers with their explanations. `#reviewCards` shows `REVIEW`. Questions, options and explanations are written as HTML (`innerHTML`), so code in them shows as code.

It need not save anything. Style what it makes through classes it gives them, never through bare `button`, `input` or similar rules (section 5, rule 5). CattoLMS ignores this script, so it never needs to suit the site. Section 14 has a short one that works with every layout.

**The panels' styles**, in your stylesheet: hide the browser's own triangle on the panels' headings, since each heading draws its own arrow, and turn the arrow when its panel is open:

```css
.panel>summary,.sub>summary,.block>summary{list-style:none;cursor:pointer}
.panel>summary::-webkit-details-marker,.sub>summary::-webkit-details-marker,.block>summary::-webkit-details-marker{display:none}
details[open]>summary .chev{transform:rotate(90deg)}
```

Name the panels in these rules (`.panel`, `.sub`, `.block`), never every `summary`: a `<details>` in a lesson, such as a worked example, keeps its own triangle.

## 13. Before you hand it over

- [ ] One file, opening in a browser with no errors, every module working.
- [ ] `<meta name="cattolms:course-code">`, the masthead with `data-level`, the title alone in the `h1`.
- [ ] One `<style>`: every kind of block in the content (a tip, a warning, a case, a definition) has a class with a rule, no rule styles content through the frame (section 5, rule 3), and no rule styles bare `button`, `input`, `label`, `fieldset`, `legend`, `select` or `form`.
- [ ] Modules `mod-m1`, `mod-m2` and so on in order, each a panel with `.m-name`, `.m-titles small`, `.module-body`, `.outcomes`, a summary in `.s-summary .sub-inner`, and `.practical` with `.answers` in `<div class="blocks">`.
- [ ] Every part in its own panel, closed when the file opens, in the order section 8 shows: Presentation, (the video, outcomes and lesson), Summary, Flash cards, Practical work, Model answers, Self Assessment, Answer key. The readiness check, the review and the final assessment are panels too. Every panel is a `<details>` with its `<summary>`, without `open`, and nothing opens or closes any other way.
- [ ] Every part under exactly the name section 4.1 gives it, and no other name for any part.
- [ ] Every practical task has its model answer in `.answers`, headed like its task, and no solution hidden inside a task.
- [ ] Nothing about submitting work or anyone marking it, and grading stated only as 50% module assessments and 50% final assessment.
- [ ] The presentation, summary, flash cards, self assessment and answer key panels inside `.subs` blocks, and the video, the practical work, the model answers and a review study aid never inside one. The review's presentation and self assessment in panels outside `.review-inner`.
- [ ] `mod-m0` only for a first module that sets the learner up.
- [ ] `QUIZ`, `PRESENTATIONS`, `FLASHCARDS` and `REVIEW` in strict JSON, with an entry for every module and `QUIZ.final`.
- [ ] Every `a` and `answer` points at an option that is there, counting from 0.
- [ ] Every presentation has 5 to 9 slides, a `check` slide, and a `next` slide last.
- [ ] Every module planned as a week, about 15 hours, with practical work of about 7 to 8 hours.
- [ ] Every module has one video, each checked at YouTube's oEmbed address and on its own page (section 8.2), or the module that lacks one named when you hand the file over.
- [ ] Every lesson illustrated with SVG drawings in `<figure>`s, each with its colours on its shapes and an `aria-label`.
- [ ] Code in `<pre><code>` or `<code>`, with `<`, `>` and `&` written as `&lt;`, `&gt;` and `&amp;`. No `<` or `>` on a slide or a flash card.
- [ ] Every fact checked, every rule stated precisely with its source, shared facts stated the same way as in the other courses of the series, house style followed, no invented sources.
- [ ] Ids unique in the whole file.

The import review in CattoLMS shows what it found: the modules, the questions, and a warning for anything it left out or found under the wrong name. Read every warning, and fix the file until there are none.

## 14. A complete example

A course of one module and a final assessment, built as the original course files are, every part in its own panel, with its stylesheet and a script that makes it work on its own. A real course has more modules, each a week of about 15 hours, with longer lessons and more drawings, three to six practical tasks, 15 to 30 questions and 10 to 20 flash cards. The video id `EXAMPLE0001` stands in for a real one, found and checked as section 8.2 says.

```html
<!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="cattolms:course-code" content="GDN-101">
<title>Growing Vegetables in Small Spaces</title>
<style>
:root{--ink:#1f2a24;--muted:#5b675f;--paper:#fbfaf6;--line:#d9ded6;--leaf:#2f5d50;--sun:#f2c14e;--warn:#b5462b}
body{margin:0;background:var(--paper);color:var(--ink);font:17px/1.65 Georgia,"Times New Roman",serif}
h1,h2,h3,h4{font-family:Arial,Helvetica,sans-serif;line-height:1.25;color:var(--leaf)}
a{color:var(--leaf)}
table{width:100%;border-collapse:collapse;margin:1rem 0}
caption{text-align:left;font-weight:bold;padding-bottom:.4rem}
th,td{border:1px solid var(--line);padding:.45rem .6rem;text-align:left;vertical-align:top}
th{background:#eef2ec}
figure{margin:1.2rem 0}
figure svg{display:block;max-width:100%;height:auto}
figcaption{color:var(--muted);font-size:.9rem}
.tip,.warning{border-left:5px solid var(--leaf);background:#eef5ef;padding:.7rem 1rem;margin:1rem 0}
.warning{border-color:var(--warn);background:#fbefea}
.tip-label,.warning-label{display:block;font:bold .8rem Arial,sans-serif;text-transform:uppercase;letter-spacing:.05em}
.example{border:1px solid var(--line);background:#fff;padding:.6rem 1rem;margin:1rem 0}
.example summary{cursor:pointer;font-weight:bold}
.outcomes{background:#fff;border:1px solid var(--line);padding:.6rem 1rem}
.video iframe{display:block;width:100%;aspect-ratio:16/9;border:0}
.video-cap{color:var(--muted);font-size:.9rem}
.task,.answer{border:1px solid var(--line);background:#fff;padding:.6rem 1rem;margin:.8rem 0}
.produce,.time{font-size:.95rem}
.masthead{background:var(--leaf);color:#fff;padding:1.5rem}
.masthead h1{color:#fff;margin:.5rem 0}
.course-cover svg{display:block;width:100%;height:auto;max-height:18rem}
.course-subtitle{font-weight:bold}
.slide{border:1px solid var(--line);background:#fff;min-height:12rem;padding:1rem 1.2rem}
.slide-controls,.card-controls,.quiz-controls{display:flex;gap:.5rem;align-items:center;margin:.6rem 0}
.btn{font:inherit;padding:.4rem .9rem;border:1px solid var(--leaf);background:#fff;color:var(--leaf);cursor:pointer}
.card{border:1px solid var(--line);background:#fff;padding:1.2rem;min-height:6rem}
.card-back{color:var(--leaf);font-weight:bold}
.question{margin:1rem 0}
.verdict{margin:.3rem 0 0}
.right{color:var(--leaf);font-weight:bold}
.wrong{color:var(--warn);font-weight:bold}
.scale-list{list-style:none;padding:0}
.scale-list li{display:flex;justify-content:space-between;border-bottom:1px solid var(--line);padding:.3rem 0}
/* The frame, for the file on its own: the page, the masthead and the panels that open and close (section 12) */
.wrap{max-width:56rem;margin:0 auto;padding:1rem}
.eyebrow{display:block;color:var(--muted);font:.8rem Arial,sans-serif;text-transform:uppercase;letter-spacing:.05em}
.panel,.sub,.block{border:1px solid var(--line);background:#fff}
.panel{margin:.8rem 0}
.panel>summary,.sub>summary,.block>summary{display:flex;align-items:center;gap:.75rem;cursor:pointer;list-style:none}
.panel>summary::-webkit-details-marker,.sub>summary::-webkit-details-marker,.block>summary::-webkit-details-marker{display:none}
.panel>summary{padding:.9rem 1rem}
.sub>summary,.block>summary{padding:.7rem .9rem;font-weight:bold}
.panel>summary:focus-visible,.sub>summary:focus-visible,.block>summary:focus-visible{outline:3px solid var(--sun);outline-offset:2px}
.node{width:2.4rem;height:2.4rem;flex:none;border-radius:50%;display:grid;place-items:center;border:2px solid var(--leaf);color:var(--leaf);font:bold .9rem Arial,sans-serif}
.node .check{display:none}
.done .panel-head .node{background:var(--leaf);color:#fff}
.done .panel-head .node .num{display:none}
.done .panel-head .node .check{display:inline}
.m-titles,.sub-label{flex:1;font-family:Arial,sans-serif}
.m-name{display:block;font-size:1.25rem;font-weight:bold;color:var(--leaf)}
.m-titles small,.sub-label small{display:block;color:var(--muted);font-weight:normal;font-size:.85rem}
.m-grade{font:bold .9rem Arial,sans-serif;color:var(--leaf)}
.chev{font-size:1.5rem;line-height:1;color:var(--leaf);transition:transform .25s ease}
details[open]>summary .chev{transform:rotate(90deg)}
.module-body{padding:0 1rem 1rem}
.subs,.blocks{display:grid;gap:.6rem;margin:1.2rem 0}
.sub-inner,.block-inner{padding:.2rem 1rem 1rem}
.key-list li{margin:.5rem 0}
.key-answer{display:block;font-weight:bold;color:var(--leaf)}
</style>
</head>
<body>
<div class="wrap">
  <header class="masthead" data-level="Beginner">
    <div class="course-cover">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000" role="img" aria-label="Three pots of vegetables on a balcony rail in the sun">
        <rect width="1600" height="1000" fill="#2F5D50"/>
        <circle cx="1270" cy="230" r="120" fill="#F2C14E"/>
        <rect x="0" y="720" width="1600" height="280" fill="#7A4E2D"/>
        <path d="M250 540 L450 540 L420 720 L280 720 Z" fill="#C8693A"/>
        <path d="M330 520 C300 420 360 360 400 330 C420 400 400 470 360 520 Z" fill="#7BB661"/>
        <path d="M680 500 L920 500 L890 720 L710 720 Z" fill="#B85C33"/>
        <circle cx="800" cy="430" r="34" fill="#D7442E"/>
        <path d="M1110 570 L1330 570 L1310 720 L1130 720 Z" fill="#C8693A"/>
        <path d="M1200 560 C1230 500 1270 480 1310 470 C1300 520 1260 550 1210 570 Z" fill="#7BB661"/>
      </svg>
    </div>
    <h1>Growing Vegetables in Small Spaces</h1>
    <p class="course-subtitle">Food from a balcony, a stoep or a small yard</p>
    <p class="course-summary">A practical course for people in South Africa who want to grow their own vegetables in containers and small beds, starting with the soil every plant depends on.</p>
  </header>

  <section class="intro">
    <h2>How this course works</h2>
    <p>Each module opens with a short presentation, then a video, the lesson, a summary, flash cards, practical work you do in your own space, and an assessment. Each is a panel: select its heading to open it or close it. Work through them in order.</p>
    <h3>How you are graded</h3>
    <p>Your module assessments count for half of your final mark and the final assessment for the other half.</p>
    <ul class="scale-list">
      <li><span>First Class (A)</span><b>75-100%</b></li>
      <li><span>Second Class (B+)</span><b>70-74%</b></li>
      <li><span>Second Class (B)</span><b>60-69%</b></li>
      <li><span>Third (C)</span><b>50-59%</b></li>
      <li><span>Fail</span><b>0-49%</b></li>
    </ul>
  </section>

  <div class="modules">
    <section class="module" id="mod-m1" data-assessment-required="true">
      <details class="panel">
        <summary class="panel-head">
          <span class="node"><span class="num">01</span><span class="check">&#10003;</span></span>
          <span class="m-titles">
            <span class="eyebrow">Module 01</span>
            <span class="m-name">Soil, compost and containers</span>
            <small>Getting the ground right before you plant</small>
          </span>
          <span class="m-grade" data-grade></span>
          <span class="chev">&#8250;</span>
        </summary>
        <div class="module-body">

          <div class="subs">
            <details class="sub s-present">
              <summary><span class="sub-label">Presentation<small>What this module will let you do</small></span><span class="chev">&#8250;</span></summary>
              <div class="sub-inner"><div class="presentation" data-deck="m1"></div></div>
            </details>
          </div>

          <div class="video">
            <iframe src="https://www.youtube-nocookie.com/embed/EXAMPLE0001" title="How to make compost at home" allowfullscreen></iframe>
            <p class="video-cap">Watch for the balance of greens and browns, and how the heap is kept damp and turned.</p>
          </div>

          <div class="outcomes">
            <span class="eyebrow">Learning outcomes</span>
            <ol>
              <li>Test your soil's texture with a jar</li>
              <li>Make compost that is ready in months</li>
              <li>Choose and fill a container for each crop</li>
            </ol>
          </div>

          <h2>1. Why soil comes first</h2>
          <p>When a vegetable garden fails, people usually blame the seed, the weather or pests. Far more often the trouble is in the soil. Roots need three things from the ground they grow in: water, air and food.</p>
          <table>
            <caption>What a good garden soil is made of, by volume</caption>
            <thead><tr><th>Part</th><th>Share</th><th>What it does</th></tr></thead>
            <tbody>
              <tr><td>Mineral particles (sand, silt and clay)</td><td>About 45%</td><td>The body of the soil. Their sizes decide how it drains.</td></tr>
              <tr><td>Water</td><td>About 25%</td><td>Carries dissolved nutrients to the roots.</td></tr>
              <tr><td>Air</td><td>About 25%</td><td>Roots need oxygen. Without it they rot.</td></tr>
              <tr><td>Organic matter</td><td>About 5%</td><td>Holds water and feeds the life in the soil.</td></tr>
            </tbody>
          </table>

          <h2>2. Test your soil with a jar</h2>
          <p>Fill a glass jar a third full with soil, top it up with water, shake it hard and leave it for a day. The particles settle by size.</p>
          <figure>
            <svg viewBox="0 0 300 200" role="img" aria-label="A jar of settled soil in three layers: sand at the bottom, silt above it and clay on top">
              <rect x="100" y="20" width="100" height="170" fill="#eaf1f4" stroke="#5b675f" stroke-width="3"/>
              <rect x="103" y="130" width="94" height="57" fill="#d8b36a"/>
              <rect x="103" y="100" width="94" height="30" fill="#a8805a"/>
              <rect x="103" y="85" width="94" height="15" fill="#7a5a43"/>
              <text x="210" y="165" font-size="14" fill="#1f2a24">Sand</text>
              <text x="210" y="120" font-size="14" fill="#1f2a24">Silt</text>
              <text x="210" y="97" font-size="14" fill="#1f2a24">Clay</text>
            </svg>
            <figcaption>Sand settles first, then silt, then clay.</figcaption>
          </figure>
          <div class="tip"><span class="tip-label">Tip</span><p>Measure each layer and divide by the total depth. Mostly sand is a sandy soil, mostly clay a clay soil, and no part dominating is a loam.</p></div>
          <details class="example">
            <summary>Worked example: reading a jar</summary>
            <p>Settled soil 10 cm deep: sand 7 cm, silt 2 cm, clay 1 cm. Sand is 70% of it, so it is a sandy soil that drains fast and needs compost to hold water.</p>
          </details>

          <h2>3. Containers</h2>
          <p>A container must be big enough for its crop, drain freely and be filled with potting soil.</p>
          <div class="warning"><span class="warning-label">Warning</span><p>Never fill a pot with garden soil. It packs down into a hard block and shuts out the air the roots need.</p></div>

          <div class="subs">
            <details class="sub s-summary">
              <summary><span class="sub-label">Summary<small>The module in brief</small></span><span class="chev">&#8250;</span></summary>
              <div class="sub-inner">
                <ul>
                  <li>Roots need water, air and food from the soil, and most garden failures start there.</li>
                  <li>A good loam is about 45% mineral particles, 25% water, 25% air and 5% organic matter.</li>
                  <li>A jar test shows your soil's texture: sand settles first, then silt, then clay.</li>
                  <li>A container must be big enough, drain freely and hold potting soil, never garden soil.</li>
                </ul>
              </div>
            </details>
            <details class="sub s-cards">
              <summary><span class="sub-label">Flash cards<small>Test your recall, one card at a time</small></span><span class="chev">&#8250;</span></summary>
              <div class="sub-inner"><div class="flashcards" data-cards="m1"></div></div>
            </details>
          </div>

          <div class="blocks">
            <details class="block practical">
              <summary><span class="sub-label">Practical work<small>Two tasks in your own space</small></span><span class="chev">&#8250;</span></summary>
              <div class="block-inner">
                <p>These tasks put the module to work in your own space. You need a glass jar with a lid, a ruler and the space you plan to grow in.</p>
                <section class="task">
                  <h3>Task 1: Do a jar test on your soil</h3>
                  <p>Take soil from where you would most like to grow vegetables. Shake it in a jar of water, leave it for a day, then measure each layer.</p>
                  <p class="produce"><strong>What you produce:</strong> a labelled sketch of the jar with the depth and share of each layer.</p>
                  <p class="time"><strong>Time:</strong> 20 minutes, then a day for the jar to settle.</p>
                </section>
                <section class="task">
                  <h3>Task 2: Choose a container for one crop</h3>
                  <p>Pick one vegetable you want to grow and choose a container for it from what you have or can buy nearby.</p>
                  <p class="produce"><strong>What you produce:</strong> a note of the crop, the container's size and how it drains.</p>
                  <p class="time"><strong>Time:</strong> 30 minutes.</p>
                </section>
              </div>
            </details>
            <details class="block answers">
              <summary><span class="sub-label">Model answers<small>Check your own work once you have done the tasks</small></span><span class="chev">&#8250;</span></summary>
              <div class="block-inner">
                <section class="answer">
                  <h3>Task 1: Do a jar test on your soil</h3>
                  <p>A good answer: "Settled soil 9 cm deep. Sand 5 cm (56%), silt 2.5 cm (28%), clay 1.5 cm (17%). A sandy loam: I will dig in compost and mulch." Check that your shares add up to about 100%.</p>
                </section>
                <section class="answer">
                  <h3>Task 2: Choose a container for one crop</h3>
                  <p>A good answer names a container at least as deep as the crop's roots, with holes in the bottom, such as a 20-litre bucket with five holes for a tomato.</p>
                </section>
              </div>
            </details>
          </div>

          <div class="subs">
            <details class="sub s-assess">
              <summary><span class="sub-label">Self Assessment<small>3 questions, 5 points</small></span><span class="chev">&#8250;</span></summary>
              <div class="sub-inner"><div class="quiz" data-quiz="m1"></div></div>
            </details>
            <details class="sub s-answers">
              <summary><span class="sub-label">Answer key<small>The answers and why, for after the assessment</small></span><span class="chev">&#8250;</span></summary>
              <div class="sub-inner"><ol class="key-list" data-key="m1"></ol></div>
            </details>
          </div>

        </div>
      </details>
    </section>
  </div>

  <section class="final" id="final">
    <details class="panel">
      <summary class="panel-head">
        <span class="node"><span class="num">F</span><span class="check">&#10003;</span></span>
        <span class="m-titles">
          <span class="eyebrow">Assessment</span>
          <span class="m-name">Final Assessment</span>
          <small>2 questions from every module</small>
        </span>
        <span class="m-grade" data-grade></span>
        <span class="chev">&#8250;</span>
      </summary>
      <div class="final-inner">
        <div class="quiz" data-quiz="final"></div>
      </div>
    </details>
  </section>
</div>

<script>
const QUIZ = {
  "m1": [
    {"q": "Roughly what share of a good garden soil, by volume, is air?", "o": ["About 5%", "About 25%", "About 45%", "About 75%"], "a": 1, "p": 1, "e": "A healthy loam is about a quarter air and a quarter water, with mineral particles about 45% and organic matter about 5%."},
    {"q": "After a day, the soil in your jar is 10 cm deep: 7 cm sand, 2 cm silt and 1 cm clay. What does this tell you?", "o": ["It is a clay soil that will waterlog", "It is a loam that needs nothing added", "The test failed because the layers are uneven", "It is a sandy soil that will dry out quickly"], "a": 3, "p": 2, "e": "Sand is 70% of it, so it is a sandy soil. It drains fast, so it needs compost to hold water."},
    {"q": "Your seedlings in a pot of garden soil have stopped growing and the soil is a hard block. What is the best fix?", "o": ["Water them every evening", "Replant them in potting soil", "Add fertiliser", "Move the pot into full sun"], "a": 1, "p": 2, "e": "Garden soil packs down in a pot and shuts out the air the roots need. Potting soil stays open."}
  ],
  "final": [
    {"q": "A bed stays waterlogged for days after rain and sets like brick in winter. What would you do first?", "o": ["Add lime every season", "Dig in compost and keep the bed mulched", "Spread river sand on top", "Water it once a week"], "a": 1, "p": 2, "e": "Waterlogging and baking hard are signs of clay. Compost opens clay up, and mulch protects the surface."},
    {"q": "In a jar test, which layer settles first?", "o": ["Clay", "Silt", "Sand", "Organic matter"], "a": 2, "p": 1, "e": "Sand is the largest and heaviest particle, so it settles within minutes."}
  ]
};
const PRESENTATIONS = {
  "m1": {
    "title": "What you will learn in this module",
    "minutes": 3,
    "slides": [
      {"layout": "title", "title": "Welcome", "kicker": "Soil, compost and containers", "heading": "Good food starts below the surface", "sub": "Three things to get right before you plant anything"},
      {"layout": "number", "title": "The small part that matters", "figure": "5%", "label": "of a good garden soil is organic matter. It holds water and feeds the life in the soil."},
      {"layout": "points", "title": "By the end of this module", "heading": "You will be able to", "points": ["Test your soil's texture with a jar", "Make compost that is ready in months", "Choose and fill a container for each crop"]},
      {"layout": "check", "title": "A quick question", "question": "Why does garden soil do badly in a pot?", "options": ["It has too few nutrients", "It packs down and drowns the roots", "It is too acidic"], "answer": 1, "explanation": "Garden soil compacts in a container and shuts out the air the roots need."},
      {"layout": "next", "title": "Up next", "heading": "Now watch the video", "text": "Watch for the three things a compost heap needs.", "action_label": "Watch the video"}
    ]
  }
};
const FLASHCARDS = {
  "m1": [
    {"front": "Which soil particle is the largest?", "back": "Sand, 0.05 to 2 mm across. It drains fast and holds little water."},
    {"front": "Which soil particle is the smallest?", "back": "Clay, smaller than 0.002 mm. It holds water but drains slowly."},
    {"front": "What is loam?", "back": "A mix of sand, silt and clay in which none dominates. It drains well yet holds water."},
    {"front": "Why is garden soil wrong for a pot?", "back": "It packs down and shuts out air.", "hint": "Think about what the roots breathe."}
  ]
};
const REVIEW = {};
</script>

<script>
/* The file on its own: presentations play one slide at a time, flash cards turn over, and quizzes mark themselves and
   fill their answer keys. The panels need no script. CattoLMS does not use this script. */
(function () {
  "use strict";
  function make(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  // The panels open and close by themselves. A link to a panel, such as #mod-m1 or #final, opens it.
  var linked = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  var panel = linked ? (linked.tagName === "DETAILS" ? linked : linked.querySelector("details")) : null;
  if (panel) { panel.open = true; }

  document.querySelectorAll(".presentation[data-deck]").forEach(function (box) {
    var deck = PRESENTATIONS[box.getAttribute("data-deck")];
    if (!deck) { return; }
    var index = 0;
    function draw() {
      var slide = deck.slides[index];
      box.textContent = "";
      var face = make("div", "slide");
      if (slide.kicker) { face.appendChild(make("p", "eyebrow", slide.kicker)); }
      var heading = slide.heading || slide.question || slide.figure || slide.text;
      if (heading) { face.appendChild(make("h3", "", heading)); }
      ["sub", "label", "facts", "source"].forEach(function (field) { if (slide[field] && slide[field] !== heading) { face.appendChild(make("p", "", slide[field])); } });
      if (slide.text && slide.text !== heading) { face.appendChild(make("p", "", slide.text)); }
      var list = slide.points || slide.steps;
      if (list) { var ul = make("ul"); list.forEach(function (item) { ul.appendChild(make("li", "", item)); }); face.appendChild(ul); }
      if (slide.events) { var events = make("ul"); slide.events.forEach(function (e) { events.appendChild(make("li", "", e.when + ": " + e.what)); }); face.appendChild(events); }
      if (slide.columns) { slide.columns.forEach(function (c) { face.appendChild(make("h4", "", c.heading)); face.appendChild(make("p", "", c.text || (c.points || []).join(", "))); }); }
      if (slide.picture) { var picture = make("figure"); picture.innerHTML = slide.picture; face.appendChild(picture); }
      if (slide.options) {
        slide.options.forEach(function (option, i) {
          var choice = make("button", "btn", option);
          choice.type = "button";
          choice.addEventListener("click", function () {
            face.appendChild(make("p", i === slide.answer ? "right" : "wrong", (i === slide.answer ? "Right. " : "Not quite. ") + slide.explanation));
          });
          face.appendChild(choice);
        });
      }
      if (slide.outcome) { var reveal = make("button", "btn", "What happened"); reveal.type = "button"; reveal.addEventListener("click", function () { face.appendChild(make("p", "", slide.outcome)); }); face.appendChild(reveal); }
      box.appendChild(face);
      var controls = make("div", "slide-controls");
      var back = make("button", "btn", "Previous"); back.type = "button"; back.disabled = index === 0;
      back.addEventListener("click", function () { index--; draw(); });
      var next = make("button", "btn", "Next"); next.type = "button"; next.disabled = index === deck.slides.length - 1;
      next.addEventListener("click", function () { index++; draw(); });
      controls.appendChild(back); controls.appendChild(make("span", "", (index + 1) + " of " + deck.slides.length)); controls.appendChild(next);
      box.appendChild(controls);
    }
    draw();
  });

  document.querySelectorAll(".flashcards[data-cards]").forEach(function (box) {
    var cards = FLASHCARDS[box.getAttribute("data-cards")];
    if (!cards) { return; }
    var index = 0;
    function draw() {
      var card = cards[index];
      box.textContent = "";
      var face = make("div", "card");
      face.appendChild(make("p", "", card.front));
      if (card.hint) { face.appendChild(make("p", "eyebrow", "Hint: " + card.hint)); }
      var answer = make("p", "card-back", card.back); answer.hidden = true; face.appendChild(answer);
      box.appendChild(face);
      var controls = make("div", "card-controls");
      var turn = make("button", "btn", "Turn over"); turn.type = "button";
      turn.addEventListener("click", function () { answer.hidden = false; });
      var next = make("button", "btn", "Next card"); next.type = "button";
      next.addEventListener("click", function () { index = (index + 1) % cards.length; draw(); });
      controls.appendChild(turn); controls.appendChild(next); controls.appendChild(make("span", "", "Card " + (index + 1) + " of " + cards.length));
      box.appendChild(controls);
    }
    draw();
  });

  // The review's cards: each module's key points from REVIEW.
  var reviewCards = document.getElementById("reviewCards");
  if (reviewCards) {
    Object.keys(REVIEW).forEach(function (key) {
      var card = make("div", "review-card");
      card.appendChild(make("h4", "", REVIEW[key].t));
      var points = make("ul");
      (REVIEW[key].pts || []).forEach(function (point) { points.appendChild(make("li", "", point)); });
      card.appendChild(points);
      reviewCards.appendChild(card);
    });
  }

  // Each answer key lists the right answers with their explanations.
  document.querySelectorAll(".key-list[data-key]").forEach(function (list) {
    (QUIZ[list.getAttribute("data-key")] || []).forEach(function (question) {
      var item = make("li"); item.innerHTML = question.q;
      var answer = make("span", "key-answer"); answer.innerHTML = "Answer: " + question.o[question.a];
      item.appendChild(answer);
      if (question.e) { var why = make("span"); why.innerHTML = question.e; item.appendChild(why); }
      list.appendChild(item);
    });
  });

  document.querySelectorAll(".quiz[data-quiz]").forEach(function (box) {
    var key = box.getAttribute("data-quiz");
    var questions = QUIZ[key];
    if (!questions) { return; }
    var form = make("form");
    questions.forEach(function (question, q) {
      var block = make("fieldset", "question");
      var legend = make("legend"); legend.innerHTML = (q + 1) + ". " + question.q;
      block.appendChild(legend);
      question.o.forEach(function (option, i) {
        var label = make("label");
        var input = make("input"); input.type = "radio"; input.name = key + "-" + q; input.value = String(i);
        var words = make("span"); words.innerHTML = " " + option;
        label.appendChild(input); label.appendChild(words);
        block.appendChild(label); block.appendChild(make("br"));
      });
      block.appendChild(make("p", "verdict"));
      form.appendChild(block);
    });
    var controls = make("div", "quiz-controls");
    var check = make("button", "btn", "Check my answers"); check.type = "submit";
    var score = make("span");
    controls.appendChild(check); controls.appendChild(score);
    form.appendChild(controls);
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var got = 0, worth = 0;
      questions.forEach(function (question, q) {
        var chosen = form.querySelector("input[name='" + key + "-" + q + "']:checked");
        var ok = chosen !== null && Number(chosen.value) === question.a;
        worth += question.p || 1;
        if (ok) { got += question.p || 1; }
        var verdict = form.querySelectorAll(".verdict")[q];
        verdict.className = "verdict " + (ok ? "right" : "wrong");
        verdict.innerHTML = (ok ? "Right. " : "The answer is: " + question.o[question.a] + ". ") + (question.e || "");
      });
      var percent = Math.round(got / worth * 100);
      score.textContent = "You scored " + got + " of " + worth + " points (" + percent + "%).";
      // The module or final assessment this quiz belongs to shows its mark on its button.
      var owner = document.getElementById(key === "final" ? "final" : "mod-" + key);
      if (owner) {
        owner.classList.add("done");
        var grade = owner.querySelector(".m-grade");
        if (grade) { grade.textContent = percent + "%"; }
      }
    });
    box.appendChild(form);
  });
}());
</script>
</body>
</html>
```
