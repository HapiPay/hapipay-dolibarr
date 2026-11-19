<?php
/**
 * Points d'accroche de la page publique de paiement de Dolibarr
 * (public/payment/newpayment.php) : HapiditPay y devient un moyen de paiement
 * en ligne, avec son bouton, à côté de Stripe ou PayPal.
 *
 * Copyright (C) Hapidit Group
 * @license GPL-3.0-or-later
 */

require_once __DIR__ . '/hapiditpaypages.class.php';

class ActionsHapiditPay
{
    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error = '';

    /** @var string[] */
    public $errors = [];

    /** @var array */
    public $results = [];

    /** @var string */
    public $resprints = '';

    /**
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * HapiditPay compte parmi les moyens de paiement en ligne dès qu'une clé est
     * enregistrée : Dolibarr montre alors le lien de paiement sur les factures
     * et les dons, dans les e-mails et sur les PDF.
     */
    public function getValidPayment($parameters, &$object, &$action, $hookmanager)
    {
        $method = isset($parameters['paymentmethod']) ? (string) $parameters['paymentmethod'] : '';
        if (!HapiditPayPages::configured() || ($method !== '' && $method !== 'hapiditpay')) {
            return 0;
        }
        // Page publique d'une commande ou d'une adhésion : HapiditPay ne la paie pas
        // encore, il ne s'y propose donc pas (pas de page sans bouton).
        $source = $this->source();
        if ($source !== '' && !HapiditPayPages::supports($source)) {
            return 0;
        }

        $this->results['validpaymentmethod'] = [
            'hapiditpay' => !empty($parameters['mode']) ? ['label' => 'HapiditPay', 'status' => 'valid'] : 'valid',
        ];

        return 0;
    }

    /** Bouton « Payer avec HapiditPay » pour une facture ou un don. */
    public function doAddButton($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        $method = isset($parameters['paymentmethod']) ? (string) $parameters['paymentmethod'] : '';
        if (!HapiditPayPages::configured() || ($method !== '' && $method !== 'hapiditpay') || !HapiditPayPages::supports($this->source())) {
            return 0;
        }

        $langs->load('hapiditpay@hapiditpay');
        $logo = dol_buildpath('/hapiditpay/img/hapiditpay.svg', 1);

        $this->resprints = '<div class="button buttonpayment" id="div_dopayment_hapiditpay">'
            . '<img src="' . dol_escape_htmltag($logo) . '" alt="" width="20" height="20" style="vertical-align: middle; margin-inline-end: 6px;">'
            . '<input class="" type="submit" id="dopayment_hapiditpay" name="dopayment_hapiditpay" value="' . dol_escape_htmltag($langs->trans('HapiditPayDoPayment')) . '">'
            . '<br><span class="buttonpaymentsmall">' . dol_escape_htmltag($langs->trans('HapiditPayMeans')) . '</span>'
            . '</div>'
            . '<script>$(document).ready(function () {'
            . '$("#div_dopayment_hapiditpay").click(function () { $("#dopayment_hapiditpay").click(); });'
            . '$("#dopayment_hapiditpay").click(function (e) { $("#div_dopayment_hapiditpay").css("cursor", "wait"); e.stopPropagation(); });'
            . '});</script>';

        return 0;
    }

    /**
     * Clic sur le bouton : la page HapiditPay est créée, puis le client y est
     * envoyé. Appelé avant tout affichage, la redirection est donc possible.
     */
    public function doPayment($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        if ($action !== 'dopayment' || ($parameters['paymentmethod'] ?? '') !== 'hapiditpay') {
            return 0;
        }

        $langs->load('hapiditpay@hapiditpay');
        $source = $this->source();
        if (!HapiditPayPages::supports($source)) {
            $this->errors[] = $langs->trans('HapiditPayUnsupported');

            return -1;
        }

        $page = (new HapiditPayPages($this->db))->start($source, GETPOST('ref', 'alpha'), GETPOST('amount', 'alpha'));
        if (!$page['ok']) {
            $this->errors[] = $page['message'];
            $action = '';

            return -1;
        }

        header('Location: ' . $page['url']);
        exit;
    }

    /** Source de la page publique : invoice, donation, order… */
    private function source()
    {
        $source = GETPOST('s', 'aZ09');

        return $source !== '' ? $source : GETPOST('source', 'aZ09');
    }
}
