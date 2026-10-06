#!/usr/bin/env python3
"""Real HTTP acceptance against the isolated, sanitized live.sh stack."""
from pathlib import Path
import os
import sys
import uuid
from datetime import datetime
from zoneinfo import ZoneInfo
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'lab' / 'backup_rehearsal'))
from portal_client import PortalClient
from posttx_client import PosttxClient

base = os.environ.get('PORTAL_LIVE_BASE_URL', 'http://127.0.0.1:18089')
if not base.startswith('http://127.0.0.1:'):
    raise RuntimeError('This test only targets an isolated localhost stack')
portal = PortalClient(base)
portal.login('102', 'fixture-pass')
member = PortalClient(base)
member.login('101', 'fixture-pass')
desktop = PosttxClient(base, 103, 'fixture-pass')

def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print('PASS', message, flush=True)

def request(client, method, path, body=None, expected=200):
    code, result = client._request(method, path, body)
    check(code == expected, f'{method} {path}: HTTP {code} expected {expected}' + (f' {result}' if code != expected else ''))
    return result

def mine(client=portal):
    return request(client, 'GET', '/trips?scope=started-by-me&status=open')['trips']

def reference(t):
    return {'logbookName':t['logbookName'], 'ecrid':t['ecrid'], 'expectedChangeCount':t['changeCount']}

def sync(table, record, kind='update', accepted=True):
    result = desktop.modify_many([{'type':kind,'table':table,'record':record}])
    check(result['ok'] is accepted, f'desktop {kind} {table}: accepted={result["ok"]}' + (f' {result["decoded"]}' if result['ok'] is not accepted else ''))
    return result

# Desktop logbook and boat status travel separately. The gap must already block checkout.
gap_id = uuid.uuid4().hex[:12]
gap = {'ecrid':gap_id, 'Logbookname':'2026', 'BoatId':'11111111-1111-4111-a111-111111111101', 'BoatName':'Albatros', 'Date':datetime.now(ZoneInfo('Europe/Berlin')).strftime('%Y-%m-%d'), 'StartTime':'08:00', 'Crew1Name':'Desktop crew', 'Open':'true', 'ChangeCount':'1'}
sync('efa2logbook', gap, 'insert')
blocked = request(portal, 'POST', '/trips', {'boatId':gap['BoatId'], 'crew':[{'id':'22222222-2222-4222-a222-222222222201'}], 'destinationName':'Desktop gap'}, 409)
check('BOAT_ON_WATER' in str(blocked), 'unattributed desktop checkout blocks before status arrives')
check(mine() == [], 'desktop gap trip never enters personal list')
sync('efa2logbook', {**gap, 'Open':'false'})

trips = []
for index in range(3):
    body = {'boatId':f'11111111-1111-4111-a111-11111111110{index+1}', 'boatVariant':'1', 'crew':[{'id':f'22222222-2222-4222-a222-22222222220{index+1}'}], 'destinationName':'Acceptance', 'idempotencyKey':['live-initial','live-biber','live-cirrus'][index]}
    result = request(portal, 'POST', '/trips', body, 201)
    trips.append(result['trip'])
    replay = request(portal, 'POST', '/trips', body, 201)
    check(replay['_idempotentReplay'] and replay['trip']['ecrid'] == result['trip']['ecrid'], 'durable replay over separate HTTP requests')
check(len(mine()) == 3, 'real API returns three checkouts for organizer absent from all crews')
check(mine(member) == [], 'real API excludes participation-only and unattributed desktop trips')
request(member, 'POST', f'/trips/{trips[0]["entryId"]}/finish', {**reference(trips[0]),'distance':'8 km'}, 403)
request(portal, 'GET', '/trips/3?logbookName=2026', expected=403)
# Legacy desktop protocol must reject a second checkout and preserve the portal record.
t = trips[0]
conflict = sync('efa2logbook', {'ecrid':uuid.uuid4().hex[:12],'Logbookname':t['logbookName'],'BoatId':t['boatId'],'BoatName':t['boatName'],'Date':t['date'],'StartTime':t['startTime'],'Crew1Id':t['crew'][0]['id'],'Crew1Name':t['crew'][0]['name'],'Open':'true','ChangeCount':'1','DestinationName':'Conflicting desktop checkout'}, 'insert', False)
check('CONFLICT' in conflict['decoded'], 'posttx conflict is explicit')
check(len(mine()) == 3, 'desktop rejected checkout does not disturb personal trips')
# Desktop closes the first trip and returns its boat through real posttx requests.
sync('efa2logbook', {'ecrid':t['ecrid'],'Logbookname':t['logbookName'],'EntryId':t['entryId'],'Date':t['date'],'StartTime':t['startTime'],'Open':'false','EndTime':datetime.now(ZoneInfo('Europe/Berlin')).strftime('%H:%M'),'Distance':'8 km','ChangeCount':str(t['changeCount'])})
boat = request(portal,'GET',f'/boats/{t["boatId"]}')
sync('efa2boatstatus', {'ecrid':boat['status']['ecrid'],'BoatId':t['boatId'],'CurrentStatus':'AVAILABLE','EntryNo':'','Logbook':'','Comment':'','ChangeCount':str(boat['status']['ChangeCount'])})
check(len(mine()) == 2, 'desktop return removes exactly one personal trip over HTTP')
request(portal,'POST',f'/trips/{t["entryId"]}/finish',{**reference(t),'distance':'8 km'},422)
# Desktop edits a remaining record, and stale portal submissions reload its version.
t = trips[1]
sync('efa2logbook', {'ecrid':t['ecrid'],'Logbookname':t['logbookName'],'EntryId':t['entryId'],'Date':t['date'],'DestinationName':'Edited at computer','ChangeCount':str(t['changeCount'])})
request(portal,'POST',f'/trips/{t["entryId"]}/finish',{**reference(t),'distance':'8 km'},409)
fresh = request(portal,'GET',f'/trips/{t["entryId"]}?logbookName={t["logbookName"]}')['trip']
check(fresh['destinationName'] == 'Edited at computer', 'portal GET reads desktop edit with stable attribution')
correct = request(portal,'PATCH',f'/trips/{t["entryId"]}', {**reference(fresh),'crew':[{'id':''},{'id':'22222222-2222-4222-a222-222222222201'}],'boatCaptain':'2','idempotencyKey':'live-correction'})['trip']
check(correct['crew'][0]['position'] == 2 and len(correct['crew']) == 1, 'actual SQL correction preserves numbered seats')
finish_body = {**reference(correct),'distance':'8 km','idempotencyKey':'live-finish'}
request(portal,'POST',f'/trips/{t["entryId"]}/finish', finish_body)
check(request(portal,'POST',f'/trips/{t["entryId"]}/finish', finish_body)['_idempotentReplay'], 'HTTP finish lost-response replay')
# Cancellation with damage is a single durable action.
t = trips[2]
abort_body = {**reference(t),'withDamage':{'severity':'FULLYUSEABLE','description':'Never left dock'},'idempotencyKey':'live-abort'}
request(portal,'POST',f'/trips/{t["entryId"]}/abort',abort_body)
check(request(portal,'POST',f'/trips/{t["entryId"]}/abort',abort_body)['_idempotentReplay'], 'HTTP cancellation replay')
check(mine() == [], 'all real API checkouts handled independently')
print('Real API and desktop posttx acceptance passed.', flush=True)
