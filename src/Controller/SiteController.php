<?php

namespace App\Controller;

use App\Entity\Product;
use App\Service\SiteContent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Contenu éditable du site (page "Contenu du site") et slider d'accueil (page "Slider d'accueil")
final class SiteController extends ApiController
{
    public function __construct(private readonly SiteContent $site)
    {
    }

    #[Route('/api/content', name: 'api_content_show', methods: ['GET'])]
    public function content(): JsonResponse
    {
        return new JsonResponse($this->site->get());
    }

    // Envoyez tout `content` (comme le bouton Enregistrer du React) ou seulement les clés à modifier
    #[Route('/api/content', name: 'api_content_update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateContent(Request $request): JsonResponse
    {
        return new JsonResponse($this->site->update($this->body($request)));
    }

    #[Route('/api/content/reset', name: 'api_content_reset', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function resetContent(): JsonResponse
    {
        return new JsonResponse($this->site->reset());
    }

    // Réglages + produits déjà sélectionnés (équivalent de sliderSettings + pickSliderProducts du React)
    #[Route('/api/slider', name: 'api_slider_show', methods: ['GET'])]
    public function slider(): JsonResponse
    {
        return $this->sliderResponse();
    }

    // { enabled, mode: "auto"|"manual", count, delay, productIds } — champs partiels acceptés
    #[Route('/api/slider', name: 'api_slider_update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateSlider(Request $request): JsonResponse
    {
        $this->site->update(['slider' => array_replace($this->site->sliderSettings(), $this->body($request))]);

        return $this->sliderResponse();
    }

    private function sliderResponse(): JsonResponse
    {
        $settings = $this->site->sliderSettings();

        return new JsonResponse([
            'settings' => $settings,
            'products' => array_map(fn (Product $p) => $p->toArray(), $this->site->sliderProducts($settings)),
        ]);
    }
}
