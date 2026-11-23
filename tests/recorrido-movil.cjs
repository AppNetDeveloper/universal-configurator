// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Recorrido del cliente final en el móvil (iPhone 13, capturas de lo que se ve en pantalla) con medidas del paso 2:
// dónde empieza el primer módulo, cuántas pantallas ocupan 10 módulos y cuánto pesa la pantalla. Quita un módulo y lo
// deshace. Uso: PW_CORE=/ruta/a/playwright-core CHROME=/ruta/a/chrome node tests/recorrido-movil.cjs [salida]
// (servidor de desarrollo en 127.0.0.1:8190 con la demo y el invitado permitido; no es una prueba automática).
const { chromium, devices } = require(process.env.PW_CORE || 'playwright-core');
const B = process.env.UC_BASE || 'http://127.0.0.1:8190';
const OUT = process.argv[2] || '/tmp/uc-recorrido';
require('fs').mkdirSync(OUT, { recursive: true });
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ ...devices['iPhone 13'] });
  const p = await ctx.newPage();
  const errs = []; p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', e => errs.push(String(e)));
  const shot = async (n) => { await p.waitForTimeout(350); await p.screenshot({ path: `${OUT}/${n}.png`, fullPage: false }); console.log('shot', n, p.url()); };
  await p.goto(B + '/'); await shot('m01_inicio');
  await Promise.all([p.waitForNavigation(), p.getByRole('button', { name: /Empezar/ }).click()]);
  await shot('m02_paso1');
  for (const n of ['Cocina', 'Dúo', 'Roble']) {
    await Promise.all([p.waitForNavigation(), p.locator('.choice', { has: p.locator(`.choice-name:text-is("${n}")`) }).first().click()]);
    await shot('m02b_' + n);
  }
  await p.waitForSelector('.mod');
  const met = await p.evaluate(() => ({ firstMod: Math.round(document.querySelector('.mod').getBoundingClientRect().top), vh: innerHeight,
    screens: +(document.documentElement.scrollHeight / innerHeight).toFixed(2), mods: document.querySelectorAll('.mod').length,
    bytes: performance.getEntriesByType('resource').reduce((a, r) => a + (r.transferSize || r.encodedBodySize || 0), 0) + (performance.getEntriesByType('navigation')[0]?.transferSize || 0) }));
  console.log('METRICAS paso2', JSON.stringify(met), 'primer módulo al', Math.round(met.firstMod / met.vh * 100) + '% de la pantalla');
  await shot('m03_paso2_arriba');
  await p.evaluate(() => window.scrollBy(0, 700)); await shot('m04_paso2_medio');
  await p.locator('.step-btn[aria-label="+1 cm"]').first().click(); await p.waitForTimeout(1200);
  await shot('m05_tras_mas');
  await p.locator('.mod-head').nth(2).click(); await p.waitForTimeout(400); await shot('m05b_desplegado');
  await p.locator('.mod.is-open .btn', { hasText: 'Quitar' }).click(); await p.waitForTimeout(900); await shot('m05c_quitado_con_deshacer');
  await p.locator('.toast-action').click(); await p.waitForTimeout(900);
  console.log('tras deshacer, módulos:', await p.locator('.mod').count());
  await Promise.all([p.waitForNavigation(), p.click('#to-summary')]);
  await shot('m06_paso3');
  await p.evaluate(() => window.scrollBy(0, 2000)); await shot('m07_paso3_abajo');
  console.log('errores consola:', JSON.stringify(errs));
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
