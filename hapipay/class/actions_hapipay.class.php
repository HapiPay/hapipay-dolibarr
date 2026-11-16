<?php
/**
 * Points d'accroche de la page publique de paiement de Dolibarr
 * (public/payment/newpayment.php) : HapiPay y devient un moyen de paiement
 * en ligne, avec son bouton, à côté de Stripe ou PayPal.
 *
 * Copyright (C) HapiPay
 * @license GPL-3.0-or-later
 */

require_once __DIR__ . '/hapipaypages.class.php';

class ActionsHapiPay
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
     * HapiPay compte parmi les moyens de paiement en ligne dès qu'une clé est
     * enregistrée : Dolibarr montre alors le lien de paiement sur les factures
     * et les dons, dans les e-mails et sur les PDF.
     */
    public function getValidPayment($parameters, &$object, &$action, $hookmanager)
    {
        $method = isset($parameters['paymentmethod']) ? (string) $parameters['paymentmethod'] : '';
        if (!HapiPayPages::configured() || ($method !== '' && $method !== 'hapipay')) {
            return 0;
        }
        // Page publique d'une commande ou d'une adhésion : HapiPay ne la paie pas
        // encore, il ne s'y propose donc pas (pas de page sans bouton).
        $source = $this->source();
        if ($source !== '' && !HapiPayPages::supports($source)) {
            return 0;
        }

        $this->results['validpaymentmethod'] = [
            'hapipay' => !empty($parameters['mode']) ? ['label' => 'HapiPay', 'status' => 'valid'] : 'valid',
        ];

        return 0;
    }

    /** Bouton « Payer avec HapiPay » pour une facture ou un don. */
    public function doAddButton($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        $method = isset($parameters['paymentmethod']) ? (string) $parameters['paymentmethod'] : '';
        if (!HapiPayPages::configured() || ($method !== '' && $method !== 'hapipay') || !HapiPayPages::supports($this->source())) {
            return 0;
        }

        $langs->load('hapipay@hapipay');
        $logo = dol_buildpath('/hapipay/img/hapipay.svg', 1);

        $this->resprints = '<div class="button buttonpayment" id="div_dopayment_hapipay">'
            . '<img src="' . dol_escape_htmltag($logo) . '" alt="" width="20" height="20" style="vertical-align: middle; margin-inline-end: 6px;">'
            . '<input class="" type="submit" id="dopayment_hapipay" name="dopayment_hapipay" value="' . dol_escape_htmltag($langs->trans('HapiPayDoPayment')) . '">'
            . '<br><span class="buttonpaymentsmall">' . dol_escape_htmltag($langs->trans('HapiPayMeans')) . '</span>'
            . '</div>'
            . '<script>$(document).ready(function () {'
            . '$("#div_dopayment_hapipay").click(function () { $("#dopayment_hapipay").click(); });'
            . '$("#dopayment_hapipay").click(function (e) { $("#div_dopayment_hapipay").css("cursor", "wait"); e.stopPropagation(); });'
            . '});</script>';

        return 0;
    }

    /**
     * Clic sur le bouton : la page HapiPay est créée, puis le client y est
     * envoyé. Appelé avant tout affichage, la redirection est donc possible.
     */
    public function doPayment($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        if ($action !== 'dopayment' || ($parameters['paymentmethod'] ?? '') !== 'hapipay') {
            return 0;
        }

        $langs->load('hapipay@hapipay');
        $source = $this->source();
        if (!HapiPayPages::supports($source)) {
            $this->errors[] = $langs->trans('HapiPayUnsupported');

            return -1;
        }

        $page = (new HapiPayPages($this->db))->start($source, GETPOST('ref', 'alpha'), GETPOST('amount', 'alpha'));
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
