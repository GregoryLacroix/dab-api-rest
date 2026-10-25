<?php

namespace App\Command;

use App\Repository\ProductRepository;
use App\Service\ImageOptimizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Optimise les photos déjà en ligne (envoyées avant la mise en place de l'optimisation) : crée les versions légères et met à jour
// les produits pour qu'ils les utilisent. Les fichiers d'origine ne sont PAS supprimés (retour arrière possible).
//   php bin/console app:optimize-images --dry-run     liste ce qui serait fait
//   php bin/console app:optimize-images               optimise
#[AsCommand(name: 'app:optimize-images', description: 'Optimise les photos de produits déjà envoyées (WebP, tailles multiples)')]
final class OptimizeImagesCommand extends Command
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
        private readonly ImageOptimizer $optimizer,
        #[Autowire('%kernel.project_dir%/public/uploads')] private readonly string $dir,
        #[Autowire('%env(API_PUBLIC_URL)%')] private readonly string $apiUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait optimisé, sans rien modifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->optimizer->isAvailable()) {
            $io->error('L’extension PHP GD est absente : impossible d’optimiser les photos (demandez son activation à l’hébergeur).');

            return Command::FAILURE;
        }
        $dry = (bool) $input->getOption('dry-run');
        $prefix = rtrim($this->apiUrl, '/').'/uploads/';
        $done = $skipped = $failed = 0;

        foreach ($this->products->findAll() as $product) {
            $images = $product->getImages();
            $changed = false;
            foreach ($images as $i => $img) {
                $src = \is_string($img['src'] ?? null) ? $img['src'] : '';
                $rel = str_starts_with($src, $prefix) ? substr($src, \strlen($prefix)) : '';
                $file = $this->dir.'/'.$rel;
                // Ignorées : photos hébergées ailleurs, déjà optimisées, GIF, chemins suspects, fichiers absents
                if ($rel === '' || str_contains($rel, '..') || preg_match('~^[a-f0-9]{32}\.(webp|jpg)$~', $rel) || preg_match('~\.gif$~i', $rel) || !is_file($file)) {
                    ++$skipped;
                    continue;
                }
                if ($dry) {
                    $io->writeln('à optimiser : '.$product->getName().' → '.$rel);
                    ++$done;
                    continue;
                }
                try {
                    $out = $this->optimizer->process($file, $this->dir);
                    $images[$i]['src'] = $prefix.$out['name'];
                    $changed = true;
                    ++$done;
                } catch (\Throwable $e) {
                    ++$failed;
                    $io->warning($product->getName().' : '.$rel.' non optimisée ('.$e->getMessage().')');
                }
            }
            if ($changed) {
                $product->setImages($images);
            }
        }
        $this->em->flush();

        $io->success(sprintf('%d photo(s) %s, %d ignorée(s), %d échec(s).', $done, $dry ? 'à optimiser' : 'optimisée(s)', $skipped, $failed));

        return Command::SUCCESS;
    }
}
