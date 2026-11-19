<?php
/**
 * Module HapiditPay pour Dolibarr : paiement des factures et des dons par
 * mobile money, sur la page de paiement HapiditPay. L'argent va directement
 * du client au compte opérateur de l'entreprise ou de l'association :
 * HapiditPay ne le touche jamais.
 *
 * Copyright (C) Hapidit Group
 * @license GPL-3.0-or-later
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modHapiditPay extends DolibarrModules
{
    /** Code du mode de règlement créé à l'activation (6 caractères au plus). */
    const PAYMENT_CODE = 'HPAY';

    /**
     * @param DoliDB $db
     */
    public function __construct($db)
    {
        $this->db = $db;

        // Numéro à faire attribuer par Dolibarr avant la publication sur DoliStore.
        $this->numero = 527400;
        $this->rights_class = 'hapiditpay';
        $this->family = 'interface';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'Paiement des factures et des dons par mobile money avec HapiditPay';
        $this->descriptionlong = 'Ajoute « Payer avec HapiditPay » aux pages de paiement en ligne de Dolibarr. Le règlement est enregistré tout seul sur la facture ou le don.';
        $this->editor_name = 'Hapidit Group';
        $this->editor_url = 'https://hapiditpay.com';
        $this->version = '0.2.1';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'hapiditpay@hapiditpay';

        // Contextes où Dolibarr demande la liste des moyens de paiement en ligne :
        // page publique de paiement, fiches et liste des factures (e-mails et PDF
        // compris), fiche des dons. Ni commandes ni adhésions pour l'instant.
        $this->module_parts = [
            'hooks' => [
                'data' => ['newpayment', 'paymentok', 'paymentko', 'invoicecard', 'invoicelist', 'doncard'],
                'entity' => '0',
            ],
        ];

        $this->dirs = [];
        $this->config_page_url = ['setup.php@hapiditpay'];
        $this->depends = [];
        $this->requiredby = [];
        $this->langfiles = ['hapiditpay@hapiditpay'];
        $this->phpmin = [8, 0];
        $this->need_dolibarr_version = [19, 0];
        $this->const = [];
        $this->rights = [];
        $this->menu = [];
    }

    /**
     * Activation : table des pages HapiditPay et mode de règlement « HapiditPay ».
     *
     * @param string $options
     * @return int
     */
    public function init($options = '')
    {
        global $conf;

        if ($this->_load_tables('/hapiditpay/sql/') < 0) {
            return -1;
        }

        // Le mode de règlement est créé une fois, et gardé à la désactivation :
        // des règlements peuvent s'y rapporter.
        $entity = (int) $conf->entity;
        $resql = $this->db->query('SELECT id FROM ' . MAIN_DB_PREFIX . "c_paiement WHERE code = '" . self::PAYMENT_CODE . "' AND entity = " . $entity);
        if ($resql && !$this->db->fetch_object($resql)) {
            $this->db->query('INSERT INTO ' . MAIN_DB_PREFIX . "c_paiement (entity, code, libelle, type, active, module, position) VALUES (" . $entity . ", '" . self::PAYMENT_CODE . "', 'HapiditPay (mobile money)', 2, 1, 'hapiditpay', 0)");
        }

        return $this->_init([], $options);
    }

    /**
     * @param string $options
     * @return int
     */
    public function remove($options = '')
    {
        return $this->_remove([], $options);
    }
}
