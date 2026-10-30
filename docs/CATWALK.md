# CatWalk, the presentation engine

CatWalk plays short, phone-first presentations inside CattoLMS: a module's opening, a course's review, and a course's
trailer on its landing page (owner, 2026-10-28, decision 7A). The original design is the owner's CatWalk specification;
this is what is built.

## Slides are data

A Presentation is a Course Item of type `slide_deck` (shown as "Presentation"). Its `type_config` holds the deck:

```json
{"kind": "intro", "theme": "light", "slides": [
  {"id": "3f9c2a1b7d10", "layout": "title", "title": "Welcome", "kicker": "Module 1", "heading": "Good food starts below the surface", "narration": "..."}
]}
```

- **kind**: `intro` (a module's opening, 5 to 9 slides), `review` (8 to 15), `overview` (4 to 7) or `trailer` (8 to 12,
  ending with a call to action).
- **theme**: `light` (the default in modules) or `dark` (the default for a trailer).
- **slides**: each a `layout` with that layout's fields, plain text only, a `title` of up to 40 characters, and an `id`
  that never changes, which its narration is filed under. Optional on every slide: `chapter`, `tone` (`base`, `alt`,
  `accent`) and `narration` (the narrator's script, which is also the slide's transcript).

The twelve layouts and their fields are `Course\Catwalk\SlideLayouts`: title, statement, points, compare, picture,
number, timeline, steps, case, check (a quick question, never marked), next (hands back to the course, last) and cta
(a trailer's call to action, last). `Course\Catwalk\SlideDeck` shapes (`normalise`), refuses what cannot be played
(`validate`, naming the slide) and gives the course specification's guidance (`warnings`, which never block a save).
A picture is kept in the deck itself, an SVG drawing or a PNG or JPEG data address, so a deck travels whole.

## The editor

A Presentation is edited a part at a time (owner, 2026-10-30, the approved mockup: "Each component needs to be edited
and saved separately"). `Course\Catwalk\PresentationEditor` makes every change, locking the item's row, changing one
part of the stored deck and saving it only when `SlideDeck::validate` accepts the whole; `Course\Catwalk\PresentationPages`
gathers what each page shows; `Http\Controller\AdminPresentationController` serves them.

- **The presentation's page**, `/admin/course-items/{id}` (Course Content's and the Library's Edit Course Item;
  `AdminCourseComponentController::edit` draws it for a Presentation): the preview at the top, at full size; Details
  (name, title, description) with Edit details; the slides as a list or a grid of their pictures (`Support\ListView`,
  `slides`), dragged by a handle (`presentation_slides_controller.js` on the shared sortable tree, one level: up and down
  in the list, left and right in the grid), moved by the keyboard or the ⋯ menu, copied and deleted, each move saved at
  once with the order the page showed (`PresentationEditor::STALE` when it differs); a slide's picture shows it in the
  preview; then where it is placed and its checks (`SlideDeck::warnings` and the recordings).
- **A slide's page**, `/admin/course-items/{id}/slides/{slide}`: the row of slides at the top (`slide_strip_controller.js`:
  first, back, on and last, icons alone), Preview (a window showing what the form holds, saving nothing;
  `slide_editor_controller.js`), Save slide (stays on the page), Close (back to the presentation; `unsaved_changes_controller.js`
  asks first while a change is not saved), the layout drawn with Change layout, the layout's fields, the background
  chosen by the slide drawn in each, and the Narration card. A new slide (`/slides/new?layout=&after=`) has the same page
  and is kept only by Save slide.
- **The layout window**, `/slides/{slide}/layout`, `/slides/new/layout` and `/slides/add`: every layout drawn as a sample
  slide (`SlideLayouts::SAMPLES`, grouped by `SlideLayouts::GROUPS`; no call to action outside a trailer), in a window
  over the page with JavaScript and a page of its own without. From a slide, the choice is sent with the slide's own
  form (`form.picture-choice`'s `form`), so what was typed comes along and the slide is drawn again in the new layout,
  unsaved (`PresentationEditor::inLayout`: fields both layouts share stay, points and steps become each other). Add a
  slide asks where it goes, before an Up next or call to action that ends the slides.
- **The details page**, `/admin/course-items/{id}/details`: name, title, description, key, time to study, and what it
  is for and its look chosen by picture; Save details goes back to the presentation, Close asks first. Below, Make a copy
  (Save As) and Delete, which also removes the recordings' files.

A slide's picture is the slide itself (`DeckRenderer::thumbnail`): drawn 800 pixels wide, inert and never played, and
shrunk by CSS `zoom` (`.cl-slide-thumb--small`, `--medium`, `--large`: 160, 240 or 360 pixels), its buttons drawn as
text. A new Presentation, made from Course Content or the Library, asks only what it is for and its look and starts with
a title slide showing its title (a trailer also ends with a call to action). Checks: `PresentationEditorIntegrationTest`,
`tests/Browser/presentation-editor.cjs` (80).

## The markup and the engine

`Course\Catwalk\DeckRenderer` draws a deck as the engine's markup contract: one `div.cw-deck` of `section.cw-slide`
elements, the words escaped, each slide's narration in `data-cw-audio`, and the host's addresses in
`data-cw-host-links` (`continue`: the next stop in the reader, `enrol` and `preview`: the course page and its free
preview). Without JavaScript, and in print, the deck reads as one page.

The deck's top bar (`data-cw-title`) shows the Presentation's description, so the page's heading is not printed twice
(owner, 2026-10-30): one plain line of up to 100 characters (`SlideDeck::description`, `SlideDeck::barText`), or the
title while there is none. A placement's description override takes its place, and the description is never printed
above the player.

`public_html/js/catwalk.js` and `public_html/css/catwalk.css` are the engine, loaded only on a page that shows a deck
(`load_catwalk`). Nothing in them is CattoLMS's own, so the same files can play a deck elsewhere. The engine:

- shows one slide at a time, in a box that keeps 16:9 on a wide screen and grows to fit on a phone;
- reveals a slide's points one at a time with Next (`.cw-step`);
- turns a quick question's options into buttons, marks the answer and opens the explanation;
- has a progress bar by chapter, Previous and Next, a counter, Contents, Full screen (with a whole-window fallback
  where the browser has no full screen), Narration (when a slide has a recording) and Transcript;
- answers keys only while focus is in its deck (arrows, Page Up and Down, Space, Home, End, F, M, Escape), and swipes;
- follows reduced motion;
- announces each slide and answer to screen readers, and makes hidden slides and steps unreachable;
- sends `cw:ready`, `cw:slide`, `cw:complete`, `cw:check` and `cw:action` events, and nothing over the network.

`Catwalk.mount(deck, {links, startAt})` and `Catwalk.mountAll(root)` mount decks by hand; every deck is mounted when the
page loads and after an htmx swap.

## Narration

Each slide's narration is recorded on the slide's page (its Narration card: the microphone,
`public_html/js/narration-recorder.js`, MediaRecorder) or uploaded as an audio file (WebM, Ogg, MP4, MP3 or WAV, up to
20 MB), and kept by `Course\Catwalk\Narrations` in `course_slide_narrations` with its file in
`storage/course-narrations/`. The slide's Narration box shows what is saved apart from a new take: a take, recorded or
chosen, is heard first, then kept or discarded. Keep saves it at once and Remove the narration deletes it at once
(`/admin/course-items/{id}/narration/{slide}` and `.../remove`), so neither waits for Save slide. The script is saved on
its own by Save the script, in place (htmx, `/slides/{slide}/narration`); without JavaScript that form posts, an
uploaded file going with the script, and Remove sends it. Leaving the page with a recording nobody
kept asks first. A new clip replaces the slide's old one, and a slide removed from the deck takes its clip with it. `/narrations/{public_id}` plays
a clip to whoever may see its Presentation: anyone where it is public (a public preview placement, a published landing
page), otherwise the course's authors and learners who have reached it. A catalogue package carries the clips in
`narrations/`.

## Where a deck appears

- In Course Content, as a module's first part (`CourseItemRenderer`), or embedded in a lesson with `[course-item:key]`.
- On a landing page, as a Presentation section that plays one of the course's presentations, usually its trailer.
- In the editor: the presentation's preview at the top of its page, a slide's Preview window, and every slide's picture.

The engine's two files depend on nothing in CattoLMS, so the same files play a deck on any page.
