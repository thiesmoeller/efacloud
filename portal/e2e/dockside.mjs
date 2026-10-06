// Deterministic UI contract checks against a mocked API; domain and DB suites test persistence.
import { chromium, devices } from 'playwright-core';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const fixture = name => JSON.parse(fs.readFileSync(new URL(`../../fixtures/sanitized/${name}.json`, import.meta.url)));
const people = fixture('persons').map(p => ({id:p.Id, firstName:p.FirstName,lastName:p.LastName,displayName:`${p.FirstName} ${p.LastName}`}));
const config = fixture('club-config').keys;
const source = fixture('boats').slice(0,3);
const boats = source.map(b => ({boatId:b.Id,name:b.Name,variantIndex:0,variant:'1',seatCategory:b.TypeSeats.split(';')[0].replace('X',''),seatCategoryLabel:b.TypeSeats.split(';')[0]+' Plätze',typeRigging:b.TypeRigging.split(';')[0],typeCoxing:b.TypeCoxing.split(';')[0],typeType:'RACING',typeDescription:'',defaultVariant:'1',listView:'available',status:'AVAILABLE',damage:{hasOpen:false,count:0},badges:{rigging:'SCULL',coxing:'COXLESS',hullType:'RACING'}}));
const destinations = fixture('destinations').map(d=>({id:d.Id,name:d.Name,distance:d.Distance || '5'}));
const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
try {
for (const device of ['Pixel 7', 'iPhone 13']) {
  const context = await browser.newContext({...devices[device],serviceWorkers:'block'});
  const page = await context.newPage();
  const errors = []; page.on('pageerror',e=>errors.push(e.message));
  let trips = [], account = 102, nextEntry = 1, lost = true, getCount = 0;
  const replay = new Map(), checkoutBodies = [];
  await page.route('**/api/portal/v1/**', async route => {
    const request = route.request(), url = new URL(request.url()), path = url.pathname.replace('/api/portal/v1','');
    const method = request.method(), body = request.postDataJSON();
    const send = (data,status=200) => route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
    if(path==='/session') return send({authenticated:true,csrfToken:'test',user:{efaCloudUserID:account,firstName:'Organisator',lastName:'Test'},privileges:{trainer:true}});
    if(path==='/config') return send({config,logbookName:'2026'});
    if(path==='/destinations') return send({destinations});
    if(path==='/persons') return send({persons:people.filter(p=>p.displayName.toLowerCase().includes((url.searchParams.get('search')||'').toLowerCase()))});
    if(path==='/boats') return send({boats,seatCategories:[{code:'1',label:'Einer'},{code:'2',label:'Zweier'},{code:'4',label:'Vierer'}]});
    if(path.startsWith('/boats/')) {
      const boat = boats.find(b=>path===`/boats/${b.boatId}`);
      if(boat) return send({boat:{Name:boat.name},variants:[boat],status:{},listView:'available',openDamages:[],damage:{hasOpen:false},reservations:[]});
    }
    if(path==='/trips' && method==='GET') { getCount++; assert.equal(url.searchParams.get('scope'),'started-by-me'); return send({trips:trips.filter(t=>t.open&&t.owner===account)}); }
    if(path==='/trips' && method==='POST') {
      checkoutBodies.push(body);
      if(replay.has(body.idempotencyKey)) return send(replay.get(body.idempotencyKey));
      const boat = boats.find(b=>b.boatId===body.boatId);
      const trip = {...body,owner:account,entryId:String(nextEntry++),logbookName:'2026',ecrid:`stable-${nextEntry}`,boatName:boat.name,date:'2026-10-06',startTime:'10:00',open:true,changeCount:1,cox:{name:'',id:''},crew:body.crew.map((p,i)=>({...p,position:i+1})).filter(p=>p.id),distance:'5'};
      trips.push(trip); const result = {trip}; replay.set(body.idempotencyKey,result);
      if(lost) { lost=false; return route.abort('failed'); }
      return send(result,201);
    }
    const match = path.match(/^\/trips\/(\d+)(?:\/(finish|abort))?$/);
    if(match) {
      const t = trips.find(t=>t.entryId===match[1]); assert(t);
      if(method==='GET') { assert.equal(url.searchParams.get('logbookName'),'2026'); return send({trip:t}); }
      assert.equal(body.logbookName,'2026'); assert.equal(body.ecrid,t.ecrid);
      if(body.expectedChangeCount!==t.changeCount) return send({error:{code:'STALE_STATE',message:'Fahrt wurde am Computer geändert.'}},409);
      if(method==='PATCH') { Object.assign(t,body,{changeCount:t.changeCount+1,crew:body.crew.map((p,i)=>({...p,position:i+1})).filter(p=>p.id)}); return send({trip:t}); }
      t.open=false; t.changeCount++; return send(match[2]==='abort'?{aborted:true}:{trip:t});
    }
    throw new Error(`Unhandled ${method} ${path}`);
  });
  await page.goto(process.env.PORTAL_UI_BASE || 'http://127.0.0.1:5173/portal/');
  await page.getByRole('heading',{name:'Meine gestarteten Fahrten'}).waitFor();
  for(let i=0;i<3;i++) {
    await page.getByRole('link',{name:'Weitere Fahrt starten'}).click();
    await page.getByRole('button',{name:new RegExp(boats[i].name)}).click();
    await page.getByLabel('Sitz 1',{exact:true}).fill(people[i].firstName);
    await page.getByRole('button',{name:people[i].displayName,exact:true}).click();
    await page.getByRole('combobox',{name:/^Ziel(?: \*)?$/}).selectOption(destinations[0].id);
    if(i===0) {
      await page.getByRole('button',{name:'Schaden melden',exact:true}).click();
      assert.equal(await page.locator('dialog[open]').count(),1);
      for (let tab = 0; tab < 10; tab++) {
        await page.keyboard.press('Tab');
        assert(await page.evaluate(() => !!document.activeElement.closest('dialog')));
      }
      await page.keyboard.press('Escape');
      assert.equal(await page.getByLabel('Sitz 1',{exact:true}).inputValue(),people[i].displayName);
      await context.setOffline(true);
      assert.equal(await page.getByRole('button',{name:'Fahrt starten',exact:true}).isDisabled(),true);
      await context.setOffline(false);
    }
    await page.getByRole('button',{name:'Fahrt starten',exact:true}).click();
    if(i===0) {
      await page.getByRole('alert').waitFor();
      await page.getByRole('button',{name:'Fahrt starten',exact:true}).click();
      assert.deepEqual(checkoutBodies[0],checkoutBodies[1]);
    }
    await page.getByRole('heading',{name:'Fahrt gestartet',exact:true}).waitFor();
    await page.getByRole('button',{name:'Meine Fahrten',exact:true}).click();
    await page.getByRole('heading',{name:'Meine gestarteten Fahrten'}).waitFor();
    await page.getByRole('heading',{name:boats[i].name,exact:true}).waitFor();
  }
  assert.equal(await page.locator('.trip-card').count(),3);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.screenshot({path:`/tmp/dockside-${device.replaceAll(' ','-')}.png`,fullPage:true});
  // Preserve a free-text destination entered at the desktop through crew correction.
  trips[1].destinationId = ''; trips[1].destinationName = 'Freies Ziel vom Computer';
  // Return presents a summary, with correction behind an explicit secondary action.
  await page.getByRole('link',{name:'Fahrt beenden'}).nth(1).click();
  await page.getByRole('heading',{name:'Mannschaft',exact:true}).waitFor();
  assert.equal(await page.locator('.person-suggest').count(),0);
  assert.equal(await page.getByLabel('Individuelles Fahrtziel',{exact:true}).inputValue(),'Freies Ziel vom Computer');
  await page.getByRole('button',{name:'Fahrt korrigieren',exact:true}).click();
  await page.getByLabel('Sitz 2',{exact:true}).fill(people[3].firstName);
  await page.getByRole('button',{name:people[3].displayName,exact:true}).click();
  await page.getByRole('button',{name:'Korrektur speichern'}).click();
  await page.getByRole('heading',{name:'Mannschaft',exact:true}).waitFor();
  assert.equal(trips[1].destinationName,'Freies Ziel vom Computer');
  // Desktop races with the return form: conflict reloads the new version.
  trips[1].changeCount++;
  await page.getByRole('button',{name:'Fahrt beenden',exact:true}).click();
  await page.getByRole('alert').waitFor();
  await page.waitForTimeout(100);
  await page.getByRole('button',{name:'Fahrt beenden',exact:true}).click();
  await page.getByRole('heading',{name:'Meine gestarteten Fahrten'}).waitFor();
  await page.waitForFunction(()=>document.querySelectorAll('.trip-card').length===2);
  trips[0].open=false;
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  await page.waitForFunction(()=>document.querySelectorAll('.trip-card').length===1);
  // Active account changes on resume must clear the previous account's cards.
  account=101;
  await page.evaluate(()=>window.dispatchEvent(new Event('pageshow')));
  await page.getByText('Du hast keine offenen Fahrten gestartet.').waitFor();
  assert.equal(await page.locator('.trip-card').count(),0);
  assert(getCount>=6); assert.deepEqual(errors,[]);
  console.log(`PASS ${device}: three boats, lost response, offline, dialog Escape, correction, stale return, desktop refresh, account switch, layout`);
  await context.close();
}
} finally { await browser.close(); }
