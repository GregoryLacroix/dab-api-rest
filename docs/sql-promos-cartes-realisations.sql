-- À exécuter UNE FOIS dans phpMyAdmin (base de l'API), onglet SQL.
-- Pas de clés étrangères : la base de l'hébergeur les refuse parfois (errno 150), elles ne sont pas nécessaires ici.

-- 1) Commandes : remises (code promo, carte cadeau)
ALTER TABLE shop_order
  ADD COLUMN promo_code VARCHAR(40) DEFAULT NULL,
  ADD COLUMN promo_cents INT NOT NULL DEFAULT 0,
  ADD COLUMN gift_code VARCHAR(24) DEFAULT NULL,
  ADD COLUMN gift_cents INT NOT NULL DEFAULT 0,
  ADD COLUMN discount_released TINYINT(1) NOT NULL DEFAULT 0;

-- 2) Codes promo
CREATE TABLE promo_code (
  id INT AUTO_INCREMENT NOT NULL,
  code VARCHAR(40) NOT NULL,
  kind VARCHAR(10) NOT NULL,
  value INT NOT NULL,
  min_order_cents INT NOT NULL,
  starts_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  max_uses INT DEFAULT NULL,
  used_count INT NOT NULL,
  one_per_customer TINYINT(1) NOT NULL,
  active TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  UNIQUE INDEX UNIQ_PROMO_CODE (code),
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- 3) Cartes cadeau
CREATE TABLE gift_card (
  id INT AUTO_INCREMENT NOT NULL,
  code VARCHAR(24) NOT NULL,
  initial_cents INT NOT NULL,
  balance_cents INT NOT NULL,
  status VARCHAR(12) NOT NULL,
  buyer_name VARCHAR(120) NOT NULL,
  buyer_email VARCHAR(180) NOT NULL,
  recipient_name VARCHAR(120) DEFAULT NULL,
  recipient_email VARCHAR(180) DEFAULT NULL,
  message LONGTEXT DEFAULT NULL,
  lang VARCHAR(2) NOT NULL,
  stripe_session_id VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  UNIQUE INDEX UNIQ_GIFT_CODE (code),
  UNIQUE INDEX UNIQ_GIFT_SESSION (stripe_session_id),
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;

-- 4) Réalisations (galerie)
CREATE TABLE realisation (
  id INT AUTO_INCREMENT NOT NULL,
  title VARCHAR(160) NOT NULL,
  title_en VARCHAR(160) DEFAULT NULL,
  location VARCHAR(160) DEFAULT NULL,
  tag VARCHAR(60) DEFAULT NULL,
  tag_en VARCHAR(60) DEFAULT NULL,
  description LONGTEXT DEFAULT NULL,
  description_en LONGTEXT DEFAULT NULL,
  images JSON NOT NULL,
  before_image VARCHAR(500) DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  published TINYINT(1) NOT NULL,
  position INT NOT NULL,
  created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
