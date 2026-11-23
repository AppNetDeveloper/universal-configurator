// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Capturas de la app con la demostración cargada (bin/uc demo), en escritorio, tablet y móvil.
// Uso (con el servidor de desarrollo en 127.0.0.1:8190 y la demo recién cargada, ver docs/CAPTURAS.md):
//   PW_CORE=/ruta/a/playwright-core CHROME=/ruta/a/chrome node tests/capturas.cjs [salida] [solo-esta-pantalla]
// No es una prueba: es la herramienta que hace las capturas de docs/capturas.

const { chromium } = require(process.env.PW_CORE || 'playwright-core');
const fs = require('fs');

const BASE = process.env.UC_BASE || 'http://127.0.0.1:8190';
const OUT = process.argv[2] || 'docs/capturas';
const ONLY = process.argv[3] || '';
const VIEWPORTS = { escritorio: { width: 1440, height: 900 }, tablet: { width: 1024, height: 768 }, movil: { width: 390, height: 844 } };
const ADMIN = ['admin@example.org', 'admin-12345'];
const COMERCIAL = ['laura.martin@example.com', 'demo-2026-clave'];

async function login(page, [email, pw]) {
  await page.goto(BASE + '/');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', pw);
  await Promise.all([page.waitForNavigation(), page.click('form[action="/entrar"] button[type=submit]')]);
}

async function api(page, method, path, body) {
  return page.evaluate(async ([method, path, body]) => {
    const csrf = JSON.parse(document.getElementById('uc-data')?.textContent || '{}').csrf || document.querySelector('meta[name=csrf-token]')?.content;
    const r = await fetch(path, { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: body ? JSON.stringify(body) : undefined });
    return r.json();
  }, [method, path, body]);
}

async function shot(page, name, vp, opts = {}) {
  if (ONLY && !name.includes(ONLY)) return;
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(250);
  const file = `${OUT}/${name}-${vp}.png`;
  const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (over > 0) console.warn(`AVISO ${name}-${vp}: la página se sale ${over} px por la derecha`);
  await page.screenshot({ path: file, fullPage: opts.fullPage !== false });
  console.log(file);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROME, args: ['--no-sandbox'] });
  // Una sola configuración nueva para todas las pantallas (Dúo en roble, cajonera a 58,5 y bajo a 50,5, en hueco de tramo)
  // y la aceptada de la demo de Laura: así «Mis configuraciones» y el panel no se llenan de copias.
  let c = await browser.newContext({ viewport: VIEWPORTS.escritorio }); let p = await c.newPage(); await login(p, COMERCIAL);
  await p.goto(BASE + '/configurar');
  await Promise.all([p.waitForNavigation(), p.click('.node-card:has(.node-name:text-is("Cocina"))')]);
  await Promise.all([p.waitForNavigation(), p.click('.node-card:has(.node-name:text-is("Dúo"))')]);
  await Promise.all([p.waitForNavigation(), p.click('.node-card:has(.node-name:text-is("Roble"))')]);
  const code = p.url().split('/').pop();
  const cfg = (await api(p, 'GET', `/api/configurations/${code}`)).config;
  const item = (ref) => cfg.walls.flatMap((w) => w.items).find((it) => it.ref === ref || it.type_ref === ref);
  await api(p, 'POST', `/api/configurations/${code}/ops`, { ops: [
    { op: 'set_width', item_id: item('CAJ3').id, width_cm: '58,5' },
    { op: 'set_width', item_id: item('B1P').id, width_cm: '50,5' },
    { op: 'customer', name: 'Rocío Ibáñez', email: 'rocio.ibanez@example.com', phone: '677 120 984' },
  ] });
  await p.goto(BASE + '/mis-configuraciones');
  const accepted = await p.locator('tr:has(.badge-accepted) a.mono').first().textContent();
  await c.close();
  for (const [vp, size] of Object.entries(VIEWPORTS)) {
    const mobile = vp === 'movil';
    const ctx = () => browser.newContext({ viewport: size, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: vp !== 'escritorio', locale: 'es-ES' });

    // --- público
    c = await ctx(); p = await c.newPage();
    await p.goto(BASE + '/'); await shot(p, 'entrar', vp);
    await p.goto(BASE + '/registro'); await shot(p, 'registro', vp);
    await c.close();

    // --- comercial: paso 1 de cada familia, paso 2 con 58,5 y un hueco, paso 3, aceptada, mis configuraciones
    c = await ctx(); p = await c.newPage(); await login(p, COMERCIAL);
    await p.goto(BASE + '/configurar'); await shot(p, 'paso1-familias', vp);
    for (const fam of ['Cocina', 'Armarios', 'Baños']) {
      await p.goto(BASE + '/configurar');
      await Promise.all([p.waitForNavigation(), p.click(`.node-card:has(.node-name:text-is("${fam}"))`)]);
      await shot(p, 'paso1-' + fam.toLowerCase().replace('ñ', 'n'), vp);
    }
    await p.goto(BASE + '/configurar');
    await Promise.all([p.waitForNavigation(), p.click('.node-card:has(.node-name:text-is("Armarios"))')]);
    await Promise.all([p.waitForNavigation(), p.click('.node-card:has(.node-name:text-is("Batiente"))')]);
    await shot(p, 'paso1-acabados', vp);
    await p.goto(BASE + `/configurar/${code}`); await p.waitForSelector('.mod');
    await shot(p, 'paso2-modulos', vp, { fullPage: false });
    // la barra del total es fija: para enseñar el hueco de tramo, la vista justo en ese módulo
    await p.locator('.mod:has(.mod-name:text-is("Bajo 1 puerta"))').first().evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await shot(p, 'paso2-hueco-de-tramo', vp, { fullPage: false });
    await p.goto(BASE + `/configurar/${code}/resumen`); await shot(p, 'paso3-resumen', vp);
    await p.goto(BASE + `/configurar/${accepted.trim()}/resumen`); await shot(p, 'paso3-aceptada', vp);
    await p.goto(BASE + '/mis-configuraciones'); await shot(p, 'mis-configuraciones', vp);
    await c.close();

    // --- panel del administrador
    c = await ctx(); p = await c.newPage(); await login(p, ADMIN);
    const admin = [['panel-inicio', '/admin'], ['panel-catalogo', '/admin/catalogo'], ['panel-nodo', '/admin/catalogo/2'],
      ['panel-tipos', '/admin/tipos'], ['panel-tipo-tramos-despiece', '/admin/tipos/3'], ['panel-tipo-corredera', '/admin/tipos/14'],
      ['panel-composicion', '/admin/composicion/7'], ['panel-configuraciones', '/admin/configuraciones'],
      ['panel-configuraciones-filtro', '/admin/configuraciones?status=accepted'], ['panel-ficha', `/admin/configuraciones/${code}`],
      ['panel-personas', '/admin/usuarios'], ['panel-ajustes', '/admin/ajustes'], ['panel-tokens', '/admin/tokens'], ['panel-actividad', '/admin/actividad']];
    for (const [name, path] of admin) {
      await p.goto(BASE + path);
      if (name === 'panel-ficha') await p.locator('details').nth(2).evaluate((d) => { d.open = true; }); // despiece de la cajonera a 58,5
      await shot(p, name, vp);
    }
    await c.close();
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
