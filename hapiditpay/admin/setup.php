<?php
/**
 * Configuration du module HapiditPay : adresse de l'API, clé, secret du
 * webhook, compte bancaire et mode de règlement des paiements reçus.
 * La clé et le secret sont enregistrés chiffrés et jamais réaffichés.
 *
 * Copyright (C) Hapidit Group
 * @license GPL-3.0-or-later
 */

$res = 0;
if (!$res && file_exists(__DIR__ . '/../../main.inc.php')) {
    $res = @include __DIR__ . '/../../main.inc.php';
}
if (!$res && file_exists(__DIR__ . '/../../../main.inc.php')) {
    $res = @include __DIR__ . '/../../../main.inc.php';
}
if (!$res) {
    die('main.inc.php introuvable');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once __DIR__ . '/../class/hapiditpaypages.class.php';

$langs->loadLangs(['admin', 'banks', 'bills', 'hapiditpay@hapiditpay']);

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
    dolibarr_set_const($db, 'HAPIDITPAY_API_URL', trim(GETPOST('HAPIDITPAY_API_URL', 'alphanohtml')), 'chaine', 0, '', $conf->entity);
    // Clé et secret : un champ vide garde la valeur enregistrée.
    foreach (['HAPIDITPAY_API_KEY', 'HAPIDITPAY_WEBHOOK_SECRET'] as $name) {
        $value = trim(GETPOST($name, 'none'));
        if ($value !== '') {
            dolibarr_set_const($db, $name, dolEncrypt($value), 'chaine', 0, '', $conf->entity);
        }
    }
    dolibarr_set_const($db, 'HAPIDITPAY_BANK_ACCOUNT', GETPOSTINT('HAPIDITPAY_BANK_ACCOUNT'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'HAPIDITPAY_PAYMENT_MODE', GETPOSTINT('HAPIDITPAY_PAYMENT_MODE'), 'chaine', 0, '', $conf->entity);

    setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'forget') {
    dolibarr_del_const($db, 'HAPIDITPAY_API_KEY', $conf->entity);
    dolibarr_del_const($db, 'HAPIDITPAY_WEBHOOK_SECRET', $conf->entity);
    setEventMessages($langs->trans('HapiditPayForgotten'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Encadré de message : $html vient de $langs->trans(), qui l'a déjà encodé.
 *
 * @param string $type info | warning | error
 * @param string $html
 */
function hapiditpay_notice($type, $html)
{
    print '<div class="' . $type . '" style="margin: 0 0 12px;">' . $html . '</div>';
}

$form = new Form($db);
$api = HapiditPayPages::api();
$webhookUrl = dol_buildpath('/hapiditpay/public/webhook.php', 2) . '?e=' . ((int) $conf->entity);

llxHeader('', $langs->trans('HapiditPaySetup'));
print load_fiche_titre($langs->trans('HapiditPaySetup'), '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>', 'title_setup');

print '<div class="opacitymedium" style="margin-bottom: 12px;">' . $langs->trans('HapiditPayMoneyNote') . '</div>';

// Vérification de la clé auprès de HapiditPay.
if (HapiditPayPages::configured()) {
    $who = $api->whoami();
    if ($who['ok']) {
        hapiditpay_notice('info', $langs->trans('HapiditPayKeyOk', HapiditPayApi::plain($who['data']['name'] ?? '?'), $api->isTest() ? $langs->transnoentitiesnoconv('HapiditPayModeTest') : $langs->transnoentitiesnoconv('HapiditPayModeLive')));
    } else {
        hapiditpay_notice('error', $langs->trans('HapiditPayKeyKo', HapiditPayApi::plain($who['message'])));
    }
} else {
    $register = $api->siteUrl('portail/register');
    $portal = $api->siteUrl('portail');
    hapiditpay_notice('info', $langs->trans('HapiditPayNoAccount', HapiditPayApi::plain($register), HapiditPayApi::plain((string) parse_url($api->siteUrl(), PHP_URL_HOST)), HapiditPayApi::plain($portal)));
}

if (strtoupper((string) $conf->currency) === 'EUR' || strtoupper((string) $conf->currency) === 'USD') {
    hapiditpay_notice('warning', $langs->trans('HapiditPayCurrencyWarning', $conf->currency));
}
if (isModEnabled('bank') && getDolGlobalInt('HAPIDITPAY_BANK_ACCOUNT') <= 0) {
    hapiditpay_notice('warning', $langs->trans('HapiditPayBankWarning'));
}

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" autocomplete="off">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('HapiditPayApiUrl') . '</td><td>';
print '<input class="minwidth400" type="url" name="HAPIDITPAY_API_URL" value="' . dol_escape_htmltag(getDolGlobalString('HAPIDITPAY_API_URL')) . '" placeholder="' . HapiditPayApi::DEFAULT_URL . '">';
print '<br><span class="opacitymedium">' . $langs->trans('HapiditPayApiUrlHelp', HapiditPayApi::DEFAULT_URL) . '</span></td></tr>';

$hasKey = getDolGlobalString('HAPIDITPAY_API_KEY') !== '';
print '<tr class="oddeven"><td class="fieldrequired">' . $langs->trans('HapiditPayApiKey') . '</td><td>';
print '<input class="minwidth400" type="password" name="HAPIDITPAY_API_KEY" value="" autocomplete="new-password" placeholder="' . ($hasKey ? '••••••••' : 'hdp_test_…') . '">';
print '<br><span class="opacitymedium">' . ($hasKey ? $langs->trans('HapiditPayKeepEmpty') . ' ' : '') . $langs->trans('HapiditPayApiKeyHelp') . '</span></td></tr>';

$hasSecret = getDolGlobalString('HAPIDITPAY_WEBHOOK_SECRET') !== '';
print '<tr class="oddeven"><td class="fieldrequired">' . $langs->trans('HapiditPayWebhookSecret') . '</td><td>';
print '<input class="minwidth400" type="password" name="HAPIDITPAY_WEBHOOK_SECRET" value="" autocomplete="new-password" placeholder="' . ($hasSecret ? '••••••••' : 'whsec_…') . '">';
print '<br><span class="opacitymedium">' . ($hasSecret ? $langs->trans('HapiditPayKeepEmpty') . ' ' : '') . $langs->trans('HapiditPayWebhookHelp') . '</span>';
print '<br><code style="user-select: all;">' . dol_escape_htmltag($webhookUrl) . '</code></td></tr>';

if (isModEnabled('bank')) {
    print '<tr class="oddeven"><td>' . $langs->trans('HapiditPayBankAccount') . '</td><td>';
    $form->select_comptes(getDolGlobalInt('HAPIDITPAY_BANK_ACCOUNT'), 'HAPIDITPAY_BANK_ACCOUNT', 0, '', 1);
    print '<br><span class="opacitymedium">' . $langs->trans('HapiditPayBankAccountHelp') . '</span></td></tr>';
}

print '<tr class="oddeven"><td>' . $langs->trans('HapiditPayPaymentMode') . '</td><td>';
$form->select_types_paiements(getDolGlobalInt('HAPIDITPAY_PAYMENT_MODE') ?: dol_getIdFromCode($db, 'HPAY', 'c_paiement', 'code', 'id', 1), 'HAPIDITPAY_PAYMENT_MODE', '', 0, 1);
print '</td></tr>';

print '</table>';
print '<div class="center" style="margin-top: 12px;"><input type="submit" class="button button-save" value="' . $langs->trans('Save') . '">';
if ($hasKey || $hasSecret) {
    print ' &nbsp; <a class="button button-cancel" href="' . $_SERVER['PHP_SELF'] . '?action=forget&token=' . newToken() . '">' . $langs->trans('HapiditPayForget') . '</a>';
}
print '</div></form>';

// Dernières pages de paiement : de quoi suivre un paiement sans ouvrir HapiditPay.
$rows = (new HapiditPayPages($db))->latest();
print '<br>' . load_fiche_titre($langs->trans('HapiditPayLatest'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Date') . '</td><td>' . $langs->trans('HapiditPayDocument') . '</td><td class="right">' . $langs->trans('Amount') . '</td><td>' . $langs->trans('Status') . '</td><td>' . $langs->trans('HapiditPayTransaction') . '</td></tr>';
if (!$rows) {
    print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">' . $langs->trans('HapiditPayNoPage') . '</span></td></tr>';
}
foreach ($rows as $row) {
    if ($row->source === 'invoice') {
        $link = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . ((int) $row->fk_object) . '">' . $langs->trans('Invoice') . ' #' . ((int) $row->fk_object) . '</a>';
    } else {
        $link = '<a href="' . DOL_URL_ROOT . '/don/card.php?id=' . ((int) $row->fk_object) . '">' . $langs->trans('HapiditPayDonation') . ' #' . ((int) $row->fk_object) . '</a>';
    }
    $badge = ['paye' => 'status4', 'ecart' => 'status8', 'ouvert' => 'status1'][$row->status] ?? 'status0';
    print '<tr class="oddeven">';
    print '<td class="nowraponall">' . dol_print_date($db->jdate($row->datec), 'dayhour') . '</td>';
    print '<td>' . $link . ($row->test ? ' <span class="badge badge-warning">test</span>' : '') . '</td>';
    print '<td class="right nowraponall">' . dol_escape_htmltag(HapiditPayApi::formatAmount($row->amount, $row->currency)) . '</td>';
    print '<td>' . dolGetStatus($langs->trans('HapiditPayStatus_' . $row->status), '', '', $badge, 1) . ($row->note ? '<br><span class="opacitymedium small">' . dol_escape_htmltag($row->note) . '</span>' : '') . '</td>';
    print '<td class="small">' . dol_escape_htmltag((string) $row->transaction_id) . ($row->operator ? ' <span class="opacitymedium">(' . dol_escape_htmltag($row->operator) . ')</span>' : '') . '</td>';
    print '</tr>';
}
print '</table>';

llxFooter();
$db->close();
