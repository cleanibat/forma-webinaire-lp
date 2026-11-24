/* FORMA : code commun à toutes les pages.
   - bandeau cookies (Accepter / Refuser au même niveau, choix gardé 6 mois)
   - Pixel Meta chargé UNIQUEMENT après « Accepter », et seulement si PIXEL_ID est renseigné
   - FORMA.lead() : événement Lead une seule fois par inscription
   - FORMA.send() : envoi au script serveur (api/inscription.php) */
(function () {
  var C = window.FORMA_CONFIG || {};
  /* Paramètres de l'URL lus une fois, puis les données personnelles (prénom, e-mail…) sont retirées de l'adresse
     AVANT tout chargement du Pixel : elles ne partent jamais chez Meta avec l'URL de la page. */
  var PARAMS = {};
  try {
    var u = new URL(location.href), keep = /^(utm_[a-z]+|src|fbclid|inscrit)$/, changed = false;
    u.searchParams.forEach(function (v, k) { PARAMS[k] = v; });
    Object.keys(PARAMS).forEach(function (k) { if (!keep.test(k)) { u.searchParams.delete(k); changed = true; } });
    if (changed) history.replaceState(null, '', u.pathname + (u.search || '') + u.hash);
  } catch (e) {}
  var CK_KEY = 'live_cookies', SIX_MONTHS = 182 * 864e5;
  var store = {
    get: function (k, s) { try { return (s ? sessionStorage : localStorage).getItem(k); } catch (e) { return null; } },
    set: function (k, v, s) { try { (s ? sessionStorage : localStorage).setItem(k, v); } catch (e) {} },
    del: function (k, s) { try { (s ? sessionStorage : localStorage).removeItem(k); } catch (e) {} }
  };

  function choice() {
    try {
      var c = JSON.parse(store.get(CK_KEY) || 'null');
      if (c && (c.v === 'accept' || c.v === 'refuse') && Date.now() - c.t < SIX_MONTHS) return c.v;
    } catch (e) {}
    return null;
  }

  var pixelOn = false;
  function loadPixel() {
    if (pixelOn || !/^\d{6,20}$/.test(String(C.PIXEL_ID || ''))) return;
    pixelOn = true;
    /* Code officiel du Pixel Meta */
    !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); }; if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s); }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    window.fbq('init', C.PIXEL_ID);
    window.fbq('track', 'PageView');
    flushLead();
  }

  /* Lead : marqué « en attente » à l'inscription, envoyé dès que le Pixel est autorisé et chargé, une seule fois. */
  function flushLead() {
    if (!pixelOn || store.get('forma_lead_pending', true) !== '1') return;
    var id = store.get('forma_lead_id', true) || ('lead-' + Date.now());
    window.fbq('track', 'Lead', { content_name: 'Live 29 novembre' }, { eventID: id });
    store.del('forma_lead_pending', true);
    store.set('forma_lead_done', '1', true);
  }

  function buildBanner() {
    var d = document.createElement('div');
    d.className = 'ck'; d.id = 'ck'; d.hidden = true;
    d.setAttribute('role', 'dialog'); d.setAttribute('aria-labelledby', 'ck-title'); d.setAttribute('aria-live', 'polite');
    d.innerHTML =
      '<h2 id="ck-title">Cookies</h2>' +
      '<p class="ck-text">Avec ton accord, nous utilisons le Pixel de Meta (Facebook, Instagram) pour savoir si tu es arrivé ici par l\'une de nos publicités et si tu t\'es inscrit. Ça nous aide à montrer nos pubs aux bonnes personnes. Si tu refuses, rien n\'est déposé et la page marche pareil.</p>' +
      '<div class="ck-btns"><button type="button" data-ck="refuse">Refuser</button><button type="button" data-ck="accept">Accepter</button></div>' +
      '<p class="ck-more">En savoir plus : <a href="confidentialite.html#cookies">politique de confidentialité, rubrique Cookies</a>.</p>';
    document.body.appendChild(d);
    d.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-ck]'); if (!b) return;
      store.set(CK_KEY, JSON.stringify({ v: b.dataset.ck, t: Date.now() }));
      d.hidden = true;
      if (b.dataset.ck === 'accept') loadPixel();
      else if (pixelOn) { location.reload(); } // retrait de l'accord : on recharge sans Pixel
    });
    return d;
  }

  function init() {
    var banner = buildBanner();
    var c = choice();
    if (c === 'accept') loadPixel(); else if (!c) banner.hidden = false;
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-ck-open]')) { e.preventDefault(); banner.hidden = false; var b = banner.querySelector('button'); if (b) b.focus(); }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.FORMA = {
    config: C,
    store: store,
    params: PARAMS,
    lead: function () {
      if (store.get('forma_lead_done', true) === '1') return; // jamais deux Lead pour la même visite
      store.set('forma_lead_pending', '1', true);
      if (!store.get('forma_lead_id', true)) store.set('forma_lead_id', 'lead-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8), true);
      flushLead();
    },
    utm: function () {
      var q = new URLSearchParams(location.search), o = {};
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
        var v = q.get(k) || store.get('forma_' + k, true) || '';
        if (q.get(k)) store.set('forma_' + k, v, true);
        o[k] = v;
      });
      return o;
    },
    /* Envoi au script serveur. Renvoie une promesse { ok: true/false }. */
    send: function (type, data, opts) {
      var body = JSON.stringify(Object.assign({ type: type, page: location.pathname }, data || {}));
      if (!C.ENDPOINT) return Promise.resolve({ ok: false, error: 'endpoint' });
      return fetch(C.ENDPOINT, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body,
        keepalive: !!(opts && opts.keepalive), credentials: 'same-origin'
      }).then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
        .catch(function () { return { ok: false, error: 'network' }; });
    }
  };
  window.FORMA.utm(); // garde les UTM de la visite pour /merci
})();
