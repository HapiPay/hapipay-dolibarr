<?php
/**
 * Pages de paiement HapiPay des factures et des dons, et enregistrement du
 * règlement dans Dolibarr quand HapiPay confirme le paiement.
 *
 * Copyright (C) HapiPay
 * @license GPL-3.0-or-later
 */

require_once __DIR__ . '/hapipayapi.class.php';

class HapiPayPages
{
    /** Pièces payables par HapiPay : source de la page publique de Dolibarr => type. */
    const SOURCES = ['invoice' => 'fac', 'donation' => 'don'];

    /** Langues de la page HapiPay. */
    const LANGUAGES = ['fr', 'en', 'ar', 'mg', 'es'];

    /** @var DoliDB */
    private $db;

    /**
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /** Client de l'API, avec la clé déchiffrée. */
    public static function api()
    {
        return new HapiPayApi(getDolGlobalString('HAPIPAY_API_URL'), self::secret('HAPIPAY_API_KEY'));
    }

    /** Réglage secret, enregistré chiffré par la page de configuration. */
    public static function secret($name)
    {
        $value = getDolGlobalString($name);
        if ($value !== '' && function_exists('dolDecrypt')) {
            $value = dolDecrypt($value);
        }

        return (string) $value;
    }

    /** Une clé d'API est enregistrée : le bouton HapiPay peut s'afficher. */
    public static function configured()
    {
        return getDolGlobalString('HAPIPAY_API_KEY') !== '';
    }

    public static function supports($source)
    {
        return isset(self::SOURCES[(string) $source]);
    }

    /**
     * Préfixe propre à cette installation de Dolibarr : un même compte HapiPay
     * peut servir une boutique et un Dolibarr sans que leurs références se mêlent.
     */
    public static function instanceTag()
    {
        global $conf, $dolibarr_main_instance_unique_id;

        $seed = !empty($conf->file->instance_unique_id) ? $conf->file->instance_unique_id : (string) $dolibarr_main_instance_unique_id;

        return substr(md5($seed . '|' . DOL_DOCUMENT_ROOT), 0, 6);
    }

    /**
     * Crée la page HapiPay d'une facture ou d'un don et renvoie son adresse.
     * Montant : le reste à payer, ou le montant demandé s'il est plus petit.
     *
     * @return array{ok: bool, url: string, message: string}
     */
    public function start($source, $ref, $requestedAmount = null)
    {
        global $conf, $langs, $mysoc;

        $object = $this->load($source, $ref);
        if (!$object) {
            return $this->failure($langs->trans('HapiPayUnknownObject'));
        }

        $remaining = (float) price2num($object->getRemainToPay(), 'MT');
        $amount = $remaining;
        $requested = (float) price2num((string) $requestedAmount, 'MT');
        if ($requested > 0 && $requested < $remaining) {
            $amount = $requested;
        }

        if ($amount <= 0) {
            return $this->failure($langs->trans('HapiPayNothingToPay'));
        }
        // HapiPay compte en unités entières de la devise (FCFA, ariary, francs congolais…).
        if (abs($amount - round($amount)) > 0.001) {
            return $this->failure($langs->trans('HapiPayNoCents'));
        }

        $currency = strtoupper((string) $conf->currency);
        if ($source === 'invoice' && !empty($object->multicurrency_code) && strtoupper($object->multicurrency_code) !== $currency) {
            return $this->failure($langs->trans('HapiPayOtherCurrency', $object->multicurrency_code, $currency));
        }

        $reference = 'dol-' . self::instanceTag() . '-' . self::SOURCES[$source] . '-' . (int) $object->id . '-' . strtolower(substr(bin2hex(random_bytes(4)), 0, 6));
        $title = $source === 'invoice'
            ? $langs->transnoentitiesnoconv('HapiPayTitleInvoice', $object->ref)
            : $langs->transnoentitiesnoconv('HapiPayTitleDonation', $object->ref ?: $object->id);
        $lang = substr((string) $langs->defaultlang, 0, 2);

        $api = self::api();
        $link = $api->createLink([
            'reference' => $reference,
            'titre' => dol_trunc($title, 120, 'right', 'UTF-8', 1),
            'description' => (string) $mysoc->name,
            'montant' => (int) round($amount),
            'devise' => $currency,
            'usage_unique' => true,
            'url_retour' => dol_buildpath('/hapipay/public/return.php', 2) . '?r=' . urlencode($reference) . '&e=' . ((int) $conf->entity),
            'langue' => in_array($lang, self::LANGUAGES, true) ? $lang : null,
        ]);

        if (!$link['ok'] || empty($link['data']['url']) || empty($link['data']['code'])) {
            dol_syslog('HapiPay : page de paiement non créée pour ' . $source . ' ' . $object->id . ' (' . $link['message'] . ')', LOG_ERR);

            return $this->failure($link['status'] === 422 && $link['message'] !== ''
                ? $langs->trans('HapiPayRefused', HapiPayApi::plain($link['message']))
                : $langs->trans('HapiPayUnavailable'));
        }

        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'hapipay_page (entity, reference, code, url, source, fk_object, amount, currency, test, status, datec) VALUES ('
            . ((int) $conf->entity) . ", '" . $this->db->escape($reference) . "', '" . $this->db->escape((string) $link['data']['code']) . "', '"
            . $this->db->escape((string) $link['data']['url']) . "', '" . $this->db->escape($source) . "', " . ((int) $object->id) . ', '
            . ((int) round($amount)) . ", '" . $this->db->escape($currency) . "', " . ($api->isTest() ? 1 : 0) . ", 'ouvert', '" . $this->db->idate(dol_now()) . "')";
        if (!$this->db->query($sql)) {
            return $this->failure($langs->trans('HapiPayUnavailable'));
        }

        return ['ok' => true, 'url' => (string) $link['data']['url'], 'message' => ''];
    }

    /** Page HapiPay connue de ce Dolibarr, d'après sa référence. */
    public function find($reference)
    {
        if (!preg_match('/^dol-[0-9a-f]{6}-(fac|don)-\d+-[0-9a-z]+$/', (string) $reference)) {
            return null;
        }

        $resql = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . "hapipay_page WHERE reference = '" . $this->db->escape((string) $reference) . "'");

        return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
    }

    /** Dernières pages, pour la page de configuration. */
    public function latest($limit = 15)
    {
        global $conf;

        $rows = [];
        $resql = $this->db->query('SELECT * FROM ' . MAIN_DB_PREFIX . 'hapipay_page WHERE entity = ' . ((int) $conf->entity) . ' ORDER BY rowid DESC' . $this->db->plimit((int) $limit));
        while ($resql && ($row = $this->db->fetch_object($resql))) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Paiement confirmé par HapiPay (webhook signé, ou état demandé à l'API au
     * retour du client) : règlement enregistré une seule fois, pièce classée
     * payée si plus rien n'est dû, écriture sur le compte bancaire choisi.
     *
     * @param object $page   Ligne de llx_hapipay_page
     * @param array  $data   id (transaction), montant, devise, operateur
     * @return string paye | deja | ecart | erreur
     */
    public function recordPayment($page, array $data)
    {
        $transaction = isset($data['id']) ? substr((string) $data['id'], 0, 64) : '';
        $operator = isset($data['operateur']) ? substr((string) $data['operateur'], 0, 64) : '';

        // Le montant et la devise doivent être ceux de la page : sinon rien n'est enregistré.
        if ((int) ($data['montant'] ?? -1) !== (int) $page->amount || strtoupper((string) ($data['devise'] ?? '')) !== strtoupper($page->currency)) {
            $this->db->query('UPDATE ' . MAIN_DB_PREFIX . "hapipay_page SET status = 'ecart', transaction_id = '" . $this->db->escape($transaction) . "', note = '"
                . $this->db->escape('Montant ou devise différent : ' . HapiPayApi::formatAmount($data['montant'] ?? 0, $data['devise'] ?? '')) . "' WHERE rowid = " . ((int) $page->rowid) . " AND status = 'ouvert'");

            return 'ecart';
        }

        $this->db->begin();

        // Verrou : seul le premier (webhook ou retour du client) passe la page à « payé ».
        $resql = $this->db->query('UPDATE ' . MAIN_DB_PREFIX . "hapipay_page SET status = 'paye', transaction_id = '" . $this->db->escape($transaction) . "', operator = '"
            . $this->db->escape($operator) . "' WHERE rowid = " . ((int) $page->rowid) . " AND status = 'ouvert'");
        if (!$resql || $this->db->affected_rows($resql) !== 1) {
            $this->db->rollback();

            return 'deja';
        }

        $paymentId = $page->source === 'invoice'
            ? $this->payInvoice($page, $transaction, $operator)
            : $this->payDonation($page, $transaction, $operator);

        if ($paymentId <= 0) {
            $this->db->rollback();
            // La page reste ouverte : la relance du webhook pourra enregistrer le
            // règlement une fois le problème corrigé. La note le signale en attendant.
            $this->db->query('UPDATE ' . MAIN_DB_PREFIX . "hapipay_page SET transaction_id = '" . $this->db->escape($transaction) . "', note = 'Paiement reçu par HapiPay, règlement pas encore enregistré dans Dolibarr' WHERE rowid = " . ((int) $page->rowid) . " AND status = 'ouvert'");

            return 'erreur';
        }

        $this->db->query('UPDATE ' . MAIN_DB_PREFIX . 'hapipay_page SET fk_payment = ' . ((int) $paymentId) . ' WHERE rowid = ' . ((int) $page->rowid));
        $this->db->commit();

        return 'paye';
    }

    /** Note du règlement : transaction HapiPay et opérateur. */
    private function noteFor($page, $transaction, $operator)
    {
        return 'HapiPay ' . $transaction . ($operator !== '' ? ' (' . $operator . ')' : '') . ($page->test ? ' - paiement simulé, mode test' : '');
    }

    private function payInvoice($page, $transaction, $operator)
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $page->fk_object) <= 0) {
            return -1;
        }

        $payment = new Paiement($this->db);
        $payment->datepaye = dol_now();
        $payment->amounts = [$invoice->id => (float) $page->amount];
        $payment->paiementid = $this->paymentTypeId();
        $payment->num_payment = $transaction;
        $payment->note_public = $this->noteFor($page, $transaction, $operator);
        $payment->note_private = $payment->note_public;
        $payment->ext_payment_id = $transaction;
        $payment->ext_payment_site = 'HapiPay';

        // Classe la facture payée quand plus rien n'est dû.
        $id = $payment->create($this->user(), 1);
        if ($id <= 0) {
            dol_syslog('HapiPay : règlement de facture refusé par Dolibarr (' . $payment->error . ')', LOG_ERR);

            return -1;
        }

        return $this->toBank($payment, 'payment', $invoice->type == Facture::TYPE_CREDIT_NOTE ? '(CustomerInvoicePaymentBack)' : '(CustomerInvoicePayment)') ? $id : -1;
    }

    private function payDonation($page, $transaction, $operator)
    {
        require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';
        require_once DOL_DOCUMENT_ROOT . '/don/class/paymentdonation.class.php';

        $donation = new Don($this->db);
        if ($donation->fetch((int) $page->fk_object) <= 0) {
            return -1;
        }

        $payment = new PaymentDonation($this->db);
        $payment->chid = $donation->id;
        $payment->fk_donation = $donation->id;
        $payment->datep = dol_now();
        $payment->amounts = [$donation->id => (float) $page->amount];
        $payment->paymenttype = $this->paymentTypeId();
        $payment->num_payment = $transaction;
        $payment->note_public = $this->noteFor($page, $transaction, $operator);
        $payment->ext_payment_id = $transaction;
        $payment->ext_payment_site = 'HapiPay';

        $id = $payment->create($this->user(), 1);
        if ($id <= 0) {
            dol_syslog('HapiPay : règlement de don refusé par Dolibarr (' . $payment->error . ')', LOG_ERR);

            return -1;
        }

        $user = $this->user();
        if ((float) $donation->getRemainToPay() <= 0) {
            if ((int) $donation->status === Don::STATUS_DRAFT) {
                $donation->valid_promesse($donation->id, $user->id);
            }
            $donation->setPaid($donation->id);
        }

        return $this->toBank($payment, 'payment_donation', '(DonationPayment)') ? $id : -1;
    }

    /** Écriture sur le compte bancaire réglé (celui du compte opérateur), si la banque est active. */
    private function toBank($payment, $mode, $label)
    {
        $account = getDolGlobalInt('HAPIPAY_BANK_ACCOUNT');
        if (!isModEnabled('bank') || $account <= 0) {
            return true;
        }

        if ($payment->addPaymentToBank($this->user(), $mode, $label, $account, '', '') < 0) {
            dol_syslog('HapiPay : écriture bancaire refusée par Dolibarr (' . $payment->error . ')', LOG_ERR);

            return false;
        }

        return true;
    }

    /** Mode de règlement réglé, sinon « HapiPay (mobile money) » créé à l'activation. */
    private function paymentTypeId()
    {
        $id = getDolGlobalInt('HAPIPAY_PAYMENT_MODE');
        if ($id > 0) {
            return $id;
        }

        $id = (int) dol_getIdFromCode($this->db, 'HPAY', 'c_paiement', 'code', 'id', 1);

        return $id > 0 ? $id : (int) dol_getIdFromCode($this->db, 'VIR', 'c_paiement', 'code', 'id', 1);
    }

    /** Utilisateur des pages publiques : celui de la session s'il y en a un, sinon anonyme. */
    private function user()
    {
        global $user;

        if (!is_object($user)) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
            $user = new User($this->db);
        }

        return $user;
    }

    /** Facture (par référence) ou don (par numéro), comme sur la page publique de Dolibarr. */
    private function load($source, $ref)
    {
        if ($source === 'invoice' && isModEnabled('invoice')) {
            require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
            $object = new Facture($this->db);

            return $object->fetch(0, (string) $ref) > 0 && (int) $object->status !== Facture::STATUS_DRAFT ? $object : null;
        }

        if ($source === 'donation' && isModEnabled('don')) {
            require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';
            $object = new Don($this->db);

            return $object->fetch((int) $ref) > 0 ? $object : null;
        }

        return null;
    }

    private function failure($message)
    {
        return ['ok' => false, 'url' => '', 'message' => $message];
    }
}
