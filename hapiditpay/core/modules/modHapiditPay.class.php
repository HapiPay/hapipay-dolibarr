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

    /** Nom de l'ancien module (0.1.x), dont les réglages et la table sont repris. */
    const PREVIOUS_NAME = 'hapipay';

    /** Réglages de l'ancien module, sans leur préfixe. */
    const PREVIOUS_SETTINGS = ['API_URL', 'API_KEY', 'WEBHOOK_SECRET', 'BANK_ACCOUNT', 'PAYMENT_MODE'];

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
        $this->version = '0.2.0';
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

        // Avant la création des tables : la table de l'ancien module, si elle
        // existe, devient celle de ce module au lieu d'en avoir une vide à côté.
        if ($this->takeOverPreviousModule() < 0) {
            return -1;
        }

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
        // Mode de règlement créé par l'ancien module : même code, nouveau nom.
        $this->db->query('UPDATE ' . MAIN_DB_PREFIX . "c_paiement SET libelle = 'HapiditPay (mobile money)', module = 'hapiditpay' WHERE code = '" . self::PAYMENT_CODE . "' AND module = '" . self::PREVIOUS_NAME . "'");

        return $this->_init([], $options);
    }

    /**
     * Reprise de l'ancien module « HapiPay » (versions 0.1.x, dossier
     * custom/hapipay), devenu HapiditPay en 0.2.0 sans changer de numéro.
     *
     * - Réglages HAPIPAY_* (clé et secret chiffrés compris) recopiés tels quels
     *   sous HAPIDITPAY_*, pour chaque entité, puis effacés. Le chiffrement de
     *   Dolibarr ne dépend pas du nom du réglage : la clé reste lisible.
     * - Réglages d'activation de l'ancien module (MAIN_MODULE_HAPIPAY,
     *   MAIN_MODULE_HAPIPAY_HOOKS…) effacés : son dossier n'existe plus.
     * - Table llx_hapipay_page renommée en llx_hapiditpay_page, avec ses index,
     *   si la nouvelle n'existe pas encore.
     *
     * Sans effet quand il n'y a rien à reprendre : passe sur une base neuve
     * comme à chaque nouvelle activation.
     *
     * @return int 1 si tout va bien, -1 en cas d'erreur
     */
    private function takeOverPreviousModule()
    {
        $old = strtoupper(self::PREVIOUS_NAME) . '_';
        $new = 'HAPIDITPAY_';

        // 1. Réglages du module, entité par entité.
        $resql = $this->db->query('SELECT name, entity, value, type, visible, note FROM ' . MAIN_DB_PREFIX . "const WHERE name IN ('" . implode("', '", array_map(function ($suffix) use ($old) {
            return $old . $suffix;
        }, self::PREVIOUS_SETTINGS)) . "')");
        if (!$resql) {
            $this->error = $this->db->lasterror();

            return -1;
        }
        $rows = [];
        while ($row = $this->db->fetch_object($resql)) {
            $rows[] = $row;
        }
        foreach ($rows as $row) {
            $newName = $new . substr($row->name, strlen($old));
            $exists = $this->db->query('SELECT rowid FROM ' . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($newName) . "' AND entity = " . ((int) $row->entity));
            if (!$exists) {
                $this->error = $this->db->lasterror();

                return -1;
            }
            // Un réglage déjà fait sous le nouveau nom l'emporte sur l'ancien.
            if (!$this->db->fetch_object($exists)) {
                $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'const (name, entity, value, type, visible, note) VALUES ('
                    . "'" . $this->db->escape($newName) . "', " . ((int) $row->entity) . ", '" . $this->db->escape((string) $row->value) . "', '"
                    . $this->db->escape((string) $row->type) . "', " . ((int) $row->visible) . ', ' . ($row->note === null ? 'NULL' : "'" . $this->db->escape((string) $row->note) . "'") . ')';
                if (!$this->db->query($sql)) {
                    $this->error = $this->db->lasterror();

                    return -1;
                }
            }
            $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . "const WHERE name = '" . $this->db->escape($row->name) . "' AND entity = " . ((int) $row->entity));
        }

        // 2. Activation de l'ancien module (toutes entités).
        $this->db->query('DELETE FROM ' . MAIN_DB_PREFIX . "const WHERE name = 'MAIN_MODULE_" . strtoupper(self::PREVIOUS_NAME) . "' OR name LIKE 'MAIN_MODULE_" . strtoupper(self::PREVIOUS_NAME) . "\\_%'");

        // 3. Table des pages de paiement.
        $oldTable = MAIN_DB_PREFIX . self::PREVIOUS_NAME . '_page';
        $newTable = MAIN_DB_PREFIX . 'hapiditpay_page';
        $tables = $this->db->DDLListTables($this->db->database_name, MAIN_DB_PREFIX . '%_page');
        if (in_array($oldTable, $tables, true) && !in_array($newTable, $tables, true)) {
            if (!$this->db->query('ALTER TABLE ' . $oldTable . ' RENAME TO ' . $newTable)) {
                $this->error = $this->db->lasterror();

                return -1;
            }
            // Anciens index retirés : le fichier .key.sql les recrée sous le nouveau nom.
            foreach (['uk_' . self::PREVIOUS_NAME . '_page_reference', 'idx_' . self::PREVIOUS_NAME . '_page_object'] as $index) {
                $this->db->query($this->db->type === 'pgsql' ? 'DROP INDEX IF EXISTS ' . $index : 'ALTER TABLE ' . $newTable . ' DROP INDEX ' . $index);
            }
        }

        return 1;
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
