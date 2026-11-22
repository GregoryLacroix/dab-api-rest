-- À exécuter UNE FOIS dans phpMyAdmin (base de l'API), onglet SQL.
-- Pas de clés étrangères (la base de l'hébergeur les refuse parfois).

-- 1) Frais de port : coût et pays de livraison enregistrés sur chaque commande
ALTER TABLE shop_order
  ADD COLUMN shipping_cents INT NOT NULL DEFAULT 0,
  ADD COLUMN shipping_country VARCHAR(2) DEFAULT NULL;

-- 2) Alertes « prévenez-moi » (retour en stock)
CREATE TABLE stock_alert (
  id INT AUTO_INCREMENT NOT NULL,
  product_id INT NOT NULL,
  email VARCHAR(180) NOT NULL,
  lang VARCHAR(2) NOT NULL,
  created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  UNIQUE INDEX uniq_stock_alert (product_id, email),
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- 3) FAQ
CREATE TABLE faq (
  id INT AUTO_INCREMENT NOT NULL,
  question VARCHAR(200) NOT NULL,
  question_en VARCHAR(200) DEFAULT NULL,
  answer LONGTEXT NOT NULL,
  answer_en LONGTEXT DEFAULT NULL,
  position INT NOT NULL,
  published TINYINT(1) NOT NULL,
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- Questions de départ (à relire et adapter dans le back-office, page « FAQ » : délais, tarifs et règles sont des exemples)
INSERT INTO faq (question, question_en, answer, answer_en, position, published) VALUES
('Les galets sont-ils peints à la main ?', 'Are the pebbles hand-painted?',
 'Oui, chaque galet et chaque décoration murale est peint à la main : deux pièces ne sont jamais identiques.',
 'Yes, every pebble and wall decoration is hand-painted: no two pieces are ever identical.', 1, 1),
('Comment sont protégées les pièces peintes ?', 'How are the painted pieces protected?',
 'Les galets sont vernis pour résister à l’humidité et aux petits chocs. Pour l’extérieur, évitez tout de même une exposition permanente à la pluie et au plein soleil.',
 'The pebbles are varnished to resist moisture and minor knocks. Outdoors, still avoid permanent exposure to rain and full sun.', 2, 1),
('Quels sont les délais et les frais de livraison ?', 'What are the delivery times and costs?',
 'Les commandes sont préparées sous quelques jours ouvrés. Les frais de port dépendent du poids du colis et du pays de livraison : ils s’affichent dans le panier avant le paiement.',
 'Orders are prepared within a few working days. Shipping costs depend on the parcel weight and the delivery country: they are shown in the cart before payment.', 3, 1),
('Comment suivre ma commande ?', 'How can I track my order?',
 'Dès l’expédition, vous recevez un e-mail avec le numéro de suivi. Vous pouvez aussi utiliser la page « Suivre ma commande » avec votre numéro de commande et votre e-mail.',
 'As soon as it ships you receive an e-mail with the tracking number. You can also use the “Track my order” page with your order number and e-mail.', 4, 1),
('Puis-je faire peindre une fresque sur mesure ?', 'Can I order a custom mural?',
 'Oui : décrivez votre projet via la page « Demande de devis ». Je vous réponds avec un devis personnalisé ; un acompte confirme la réservation.',
 'Yes: describe your project on the “Request a quote” page. I will reply with a personalised quote; a deposit confirms the booking.', 5, 1),
('Puis-je retourner un article ?', 'Can I return an item?',
 'Vous disposez de 14 jours après réception pour exercer votre droit de rétractation (hors pièces personnalisées). Les conditions détaillées figurent dans les conditions générales de vente.',
 'You have 14 days after receipt to withdraw (except personalised pieces). Full conditions are in the terms and conditions.', 6, 1),
('Comment fonctionne la carte cadeau ?', 'How does the gift card work?',
 'Choisissez un montant, payez en ligne : le code est envoyé par e-mail. Il se saisit dans le panier, est valable un an et reste utilisable jusqu’à épuisement du solde.',
 'Pick an amount and pay online: the code is sent by e-mail. Enter it in the cart; it is valid for one year and can be used until the balance runs out.', 7, 1),
('Un produit est épuisé, vais-je en retrouver ?', 'A product is sold out, will it come back?',
 'Beaucoup de pièces sont uniques. Sur la fiche d’un produit épuisé, laissez votre e-mail : vous serez prévenu(e) s’il revient. Vous pouvez aussi demander une pièce similaire via le formulaire de contact.',
 'Many pieces are unique. On a sold-out product page, leave your e-mail and you will be notified if it returns. You can also ask for a similar piece through the contact form.', 8, 1);
