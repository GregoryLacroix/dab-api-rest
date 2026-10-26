<?php

namespace App\Service;

// Optimisation des photos téléversées (extension PHP GD, présente chez presque tous les hébergeurs) :
//  - orientation corrigée (photos de téléphone), métadonnées supprimées (dont la position GPS) ;
//  - image principale réduite à 1600 px de côté maximum, au format WebP (JPEG si WebP indisponible) ;
//  - versions 480 / 800 / 1200 px (nommées <nom>-opt-480.webp…) : le site choisit la plus légère adaptée à l'écran (srcset) ;
//  - <nom>-share.jpg : version JPEG 1200 px pour les aperçus de partage (tous les réseaux sociaux n'affichent pas le WebP).
final class ImageOptimizer
{
    public const MAX_SIDE = 1600;
    public const VARIANTS = [480, 800, 1200];
    private const MAX_PIXELS = 40_000_000; // au-delà, le décodage dépasserait la mémoire d'un hébergement mutualisé

    public function isAvailable(): bool
    {
        return \extension_loaded('gd') && \function_exists('imagecreatefromstring') && \function_exists('imagescale');
    }

    /**
     * @return array{name: string, width: int, height: int} nom du fichier principal (créé dans $dir) et ses dimensions
     *
     * @throws \RuntimeException si l'image n'est pas décodable (l'appelant garde alors le fichier d'origine)
     */
    public function process(string $path, string $dir, bool $variants = true): array
    {
        $info = @getimagesize($path);
        if ($info === false || ($info[0] * $info[1]) > self::MAX_PIXELS) {
            throw new \RuntimeException('Image illisible ou trop grande.');
        }
        @ini_set('memory_limit', '256M');
        $raw = file_get_contents($path);
        $image = $raw === false ? false : @imagecreatefromstring($raw);
        unset($raw);
        if ($image === false) {
            throw new \RuntimeException('Format non décodable (image animée ?).');
        }
        if (!imageistruecolor($image) && \function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image); // WebP n'accepte pas les images à palette (PNG indexés)
        }
        $image = $this->orient($image, $path, (string) ($info['mime'] ?? ''));
        imagesavealpha($image, true);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier d’envoi introuvable.');
        }
        $webp = \function_exists('imagewebp');
        $ext = $webp ? 'webp' : 'jpg';
        // « -opt » : marque les photos optimisées (les anciennes photos, sans versions allégées, ne portent pas ce suffixe)
        $base = bin2hex(random_bytes(16)).'-opt';

        $main = $this->fit($image, self::MAX_SIDE);
        $this->save($main, "$dir/$base.$ext", $webp);
        if ($variants) { // les photos de mur des devis n'ont besoin que de l'image principale
            foreach (self::VARIANTS as $side) {
                $this->save($this->fit($main, $side), "$dir/$base-$side.$ext", $webp);
            }
            $this->save($this->fit($main, 1200), "$dir/$base-share.jpg", false);
        }

        return ['name' => "$base.$ext", 'width' => imagesx($main), 'height' => imagesy($main)];
    }

    /** Réduit l'image pour que son grand côté ne dépasse pas $maxSide (jamais d'agrandissement). */
    private function fit(\GdImage $img, int $maxSide): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long <= $maxSide) {
            return $img;
        }
        $ratio = $maxSide / $long;
        $scaled = imagescale($img, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)), \IMG_BICUBIC);
        if ($scaled === false) {
            throw new \RuntimeException('Redimensionnement impossible.');
        }
        imagesavealpha($scaled, true);

        return $scaled;
    }

    private function save(\GdImage $img, string $file, bool $webp): void
    {
        if ($webp) {
            if (!imagewebp($img, $file, 82)) {
                throw new \RuntimeException('Écriture WebP impossible.');
            }

            return;
        }
        // JPEG : pas de transparence, on pose l'image sur fond blanc
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        imageinterlace($flat, true); // JPEG progressif : s'affiche d'abord en flou, puis se précise
        if (!imagejpeg($flat, $file, 85)) {
            throw new \RuntimeException('Écriture JPEG impossible.');
        }
    }

    /** Redresse une photo de téléphone d'après son orientation EXIF (si l'extension exif est disponible). */
    private function orient(\GdImage $img, string $path, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !\function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $angle = match (\is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);

        return $rotated === false ? $img : $rotated;
    }
}
