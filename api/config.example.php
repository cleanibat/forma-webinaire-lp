<?php
/* Copier ce fichier en api/config.php SUR LE SERVEUR (jamais dans Git) et remplir. */
return [
  'MAKE_WEBHOOK' => 'https://hook.eu2.make.com/XXXXXXXX', // webhook du scénario « FORMA · Formulaires → CRM »
  'MAKE_KEY'     => 'remplacer-par-une-longue-cle-aleatoire', // la même clé que dans le filtre du scénario Make
  'ALERT_EMAIL'  => '',                                       // adresse qui reçoit une alerte si Make ne répond pas
];
