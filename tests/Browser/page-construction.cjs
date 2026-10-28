/* The one construction every page shares (UX rule 1.7), as a browser check sees it: inspect() runs in the page and
   judge() turns what it found into failures. Used by section-pages.cjs (the menu pages, every theme) and every-page.cjs
   (every page the links reach). */
const inspect = () => {
  const main = document.querySelector('main.cl-main');
  const allowed = ['cl-flash-stack', 'cl-page-head', 'cl-identity-head', 'cl-page-section', 'cl-page-family', 'cl-ui-modal'];
  const strays = [...main.children].filter(c => !['SCRIPT', 'LINK', 'STYLE', 'TEMPLATE'].includes(c.tagName))
    .filter(c => !allowed.some(a => c.classList.contains(a))).map(c => c.tagName.toLowerCase() + '.' + [...c.classList].join('.'));
  // Words whose nearest painted ancestor is the body: they stand on the texture.
  const bare = [];
  const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const node = walker.currentNode;
    if (!node.textContent.trim()) continue;
    const el = node.parentElement;
    if (!el || el.closest('[hidden], script, style, template, svg, dialog:not([open]), .cl-ui-modal:not(.open)')) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || !el.getClientRects().length) continue;
    let a = el, painted = false;
    while (a && a !== document.body) {
      const s = getComputedStyle(a);
      if ((s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent') || s.backgroundImage !== 'none') { painted = true; break; }
      a = a.parentElement;
    }
    if (!painted) bare.push(node.textContent.trim().slice(0, 40));
  }
  const h1 = main.querySelector('.cl-page-head h1');
  // A page head with a picture holds its title inside the picture: the picture's edge is the one the cards line up with.
  const picture = main.querySelector('.cl-page-head .cl-picture-head');
  // A head that opens with a logo (ph_media, the company page) starts at the logo, not at its title.
  const media = main.querySelector('.cl-page-head .cl-page-head-media');
  // The first box in document order: a card, or the accordion that holds a section on an "All sections" page.
  const card = main.querySelector([':scope > .cl-page-section', ':scope > .cl-page-family .cl-page-section']
    .flatMap(row => [`${row} .cl-ui-surface`, `${row} .cl-ui-accordion-section`]).join(', '));
  // The page's own boxes line up with its cards (owner, 2026-10-21: "the Page header is wider than the Page section. They
  // should align with each other"): every painted notice, page head and identity band has the cards' row edges, the first
  // page section's content box.
  const painted = (el) => { const s = getComputedStyle(el); return (s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent') || s.backgroundImage !== 'none' || parseFloat(s.borderLeftWidth) > 0 || s.boxShadow !== 'none'; };
  const firstSection = main.querySelector(':scope > .cl-page-section, :scope > .cl-page-family > .cl-page-section');
  let cardRow = null;
  if (firstSection) { const b = firstSection.getBoundingClientRect(), cs = getComputedStyle(firstSection); cardRow = [Math.round(b.left + parseFloat(cs.paddingLeft)), Math.round(b.right - parseFloat(cs.paddingRight))]; }
  const head = main.querySelector(':scope > .cl-page-head, :scope > .cl-page-family > .cl-page-head');
  const headPainted = !!head && [head, head.querySelector(':scope > .cl-page-head-inner')].some((el) => el && painted(el));
  const boxes = [];
  const add = (name, el) => { if (!el || !el.getClientRects().length) return; const b = el.getBoundingClientRect(); boxes.push({name, edges: [Math.round(b.left), Math.round(b.right)]}); };
  main.querySelectorAll(':scope > .cl-flash-stack .cl-ui-notice, :scope > .cl-page-family > .cl-flash-stack .cl-ui-notice').forEach((n) => { if (painted(n)) add('notice', n); });
  if (headPainted) add('page head', head);
  const band = main.querySelector(':scope > .cl-identity-head, :scope > .cl-page-family > .cl-identity-head');
  if (band && painted(band)) add('identity band', band);
  // The reader's account reference, in the identity band or a header chip standing in for it, sits under its label
  // (owner, 2026-10-21: "Put the label "Account Reference:" in a line above it. Without a label, people will not know
  // what it is"), clear of the role badge, and shows once on the page.
  const shown = (el) => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
  const overlaps = (a, b) => a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1;
  const references = [...document.querySelectorAll('.cl-identity-reference')].filter(shown);
  const referenceFaults = references.length > 1 ? [`shown ${references.length} times`] : [];
  for (const reference of references) {
    const tag = reference.closest('.cl-identity-account')?.querySelector('.cl-identity-account-label'), r = reference.getBoundingClientRect();
    if (!shown(tag) || tag.textContent.trim() !== 'Account Reference:') { referenceFaults.push('without its label'); continue; }
    const t = tag.getBoundingClientRect();
    if (t.bottom > r.top + 1 || t.right < r.left || t.left > r.right) referenceFaults.push('not under its label');
    const roles = reference.closest('.cl-identity')?.querySelector('.cl-identity-roles');
    if (shown(roles) && [t, r].some((b) => overlaps(b, roles.getBoundingClientRect()))) referenceFaults.push('under the role badge');
  }
  // A figure stays inside its card (owner, 2026-10-21: the dashboard's money amounts ran out of their cards).
  const figureSpill = [...main.querySelectorAll('.cl-ui-stat-card')].filter(shown).filter((card) => {
    const edge = card.getBoundingClientRect().right, amount = card.querySelector('.cl-ui-stat-value > span');
    return [...card.children, ...(amount ? [amount] : [])].some((el) => el.getBoundingClientRect().right > edge + 1)
      || (amount && amount.getBoundingClientRect().right > amount.parentElement.getBoundingClientRect().right + 1);
  }).map((card) => (card.querySelector('.cl-ui-stat-label')?.textContent || '').trim());
  const rows = [...main.children].filter(c => c.getClientRects().length && !['SCRIPT', 'LINK', 'STYLE', 'TEMPLATE'].includes(c.tagName));
  const last = rows[rows.length - 1];
  const footer = document.querySelector('.cl-footer');
  // Anything standing out past its table's right edge (a menu's open panel aside), unless a box
  // inside the table scrolls or clips it; and every action control cut off by its own cell.
  const clipped = (el, stop) => { for (let a = el.parentElement; a && a !== stop; a = a.parentElement) if (getComputedStyle(a).overflowX !== 'visible') return true; return false; };
  const tableSpill = [], cutActions = [];
  for (const table of main.querySelectorAll('.cl-ui-table')) {
    const edge = table.getBoundingClientRect().right;
    if (!table.getClientRects().length) continue;
    for (const el of table.querySelectorAll('*')) {
      if (el.closest('.cl-ui-row-panel')) continue;
      const r = el.getBoundingClientRect();
      if (r.width && r.height && r.right > edge + 1 && !clipped(el, table)) { tableSpill.push(`${el.tagName.toLowerCase()} "${el.textContent.trim().slice(0, 20)}" by ${Math.round(r.right - edge)}px`); break; }
    }
    for (const control of table.querySelectorAll('td .cl-ui-action, td .cl-ui-row-trigger')) {
      if (control.closest('.cl-ui-row-panel') || !control.getClientRects().length) continue;
      const td = control.closest('td'), cs = getComputedStyle(td);
      const right = td.getBoundingClientRect().right - parseFloat(cs.paddingRight) - parseFloat(cs.borderRightWidth);
      const name = `"${control.textContent.trim().replace(/\s+/g, ' ').slice(0, 20)}"`;
      if (control.getBoundingClientRect().right > right + 1) { cutActions.push(`${name} by ${Math.round(control.getBoundingClientRect().right - right)}px`); break; }
      if (control.scrollWidth > control.clientWidth + 1) { cutActions.push(`${name} squeezed to ${control.clientWidth}px`); break; }
    }
  }
  return {
    tableSpill, cutActions, referenceFaults, figureSpill,
    h1: main.querySelectorAll('h1').length,
    strays, bare: bare.slice(0, 6), bareCount: bare.length,
    headLeft: picture ? Math.round(picture.getBoundingClientRect().left) : (media ? Math.round(media.getBoundingClientRect().left) : (h1 ? Math.round(h1.getBoundingClientRect().left) : null)),
    cardLeft: card ? Math.round(card.getBoundingClientRect().left) : null,
    cardRow, boxes, headPainted: headPainted && !picture,
    gapAboveFooter: footer && main ? Math.round(footer.getBoundingClientRect().top - main.getBoundingClientRect().bottom) : null,
    lastRowBottom: last ? Math.round(main.getBoundingClientRect().bottom - last.getBoundingClientRect().bottom) : null,
    overflowX: document.documentElement.scrollWidth > innerWidth + 1,
    // A pagination row that does not fit its line (owner, 2026-10-21: it must not scroll on a desktop screen, 1440px).
    pagerScrolls: [...main.querySelectorAll('nav.pagination-row')].some((row) => row.getClientRects().length && row.scrollWidth > row.clientWidth + 1),
    // A list longer than the smallest page size keeps its rows drop-down on every page size (owner, 2026-10-21: after
    // choosing 100, 150 or 250 rows the bar showed "Pg 1 of 1 with no way to change the rows per page back down").
    pagerWithoutSizes: [...main.querySelectorAll('nav.pagination-row')].filter((row) => row.getClientRects().length)
      .map((row) => Number((row.querySelector('.pagination-summary')?.textContent.match(/of\s+([\d,]+)\s+·/) || [])[1]?.replace(/,/g, '') || 0) > 25 && !row.querySelector('select') ? row.dataset.pagination : null).filter(Boolean),
    width: innerWidth,
    theme: [...document.querySelectorAll('link[href*="/themes/"]')].map(l => l.getAttribute('href')).join(' '),
  };
};

/** The failures a page's facts show, each beginning with the label. */
function judge(r, label) {
  const failures = [];
  if (r.h1 !== 1) failures.push(`${label}: ${r.h1} h1 elements`);
  if (r.strays.length) failures.push(`${label}: not a page row in main: ${r.strays.join(', ')}`);
  if (r.bareCount) failures.push(`${label}: words on the texture: ${JSON.stringify(r.bare)}`);
  // A head drawn as a box lines up by its edges (below); one that is not a box, by its title.
  if (!r.headPainted && r.headLeft !== null && r.cardLeft !== null && Math.abs(r.headLeft - r.cardLeft) > 3) failures.push(`${label}: first card at ${r.cardLeft}px, page head text at ${r.headLeft}px`);
  if (r.cardRow) for (const box of r.boxes) {
    if (Math.abs(box.edges[0] - r.cardRow[0]) > 2 || Math.abs(box.edges[1] - r.cardRow[1]) > 2) failures.push(`${label}: the ${box.name} spans ${box.edges[0]}-${box.edges[1]}px, the cards ${r.cardRow[0]}-${r.cardRow[1]}px`);
  }
  if (r.overflowX) failures.push(`${label}: scrolls sideways`);
  if (r.pagerScrolls && r.width >= 1440) failures.push(`${label}: its pagination row scrolls sideways`);
  if (r.pagerWithoutSizes.length) failures.push(`${label}: a list of more than 25 rows without its rows drop-down: ${r.pagerWithoutSizes.join(', ')}`);
  if (r.tableSpill.length) failures.push(`${label}: stands out of its table: ${r.tableSpill.join(', ')}`);
  if (r.cutActions.length) failures.push(`${label}: an action wider than its cell: ${r.cutActions.join(', ')}`);
  if (r.referenceFaults.length) failures.push(`${label}: the account reference is ${r.referenceFaults.join(', ')}`);
  if (r.figureSpill.length) failures.push(`${label}: a figure runs out of its card: ${r.figureSpill.join(', ')}`);
  return failures;
}

module.exports = {inspect, judge};
