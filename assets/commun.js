/* FORMA : code commun à toutes les pages.
   - bandeau cookies (Accepter / Refuser au même niveau, choix gardé 6 mois)
   - Pixel Meta chargé UNIQUEMENT après « Accepter », et seulement si PIXEL_ID est renseigné
   - FORMA.lead() : événement Lead une seule fois par inscription (même event_id côté serveur → pas de doublon)
   - FORMA.track() : autres événements (ViewContent, FormStart, JoinWhatsApp…), mis en file tant que le Pixel n'est pas autorisé
   - FORMA.send() : envoi au script serveur (api/inscription.php), avec l'accord cookies et les identifiants Meta (fbp/fbc) */
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

  /* Pas de bandeau (décision d'Aymeric, 26/11/2026) : le Pixel se charge pour tout le monde dès l'arrivée */
  function choice() { return 'accept'; }

  var pixelOn = false;
  function loadPixel() {
    if (pixelOn || !/^\d{6,20}$/.test(String(C.PIXEL_ID || ''))) return;
    pixelOn = true;
    /* Code officiel du Pixel Meta */
    !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); }; if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s); }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    window.fbq('init', C.PIXEL_ID);
    window.fbq('track', 'PageView');
    flushLead();
    queue.splice(0).forEach(function (a) { fire(a[0], a[1], a[2]); });
  }

  /* Événements : standard (fbq track) ou personnalisés (trackCustom). Gardés en file si le Pixel n'est pas encore chargé. */
  var STD = /^(PageView|ViewContent|Lead|CompleteRegistration|Contact|Schedule|SubmitApplication|Purchase|InitiateCheckout|Search|FindLocation|StartTrial|Subscribe)$/;
  var queue = [];
  function fire(name, params, id) {
    window.fbq(STD.test(name) ? 'track' : 'trackCustom', name, params || {}, id ? { eventID: id } : undefined);
  }
  function track(name, params, id) {
    if (pixelOn) fire(name, params, id); else if (choice() !== 'refuse') queue.push([name, params, id]);
  }
  function cookie(n) { var m = document.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  /* _fbc : construit depuis fbclid si Meta ne l'a pas encore posé (uniquement avec l'accord) */
  function fbc() {
    var v = cookie('_fbc'); if (v) return v;
    var id = PARAMS.fbclid || store.get('forma_fbclid', true) || '';
    return id ? 'fb.1.' + (Number(store.get('forma_fbclid_t', true)) || Date.now()) + '.' + id : '';
  }
  /* Code de la pub lu dans le fbclid (7 octets après « adid » + 0x01), relevé sur les inscrits du 26/11 qui avaient leurs UTM.
     Clé = code, valeur = [nom de la pub, ensemble de pubs]. Une pub absente de la liste donne « meta-pub-inconnue ». */
  var T = '120251008362280497', CH = '120251008362630497', TL = '120251008363240497';
  var PUBS = {
    ab37ab65438a21: ['CH-H14C2-sobre', CH], ab37ab653fb991: ['CH-H11C4-sobre', CH], ab37ab6530c571: ['CH-H8C2-sobre', CH],
    ab37ab57961af1: ['T-B3', T], ab37ab57b69b41: ['T-C3', T], ab37ab57b93351: ['TL-formateurs-H3', TL]
  };
  function codePub(id) {
    for (var j = 0; j < 4; j++) {
      try {
        var t = id.slice(j).replace(/-/g, '+').replace(/_/g, '/'); t = t.slice(0, t.length - t.length % 4);
        var b = atob(t), i = b.indexOf('adid\x01'), h = '';
        if (i < 0) continue;
        for (var k = i + 5; k < i + 12; k++) h += ('0' + b.charCodeAt(k).toString(16)).slice(-2);
        return h;
      } catch (e) {}
    }
    return '';
  }
  if (PARAMS.fbclid) { store.set('forma_fbclid', PARAMS.fbclid, true); if (!store.get('forma_fbclid_t', true)) store.set('forma_fbclid_t', String(Date.now()), true); }

  /* Lead : marqué « en attente » à l'inscription, envoyé dès que le Pixel est autorisé et chargé, une seule fois. */
  function flushLead() {
    if (!pixelOn || store.get('forma_lead_pending', true) !== '1') return;
    var id = store.get('forma_lead_id', true) || ('lead-' + Date.now());
    window.fbq('track', 'Lead', { content_name: 'Live 29 novembre', content_category: 'inscription' }, { eventID: id });
    store.del('forma_lead_pending', true);
    store.set('forma_lead_done', '1', true);
  }

  function buildBanner() {
    var d = document.createElement('div');
    d.className = 'ck'; d.id = 'ck'; d.hidden = true;
    d.setAttribute('role', 'dialog'); d.setAttribute('aria-labelledby', 'ck-title'); d.setAttribute('aria-live', 'polite');
    d.innerHTML =
      /* Format « pop-in + Continuer sans accepter » (meilleurs taux conformes CNIL, benchmark Didomi) : le refus reste en 1 clic, bien visible */
      '<button type="button" class="ck-skip" data-ck="refuse">Continuer sans accepter <span aria-hidden="true">→</span></button>' +
      '<h2 id="ck-title">Ta vie privée est notre priorité</h2>' +
      '<p class="ck-text">Avec ton accord, on utilise le Pixel de Meta (Facebook, Instagram) pour savoir quelles pubs t\'ont amené ici. Ça nous aide à montrer le live aux bonnes personnes.</p>' +
      '<div class="ck-btns"><button type="button" data-ck="accept">Accepter</button></div>' +
      '<p class="ck-more">En savoir plus&nbsp;: <a href="confidentialite.html#cookies">politique de confidentialité</a>.</p>';
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

  function init() { loadPixel(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();

  window.FORMA = {
    config: C,
    store: store,
    params: PARAMS,
    lead: function () {
      if (store.get('forma_lead_done', true) === '1') return; // jamais deux Lead pour la même visite
      store.set('forma_lead_pending', '1', true);
      window.FORMA.leadId();
      flushLead();
    },
    /* Identifiant unique de l'inscription, partagé entre le Pixel et l'API Conversions (déduplication) */
    leadId: function () {
      var id = store.get('forma_lead_id', true);
      if (!id) { id = 'lead-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8); store.set('forma_lead_id', id, true); }
      return id;
    },
    track: track,
    /* Un événement au plus une fois par visite (ex. FormStart) */
    once: function (name, params) {
      var k = 'forma_ev_' + name; if (store.get(k, true) === '1') return; store.set(k, '1', true); track(name, params);
    },
    /* UTM de la pub : gardés aussi dans localStorage (7 jours), car WebinarJam peut ouvrir /merci dans un autre onglet,
       où la mémoire de session est vide. Ce ne sont pas des données personnelles (nom de pub). */
    utm: function () {
      var q = new URLSearchParams(location.search), o = {}, keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
      var dur = null;
      try { dur = JSON.parse(store.get('forma_utm') || 'null'); if (dur && Date.now() - dur.t > 7 * 864e5) dur = null; } catch (e) { dur = null; }
      var neuf = keys.some(function (k) { return q.get(k); });
      keys.forEach(function (k) {
        var v = q.get(k) || (neuf ? '' : (store.get('forma_' + k, true) || (dur && dur[k]) || ''));
        if (q.get(k)) store.set('forma_' + k, v, true);
        o[k] = v;
      });
      if (neuf) { var d = { t: Date.now() }; keys.forEach(function (k) { d[k] = o[k]; }); store.set('forma_utm', JSON.stringify(d)); }
      /* Clic Meta sans UTM (lien ouvert depuis la vue du post : commentaires, notification, partage) :
         Meta n'ajoute pas les UTM mais l'identifiant de clic (fbclid) contient un code de la pub.
         On le relit pour retrouver la pub ; jamais mémorisé, seulement déduit à l'envoi. */
      if (!o.utm_source && !o.utm_campaign && !o.utm_content) {
        var clic = PARAMS.fbclid || store.get('forma_fbclid', true) || fbc().split('.')[3] || '';
        if (clic) {
          var pub = PUBS[codePub(clic)];
          /* « paid-deduit » seulement si la pub est reconnue : un fbclid sans code de pub peut venir d'un post organique ou d'un partage */
          o.utm_source = 'meta'; o.utm_medium = pub ? 'paid-deduit' : 'meta-deduit';
          o.utm_campaign = pub ? 'cleanscale-live-29nov' : '';
          o.utm_content = pub ? pub[0] : 'meta-pub-inconnue';
          o.utm_term = pub ? pub[1] : '';
        }
      }
      return o;
    },
    /* Envoi au script serveur. Renvoie une promesse { ok: true/false }. */
    send: function (type, data, opts) {
      var ok = choice() === 'accept';
      var body = JSON.stringify(Object.assign({ type: type, page: location.pathname, url: location.origin + location.pathname,
        ck: ok ? 1 : 0, fbp: ok ? cookie('_fbp') : '', fbc: ok ? fbc() : '' }, data || {}));
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
