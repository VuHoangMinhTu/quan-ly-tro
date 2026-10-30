import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { spawn, execFileSync } from 'node:child_process'
const root=fileURLToPath(new URL('../../',import.meta.url))
const require=createRequire(resolve(root,'front-end/package.json'))
const { createServer }=await import(pathToFileURL(require.resolve('vite')))
const { chromium }=createRequire('C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json')('playwright')
const output=resolve(root,'tmp/house-idempotency-browser'); await mkdir(output,{recursive:true})
const php='C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe'
const mysql='C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe'
const sql=query=>execFileSync(mysql,['--no-defaults','--protocol=TCP','-h','127.0.0.1','-P','13317','-u','root','--skip-password','-N','beehouse_concurrency_test','-e',query],{encoding:'utf8',stdio:['ignore','pipe','ignore']}).trim()
assert.equal(sql('SELECT DATABASE()'),'beehouse_concurrency_test')
const backend=spawn(php,['-S','127.0.0.1:18946',resolve(root,'tests/e2e/mysql-house-test.php')],{cwd:resolve(root,'backend-api'),stdio:'ignore'})
const server=await createServer({root:resolve(root,'front-end'),configFile:resolve(root,'front-end/vite.config.js'),define:{'import.meta.env.VITE_API_BASE_URL':JSON.stringify('/api')},plugins:[{name:'real-api-audit',configureServer(vite){vite.middlewares.use('/floor-audit',async(_req,res)=>{res.setHeader('Content-Type','text/html');res.end(await vite.transformIndexHtml('/floor-audit','<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="root"></div><script type="module" src="/tests/boarding-house-floors-audit.jsx"></script></body></html>'))})}}],server:{host:'127.0.0.1',port:18945,strictPort:true,proxy:{'/api':'http://127.0.0.1:18946'}}})
let browser
const results=[]
try {
 await server.listen(); browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true})
 const context=await browser.newContext({viewport:{width:1366,height:900}}); await context.tracing.start({screenshots:true,snapshots:true})
 const email=sql('SELECT email FROM users ORDER BY id LIMIT 1')
 const login=await context.request.post('http://127.0.0.1:18945/api/login',{data:{email,password:'password'}})
 assert.equal(login.status(),200); const token=(await login.json()).data.token
 await context.addInitScript(token=>localStorage.setItem('access_token',token),token)
 const page=await context.newPage(); let mode='normal'; const requests=[]; let pending=false
 const counts=()=>sql('SELECT CONCAT((SELECT COUNT(*) FROM boarding_houses),",",(SELECT COUNT(*) FROM floors),",",(SELECT COUNT(*) FROM rooms))').split(',').map(Number)
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url()); assert.equal(url.origin,'http://127.0.0.1:18945')
  if(url.pathname!=='/api/boarding-houses/with-rooms') return route.continue()
  requests.push(req.postDataJSON()); pending=true
  if(mode==='validation'){const payload=req.postDataJSON();payload.rooms[0].room_code='';const response=await route.fetch({postData:JSON.stringify(payload)});pending=false;return route.fulfill({response})}
  const response=await route.fetch();assert.equal(response.status(),201)
  if(mode==='lost'){mode='normal';pending=false;return route.abort('failed')}
  await new Promise(r=>setTimeout(r,700));pending=false;return route.fulfill({response})
 })
 const fill=async name=>{
  await page.goto('http://127.0.0.1:18945/floor-audit'); await page.getByLabel('Tên nhà trọ',{exact:true}).fill(name);await page.getByLabel('Địa chỉ',{exact:true}).fill('Synthetic address')
  await page.getByRole('button',{name:'Tiếp tục: Tạo phòng →',exact:true}).click()
  for(let i=1;i<=2;i++){await page.getByRole('button',{name:'+ Thêm tầng',exact:true}).click();await page.getByRole('textbox',{name:`Tên tầng ${i}`,exact:true}).fill(`Floor ${i}`)}
  await page.getByRole('combobox',{name:'Tầng mặc định',exact:true}).selectOption({label:'Floor 1'});await page.locator('#bulk-from').fill('1');await page.locator('#bulk-to').fill('20');await page.getByRole('button',{name:'Sinh mã phòng',exact:true}).click()
  for(let i=11;i<=20;i++)await page.getByRole('combobox',{name:`Tầng phòng dòng ${i}`,exact:true}).selectOption({label:'Floor 2'})
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false)
 }
 const submit=()=>page.getByRole('button',{name:'Tạo nhà trọ và 20 phòng',exact:true})
 const success=()=>page.getByText('Đã tạo nhà trọ kiểm thử',{exact:true}).waitFor()
 for(const width of [1366,390]){
  await page.setViewportSize({width,height:900})
  await fill(`Normal ${width}`);let before=counts();await submit().click();await page.getByRole('button',{name:'Đang tạo...',exact:true}).waitFor();assert.equal(await page.getByRole('button',{name:'Đang tạo...',exact:true}).isDisabled(),true);await page.screenshot({path:resolve(output,`loading-${width}.png`),fullPage:true});await success();assert.deepEqual(counts(),before.map((n,i)=>n+[1,2,20][i]));results.push(`A ${width} PASS`)
  await fill(`Double ${width}`);before=counts();let n=requests.length;await submit().evaluate(b=>{b.click();b.click()});await success();assert.equal(requests.length,n+1);assert.deepEqual(counts(),before.map((v,i)=>v+[1,2,20][i]));assert.equal(await page.locator('[aria-label="Thông báo"] [role="status"]').count(),1);results.push(`B ${width} PASS`)
  await fill(`Lost ${width}`);mode='lost';before=counts();await submit().click();await page.getByText(/Chưa xác định thao tác/).first().waitFor();const key=requests.at(-1).idempotency_key;await page.screenshot({path:resolve(output,`lost-${width}.png`),fullPage:true});await submit().click();await success();assert.equal(requests.at(-1).idempotency_key,key);assert.deepEqual(counts(),before.map((v,i)=>v+[1,2,20][i]));results.push(`C ${width} PASS`)
  await fill(`Reload ${width}`);mode='lost';before=counts();await submit().click();await page.getByText(/Chưa xác định thao tác/).first().waitFor();const reloadKey=requests.at(-1).idempotency_key;await page.reload();await submit().waitFor();await page.screenshot({path:resolve(output,`restored-${width}.png`),fullPage:true});await submit().click();await success();assert.equal(requests.at(-1).idempotency_key,reloadKey);assert.deepEqual(counts(),before.map((v,i)=>v+[1,2,20][i]));results.push(`D ${width} PASS`)
  await fill(`Validation ${width}`);mode='validation';before=counts();await submit().click();await page.locator('[role="alert"]').first().waitFor();assert.deepEqual(counts(),before);assert.equal(await page.getByRole('combobox',{name:/^Tầng phòng dòng /}).count(),20);mode='normal';await submit().click();await success();results.push(`E ${width} PASS`)
  const previousKey=requests.at(-1).idempotency_key;await fill(`New ${width}`);await submit().click();await success();assert.notEqual(requests.at(-1).idempotency_key,previousKey);results.push(`F ${width} PASS`)
 }
 assert.equal(pending,false);await context.tracing.stop({path:resolve(output,'trace.zip')});await writeFile(resolve(output,'results.json'),JSON.stringify(results,null,2));console.log(results.join('\n'))
} finally {await browser?.close();await server.close();backend.kill()}
