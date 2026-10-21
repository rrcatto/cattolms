# The catalogue file

`catalogue.json` holds the starter categories and tags for a CattoLMS site, so a site never has to make them from nothing. It is published with the code so it can be edited and installed:

- **categories**: a tree of up to three levels, each with a name, slug, description and SVG icon;
- **tags**: each with a name, slug and SVG icon (a category or tag without one takes the icon library's picture that fits its name);
- **courses**: empty here. A site's own catalogue file can also list its courses: for each, the HTML file it was imported from, its slug, title, category (its path of names joined by ` › `), position in the catalogue (numbered courses first, lowest first; number in hundreds), related courses (by slug; a relation is both courses'), tags, SVG cover picture and 12-month price and was price. Keep such a file privately: it describes your own courses and prices.

The icons and covers are SVG pictures held in the file as text. Each is checked when it is imported, as an upload is: nothing that runs, and no link outside itself.

## Loading it

- **A new installation:** the install script offers to load the starter categories and tags. Administration → Setup does the same.
- **By hand:** run `php bin/console catalogue:import` on the installation. Without a file argument, it reads this file.
- **What a load does:**
  - A category or tag that already exists keeps its place and gets the file's icon.
  - A course already on the site, matched by its slug, gets the file's tags and cover. It gets the file's category only when it has none.
  - Prices are never taken from the file: each course is priced on its own Pricing tab.
- **Order:** import courses first (Administration → Courses → Import). Then load the file again from Administration → Setup to give them their categories, tags and covers.

## Changing it

Edit the file directly. Or change categories, tags and icons in Administration and write them back to the file, leaving your courses out:

```sh
php bin/console catalogue:export --without-courses - > resources/catalogue/catalogue.json
```

`catalogue:export -` without `--without-courses` writes everything, your courses included: write that to a private file, and load it on your own sites with `php bin/console catalogue:import <file>`.

The development walkthrough, `tests/Browser/one-learner-scenario.cjs`, builds its example site from a private catalogue file with its courses (`CATTO_CATALOGUE`).
