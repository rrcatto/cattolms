# Changelog

What each release brought. Releases are tagged in the repository and published on GitHub with a source zip; versions before v0.8.6 are listed by tag only.

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
