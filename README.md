# HapiPay pour Dolibarr

Module Dolibarr (19 et plus récent, testé avec la 22) pour faire payer les factures et les dons par mobile money, carte bancaire et PayPal avec HapiPay. L'argent va directement du client ou du donateur au compte opérateur de l'entreprise ou de l'association : HapiPay ne le touche jamais.

## Fonctionnement

1. Une fois la clé enregistrée, HapiPay devient un moyen de paiement en ligne de Dolibarr. Le lien de paiement s'affiche sur les factures et les dons validés, dans les e-mails et sur les PDF, comme avec Stripe ou PayPal.
2. Sur la page publique de paiement de Dolibarr, le client clique sur « Payer avec HapiPay ». Le module crée une page de paiement HapiPay à usage unique (`POST /api/v1/liens`) avec le reste à payer, la devise et une adresse de retour, puis y envoie le client. Si HapiPay ne répond pas, rien n'est créé et le client voit un message.
3. Le client paie sur la page HapiPay, puis revient sur Dolibarr.
4. HapiPay envoie le webhook signé `paiement.reussi` à `custom/hapipay/public/webhook.php`. Le module vérifie la signature, le montant et la devise, puis enregistre le règlement (mode « HapiPay (mobile money) », numéro de transaction HapiPay) : la facture ou le don passe à « payé », et l'écriture est inscrite sur le compte bancaire choisi.
5. Si le webhook n'est pas encore arrivé au retour du client, le module demande l'état de la page à HapiPay.

Chaque page HapiPay est gardée dans la table `llx_hapipay_page`. Un même paiement n'est jamais enregistré deux fois, même si le webhook et le retour du client arrivent ensemble. Un paiement d'un montant ou d'une devise différente n'est pas enregistré : il apparaît « À vérifier » dans la configuration du module. Les références des pages (`dol-<installation>-fac-<id>-…`) sont propres à chaque Dolibarr : une boutique branchée sur le même compte HapiPay n'est pas confondue.

Aucun secret dans ce dépôt : chaque utilisateur saisit sa propre clé d'API et le secret de son webhook, enregistrés chiffrés dans Dolibarr et jamais réaffichés.

## Installation

1. Téléchargez `module_hapipay-0.1.0.zip` depuis la [dernière version](https://github.com/HapiPay/hapipay-dolibarr/releases/latest) et installez-le depuis Configuration → Modules/Applications → Déployer/Installer un module externe. Gardez ce nom de fichier : Dolibarr l'exige. Vous pouvez aussi copier le dossier `hapipay/` dans `htdocs/custom/`.
2. Activez le module HapiPay (famille « Interfaces »).
3. Dans sa configuration :
   - **Adresse de l'API** : laissez vide pour `https://pay.scigroup.td/api/v1` (sans redirection).
   - **Clé d'API** : `hpc_test_…` pour les essais, `hpc_…` pour encaisser.
   - **Secret du webhook** : déclarez dans votre espace HapiPay (Développeurs → Webhooks) l'adresse indiquée sur la page, puis copiez son secret.
   - **Compte bancaire** : celui qui représente votre compte opérateur (Airtel Money, par exemple).

La devise principale de Dolibarr doit être celle de votre compte HapiPay (FCFA, ariary, francs congolais). HapiPay encaisse des montants entiers.

## Ce qui est payé

- Factures clients validées (le reste à payer, ou un montant plus petit si le lien en précise un).
- Dons.

Les commandes et les adhésions gardent leurs moyens de paiement habituels : HapiPay ne s'y propose pas encore.

## Essais

Testé de bout en bout avec Dolibarr 22.0.5 et une instance HapiPay locale, en mode test : paiement d'une facture et d'un don (règlement, facture et don classés payés, écriture bancaire), webhook signé reçu, faux webhook refusé (401), webhook rejoué sans double enregistrement, montant différent mis de côté sans toucher à la facture, référence d'une autre boutique ignorée, montant à centimes refusé avec un message, page de commande sans bouton HapiPay.

## Tests

`php tests/run.php` vérifie les signatures, les montants et l'adresse de l'API, sans Dolibarr. Avec `HAPIPAY_API_URL` et `HAPIPAY_API_KEY` (clé de test), il essaie aussi l'API réelle.

## Avant une publication sur DoliStore

Le numéro de module (527400) est provisoire : demander un numéro réservé à l'équipe Dolibarr.

## Versions

- **0.1.0** : première version. Factures et dons ; Dolibarr 22.0.5.

## Licence

GPL-3.0-or-later.
