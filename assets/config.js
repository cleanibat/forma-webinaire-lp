/* FORMA : réglages du lancement, à remplir à un seul endroit.
   Rien de secret ici : ce fichier est public. La clé Make reste dans api/config.php (jamais dans Git). */
window.FORMA_CONFIG = {
  // Circuit d'inscription. 'A' = notre formulaire → api/inscription.php → Make (→ API WebinarJam).
  //                        'B' = formulaire intégré WebinarJam (coller son code dans le bloc #wj-embed de index.html).
  MODE: 'A',
  ENDPOINT: 'api/inscription.php',          // script serveur (même domaine que la page)
  PIXEL_ID: '1928513481863228',                             // identifiant du Pixel Meta (chiffres uniquement). Vide = aucun Pixel.
  WHATSAPP_URL: 'https://chat.whatsapp.com/CKqmeaBs4MM5EXpwpUhDx0',
  LIEN_RDV: '',                             // page de réservation d'appel avec Marius (après le live)                         // lien d'invitation de la Communauté WhatsApp du live
  LIVE_UTC: Date.UTC(2026, 10, 29, 19, 0, 0), // dimanche 29/11/2026 20 h, heure de Paris
  CONSENT_VERSION: 'consent-v7-2026-11-24'  // à changer si le texte des cases change
};
