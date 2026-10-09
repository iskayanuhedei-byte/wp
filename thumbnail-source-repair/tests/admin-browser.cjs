const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');

const html = `<!doctype html><html><body>
<fieldset id="wtsr-mode-controls">
<input type="radio" name="wtsr_mode" value="preview" checked>
<input type="radio" name="wtsr_mode" value="repair">
<input type="radio" name="wtsr_mode" value="repair_delete">
<div id="wtsr-delete-confirmation" hidden><input type="checkbox" id="wtsr-confirm-delete" disabled></div>
</fieldset>
<button id="wtsr-run">Run</button><button id="wtsr-stop" disabled>Stop</button>
<p id="wtsr-status"></p><div id="wtsr-error" hidden><p></p></div>
<p id="wtsr-empty"></p><table><tbody id="wtsr-results"></tbody></table>
<form id="wtsr-settings-form"><textarea></textarea><input type="submit"></form>
${['scanned','healthy','repairable','fixed','missing','retry','deleted'].map(s => `<span id="wtsr-count-${s}">0</span>`).join('')}
<script>window.WTSR_ADMIN={ajax_url:'http://wtsr.test/admin-ajax.php',nonce:'fixture-nonce'};</script>
</body></html>`;
const items = [
 {post_id:1,title:'Healthy',state:'healthy',message:'Baik',evidence:[],fingerprint:'a'.repeat(64)},
 {post_id:2,title:'<img src=x onerror="window.pwned=1">',edit_url:'javascript:alert(1)',state:'repairable',message:'Dapat diperbaiki',evidence:['<script>window.pwned=1</script>'],fingerprint:'b'.repeat(64)},
 {post_id:3,title:'Missing',edit_url:'http://wtsr.test/edit.php?post=3',state:'missing',message:'Tak ada gambar',evidence:[],fingerprint:'c'.repeat(64)}
];

(async () => {
 const browser = await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE_PATH ? {executablePath:process.env.CHROMIUM_EXECUTABLE_PATH} : {})});
 try {
  async function pageFor(processDelay=0) {
   const page = await browser.newPage();
   const calls = [];
   let active=0,maxActive=0;
   await page.route('http://wtsr.test/**', async route => {
    if (route.request().url().endsWith('/admin-ajax.php')) {
     const body = new URLSearchParams(route.request().postData());
     const call = Object.fromEntries(body);
     calls.push(call);
     active += 1; maxActive=Math.max(maxActive,active);
     let data;
     if(call.action==='wtsr_scan') {
      assert.equal(call.limit,'1');
      const index = Number(call.cursor);
      data={items:index<items.length?[items[index]]:[],next_cursor:index+1,done:index+1>=items.length};
     } else {
      assert.equal(call.action,'wtsr_process');
      if(processDelay) await new Promise(resolve=>setTimeout(resolve,processDelay));
      const id=Number(call.post_id);
      const item=items[id-1];
      data={...item,state:id===2?'fixed':call.mode==='repair_delete'?'deleted':'missing',message:'Hasil tindakan'};
     }
     await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
     active-=1;
    } else {
     await route.fulfill({contentType:'text/html',body:html});
    }
   });
   await page.goto('http://wtsr.test/tools.php');
   await page.addScriptTag({path:path.resolve(__dirname, '../assets/admin.js')});
   return {page,calls,maxActive:()=>maxActive};
  }
  {
   const {page,calls,maxActive} = await pageFor();
   assert.equal(await page.locator('[name=wtsr_mode][value=preview]').isChecked(),true);
   await page.locator('#wtsr-run').click();
   await page.waitForFunction(()=>document.querySelector('#wtsr-status').textContent.startsWith('Selesai.'));
   assert.deepEqual(calls.map(c=>c.action),['wtsr_scan','wtsr_scan','wtsr_scan']);
   assert.equal(await page.locator('#wtsr-count-scanned').textContent(),'3');
   assert.equal(await page.locator('#wtsr-count-healthy').textContent(),'1');
   assert.equal(await page.locator('#wtsr-results tr').count(),2);
   assert.equal(await page.locator('#wtsr-results img, #wtsr-results script').count(),0);
   assert.equal(await page.locator('#wtsr-results a').count(),1);
   assert.equal(await page.evaluate(()=>window.pwned),undefined);
   assert.equal(maxActive(),1);
   console.log('PASS preview: read-only, counts, bounded serial scan, hostile text escaped');
   await page.close();
  }
  {
   const {page,calls,maxActive} = await pageFor();
   await page.locator('[name=wtsr_mode][value=repair_delete]').check();
   assert.equal(await page.locator('#wtsr-run').isDisabled(),true);
   assert.equal(await page.locator('#wtsr-delete-confirmation').isVisible(),true);
   await page.locator('#wtsr-confirm-delete').check();
   assert.equal(await page.locator('#wtsr-run').isDisabled(),false);
   await page.locator('#wtsr-run').click();
   await page.waitForFunction(()=>document.querySelector('#wtsr-status').textContent.startsWith('Selesai.'));
   assert.deepEqual(calls.map(c=>c.action),['wtsr_scan','wtsr_scan','wtsr_process','wtsr_scan','wtsr_process']);
   calls.filter(c=>c.action==='wtsr_process').forEach(c=>assert.equal(c.confirm_delete,'1'));
   assert.equal(await page.locator('#wtsr-count-fixed').textContent(),'1');
   assert.equal(await page.locator('#wtsr-count-deleted').textContent(),'1');
   assert.equal(await page.locator('#wtsr-results a').count(),0);
   assert.equal(maxActive(),1);
   console.log('PASS destructive mode: explicit checkbox, server confirmation, serial actions, deleted link removed');
   await page.close();
  }
  {
   const {page,calls} = await pageFor(800);
   await page.locator('[name=wtsr_mode][value=repair]').check();
   await page.locator('#wtsr-run').click();
   await page.waitForFunction(()=>document.querySelector('#wtsr-results').textContent.includes('Sedang diproses'));
   await page.locator('#wtsr-stop').click();
   await page.waitForFunction(()=>document.querySelector('#wtsr-status').textContent.startsWith('Dihentikan.'));
   assert.deepEqual(calls.map(c=>c.action),['wtsr_scan','wtsr_scan','wtsr_process']);
   assert.equal(calls[2].confirm_delete,'0');
   assert.equal(await page.locator('#wtsr-count-fixed').textContent(),'1');
   assert.equal(await page.locator('#wtsr-run').isDisabled(),false);
   console.log('PASS stop: finishes in-flight action, skips next post, unlocks controls');
   await page.close();
  }
 } finally {
  await browser.close();
 }
})().catch(error=>{console.error(error);process.exit(1)});
