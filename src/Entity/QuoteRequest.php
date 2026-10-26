<?php

namespace App\Entity;

use App\Repository\QuoteRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Demande de devis pour une fresque murale (ou un galet sur mesure) : formulaire du site, puis suivi dans le back-office
// (devis envoyé au client, acompte réglé par Stripe).
#[ORM\Entity(repositoryClass: QuoteRequestRepository::class)]
#[ORM\Table(name: 'quote_request')]
class QuoteRequest
{
    public const REF_OFFSET = 1000; // DEV-1001, DEV-1002…
    public const PROJECT_TYPES = ['interieur', 'exterieur'];
    public const BUDGETS = ['moins-500', '500-1000', '1000-2500', 'plus-2500', 'ne-sais-pas'];
    public const PROJECT_LABELS = ['interieur' => 'Intérieur', 'exterieur' => 'Extérieur'];
    public const BUDGET_LABELS = [
        'moins-500' => 'Moins de 500 €', '500-1000' => '500 à 1 000 €', '1000-2500' => '1 000 à 2 500 €',
        'plus-2500' => 'Plus de 2 500 €', 'ne-sais-pas' => 'Je ne sais pas encore',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    // Langue du site au moment de la demande (fr / en) : décide de la langue de la page de paiement de l'acompte
    #[ORM\Column(length: 2)]
    private string $lang = 'fr';

    #[ORM\Column(length: 20)]
    private string $projectType = 'interieur';

    #[ORM\Column(nullable: true)]
    private ?int $widthCm = null;

    #[ORM\Column(nullable: true)]
    private ?int $heightCm = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $budget = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $desiredBy = null;

    /** @var string[] adresses des photos du mur envoyées par la personne */
    #[ORM\Column(type: Types::JSON)]
    private array $photos = [];

    #[ORM\Column(length: 20, enumType: QuoteStatus::class)]
    private QuoteStatus $status = QuoteStatus::New;

    // --- Devis envoyé au client
    #[ORM\Column(nullable: true)]
    private ?int $amountCents = null;

    #[ORM\Column(options: ['default' => 30])]
    private int $depositPercent = 30;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $quoteMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $quoteSentAt = null;

    // Clé de la page « devis » envoyée par e-mail (48 caractères imprévisibles) : donne accès au devis et au paiement de l'acompte
    #[ORM\Column(length: 48, nullable: true, unique: true)]
    private ?string $payToken = null;

    // --- Acompte réglé par Stripe Checkout
    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $depositSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $depositPaymentIntentId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $depositPaidAt = null;

    // Note interne (jamais montrée au client)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNote = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return 'DEV-'.(self::REF_OFFSET + (int) $this->id); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getName(): string { return $this->name; }
    public function getEmail(): string { return $this->email; }
    public function getPhone(): ?string { return $this->phone; }
    public function getLang(): string { return $this->lang; }
    public function getProjectType(): string { return $this->projectType; }
    public function getWidthCm(): ?int { return $this->widthCm; }
    public function getHeightCm(): ?int { return $this->heightCm; }
    public function getLocation(): ?string { return $this->location; }
    public function getDescription(): string { return $this->description; }
    public function getBudget(): ?string { return $this->budget; }
    public function getDesiredBy(): ?string { return $this->desiredBy; }
    public function getPhotos(): array { return $this->photos; }
    public function getStatus(): QuoteStatus { return $this->status; }
    public function setStatus(QuoteStatus $status): static { $this->status = $status; return $this; }
    public function getAmountCents(): ?int { return $this->amountCents; }
    public function getDepositPercent(): int { return $this->depositPercent; }
    public function getQuoteMessage(): ?string { return $this->quoteMessage; }
    public function getQuoteSentAt(): ?\DateTimeImmutable { return $this->quoteSentAt; }
    public function getPayToken(): ?string { return $this->payToken; }
    public function getDepositSessionId(): ?string { return $this->depositSessionId; }
    public function setDepositSessionId(?string $id): static { $this->depositSessionId = $id; return $this; }
    public function getDepositPaymentIntentId(): ?string { return $this->depositPaymentIntentId; }
    public function setDepositPaymentIntentId(?string $id): static { $this->depositPaymentIntentId = $id; return $this; }
    public function getDepositPaidAt(): ?\DateTimeImmutable { return $this->depositPaidAt; }
    public function getAdminNote(): ?string { return $this->adminNote; }
    public function setAdminNote(?string $note): static { $this->adminNote = $note; return $this; }

    /** @param string[] $photos */
    public function setRequest(string $name, string $email, ?string $phone, string $lang, string $projectType, ?int $widthCm, ?int $heightCm,
        ?string $location, string $description, ?string $budget, ?string $desiredBy, array $photos): static
    {
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->lang = $lang === 'en' ? 'en' : 'fr';
        $this->projectType = $projectType;
        $this->widthCm = $widthCm;
        $this->heightCm = $heightCm;
        $this->location = $location;
        $this->description = $description;
        $this->budget = $budget;
        $this->desiredBy = $desiredBy;
        $this->photos = array_values($photos);

        return $this;
    }

    public function setQuote(int $amountCents, int $depositPercent, ?string $message): static
    {
        $this->amountCents = $amountCents;
        $this->depositPercent = $depositPercent;
        $this->quoteMessage = $message;

        return $this;
    }

    public function markQuoteSent(string $token): static
    {
        $this->payToken ??= $token;
        $this->quoteSentAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = QuoteStatus::Quoted;

        return $this;
    }

    /** Acompte en centimes (0 si aucun acompte demandé ; null tant qu'aucun montant n'est fixé). */
    public function depositCents(): ?int
    {
        return $this->amountCents === null ? null : (int) round($this->amountCents * $this->depositPercent / 100);
    }

    /** Vue complète pour le back-office. */
    public function toArray(): array
    {
        return [
            'id' => $this->getReference(),
            'date' => $this->createdAt->format(\DATE_ATOM),
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'lang' => $this->lang,
            'projectType' => $this->projectType,
            'widthCm' => $this->widthCm,
            'heightCm' => $this->heightCm,
            'location' => $this->location,
            'description' => $this->description,
            'budget' => $this->budget,
            'desiredBy' => $this->desiredBy,
            'photos' => $this->photos,
            'status' => $this->status->value,
            'amount' => $this->amountCents === null ? null : $this->amountCents / 100,
            'depositPercent' => $this->depositPercent,
            'deposit' => $this->depositCents() === null ? null : $this->depositCents() / 100,
            'quoteMessage' => $this->quoteMessage,
            'quoteSentAt' => $this->quoteSentAt?->format(\DATE_ATOM),
            'depositPaidAt' => $this->depositPaidAt?->format(\DATE_ATOM),
            'adminNote' => $this->adminNote,
        ];
    }

    /** Vue montrée au client sur la page de son devis (sans note interne, sans e-mail ni téléphone). */
    public function toPublicArray(): array
    {
        return [
            'reference' => $this->getReference(),
            'name' => $this->name,
            'projectType' => $this->projectType,
            'widthCm' => $this->widthCm,
            'heightCm' => $this->heightCm,
            'location' => $this->location,
            'status' => $this->status->value,
            'amount' => $this->amountCents === null ? null : $this->amountCents / 100,
            'depositPercent' => $this->depositPercent,
            'deposit' => $this->depositCents() === null ? null : $this->depositCents() / 100,
            'message' => $this->quoteMessage,
            'depositPaid' => $this->depositPaidAt !== null,
            'depositPaidAt' => $this->depositPaidAt?->format(\DATE_ATOM),
            'lang' => $this->lang,
        ];
    }
}
