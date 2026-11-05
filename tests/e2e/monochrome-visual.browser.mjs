import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { accounts, startSyntheticServer } from './synthetic-session-server.mjs'
const root=fileURLToPath(new URL('../../',import.meta.url))
const { chromium }=createRequire('C:/Users/Yato/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/package.json')('playwright')
const dist=process.env.VISUAL_DIST || resolve(root,'front-end/dist')
const output=resolve(root,'tmp/monochrome-visual',process.env.VISUAL_PHASE || 'after')
await mkdir(output,{recursive:true})
const server=await startSyntheticServer(dist)
const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true})
const results=[]
try {
 for(const width of [360,390,768,1024,1440,1920]){
  const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'})
  await context.route('**/*',route=>new URL(route.request().url()).origin===server.origin?route.continue():route.abort())
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message))
  await page.goto(server.origin+'/');await page.locator('h1').first().waitFor();await page.evaluate(()=>document.fonts.ready)
  if(!process.env.VISUAL_PHASE){
   await page.getByRole('heading',{name:/Quản lý nhà trọ/}).waitFor()
   assert.equal(await page.locator('h1').count(),1)
   assert.equal(await page.getByRole('link',{name:'Bắt đầu với BeeHouse',exact:true}).getAttribute('href'),'/register')
   await page.keyboard.press('Tab');assert.equal(await page.locator('.skip-link').evaluate(e=>e===document.activeElement),true)
   await page.locator('h1').click()
   if(width<768){await page.getByRole('button',{name:'Mở menu',exact:true}).click();await page.getByRole('navigation',{name:'Điều hướng di động'}).waitFor();await page.getByRole('button',{name:'Đóng menu',exact:true}).click()}
  }
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false)
  await page.screenshot({path:resolve(output,`landing-${width}.png`),fullPage:true})
  await page.screenshot({path:resolve(output,`landing-hero-${width}.png`)})
  await page.goto(server.origin+'/login');await page.getByLabel('Email',{exact:true}).fill(accounts.A.user.email);await page.getByLabel('Mật khẩu',{exact:true}).fill(accounts.A.password)
  await page.screenshot({path:resolve(output,`login-${width}.png`),fullPage:true})
  await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();await page.getByRole('heading',{name:'Tổng quan vận hành',exact:true}).waitFor()
  for(const [path,name] of [['/dashboard','dashboard'],['/boarding-houses','houses'],['/boarding-houses/101/rooms','rooms'],['/tenants','tenants']]){
   await page.goto(server.origin+path);await page.locator('main h2').first().waitFor();await page.waitForTimeout(150)
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,`${name} ${width}`)
   await page.screenshot({path:resolve(output,`${name}-${width}.png`),fullPage:true})
  }
  await page.goto(server.origin+'/');await page.getByRole('heading',{name:'Tổng quan vận hành',exact:true}).waitFor()
  assert.deepEqual(errors,[]);results.push({width,guestLanding:true,login:true,authenticatedRoot:true,noOverflow:true,errors});await context.close()
 }
 await writeFile(resolve(output,'results.json'),JSON.stringify(results,null,2));console.log(JSON.stringify(results))
}finally{await browser.close();await server.close()}
