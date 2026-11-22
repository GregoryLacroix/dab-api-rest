<?php

namespace App\Service;

use App\Entity\Setting;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

// Contenu éditable du site (page d'accueil, pied de page, slider) : même structure que `content` du store React.
final class SiteContent
{
    public const DEFAULTS = [
        'hero' => [
            'title' => 'Des paysages de mer peints sur pierre, des murs qui prennent vie.',
            'text' => 'Chaque galet est peint à la main et verni, chaque fresque est pensée pour votre mur. Choisissez une pièce unique ou demandez la vôtre.',
            'primaryLabel' => 'Voir la boutique', 'primaryTo' => '/produits',
            'secondaryLabel' => 'Demander une fresque', 'secondaryTo' => '/devis',
        ],
        'marquee' => ['Peinture sur galet', 'Fresque murale', 'Boutique en ligne'],
        'story' => [
            ['title' => 'Le galet nu', 'text' => 'Je choisis une pierre sur la plage. Sa forme décide du paysage.'],
            ['title' => 'La mer prend forme', 'text' => 'À l’acrylique, couche après couche, la vague et l’horizon apparaissent.'],
            ['title' => 'Le vernis', 'text' => 'Un vernis brillant protège la peinture. Votre galet est prêt à vivre chez vous.'],
        ],
        // Galet de l'accueil : « animation » (galet qui se peint au scroll) ou « photos » (une photo par étape, dans la même forme de galet)
        'storyView' => ['mode' => 'animation', 'images' => ['', '', '']],
        // Bandeau de réassurance (accueil) et liste courte (fiche produit) : 4 éléments ; un titre vide masque l'élément
        'trust' => [
            'enabled' => true,
            'items' => [
                ['icon' => 'hand', 'title' => 'Peint à la main', 'text' => 'Chaque pièce est unique', 'titleEn' => 'Hand-painted', 'textEn' => 'Every piece is unique'],
                ['icon' => 'varnish', 'title' => 'Vernis protecteur', 'text' => 'Une peinture faite pour durer', 'titleEn' => 'Protective varnish', 'textEn' => 'Paint made to last'],
                ['icon' => 'ship', 'title' => 'Expédition soignée', 'text' => 'Colis protégé, suivi par e-mail', 'titleEn' => 'Careful shipping', 'textEn' => 'Protected parcel, tracked by e-mail'],
                ['icon' => 'pay', 'title' => 'Paiement sécurisé', 'text' => 'Par carte, via Stripe', 'titleEn' => 'Secure payment', 'textEn' => 'By card, through Stripe'],
            ],
        ],
        // Page « À propos » : masquée tant qu'elle n'est pas activée dans le back-office (texte de départ à personnaliser avec votre histoire)
        'about' => [
            'enabled' => false,
            'title' => 'À propos', 'lead' => 'Des galets et des murs peints à la main, un par un.',
            'text' => "Chaque galet est peint à la main à l’acrylique, puis verni pour durer. Chaque pièce est unique.\n\nLes fresques murales sont pensées pour votre mur : on échange sur votre projet, puis je peins sur place.",
            'titleEn' => 'About', 'leadEn' => 'Pebbles and walls painted by hand, one at a time.',
            'textEn' => "Every pebble is hand-painted in acrylic, then varnished to last. Every piece is unique.\n\nMurals are designed for your wall: we talk about your project, then I paint on site.",
            'image' => '', 'signature' => '',
        ],
        'slider' => ['enabled' => true, 'mode' => 'auto', 'count' => 5, 'delay' => 5, 'productIds' => []],
        'latest' => ['title' => 'Les dernières créations', 'count' => 3],
        // Zoom des photos sur la fiche produit : loupe à la souris (on/off, grossissement) et grossissement en plein écran
        'zoom' => ['lens' => true, 'lensLevel' => 2.5, 'fullLevel' => 2.5],
        'blocks' => [
            ['title' => 'Peinture sur galet', 'text' => 'Des galets ramassés, peints à l’acrylique, vernis pour durer. Chacun est unique.', 'cta' => 'Voir les galets', 'to' => '/produits?categorie=galets'],
            ['title' => 'Fresque murale', 'text' => 'Intérieur ou extérieur : on échange sur votre projet, puis je peins sur place.', 'cta' => 'Demander un devis', 'to' => '/devis'],
        ],
        'contact' => [
            'title' => 'Contactez-nous',
            'text' => 'Une question sur un galet, une commande sur mesure ou une fresque ? Écrivez-nous, nous répondons en général sous 48 h ouvrées.',
            'email' => 'contact@dab-boutique.fr',
            'instagram' => 'https://www.instagram.com/piloute_s/',
        ],
        // Informations légales : à compléter dans le back-office, elles alimentent mentions légales, confidentialité et CGV
        'legal' => [
            'company' => '', 'status' => '', 'siret' => '', 'address' => '', 'email' => '', 'phone' => '', 'publisher' => '',
            'host' => '', 'hostAddress' => '', 'mediator' => '',
            'vatNote' => 'Les prix sont indiqués en euros, toutes taxes comprises (TTC).',
            'deliveryDelay' => '1 à 2 semaines après la commande pour les galets en stock',
            'shippingTerms' => 'Les frais de livraison sont indiqués avant la validation de la commande.',
        ],
        // Page d'attente « Bientôt disponible » : quand `enabled` est vrai, les visiteurs ne voient que cette page (l'administrateur connecté voit le site)
        'comingSoon' => [
            'enabled' => false,
            'title' => 'Bientôt disponible',
            'text' => 'La boutique de galets peints à la main et de décoration murale ouvre très prochainement. Revenez nous voir !',
            'date' => '',         // date d'ouverture facultative (AAAA-MM-JJ), affichée sur la page
            'autoOpen' => false,  // vrai : le site s'ouvre tout seul à cette date
        ],
        'footer' => ['text' => 'Les galets d’Ambre – Peinture sur galets & décoration murale'],
    ];

    private const KEY = 'content';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiValidator $validator,
        private readonly ProductRepository $products,
    ) {
    }

    /** Contenu enregistré, complété par les valeurs par défaut (fusion de premier niveau, comme le store React). */
    public function get(): array
    {
        $saved = $this->em->find(Setting::class, self::KEY)?->getValue() ?? [];

        return array_replace(self::DEFAULTS, $saved);
    }

    /** @param array<string, mixed> $input clés de premier niveau à remplacer ; les autres sont conservées */
    public function update(array $input): array
    {
        $content = array_replace($this->get(), $input);
        $this->validator->check($content, self::constraint());
        $this->store($content);

        return $content;
    }

    public function reset(): array
    {
        $this->store(self::DEFAULTS);

        return self::DEFAULTS;
    }

    /** Réglages du slider avec valeurs par défaut (équivalent de sliderSettings() côté React) */
    public function sliderSettings(?array $content = null): array
    {
        return array_replace(self::DEFAULTS['slider'], ($content ?? $this->get())['slider'] ?? []);
    }

    /** Produits affichés dans le slider (équivalent de pickSliderProducts() côté React) */
    public function sliderProducts(array $settings): array
    {
        if (!$settings['enabled']) {
            return [];
        }
        if ($settings['mode'] === 'manual') {
            $byId = $this->products->findPublishedByIds(array_map('intval', $settings['productIds']));
            $ordered = [];
            foreach ($settings['productIds'] as $id) {
                if (isset($byId[$id])) {
                    $ordered[] = $byId[$id];
                }
            }

            return $ordered;
        }

        return array_slice($this->products->findPublished(), 0, (int) $settings['count']);
    }

    private function store(array $content): void
    {
        $setting = $this->em->find(Setting::class, self::KEY);
        if ($setting === null) {
            $setting = new Setting(self::KEY);
            $this->em->persist($setting);
        }
        $setting->setValue($content);
        $this->em->flush();
    }

    public static function constraint(): Assert\Collection
    {
        $text = fn (int $max) => [
            new Assert\Type('string', message: 'Texte attendu.'),
            new Assert\NotBlank(message: 'Ce champ est obligatoire.'),
            new Assert\Length(max: $max, maxMessage: 'Texte trop long ({{ limit }} caractères maximum).'),
        ];
        $link = [
            new Assert\Type('string', message: 'Texte attendu.'),
            new Assert\Regex(pattern: '~^(/\S*|https?://\S+)$~', message: 'Lien interne (ex. /produits) ou adresse http(s)://…'),
        ];
        $int = fn (int $min, int $max) => [
            new Assert\Type('integer', message: 'Nombre entier attendu.'),
            new Assert\Range(min: $min, max: $max, notInRangeMessage: 'Valeur comprise entre {{ min }} et {{ max }}.'),
        ];
        // Champ texte facultatif (peut rester vide)
        $opt = fn (int $max) => [
            new Assert\Type('string', message: 'Texte attendu.'),
            new Assert\Length(max: $max, maxMessage: 'Texte trop long ({{ limit }} caractères maximum).'),
        ];
        // Messages communs aux Collection. NB : PHP interdit `...$tableau` APRÈS un argument nommé (fields: …), d'où les arguments nommés écrits en toutes lettres.
        $extra = 'Champ inattendu.';
        $missing = 'Champ manquant.';

        return new Assert\Collection(fields: [
            'hero' => new Assert\Collection(fields: [
                'title' => $text(200), 'text' => $text(600),
                'primaryLabel' => $text(60), 'primaryTo' => $link,
                'secondaryLabel' => $text(60), 'secondaryTo' => $link,
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'marquee' => [
                new Assert\Type('array', message: 'Liste attendue.'),
                new Assert\Count(min: 1, max: 6, minMessage: 'Au moins un mot.', maxMessage: '6 mots maximum.'),
                new Assert\All(constraints: $text(60)),
            ],
            'story' => [
                new Assert\Type('array', message: 'Liste attendue.'),
                new Assert\Count(exactly: 3, exactMessage: 'Les 3 étapes sont obligatoires.'),
                new Assert\All(constraints: [new Assert\Collection(fields: ['title' => $text(80), 'text' => $text(300)], extraFieldsMessage: $extra, missingFieldsMessage: $missing)]),
            ],
            'trust' => new Assert\Collection(fields: [
                'enabled' => new Assert\Type('bool', message: 'Booléen attendu.'),
                'items' => [
                    new Assert\Type('array', message: 'Liste attendue.'),
                    new Assert\Count(exactly: 4, exactMessage: 'Les 4 éléments sont attendus (titre vide = masqué).'),
                    new Assert\All(constraints: [new Assert\Collection(fields: [
                        'icon' => new Assert\Choice(choices: ['hand', 'varnish', 'ship', 'pay'], message: 'Icône invalide.'),
                        'title' => $opt(60), 'text' => $opt(120), 'titleEn' => $opt(60), 'textEn' => $opt(120),
                    ], extraFieldsMessage: $extra, missingFieldsMessage: $missing)]),
                ],
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'about' => new Assert\Collection(fields: [
                'enabled' => new Assert\Type('bool', message: 'Booléen attendu.'),
                'title' => $text(80), 'lead' => $opt(300), 'text' => $opt(5000),
                'titleEn' => $opt(80), 'leadEn' => $opt(300), 'textEn' => $opt(5000),
                'image' => [new Assert\Type('string', message: 'Texte attendu.'), new Assert\Regex(pattern: '~^(https?://\S{1,480})?$~', message: 'Adresse de photo invalide.')],
                'signature' => $opt(80),
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'storyView' => new Assert\Collection(fields: [
                'mode' => new Assert\Choice(choices: ['animation', 'photos'], message: 'Mode invalide (animation ou photos).'),
                'images' => [
                    new Assert\Type('array', message: 'Liste attendue.'),
                    new Assert\Count(exactly: 3, exactMessage: 'Une photo (ou vide) par étape : 3 au total.'),
                    new Assert\All(constraints: [
                        new Assert\Type('string', message: 'Texte attendu.'),
                        new Assert\Regex(pattern: '~^(https?://\S{1,480})?$~', message: 'Adresse de photo invalide.'),
                    ]),
                ],
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'slider' => new Assert\Collection(fields: [
                'enabled' => new Assert\Type('bool', message: 'Booléen attendu.'),
                'mode' => new Assert\Choice(choices: ['auto', 'manual'], message: 'Mode invalide (auto ou manual).'),
                'count' => $int(1, 8),
                'delay' => $int(3, 10),
                'productIds' => [
                    new Assert\Type('array', message: 'Liste attendue.'),
                    new Assert\Count(max: 30, maxMessage: '30 produits maximum.'),
                    new Assert\All(constraints: [new Assert\Type('integer', message: 'Identifiant de produit attendu.')]),
                ],
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'latest' => new Assert\Collection(fields: ['title' => $text(80), 'count' => $int(1, 6)], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'zoom' => new Assert\Collection(fields: [
                'lens' => new Assert\Type('bool', message: 'Booléen attendu.'),
                'lensLevel' => [
                    new Assert\Type(type: ['int', 'float'], message: 'Nombre attendu.'),
                    new Assert\Range(min: 1.5, max: 5, notInRangeMessage: 'Valeur comprise entre {{ min }} et {{ max }}.'),
                ],
                'fullLevel' => [
                    new Assert\Type(type: ['int', 'float'], message: 'Nombre attendu.'),
                    new Assert\Range(min: 1.5, max: 5, notInRangeMessage: 'Valeur comprise entre {{ min }} et {{ max }}.'),
                ],
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'blocks' => [
                new Assert\Type('array', message: 'Liste attendue.'),
                new Assert\Count(exactly: 2, exactMessage: 'Les 2 blocs sont obligatoires.'),
                new Assert\All(constraints: [new Assert\Collection(fields: [
                    'title' => $text(80), 'text' => $text(300), 'cta' => $text(60), 'to' => $link,
                ], extraFieldsMessage: $extra, missingFieldsMessage: $missing)]),
            ],
            'contact' => new Assert\Collection(fields: [
                'title' => $text(80), 'text' => $text(400),
                'email' => [new Assert\Type('string', message: 'Texte attendu.'), new Assert\NotBlank(message: 'Ce champ est obligatoire.'), new Assert\Email(message: 'Adresse e-mail invalide.')],
                'instagram' => [new Assert\Type('string', message: 'Texte attendu.'), new Assert\Regex(pattern: '~^(https?://\S+)?$~', message: 'Adresse complète (https://…) ou vide.')],
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'legal' => new Assert\Collection(fields: [
                'company' => $opt(160), 'status' => $opt(160), 'siret' => $opt(40), 'address' => $opt(300),
                'email' => [new Assert\Type('string', message: 'Texte attendu.'), new Assert\Email(message: 'Adresse e-mail invalide.')],
                'phone' => $opt(40), 'publisher' => $opt(160), 'host' => $opt(160), 'hostAddress' => $opt(300), 'mediator' => $opt(500),
                'vatNote' => $opt(300), 'deliveryDelay' => $opt(200), 'shippingTerms' => $opt(500),
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'comingSoon' => new Assert\Collection(fields: [
                'enabled' => new Assert\Type('bool', message: 'Booléen attendu.'),
                'title' => $text(80), 'text' => $text(400),
                'date' => [new Assert\Type('string', message: 'Texte attendu.'), new Assert\Regex(pattern: '~^(\d{4}-\d{2}-\d{2})?$~', message: 'Date au format AAAA-MM-JJ, ou vide.')],
                'autoOpen' => new Assert\Type('bool', message: 'Booléen attendu.'),
            ], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
            'footer' => new Assert\Collection(fields: ['text' => $text(160)], extraFieldsMessage: $extra, missingFieldsMessage: $missing),
        ], extraFieldsMessage: $extra, missingFieldsMessage: $missing);
    }
}
