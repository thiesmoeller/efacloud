// Simulate the visual viewport lost to a phone keyboard; no physical keyboard is emulated.
import { chromium, devices } from 'playwright-core';
import assert from 'node:assert/strict';

const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
try {
  const context = await browser.newContext({...devices['Pixel 7'], serviceWorkers:'block'});
  const page = await context.newPage();
  const people = ['Anna Müller', 'Anne Meyer', 'Anja Schulz'].map((displayName, i) => ({id:String(i + 1),displayName,firstName:displayName.split(' ')[0],lastName:displayName.split(' ')[1]}));
  await page.route('**/api/portal/v1/**', route => {
    const path = new URL(route.request().url()).pathname.split('/v1')[1];
    const responses = {
      '/session': {authenticated:true,csrfToken:'test',user:{efaCloudUserID:102,firstName:'Test'},privileges:{}},
      '/config': {config:{}}, '/destinations': {destinations:[]},
      '/boats/test': {boat:{Name:'Testboot'},variants:[{variant:'1',seatCategory:'4',typeCoxing:'COXLESS'}]},
      '/persons': {persons:people},
    };
    assert(path in responses, path);
    return route.fulfill({contentType:'application/json',body:JSON.stringify(responses[path])});
  });
  await page.goto((process.env.PORTAL_UI_BASE || 'http://127.0.0.1:5173/portal/') + 'trips/new?boatId=test');
  const input = page.getByLabel('Sitz 1', {exact:true});
  await input.fill('An');
  await page.locator('.person-suggest li').first().waitFor();
  await page.evaluate(() => {
    const input = document.querySelector('input[placeholder="Name suchen…"]');
    window.keyboardHeight = input.getBoundingClientRect().bottom + 8;
    Object.defineProperty(window.visualViewport, 'height', {configurable:true,get:() => window.keyboardHeight});
    window.visualViewport.dispatchEvent(new Event('resize'));
  });
  await page.waitForTimeout(350);
  const bounds = await page.evaluate(() => ({
    inputTop:document.querySelector('input[placeholder="Name suchen…"]').getBoundingClientRect().top,
    listBottom:document.querySelector('.person-suggest').getBoundingClientRect().bottom,
    visibleBottom:visualViewport.offsetTop + visualViewport.height,
  }));
  assert(bounds.inputTop >= 0, JSON.stringify(bounds));
  assert(bounds.listBottom <= bounds.visibleBottom, `Suggestions hidden by keyboard: ${JSON.stringify(bounds)}`);
  await page.screenshot({path:'/tmp/portal-person-picker.png'});
  console.log('PASS first-seat suggestions and input fit above simulated phone keyboard');
  await context.close();
} finally { await browser.close(); }
