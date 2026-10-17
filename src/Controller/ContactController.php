<?php

namespace App\Controller;

use App\Dto\ContactInput;
use App\Entity\ContactMessage;
use App\Repository\ContactMessageRepository;
use App\Service\ApiValidator;
use App\Service\AppMailer;
use App\Service\ValidationFailedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
final class ContactController extends ApiController
{
    private const THANKS = 'Merci ! Votre message a bien été envoyé. Nous vous répondons en général sous 48 h ouvrées.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactMessageRepository $messages,
        private readonly ApiValidator $validator,
        private readonly AppMailer $mailer,
        #[Autowire(service: 'limiter.contact')] private readonly RateLimiterFactory $contactLimiter,
        #[Autowire('%env(CONTACT_EMAIL)%')] private readonly string $shopEmail,
    ) {
    }

    // Page "Contact" (public) : { name, email, subject, message, consent, website }
    #[Route('/contact', name: 'api_contact_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $this->throttle($this->contactLimiter, $request->getClientIp() ?? 'unknown');

        $input = ContactInput::fromArray($this->body($request));
        // Champ piège rempli = robot : on répond "succès" sans rien enregistrer ni envoyer
        if ($input->website !== '') {
            return new JsonResponse(['message' => self::THANKS], 201);
        }
        $this->validator->check($input);

        $message = (new ContactMessage())->setContent($input->name, $input->email, $input->subject, $input->message);
        $this->em->persist($message);
        $this->em->flush();

        // Les e-mails ne bloquent jamais l'envoi : le message est déjà enregistré, un échec est journalisé
        $this->mailer->sendContactNotification($message, $this->shopEmail);
        $this->mailer->sendContactAck($message);

        return new JsonResponse(['message' => self::THANKS], 201);
    }

    // Back-office "Messages" : ?read=0 (non lus) &q=… &page=1 &perPage=10
    #[Route('/messages', name: 'api_messages_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request): JsonResponse
    {
        $raw = $request->query->get('read');
        $read = ($raw === null || $raw === '') ? null : filter_var($raw, FILTER_VALIDATE_BOOLEAN);

        return $this->paginate($this->messages->search($read, $request->query->get('q')), $request, fn (ContactMessage $m) => $m->toArray(), 10);
    }

    #[Route('/messages/{id}', name: 'api_messages_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse($this->find($id)->toArray());
    }

    // { "read": true } : marquer comme lu / non lu
    #[Route('/messages/{id}', name: 'api_messages_update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $message = $this->find($id);
        $read = $this->body($request)['read'] ?? null;
        if (!is_bool($read)) {
            throw new ValidationFailedException(['read' => 'Booléen attendu (true ou false).']);
        }
        $message->setRead($read);
        $this->em->flush();

        return new JsonResponse($message->toArray());
    }

    #[Route('/messages/{id}', name: 'api_messages_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): Response
    {
        $this->em->remove($this->find($id));
        $this->em->flush();

        return new Response(status: 204);
    }

    private function find(int $id): ContactMessage
    {
        return $this->messages->find($id) ?? throw new NotFoundHttpException();
    }
}
