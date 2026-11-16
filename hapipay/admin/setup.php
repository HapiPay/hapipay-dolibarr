<?php
/**
 * Configuration du module HapiPay : adresse de l'API, clé, secret du
 * webhook, compte bancaire et mode de règlement des paiements reçus.
 * La clé et le secret sont enregistrés chiffrés et jamais réaffichés.
 *
 * Copyright (C) HapiPay
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
require_once __DIR__ . '/../class/hapipaypages.class.php';

$langs->loadLangs(['admin', 'banks', 'bills', 'hapipay@hapipay']);

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
    dolibarr_set_const($db, 'HAPIPAY_API_URL', trim(GETPOST('HAPIPAY_API_URL', 'alphanohtml')), 'chaine', 0, '', $conf->entity);
    // Clé et secret : un champ vide garde la valeur enregistrée.
    foreach (['HAPIPAY_API_KEY', 'HAPIPAY_WEBHOOK_SECRET'] as $name) {
        $value = trim(GETPOST($name, 'none'));
        if ($value !== '') {
            dolibarr_set_const($db, $name, dolEncrypt($value), 'chaine', 0, '', $conf->entity);
        }
    }
    dolibarr_set_const($db, 'HAPIPAY_BANK_ACCOUNT', GETPOSTINT('HAPIPAY_BANK_ACCOUNT'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'HAPIPAY_PAYMENT_MODE', GETPOSTINT('HAPIPAY_PAYMENT_MODE'), 'chaine', 0, '', $conf->entity);

    setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'forget') {
    dolibarr_del_const($db, 'HAPIPAY_API_KEY', $conf->entity);
    dolibarr_del_const($db, 'HAPIPAY_WEBHOOK_SECRET', $conf->entity);
    setEventMessages($langs->trans('HapiPayForgotten'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Encadré de message : $html vient de $langs->trans(), qui l'a déjà encodé.
 *
 * @param string $type info | warning | error
 * @param string $html
 */
function hapipay_notice($type, $html)
{
    print '<div class="' . $type . '" style="margin: 0 0 12px;">' . $html . '</div>';
}

$form = new Form($db);
$api = HapiPayPages::api();
$webhookUrl = dol_buildpath('/hapipay/public/webhook.php', 2) . '?e=' . ((int) $conf->entity);

llxHeader('', $langs->trans('HapiPaySetup'));
print load_fiche_titre($langs->trans('HapiPaySetup'), '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>', 'title_setup');

print '<div class="opacitymedium" style="margin-bottom: 12px;">' . $langs->trans('HapiPayMoneyNote') . '</div>';

// Vérification de la clé auprès de HapiPay.
if (HapiPayPages::configured()) {
    $who = $api->whoami();
    if ($who['ok']) {
        hapipay_notice('info', $langs->trans('HapiPayKeyOk', HapiPayApi::plain($who['data']['name'] ?? '?'), $api->isTest() ? $langs->transnoentitiesnoconv('HapiPayModeTest') : $langs->transnoentitiesnoconv('HapiPayModeLive')));
    } else {
        hapipay_notice('error', $langs->trans('HapiPayKeyKo', HapiPayApi::plain($who['message'])));
    }
} else {
    $register = $api->siteUrl('portail/register');
    $portal = $api->siteUrl('portail');
    hapipay_notice('info', $langs->trans('HapiPayNoAccount', HapiPayApi::plain($register), HapiPayApi::plain((string) parse_url($api->siteUrl(), PHP_URL_HOST)), HapiPayApi::plain($portal)));
}

if (strtoupper((string) $conf->currency) === 'EUR' || strtoupper((string) $conf->currency) === 'USD') {
    hapipay_notice('warning', $langs->trans('HapiPayCurrencyWarning', $conf->currency));
}
if (isModEnabled('bank') && getDolGlobalInt('HAPIPAY_BANK_ACCOUNT') <= 0) {
    hapipay_notice('warning', $langs->trans('HapiPayBankWarning'));
}

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" autocomplete="off">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('HapiPayApiUrl') . '</td><td>';
print '<input class="minwidth400" type="url" name="HAPIPAY_API_URL" value="' . dol_escape_htmltag(getDolGlobalString('HAPIPAY_API_URL')) . '" placeholder="' . HapiPayApi::DEFAULT_URL . '">';
print '<br><span class="opacitymedium">' . $langs->trans('HapiPayApiUrlHelp', HapiPayApi::DEFAULT_URL) . '</span></td></tr>';

$hasKey = getDolGlobalString('HAPIPAY_API_KEY') !== '';
print '<tr class="oddeven"><td class="fieldrequired">' . $langs->trans('HapiPayApiKey') . '</td><td>';
print '<input class="minwidth400" type="password" name="HAPIPAY_API_KEY" value="" autocomplete="new-password" placeholder="' . ($hasKey ? '••••••••' : 'hpc_test_…') . '">';
print '<br><span class="opacitymedium">' . ($hasKey ? $langs->trans('HapiPayKeepEmpty') . ' ' : '') . $langs->trans('HapiPayApiKeyHelp') . '</span></td></tr>';

$hasSecret = getDolGlobalString('HAPIPAY_WEBHOOK_SECRET') !== '';
print '<tr class="oddeven"><td class="fieldrequired">' . $langs->trans('HapiPayWebhookSecret') . '</td><td>';
print '<input class="minwidth400" type="password" name="HAPIPAY_WEBHOOK_SECRET" value="" autocomplete="new-password" placeholder="' . ($hasSecret ? '••••••••' : 'whsec_…') . '">';
print '<br><span class="opacitymedium">' . ($hasSecret ? $langs->trans('HapiPayKeepEmpty') . ' ' : '') . $langs->trans('HapiPayWebhookHelp') . '</span>';
print '<br><code style="user-select: all;">' . dol_escape_htmltag($webhookUrl) . '</code></td></tr>';

if (isModEnabled('bank')) {
    print '<tr class="oddeven"><td>' . $langs->trans('HapiPayBankAccount') . '</td><td>';
    $form->select_comptes(getDolGlobalInt('HAPIPAY_BANK_ACCOUNT'), 'HAPIPAY_BANK_ACCOUNT', 0, '', 1);
    print '<br><span class="opacitymedium">' . $langs->trans('HapiPayBankAccountHelp') . '</span></td></tr>';
}

print '<tr class="oddeven"><td>' . $langs->trans('HapiPayPaymentMode') . '</td><td>';
$form->select_types_paiements(getDolGlobalInt('HAPIPAY_PAYMENT_MODE') ?: dol_getIdFromCode($db, 'HPAY', 'c_paiement', 'code', 'id', 1), 'HAPIPAY_PAYMENT_MODE', '', 0, 1);
print '</td></tr>';

print '</table>';
print '<div class="center" style="margin-top: 12px;"><input type="submit" class="button button-save" value="' . $langs->trans('Save') . '">';
if ($hasKey || $hasSecret) {
    print ' &nbsp; <a class="button button-cancel" href="' . $_SERVER['PHP_SELF'] . '?action=forget&token=' . newToken() . '">' . $langs->trans('HapiPayForget') . '</a>';
}
print '</div></form>';

// Dernières pages de paiement : de quoi suivre un paiement sans ouvrir HapiPay.
$rows = (new HapiPayPages($db))->latest();
print '<br>' . load_fiche_titre($langs->trans('HapiPayLatest'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Date') . '</td><td>' . $langs->trans('HapiPayDocument') . '</td><td class="right">' . $langs->trans('Amount') . '</td><td>' . $langs->trans('Status') . '</td><td>' . $langs->trans('HapiPayTransaction') . '</td></tr>';
if (!$rows) {
    print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">' . $langs->trans('HapiPayNoPage') . '</span></td></tr>';
}
foreach ($rows as $row) {
    if ($row->source === 'invoice') {
        $link = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . ((int) $row->fk_object) . '">' . $langs->trans('Invoice') . ' #' . ((int) $row->fk_object) . '</a>';
    } else {
        $link = '<a href="' . DOL_URL_ROOT . '/don/card.php?id=' . ((int) $row->fk_object) . '">' . $langs->trans('HapiPayDonation') . ' #' . ((int) $row->fk_object) . '</a>';
    }
    $badge = ['paye' => 'status4', 'ecart' => 'status8', 'ouvert' => 'status1'][$row->status] ?? 'status0';
    print '<tr class="oddeven">';
    print '<td class="nowraponall">' . dol_print_date($db->jdate($row->datec), 'dayhour') . '</td>';
    print '<td>' . $link . ($row->test ? ' <span class="badge badge-warning">test</span>' : '') . '</td>';
    print '<td class="right nowraponall">' . dol_escape_htmltag(HapiPayApi::formatAmount($row->amount, $row->currency)) . '</td>';
    print '<td>' . dolGetStatus($langs->trans('HapiPayStatus_' . $row->status), '', '', $badge, 1) . ($row->note ? '<br><span class="opacitymedium small">' . dol_escape_htmltag($row->note) . '</span>' : '') . '</td>';
    print '<td class="small">' . dol_escape_htmltag((string) $row->transaction_id) . ($row->operator ? ' <span class="opacitymedium">(' . dol_escape_htmltag($row->operator) . ')</span>' : '') . '</td>';
    print '</tr>';
}
print '</table>';

llxFooter();
$db->close();
