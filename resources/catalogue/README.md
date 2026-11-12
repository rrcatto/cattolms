# The catalogue file

**LMS:** 1.2.0 · **Updated:** 2026-11-12

Keep this guide to what the catalogue file and package hold now, and under 150,000 characters: when something here stops being true, change it or take it out. The Git history keeps every earlier version.

`catalogue.json` holds the starter categories and tags for a CattoLMS site, so a site never has to make them from nothing. It is published with the code so it can be edited and installed:

- **categories**: a tree of up to three levels, each with a name, slug, description and SVG icon;
- **tags**: each with a name, slug and SVG icon (a category or tag without one takes the icon library's picture that fits its name).

It is version 2 of the catalogue file (`"format": "cattolms-catalogue", "version": 2`). The icons are SVG pictures held in the file as text. Each is checked when it is imported, as an upload is: nothing that runs, and no link outside itself.

## A site's whole catalogue

A site's own courses travel in a **catalogue package**, a `.zip` made at Administration → Courses → Import & Export Catalogue: tick the courses to export (or Tick every course, or Tick the published courses), then Download catalogue package (.zip). It holds only the ticked courses and only their categories (with the categories above them) and tags, a related course link only between ticked courses, and the bundles whose courses are all ticked. It holds this file (with the courses' codes in catalogue order, and those bundles with their positions), one course package per course (`courses/GDN-101.json`, `catto-learning-course` 2.1: the course's content as it stands on the site, its course code, whether it is a full or a short course, and its category, tags, cover, position, related courses, prices (each with when it is on sale, to the minute, and whether it takes the default's place), page layout and status), the Resource Library files the courses use (`resources/`), their presentations' narration (`narrations/`) and the site's own presentation (`site-presentation.json`). Import it on another site from the same page: before anything changes, the review shows every course and bundle as new, updated, unchanged or left out, and lists every difference with Use the file's (the default) or Keep this site's. A course is matched by its course code alone, a bundle by its code and a file by the name its course links; what a course already on the site takes from the file is in [docs/COURSES.md](../../docs/COURSES.md), "The catalogue import is canonical". Keep a site's package privately: it holds your own courses and prices.

## Loading it

- **A new installation:** Administration → System → Setup loads the starter categories and tags, or opens Import & Export Catalogue for a package from another site.
- **Any time:** Administration → Courses → Import & Export Catalogue → Import a catalogue, with this file or a package.
- **What a load does:** a category or tag that already exists keeps its place and gets the file's icon.

## Changing it

Edit the file directly. Or change categories, tags and icons in Administration and download them again: Import & Export Catalogue → Download their categories and tags (.json), saved over this file. With no course ticked it holds every category and tag on the site; with courses ticked, only theirs. For scripts, `php bin/console catalogue:export --categories-and-tags - > resources/catalogue/catalogue.json` writes the same file, and `catalogue:import <file>` loads a file or package without the review, taking the file's version of every difference.

The development walkthrough, `tests/Browser/one-learner-scenario.cjs`, builds its example site from a private catalogue package (`CATTO_CATALOGUE`), imported through the page.
