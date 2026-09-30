// Corre en el mundo de la página de Sanitas desde el inicio de CADA carga del
// formulario de novedades. El Enviar recarga la página (dos veces: la del POST y la
// que vuelve al formulario) y Chrome pregunta «¿Quieres salir del sitio web?» por el
// `beforeunload` de Sanitas; el robot se quedaba esperando a una persona. Aquí se
// ignora todo beforeunload de esta página: no hay nada sin guardar que perder.
(() => {
  try {
    window.onbeforeunload = null;
    Object.defineProperty(window, 'onbeforeunload', { configurable: true, get: () => null, set: () => {} });
  } catch { /* la página no lo permite: quedan los oyentes de abajo */ }

  const agregar = EventTarget.prototype.addEventListener;
  EventTarget.prototype.addEventListener = function (tipo, ...resto) {
    if (tipo === 'beforeunload') return;
    return agregar.call(this, tipo, ...resto);
  };
  agregar.call(window, 'beforeunload', (ev) => { ev.stopImmediatePropagation(); }, true);
})();
