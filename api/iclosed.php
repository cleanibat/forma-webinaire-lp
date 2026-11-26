<?php
/* FORMA : réception des webhooks iClosed (Paramètres → Développeur → Webhooks).
   URL : https://live.cleanscale.fr/api/iclosed.php?k=JETON
   JETON = 32 premiers caractères de sha256(MAKE_KEY . '|iclosed') : rien de secret dans ce fichier (dépôt public).
   Événements suivis : Call booked, Call cancelled, Call rescheduled, Outcome added.
   - copie brute dans api/data/iclosed-<événement>.jsonl (protégé par api/data/.htaccess) ;
   - Make (type « rdv ») : une ligne par événement dans l'onglet RDV du CRM ;
   - Brevo : à la réservation, le contact passe dans la liste « Candidats » (BREVO_LIST_CANDIDATS, 13 par défaut) ;
   - Telegram : alerte (prénom, date, résultat ; ni e-mail ni téléphone) ;
   - API Conversions Meta : Schedule à la réservation (même event_id que la page candidature-merci.html, donc compté une fois),
     Purchase quand l'appel est noté « vente ». Jamais pour nos adresses de test. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ic_out(bool $ok, int $code = 200): void { http_response_code($code); echo json_encode(['ok' => $ok]); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ic_out(false, 405);
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) ic_out(false, 500);
$cfg = require $cfgFile;

$jeton = substr(hash('sha256', ($cfg['MAKE_KEY'] ?? '') . '|iclosed'), 0, 32);
if (($cfg['MAKE_KEY'] ?? '') === '' || !hash_equals($jeton, (string)($_GET['k'] ?? ''))) ic_out(false, 403);

$raw = file_get_contents('php://input', false, null, 0, 200000) ?: '';
$in = json_decode($raw, true);
if (!is_array($in)) ic_out(false, 400);

$s = static function ($v, int $max = 300): string {
  $v = is_scalar($v) ? (string)$v : '';
  $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
  return mb_substr(trim($v), 0, $max);
};
$g = static function (array $a, string $path) {
  foreach (explode('.', $path) as $k) { if (!is_array($a) || !array_key_exists($k, $a)) return null; $a = $a[$k]; }
  return $a;
};

/* Quel événement ? (hookType, avec repli sur la forme du contenu) */
$hook = strtolower($s($in['hookType'] ?? '', 60));
if (strpos($hook, 'outcome') !== false || isset($in['callOutcome'])) $evt = 'resultat';
elseif (strpos($hook, 'cancel') !== false) $evt = 'annule';
elseif (strpos($hook, 'reschedul') !== false) $evt = 'deplace';
elseif (strpos($hook, 'booked') !== false) $evt = 'reserve';
else $evt = 'autre';

/* Champs communs */
if ($evt === 'resultat') {
  $email = strtolower($s($in['email'] ?? '', 180));
  $prenom = $s($in['firstName'] ?? '', 80);
  $nom = $s($in['lastName'] ?? '', 80);
  $tel = $s($in['phoneNumber'] ?? '', 30);
  $debut = $s($in['startTime'] ?? '', 40);
  $closer = $s($in['closerName'] ?? '', 80);
  $idAppel = $s($in['callPreviewId'] ?? '', 60);
  $idInvite = '';
  $resultat = $s($in['callOutcome'] ?? '', 40);
  $objection = $s(($in['objection'] ?? '') ?: ($in['noSaleReason'] ?? ''), 120);
  $valeur = is_numeric($in['dealValue'] ?? null) ? (float)$in['dealValue'] : 0.0;
  $motif = mb_substr(trim(strip_tags(html_entity_decode((string)($in['notes'] ?? ''), ENT_QUOTES, 'UTF-8'))), 0, 500);
  $lien = '';
  $reponses = '';
  $utm = ['utm_source' => '', 'utm_campaign' => '', 'utm_content' => ''];
} else {
  $email = strtolower($s($g($in, 'invitee.email'), 180));
  $prenom = $s($g($in, 'invitee.first_name'), 80);
  $nom = $s($g($in, 'invitee.last_name'), 80);
  $tel = $s($g($in, 'invitee.text_reminder_number'), 30);
  $debut = $s($g($in, 'event.utc_start_time') ?: $g($in, 'event.start_time'), 40);
  $closer = $s($g($in, 'event.closerName'), 80);
  $idAppel = $s($g($in, 'event.callPreviewId'), 60);
  $idInvite = $s($g($in, 'invitee.uuid'), 40);
  $resultat = '';
  $objection = '';
  $valeur = 0.0;
  $motif = $s($g($in, 'invitee.cancel_reason') ?: $g($in, 'event.cancel_reason') ?: $g($in, 'invitee.reschedule_reason'), 300);
  $lien = $s($g($in, 'invitee.previewUrl'), 300);
  $qa = [];
  foreach ((array)($in['questions_and_answers'] ?? []) as $q) {
    if (!is_array($q)) continue;
    $question = $s($q['question'] ?? '', 120);
    if ($question === '' || preg_match('/e-?mail|phone|t[ée]l[ée]phone|full name|first name|last name|pr[ée]nom|nom de famille|^nom$/iu', $question)) continue;
    $qa[] = $question . ' : ' . $s($q['answer'] ?? '', 400);
  }
  $reponses = implode(' | ', $qa);
  $utm = [];
  foreach (['utm_source', 'utm_campaign', 'utm_content'] as $k) $utm[$k] = $s($g($in, 'tracking.' . $k) ?: $g($in, 'tracking.first_' . $k), 120);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
$telE164 = preg_replace('/[^\d+]/', '', $tel) ?? '';
if (!preg_match('/^\+[1-9]\d{8,14}$/', $telE164)) $telE164 = '';
$test = $email !== '' && (bool)preg_match('/@(cleanibat|cleanscale)\.fr$|test/i', $email);

/* Date du rendez-vous à l'heure de Paris, en français (« jeudi 3 décembre à 14 h ») */
$paris = new DateTimeZone('Europe/Paris');
$quand = ''; $quandCourt = '';
try {
  if ($debut !== '') {
    $d = (new DateTime($debut))->setTimezone($paris);
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $h = (int)$d->format('G') . ' h' . ($d->format('i') === '00' ? '' : ' ' . $d->format('i'));
    $quand = $jours[(int)$d->format('w')] . ' ' . (int)$d->format('j') . ' ' . $mois[(int)$d->format('n')] . ' à ' . $h;
    $quandCourt = $d->format('d/m/Y H:i');
  }
} catch (Exception $e) { $quand = ''; }

/* 1. Copie brute */
$dir = __DIR__ . '/data';
if (is_dir($dir) || @mkdir($dir, 0750, true)) {
  @file_put_contents($dir . '/iclosed-' . $evt . '.jsonl', json_encode(['recu' => date('c'), 'e' => $evt, 'donnees' => $in], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/* 2. Make → onglet RDV du CRM */
if (!empty($cfg['MAKE_WEBHOOK'])) {
  $ch = curl_init($cfg['MAKE_WEBHOOK']);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
      'type' => 'rdv', 'cle' => $cfg['MAKE_KEY'] ?? '',
      'date' => (new DateTime('now', $paris))->format('d/m/Y H:i:s'),
      'evenement' => $evt, 'email' => $email, 'prenom' => $prenom, 'nom' => $nom, 'telephone' => $telE164 ?: $tel,
      'date_rdv' => $quandCourt, 'closer' => $closer, 'resultat' => $resultat, 'objection' => $objection,
      'valeur' => $valeur > 0 ? $valeur : '', 'motif' => $motif, 'reponses' => $reponses,
      'utm_source' => $utm['utm_source'], 'utm_campaign' => $utm['utm_campaign'], 'utm_content' => $utm['utm_content'],
      'lien_iclosed' => $lien, 'id_appel' => $idAppel,
    ], JSON_UNESCAPED_UNICODE)]);
  curl_exec($ch); curl_close($ch);
}

/* 3. Brevo : à la réservation, liste « Candidats » */
$listeCandidats = (int)($cfg['BREVO_LIST_CANDIDATS'] ?? 13);
if ($evt === 'reserve' && $email !== '' && !empty($cfg['BREVO_API_KEY']) && $listeCandidats > 0) {
  $ch = curl_init('https://api.brevo.com/v3/contacts');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'api-key: ' . $cfg['BREVO_API_KEY']],
    CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'attributes' => (object)array_filter(['PRENOM' => $prenom]), 'listIds' => [$listeCandidats], 'updateEnabled' => true], JSON_UNESCAPED_UNICODE)]);
  $br = curl_exec($ch); $bc = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  if ($bc < 200 || $bc >= 300) @file_put_contents($dir . '/brevo-erreurs.log', date('c') . " iclosed-$evt $email $bc " . substr((string)$br, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
}

/* 4. Telegram */
/* iClosed ajoute tout seul un résultat « NO_SALE / ADMIN_CANCELLED » à chaque annulation : déjà signalé par l'alerte « RDV annulé » */
$resultatAuto = $evt === 'resultat' && stripos($objection, 'CANCEL') !== false;
if (!empty($cfg['TELEGRAM_TOKEN']) && !empty($cfg['TELEGRAM_CHAT']) && $evt !== 'autre' && !$resultatAuto) {
  $qui = ($prenom !== '' ? $prenom : 'Sans prénom') . ($nom !== '' ? ' ' . mb_substr($nom, 0, 1) . '.' : '');
  $avec = $closer !== '' ? ' avec ' . trim($closer) : '';
  if ($evt === 'reserve') $txt = "📅 Nouveau RDV : $qui, $quand$avec." . ($reponses !== '' ? "\n" . mb_substr($reponses, 0, 600) : '');
  elseif ($evt === 'annule') $txt = "❌ RDV annulé : $qui ($quand)." . ($motif !== '' ? "\nMotif : $motif" : '');
  elseif ($evt === 'deplace') $txt = "🔁 RDV déplacé : $qui, maintenant $quand$avec.";
  else {
    $r = strtoupper($resultat);
    $libelles = ['SALE' => '💰 Vente', 'PARTIAL_SALE' => '💰 Acompte', 'NO_SALE' => 'Pas de vente', 'PRICE_OBJECTION' => 'Pas de vente (prix)',
      'TIMING_OBJECTION' => 'Pas de vente (pas le moment)', 'AUTHORITY_OBJECTION' => 'Pas de vente (ne décide pas seul)', 'NEED_OBJECTION' => 'Pas de vente (pas le besoin)',
      'NO_SHOW' => '👻 Absent', 'RESCHEDULE' => '🔁 À replanifier', 'FOLLOW_UP' => '⏳ À relancer', 'CANCELLED' => 'Annulé', 'DISQUALIFIED' => 'Non qualifié', 'NOT_INTERESTED' => 'Pas intéressé'];
    $txt = ($libelles[$r] ?? ('Résultat ' . $resultat)) . " : $qui" . ($valeur > 0 ? ', ' . number_format($valeur, 0, ',', ' ') . ' €' : '')
      . ($objection !== '' ? " (objection : $objection)" : '') . '.';
  }
  $ch = curl_init('https://api.telegram.org/bot' . $cfg['TELEGRAM_TOKEN'] . '/sendMessage');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $cfg['TELEGRAM_CHAT'], 'text' => $txt], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
  curl_exec($ch); curl_close($ch);
}

/* 5. API Conversions Meta : Schedule (réservation) et Purchase (vente) */
$metaEvent = null;
if ($evt === 'reserve' && $idInvite !== '') $metaEvent = ['Schedule', 'rdv-' . $idInvite, ['content_name' => 'Appel avec Marius'], 'website'];
if ($evt === 'resultat' && in_array(strtoupper($resultat), ['SALE', 'PARTIAL_SALE'], true) && $valeur > 0) $metaEvent = ['Purchase', 'vente-' . ($idAppel !== '' ? $idAppel : md5($email . $debut)), ['content_name' => 'Accompagnement CleanScale', 'value' => $valeur, 'currency' => 'EUR'], 'phone_call'];
if ($metaEvent && $email !== '' && !$test && !empty($cfg['META_PIXEL_ID']) && !empty($cfg['META_CAPI_TOKEN'])) {
  $h = static function (string $v): string { return $v === '' ? '' : hash('sha256', $v); };
  $ud = array_filter([
    'em' => [$h($email)],
    'ph' => $telE164 !== '' ? [$h(ltrim($telE164, '+'))] : null,
    'fn' => $prenom !== '' ? [$h(mb_strtolower($prenom))] : null,
    'ln' => $nom !== '' ? [$h(mb_strtolower($nom))] : null,
    'country' => [$h('fr')],
    'external_id' => [$h($email)],
    'client_ip_address' => $metaEvent[3] === 'website' ? ($s($g($in, 'invitee.ip_address'), 60) ?: null) : null,
    'client_user_agent' => $metaEvent[3] === 'website' ? ($s($g($in, 'externalIntegrationData.userAgent'), 400) ?: null) : null,
  ]);
  $ev = ['event_name' => $metaEvent[0], 'event_time' => time(), 'event_id' => $metaEvent[1], 'action_source' => $metaEvent[3], 'user_data' => $ud, 'custom_data' => $metaEvent[2]];
  if ($metaEvent[3] === 'website') $ev['event_source_url'] = 'https://live.cleanscale.fr/candidature.html';
  $payload = ['data' => [$ev], 'access_token' => $cfg['META_CAPI_TOKEN']];
  if (!empty($cfg['META_TEST_CODE'])) $payload['test_event_code'] = $cfg['META_TEST_CODE'];
  $ch = curl_init('https://graph.facebook.com/' . ($cfg['META_API_VERSION'] ?? 'v24.0') . '/' . $cfg['META_PIXEL_ID'] . '/events');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
  $mr = curl_exec($ch); $mc = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
  if ($mc < 200 || $mc >= 300) @file_put_contents($dir . '/meta-erreurs.log', date('c') . " iclosed-$evt $mc " . substr((string)$mr, 0, 300) . "\n", FILE_APPEND | LOCK_EX);
}

ic_out(true);
