<?php

namespace App\Command;

use App\Entity\Category;
use App\Entity\Money;
use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\ContactMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

// Mêmes données d'exemple que src/store/seed.js du React
#[AsCommand(name: 'app:seed', description: 'Charge les catégories, produits (avec photos), commandes et messages d’exemple du site')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%env(API_PUBLIC_URL)%')] private readonly string $publicUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('demo-admin', null, InputOption::VALUE_NONE, 'Crée aussi admin@dab.fr / galet2026 (DÉVELOPPEMENT UNIQUEMENT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->em->getRepository(Category::class)->count([]) > 0 || $this->em->getRepository(Product::class)->count([]) > 0) {
            $io->warning("La base contient déjà des données : rien n'a été ajouté.\nPour repartir de zéro : php bin/console doctrine:schema:drop --force --full-database && php bin/console doctrine:schema:create && php bin/console app:seed");

            return Command::SUCCESS;
        }

        $categories = [];
        foreach ([['galets', 'Galets'], ['fresques', 'Fresques']] as $i => [$id, $name]) {
            $categories[$id] = (new Category())->setId($id)->setName($name)->setPosition($i + 1);
            $this->em->persist($categories[$id]);
        }

        // Photos d'exemple : public/uploads/seed/*.jpg, servies par l'API
        $photo = fn (string $file, string $alt) => [['src' => rtrim($this->publicUrl, '/').'/uploads/seed/'.$file.'.jpg', 'alt' => $alt]];
        $defs = [
            ['Galet « Arc-en-ciel »', 'galets', 38, 'Un galet haut en couleurs : dégradé arc-en-ciel, fleurs blanches et motifs graphiques noirs peints à la main. Pièce unique.',
                $photo('arc-en-ciel', 'Galet arc-en-ciel peint de fleurs et de motifs graphiques')],
            ['Tortue en galets', 'galets', 65, 'Une tortue composée de cinq galets assemblés, peints en dégradés de verts avec des fleurs et des écailles dessinées une à une. Pièce unique, vernie.',
                $photo('tortue', 'Tortue réalisée avec cinq galets peints en vert')],
            ['Galet « Spectre »', 'galets', 34, 'Un galet en dôme aplati recouvert de points peints du rouge au turquoise, rehaussés de touches dorées. Sa petite ouverture au sommet en fait un joli vide-poche.',
                $photo('spectre', 'Galet en dôme couvert de points aux couleurs de l’arc-en-ciel')],
            ['Galet mandala turquoise et or', 'galets', 42, 'Un mandala très détaillé en turquoise, or et blanc sur fond noir. Chaque motif est tracé au pinceau fin.',
                $photo('turquoise-or', 'Galet orné d’un mandala turquoise et or sur fond noir')],
            ['Galet mandala « Fleur de feu »', 'galets', 40, 'Un mandala aux pétales orange, bleus et violets autour d’un cœur jaune et rose. Vernis brillant.',
                $photo('fleur-de-feu', 'Galet orné d’un mandala orange, bleu et violet')],
            ['Lot de 3 galets mandalas pointillés', 'galets', 75, 'Trois galets noirs ornés de mandalas en points colorés. Vendus ensemble, livrés dans un écrin.',
                $photo('trio-pointilles', 'Trois galets noirs décorés de mandalas en points colorés')],
            ['Galet « Fleurs bleues »', 'galets', 36, 'De grandes fleurs en dégradés de bleus, cernées de noir et peintes en points très fins.',
                $photo('fleurs-bleues', 'Galet bleu décoré de grandes fleurs pointillées')],
            ['Galet personnalisé', 'galets', 45, 'Votre galet adapté à vos goûts : couleurs, motifs, prénom ou date. Exemple réalisé pour la petite fille d’une collègue. Délai : environ 2 semaines.',
                $photo('personnalise', 'Exemple de galet personnalisé : mandala de feuilles vertes et bleues')],
            ['Duo de galets mandalas floraux', 'galets', 55, 'Deux galets noirs ornés de fleurs en points multicolores. Vendus ensemble.',
                $photo('duo-fleurs', 'Deux galets noirs décorés de fleurs en points colorés')],
            ['Fresque murale sur mesure', 'fresques', 450, 'Fresque réalisée chez vous, à partir de 450 € selon la surface. Devis offert après échange.',
                [['tone' => 'wall', 'alt' => 'Fresque murale (visuel provisoire)']]],
        ];
        $products = [];
        foreach ($defs as [$name, $cat, $price, $desc, $images]) {
            $p = (new Product())->setName($name)->setCategory($categories[$cat])->setPriceCents(Money::toCents($price))
                ->setDescription($desc)->setPublished(true)->setImages($images);
            $this->em->persist($p);
            $products[] = $p;
        }
        $this->em->flush(); // identifiants 1 à 10, comme dans le React

        // Du plus ancien au plus récent : CMD-1001 … CMD-1004
        $orders = [
            ['2026-09-28 14:22:00', 'livrée', 'Camille Martin', 'camille.martin@example.com', [[1, 1], [4, 1]]],
            ['2026-10-01 09:05:00', 'payée', 'Julien Leroy', 'julien.leroy@example.com', [[10, 1]]],
            ['2026-10-02 16:40:00', 'expédiée', 'Sophie Dubois', 'sophie.dubois@example.com', [[9, 1], [7, 1]]],
            ['2026-10-03 10:12:00', 'en attente', 'Marc Lefèbvre', 'marc.lefebvre@example.com', [[2, 1]]],
        ];
        foreach ($orders as [$date, $status, $name, $email, $lines]) {
            $items = [];
            $total = 0;
            foreach ($lines as [$n, $qty]) {
                $p = $products[$n - 1];
                $items[] = ['productId' => $p->getId(), 'name' => $p->getName(), 'price' => Money::fromCents($p->getPriceCents()), 'qty' => $qty];
                $total += $p->getPriceCents() * $qty;
            }
            $this->em->persist((new Order())
                ->setCreatedAt(new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
                ->setStatus(OrderStatus::from($status))
                ->setCustomer($name, $email)->setItems($items)->setTotalCents($total));
        }

        // Messages de la page Contact (du plus ancien au plus récent)
        $messages = [
            ['2026-10-02 18:05:00', 'Thomas Girard', 'thomas.girard@example.com', 'produit', 'Bonjour, la tortue en galets est-elle toujours disponible ? Merci !', true],
            ['2026-10-04 09:30:00', 'Léa Robert', 'lea.robert@example.com', 'sur-mesure', 'Bonjour, je souhaiterais un galet mandala aux couleurs pastel pour l’anniversaire de ma nièce. Quel serait le délai et le tarif ?', false],
        ];
        foreach ($messages as [$date, $name, $email, $subject, $text, $read]) {
            $this->em->persist((new ContactMessage())
                ->setCreatedAt(new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
                ->setContent($name, $email, $subject, $text)->setRead($read));
        }

        if ($input->getOption('demo-admin')) {
            $admin = (new User())->setEmail('admin@dab.fr')->setName('Administrateur')->setRoles(['ROLE_ADMIN'])->setVerified(true);
            $admin->setPassword($this->hasher->hashPassword($admin, 'galet2026'));
            $this->em->persist($admin);
            $io->note('Compte de démonstration admin@dab.fr / galet2026 créé : à NE JAMAIS utiliser en production.');
        }
        $this->em->flush();

        $io->success('Données d’exemple chargées (2 catégories, 10 produits, 4 commandes, 2 messages).');

        return Command::SUCCESS;
    }
}
