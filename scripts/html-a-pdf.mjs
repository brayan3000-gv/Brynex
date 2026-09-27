/**
 * Imprime HTML a PDF con el Chrome del servidor, igual que "Imprimir → Guardar
 * como PDF" en el navegador. Sirve para mandar por WhatsApp un documento que ya
 * existe como vista HTML (el recibo de factura o de anticipo) sin rehacerlo en
 * DomPDF, que no entiende su CSS.
 *
 * Entrada (stdin, JSON): { htmls: ["<!DOCTYPE html>…", …], recorte?: "#recibo-print-area" }
 *   recorte: el elemento que se imprime. La hoja queda del ancho de una carta
 *   y del alto de ese elemento, sin media página en blanco debajo.
 * Salida (stdout, JSON): { ok: true, pdfs: ["<base64>", …] } | { ok: false, error }
 *   Un PDF por documento, con un solo Chrome para todos.
 *
 * Lo llama App\Services\ReciboVisitaService::htmlAPdf().
 */
import puppeteer from 'puppeteer-core';
import { rutaChrome } from './arl-sura-sesion-comun.mjs';
import { existsSync } from 'node:fs';

// Con stdout en tubería, write() es asíncrono: salir sin esperar corta el PDF.
const salir = (r) => process.stdout.write(JSON.stringify(r), () => process.exit(0));

let entrada = '';
for await (const trozo of process.stdin) entrada += trozo;
const { htmls, recorte } = JSON.parse(entrada || '{}');
if (!htmls?.length) await new Promise(() => salir({ ok: false, error: 'No llegó el HTML.' }));

const ejecutable = rutaChrome().find((r) => existsSync(r));
if (!ejecutable) await new Promise(() => salir({ ok: false, error: 'No se encontró Chrome. Define CHROME_PATH.' }));

let navegador;
let resultado;
try {
  navegador = await puppeteer.launch({
    executablePath: ejecutable,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'],
  });
  const pdfs = [];
  for (const html of htmls) {
    const pagina = await navegador.newPage();
    // Ancho útil de una carta con 8 mm por lado: el que suponen los @media
    // print de los recibos (199,9 mm).
    await pagina.setViewport({ width: 755, height: 1000 });
    await pagina.emulateMediaType('print');
    // Las fuentes y el logo vienen de internet: se esperan, pero sin colgarse
    // si alguno no carga.
    await pagina.setContent(html, { waitUntil: 'networkidle0', timeout: 20000 }).catch(() => {});
    await pagina.evaluate(() => document.fonts && document.fonts.ready).catch(() => {});
    await pagina.addStyleTag({ content: 'html, body { background: #fff !important; }' });

    const alto = await pagina.evaluate((sel) => {
      const raiz = (sel && document.querySelector(sel)) || document.body;
      // Lo más bajo que de verdad se ve: la vista simple esconde bloques de la
      // detallada que igual ocupan alto en el contenedor.
      let fondo = 0;
      for (const el of raiz.querySelectorAll('*')) {
        const r = el.getBoundingClientRect();
        if (r.height > 0 && r.width > 0 && getComputedStyle(el).visibility === 'visible') fondo = Math.max(fondo, r.bottom);
      }
      return Math.ceil(fondo || raiz.getBoundingClientRect().bottom);
    }, recorte || null);

    // El tamaño va por @page y no por width/height de pdf(): Chrome voltea la
    // hoja a horizontal cuando el alto sale menor que el ancho.
    // Los recibos traen su propio @page (carta): se quitan para que mande este.
    await pagina.evaluate(() => {
      for (const hoja of document.styleSheets) {
        try {
          for (let i = hoja.cssRules.length - 1; i >= 0; i--) {
            if (hoja.cssRules[i].type === CSSRule.PAGE_RULE) hoja.deleteRule(i);
          }
        } catch (_) { /* hoja de otro dominio (fuentes): no trae @page */ }
      }
    });
    await pagina.addStyleTag({ content: `@page { size: 8.5in ${alto + 64}px; margin: 8mm; }` });
    const pdf = await pagina.pdf({ printBackground: true, preferCSSPageSize: true });
    pdfs.push(Buffer.from(pdf).toString('base64'));
    await pagina.close();
  }
  resultado = { ok: true, pdfs };
} catch (e) {
  resultado = { ok: false, error: e.message };
}
// Se cierra Chrome antes de salir: process.exit no espera y lo dejaría huérfano.
if (navegador) await navegador.close().catch(() => {});
salir(resultado);
