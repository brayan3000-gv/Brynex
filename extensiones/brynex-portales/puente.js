/**
 * Puente entre la página de BryNex y la extensión.
 *
 * La página no puede hablar con la extensión directamente sin conocer su id,
 * así que manda `window.postMessage({canal: 'brynex-portales', tipo: 'pedido', …})`
 * y este script (que corre dentro de BryNex) lo reenvía al service worker y
 * devuelve la respuesta por el mismo canal. También marca el documento para
 * que la página sepa que la extensión está instalada.
 */
// Con varias copias de la extensión instaladas (cada ZIP cargado como una nueva, sin
// quitar la anterior) todas reciben el mismo pedido y el trámite se hace varias veces a
// la vez: el 1-oct-2026 Sanitas recibió el formulario cuatro veces adjunto. Cada copia se
// anota en el documento y solo atiende la más nueva (a igual versión, la de id mayor).
// Las copias anteriores a la 1.45.43 no se anotan: la página las detecta porque
// responden de más (ver sannExt) y pide quitarlas.
const yo = { version: chrome.runtime.getManifest().version, id: chrome.runtime.id };
const raiz = document.documentElement;
const copias = () => { try { return JSON.parse(raiz.dataset.brynexPortalesCopias || '[]'); } catch { return []; } };
const comparar = (a, b) => {
  const x = a.version.split('.').map(Number), y = b.version.split('.').map(Number);
  for (let i = 0; i < Math.max(x.length, y.length); i++) if ((x[i] || 0) !== (y[i] || 0)) return (x[i] || 0) - (y[i] || 0);
  return a.id === b.id ? 0 : (a.id > b.id ? 1 : -1);
};
const masNueva = () => copias().reduce((m, c) => (comparar(c, m) > 0 ? c : m), yo);
raiz.dataset.brynexPortalesCopias = JSON.stringify([...copias().filter(c => c.id !== yo.id), yo]);
raiz.dataset.brynexPortales = masNueva().version;

window.addEventListener('message', (ev) => {
  if (ev.source !== window || ev.data?.canal !== 'brynex-portales' || ev.data.tipo !== 'pedido') return;
  if (masNueva().id !== yo.id) return;

  const { id, portal, accion, datos } = ev.data;
  const responder = (respuesta) => window.postMessage(
    { canal: 'brynex-portales', tipo: 'respuesta', id, respuesta },
    window.location.origin
  );

  try {
    chrome.runtime.sendMessage({ canal: 'brynex-portales', portal, accion, datos }, (respuesta) => {
      const error = chrome.runtime.lastError?.message;
      responder(respuesta || { ok: false, error: error || 'La extensión no respondió.' });
    });
  } catch (e) {
    // La extensión se actualizó con la página abierta: hay que recargar.
    responder({ ok: false, error: 'La extensión BryNex Portales se actualizó: recarga la página.' });
  }
});
