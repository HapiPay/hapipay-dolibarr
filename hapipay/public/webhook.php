<?php
/**
 * Réception des webhooks HapiPay. La signature HapiPay-Signature tient lieu
 * d'authentification : sans elle, rien n'est modifié.
 *
 * Copyright (C) HapiPay
 * @license GPL-3.0-or-later
 */

if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}
if (!defined('NOIPCHECK')) {
    define('NOIPCHECK', 1);
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', 1);
}
if (!defined('NOBROWSERNOTIF')) {
    define('NOBROWSERNOTIF', 1);
}
// Multisociété : l'entité est dans l'adresse déclarée chez HapiPay (?e=…).
if (!defined('DOLENTITY')) {
    define('DOLENTITY', (isset($_GET['e']) && ctype_digit((string) $_GET['e'])) ? (int) $_GET['e'] : 1);
}

$res = 0;
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) {
    $res = @include __DIR__ . '/../../main.inc.php';
}
if (!$res && file_exists(__DIR__ . '/../../../main.inc.php')) {
    $res = @include __DIR__ . '/../../../main.inc.php';
}
if (!$res) {
    http_response_code(500);
    exit;
}

require_once __DIR__ . '/../class/hapipaypages.class.php';

/**
 * @param int   $status
 * @param array $body
 */
function hapipay_reply($status, array $body)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

if (!isModEnabled('hapipay')) {
    hapipay_reply(404, ['message' => 'Module HapiPay désactivé.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hapipay_reply(405, ['message' => 'POST attendu.']);
}

$raw = (string) file_get_contents('php://input');
$header = isset($_SERVER['HTTP_HAPIPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_HAPIPAY_SIGNATURE'] : '';

if (!HapiPayApi::validSignature($header, $raw, HapiPayPages::secret('HAPIPAY_WEBHOOK_SECRET'))) {
    hapipay_reply(401, ['message' => 'Signature invalide.']);
}

$event = json_decode($raw, true);
$name = is_array($event) && isset($event['evenement']) ? (string) $event['evenement'] : '';
$data = is_array($event) && isset($event['data']) && is_array($event['data']) ? $event['data'] : [];

if ($name === 'test.ping') {
    hapipay_reply(200, ['ok' => true]);
}

$pages = new HapiPayPages($db);
$page = $pages->find(isset($data['reference']) ? (string) $data['reference'] : '');
if (!$page || (int) $page->entity !== (int) $conf->entity) {
    // Pas une page de ce Dolibarr (une boutique sur le même compte, par exemple) :
    // on accuse réception pour éviter les relances.
    hapipay_reply(200, ['ignore' => true]);
}

$result = 'ok';
switch ($name) {
    case 'paiement.reussi':
        $result = $pages->recordPayment($page, $data);
        break;
    case 'paiement.echoue':
    case 'paiement.expire':
    case 'paiement.a_verifier':
        // Rien n'est enregistré : le client peut réessayer depuis le lien de paiement.
        $db->query('UPDATE ' . MAIN_DB_PREFIX . "hapipay_page SET note = '" . $db->escape($name) . "' WHERE rowid = " . ((int) $page->rowid) . " AND status = 'ouvert'");
        break;
}

// Une erreur d'enregistrement est signalée à HapiPay, qui relancera le webhook.
hapipay_reply($result === 'erreur' ? 500 : 200, ['ok' => $result !== 'erreur', 'resultat' => $result]);
