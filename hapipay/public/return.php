<?php
/**
 * Retour du client après la page HapiPay. Si le webhook n'est pas encore
 * arrivé, l'état de la page est demandé à HapiPay (de serveur à serveur),
 * puis le client voit où en est son paiement.
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
if (!defined('NOBROWSERNOTIF')) {
    define('NOBROWSERNOTIF', 1);
}
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
    exit;
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';
require_once __DIR__ . '/../class/hapipaypages.class.php';

$langs->loadLangs(['main', 'bills', 'hapipay@hapipay']);

if (!isModEnabled('hapipay')) {
    httponly_accessforbidden('Module HapiPay désactivé.');
}

$pages = new HapiPayPages($db);
$page = $pages->find(GETPOST('r', 'aZ09arobase'));

// Le paramètre ?paiement=… ajouté par HapiPay n'est qu'une indication : on demande l'état à HapiPay.
if ($page && (int) $page->entity === (int) $conf->entity && $page->status === 'ouvert') {
    $state = HapiPayPages::api()->linkState($page->code);
    if ($state['ok'] && ($state['data']['etat'] ?? '') === 'paye') {
        $pages->recordPayment($page, [
            'id' => $state['data']['paiement'] ?? $page->code,
            'montant' => $state['data']['montant'] ?? null,
            'devise' => $state['data']['devise'] ?? null,
        ]);
        $page = $pages->find($page->reference);
    }
}

$status = $page ? (string) $page->status : '';
[$title, $text] = match ($status) {
    'paye' => [$langs->trans('HapiPayReturnPaidTitle'), $langs->trans('HapiPayReturnPaidText')],
    'ecart' => [$langs->trans('HapiPayReturnCheckTitle'), $langs->trans('HapiPayReturnCheckText')],
    'ouvert' => [$langs->trans('HapiPayReturnWaitingTitle'), $langs->trans('HapiPayReturnWaitingText')],
    default => [$langs->trans('HapiPayReturnUnknownTitle'), $langs->trans('HapiPayReturnUnknownText')],
};

// Lien vers la page de paiement de Dolibarr : facture ou don, avec sa clé de sécurité.
$back = '';
if ($page) {
    $ref = (string) $page->fk_object;
    if ($page->source === 'invoice') {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($db);
        $ref = $invoice->fetch((int) $page->fk_object) > 0 ? $invoice->ref : '';
    }
    if ($ref !== '') {
        $back = getOnlinePaymentUrl(0, $page->source, $ref);
    }
}

// Tant que le paiement n'est pas confirmé, la page se recharge toute seule.
$head = $status === 'ouvert' ? '<meta http-equiv="refresh" content="6">' : '';

// Même présentation que la page de paiement de Dolibarr : ni menu, ni barre latérale.
$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;
llxHeader($head, $title, '', '', 0, 0, '', '', '', 'onlinepaymentbody', '<div>');

// En-tête : logo de la société (celui des paiements en ligne s'il est réglé), sinon son nom.
$logo = getDolGlobalString('ONLINE_PAYMENT_LOGO') ?: $mysoc->logo_small;
$urllogo = '';
if ($logo !== '' && is_readable($conf->mycompany->dir_output . '/logos/thumbs/' . $logo)) {
    $urllogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;entity=' . ((int) $conf->entity) . '&amp;file=' . urlencode('logos/thumbs/' . $logo);
} elseif (!empty($mysoc->logo) && is_readable($conf->mycompany->dir_output . '/logos/' . $mysoc->logo)) {
    $urllogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;entity=' . ((int) $conf->entity) . '&amp;file=' . urlencode('logos/' . $mysoc->logo);
}
$creditor = getDolGlobalString('ONLINE_PAYMENT_CREDITOR') ?: $mysoc->name;
print '<div class="backgreypublicpayment"><div class="logopublicpayment">';
print $urllogo !== '' ? '<img id="dolpaymentlogo" src="' . $urllogo . '" alt="">' : dol_escape_htmltag($creditor);
print '</div></div>';

$colour = $status === 'paye' ? '#047857' : ($status === 'ouvert' ? '#b54708' : '#b42318');
print '<div class="center" style="max-width: 520px; margin: 32px auto; padding: 0 16px;">';
print '<div style="border: 1px solid #d9dee5; border-radius: 14px; padding: 24px; background: #fff; text-align: start;">';
print '<p style="font-size: 1.25em; font-weight: 700; color: ' . $colour . '; margin: 0 0 8px;">' . dol_escape_htmltag($title) . '</p>';
print '<p style="margin: 0;">' . dol_escape_htmltag($text) . '</p>';
if ($page) {
    print '<p class="opacitymedium" style="margin: 16px 0 0;">' . dol_escape_htmltag(HapiPayApi::formatAmount($page->amount, $page->currency)) . ($page->transaction_id ? ' · ' . dol_escape_htmltag($page->transaction_id) : '') . '</p>';
}
if ($back !== '') {
    print '<p style="margin: 20px 0 0;"><a class="button" href="' . dol_escape_htmltag($back) . '">' . dol_escape_htmltag($langs->trans('HapiPayBackToPayment')) . '</a></p>';
}
print '</div></div>';

htmlPrintOnlineFooter($mysoc, $langs, 0);
llxFooter('', 'public');
$db->close();
