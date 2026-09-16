/* Run against the development instance with the isolated identity from ui-stabilisation-fixture.php.
 * Uses the installed Playwright; no frontend build or application dependency is introduced. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-ui-stabilisation-state.json'));
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-ui-stabilisation';
fs.mkdirSync(output, {recursive: true});
const themes = fs.readdirSync(path.join(root, 'themes')).filter(n => fs.existsSync(path.join(root, 'themes', n, 'theme.json'))).map(n => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map(t => ({slug: t.slug, key: `${t.slug}-v${t.version}`}));
const results = [], failures = [];
function measureUi() {
                        const visible = e => !!e.getClientRects().length;
                        const rgba = colour => {const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const c=canvas.getContext('2d');c.fillStyle=colour;c.fillRect(0,0,1,1);return [...c.getImageData(0,0,1,1).data];};
                        const luminance = rgb => rgb.slice(0,3).map(c=>{c/=255;return c<=.04045?c/12.92:((c+.055)/1.055)**2.4;}).reduce((n,c,i)=>n+c*[.2126,.7152,.0722][i],0);
                        const contrast = (a,b) => {const x=luminance(rgba(a)),y=luminance(rgba(b));return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);};
                        const box = e => {const b=e.getBoundingClientRect();return {x:b.x,y:b.y,right:b.right,bottom:b.bottom,height:b.height,width:b.width};};
                        const heads = [...document.querySelectorAll('.cl-ui-section-head')].filter(visible).map(e => {
                            const s=getComputedStyle(e), c=e.querySelector(':scope > .cl-ui-section-head-content'), a=e.querySelector(':scope > .cl-ui-section-actions');
                            return {box:box(e), left:box(e).x+parseFloat(s.paddingLeft)+parseFloat(s.borderLeftWidth), right:box(e).right-parseFloat(s.paddingRight)-parseFloat(s.borderRightWidth), content:c&&box(c), first:e.firstElementChild===c, actions:a&&box(a), align:c&&[...c.querySelectorAll('h1,h2,h3,h4,h5,h6,p,.cl-ui-eyebrow')].map(n=>getComputedStyle(n).textAlign), pseudo:['::before','::after'].map(p=>{const ps=getComputedStyle(e,p);return {content:ps.content,position:ps.position,pointerEvents:ps.pointerEvents};})};
                        });
                        const pagers=[...document.querySelectorAll('.pagination-row')].filter(visible).map(e=>({box:box(e), clientWidth:e.clientWidth, scrollWidth:e.scrollWidth, overflow:getComputedStyle(e).overflowX, summary:e.querySelector('.pagination-summary').textContent.replace(/\s+/g,' ').trim(), groups:[e,...e.querySelectorAll('.pagination-summary,.pagination-controls,.pagination-pages-list,.pagination-jump,.pagination-page-size')].filter(visible).map(g=>({name:g.className,wrap:getComputedStyle(g).flexWrap,direction:getComputedStyle(g).flexDirection,box:box(g)})), children:[...e.children].filter(visible).map(box)}));
                        const banners=[...document.querySelectorAll('.company-context')].filter(visible).map(e=>({background:rgba(getComputedStyle(e).backgroundColor),contrast:contrast(getComputedStyle(e).backgroundColor,getComputedStyle(e).color)}));
                        const footer=document.querySelector('.gn-footer,.fs-footer,.cl-footer');
                        const footerStyle=footer&&getComputedStyle(footer);
                        const grids=[...document.querySelectorAll('.cl-ui-stat-grid')].filter(visible).map(e=>({mode:e.dataset.columns||"auto",columns:getComputedStyle(e).gridTemplateColumns,cards:[...e.children].map(box)}));
                        return {heads,pagers,banners,grids,footer:footer&&{class:footer.className,background:rgba(footerStyle.backgroundColor),contrast:contrast(footerStyle.backgroundColor,footerStyle.color)},overflow:document.documentElement.scrollWidth-innerWidth};
}
function assertUi(measured,theme,route,width) {
                    assert.ok(measured.heads.length, 'Real section headings rendered');
                    for (const h of measured.heads) {
                        assert.ok(h.content && h.first, 'Explicit first content child');
                        assert.ok(Math.abs(h.content.x-h.left)<2, `Content starts left: ${JSON.stringify(h)}`);
                        assert.ok(h.align.every(a=>a==='left'), 'Left-aligned heading/eyebrow/summary');
                        if(h.actions) assert.ok(Math.abs(h.actions.right-h.right)<2, 'Actions stay right, including on a wrapped line');
                        for(const p of h.pseudo) if(!['none','normal',''].includes(p.content)) {assert.equal(p.position,'absolute','Root decoration outside flex layout');assert.equal(p.pointerEvents,'none');}
                    }
                    if(['/admin/system/ui-components','/admin/people'].includes(route)) assert.ok(measured.pagers.length, 'Pagination exercised');
                    for(const p of measured.pagers) {
                        assert.equal(p.overflow,'auto','Narrow overflow belongs to pager');
                        for(const g of p.groups) {assert.equal(g.wrap,'nowrap',g.name);assert.equal(g.direction,'row',g.name);}
                        const centres=p.children.map(b=>b.y+b.height/2);
                        assert.ok(Math.max(...centres)-Math.min(...centres)<2,'All pagination groups share one horizontal centre line');
                        assert.ok(p.box.height<100,`One-row pager height ${p.box.height}`);
                        assert.match(p.summary,/^(Showing \d+–\d+ of \d+ · )?Page \d+ of \d+$/);
                    }
                    if(route==='/company') assert.ok(measured.banners.length,'Company context exercised');
                    for(const b of measured.banners) {assert.equal(b.background[3],255,'Opaque context surface');assert.ok(Math.max(...b.background.slice(0,3))-Math.min(...b.background.slice(0,3))>=10,`Visible context colour: ${b.background}`);assert.ok(b.contrast>=4.5,`Context contrast ${b.contrast}`);}
                    assert.ok(measured.footer,'Footer rendered');
                    const expected=theme.slug==='gilded-noir'?'gn-footer':theme.slug==='factory-reset-sidebar'?'fs-footer':'cl-footer';
                    assert.ok(measured.footer.class.split(' ').includes(expected),`Preserved ${expected}`);
                    assert.equal(measured.footer.background[3],255,'Footer has its own opaque surface');
                    assert.ok(measured.footer.contrast>=4.5,`Footer text contrast ${measured.footer.contrast}`);
                    for(const grid of measured.grids) if(width===390 && grid.mode==='auto') assert.equal(grid.columns.split(' ').length,1,'Automatic stat grids fit mobile width');
                    if(route==='/admin/reports') {
                        assert.ok(measured.grids.length,'Reports stat grid exercised');
                        for(const g of measured.grids) if(g.cards.length>=4) assert.equal(g.columns.split(' ').length,width>1000?4:1,'Reports responsive columns');
                    }
                    assert.ok(measured.overflow<=2,`Document overflow: ${measured.overflow}px`);
}
(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const context = await browser.newContext({ignoreHTTPSErrors: true});
        await context.addCookies([{name:'catto_learning_session', value:state.token, url:base, secure:true}]);
        const page = await context.newPage();
        page.on('pageerror', e => failures.push(`JavaScript: ${e.message}`));
        for (const theme of themes) for (const width of [1440, 390]) {
            await page.setViewportSize({width, height:1000});
            for (const route of ['/admin/system/ui-components', '/admin/reports', '/admin/people', '/company', '/account', '/courses', '/help']) {
                const label = `${theme.slug} ${width} ${route}`;
                try {
                    const response = await page.goto(`${base}${route}?theme_preview=${theme.key}`);
                    assert.equal(response.status(), 200, 'HTTP status');
                    assert.equal(new URL(page.url()).pathname, route, 'No login/error redirect');
                    await page.evaluate(() => document.fonts.ready);
                    const sheets = await page.locator('link[rel=stylesheet]').evaluateAll(nodes => nodes.map(n => n.href));
                    assert.ok(sheets.some(url => url.includes('/'+theme.key+'/')), `Requested theme actually loaded: ${sheets.join(', ')}`);
                    if(['/company','/account'].includes(route)) {
                        const first=page.locator('details.cl-ui-accordion-section').first();
                        if(await first.count() && (await first.getAttribute('open'))===null) {
                            await first.locator(':scope > summary').click();
                            await page.waitForLoadState('networkidle');
                        }
                    }
                    const measured = await page.evaluate(measureUi);
                    assertUi(measured,theme,route,width);
                    const scrolls=await page.locator('.pagination-row').evaluateAll(rows=>rows.filter(r=>r.scrollWidth>r.clientWidth+1).map(r=>{r.scrollLeft=r.scrollWidth;const moved=r.scrollLeft>0;r.scrollLeft=0;return moved;}));
                    assert.ok(scrolls.every(Boolean),'Overflowing pagination actually scrolls');
                    results.push({label,...measured});
                    console.log('PASS',label);
                } catch(error) {failures.push(`${label}: ${error.message}`); console.error('FAIL',label,error.message);}
                await page.screenshot({path:path.join(output,`${theme.slug}-${width}-${route.replaceAll('/','_')}.png`),fullPage:true});
            }
        }
        // Mutation checks prove these tests detect the original defects in the real cascade.
        const light=themes.find(t=>t.slug==='light-default');
        await page.setViewportSize({width:1440,height:1000});
        await page.goto(`${base}/admin/system/ui-components?theme_preview=${light.key}`);
        let mutation=await page.addStyleTag({content:'.ld-main .cl-ui-section-head::before{position:static}'});
        let broken=await page.evaluate(measureUi);
        assert.throws(()=>assertUi(broken,light,'/admin/system/ui-components',1440),/Content starts left|Root decoration/);
        await mutation.evaluate(e=>e.remove());
        mutation=await page.addStyleTag({content:'.ld-main .pagination-controls{flex-wrap:wrap}'});
        broken=await page.evaluate(measureUi);
        assert.throws(()=>assertUi(broken,light,'/admin/system/ui-components',1440),/pagination-controls/);
        await mutation.evaluate(e=>e.remove());
        await page.goto(`${base}/company?theme_preview=${light.key}`);
        mutation=await page.addStyleTag({content:'.ld-main .company-context{background:white}'});
        broken=await page.evaluate(measureUi);
        assert.throws(()=>assertUi(broken,light,'/company',1440),/Visible context colour/);
        await mutation.evaluate(e=>e.remove());
        console.log('PASS browser mutations: flex pseudo-element, pagination wrapping, white company banner');
        // Native GET navigation is still usable without htmx/Stimulus.
        const plain=await browser.newContext({ignoreHTTPSErrors:true,javaScriptEnabled:false,viewport:{width:390,height:1000}});
        await plain.addCookies([{name:'catto_learning_session',value:state.token,url:base,secure:true}]);
        const p=await plain.newPage();
        await p.goto(`${base}/admin/system/ui-components`);
        assert.ok(await p.locator('.pagination-jump[method=get]').count());
        assert.ok(await p.locator('.pagination-page-size noscript button').first().isVisible());
        await p.goto(`${base}/admin/people`);
        await p.locator('.pagination-controls').first().getByRole('link',{name:'Next page',exact:true}).click();
        await p.waitForLoadState();
        assert.match(await p.locator('.pagination-summary').first().innerText(),/Page 2 of/);
        const jump=p.locator('.pagination-jump').first();
        await jump.locator('input[type=number]').fill('3');
        await jump.locator('input[type=number]').press('Enter');
        await p.waitForLoadState();
        assert.match(await p.locator('.pagination-summary').first().innerText(),/Page 3 of/);
        await p.goto(`${base}/courses`);
        assert.equal(new URL(p.url()).pathname,'/courses');
        console.log('PASS non-JavaScript GET controls');
    } finally {
        fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({results,failures},null,2));
        await browser.close();
    }
    assert.deepEqual(failures,[],'Rendered UI regressions');
})().catch(e=>{console.error(e);process.exitCode=1;});
