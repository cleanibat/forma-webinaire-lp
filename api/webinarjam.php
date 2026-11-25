<?php
/* FORMA : réception des webhooks WebinarJam (règles « CustomWebhook » du webinaire).
   URL : https://live.cleanscale.fr/api/webinarjam.php?e=inscription|present|absent&k=JETON
   JETON = 32 premiers caractères de sha256(MAKE_KEY . '|webinarjam') : rien de secret dans ce fichier (dépôt public).
   - garde chaque appel tel quel dans api/data/wj-<événement>.jsonl (protégé par api/data/.htaccess) ;
   - inscription : ajoute ou met à jour le contact Brevo (liste du live, prénom, lien personnel) si Brevo est configuré ;
   - present / absent : note la présence au live sur le contact Brevo (PRESENT_LIVE, DUREE_LIVE) pour trier l'après-live. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function wj_out(bool $ok, int $code = 200): void { http_response_code($code); echo json_encode(['ok' => $ok]); exit; }

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) wj_out(false, 500);
$cfg = require $cfgFile;

$jeton = substr(hash('sha256', ($cfg['MAKE_KEY'] ?? '') . '|webinarjam'), 0, 32);
if (($cfg['MAKE_KEY'] ?? '') === '' || !hash_equals($jeton, (string)($_GET['k'] ?? ''))) wj_out(false, 403);

$evt = (string)($_GET['e'] ?? '');
if (!in_array($evt, ['inscription', 'present', 'absent'], true)) wj_out(false, 400);

/* WebinarJam envoie selon les cas du formulaire ou du JSON : on accepte les deux */
$raw = file_get_contents('php://input', false, null, 0, 50000) ?: '';
$in = $_POST;
if (!$in && $raw !== '') { $j = json_decode($raw, true); if (is_array($j)) $in = $j; else parse_str($raw, $in); }
if (!is_array($in)) $in = [];

/* Cherche une valeur par plusieurs noms possibles (les noms exacts de WebinarJam varient selon l'événement) */
$pick = static function (array $a, array $names) {
  foreach ($a as $k => $v) {
    if (is_array($v)) { $r = null; foreach ($names as $n) if (isset($v[$n]) && is_scalar($v[$n]) && $v[$n] !== '') return (string)$v[$n]; continue; }
    if (in_array(strtolower((string)$k), $names, true) && is_scalar($v) && $v !== '') return (string)$v;
  }
  return '';
};
$email = strtolower(trim($pick($in, ['email', 'lead_email', 'wj_lead_email', 'attendee_email'])));
$prenom = trim($pick($in, ['first_name', 'firstname', 'name', 'lead_first_name', 'wj_lead_first_name']));
$lien = trim($pick($in, ['live_room_url', 'unique_link_live_room', 'live_room_link', 'wj_lead_unique_link_live_room', 'live_url']));
$duree = trim($pick($in, ['time_live', 'time_in_live_room', 'live_time', 'duration', 'time_in_room']));
if ($lien !== '' && strpos($lien, 'https://event.webinarjam.com/') !== 0) $lien = '';

/* 1. Copie brute (sert aussi à découvrir les noms exacts des champs au premier envoi) */
$dir = __DIR__ . '/data';
if (is_dir($dir) || @mkdir($dir, 0750, true)) {
  @file_put_contents($dir . '/wj-' . $evt . '.jsonl', json_encode(['recu' => date('c'), 'e' => $evt, 'donnees' => $in], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/* 1 bis. Copie vers Make (visible dans l'historique du scénario ; la route « presence » écrit dans l'onglet Import_presence) */
if (!empty($cfg['MAKE_WEBHOOK'])) {
  $ch = curl_init($cfg['MAKE_WEBHOOK']);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['type' => 'wj_' . $evt, 'cle' => $cfg['MAKE_KEY'] ?? '', 'date' => (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y H:i:s'),
      'email' => $email, 'prenom' => $prenom, 'lien_live' => $lien, 'duree' => $duree, 'brut' => $in], JSON_UNESCAPED_UNICODE)]);
  curl_exec($ch); curl_close($ch);
}

/* 2. Brevo */
if (filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($cfg['BREVO_API_KEY']) && !empty($cfg['BREVO_LIST_ID'])) {
  if ($evt === 'inscription') {
    $attrs = array_filter(['PRENOM' => mb_substr($prenom, 0, 80), 'LIEN_LIVE' => $lien]);
  } else {
    $attrs = ['PRESENT_LIVE' => $evt === 'present' ? 'oui' : 'non'];
    if ($duree !== '') $attrs['DUREE_LIVE'] = mb_substr($duree, 0, 20);
  }
  $ch = curl_init('https://api.brevo.com/v3/contacts');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'api-key: ' . $cfg['BREVO_API_KEY']],
    CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'attributes' => (object)$attrs, 'listIds' => [(int)$cfg['BREVO_LIST_ID']], 'updateEnabled' => true], JSON_UNESCAPED_UNICODE)]);
  $br = curl_exec($ch); $bc = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  if ($bc < 200 || $bc >= 300) @file_put_contents($dir . '/brevo-erreurs.log', date('c') . " wj-$evt $email $bc " . substr((string)$br, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
}

wj_out(true);
