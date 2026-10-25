<?php

namespace App\Controller;

use App\Service\ImageOptimizer;
use App\Service\ValidationFailedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Envoi d'une photo produit (multipart/form-data, champ "file") -> { src: "https://…/uploads/xxx.webp", width, height }
// À utiliser à la place des images encodées en base64 : la base reste légère.
// Les photos JPEG / PNG / WebP sont optimisées (voir ImageOptimizer : 1600 px max, WebP, versions 480/800/1200 px pour le srcset) ;
// si l'optimisation est impossible (extension GD absente, image animée…), le fichier d'origine est conservé tel quel.
final class UploadController extends ApiController
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const MAX_BYTES = 5 * 1024 * 1024;

    #[Route('/api/uploads', name: 'api_upload', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function upload(
        Request $request,
        ImageOptimizer $optimizer,
        LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/public/uploads')] string $dir,
    ): JsonResponse {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new ValidationFailedException(['file' => 'Envoyez une image dans le champ « file ».']);
        }
        $ext = self::TYPES[$file->getMimeType() ?? ''] ?? null; // type détecté sur le contenu, pas sur le nom
        if ($ext === null) {
            throw new ValidationFailedException(['file' => 'Format accepté : JPEG, PNG, WebP ou GIF.']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ValidationFailedException(['file' => 'Image trop lourde (5 Mo maximum).']);
        }

        $base = $request->getSchemeAndHttpHost().$request->getBasePath().'/uploads/';

        // GIF : conservé tel quel (une image animée perdrait son animation)
        if ($ext !== 'gif' && $optimizer->isAvailable()) {
            try {
                $out = $optimizer->process($file->getPathname(), $dir);

                return new JsonResponse(['src' => $base.$out['name'], 'width' => $out['width'], 'height' => $out['height']], 201);
            } catch (\Throwable $e) {
                $logger->warning('Photo non optimisée, fichier d\'origine conservé : '.$e->getMessage());
            }
        }

        $name = bin2hex(random_bytes(16)).'.'.$ext;
        $file->move($dir, $name);

        return new JsonResponse(['src' => $base.$name], 201);
    }
}
