# Changelog

What each release brought. Releases are tagged in the repository and published on GitHub with a source zip; versions before v0.8.6 are listed by tag only.

## 2026-10-30 — v1.0.2 The Page Builder for course pages, and the landing page's hero

### Installing and updating

- **A clean installation is needed.** The baseline schema gains `courses.page_layout`, a course's own page layout.
- **No new `.env` settings, packages, scheduled jobs or legal text, and nothing removed; the bundled Saharan Sunset is now 1.0.7.** New: a section of Settings, Course pages, and a tab on each course, Page layout. Saharan Sunset 1.0.7 replaces 1.0.6's landing hero across the window.

### The course page

- **The course in rows.** Under the head, which is unchanged: About this course beside How each module works, the modules across the whole width, At a glance beside What you get, then the reviews, Course access across the whole width just above Ready to start, and Related courses. Two cards side by side are the same height.
- **The Page Builder.** A course's page is laid out block by block: each of About this course, How each module works, the modules, At a glance, What you get, Reviews, Course access, Ready to start and Related courses has a place, is half or full width, and is shown or hidden (Course access is always shown, being the only place to buy). Two half blocks side by side share a row. Every course uses the default at Administration › Settings › Course pages until it is given a layout of its own on its Page layout tab, where Reset puts it back on the default. Three templates to start from: Course first (the rows above), Buy first and Reviews first. A block is dragged by its handle or moved with its arrows, made half or full width, hidden to a shelf or shown again, beside a sketch of the page as visitors see it; Save layout keeps it, and leaving with changes not saved asks first. Without JavaScript every button works and saves at once.
- **A course package's catalogue block carries the course's own page layout,** so a catalogue imported on another site gives each course its layout, a course already there included.

### The landing page's hero

- **The hero is built as the course page's head is:** as wide as the cards below it, the hero picture (the course's cover or a Resource Library picture) filling it, and over it the category and level, the headline, the supporting text, the rating and the optional second button. A hero without a picture shows those words in the page-head band.
- **No buy box in the hero.** Buying is in Price and access and the call to action.

## 2026-10-30 — v1.0.1 The course structure, CatWalk presentations and the presentation editor

### Installing and updating

- **A clean installation is needed.** The baseline schema changed: the item types Presentation, Flash cards and Practical work, each Course Item's name (`course_items.name`, unique) and time to study (`course_items.estimated_minutes`), numbered module sections (`course_sections.numbered`) and a course's first module number (`courses.first_module_number`), each slide's narration (`course_slide_narrations`), the landing page's Presentation section, the review email's record (`course_enrolments.review_requested_at`) and each course video's check (`course_video_checks`). A v1.0.0 database cannot be updated in place: install v1.0.1 on an empty database, then import your catalogue package at Administration → Courses → Import & Export Catalogue.
- **No new `.env` settings or packages; the bundled Saharan Sunset is now 1.0.6.** Narration clips are kept in `storage/course-narrations/` and course part PDFs in `storage/course-pdfs/`, beside the other storage folders.
- **New scheduled jobs:** `review_requests` (every hour) and `video_check` (every day). The commerce worker runs them; restart it after an update.
- **The Podman kit no longer stops on a file it does not own.** Building the site set the permissions of everything in `storage/`, and stopped at a file another user had written there, such as the GeoIP databases kept up to date by root's `geoipupdate` in `storage/geoip`. It now sets them only on what the site's user owns.
- **A new console command** for scripts, `course:arrange-modules`, does what Arrange into modules does on Course Content.
- **Outside sources:** the `video_check` job asks YouTube's oEmbed address (`https://www.youtube.com/oembed`) about each course video, sending only the video's id. CatWalk sends nothing over the network, and a module's Video part is the YouTube item that already existed. Recording a slide's narration uses the administrator's microphone in their own browser.
- **Changed legal text:** the Privacy Policy (dated 28 October 2026) adds asking a learner once, by email a few days after they finish a course, to review it.
- **Removed:** the one-page Presentation editor (every slide on one form) is replaced by the presentation editor below, at the same address.

### The course structure

- **A module is a numbered section** ("This section is a module: number it"), read as "Module N: title" in the outline and on its pages, holding its parts in order: Presentation, Video, Lesson, Summary, Flash cards, Practical work and Assessment. The outline shows each part's kind and time, and each module its total.
- **Time to study** on every item, or worked out from its size: about 25 seconds a slide, 45 a flash card, 90 a question, and a lesson at 120 words a minute.
- **Flash cards:** a set of up to 60 cards, worked through one at a time (Turn over, I knew it, Again) until every card is known, nothing recorded; a list without JavaScript; a pasted list in the editor.
- **Practical work:** tasks with a model answer behind "Show the model answer".
- **PDFs** of the lesson, summary, review study aid, flash cards and practical work ("Download as PDF"), with drawings printed as pictures.
- **Arrange into modules** on Course Content turns a course of the older shape (one lesson per module) into module sections in one step: outcomes into the section's introduction, the video, the summary and the practical work into parts of their own, the assessment after them. An HTML import is arranged as it is imported, and an arranged course whose lessons still end with their summary is offered "Move the summaries into parts of their own".
- **Module 0.** A course whose first module sets it up (a practice environment and a readiness check) numbers its modules from 0: "Number the modules from" on the course's Details tab.
- **A video's start time**, for a long video of which only part is relevant, on the YouTube item's form.
- **A trailer stays with visitors.** A trailer presentation plays on the landing page and in the public preview, but is never in the outline of someone who has the course.
- **Course package 3.0** (`docs/COURSE-PACKAGE.md`): a zipped folder of `course.json` and a folder per module, imported at Import course after being checked against its schema, every error named. A worked example, GDN-101, is in `tests/Fixtures/course-package/`.

### Course Items and lists

- **Course Item names.** Every Course Item has a name only administrators see, never used twice, such as "GDN-101 Module 1 Presentation", which the Course Item Library, Add existing item, Course Content and a landing page's choice of presentation show and the Library's search finds. Left empty, it is named after its place (the course's code, the module and the part); imported items and the parts Arrange into modules makes are named that way. Learners still see the title.
- **Course Item types by their names.** The editor is "Edit Course Item: " and the item's type (such as "Edit Course Item: Presentation"), opened by "Edit Course Item" and saved by "Save Course Item", and every page names a type the same way (Presentation, Flash cards, Video (YouTube)), never by its stored code ("slide deck"). Import HTML course is now Import course, since it also takes course packages.
- **List and grid views change in place.** The List and Grid buttons above a list (People, Companies, Course Creators, Courses, the catalogue, the Company workspace's People, Subscribers, a company's people and courses, and a presentation's slides) change only the list, without loading the page again.
- **Course Content's Cancel** under Edit section and Placement settings closes the form where it is and puts it back as it was saved, without loading the page again.

### CatWalk presentations

- **A Presentation** is a Course Item of slides kept as data, in twelve layouts (title, statement, points, compare, picture, number, timeline, steps, case, quick question, Up next, call to action), played by CatWalk (`docs/CATWALK.md`): one slide at a time on any screen, points revealed one by one, quick questions marked at once and never graded, contents, full screen, a transcript, keys that answer only in their own deck, swipes, and reduced motion followed. Without JavaScript, and in print, it reads as one page.
- **The presentation editor**, a part at a time: the presentation's page plays it at the top, at full size, shows its details (with Edit details) and its slides as a list or a grid of their pictures, dragged by a handle or moved by the keyboard or a ⋯ menu, each move saved at once, copied and deleted, then where it is placed and its checks. Each slide has its own page: the row of slides at the top with icon buttons to scroll it, Preview in a window (saving nothing), Save slide, Close (which asks first when changes are not saved), Change layout in a window where every layout is drawn as a sample slide (keeping what was typed and the fields both layouts share), the background chosen by picture, and its narration. Add a slide chooses the layout by picture and where it goes; the new slide is kept only by Save slide. The details page has the name, title, description, key, time to study, what it is for and the look (both chosen by picture), Make a copy and Delete. A new presentation asks only what it is for and its look, and starts with a title slide.
- **Narration:** each slide's narration recorded on its page with the microphone, or uploaded (WebM, Ogg, MP4, MP3 or WAV, up to 20 MB), and played with the slide when the learner turns narration on. A new recording or chosen file is heard first, then kept or discarded; Keep and Remove act at once, and the script has its own Save the script. Clips are heard only by those who may see the presentation, and travel in catalogue packages.
- **A presentation's description** is one plain line of up to 100 characters, shown in the player's top bar in place of the title, which the page's heading already shows.
- **A landing page's Presentation section** plays one of the course's presentations, such as its trailer, ending with Get this course and Preview the course.

### The course page

- **A course's page helps someone choose it** (owner, 2026-10-30, the accepted mockup). It lists the course's modules, numbered, with each one's study time and the free preview marked, not every part of every module (a course of 17 modules had listed 123 items), with one paragraph on how a module is made, worked out from the parts the modules hold, and the parts drawn as a strip; then what the course holds, counted (presentations, videos, flash cards, practical tasks, questions); then its reviews, with Course access (the one buy box) and At a glance beside them, and the free preview offered again at the end. **Watch course presentation**, under the cover, plays the course's trailer in a window as wide as the page's box, closed by its Close button or Esc; without JavaScript it opens the trailer in the free preview. The head is the cover with the title and the favourite star, as before; the description is no longer printed twice, and the counts of Course Items and questions are gone. A landing page's Course contents section shows a course of modules the same way.
- **Windows:** a page-wide size (`overlay.modal` `size: 'page'`); a link inside a window to a place on the page closes the window first; an Esc that something inside the window has already used (a presentation closing its Contents) leaves the window open.
- **A module's review study aid is a part of its own.** A module lesson that ends with a Review Study Aid block (`details.review-aid`) now has it lifted into a Review study aid part, placed before the module's assessment and opening with its subtitle: on every HTML import, and for a course already arranged through Course Content's "Move the review study aids into parts of their own". The outline labels the part Review study aid, and it downloads as a PDF.
- **Course packages carry Course Item names.** A course or catalogue package now holds each item's name, and an import keeps it (with the first free number after it when another item already has it). Items that arrive without a name, as from an older package, are still named after their places.

### Reviews and videos

- **Asked for a review when they finish.** The final assessment's result page holds the review form, and saving it comes back there. Three days after completing a course, a learner who has not reviewed it is emailed once with a link to rate it, never at an address on Addresses never emailed.
- **Course videos are checked every day.** A video YouTube says was removed, is private or may not be played on other sites is listed under Needs your attention, with a button to its item, until it plays again.

### Fixed

- **An administrator's course preview named ten years** on its start page ("starting begins my access period of 10 years"). Every preview enrolment had a fixed ten-year period, there since the first version and shown since v0.9.4 named the period. A preview now runs for the period a buyer gets: the course's default price option, else the course's default access period. A preview not yet started follows price changes.
- **Start course took two clicks.** It returned to the course page, where Begin course had to be clicked as well. Start course now opens the course's first part, and Begin course, for a learner coming back, leads to the same part (it had pointed at the first row even when that row was a heading with nothing to show).
- **Start course's wording** names whose refund starting ends: the learner's own for a course they bought, their company's for a place from its credit, and none for a course given to test or an administrator's preview.
- **A picture slide fits its slide.** On a wide screen a tall diagram pushed its heading and caption out of the slide, and a slide fuller than its box lost its top. A picture now takes the room under its heading, and on a phone it is sized by its width.
- **Line breaks doubled by an HTML import or Arrange into modules.** Both parse a lesson without the HTML namespace and wrote it straight back, so every `<br>` came back as `<br></br>`, which a browser shows as two line breaks, doubling again each time a lesson was arranged; a picture gained a closing tag and a script's or style's text would have been escaped. They now write lessons back through `CourseHtml::html`, which matches a normal HTML parse exactly. A course imported or arranged with v1.0.1 keeps the extra breaks until it is imported again.
- **Saving a review study aid in the Course Item editor made it a plain lesson.** The editor showed its role, but saving dropped any role other than the lesson and the summary. A review study aid is now one of the roles a lesson may have.
- **A landing page's hero on a wide screen.** The hero runs across the window like Home's, its words and picture in the page's frame. On screens wider than the frame they had stood in a narrow column in the middle (the theme padded a band that Core had already narrowed to the page's frame).

## 2026-10-28 — v1.0.0 Mailing, the sign-up check, South African ID numbers, overview pages and drawn pictures

### Installing and updating

- **A clean installation is needed.** The baseline schema changed (mailing, sign-up attempts, ID numbers, company departures and logos, drawn avatars, and a company that always keeps a Company Administrator), and a v0.9.11 database cannot be updated in place. Install v1.0.0 on an empty database, then import your catalogue package at Administration → Courses → Import & Export Catalogue.
- **New `.env` settings** (each overridden by its Settings page, with a Reset): `MAILING_FROM_ADDRESS`, `MAILING_FROM_NAME`, `MAILING_LINK_BASE`, `MAILING_TRANSPORT` (`smtp` or `cattomail`), `MAILING_DSN`, `CATTOMAIL_API_BASE_URL`, `CATTOMAIL_API_KEY`, `CATTOMAIL_WEBHOOK_SECRET`, `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS`, `SIGNUP_ALLOWED_COUNTRIES`, `SIGNUP_BLOCKED_COUNTRIES` and `LOG_ARCHIVES_KEPT_FOR` (2w). The catto-mail key and secret normally come from connecting it on Mailing › catto-mail rather than from `.env`.
- **Lengths of time are written one way.** `AUTH_MAGIC_LINK_TTL=30m`, `AUTH_SESSION_TTL=1d`, `AUTH_SESSION_TOUCH_INTERVAL=5m`, `AUTH_REQUEST_WINDOW=15m` and `PHP_SESSION_TTL=1d`: y, w, d, h, m (minutes) and s, largest first, no spaces. A plain number of seconds is no longer read (the default is used); the install wizard turns an older site's seconds into the new form.
- **New packages:** `dicebear/core` 10.7 and `dicebear/styles` 10.6 (MIT; the avatar styles CC0 1.0), drawing avatars and logos on the server.
- **Outside sources.** Country and network lookups read GeoLite2's country database and, beside it, its ASN database (`GeoLite2-ASN.mmdb`). The sign-up check's lists are fetched by the new `signup_lists` scheduled job into `storage/signup-lists/`: Tor exits (check.torproject.org), VPN and hosting networks (X4BNet's lists) and throwaway email domains (disposable-email-domains). Email templates designed with the Cormorant Garamond or Jost fonts link to Google Fonts in the email itself. News can be sent through catto-mail's API.
- **New scheduled jobs:** `mailing` (every minute), `signup_lists` and `log_archive` (once a day, just after midnight). The commerce worker runs them; restart it after an update.
- **Removed:** `/account/confirm-age` (replaced by `/account/id-number`), `/account/email-preferences` (replaced by `/account/subscriptions`), the Company workspace's Remove from the company (replaced by Delete, below), `tools/seed-generate.php`, `tools/seed-benchmark-dataset.php`, `tools/generate-seed-words.php` and the bulk random seed. `composer seeds:generate` now runs `seed:run`. Old addresses are not redirected.
- **Changed legal text:** the Privacy Policy (dated 28 October 2026) says what registration checks keep, that the ID number is kept encrypted, the country of each sign-in, every email choice and how updates reach each person, the news emails' records, emails forwarded to a friend, and which themes load web fonts.

### Mailing

- **A Mailing menu of its own:** Overview, Digest, Messages, Templates, Lists, Subscribers, Addresses never emailed, Automatic emails, Sending settings, catto-mail and Reports. Nothing is sent to anyone who did not say yes, and every email carries an unsubscribe link and a one-click unsubscribe for email apps (RFC 8058).
- **Following.** People follow a course, category or tag with its Follow button, say yes to News and Updates when registering or at checkout, follow the courses they buy, and join the lists an administrator makes. Following needs an account: signed out, Follow explains that and carries the follow through registration or signing in. The first Follow asks how the person would like their updates.
- **Updates and digests.** The site records course changes as updates (a course published for the first time, lessons or sections added, a price lowered, a promotion starting, a landing page published, a course retired; each can be switched off), and an administrator can write one for any list. Updates are held on Mailing › Digest until an administrator releases them, now or at a date and time. Each person chooses to get updates as they happen, in a digest for each thing they follow, or in one digest with everything, daily, weekly or monthly. Nobody gets an update twice. An update nobody follows can be posted to News and Updates.
- **Messages** are written in blocks (heading, rich text, picture, button, course, divider, HTML) in a template, with their own sender, reply-to, lists to leave out, a most-to-send number and their own plain-text copy; previewed, proofed to any address, sent now or at a time, paused, resumed and cancelled, with each copy's and each link's figures.
- **Templates:** designed in a form (logo, header picture, colours as hex codes with a live swatch, fonts, width, corners, spacing, buttons, footer words, social links) or written in HTML with placeholders, with six sample designs to start from or borrow a look from, a live preview and proofs.
- **Lists** an administrator makes: shown on My Subscriptions or joined only by their join link, each with a welcome email, its own sender, reply-to and template, and closing. **Subscribers** as a table or a grid, each with an Actions menu (their subscriptions, their account, never email the address) and a page of their lists, the emails they were sent and their history.
- **Account › My Subscriptions** replaces Email Preferences: how and how often updates come, News and Updates, what is followed, lists to join, the emails sent (each with its web copy) and Stop all email.
- **Every email** has "View it in your browser" and "Forward to a friend". A friend gets the one email, with a button to create an account and "Never email me", and is put on no list (at most 5 friends from one email and 10 forwards a day from one person).
- **Sending discipline:** sending hours for everything the mailing system sends (07:00 to 20:00 to start), an hourly limit and a gentle first day that doubles daily, a sending that pauses itself above the bounce or complaint limits, hard bounces and spam complaints never emailed again, and addresses that have opened nothing for a year asked once whether they still want news. Account and order emails are not affected and always go at once.
- **Automatic emails** and **Reports**: the words of the update emails, digests and "Do you still want our news?" (which can be switched off), and a period's emails sent, opened, clicked, bounced, marked as spam, unsubscribed and forwarded, by kind, message, list and link.
- **Sending:** through a mail server (SMTP, with List-Unsubscribe, List-Id and Feedback-ID) or catto-mail. Mailing › catto-mail connects it in four steps: its address, Connect (catto-mail's own approval page, OAuth 2 with PKCE, after which the key and webhook secret are kept encrypted), the sending domain ready (news waits until catto-mail can send from it), and a test. It checks the connection, disconnects, and waits until a used-up quota resets.

### Registration and sign-in

- **South African ID numbers.** Registration asks the country you live in and a valid South African ID number (13 digits, a real date of birth, a citizen or permanent resident, its checksum), which gives the date of birth; anyone under 18 is refused, one account per number. The number is kept encrypted with a keyed fingerprint and shown masked. An account without one gives it at its next sign-in. Registration is open to people living in South Africa.
- **The sign-up check.** Every registration is scored (country, Tor, VPN and hosting networks, throwaway or mail-less domains, a random-looking address, a script browser, speed, time zone, repeated sign-ups, a made-up name, a hidden field). A throwaway address is refused, a bot is shown the usual page and sent nothing, a blocked country is held for approval without being told why, and on a live site so is a high-risk registration. Administration → People & Companies → Sign-up Attempts, risk badges, each person's sign-in countries and a first sign-in from a new country on Needs your attention. Settings > Sign-up checks sets the allowed and blocked countries.
- **Demo sites** say, before and after registering, that the account waits for approval; registration has its own "Finish your registration" email.
- **An administrator can no longer disable their own account** or remove their own Platform Administrator role.
- **GeoIP** location works again in Activity (it had read no database since the move to Symfony).

### Companies and people

- **The company domain rule.** A company has its own domain, never a webmail one, and a person's company follows their primary address: an address at a company's domain makes them its staff, any other a private account. Registering or adding an address at a company's domain is refused to anyone but its staff. Changing a company's domain warns first, naming every staff address it moves.
- **A company always has an active Company Administrator** (the database refuses a change that would leave none), and Add Company asks for its Company Administrator.
- **Delete** replaces Remove from the company: never someone who completed a course; someone busy with a course is emailed a 3-day link to keep their account as a private one at a new address or delete it, and otherwise is deactivated with everything kept; someone who started nothing is deleted. Restore at a new address; Delete the company on Company Profile.
- **One-page overviews.** A company's page in Administration (logo and badges, what waits, figures, people and courses as a grid or list, its staff's learning, credits, orders, wallet, billing, activity) and a person's page (summary, particulars, company and roles, courses, account, sign-up risk, recent sign-ins, age and identity), each with its actions on the page.
- **The company logo** in the company band on every Company page, large on the Dashboard, beside each name in Switch company, and on Company → Company Profile.
- **Drawn pictures:** a person without a picture gets a drawn avatar and a company without a logo a drawn picture, made on the server (Show me another, Back to my first one).
- **Grid or list:** People, Companies, Course Creators, Courses, Subscribers and the catalogue as a table or a grid of cards, remembered per list.
- **Company › Orders** for every Company Administrator; a credit's length is one of its course's own; unused places of a credit given by hand can be taken back.

### Also

- **Addresses** choose the country by name from every country, and the province from South Africa's nine or typed for another.
- **Colours** are typed as hex codes with a swatch that follows as you type, on Financial Documents, certificate designs and email templates.
- **Log archive:** every log is archived and started afresh just after midnight, and archives older than two weeks are deleted (Settings › Maintenance).
- **Lengths of time** everywhere are worded one way ("1 hour 30 minutes"); a month is 30 days and each whole year 365 (6 months 180 days, 18 months 545).
- **Record EFT Payment** reads a reference written with spaces correctly (about one in 37 was misread).
- **Course landing pages** have nine kinds of section: the Instructor section is gone.
- **Seed Database** (Administration → System) makes demo data through the site's own workflows with real names: companies registered with their Company Administrator, staff, purchases, payments, assignments, requests, assessments, reviews, refunds and contact messages.
- **Fixes from an audit of the administration pages,** among them: a person's page showing every company member as private and ending their membership on saving, Add person taking over an existing account, a company founder not counted as a Company Administrator, Restore access giving back a place someone else held, a refund from the order page leaving its request open, Company Credits without a way to assign a place, Owner & editors listing only 50 people, Replace on import clearing a course's category, and theme Uninstall always "not found".

## 2026-10-24 — v0.9.11 PostgreSQL 18

- **Fixed on PostgreSQL 18: a delete refused by a foreign key.** PostgreSQL 18 reports a delete refused by an `ON DELETE RESTRICT` foreign key with SQLSTATE 23001 (restrict_violation), where 16 reported 23503 (foreign_key_violation), and Doctrine DBAL 4.4 recognises only 23503. Deleting a promotion or a bundle that orders refer to was therefore a server error on 18 instead of "it is kept for their history". Both database connections now report 23001 as the foreign key violation it is (`Infrastructure\Persistence\RestrictViolationMiddleware`), on every PostgreSQL version.
- **PostgreSQL 18.6.** CattoLMS is developed and tested on PostgreSQL 18.6, the version Ubuntu 26.04 installs. It still runs on PostgreSQL 13 or later. A development pod moving from 16 to 18 needs a dump and a restore: the official 18 image keeps its data in `/var/lib/postgresql/18/docker`, so its folder is mounted at `/var/lib/postgresql`.
- **Fixed: a dashboard amount past its card.** A small stat card's amount (the money figures) is fitted again whenever the amount or its card changes size, whatever the cause, so a web font arriving late can no longer leave it running out of its card (it was seen once in Gilded Noir's Net money). Before, it was fitted only when the fonts and the page had loaded.
- **A test that ran out of time.** A wallet test froze its clock on 17 October but opened an order page that runs on the real clock, so its order fell overdue a week later and the test failed. Its clock now starts at the real time.
- **Updating.** The schema is unchanged: update a v0.9.10 site in place.

## 2026-10-24 — v0.9.10 A cache folder per release, so an update never stops the site

- **Fixed: updating a running site stopped.** The Podman kit's `update` of a running site stopped at its first console step with "Cannot redeclare interface ... HttpKernelInterface": every release shared one compiled cache, `storage/cache/symfony/<environment>`, and a compiled container names the files of the code it was compiled from, so the new release loaded the running release's. Deleting the folder first would not help, because the running site builds it again within seconds.
- **A cache folder per release.** The kernel keeps its cache in `storage/cache/symfony/<release>/<environment>`, the release being its code's folder name (`0.9.10`) and 8 hex digits of its full path. A release being installed builds its own and never meets the running one's, so the site keeps running while an update builds, until the restart that switches it, which takes about a second. Going back to the release before uses its own folder, or builds it again.
- **The installers.** `cattolms-podman.sh` and `install/install.sh` clear only the new release's folder, before its first console step, and never the running release's; the kit deletes the folders of the releases it deletes and the one releases before v0.9.10 shared. Fetch the kit script again before updating from v0.9.9 or earlier.
- **Tested** on a throwaway kit site receiving a request every half second: updating it with v0.9.9's code stopped as on the server, the site still running the old release; updating it with this code, from v0.9.9's layout and then again, succeeded with the site running, and it answered every request but those of the second or so of each restart that switched the release.
- **Updating.** The schema is unchanged: update a v0.9.9 site in place. The cache of the old layout is deleted by the kit; on a site installed with `install/install.sh`, `storage/cache/symfony/<environment>` can be deleted after the update.

## 2026-10-24 — v0.9.9 Who can sign in from .env, APP_ENV alone, and the Podman kit's shared packages

- **Who can sign in from `.env`.** `AUTH_SIGNIN` is `ADMIN` (administrators and the listed addresses only) or `EVERYONE`, in either case; with no value, any other value or no `AUTH_SIGNIN` at all it is `ADMIN`. `AUTH_SIGNIN_ALLOWED_EMAILS` lists the addresses, separated by commas, and is empty when it is missing. Administration → Settings → Who can sign in overrides both, as Settings overrides every `.env` value, says where the choice comes from, and Reset who can sign in to .env returns to them. A new site starts with `AUTH_SIGNIN=ADMIN`. A site with nothing saved in Settings and no `AUTH_SIGNIN` now admits only administrators: to keep it open, set `AUTH_SIGNIN=EVERYONE` or choose Everyone in Settings.
- **How the program runs is `APP_ENV` alone.** `development`: a server error shows its reason, templates are rebuilt when they change and every database query is logged. `production`: none of that. A site is production only when `APP_ENV` says `production`, in either case; anything else, an empty value or no `APP_ENV` is development. `APP_DEBUG` is retired: debugging follows `APP_ENV`, nothing reads it (a test fails if anything does), and `.env.example` no longer has it. The web request, the console and the tools read it in one place (`Configuration\RunMode`); they had three different defaults. `APP_SITE_MODE` still says whether the site is real.
- **Installs never change `APP_ENV`.** The setup wizard (both installers) wrote `APP_ENV=production` and `APP_DEBUG=false` on every install since v0.9.6. It now keeps the site's existing value, a new site starts as `development`, and only the site's owner changes a site to production. It drops an old `APP_DEBUG` line and checks `AUTH_SIGNIN`.
- **Expired forms.** A form sent without its token, or with an expired one, answers "That could not be done" (400) and says to reload the page and send it again, on every form, never a server error.
- **The Podman kit.** A new release's `vendor` starts as hard links to the running release's, so a package both use takes its space once and Composer replaces only what changed; an update keeps the running release and the one before it and deletes older ones with their image's name; the pod's web server waits 180 seconds for PHP, as the server's proxy does. The guide puts the proxy's timeout and upload limit in the server block, notes what a hosting panel's proxy page rewrites, and says to fetch the script again before an update.
- **Tests.** No test depends on which certificate design is the site's default: they use the design the course has, and the installation test checks that a new site starts with Classic. The test suite runs with `AUTH_SIGNIN=EVERYONE`, and the sign-in tests set their own values.
- **Updating.** The schema is unchanged from v0.9.8: update a v0.9.8 site in place. Remove `APP_DEBUG` from its `.env` (it is ignored) and add `AUTH_SIGNIN` and `AUTH_SIGNIN_ALLOWED_EMAILS` if it should not rely on the defaults.

## 2026-10-23 — v0.9.8 Import & Export Catalogue, course codes and course packages 2.1

- **Import & Export Catalogue.** Administration → Courses → Import & Export Catalogue moves courses between sites in one file from the web app, with no console command. Export: tick the courses to export (Tick every course, Tick the published courses and Clear the ticks help; they tick in place, or reload the page with the boxes ticked without JavaScript, and the count follows the boxes ticked by hand), then download a catalogue package: a zip of `catalogue.json` (only the ticked courses' categories, each with the categories above it, and their tags, with their icons), one course package per course and the Resource Library files they use. A related course link is kept only between ticked courses. Download their categories and tags gives the ticked courses' categories and tags, or every category and tag when none is ticked.
- **Importing a package.** Upload a package (or a catalogue file of categories and tags) and review what it would do, changing nothing: every course new, updated, unchanged or left out with the reason, each with a tick to leave it out, and Replace its content for a course already on the site that nobody has started. Import then brings the categories and tags, the files (with the public ids they had) and each course: a new course with its code, category, tags, cover, position, the package's prices and, published where it came from, published; a course already here gets the package's tags, cover and position, its category only when it has none, never its prices. Related courses are added once every course is in. Each course is imported on its own, so one that fails is reported and the rest go in; the same package again changes nothing. A result page lists what was done.
- **Course codes.** Every course has a code such as GDN-101: capital letters and digits in parts joined by hyphens, unique on the site and the same on every site. A course HTML file names it in its head (`<meta name="cattolms:course-code" content="GDN-101">`), the import review shows it or suggests one from the title, the Details tab and Create course show it beside the title, and Administration → Courses lists it in a Code column and finds a course by it. Every import matches a course by its code alone, never by its title or address. A course made by a script gets a random `C-` code.
- **Course packages 2.1.** A course's JSON package (`catto-learning-course` 2.1) carries its code and its place in the catalogue (category, tags, cover, position, related courses by code, status and prices). Importing one on its own preselects its category in the review and gives a new course its prices; 2.0 packages still import. A package may hold a course with no content yet.
- **The catalogue file.** `catalogue.json` is version 2: categories and tags only, with the courses' codes in a package. The starter file published with the code is version 2. `catalogue:import` and `catalogue:export` use the same package code for scripts.
- **The setup wizard.** Its catalogue step opens Import & Export Catalogue, and no step names a console command.
- **Order links opened by someone else.** A link in a buyer's email opened by ADMIN refused with "403 Request failed This order belongs to another purchaser". Someone who manages orders now lands on the same order, or the same invoice or receipt, in Administration, told whose order it is. Anyone else, and anyone opening an order that does not exist (which failed as an unexpected error), finds no such order in their account: nothing says the order exists or whose it is.
- **Errors in plain words.** The error page is built like every page: a title that says what happened (We could not find that page, You can't open this page, Something went wrong on our side), the reason in plain words, what to do next (Go to the home page, Browse the catalogue, My courses, Go to your account, Sign out, Try again, Contact us) and the status code in small print. A signed-in reader keeps their header and identity band; the page showed them as signed out.
- **Dashboard amounts fitted after late fonts.** A money figure is fitted to its card again whenever a web font finishes loading and once the page has loaded, so a theme's font that arrives late cannot push it past its card (seen once in Gilded Noir).
- **A clean installation.** The baseline schema gains the course code, so a site moves to v0.9.8 with a clean installation, as with every release. Its courses can then be loaded from a catalogue package made on another v0.9.8 site, or one by one from their HTML or course JSON files (a v0.9.7 course JSON export imports, given its code at the review).
- **Gate.** `composer qa` from a cleared cache (1,337 PHPUnit tests, PHPStan, the architecture, runtime, UI-contract and release checks), Twig lint (298 files), AssetMapper compilation, Composer validation and `git diff --check`; in the browser, the menu pages in every theme (696 views), every page (558 checks), UI stabilisation, canonical (36), the safety net, choosing the courses to export (9, Chromium, Firefox and without JavaScript), legal pages (77), order links and error pages (12) and financial documents (9), all passing. Earlier the same day the one-learner walkthrough ran on a fresh site installed with `deploy/podman/`, importing a catalogue package through the page (11 steps, all passing).

## 2026-10-22 — v0.9.7 Rootless Podman, PHP 8.5.12, dashboards with icons and periods, reviews in their own card

- **Install with rootless Podman.** `deploy/podman/` runs one site as its own Linux user in a pod of three containers (the web server, PHP-FPM and the scheduler) behind the server's own web server, with the server's PostgreSQL and mail: `root-prepare.sh <user>` once as root, then `cattolms-podman.sh install --version X.Y.Z --port N` as the site's user, and `update` for each release. The code is read-only in every container and the pod is published on 127.0.0.1 only, so a break-in through PHP cannot change the code or reach the rest of the server. The guide is `deploy/podman/README.md`; `install/install.sh` stays for running the site with the server's own PHP.
- **PHP 8.5.12 or later.** 8.5.12 fixes two remote code execution flaws, CVE-2026-109618 and CVE-2026-109619: `composer.json` requires it, the installers refuse anything earlier and the Podman image is pinned to it.
- **Dashboards.** Every dashboard figure has an icon and opens what it counts, four or five to a row (five falling to three, never four and a lone card). The Administration dashboard's money has one Period drop-down for everything beneath it (Today, Past week, Past month, Past 3, 6, 9 and 12 months, Year to date, Past 2 years, All time, and Dates), three labelled groups (Money in and out, Wallet credit, Orders), each money figure compared with the period before, money in as a column for each hour, day, week or month of the whole period (the chart showed only the days with money) and money in by method with each method's share and payments; each column and method opens the Money Ledger for it. Money amounts are drawn smaller and never run out of their cards.
- **An icon on every button.** Every button and button-styled link shows an icon that fits its action, table rows and Actions menus included (66 new symbols, the "Action icons" table in `docs/UI-COMPONENTS.md`).
- **Needs your attention lists everything.** Every account waiting for approval, review, request, message, money item, identity check and draft is listed; it stopped at 20 accounts and 100 of each other kind while saying how many were waiting.
- **The People search.** It finds a person by a middle name and by any of their email addresses too, in Administration and the Company workspace. Find a person is gone: the People search finds everyone it found.
- **Pagination.** The rows drop-down stays whenever a list is longer than the smallest page size, so choosing more rows than a list holds can be undone; one page states its rows (`1–22 of 22 · Pg 1 of 1`); a long list's numbered pages give way where the bar would not fit its line.
- **The account reference labelled.** The identity band's account reference sits under the label "Account Reference:"; a header chip shows it only where it stands in for the band.
- **A course's reviews in their own card.** On a course page the reviews sit in their own card under Course access: each number of stars is a filter, with View all reviews while filtered; the reviews are numbered, newest first; no pagination bar without a second page.
- **A course that was bought is never deleted.** Its orders, invoices, receipts and ledger lines are kept for good, so deleting it says why and offers retiring it instead (deleting failed with a database error); a course in somebody's cart now deletes.
- **Fixed: a message lost when two requests overlapped.** A request that changed nothing in the session wrote its copy back anyway, so the page script's palette request, finishing after a form sent a moment later, wiped the form's message (such as "Promo code … applied"). The session store now only marks such a session active.
- **Themes.** Saharan Sunset 1.0.5 and Gilded Noir 2.0.7: the footer's contact form button has its icon.
- **Gate.** `composer qa` from a cleared cache (1,333 PHPUnit tests, PHPStan, the architecture, runtime, UI-contract and release checks), Twig lint (295 files), AssetMapper compilation, Composer validation and `git diff --check`; in the browser, the menu pages in every theme (696 views), every page (548 checks), wallets (342), the demo site (78), legal pages (77), UI stabilisation, canonical (36), the safety net, reviews (9), bundles (9), billing (10), promotions (10), financial documents (9), landing pages (13), course content, the category tree (22) and picker (17), popular courses (13) and certificates (12), all passing; the Podman install and update run end to end on a test site.

## 2026-10-20 — v0.9.6 Installation, the setup wizard, was prices, automatic promotions and the public pages

- **Installation.** `install/install.sh` installs a release on a Linux server, run as root with the site's Linux user as its argument. It asks whether the site is a demo or live site (a demo installs Composer's development packages), copies the release to `/usr/local/lib/php/cattolms/<version>`, installs its Composer packages, walks through every setting of `.env.example` with checked defaults and writes the site's `.env`, prepares or empties the database, builds the tables, installs the themes, copies the web files to the user's `public_html` and schedules the scheduler from cron. `install/README.md` is the guide.
- **Setup wizard.** Administration → System → Setup lists sixteen steps in order (the business details, demo or live, email, payment methods, fees, identity verification, the theme, the categories and tags, importing courses, their details, prices and publishing, the certificate design, financial documents, the legal pages, the scheduler and launch), each opening the page where it is done; the dashboard shows the progress.
- **Starter categories and tags.** `resources/catalogue/catalogue.json` holds a starter tree of categories and a set of tags, each with a drawn icon, loaded by `php bin/console catalogue:import` or from the setup wizard. `catalogue:export` writes a site's catalogue back to a file, with `--without-courses` for a file to share.
- **Was prices.** A course price option and a bundle offer may carry a higher was price, struck through beside the price with the saving wherever the price is shown. Only the price is charged.
- **Promotions that apply themselves.** A promotion may apply automatically, a guest's cart included; one promotion applies to an order, the one that saves the most, and it says so in the cart, at checkout, on the order and its documents. Course and bundle pages announce the promotions that cover them.
- **Pictures.** Courses have drawn cover pictures, and categories and tags drawn icons, uploaded as SVG. Every card picture is the same size. A course page opens with its cover and its title over it.
- **Help, FAQ, contact and the legal pages.** Help and a new FAQ (`/faq`) as accordions with icons; drawn header pictures, all one size, for Help, the FAQ, the catalogue, the tag pages, Contact and the legal pages; a contact form with a reason for the message and topic cards, whose messages are kept and listed in Administration; the Terms, the Refund Policy and the Privacy Policy as numbered accordions, each with a formatted PDF (`/terms.pdf`, `/refunds.pdf`, `/privacy.pdf`) that opens in a new tab and names the site, its last-updated date and the Site Owner's details from settings.
- **Adults only.** Registration asks for the date of birth and accepts adults only; an account someone else made gives it at its first sign-in. Identity verification is asked only before a certificate is issued.
- **Feature cards.** Choices such as Register's account type and the contact reasons are cards with an icon, a title and a description.
- **Launch items.** `/sitemap.xml` and a generated `/robots.txt`; one scheduler (`schedule:run`) for the commerce job, popularity and a daily clean-up of records older than the Privacy Policy keeps; Settings → Payment methods; identity verification through a hosted verification page; certificates that prove the course was taken, without a grade, with a transcript, each private until its learner makes it public at a random address; the catalogue showing every course, and each category its whole branch.
- **The catalogue's categories as feature cards.** The categories beneath the one browsed (the main categories on All courses) are short feature cards, four to a row, each with its icon, name and description; choosing one opens it, and the trail above leads back up to All courses. The deepest level shows none.
- **Each course's position in the catalogue.** An optional number on the course's Details tab: numbered courses come first, lowest first, then the rest newest first. Number in hundreds (100, 200, 300) to leave room. Catalogue files carry it.
- **A demo site approves its new accounts.** On a demo site, an account registered through the site waits for an administrator's approval before it can sign in, so a stranger cannot buy courses with the simulated card and read them. The administrator is emailed when one registers and sees it on the dashboard and in People; Approve account on the person's page lets it in and emails the person. Accounts an administrator adds are approved already; a live site approves nobody by hand.
- **Who can sign in.** Administration → Settings → Who can sign in: everyone, or administrators and a list of allowed email addresses only. Anyone else is refused at sign-in and registration, and a session they hold stops working at once; a listed address needs no approval on a demo site.
- **Courses dragged into the catalogue's order.** Administration → Courses lists the courses in the catalogue's order with a Position column. A course is dragged by its handle, moved with the arrow keys, Home or End on it, or moved with the ⋯ menu's Move up, Move down and Move to the top without JavaScript; it takes the number halfway between its new neighbours (half the next one's when first, the one before plus 100 when last), and when there is no whole number between them the list is numbered again in hundreds from the top down to it.
- **Related courses.** A course's Details tab lists every other course to tick as related. A relation belongs to both courses: it is stored once for the pair, so relating A to B relates B to A, and removing it from either removes it from both. A course page ends with its published related courses as catalogue cards. Catalogue files carry them (`related`, by slug).
- **An icon library for categories and tags.** Lucide 1.52.0 (ISC licence), about 2,100 line icons with their keywords, ships in `resources/icons/`. A new category or tag takes the icon that fits its name best (Law gets the scales, Linux a terminal); its page offers the icons that fit, a search of the library by word and an upload of one's own.
- **Icons from icon sites.** An uploaded SVG with the plain document type line icon sites add is accepted; comments are dropped; a style inside a picture is kept to that picture, and its ids too; an icon drawn in one colour (or none) takes the theme's colour and loses its fixed size.
- **The catalogue keeps its place.** Choosing a category card or a step of the trail opens the next page with the cards where they were on the screen, not at the top.
- **Fixed:** a course's first tag on its Details tab rode up beside the Tags heading, past the form's right edge, because a hidden field sat straight after the heading (Core now clears the field after one, and a test refuses the pattern); Popular Courses on the home page is topped up with the newest courses, whatever their position.
- **Administration → People and a person's page.** The list printed a fixed "U" badge, left from the design mock-up, beside every name: the list now shows the name only, without the email address, so Last login has room for its whole date and time (the search still finds a person by email). A person's page had its cards outside the page section (no margin, no proper surface): it is built like every page, with the person's name as its title, a Summary card with their profile picture (or a plain placeholder), and granting course access in a card of its own; a search of their own courses appears once they have five or more. Roles read as words everywhere ("Company Administrator", not "CompanyAdministratorCOMPANY_ADMIN"). A list row's actions go below its text in a narrow card instead of past its edge.
- **The full version on every page.** The generator stamp in each page's head, the API status and the MCP status all give the release in full (`Support\AppVersion`, the version in `composer.json`), not the code directory's "v0.9".
- **Course and bundle pages.** The course or bundle itself is in a card ("About this course", "About this bundle"), beside the access card, as every page's content is.
- **A short pagination bar.** First, previous, next and last are icons; "Pg" for "Page"; "Show Pg" for "Jump to page"; the page count only once (`1–25 of 158 · Pg 1 of 7`); the rows drop-down before "Rows per pg"; the page box is as wide as the last page number needs, from one digit to six. It fits one line on a desktop screen, and the page checks fail any page whose pagination bar scrolls at 1440px in any theme, or that a phone has to scroll sideways.
- **Tables size their columns to each page.** Every table measures what each column holds on the page shown and shares its width out from that, so a cell is cut short only when the row truly cannot fit; it is worked out again on every page turn, resize and opened section (Core script; without JavaScript the server's widths stand).
- **My Courses in one table.** Account → My Courses shows one table, with a feature card above it for each list (Current courses, Completed courses, Favourite courses, and Course requests for an account that belongs to a company) saying how many it holds. Choosing a card swaps the table in place and the address follows; without JavaScript, Show opens the list. The search field appears only when the chosen list holds five or more entries. A list the account does not have is not found. My Courses has one address, `/account/courses`: `/account/library` is gone.
- **Needs your attention.** The ADMIN dashboard opens with one list of everything waiting for you, one row per thing (never a count): who it concerns, what to do, and a button named for the action that opens that one thing. It holds accounts waiting for approval, reviews to moderate (missing before), company course requests, unanswered contact messages, the money matters that had their own card, identity checks and unpublished courses. The old counts with Review buttons went to long lists (the course requests one even opened the enrolments list), and the accounts-waiting notice and the money card are now rows of the one list.
- **People named with their accounts.** Wherever a page names a person with an account, their name links to their profile. A page about a person or their account (an order, a ledger entry, a payment, a wallet, a message, a certificate) names them once under its title with their account reference and email, each on its own line. Tables and listings show the linked name alone and keep their columns as they were. The Company workspace links each name to its own person page.
- **Find a person.** Administration → People → Find a person finds anyone by name, email address or account reference (typed with or without its dashes): one person found opens their profile, several are listed with links.
- **A person's courses in Administration.** Each course in a person's library opens the course, with Remove access (or Restore access) on its row, coming back to their page. The Manage link, which opened the whole enrolments list, is gone.
- **The page's boxes line up with its cards.** In every theme the notices, the page head and the identity band are exactly as wide as the cards beneath them (in Factory Reset they stood 12px wider on each side, in Radiant Learning 36px, and Light Default, Gilded Noir and Saharan Sunset drew the head or the band across the whole page). The page check now measures the boxes' edges, not only the title's.
- **A company person page.** The Company workspace has a page for each member (`/company/people/{id}`): their account reference and email, role, status, last sign-in, their courses with progress and their course requests, with Disable or Enable, Remove, editing their details, removing or restoring a course and deciding a request, each coming back to the page. The company's People, Requests and Enrolments link each name to it.
- **The identity band's account reference.** The reader's account reference sits at the right of the identity band and opens their profile.
- **Course requests only where they belong.** A person's page in Administration shows Course requests only for an account that belongs to a company; a private account's page renders no such block.
- **The wallet balance stands out.** A wallet's balance (Account, Company and an administrator's view of a wallet) is a large stat card: the amount big and bold, with its label, in a border of the theme's accent (`data.stat-card` `size: 'large'`).
- **Fixed: diagnostics asked nothing.** Starting any imported course's Diagnostic said "No eligible questions are available for this assessment". The importers mark a diagnostic's questions as not for graded use, because a diagnostic never counts towards a grade, and since v0.8.6 an attempt drew a diagnostic's questions only from the graded ones. A diagnostic now asks every question it holds (`DiagnosticAttemptIntegrationTest`).
- **Fixed: work after a search or a page change.** The page script listened for htmx 2's swap event, which the bundled htmx 4 never sends, so a table loaded by a search or by paging did not fit its columns or get its row tooltips, and a window loaded into the page was not prepared. It listens for `htmx:after:swap` now.
- **Dead links.** "View all enrolments" led to a not-found page; the UI components gallery's sample course card did too.
- **Every page checked.** `tests/Browser/every-page.cjs` follows every link on the site, as an administrator and a visitor, at desktop and phone width, and applies the page construction checks to each page it reaches (shared with `section-pages.cjs` in `page-construction.cjs`), including no link to an error page.
- **Administration → Courses** shows each course's current price, with wrapped titles; the row tooltip of every table is one line along the row.
- **Themes.** Saharan Sunset 1.0.4: new sign-in and registration pictures, even padding in date fields, and its display typeface's bold weight for large figures.
- **Gate.** `composer qa` from a cold cache (1,314 PHPUnit tests, PHPStan, the architecture, runtime, UI-contract and release checks), Twig lint (295 files), AssetMapper compilation, Composer validation and `git diff --check`; in the browser, the menu pages in all six themes at 1440 and 390px (696 page views) and every page the links reach (546 page views) with no failures, the wallets and money check (342), bundles (9), the safety net (42), UI stabilisation and the canonical page (36) on the final code, and the other suites the same day: the course content and category trees, the category picker (17), course reviews (9), popular courses (13), billing profiles (10), promotions (10), legal pages (77), the demo site, certificates (12), financial documents (9) and landing pages (13).

## 2026-10-19 — v0.9.5

- the account reference at the top of every invoice, receipt and credit note; Record EFT Payment from the bank statement and Unmatched Money; each order's page shows its ledger lines, payment events and commerce trail together (Payment Events and the Commerce Trail left the menu); emails to the buyer at every step (how to pay by EFT, a part payment, a cancelled unpaid order, the order confirmation with its receipts, the courses that started); a month is a twelfth of a year (12 months = 365 days); Help and Contact out of the menu and Account, Company and Administration out of the footer (Saharan Sunset 1.0.3, Gilded Noir 2.0.6); browser-check data made only through the real workflows; Add person preselects Student; a missing attention item is a not-found page.

## 2026-10-18 — v0.9.4

- the commerce launch work (immediate start, the money ledger and wallets, refunds to the wallet or as money back with fees, refund requests, the Refund Policy rule, commerce settings, account references); only private accounts buy; the demo site or live site setting; the Site Owner in place of the System Company, with the commerce settings inside Settings and Create company fixed; the money records (Money Ledger, Payment Events, Commerce Trail, Orders & Payments filters, the dashboard's Money section); one page width; a real tag cloud; tables on phones; Gilded Noir 2.0.5.

## 2026-10-17 — v0.9.3

- Bootstrap 6 underneath (6.0.0-alpha.1, only its reboot, grid and utilities files, no Bootstrap script), with Core classes for fields and text and 5.3.8's look kept; every theme declares its colour scheme and keeps its look in a dark operating system; checkboxes and radios in each theme's colour; the scrollbar's strip painted in each theme's background; a plain-colour safety net in Core and every theme, checked in the browser; the six themes at new versions (Saharan Sunset 1.0.2, Gilded Noir 2.0.4, Light Default 2.0.3, Radiant Learning 4.0.3, Factory Reset 2.0.3 and Factory Reset Sidebar 2.0.3).

## 2026-10-17 — v0.9.2

- one construction for every page: each page has one width, the page head is its only main heading and holds its actions, and every section sits in a card with its own heading, with no "All … sections" buttons, back links on menu pages or group tabs; Saharan Sunset 1.0.1 bundled, with Home and course landing pages full width; every bundled theme at a new version with its package rebuilt; footer social marks that link to each platform until profile addresses are set, and show in Firefox; Symfony 8.1.9.

## 2026-10-13 — v0.9.1

- the public legal pages (`/terms`, `/refunds`, `/privacy`) with versions and the supplier's details; terms acceptance naming both documents at both checkouts, with the accepted versions in the order; no identity number at checkout; the Refund Policy's rule enforced on ADMIN refunds, with a recorded override; a correct message on a refunded order.

## 2026-10-09 — v0.9.0

- course marketing landing pages (Phase L): one optional page per course at `/landing/{slug}`, built from ten structured section types with the price, rating, reviews and outline read live from the course; a buy box shared with the course page; search and sharing metadata in every theme's head; first-party landing page analytics.

## 2026-10-08 — v0.8.9

- invoices, receipts and credit notes on the document template engine (Phase K): each keeps the values and design version it was issued with, and Commerce → Financial Documents sets their logo, typeface, accent colour and notes under a live PDF preview.

## 2026-10-08 — v0.8.8.14

- a certificate signature picture of any size is made transparent, trimmed, fitted above the signatory's name and centred on the line, so designs with a signature save.

## 2026-10-07 — v0.8.8.13

- GD is a required PHP extension, with a plain message instead of HTTP 500 where it is missing; theme uploads name a folder the server cannot write; importing a course again offers to replace it; the certificate preview is twice as large, above the form.

## 2026-10-07 — v0.8.8.12

- one category picker for the catalogue, the course forms, course import and category deletion, opening on the main categories, with + New creating a category at the level chosen.

## 2026-10-07 — v0.8.8.11

- the draggable category tree, sharing its tree behaviour with Course Content.

## 2026-10-07 — v0.8.8.10

- certificate designs replace the certificate template pages: six looks or your own uploaded background, one form with a live PDF preview, and a design and accreditation line per course.

## 2026-10-07 — v0.8.8.9

- certificates on the document template engine (Phase J): Classic, Modern and Minimal designs, a certificate PDF, and certificates that keep the values and template version they were issued with.

## 2026-10-07 — v0.8.8.8

- the shared document template engine (System → Document Templates: versioned HTML/CSS templates, a controlled placeholder language, one renderer for preview and PDF), and a front controller that reads `.env` exactly as phpdotenv does.

## 2026-10-06 — v0.8.8.7

- Billing Address is its own Account → Profile page after Personal Particulars, which again holds the particulars beside the profile image.

## 2026-10-06 — v0.8.8.6

- the front controller reads `APP_CODEBASE_PATH` as a `.env` file, so comments in the instance `.env` no longer stop the site loading.

## 2026-10-06 — v0.8.8.5

- one canonical baseline migration in place of the 25 development migrations, and the refreshed documentation.

## 2026-10-06 — v0.8.8.4

- billing profiles, promo codes, course bundles and independent entitlement sources.

## 2026-10-05 — v0.8.8.3

- rotating Popular Courses on the home page.

## 2026-10-04 — v0.8.8.2

- the course popularity engine.

## 2026-10-04 — v0.8.8.1

- moderated ratings and reviews.

## 2026-10-04 — v0.8.8

- first-party analytics events.

## 2026-10-03 — v0.8.7.4–v0.8.7.9

- protected Downloadable Files and the Course Content tree. - The tree has drag and drop, keyboard and ⋯ menu moves, and "+ Add here" through the insert modal. - The reader outline is depth-indented, with one Previous/Next order. - Sections can opt into the public preview.

## 2026-10-02 — v0.8.7.1–v0.8.7.3

- the tabbed course editor, the working grading scale and `[grading-scale]`, the compact category picker and category management tree, and the one-row Gilded Noir navbar.

## 2026-09-24 — v0.8.7

- tester administration, company credit purchasing at `/company/credits/buy`, and payment/refund administration at `/admin/commerce/orders`.

## 2026-09-23 — v0.8.6 / v0.8.6.1

- Course Components v2; ADMIN test access.
