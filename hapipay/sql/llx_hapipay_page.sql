-- Pages de paiement HapiPay créées par le module : une par tentative de
-- paiement d'une facture ou d'un don. Sert à retrouver la pièce au retour
-- du webhook, à vérifier le montant et à ne jamais enregistrer deux fois
-- le même paiement.
--
-- Copyright (C) HapiPay
-- Licence GPL-3.0-or-later

CREATE TABLE llx_hapipay_page(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	reference varchar(191) NOT NULL,
	code varchar(32) NOT NULL,
	url varchar(255),
	source varchar(16) NOT NULL,
	fk_object integer NOT NULL,
	amount bigint NOT NULL,
	currency varchar(3) NOT NULL,
	test smallint DEFAULT 0 NOT NULL,
	status varchar(16) DEFAULT 'ouvert' NOT NULL,
	transaction_id varchar(64),
	operator varchar(64),
	fk_payment integer,
	note varchar(255),
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
