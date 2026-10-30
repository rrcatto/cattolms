# Saharan Sunset

Theme version **1.0.6** for CattoLMS **v0.9.3** and later. Theme Package 4.0 / Twig Template API 2.0. Built from the Saharan Sunset mockups (Home, Catalogue, Course detail and Sign in). Version 1.0.2 runs on Bootstrap 6 (CattoLMS v0.9.3): it declares its colour scheme (light), sets its checkbox and radio colour (`accent-color`) and focus outline colour, and styles Core's classes in place of Bootstrap 5's. Version 1.0.3 leaves Contact out of the footer's list of links, because the footer's Get in touch has the contact form button (owner, 2026-10-18). Version 1.0.4 replaces the serpent beside the sign-in pages with two drawn pictures, one for signing in and one for registering (owner, 2026-10-20: the serpent "will put off some people"), and gives a form field's date boxes and drop-downs the height of its text boxes. Version 1.0.5 gives the footer's contact form button its icon (owner, 2026-10-21: every button has an icon to the left of its title). Version 1.0.6 runs a course landing page's hero across the window like Home's, its words in the page's frame: on a wide screen they had stood in a narrow column in the middle.

## Look

Warm ivory ground (#f7f1e8, striped with #efe5d6) with paper surfaces (#fbf6ee), lighter cards (#fffdf9), sand bands (#efe3d3), near-black ink (#1f1915) and a terracotta accent (#9a4a2a). Headings are Cormorant Garamond and text is Jost, loaded from Google Fonts. Corners are square and actions use tracked uppercase labels. The colours are fixed, so there is no palette list and no palette switcher.

## Imagery

- `public/img/hero-abacus.jpg` is the home page hero behind a dark gradient.
- `public/img/decor-orb.jpg` lies behind the footer under a veil, mirrored so the orb sits on the right, and is the artwork of course cards that have no cover image.
- `public/img/auth-sign-in.svg` is the side panel when signing in (sign in, link sent): an arched doorway at night, its door open on a lit room, with lanterns and the moon.
- `public/img/auth-register.svg` is the side panel when registering (register, company registration): dawn over the dunes, a new account card in the sky and fresh footprints towards the sun. Each panel has its own line of text.

No picture appears twice on one page.

## Construction

`base.html.twig` provides one shell for anonymous and signed-in readers. The header runs the full width of the window and stays at the top while the page scrolls; from 1361px the menu is centred on the page, and between 1121 and 1360px it sits on the right. Navigation, identity, flash messages, page bodies and the head metadata (`@platform/partials/head-meta.html.twig`) are core-owned and included unchanged. `main.cl-main` and `footer.cl-footer` are siblings inside `div.cl-page-frame`.

Every page except Home and course landing pages is one box on the slanted texture: the page context band is its cap, with a terracotta edge, and `main` is its body. Every block core places in `main` is a full-width row of the box: the page head, the identity band, then the page sections. Cards inside the box are one step lighter than the box. The box is wide by default and narrower on medium and narrow pages; sign-in pages stay wide. On phones (560px and below) it runs edge to edge. Home runs full width without the box: the abacus hero, the catalogue as one paper panel and the three feature cards stand on the texture. Course landing pages run full width too: the hero is a night band with the price card and the course's picture, each section is a paper panel on the texture, the pricing panel is framed in terracotta and the closing call to action is sand.

`partials/footer.html.twig` is a rich footer with three `cl-footer-section` sections, the core `footer_navigation` destinations, the core `footer_social` marks from the platform sprite and a contact action. The orb art and veil are `ss-footer-art` and `ss-footer-veil`.

`pages/auth.html.twig` wraps the sign-in family (sign in, link sent, register and company registration) in `div.cl-page-family.ss-auth`, placing the picture panel beside the unchanged page body inside the box. It reads `page.name` to choose the picture and the line of text (`ss-auth--sign-in` or `ss-auth--register`).

There is no theme JavaScript. Theme CSS sets colours, type, borders, shadows and decoration on canonical classes. Layout is set only on the shell, the box and its rows, and theme-owned `ss-*` elements.
