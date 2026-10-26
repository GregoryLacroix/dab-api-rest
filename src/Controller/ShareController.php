<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// Aperçus de partage (WhatsApp, Facebook, iMessage, X, LinkedIn, Telegram…) d'une fiche produit.
// Ces robots ne lancent pas le JavaScript du site React : ils ne verraient que les balises génériques. Le .htaccess du site les envoie
// ici (seulement eux) : une page minimale avec le nom, la photo et le prix du produit. Les visiteurs normaux n'y passent jamais.
final class ShareController
{
    public function __construct(
        private readonly ProductRepository $products,
        #[Autowire('%env(FRONTEND_URL)%')] private readonly string $frontendUrl,
        #[Autowire('%env(API_PUBLIC_URL)%')] private readonly string $apiUrl,
    ) {
    }

    #[Route('/api/share/products/{id}', name: 'api_share_product', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function product(int $id, Request $request): Response
    {
        $lang = $request->query->get('lang') === 'en' ? 'en' : 'fr';
        $front = rtrim($this->frontendUrl, '/');
        $product = $this->products->findPublishedByIds([$id])[$id] ?? null;
        if ($product === null) {
            return new Response('<!doctype html><meta charset="utf-8"><title>404</title><p>Produit introuvable.</p>', 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $url = $front.($lang === 'en' ? '/en/shop/' : '/produits/').$id;
        $title = $product->getName();
        $description = trim(mb_strimwidth(preg_replace('/\s+/', ' ', $product->getDescription()) ?? '', 0, 200, '…'));
        if ($description === '') {
            $description = $lang === 'en' ? 'Hand-painted pebble, unique piece.' : 'Galet peint à la main, pièce unique.';
        }
        $price = number_format($product->getPriceCents() / 100, 2, '.', '');
        $image = $this->imageFor($product->getImages(), $front);
        $locale = $lang === 'en' ? 'en_GB' : 'fr_FR';
        $e = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<title>{$e($title)} | Les galets d’Ambre</title>
<meta name="description" content="{$e($description)}">
<meta name="robots" content="noindex">
<link rel="canonical" href="{$e($url)}">
<meta property="og:type" content="product">
<meta property="og:site_name" content="Les galets d’Ambre">
<meta property="og:locale" content="{$locale}">
<meta property="og:title" content="{$e($title)}">
<meta property="og:description" content="{$e($description)}">
<meta property="og:url" content="{$e($url)}">
<meta property="og:image" content="{$e($image)}">
<meta property="product:price:amount" content="{$price}">
<meta property="product:price:currency" content="EUR">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{$e($title)}">
<meta name="twitter:description" content="{$e($description)}">
<meta name="twitter:image" content="{$e($image)}">
<meta http-equiv="refresh" content="0;url={$e($url)}">
</head>
<body>
<p><a href="{$e($url)}">{$e($title)}</a></p>
</body>
</html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /** Première photo du produit, en adresse absolue ; si elle a été optimisée à l'envoi, sa version JPEG de partage (1200 px). */
    private function imageFor(array $images, string $front): string
    {
        foreach ($images as $img) {
            $src = \is_string($img['src'] ?? null) ? $img['src'] : '';
            if ($src === '') {
                continue;
            }
            if (str_starts_with($src, '/')) {
                $src = rtrim($this->apiUrl, '/').$src;
            }

            return preg_replace('~^(.*/uploads/[a-f0-9]{32}-opt)\.(webp|jpg)$~', '$1-share.jpg', $src) ?? $src;
        }

        return $front.'/og-default.jpg';
    }
}
