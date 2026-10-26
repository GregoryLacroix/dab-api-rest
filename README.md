# Les galets d’Ambre – API REST (Symfony)

API de la boutique React **Les galets d’Ambre**. Les JSON renvoyés ont **exactement la forme des données du store React** (`src/store/seed.js`) :
produits `{ id, name, categoryId, price, published, description, images[] }`, catégories `{ id, name }`,
commandes `{ id: "CMD-1001", date, status, total, customer: { name, email }, items[] }`, et `content` (accueil, slider, pied de page).

## Installation

Les photos d'exemple des produits sont dans `public/uploads/seed/` (servies par l'API, `app:seed` les référence avec `API_PUBLIC_URL`).

Prérequis : PHP ≥ 8.2 (extensions `ctype`, `iconv`, `pdo_sqlite` ou `pdo_mysql`/`pdo_pgsql`), Composer.

```bash
composer install
cp .env .env.local            # puis éditez .env.local (APP_SECRET, DATABASE_URL, MAILER_DSN…)
php bin/console doctrine:database:create
php bin/console doctrine:schema:create          # première installation (voir « Production » pour les migrations)
php bin/console app:seed --demo-admin           # données d'exemple + admin@dab.fr / galet2026 (DEV UNIQUEMENT)
php -S localhost:8000 -t public                 # ou : symfony server:start
```

Compte administrateur réel : `php bin/console app:create-admin vous@exemple.fr --name="Votre nom"`.

**E-mails en développement** : lancez [Mailpit](https://mailpit.axllent.org) (SMTP `127.0.0.1:1025`, boîte de réception sur http://localhost:8025). C'est la valeur de `MAILER_DSN` par défaut.

## Inscription avec vérification de l'e-mail

1. `POST /api/auth/register` crée le compte **non confirmé** et envoie un e-mail contenant un lien valable 24 h
   (le jeton est aléatoire ; seul son hash SHA-256 est stocké).
2. Le lien `GET /api/auth/verify-email?token=…` confirme le compte puis **redirige vers le React** :
   `FRONTEND_URL/connexion?verified=1` (ou `?verified=0&reason=expired|invalid`).
3. Tant que l'e-mail n'est pas confirmé, `POST /api/auth/login` répond **403** `{ "error": "email_not_verified" }`
   (uniquement si le mot de passe est correct, pour ne pas révéler quelles adresses sont inscrites).
4. `POST /api/auth/resend-verification { "email" }` renvoie un nouvel e-mail (réponse identique que le compte existe ou non, limitée à 3 demandes / 15 min).

Variante pour une page de confirmation gérée par le React : `POST /api/auth/verify-email { "token" }` → `200`, `400` (invalide) ou `410` (expiré).

## Authentification

`POST /api/auth/login { "email", "password" }` → `{ "token", "expiresAt", "user": { id, name, email, roles, isAdmin, verified } }`.
Envoyez ensuite `Authorization: Bearer <token>`. Le jeton dure 7 jours (`API_TOKEN_TTL_DAYS`), est stocké hashé et révoqué par `POST /api/auth/logout`.
Les routes marquées **Admin** exigent `ROLE_ADMIN` (`user.isAdmin` dans la réponse de connexion → garde du back-office React).
Connexion limitée à 5 essais / 15 min.

## Endpoints

| Méthode | Route | Accès | Rôle (écran React) |
|---|---|---|---|
| POST | `/api/auth/register` | public | Créer un compte : `{ name, email, password, confirm, consent: true }` |
| GET / POST | `/api/auth/verify-email` | public | Lien de confirmation (redirection) / variante JSON |
| POST | `/api/auth/resend-verification` | public | Renvoyer l'e-mail |
| POST | `/api/auth/login` | public | Connexion (site et back-office) |
| GET · POST | `/api/auth/me` · `/api/auth/logout` | connecté | Session |
| GET | `/api/auth/me/export` | connecté | Mon compte : copie de mes données (RGPD, accès et portabilité) |
| DELETE | `/api/auth/me` | connecté | Mon compte : supprimer mon compte `{ "password" }` (RGPD, effacement) |
| GET | `/api/products` | public* | Boutique, accueil, **scroll infini** : `?category=galets&q=…&page=1&perPage=4` |
| GET | `/api/products/{id}` | public* | Fiche produit (carrousel = `images[]`) |
| POST · PUT · PATCH · DELETE | `/api/products[/{id}]` | Admin | Produits (liste, création, modification, publication, suppression) |
| GET | `/api/categories` | public | Filtres de la boutique (`productCount` en plus) |
| POST · PUT/PATCH · DELETE | `/api/categories[/{id}]` | Admin | Catégories (suppression refusée en **409** si elle contient des produits) |
| POST | `/api/orders` | public | Panier « Commander » : `{ customer: { name, email }, items: [{ productId, qty }], acceptTerms: true }` → commande + `checkoutUrl` (page de paiement Stripe) |
| GET | `/api/payments/checkout/{sessionId}` | public (clé = id de session) | Page de retour du React : état du paiement `{ id, status, paid, refunded, total, customer.email }` |
| GET | `/api/auth/oauth/providers` | public | Fournisseurs de connexion configurés `{ providers: [{ id, label }] }` (les boutons du site en dépendent) |
| GET | `/api/auth/oauth/{google\|facebook}/start?lang=fr\|en` | public | Redirige le navigateur vers Google / Facebook |
| GET | `/api/auth/oauth/{google\|facebook}/callback` | fournisseur | Retour du fournisseur, puis redirection vers le React : `/connexion/retour#token=…` ou `/connexion?oauth=<raison>` |
| GET | `/api/auth/me/orders` | Connecté | Mes commandes (liées au compte ou passées avec mon adresse e-mail), sans les paiements abandonnés |
| GET | `/api/orders/{CMD-1001}/invoice` | Connecté (propriétaire ou admin) | Facture PDF d'une commande payée |
| POST | `/api/auth/forgot-password` | public | `{ email }` : envoie un lien de réinitialisation (réponse identique que le compte existe ou non) |
| POST | `/api/auth/reset-password` | public (jeton du lien) | `{ token, password, confirm }` : nouveau mot de passe, ferme toutes les sessions |
| PATCH | `/api/orders/{CMD-1001}/status` | Admin | `{ status, tracking?: { carrier, number, url? }, notify? }` : « expédiée » enregistre le suivi et prévient le client |
| POST | `/api/quotes` | public (limité : 5 / heure / IP) | Demande de devis, `multipart/form-data` : `name`, `email`, `phone`, `projectType` (interieur\|exterieur), `widthCm`, `heightCm`, `location`, `description`, `budget`, `desiredBy`, `consent`, `lang` + `photos[]` (4 × 8 Mo max) |
| GET | `/api/quotes/{clé}` | public (clé de 48 car.) | Page du devis du client : montant, acompte, état, `payable` |
| POST | `/api/quotes/{clé}/checkout` | public (clé) | Crée la page de paiement Stripe de l'acompte → `{ checkoutUrl }` |
| GET | `/api/quotes` | Admin | Liste des demandes (`?status=`, `?q=`, pagination) |
| PATCH | `/api/quotes/DEV-1001` | Admin | `{ status?, adminNote?, amount?, depositPercent?, quoteMessage? }` |
| POST | `/api/quotes/DEV-1001/send` | Admin | `{ amount, depositPercent, quoteMessage }` : envoie le devis par e-mail avec le lien de l'acompte |
| DELETE | `/api/quotes/DEV-1001` | Admin | Supprime la demande et ses photos |
| POST | `/api/payments/webhook` | Stripe (signature) | Confirmation de paiement, expiration, remboursement |
| POST | `/api/orders/{CMD-1001}/refund` | Admin | Remboursement total d'une commande payée en ligne |
| GET | `/api/orders` | Admin | Commandes : `?status=payée&q=martin&page=1&perPage=10` |
| GET · PATCH | `/api/orders/{CMD-1001}` · `/api/orders/{ref}/status` | Admin | Détail · changer le statut `{ "status": "expédiée" }` |
| GET | `/api/content` | public | Textes de l'accueil, de la page Contact et du pied de page |
| PUT/PATCH · POST | `/api/content` · `/api/content/reset` | Admin | Contenu du site · rétablir les textes d'origine |
| POST | `/api/contact` | public | Page Contact : `{ name, email, subject, message, consent, website }` (e-mail à la boutique + accusé au visiteur) |
| GET | `/api/messages` | Admin | Messages reçus : `?read=0&q=…&page=1&perPage=10` |
| GET · PATCH · DELETE | `/api/messages/{id}` | Admin | Détail · marquer lu/non lu `{ "read": true }` · supprimer |
| GET | `/api/slider` | public | `{ settings, products }` (produits déjà choisis selon le mode auto/manuel) |
| PUT/PATCH | `/api/slider` | Admin | Slider d'accueil `{ enabled, mode, count, delay, productIds }` |
| POST | `/api/uploads` | Admin | Photo (multipart, champ `file`) → `{ src, width, height }` ; la photo est optimisée (voir « Photos optimisées ») |
| GET | `/api/share/products/{id}?lang=fr\|en` | public (robots de partage) | Page d'aperçu (nom, photo, prix) d'une fiche produit, utilisée par WhatsApp, Facebook… |
| GET | `/api/admin/stats` | Admin | Tableau de bord |

\* Les visiteurs ne voient que les produits **publiés** ; un administrateur voit aussi les brouillons (`?published=0|1`).

Listes : `{ "data": [...], "meta": { "page", "perPage", "total", "pageCount" } }` (`perPage` de 1 à 100).
Les statuts de commande : `en attente`, `payée`, `expédiée`, `livrée`, `annulée`.

## Devis de fresque (demande, devis chiffré, acompte)
1. **Demande** : la page `/devis` du site (anglais `/en/quote`) collecte projet, dimensions, lieu, budget et jusqu'à 4 photos du mur. Elle est enregistrée (référence `DEV-1001`…), **vous recevez un e-mail** avec les détails et les liens des photos (répondre écrit directement au client), et le client reçoit un accusé de réception.
2. **Devis** : back-office → **Devis** : ouvrez la demande, saisissez le **montant TTC**, le **pourcentage d'acompte** (30 % par défaut, 0 = pas d'acompte en ligne) et un message, puis **Envoyer le devis au client**. Le client reçoit un e-mail avec le montant et un lien vers sa page de devis (`/devis/<clé>`, la clé de 48 caractères tient lieu de mot de passe).
3. **Acompte** : sur cette page, « Régler l'acompte » ouvre une page de paiement **Stripe** (31 minutes). Au paiement (webhook, avec vérification directe auprès de Stripe en secours), le statut passe à **acompte payé** et vous et le client recevez un e-mail. Une demande dont l'acompte est réglé ne peut plus être modifiée.
- **Prérequis** : Stripe configuré (voir « Paiement en ligne ») ; sans clé Stripe, mettez l'acompte à 0 %. Après mise à jour : `doctrine:schema:update --force` (nouvelle table `quote_request`) et `cache:clear`.
- **Photos** : enregistrées dans `public/uploads/quotes/` (optimisées si l'extension GD est présente). Leurs adresses sont aléatoires (non devinables) mais publiques. **PHP** doit accepter les envois : `upload_max_filesize` ≥ 8M et `post_max_size` ≥ 32M (réglage de l'hébergeur ; avec 2M par défaut, certaines photos seront refusées).
- **Données (RGPD)** : les demandes sans acompte sont supprimées avec leurs photos après 36 mois (`php bin/console app:purge-data`, option `--quotes-months`), incluses dans l'export du compte (même adresse e-mail) et supprimées avec le compte ; un acompte réglé est conservé (pièce comptable). La **facture d'acompte** n'est pas automatisée (Stripe envoie un reçu) : à établir selon votre comptabilité.

## Photos optimisées
À l'envoi (back-office → fiche produit), chaque photo JPEG / PNG / WebP est **optimisée** par l'API (extension PHP **GD**, présente chez presque tous les hébergeurs ; sans elle, la photo est conservée telle quelle) : orientation corrigée, métadonnées (dont la position GPS) supprimées, image principale réduite à 1600 px au format **WebP**, versions 480 / 800 / 1200 px (`<nom>-480.webp`…) et une version JPEG `<nom>-share.jpg` pour les aperçus de partage. Le site choisit tout seul la version la plus légère adaptée à l'écran (`srcset`). Les GIF sont conservés tels quels. Dans le navigateur, les grosses photos de téléphone sont d'abord réduites à 2400 px (envoi plus rapide ; limite de 30 Mo à l'origine, 5 Mo après réduction).
**Photos déjà en ligne** (envoyées avant cette version, ou photos d'exemple) :
```bash
php bin/console app:optimize-images --dry-run   # liste ce qui serait fait
php bin/console app:optimize-images             # crée les versions légères et met à jour les produits
```
Les fichiers d'origine ne sont pas supprimés. Prévoyez de la mémoire PHP (256 Mo conseillés pour de grosses images).

## Aperçus de partage (WhatsApp, Facebook, iMessage, X, LinkedIn…)
Ces robots ne lancent pas le JavaScript du site : ils ne voient que les balises génériques. Le `.htaccess` du site les redirige (eux seulement, d'après leur identifiant) pour une fiche produit vers `GET /api/share/products/{id}`, une page minimale avec le nom, la photo (JPEG de partage) et le prix. Un nouveau produit a donc un bel aperçu **tout de suite, sans nouveau build**. Test : `curl -sIA "facebookexternalhit/1.1" https://votre-site/produits/1` doit répondre `302` vers l'API ; `curl -s https://votre-api/api/share/products/1` montre les balises `og:`. Le site doit déclarer l'adresse de l'API dans son `.htaccess` (2 lignes à adapter si elle change). Pour forcer Facebook à relire un aperçu : [developers.facebook.com/tools/debug](https://developers.facebook.com/tools/debug/).

## Stock des pièces uniques
Chaque produit a une **quantité en stock** (champ « Quantité en stock » du back-office ; 1 par défaut, 0 = « Vendu »). Une commande **réserve** les pièces dès sa création (même en attente de paiement) par une requête atomique : deux clients ne peuvent jamais acheter la même pièce, le second reçoit « n'est plus disponible » (409). La réservation est rendue si le paiement échoue ou expire (31 min), si la commande est annulée avant envoi (back-office) ou remboursée avant envoi ; jamais en double. Sur le site, une pièce vendue affiche « Vendu » et ne peut plus être ajoutée au panier. Après mise à jour : `doctrine:schema:update --force` — les produits existants passent à `stock = 1` et les commandes existantes sont considérées comme « sans réservation ».

## Mot de passe oublié
Lien « Mot de passe oublié ? » sur la page de connexion → e-mail avec un lien **valable 1 heure, à usage unique** (seul le hash du jeton est en base, le jeton est dans le fragment `#` de l'adresse) → nouveau mot de passe (8 caractères minimum). Toutes les sessions ouvertes sont fermées ; un compte non confirmé est confirmé (la personne a prouvé qu'elle lit la boîte) ; un compte créé avec Google obtient ainsi un mot de passe. Limité à 8 demandes / 15 min par IP + e-mail. Nécessite un **envoi d'e-mails fonctionnel** (`MAILER_DSN`).

## Suivi d'expédition
Dans le back-office, choisir le statut « expédiée » ouvre une fenêtre : transporteur (Colissimo, Chronopost, Mondial Relay, DHL, UPS, autre), numéro de suivi, lien facultatif (sinon le lien habituel du transporteur est utilisé : `App\Service\TrackingLinks`, à vérifier avec un vrai numéro), et case « Prévenir le client par e-mail ». Le client reçoit l'e-mail « Votre commande est expédiée » et retrouve le suivi dans *Mon compte*. Le bouton « Suivi / renvoyer l'e-mail » permet de corriger un numéro. Un e-mail de **remboursement** part aussi au client à chaque remboursement.

## Mise à jour de la base (stock, mot de passe oublié, suivi)
`php bin/console doctrine:schema:update --force --env=prod` ajoute : `product.stock` ; `shop_order.stock_released`, `carrier`, `tracking_number`, `tracking_url`, `shipped_at` ; `app_user.reset_hash`, `reset_expires_at`. Puis `php bin/console cache:clear --env=prod`.

## Zoom des photos (réglage)
Le contenu du site (`GET/PUT /api/content`) contient un bloc `zoom` : `{ "lens": true, "lensLevel": 2.5, "fullLevel": 2.5 }` (loupe à la souris on/off, grossissement de la loupe et grossissement en plein écran, de 1,5 à 5). Il se règle dans le back-office, page *Contenu du site*. Un contenu enregistré avant cette version reçoit automatiquement ces valeurs par défaut.

## Factures (PDF)

Chaque commande **payée** (statut payée, expédiée ou livrée, non remboursée) a une facture PDF, téléchargeable depuis *Mon compte* (côté client) et depuis la page *Commandes* du back-office (administrateur). Elle est générée à la demande avec la bibliothèque FPDF (`composer install`, extension PHP `mbstring`) : rien n'est stocké.
- **Numérotation** : `FA-2026-0001`, continue sans trou, une série par année. Le numéro est attribué à la confirmation du paiement Stripe (jamais pour une commande abandonnée) ; pour une commande passée « payée » à la main, au premier téléchargement. Après mise à jour : `php bin/console doctrine:schema:update --force` (colonnes `invoice_number`, `invoiced_at`).
- **Contenu** : le vendeur vient de *Contenu du site → Informations légales* du back-office (raison sociale, forme juridique, SIRET, adresse, e-mail, téléphone, mention de TVA). **Remplissez-les** : un champ vide s'imprime « [à compléter] ». Les prix étant TTC, la facture affiche le total TTC et votre mention sur la TVA (par exemple « TVA non applicable, art. 293 B du CGI » si vous êtes en franchise en base). Les frais de port éventuels apparaissent sur une ligne distincte.
- **Sécurité** : une personne ne peut télécharger que ses propres factures (compte lié ou même adresse e-mail vérifiée) ; pour toute autre commande l'API répond « introuvable ».
- **Limites** : facture en français uniquement ; un remboursement ne génère pas encore d'avoir (la facture d'une commande remboursée n'est plus proposée) ; ce modèle est à faire valider par votre comptable (mentions obligatoires, TVA).

## Connexion avec Google / Facebook

Les pages Connexion et Inscription du React affichent « Continuer avec Google » / « … Facebook » **pour chaque fournisseur dont les clés sont renseignées** (sans clé : aucun bouton, rien ne change). Le site ne voit jamais le mot de passe du compte Google/Facebook : il reçoit un identifiant stable, le nom et l'adresse e-mail.

**Déroulé** (OAuth 2.0 « code d'autorisation », avec PKCE pour Google) : le bouton envoie le navigateur sur `GET /api/auth/oauth/google/start` → page Google → `GET /api/auth/oauth/google/callback` → l'API échange le code contre le profil, trouve ou crée le compte, émet le même jeton qu'une connexion par mot de passe et renvoie au React sur `/connexion/retour#token=…` (le jeton est dans le *fragment* de l'adresse, jamais envoyé à un serveur ; le React l'efface aussitôt). Pas de session côté API : un cookie signé `dab_oauth` de 10 minutes mémorise l'aller-retour.

**Règles de rattachement aux comptes** (ce qui protège contre le vol de compte)
- Identité déjà connue → on connecte le compte correspondant.
- E-mail déjà inscrit : rattachement **seulement avec Google** (qui garantit l'adresse, `email_verified`). Si le compte existant n'avait jamais été confirmé, son mot de passe est supprimé (quelqu'un a pu l'ouvrir avant le vrai propriétaire). Avec Facebook (adresse non garantie) : refus `account_exists`, on invite à se connecter avec le mot de passe.
- Nouvel e-mail : compte créé **sans mot de passe** ; confirmé d'emblée avec Google, sinon (Facebook) l'e-mail de confirmation habituel est envoyé avant la première connexion.
- Un compte sans mot de passe supprime son compte en saisissant son adresse e-mail (au lieu du mot de passe). Il n'y a pas encore de « mot de passe oublié » dans cette version.

**Mise en route Google** ([console.cloud.google.com](https://console.cloud.google.com)) :
1. Créez (ou choisissez) un projet, puis ouvrez **Google Auth Platform**. *Branding* (ou « Commencer ») : nom de l'application, e-mail d'assistance, public **Externe**, e-mail de contact. Dans *Branding*, ajoutez le logo, la page d'accueil, les liens vers la politique de confidentialité et les CGV, et votre domaine dans « Domaines autorisés ».
2. *Accès aux données* : les portées `openid`, `email` et `profile` suffisent (ce sont les plus simples, elles ne demandent pas de validation approfondie).
3. *Audience* : tant que l'application est en « Test », seuls les comptes listés comme testeurs peuvent se connecter. Cliquez sur **Publier l'application** pour l'ouvrir à tout le monde.
4. *Clients* → **Créer un client** → type **Application Web**. Dans **URI de redirection autorisés**, ajoutez `API_PUBLIC_URL/api/auth/oauth/google/callback` (et `http://localhost:8000/api/auth/oauth/google/callback` pour tester en local). Ne remplissez pas « Origines JavaScript autorisées ».
5. Copiez l'**ID client** et le **code secret du client** dans `.env.local` : `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`. En cas d'erreur `redirect_uri_mismatch`, l'adresse ne correspond pas au caractère près (ou n'est pas encore active : patientez quelques minutes).

**Mise en route Facebook** ([developers.facebook.com](https://developers.facebook.com)) :
1. *Mes apps* → **Créer une app** → cas d'usage **« S'authentifier et demander des données aux utilisateurs avec Facebook Login »** → nom de l'app et e-mail de contact.
2. *Cas d'usage* → **Personnaliser** → onglet *Paramètres* : dans **URI de redirection OAuth valides**, ajoutez `API_PUBLIC_URL/api/auth/oauth/facebook/callback` ; « Connexion OAuth client » et « Connexion OAuth Web » sur Oui. Dans *Autorisations et fonctionnalités*, vérifiez la présence de `email` et `public_profile`.
3. *Paramètres de l'app* → *Général* : copiez l'**ID de l'app** et le **Secret de l'app** (bouton « Afficher ») dans `FACEBOOK_APP_ID` et `FACEBOOK_APP_SECRET`. Renseignez aussi l'URL de la politique de confidentialité, l'icône, la catégorie, et l'adresse expliquant comment supprimer ses données (vous pouvez indiquer la page de politique de confidentialité).
4. Tant que l'app est en mode **Développement**, seules les personnes ayant un rôle sur l'app peuvent se connecter : passez-la en mode **Live** (interrupteur en haut de la page) quand tout fonctionne.

**Ajouter un autre fournisseur** (Microsoft, Apple…) : une entrée dans `OAuthService::__construct` (adresses d'autorisation, de jeton et de profil, portée, `trustEmail`), le nom ajouté à `OAuthService::PROVIDERS` et à la contrainte de route de `OAuthController`, et un bouton/logo dans `SocialLogin.jsx` côté React. Apple demande en plus un secret signé (JWT) : non géré ici.

## Paiement en ligne (Stripe)

Paiement par **Stripe Checkout** : le client est redirigé vers une page de paiement hébergée par Stripe (cartes, Apple Pay / Google Pay, et les moyens que vous activez dans votre tableau de bord). **Aucune donnée bancaire ne passe par ce site ni par l'API.**

**Déroulé**
1. Le React envoie `POST /api/orders` ; l'API recalcule les prix, enregistre la commande **« en attente »**, crée la session Stripe et renvoie `checkoutUrl`. Le React y redirige le navigateur.
2. Le client paie chez Stripe (adresse de livraison demandée par Stripe, dans les pays de `STRIPE_SHIPPING_COUNTRIES`).
3. Stripe appelle `POST /api/payments/webhook` (signature vérifiée) : la commande passe à **« payée »**, l'adresse de livraison et le total encaissé sont enregistrés, le client reçoit l'e-mail de confirmation et **la boutique reçoit un e-mail « Nouvelle commande payée »**.
4. Le client revient sur `FRONTEND_URL/commande?session_id=…` (anglais : `/en/order`) ; le React interroge `GET /api/payments/checkout/{sessionId}`. Si le webhook n'est pas encore arrivé, l'API interroge directement Stripe : la page de confirmation est donc juste même sans webhook (utile en local).
5. Si le client abandonne ou ferme l'onglet : la page de paiement expire au bout de 31 minutes, Stripe envoie `checkout.session.expired` et la commande passe à **« annulée »**.

**Mise en route**
```bash
composer install                                   # installe stripe/stripe-php
php bin/console doctrine:schema:update --force     # installation existante : ajoute les colonnes de paiement (en production : make:migration puis migrate)
```
Dans `.env.local` : `STRIPE_SECRET_KEY=sk_test_…` (Stripe > Développeurs > Clés API), `STRIPE_WEBHOOK_SECRET=whsec_…`, et `STRIPE_SHIPPING_COUNTRIES` (ex. `FR,BE,LU`).
- **En local** : `stripe listen --forward-to localhost:8000/api/payments/webhook` (Stripe CLI) affiche le secret `whsec_…`. Carte de test : `4242 4242 4242 4242`, date future, CVC quelconque.
- **En production** : Stripe > Développeurs > Webhooks > ajouter `https://votre-api/api/payments/webhook` avec les événements `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `charge.refunded`. Clé `sk_live_…`, et activez votre compte Stripe (informations légales et bancaires).
- **Frais de port** (facultatif) : créez un tarif d'expédition dans Stripe (Produits > Tarifs d'expédition) et mettez son identifiant `shr_…` dans `STRIPE_SHIPPING_RATE_ID`. Sans lui, aucun frais n'est ajouté.
- **Sans clé Stripe** (`STRIPE_SECRET_KEY` vide) : le paiement en ligne est désactivé et les commandes s'enregistrent comme avant (statut « en attente », e-mail de confirmation immédiat).
- **Filet de sécurité** (recommandé) : `*/15 * * * *  php /chemin/dab-api/bin/console app:sync-payments` rattrape un webhook manqué.

**Remboursement** : bouton « Rembourser » dans le back-office (ou `POST /api/orders/{ref}/refund`) : remboursement total chez Stripe puis statut « annulée ». Un remboursement fait depuis le tableau de bord Stripe est aussi pris en compte (`charge.refunded`). Les remboursements partiels ne changent pas le statut.

**À savoir**
- Les montants viennent toujours de la base, jamais du navigateur ; la signature du webhook prouve que l'appel vient de Stripe ; les traitements sont idempotents (webhook rejoué = sans effet).
- Montant minimum d'une commande payée en ligne : 0,50 € (règle Stripe).
- Chaque galet est une pièce unique mais le stock n'est pas géré : deux clients peuvent payer le même produit en même temps.
- Chiffre d'affaires du tableau de bord : seules les commandes payées comptent (les commandes en ligne « en attente » et les annulées sont exclues).
- Les e-mails restent en français (la page de paiement Stripe suit, elle, la langue du site).

## Erreurs

Toujours du JSON : `{ "error": "code", "message": "…" }`. Les erreurs de formulaire (**422**) donnent `violations` par champ,
avec **les mêmes messages que le React** :

```json
{ "error": "validation_failed", "message": "Certains champs sont invalides.",
  "violations": { "email": "Saisissez une adresse e-mail valide.", "password": "Le mot de passe doit contenir 8 caractères minimum." } }
```
Clés : `name`, `email`, `password`, `confirm` (inscription) · `name`, `email`, `subject`, `message`, `consent` (contact) · `customer.name`, `customer.email`, `items.0.qty` (commande) · `name`, `categoryId`, `price`, `images.0.src` (produit).

## Sécurité : ce qui est en place

- Mots de passe hashés (`auto` : bcrypt/argon2), jetons d'accès et de confirmation stockés **hashés**.
- Prix et total d'une commande **recalculés côté serveur** (le client n'envoie que `productId` et `qty`).
- Limites anti-abus : inscription (5 / h), renvoi d'e-mail (3 / 15 min), commandes (20 / h), contact (5 / h), connexion (5 / 15 min). Le formulaire de contact a en plus un champ piège anti-robots (`website`) et neutralise les retours à la ligne dans le nom (pas d'injection d'en-têtes d'e-mail).
- Connexion Google/Facebook : paramètre `state` vérifié (cookie signé), PKCE, jeton transmis dans le fragment de l'adresse, rattachement à un compte existant uniquement si l'adresse est garantie par le fournisseur.
- Paiement : webhook Stripe protégé par signature, montants recalculés côté serveur, passage « payée » atomique (un seul e-mail même si le webhook et la page de retour arrivent ensemble).
- CORS limité à `CORS_ALLOW_ORIGIN`, droits admin vérifiés route par route (`#[IsGranted]`), uploads contrôlés sur le contenu réel du fichier (5 Mo, JPEG/PNG/WebP/GIF).

## Brancher le React

1. Copiez `docs/api-client.js` dans le React (`src/services/api.js`) et ajoutez `VITE_API_URL=http://localhost:8000` à son `.env`.
2. Remplacez le contenu des `actions` et des hooks de `src/store/store.js` par des appels à `api.*` (mêmes noms : produits, catégories, commandes, contenu, slider).
3. Page **Créer un compte** : afficher `message` après `api.register`. Page **Connexion** : afficher « E-mail confirmé » si l'URL contient `?verified=1`, et proposer « Renvoyer l'e-mail » sur l'erreur `email_not_verified`.
4. Back-office : la garde `isAdmin()` devient `user.isAdmin` ; les photos passent par `api.upload(file)` plutôt que par des images en base64.

## RGPD

- **Acceptation prouvée côté serveur** : `consent` est obligatoire à l'inscription (politique de confidentialité + CGV) et `acceptTerms` à la commande ; sans eux, l'API répond 422.
- **Droit d'accès / portabilité** : `GET /api/auth/me/export` renvoie compte, commandes et messages de la personne.
- **Droit à l'effacement** : `DELETE /api/auth/me` (confirmé par le mot de passe) supprime le compte, ses jetons et ses messages. Les commandes sont conservées 10 ans (obligation comptable) mais détachées du compte. Un compte administrateur ne peut pas être supprimé ainsi.
- **Durées de conservation** (identiques à la politique de confidentialité du site) : `php bin/console app:purge-data` supprime les comptes non confirmés depuis plus de 30 jours, les messages de contact de plus de 12 mois et les jetons expirés (`--dry-run` pour simuler). À planifier chaque nuit : `0 3 * * * php /chemin/dab-api/bin/console app:purge-data`.
- **Informations légales** : `content.legal` (raison sociale, SIRET, hébergeur, médiateur…) se renseigne dans le back-office et alimente les pages légales du site.
- Pensez à lister votre hébergeur et votre service d'envoi d'e-mails dans la politique de confidentialité.

## Production

**Dépannage en production**
- *Console du navigateur : « l'en-tête CORS Access-Control-Allow-Origin est manquant »* (même avec un code 200) : l'API ne connaît pas l'adresse du site, donc `CORS_ALLOW_ORIGIN` n'a pas la valeur de production. Le plus souvent `.env.local` n'existe pas sur le serveur, ou `.env.prod` n'a pas été envoyé (fichier caché). Vérifiez avec `php bin/console debug:dotenv CORS_ALLOW_ORIGIN` : la valeur affichée doit être `^https://dab\.gregory-lacroix-pf\.com$`. Sinon complétez `.env.local` (modèle : `.env.local.prod.example`), puis `php bin/console cache:clear --env=prod`.
- Test sans navigateur : `curl -i -H "Origin: https://dab.gregory-lacroix-pf.com" https://dab.api.gregory-lacroix-pf.com/api/auth/oauth/providers` doit renvoyer une ligne `access-control-allow-origin: https://dab.gregory-lacroix-pf.com`.
- *Erreur 500 sur `/api/products`, `/api/slider`, `/api/content` alors que `/api/auth/oauth/providers` répond 200* : ces routes lisent la base de données, `oauth/providers` non. La base est donc injoignable ou vide : vérifiez `DATABASE_URL`, créez les tables (`php bin/console doctrine:schema:update --force --env=prod`) et regardez `var/log/prod.log` (le dossier `var/` doit être accessible en écriture).

- *Erreur 500 sur TOUTES les routes (page HTML grise en `iso-8859-1`, signée LiteSpeed)* : c'est le serveur, pas Symfony : une ligne du `public/.htaccess` est refusée par l'hébergeur (par exemple `SecRuleEngine Off`, interdit sur PlanetHoster). Retirez la dernière ligne ajoutée, ou renommez temporairement `.htaccess` pour confirmer.
- *403 « Forbidden » au retour de Google* (`…/google/callback?…&iss=https://…`) : pare-feu du serveur (ModSecurity) qui prend les adresses web des paramètres pour une attaque. `SecRuleEngine Off` dans le `.htaccess` est refusé par PlanetHoster : il faut demander au support une exception pour le chemin `/api/auth/oauth/`.

**Adresses de ce déploiement** : site `https://dab.gregory-lacroix-pf.com`, API `https://dab.api.gregory-lacroix-pf.com`.
- `.env.prod` (fourni) contient `FRONTEND_URL`, `API_PUBLIC_URL` et `CORS_ALLOW_ORIGIN` ; `.env.local.prod.example` est le modèle du fichier **`.env.local` à créer sur le serveur** (`APP_ENV=prod`, `APP_SECRET`, base de données, e-mails, clés Stripe et Google/Facebook). Ce `.env.local` ne doit jamais être publié.
- À déclarer chez les services externes :
  - Stripe → Webhooks : `https://dab.api.gregory-lacroix-pf.com/api/payments/webhook`
  - Google → URI de redirection : `https://dab.api.gregory-lacroix-pf.com/api/auth/oauth/google/callback` ; domaine autorisé : `gregory-lacroix-pf.com` ; accueil `https://dab.gregory-lacroix-pf.com`, confidentialité `https://dab.gregory-lacroix-pf.com/politique-de-confidentialite`, CGV `https://dab.gregory-lacroix-pf.com/conditions-generales-de-vente`
  - Facebook → URI de redirection OAuth valide : `https://dab.api.gregory-lacroix-pf.com/api/auth/oauth/facebook/callback`

- Hébergement Apache / LiteSpeed (PlanetHoster…) : le sous-domaine de l'API doit pointer sur le dossier `public/` ; `public/.htaccess` (fourni) envoie les adresses `/api/...` à Symfony **et** remet l'en-tête `Authorization` que certains hébergeurs effacent (sans lui : connexion en 401).

- Connexion Google/Facebook : les clés dans l'environnement du serveur ; l'URI de redirection déclarée chez le fournisseur doit être en HTTPS et identique à `API_PUBLIC_URL/api/auth/oauth/<fournisseur>/callback`. Mettez `API_PUBLIC_URL` en `https://…` (le cookie `dab_oauth` est alors marqué « Secure »).
- Paiement : `STRIPE_SECRET_KEY` (`sk_live_…`) et `STRIPE_WEBHOOK_SECRET` dans l'environnement du serveur, jamais dans le dépôt. Le webhook doit être joignable en HTTPS depuis Internet (sans CORS ni jeton : la signature suffit).

- `APP_ENV=prod`, `APP_DEBUG=0`, un vrai `APP_SECRET`, HTTPS, `API_PUBLIC_URL`/`FRONTEND_URL`/`CORS_ALLOW_ORIGIN` réels, un vrai `MAILER_DSN` (Brevo, Mailjet, SES…).
- Migrations plutôt que `schema:create` : `php bin/console make:migration` puis `php bin/console doctrine:migrations:migrate` (installez les dépendances de dev pour la génération).
- Derrière un proxy/CDN : déclarez-le (`framework.trusted_proxies`) pour que les limites par IP voient la vraie adresse.
- Servez `public/uploads/` en fichiers statiques et sauvegardez-le avec la base.
- Pas de réinitialisation de mot de passe dans cette version.
