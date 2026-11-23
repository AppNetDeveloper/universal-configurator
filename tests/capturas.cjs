// Universal Configurator · Copyright (c) 2026 BionicAdri · Licencia BionicAdri 2.0 (ver LICENSE)
//
// Capturas de la app con la demostración cargada (bin/uc demo), en escritorio, tablet y móvil.
// Uso (con el servidor de desarrollo en 127.0.0.1:8190, la demo recién cargada y el invitado permitido; ver
// docs/CAPTURAS.md):
//   PW_CORE=/ruta/a/playwright-core CHROME=/ruta/a/chrome node tests/capturas.cjs [salida] [solo-esta-pantalla]
// No es una prueba: es la herramienta que hace las capturas de docs/capturas. Avisa si una página se sale por la derecha.
// UC_ADRI_ON=1: solo las del panel con «Adri conectada» (hay que haber usado un token antes; ver docs/CAPTURAS.md).

const { chromium } = require(process.env.PW_CORE || 'playwright-core');
const fs = require('fs');

const BASE = process.env.UC_BASE || 'http://127.0.0.1:8190';
const OUT = process.argv[2] || 'docs/capturas';
const ONLY = process.argv[3] || '';
const VIEWPORTS = { escritorio: { width: 1440, height: 900 }, tablet: { width: 1024, height: 768 }, movil: { width: 390, height: 844 } };
const ADMIN = ['admin@example.org', 'admin-12345'];
const COMERCIAL = ['laura.martin@example.com', 'demo-2026-clave'];

async function login(page, [email, pw]) {
  await page.goto(BASE + '/entrar');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', pw);
  await Promise.all([page.waitForNavigation(), page.click('form[action="/entrar"] button[type=submit]')]);
}
const choose = (p, name) => Promise.all([p.waitForNavigation(), p.locator('.choice', { has: p.locator(`.choice-name:text-is("${name}")`) }).first().click()]);

async function api(page, method, path, body) {
  return page.evaluate(async ([method, path, body]) => {
    const csrf = JSON.parse(document.getElementById('uc-data')?.textContent || '{}').csrf;
    const r = await fetch(path, { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: body ? JSON.stringify(body) : undefined });
    return r.json();
  }, [method, path, body]);
}

async function shot(page, name, vp, opts = {}) {
  if (ONLY && !name.includes(ONLY)) return;
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);
  const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (over > 0) console.warn(`AVISO ${name}-${vp}: la página se sale ${over} px por la derecha`);
  const file = `${OUT}/${name}-${vp}.png`;
  await page.screenshot({ path: file, fullPage: opts.fullPage !== false });
  console.log(file);
}

/** Configuración nueva de cocina Dúo en roble con la cajonera a 58,5 y el bajo a 50,5 (hueco de tramo). */
async function makeDuo(p, client) {
  await p.goto(BASE + '/configurar');
  await choose(p, 'Cocina'); await choose(p, 'Dúo'); await choose(p, 'Roble');
  const code = p.url().split('/').pop();
  const cfg = (await api(p, 'GET', `/api/configurations/${code}`)).config;
  const item = (ref) => cfg.walls.flatMap((w) => w.items).find((it) => it.ref === ref);
  await api(p, 'POST', `/api/configurations/${code}/ops`, { ops: [
    { op: 'set_width', item_id: item('CAJ3').id, width_cm: '58,5' },
    { op: 'set_width', item_id: item('B1P').id, width_cm: '50,5' },
  ].concat(client ? [] : [{ op: 'customer', name: 'Rocío Ibáñez', email: 'rocio.ibanez@example.com', phone: '677 120 984' }]) });
  return code;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROME, args: ['--no-sandbox'] });
  const adriOn = !!process.env.UC_ADRI_ON;
  for (const [vp, size] of Object.entries(VIEWPORTS)) {
    const mobile = vp === 'movil';
    const ctx = () => browser.newContext({ viewport: size, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: vp !== 'escritorio', locale: 'es-ES' });
    let c, p;

    if (!adriOn) {
      // --- cliente final (invitado): portada, paso 1, paso 2, paso 3, «Quiero este presupuesto», enlace compartido
      c = await ctx(); p = await c.newPage();
      await p.goto(BASE + '/'); await shot(p, 'portada', vp, { fullPage: !mobile });
      await p.goto(BASE + '/entrar'); await shot(p, 'entrar-equipo', vp);
      await p.goto(BASE + '/');
      await Promise.all([p.waitForNavigation(), p.click('form[action="/invitado"] button')]);
      await shot(p, 'paso1-familias', vp);
      await choose(p, 'Cocina'); await shot(p, 'paso1-cocina', vp);
      await p.goto(BASE + '/configurar'); await choose(p, 'Armarios'); await choose(p, 'Batiente'); await shot(p, 'paso1-acabados', vp);
      await p.goto(BASE + '/configurar'); await choose(p, 'Baños'); await shot(p, 'paso1-banos', vp);
      const gcode = await makeDuo(p, true);
      await p.goto(BASE + `/configurar/${gcode}`); await p.waitForSelector('.mod');
      await shot(p, 'paso2-cliente', vp, { fullPage: false });
      await p.locator('.plan-box').nth(3).click(); await p.waitForTimeout(800);
      await shot(p, 'paso2-hueco-de-tramo', vp, { fullPage: false });
      await p.locator('.mod.is-open .btn', { hasText: 'Cambiar' }).click(); await p.waitForTimeout(400);
      await p.locator('.mod.is-open .picker').evaluate((el) => el.scrollIntoView({ block: 'center' }));
      await shot(p, 'paso2-cambiar-por', vp, { fullPage: false });
      await p.locator('.mod.is-open .btn', { hasText: 'Quitar' }).click(); await p.waitForTimeout(900);
      await shot(p, 'paso2-quitado-deshacer', vp, { fullPage: false });
      await p.locator('.toast-action').click(); await p.waitForTimeout(800);
      await p.goto(BASE + `/configurar/${gcode}/resumen`); await shot(p, 'paso3-cliente', vp);
      // El enlace firmado (lo que abre «Envíamela por WhatsApp»), pedido antes de dejar los datos
      const shareUrl = await p.evaluate(async (code) => {
        const csrf = document.querySelector('[name=_csrf]').value;
        const r = await fetch(`/api/configurations/${code}/share`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: '{}' });
        return (await r.json()).url;
      }, gcode);
      const who = { escritorio: ['Rocío Ibáñez', '677 120 984', 'rocio.ibanez'], tablet: ['Andrés Vela', '644 310 922', 'andres.vela'], movil: ['Noelia Pardo', '622 905 117', 'noelia.pardo'] }[vp];
      await p.fill('input[name=name]', who[0]); await p.fill('input[name=phone]', who[1]); await p.fill('input[name=email]', who[2] + '@example.com');
      await p.fill('textarea[name=message]', 'Mejor por la tarde, a partir de las 17:00.');
      await p.check('input[name=consent]');
      await Promise.all([p.waitForNavigation(), p.click('form#pedir button[type=submit]')]);
      await shot(p, 'paso3-recibido', vp);
      const v = await browser.newContext({ viewport: size, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, locale: 'es-ES' });
      const vp2 = await v.newPage(); await vp2.goto(shareUrl.replace(/^https?:\/\/[^/]+/, BASE)); await shot(vp2, 'enlace-compartido', vp); await v.close();
      await c.close();

      // --- comercial con la tablet: paso 2 con su código, resumen y aceptar, mis configuraciones
      c = await ctx(); p = await c.newPage(); await login(p, COMERCIAL);
      const code = await makeDuo(p, false);
      await p.goto(BASE + `/configurar/${code}`); await p.waitForSelector('.mod'); await shot(p, 'paso2-comercial', vp, { fullPage: false });
      await p.goto(BASE + `/configurar/${code}/resumen`); await shot(p, 'paso3-comercial', vp);
      await Promise.all([p.waitForNavigation(), p.click(`form[action="/configurar/${code}/aceptar"] button[type=submit]`)]);
      await shot(p, 'paso3-aceptada', vp);
      await p.goto(BASE + '/mis-configuraciones'); await shot(p, 'mis-configuraciones', vp);
      await c.close();
    }

    // --- panel del administrador
    c = await ctx(); p = await c.newPage(); await login(p, ADMIN);
    const firstOf = async (status) => { await p.goto(BASE + '/admin/configuraciones?status=' + status); return (await p.locator('table a.mono').first().textContent()).trim(); };
    const acc = await firstOf('accepted');
    const req = await firstOf('requested');
    const admin = adriOn ? [['panel-inicio-adri-conectada', '/admin'], ['panel-ficha-adri-conectada', `/admin/configuraciones/${acc}`]] : [
      ['panel-inicio', '/admin'], ['panel-catalogo', '/admin/catalogo'], ['panel-nodo', '/admin/catalogo/2'],
      ['panel-tipos', '/admin/tipos'], ['panel-tipo-tramos-despiece', '/admin/tipos/3'], ['panel-tipo-corredera', '/admin/tipos/14'],
      ['panel-composicion', '/admin/composicion/7'], ['panel-configuraciones', '/admin/configuraciones'],
      ['panel-configuraciones-pendientes', '/admin/configuraciones?pendientes=1'], ['panel-ficha', `/admin/configuraciones/${acc}`],
      ['panel-ficha-pedida', `/admin/configuraciones/${req}`], ['panel-leads', '/admin/leads'],
      ['panel-personas', '/admin/usuarios'], ['panel-ajustes', '/admin/ajustes'], ['panel-tokens', '/admin/tokens'],
      ['panel-copias', '/admin/copias'], ['panel-actualizaciones', '/admin/actualizaciones'], ['panel-actividad', '/admin/actividad']];
    for (const [name, path] of admin) {
      await p.goto(BASE + path);
      if (name === 'panel-ficha' || name === 'panel-ficha-adri-conectada') await p.locator('details').nth(2).evaluate((d) => { d.open = true; }).catch(() => {});
      if (name === 'panel-copias') await p.locator('details.bk-actions').first().evaluate((d) => { d.open = true; }).catch(() => {});
      await shot(p, name, vp);
    }
    await c.close();
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
