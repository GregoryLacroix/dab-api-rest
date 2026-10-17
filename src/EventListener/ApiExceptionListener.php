<?php

namespace App\EventListener;

use App\Service\ValidationFailedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

// Format d'erreur unique pour toute l'API : { error, message, violations? }
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    private const CODES = [400 => 'bad_request', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict', 410 => 'gone', 413 => 'too_large', 429 => 'too_many_requests'];

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }
        $e = $event->getThrowable();
        $headers = [];

        if ($e instanceof ValidationFailedException) {
            $status = 422;
            $payload = ['error' => 'validation_failed', 'message' => $e->getMessage(), 'violations' => $e->violations];
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $message = match ($status) {
                404 => 'Ressource introuvable.',
                405 => 'Méthode non autorisée.',
                default => $e->getMessage() !== '' ? $e->getMessage() : 'Requête invalide.',
            };
            $payload = ['error' => self::CODES[$status] ?? 'http_error', 'message' => $message];
        } else {
            $status = 500;
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            $payload = ['error' => 'server_error', 'message' => 'Une erreur est survenue. Réessayez plus tard.'];
            if ($this->debug) {
                $payload['debug'] = $e::class.': '.$e->getMessage();
            }
        }

        $event->setResponse(new JsonResponse($payload, $status, $headers));
    }
}
