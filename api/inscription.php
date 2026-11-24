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
$allowed = ['inscription', 'quiz', 'whatsapp', 'attente', 'candidature'];
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

/* 1. Copie de secours (fichier protégé par api/data/.htaccess) */
$saved = false;
$dir = __DIR__ . '/data';
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
    CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
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

/* La personne passe à la suite si l'inscription est gardée quelque part */
if ($sent || $saved) out(true);
out(false, ['error' => 'stockage'], 502);
