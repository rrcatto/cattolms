/* Real HTTP checks for all bundled themes, both authentication states and three viewport widths.
   Theme selection is temporarily changed for anonymous requests and restored even on failure. */
const {chromium}=require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs=require('node:fs'), path=require('node:path'), assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..');
const base=process.env.CATTO_BASE_URL || 'https://catto.test';
const output=process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-canonical-page';
const state=JSON.parse(fs.readFileSync('/tmp/catto-ui-stabilisation-state.json'));
const themes=fs.readdirSync(path.join(root,'themes')).map(slug=>JSON.parse(fs.readFileSync(path.join(root,'themes',slug,'theme.json'))).theme);
const themeState=(...args)=>execFileSync('podman',['exec','-u','cattotest','env_php_1','php','/home/cattotest/code/cattolms-v0.8/tests/Browser/canonical-page-theme.php',...args],{stdio:'pipe'});
fs.mkdirSync(output,{recursive:true});
const results=[], failures=[];
(async()=>{
 const browser=await chromium.launch({headless:true});
 let begun=false;
 try {
  themeState('begin');begun=true;
  for(const theme of themes){
   themeState('select',`${theme.slug}-v${theme.version}`);
   for(const authenticated of [false,true]){
    const context=await browser.newContext({ignoreHTTPSErrors:true});
    if(authenticated)await context.addCookies([{name:'catto_learning_session',value:state.token,url:base,secure:true}]);
    const page=await context.newPage();
    page.on('pageerror', e=>failures.push(`${theme.slug}: ${e.message}`));
    for(const width of [1440,820,390]){
     const label=`${theme.slug}-${authenticated?'signed-in':'anonymous'}-${width}`;
     try {
      await page.setViewportSize({width,height:1000});
      const route=authenticated?'/account':'/help';
      const response=await page.goto(base+route);
      assert.equal(response.status(),200);assert.equal(new URL(page.url()).pathname,route);
      await page.evaluate(()=>document.fonts.ready);
      assert.ok(await page.locator(`link[href*="/${theme.slug}-v${theme.version}/"]`).count(),'Correct theme served');
      const structure=await page.evaluate(()=>{
       const main=document.querySelector('main'),footer=document.querySelector('footer.cl-footer'),nav=document.querySelector('#cl-primary-navigation');
       const rect=e=>{const b=e.getBoundingClientRect();return {x:b.x,y:b.y,width:b.width,height:b.height,right:b.right}};
       return {mains:document.querySelectorAll('main').length,frame:main?.parentElement.classList.contains('cl-page-frame'),siblings:main?.nextElementSibling===footer,context:!!document.querySelector('section.cl-page-context>div.cl-page-context-inner'),head:!!document.querySelector('section.cl-page-head>.cl-page-head-inner>.cl-page-head-content'),nav:!!nav,overflow:document.documentElement.scrollWidth-innerWidth,geometry:document.body.dataset.clNavigation,main:rect(main),footer:rect(footer),gnIdentity:!!document.querySelector('header .cl-identity-compact'),gnArt:!!document.querySelector('.cl-footer .gn-footer-art'),gnSections:document.querySelectorAll('.cl-footer .cl-footer-section').length};
      });
      assert.equal(structure.mains,1);assert.ok(structure.frame && structure.siblings && structure.context && structure.head && structure.nav,'Canonical landmarks');
      assert.equal(structure.geometry,theme.slug.startsWith('factory-reset')?'sidebar':'top');
      assert.ok(structure.overflow<=2,`Document overflow ${structure.overflow}`);
      if(width<=820)assert.ok(structure.main.y<650,`No empty navigation grid row: main y=${structure.main.y}`);
      if(theme.slug==='gilded-noir'){assert.equal(structure.gnIdentity,authenticated);assert.ok(structure.gnArt);assert.equal(structure.gnSections,3);}
      const paletteHide=page.locator('.cl-palette-hide');
      if(await paletteHide.isVisible())await paletteHide.click();
      await page.screenshot({path:path.join(output,label+'.png'),fullPage:true});
      const toggle=page.locator('[data-cl-nav-toggle]');
      if(width<=820){
       const contrast=await toggle.evaluate(e=>{
        const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d');
        const rgba=value=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=value;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data];};
        const luminance=rgb=>rgb.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((n,v,i)=>n+v*[.2126,.7152,.0722][i],0);
        let parent=e,bg=rgba(getComputedStyle(e).backgroundColor);
        while(bg[3]<250 && parent.parentElement){parent=parent.parentElement;bg=rgba(getComputedStyle(parent).backgroundColor);}
        const a=luminance(rgba(getComputedStyle(e).color)),b=luminance(bg);
        return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
       });
       assert.ok(contrast>=4.5,`Menu label contrast ${contrast}`);
       await toggle.click();assert.equal(await toggle.getAttribute('aria-expanded'),'true');await page.locator('#cl-primary-navigation').waitFor({state:'visible'});
      }
      if(authenticated){
       const account=page.locator('.cl-nav-menu[data-nav-item="account"]');
       await account.locator('summary').click();
       const child=account.locator('.cl-nav-group-label').first();await child.focus();
       const nested=account.locator('.cl-nav-subpanel a').first();
       assert.ok(await nested.isVisible(),'Keyboard-reachable nested menu');
       const nestedBox=await nested.boundingBox();
       assert.ok(nestedBox.x>=0 && nestedBox.x+nestedBox.width<=width+2,'Nested navigation fits viewport');
       const position=await account.locator('.cl-nav-subpanel').first().evaluate(e=>getComputedStyle(e).position);
       assert.equal(position,width>1120 && structure.geometry==='top'?'absolute':'static','Desktop flyouts and inline sidebar/mobile groups');
      }else{assert.ok(await page.locator('#cl-primary-navigation a[href="/login"]').isVisible(),'Anonymous sign-in link');}
      await page.screenshot({path:path.join(output,label+'-navigation.png')});
      await page.keyboard.press('Escape');
      if(width<=820)assert.equal(await toggle.getAttribute('aria-expanded'),'false');
      await page.locator('.cl-footer').scrollIntoViewIfNeeded();
      await page.screenshot({path:path.join(output,label+'-footer.png')});
      results.push({label,...structure});console.log('PASS',label);
     }catch(e){failures.push(`${label}: ${e.message}`);console.error('FAIL',label,e.message);await page.screenshot({path:path.join(output,label+'-failure.png')}).catch(()=>{});}
    }
    await context.close();
    // Native navigation remains available with scripting disabled.
    const native=await browser.newContext({ignoreHTTPSErrors:true,javaScriptEnabled:false,viewport:{width:390,height:900}});
    if(authenticated)await native.addCookies([{name:'catto_learning_session',value:state.token,url:base,secure:true}]);
    const nativePage=await native.newPage();await nativePage.goto(base+'/help');
    try{assert.ok(await nativePage.locator('#cl-primary-navigation a[href="/courses"]').isVisible());await nativePage.locator('#cl-primary-navigation a[href="/courses"]').click();assert.equal(new URL(nativePage.url()).pathname,'/courses');}catch(e){failures.push(`${theme.slug} native ${authenticated}: ${e.message}`);}
    await native.close();
   }
  }
 }finally{
  if(begun)themeState('restore');
  await browser.close();
  fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({results,failures},null,2));
 }
 console.log(JSON.stringify({checks:results.length,failures},null,2));
 if(failures.length)process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
