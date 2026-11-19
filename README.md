# HapiditPay pour Dolibarr

Module Dolibarr (19 et plus récent, testé avec la 22) pour faire payer les factures et les dons par mobile money, carte bancaire et PayPal avec HapiditPay. L'argent va directement du client ou du donateur au compte opérateur de l'entreprise ou de l'association : HapiditPay ne le touche jamais.

## Fonctionnement

1. Une fois la clé enregistrée, HapiditPay devient un moyen de paiement en ligne de Dolibarr. Le lien de paiement s'affiche sur les factures et les dons validés, dans les e-mails et sur les PDF, comme avec Stripe ou PayPal.
2. Sur la page publique de paiement de Dolibarr, le client clique sur « Payer avec HapiditPay ». Le module crée une page de paiement HapiditPay à usage unique (`POST /api/v1/liens`) avec le reste à payer, la devise et une adresse de retour, puis y envoie le client. Si HapiditPay ne répond pas, rien n'est créé et le client voit un message.
3. Le client paie sur la page HapiditPay, puis revient sur Dolibarr.
4. HapiditPay envoie le webhook signé `paiement.reussi` à `custom/hapiditpay/public/webhook.php`. Le module vérifie la signature, le montant et la devise, puis enregistre le règlement (mode « HapiditPay (mobile money) », numéro de transaction HapiditPay) : la facture ou le don passe à « payé », et l'écriture est inscrite sur le compte bancaire choisi.
5. Si le webhook n'est pas encore arrivé au retour du client, le module demande l'état de la page à HapiditPay.

Chaque page HapiditPay est gardée dans la table `llx_hapiditpay_page`. Un même paiement n'est jamais enregistré deux fois, même si le webhook et le retour du client arrivent ensemble. Un paiement d'un montant ou d'une devise différente n'est pas enregistré : il apparaît « À vérifier » dans la configuration du module. Les références des pages (`dol-<installation>-fac-<id>-…`) sont propres à chaque Dolibarr : une boutique branchée sur le même compte HapiditPay n'est pas confondue.

Aucun secret dans ce dépôt : chaque utilisateur saisit sa propre clé d'API et le secret de son webhook, enregistrés chiffrés dans Dolibarr et jamais réaffichés.

## Installation

1. Téléchargez `module_hapiditpay-0.2.0.zip` depuis la [dernière version](https://github.com/HapiditPay/hapiditpay-dolibarr/releases/latest) et installez-le depuis Configuration → Modules/Applications → Déployer/Installer un module externe. Gardez ce nom de fichier : Dolibarr l'exige. Vous pouvez aussi copier le dossier `hapiditpay/` dans `htdocs/custom/`.
2. Activez le module HapiditPay (famille « Interfaces »).
3. Dans sa configuration :
   - **Adresse de l'API** : laissez vide pour `https://hapiditpay.com/api/v1` (sans redirection).
   - **Clé d'API** : `hdp_test_…` pour les essais, `hdp_…` pour encaisser.
   - **Secret du webhook** : déclarez dans votre espace HapiditPay (Développeurs → Webhooks) l'adresse indiquée sur la page, puis copiez son secret.
   - **Compte bancaire** : celui qui représente votre compte opérateur (Airtel Money, par exemple).

La devise principale de Dolibarr doit être celle de votre compte HapiditPay (FCFA, ariary, francs congolais). HapiditPay encaisse des montants entiers.

## Mise à jour depuis la version 0.1.x (ancien nom « HapiPay »)

Jusqu'à la 0.1.0, le module s'appelait HapiPay et vivait dans `htdocs/custom/hapipay/`. Il garde le même numéro (527400) ; les réglages et l'historique des paiements sont repris.

1. Choisissez un moment sans paiement en cours (un client parti payer revient sur l'ancienne adresse de retour).
2. Supprimez le dossier `htdocs/custom/hapipay/` sans désactiver l'ancien module : la désactivation ne perd rien, mais elle n'est pas nécessaire. Les deux dossiers ne doivent pas cohabiter, ils portent le même numéro.
3. Installez `module_hapiditpay-0.2.0.zip` (ou copiez le dossier `hapiditpay/`), puis activez le module HapiditPay. À l'activation :
   - les réglages `HAPIPAY_*` (adresse de l'API, clé et secret chiffrés, compte bancaire, mode de règlement) sont recopiés sous `HAPIDITPAY_*` pour chaque entité, puis effacés ; un réglage déjà fait sous le nouveau nom est gardé ;
   - les réglages d'activation de l'ancien module (`MAIN_MODULE_HAPIPAY*`) sont effacés ;
   - la table `llx_hapipay_page` devient `llx_hapiditpay_page`, avec ses index ;
   - le mode de règlement `HPAY` garde son code (les règlements déjà enregistrés s'y rapportent) et prend le nom « HapiditPay (mobile money) ».
4. L'adresse du webhook change : `custom/hapiditpay/public/webhook.php?e=…`. Dans votre espace HapiditPay (Développeurs → Webhooks), modifiez l'adresse du webhook existant pour celle affichée dans la configuration du module (son secret reste le même), ou créez-en un nouveau et copiez son secret dans le module.
5. Si vous aviez saisi l'ancienne adresse de l'API, remplacez-la par `https://hapiditpay.com/api/v1`, ou laissez le champ vide. Les clés `hpc_…` deviennent `hdp_…` : enregistrez la nouvelle clé que vous donne votre espace HapiditPay.

## Ce qui est payé

- Factures clients validées (le reste à payer, ou un montant plus petit si le lien en précise un).
- Dons.

Les commandes et les adhésions gardent leurs moyens de paiement habituels : HapiditPay ne s'y propose pas encore.

## Essais

Testé de bout en bout avec Dolibarr 22.0.5 et une instance HapiditPay locale, en mode test : paiement d'une facture et d'un don (règlement, facture et don classés payés, écriture bancaire), webhook signé reçu, faux webhook refusé (401), webhook rejoué sans double enregistrement, montant différent mis de côté sans toucher à la facture, référence d'une autre boutique ignorée, montant à centimes refusé avec un message, page de commande sans bouton HapiditPay. La version 0.2.0 (changement de nom) n'a pas encore été rejouée de bout en bout dans Dolibarr : seuls `tests/run.php`, la syntaxe PHP et la reprise des réglages et de la table (sur une base d'essai simulée) ont été vérifiés.

## Tests

`php tests/run.php` vérifie les signatures, les montants et l'adresse de l'API, sans Dolibarr. Avec `HAPIDITPAY_API_URL` et `HAPIDITPAY_API_KEY` (clé de test), il essaie aussi l'API réelle.

## Avant une publication sur DoliStore

Le numéro de module (527400) est provisoire : demander un numéro réservé à l'équipe Dolibarr.

## Versions

- **0.2.0** : le module HapiPay devient HapiditPay. Dossier `hapiditpay/`, réglages `HAPIDITPAY_*`, table `llx_hapiditpay_page`, en-tête de webhook `HapiditPay-Signature`, User-Agent `HapiditPay-Dolibarr/0.2.0`, clés d'API `hdp_…` et `hdp_test_…`, service sur `https://hapiditpay.com`. Les réglages, la table et le mode de règlement de la 0.1.x sont repris à l'activation (voir « Mise à jour depuis la version 0.1.x »).
- **0.1.0** : première version. Factures et dons ; Dolibarr 22.0.5.

## Licence

GPL-3.0-or-later.
