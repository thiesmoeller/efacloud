#!/usr/bin/env python3
"""Force the desktop to validate a status write while portal checkout is uncommitted."""
from pathlib import Path
import os, sys, subprocess, json
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'lab' / 'backup_rehearsal'))
from portal_client import PortalClient
from posttx_client import PosttxClient
base = os.environ.get('PORTAL_LIVE_BASE_URL','http://127.0.0.1:18089')
portal = PortalClient(base); portal.login('102','fixture-pass')
boat_id = '11111111-1111-4111-a111-111111111101'
status = portal.boat(boat_id)['status']
assert status['CurrentStatus'] == 'AVAILABLE'
container = os.environ.get('PORTAL_LIVE_CONTAINER','efa-dockside-acceptance-web')
child = subprocess.Popen(['docker','exec',container,'php','/acceptance/hold-checkout.php'],stdout=subprocess.PIPE,text=True)
assert child.stdout.readline().strip() == 'CHECKOUT_UNCOMMITTED'
# A stale desktop status write passes the old guard if it can inspect uncommitted state.
result = PosttxClient(base,103,'fixture-pass').modify_many([{'type':'update','table':'efa2boatstatus','record':{
    'ecrid':status['ecrid'],'BoatId':boat_id,'CurrentStatus':'AVAILABLE','EntryNo':'','Logbook':'','ChangeCount':status['ChangeCount']}}])
output = child.communicate(timeout=20)[0]; assert child.returncode == 0, output
status_after = portal.boat(boat_id)['status']
code, mine = portal._request('GET','/trips?scope=started-by-me&status=open')
print('Desktop accepted:',result['ok'],'; boat status:',status_after['CurrentStatus'],'; attributed open trips:',len(mine['trips']),flush=True)
assert not result['ok'], 'Desktop status write must revalidate after portal commits'
assert status_after['CurrentStatus'] == 'ONTHEWATER'
t = mine['trips'][0]
code, data = portal.abort_trip(t['entryId'],{'logbookName':t['logbookName'],'ecrid':t['ecrid'],'expectedChangeCount':t['changeCount'],'idempotencyKey':'held-cleanup'})
assert code == 200, data
print('PASS simultaneous desktop status write cannot overwrite portal checkout',flush=True)
