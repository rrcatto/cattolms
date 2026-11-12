/* The themes the browser checks look at (owner, 2026-11-07: "there is only ONE theme that matters now and it is Saharan
   sunset", and the other five "on ice until further notice").
   - bundledThemes(): every theme the code ships under themes/, Saharan Sunset alone, for the checks that look through
     the theme preview, which changes nothing on the site.
   - siteTheme(): the theme the site is drawn in, the bundled default (View\ProjectTheme::DEFAULT_SLUG, CattoPHP's theme system), for the checks that
     look as a visitor or a learner, who cannot preview a theme. No check switches the active theme: a check that
     finds the site drawn in another theme fails and says so, and the owner's choice is never changed to make it pass. */
const fs = require('node:fs'), path = require('node:path');
const root = path.resolve(__dirname, '../..');

const manifest = (slug) => JSON.parse(fs.readFileSync(path.join(root, 'themes', slug, 'theme.json'))).theme;

function bundledThemes() {
    return fs.readdirSync(path.join(root, 'themes')).filter((slug) => fs.existsSync(path.join(root, 'themes', slug, 'theme.json'))).sort().map(manifest);
}

function siteTheme() {
    const found = /DEFAULT_SLUG = '([a-z0-9-]+)'/.exec(fs.readFileSync(path.join(root, 'src/View/ProjectTheme.php'), 'utf8'));
    if (!found) throw new Error('ProjectTheme names no default theme (DEFAULT_SLUG).');
    return manifest(found[1]);
}

/** A theme's install key, as the theme preview and the served stylesheet name it: saharan-sunset-v1.0.7. */
const themeKey = (theme) => `${theme.slug}-v${theme.version}`;

module.exports = {bundledThemes, siteTheme, themeKey};
