<?php
/* FORMA : script serveur unique des formulaires (inscription, mini-questionnaire, clic WhatsApp, liste d'attente, candidature).
   Le navigateur n'envoie qu'ici (même domaine). Ce script transmet à Make avec une clé secrète (api/config.php, hors Git)
   et garde une copie de secours dans api/data/ : une inscription n'est jamais perdue si Make est en panne. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(bool $ok, array $extra = [], int $code = 200): void {
  http_response_code($code);
  echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(false, ['error' => 'method'], 405);

/* Même origine uniquement */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) out(false, ['error' => 'origin'], 403);

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) out(false, ['error' => 'config'], 500);
$cfg = require $cfgFile; // ['MAKE_WEBHOOK' => 'https://hook.eu2.make.com/…', 'MAKE_KEY' => '…', 'ALERT_EMAIL' => '…']

$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode($raw ?: '', true);
if (!is_array($in)) out(false, ['error' => 'json'], 400);

$type = (string)($in['type'] ?? '');
$allowed = ['inscription', 'inscription_wj', 'quiz', 'whatsapp', 'attente', 'candidature'];
if (!in_array($type, $allowed, true)) out(false, ['error' => 'type'], 400);

/* Piège à robots : on répond « ok » sans rien garder */
if (trim((string)($in['website'] ?? '')) !== '') out(true);

$clean = static function ($v, int $max = 300): string {
  $v = is_scalar($v) ? (string)$v : '';
  $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
  return mb_substr(trim($v), 0, $max);
};
$email = mb_strtolower($clean($in['email'] ?? '', 180));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(false, ['error' => 'email'], 422);

$phone = preg_replace('/[^\d+]/', '', $clean($in['phone'] ?? '', 30)) ?? '';
if (strncmp($phone, '00', 2) === 0) $phone = '+' . substr($phone, 2);
if (preg_match('/^0[1-9]\d{8}$/', $phone)) $phone = '+33' . substr($phone, 1);

$data = [
  'type' => $type,
  'cle' => $cfg['MAKE_KEY'] ?? '',
  'date' => (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('d/m/Y H:i:s'),
  'page' => $clean($in['page'] ?? '', 120),
  'email' => $email,
];

switch ($type) {
  case 'inscription':
  case 'attente':
    $prenom = $clean($in['firstName'] ?? '', 80);
    if (mb_strlen($prenom) < 2) out(false, ['error' => 'prenom'], 422);
    if ($type === 'inscription' && !preg_match('/^\+[1-9]\d{8,14}$/', $phone)) out(false, ['error' => 'telephone'], 422);
    if ($type === 'inscription' && ($in['offres_ok'] ?? '') !== 'oui') out(false, ['error' => 'offres'], 422); // case « offres » obligatoire pour s'inscrire
    if ($phone !== '' && !preg_match('/^\+[1-9]\d{8,14}$/', $phone)) $phone = '';
    $data += [
      'prenom' => $prenom, 'telephone' => $phone,
      'offres_ok' => ($in['offres_ok'] ?? '') === 'oui' ? 'oui' : 'non',
      'appel_ok' => ($in['appel_ok'] ?? '') === 'oui' ? 'oui' : 'non',
      'consent_version' => $clean($in['consent_version'] ?? '', 60),
      'consent_at' => $clean($in['consent_at'] ?? '', 40),
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) $data[$k] = $clean($in[$k] ?? '', 120);
    break;
  case 'inscription_wj':
    /* Inscrit via le formulaire WebinarJam : la page merci renvoie ce que WebinarJam lui transmet (dont le lien personnel du live) */
    $lien = $clean($in['lien_live'] ?? '', 300);
    if ($lien !== '' && strpos($lien, 'https://event.webinarjam.com/') !== 0) $lien = '';
    if (!preg_match('/^\+[1-9]\d{8,14}$/', $phone)) $phone = '';
    $data += [
      'prenom' => $clean($in['firstName'] ?? '', 80), 'telephone' => $phone, 'lien_live' => $lien,
      'offres_ok' => 'webinarjam', 'appel_ok' => '', 'consent_version' => 'formulaire-webinarjam', 'consent_at' => $data['date'],
      'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
      // Pour relier plus tard un appel ou un achat à la pub (API Conversions) : identifiants Meta, seulement avec l'accord cookies
      'cookies_ok' => (int)($in['ck'] ?? 0) === 1 ? 'oui' : 'non',
      'fbp' => (int)($in['ck'] ?? 0) === 1 ? $clean($in['fbp'] ?? '', 200) : '',
      'fbc' => (int)($in['ck'] ?? 0) === 1 ? $clean($in['fbc'] ?? '', 300) : '',
      'user_agent' => $clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
      'source' => 'webinarjam',
    ];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) $data[$k] = $clean($in[$k] ?? '', 120);
    break;
  case 'quiz':
    $q = $clean($in['question'] ?? '', 20);
    $answers = ['presence' => ['oui', 'organiser'], 'profil' => ['debutant', 'artisan', 'nettoyage'], 'blocage' => ['clients', 'prix', 'materiel'], 'horizon' => ['janvier', '6mois', 'renseigne'], 'budget' => ['dedie', 'petit', 'rien']];
    $r = $clean($in['reponse'] ?? '', 20);
    if (!isset($answers[$q]) || !in_array($r, $answers[$q], true)) out(false, ['error' => 'quiz'], 422);
    $data += ['question' => $q, 'reponse' => $r];
    break;
  case 'whatsapp':
    break;
  case 'candidature':
    $fields = $in['reponses'] ?? [];
    if (!is_array($fields)) out(false, ['error' => 'reponses'], 422);
    foreach ($fields as $k => $v) $data['c_' . preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k))] = $clean($v, 600);
    $data += ['prenom' => $clean($in['firstName'] ?? '', 80), 'nom' => $clean($in['lastName'] ?? '', 80), 'telephone' => $phone,
      'appel_demande' => ($in['appel_demande'] ?? '') === 'oui' ? 'oui' : 'non', 'offres_ok' => ($in['offres_ok'] ?? '') === 'oui' ? 'oui' : 'non'];
    if ($data['appel_demande'] !== 'oui') out(false, ['error' => 'appel'], 422);
    break;
}

$dir = __DIR__ . '/data';

/* 0. Anti-doublon : une inscription déjà reçue pour cet e-mail (page merci rechargée ou ouverte deux fois)
   → on répond « ok » sans nouvelle ligne au CRM, sans nouveau contact Brevo ni second Lead Meta. */
if ($type === 'inscription' || $type === 'inscription_wj') {
  $needle = '"email":' . json_encode($email, JSON_UNESCAPED_UNICODE) . ',';
  foreach (['inscription', 'inscription_wj'] as $f) {
    $h = @fopen($dir . '/' . $f . '.jsonl', 'r');
    if (!$h) continue;
    while (($line = fgets($h)) !== false) {
      if (strpos($line, $needle) !== false) { fclose($h); out(true, ['deja' => true]); }
    }
    fclose($h);
  }
}

/* 1. Copie de secours (fichier protégé par api/data/.htaccess) */
$saved = false;
if (is_dir($dir) || @mkdir($dir, 0750, true)) {
  $row = $data; unset($row['cle']);
  $saved = @file_put_contents($dir . '/' . $type . '.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX) !== false;
}

/* 2. Envoi à Make */
$sent = false;
if (!empty($cfg['MAKE_WEBHOOK'])) {
  $ch = curl_init($cfg['MAKE_WEBHOOK']);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($type === 'inscription_wj' ? ['type' => 'inscription'] + $data : $data, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
  ]);
  $resp = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  $sent = $resp !== false && $code >= 200 && $code < 300;
  if (!$sent && !empty($cfg['ALERT_EMAIL']) && in_array($type, ['inscription', 'candidature'], true)) {
    @mail($cfg['ALERT_EMAIL'], 'FORMA : envoi à Make en échec (' . $type . ')', "Make a répondu $code. La ligne est gardée dans api/data/$type.jsonl.\nE-mail : $email");
  }
}

/* 2 bis. Brevo (e-mails avant le live) : contact créé ou mis à jour dans la liste du live, avec son lien personnel WebinarJam.
   Réglages dans api/config.php : BREVO_API_KEY, BREVO_LIST_ID. */
if (in_array($type, ['inscription', 'inscription_wj', 'quiz'], true) && !empty($cfg['BREVO_API_KEY']) && !empty($cfg['BREVO_LIST_ID'])) {
  $attrs = $type === 'quiz'
    ? [strtoupper($data['question']) => $data['reponse']]   // PROFIL, BLOCAGE, HORIZON, BUDGET : pour trier et personnaliser les e-mails
    : array_filter(['PRENOM' => $data['prenom'] ?? '', 'LIEN_LIVE' => $data['lien_live'] ?? '', 'TELEPHONE' => $data['telephone'] ?? '',
    'UTM_CONTENT' => $data['utm_content'] ?? '']);
  $ch = curl_init('https://api.brevo.com/v3/contacts');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'api-key: ' . $cfg['BREVO_API_KEY']],
    CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'attributes' => (object)$attrs, 'listIds' => [(int)$cfg['BREVO_LIST_ID']], 'updateEnabled' => true], JSON_UNESCAPED_UNICODE)]);
  $br = curl_exec($ch); $bc = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  if ($bc < 200 || $bc >= 300) {
    @file_put_contents($dir . '/brevo-erreurs.log', date('c') . " $email $bc " . substr((string)$br, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
    if (!empty($cfg['ALERT_EMAIL'])) @mail($cfg['ALERT_EMAIL'], 'FORMA : ajout Brevo en échec', "Brevo a répondu $bc pour $email. Détail dans api/data/brevo-erreurs.log.");
  }
}

/* 3. API Conversions Meta (côté serveur), seulement avec l'accord cookies de la personne.
   Même event_id que le Pixel du navigateur : Meta ne compte l'inscription qu'une fois. */
$events = ['inscription' => 'Lead', 'inscription_wj' => 'Lead', 'candidature' => 'SubmitApplication'];
if (isset($events[$type]) && (int)($in['ck'] ?? 0) === 1 && !empty($cfg['META_PIXEL_ID']) && !empty($cfg['META_CAPI_TOKEN'])) {
  $h = static function (string $v): string { return $v === '' ? '' : hash('sha256', $v); };
  $ud = array_filter([
    'em' => [$h($email)],
    'ph' => $phone !== '' ? [$h(ltrim($phone, '+'))] : null,
    'fn' => !empty($data['prenom']) ? [$h(mb_strtolower(trim((string)$data['prenom'])))] : null,
    'country' => [$h('fr')],
    'external_id' => [$h($email)],
    'client_ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'fbp' => $clean($in['fbp'] ?? '', 200) ?: null,
    'fbc' => $clean($in['fbc'] ?? '', 300) ?: null,
  ]);
  $ev = [
    'event_name' => $events[$type],
    'event_time' => time(),
    'action_source' => 'website',
    'event_source_url' => $clean($in['url'] ?? '', 300),
    'user_data' => $ud,
    'custom_data' => ['content_name' => 'Live 29 novembre', 'content_category' => $type],
  ];
  $eid = $clean($in['event_id'] ?? '', 80);
  if ($eid !== '') $ev['event_id'] = $eid;
  $payload = ['data' => [$ev], 'access_token' => $cfg['META_CAPI_TOKEN']];
  if (!empty($cfg['META_TEST_CODE'])) $payload['test_event_code'] = $cfg['META_TEST_CODE'];
  $ch = curl_init('https://graph.facebook.com/' . ($cfg['META_API_VERSION'] ?? 'v24.0') . '/' . $cfg['META_PIXEL_ID'] . '/events');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
  $mr = curl_exec($ch); $mc = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  if ($mc < 200 || $mc >= 300) @file_put_contents($dir . '/meta-erreurs.log', date('c') . " $type $mc " . substr((string)$mr, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
}

/* 4. Notification Telegram à chaque nouvel inscrit (prénom, numéro, heure ; ni e-mail ni téléphone) */
if (($type === 'inscription' || $type === 'inscription_wj') && !empty($cfg['TELEGRAM_TOKEN']) && !empty($cfg['TELEGRAM_CHAT'])) {
  $vus = [];
  foreach (['inscription', 'inscription_wj'] as $f) {
    foreach (@file($dir . '/' . $f . '.jsonl') ?: [] as $l) {
      $o = json_decode($l, true);
      $e = is_array($o) ? (string)($o['email'] ?? '') : '';
      if ($e !== '' && !preg_match('/@(cleanibat|cleanscale)\.fr$|test/i', $e)) $vus[$e] = true; // sans nos adresses de test
    }
  }
  $txt = '🎉 Nouvel inscrit n° ' . count($vus) . ' : ' . ($data['prenom'] !== '' ? $data['prenom'] : 'sans prénom')
    . ' (' . (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('H\hi') . ')';
  $ch = curl_init('https://api.telegram.org/bot' . $cfg['TELEGRAM_TOKEN'] . '/sendMessage');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $cfg['TELEGRAM_CHAT'], 'text' => $txt], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
  curl_exec($ch); curl_close($ch);
}

/* La personne passe à la suite si l'inscription est gardée quelque part */
if ($sent || $saved) out(true);
out(false, ['error' => 'stockage'], 502);
