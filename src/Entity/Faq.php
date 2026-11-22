<?php

namespace App\Entity;

use App\Repository\FaqRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Question fréquente (page FAQ du site, éditable dans le back-office)
#[ORM\Entity(repositoryClass: FaqRepository::class)]
class Faq
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    private string $question = '';

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $questionEn = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $answer = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $answerEn = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $published = true;

    public function getId(): ?int { return $this->id; }
    public function setQuestion(string $v): static { $this->question = $v; return $this; }
    public function setQuestionEn(?string $v): static { $this->questionEn = $v; return $this; }
    public function setAnswer(string $v): static { $this->answer = $v; return $this; }
    public function setAnswerEn(?string $v): static { $this->answerEn = $v; return $this; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }
    public function setPublished(bool $v): static { $this->published = $v; return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'question' => $this->question, 'questionEn' => $this->questionEn,
            'answer' => $this->answer, 'answerEn' => $this->answerEn, 'position' => $this->position, 'published' => $this->published,
        ];
    }
}
