// Built PWA + real PHP API + real MariaDB. Run only through tests/portal/live.sh.
import { chromium, devices } from 'playwright-core';
import assert from 'node:assert/strict';
const base = process.env.PORTAL_LIVE_BASE_URL;
assert(base?.startsWith('http://127.0.0.1:'), 'requires isolated localhost acceptance stack');
const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome', headless:true, args:['--no-sandbox']});
try {
  for (const device of ['Pixel 7','iPhone 13']) {
    const context = await browser.newContext({...devices[device],baseURL:base});
    const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const submitted = [];
    let loseResponse = true;
    await page.route('**/api/portal/v1/trips',async route => {
      if (route.request().method() !== 'POST') return route.continue();
      submitted.push(route.request().postDataJSON());
      if (loseResponse) {
        const response = await route.fetch();
        if (response.status() !== 201) return route.fulfill({response});
        loseResponse = false;
        return route.abort('failed'); // The real server has committed; browser loses its response.
      }
      return route.continue();
    });
    await page.goto('/portal/');
    await page.getByLabel('Konto',{exact:true}).fill('102');
    await page.getByLabel('Passwort',{exact:true}).fill('fixture-pass');
    await page.getByRole('button',{name:'Anmelden',exact:true}).click();
    await page.getByRole('heading',{name:'Meine gestarteten Fahrten'}).waitFor();
    assert.equal(await page.locator('.trip-card').count(),0);
    for(const [index,name] of ['Albatros','Biber','Cirrus'].entries()) {
      await page.getByRole('link',{name:'Weitere Fahrt starten'}).click();
      await page.getByRole('button',{name:new RegExp(name)}).click();
      if(index>0) {
        await page.getByRole('heading',{name:'Konfiguration'}).waitFor();
        await page.locator('.boat-row').first().click();
      }
      await page.getByLabel('Sitz 1',{exact:true}).fill(['Anna','Ben','Carla'][index]);
      await page.locator('.person-suggest button').first().click();
      await page.getByRole('combobox',{name:/^Ziel(?: \*)?$/}).selectOption('33333333-3333-4333-a333-333333333301');
      await page.getByRole('button',{name:'Fahrt starten',exact:true}).click();
      if(index===0) {
        await page.getByRole('alert').waitFor();
        await page.getByRole('button',{name:'Fahrt starten',exact:true}).click();
        assert.deepEqual(submitted[0],submitted[1], 'lost response retries the real original request');
      }
      // The previous cancellation may have left a real FULLYUSEABLE damage warning.
      for(let warning=0;warning<4;warning++) {
        const outcome = await Promise.race([
          page.getByRole('heading',{name:'Fahrt gestartet',exact:true}).waitFor().then(()=> 'started'),
          page.locator('dialog[open]').waitFor().then(()=> 'dialog'),
        ]);
        if(outcome==='started') break;
        await page.getByRole('button',{name:'Ja',exact:true}).click();
      }
      await page.getByRole('button',{name:'Meine Fahrten',exact:true}).click();
      await page.getByRole('heading',{name,exact:true}).waitFor();
    }
    assert.equal(await page.locator('.trip-card').count(),3);
    const listed = await (await context.request.get('/api/portal/v1/trips?scope=started-by-me&status=open')).json();
    assert.equal(listed.trips.length,3);
    assert(listed.trips.every(t=>t.crew.every(p=>p.id!=='22222222-2222-4222-a222-222222222206')));
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    for(let count=3;count>0;count--) {
      await page.getByRole('link',{name:'Fahrt beenden',exact:true}).first().click();
      await page.getByRole('heading',{name:'Mannschaft',exact:true}).waitFor();
      await page.getByLabel('Kilometer',{exact:true}).fill('8 km');
      await page.getByRole('button',{name:'Fahrt beenden',exact:true}).click();
      await page.getByRole('heading',{name:'Meine gestarteten Fahrten'}).waitFor();
      await page.waitForFunction(expected=>document.querySelectorAll('.trip-card').length===expected,count-1);
    }
    assert.deepEqual(errors,[]);
    console.log(`PASS real ${device}: login, three distinct crews, committed/lost checkout response, database personal list, individual returns`);
    await context.close();
  }
} finally { await browser.close(); }
