<?php

namespace App\Controller;

use App\Service\ValidationFailedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Envoi d'une photo produit (multipart/form-data, champ "file") -> { src: "https://…/uploads/xxx.jpg" }
// À utiliser à la place des images encodées en base64 : la base reste légère.
final class UploadController extends ApiController
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const MAX_BYTES = 5 * 1024 * 1024;

    #[Route('/api/uploads', name: 'api_upload', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function upload(Request $request, #[Autowire('%kernel.project_dir%/public/uploads')] string $dir): JsonResponse
    {
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

        $name = bin2hex(random_bytes(16)).'.'.$ext;
        $file->move($dir, $name);

        return new JsonResponse(['src' => $request->getSchemeAndHttpHost().$request->getBasePath().'/uploads/'.$name], 201);
    }
}
