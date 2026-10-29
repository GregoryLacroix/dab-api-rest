<?php

namespace App\Service;

use App\Entity\ContactMessage;
use App\Entity\Order;
use App\Entity\QuoteRequest;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Envoi des e-mails. Un échec d'envoi n'interrompt jamais l'action (inscription, commande) : il est journalisé.
final class AppMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAIL_FROM)%')] private readonly string $from,
        #[Autowire('%env(FRONTEND_URL)%')] private readonly string $frontendUrl,
    ) {
    }

    public function sendVerification(User $user, string $token): bool
    {
        $url = $this->urls->generate('api_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $name = $this->e($user->getName());
        $html = $this->layout("Bonjour $name,", '<p>Merci de votre inscription sur la boutique Les galets d’Ambre. Pour activer votre compte, confirmez votre adresse e-mail :</p>'
            .'<p style="margin:24px 0"><a href="'.$this->e($url).'" style="background:#0f2a6b;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Confirmer mon adresse e-mail</a></p>'
            .'<p style="font-size:13px;color:#6b5f50">Ce lien est valable 24 heures. Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>'.$this->e($url).'</p>'
            .'<p style="font-size:13px;color:#6b5f50">Vous n’êtes pas à l’origine de cette inscription ? Ignorez simplement ce message.</p>');
        $text = "Bonjour {$user->getName()},\n\nPour activer votre compte Les galets d’Ambre, confirmez votre adresse e-mail :\n$url\n\nCe lien est valable 24 heures. Si vous n'êtes pas à l'origine de cette inscription, ignorez ce message.\n";

        return $this->send($user->getEmail(), $user->getName(), 'Confirmez votre adresse e-mail', $text, $html);
    }

    /** @param array{0: string, 1: string}|null $invoicePdf [contenu, nom du fichier] : facture jointe (commande payée) */
    public function sendOrderConfirmation(Order $order, ?array $invoicePdf = null): bool
    {
        $paid = $order->getPaidAt() !== null;
        [$lines, $textLines] = $this->orderLines($order);
        $total = $this->eur($order->getTotalCents() / 100);
        $address = $this->addressLines($order->getShippingAddress());

        $intro = $paid ? 'Nous avons bien reçu votre paiement. Votre commande <strong>'.$order->getReference().'</strong> est confirmée.'
            : 'Votre commande <strong>'.$order->getReference().'</strong> est bien enregistrée.';
        $html = $this->layout('Merci pour votre commande, '.$this->e($order->getCustomerName()).' !',
            '<p>'.$intro.'</p><table style="border-collapse:collapse;font-size:15px">'.$lines
            .'<tr><td style="padding-top:10px"><strong>Total</strong></td><td style="text-align:right;padding-top:10px"><strong>'.$total.'</strong></td></tr></table>'
            .($address === [] ? '' : '<p style="margin-top:20px"><strong>Adresse de livraison</strong><br>'.implode('<br>', array_map($this->e(...), $address)).'</p>')
            .($paid ? '<p style="font-size:13px;color:#6b5f50">Chaque galet étant peint à la main, nous vous écrirons dès l’envoi de votre colis.</p>' : ''));
        $text = "Merci pour votre commande, {$order->getCustomerName()} !\n"
            .($paid ? "Nous avons bien reçu votre paiement. Commande {$order->getReference()} confirmée.\n" : "Commande {$order->getReference()}\n")
            ."\n$textLines\nTotal : $total\n"
            .($address === [] ? '' : "\nAdresse de livraison :\n".implode("\n", $address)."\n");

        return $this->send($order->getCustomerEmail(), $order->getCustomerName(), 'Votre commande '.$order->getReference(), $text, $html, null, $invoicePdf);
    }

    // Prévient la boutique qu'une commande vient d'être payée (à préparer et expédier)
    public function sendNewOrderNotification(Order $order, string $shopEmail): bool
    {
        [$lines, $textLines] = $this->orderLines($order);
        $total = $this->eur($order->getTotalCents() / 100);
        $address = $this->addressLines($order->getShippingAddress());
        $html = $this->layout('Nouvelle commande payée : '.$order->getReference(),
            '<p><strong>'.$this->e($order->getCustomerName()).'</strong> &lt;'.$this->e($order->getCustomerEmail()).'&gt;</p>'
            .'<table style="border-collapse:collapse;font-size:15px">'.$lines
            .'<tr><td style="padding-top:10px"><strong>Total payé</strong></td><td style="text-align:right;padding-top:10px"><strong>'.$total.'</strong></td></tr></table>'
            .($address === [] ? '' : '<p style="margin-top:20px"><strong>À expédier à</strong><br>'.implode('<br>', array_map($this->e(...), $address)).'</p>'));
        $text = "Nouvelle commande payée {$order->getReference()}\n{$order->getCustomerName()} <{$order->getCustomerEmail()}>\n\n$textLines\nTotal payé : $total\n"
            .($address === [] ? '' : "\nÀ expédier à :\n".implode("\n", $address)."\n");

        return $this->send($shopEmail, 'Les galets d’Ambre', '[Commande payée] '.$order->getReference().' – '.$total, $text, $html,
            new Address($order->getCustomerEmail(), $order->getCustomerName()));
    }

    /** @return array{0: string, 1: string} lignes HTML et texte brut du récapitulatif */
    private function orderLines(Order $order): array
    {
        $html = '';
        $text = '';
        foreach ($order->getItems() as $i) {
            $html .= '<tr><td style="padding:4px 12px 4px 0">'.(int) $i['qty'].' × '.$this->e((string) $i['name']).'</td><td style="text-align:right">'.$this->eur($i['price'] * $i['qty']).'</td></tr>';
            $text .= "- {$i['qty']} x {$i['name']} : ".$this->eur($i['price'] * $i['qty'])."\n";
        }

        return [$html, $text];
    }

    /** @return string[] */
    private function addressLines(?array $a): array
    {
        if ($a === null) {
            return [];
        }
        $cityLine = trim(($a['postalCode'] ?? '').' '.($a['city'] ?? ''));

        return array_values(array_filter([$a['name'] ?? null, $a['line1'] ?? null, $a['line2'] ?? null, $cityLine, $a['state'] ?? null, $a['country'] ?? null],
            static fn ($l) => \is_string($l) && trim($l) !== ''));
    }

    // Message reçu par la boutique (Répondre = répondre directement au visiteur grâce à Reply-To)
    public function sendContactNotification(ContactMessage $m, string $shopEmail): bool
    {
        $html = $this->layout('Nouveau message : '.$this->e($m->getSubjectLabel()),
            '<p><strong>'.$this->e($m->getName()).'</strong> &lt;'.$this->e($m->getEmail()).'&gt;</p>'
            .'<p style="white-space:pre-line">'.$this->e($m->getMessage()).'</p>'
            .'<p style="font-size:13px;color:#6b5f50">Répondez simplement à cet e-mail pour écrire au visiteur.</p>');
        $text = "Nouveau message ({$m->getSubjectLabel()})\nDe : {$m->getName()} <{$m->getEmail()}>\n\n{$m->getMessage()}\n";

        return $this->send($shopEmail, 'Les galets d’Ambre', '[Contact] '.$m->getSubjectLabel().' – '.$m->getName(), $text, $html,
            new Address($m->getEmail(), $m->getName()));
    }

    // Accusé de réception pour le visiteur
    public function sendContactAck(ContactMessage $m): bool
    {
        $html = $this->layout('Merci '.$this->e($m->getName()).', nous avons bien reçu votre message',
            '<p>Nous vous répondons en général sous 48 h ouvrées.</p><p style="font-size:13px;color:#6b5f50">Pour mémoire, voici votre message :</p>'
            .'<p style="white-space:pre-line;border-left:3px solid #e6d9c3;padding-left:12px">'.$this->e($m->getMessage()).'</p>');
        $text = "Merci {$m->getName()}, nous avons bien reçu votre message. Nous vous répondons en général sous 48 h ouvrées.\n\nVotre message :\n{$m->getMessage()}\n";

        return $this->send($m->getEmail(), $m->getName(), 'Nous avons bien reçu votre message', $text, $html);
    }

    // ---- Devis de fresque -------------------------------------------------------------------------------------------------

    /** Résumé de la demande, en HTML (pour le client et pour la boutique). */
    private function quoteSummary(QuoteRequest $q, bool $withPhotos): string
    {
        $size = ($q->getWidthCm() && $q->getHeightCm()) ? $q->getWidthCm().' × '.$q->getHeightCm().' cm' : 'non précisées';
        $rows = [
            'Type de projet' => QuoteRequest::PROJECT_LABELS[$q->getProjectType()] ?? $q->getProjectType(),
            'Dimensions du mur' => $size,
            'Lieu' => $q->getLocation() ?: 'non précisé',
            'Budget' => $q->getBudget() ? (QuoteRequest::BUDGET_LABELS[$q->getBudget()] ?? $q->getBudget()) : 'non précisé',
            'Pour quand' => $q->getDesiredBy() ?: 'non précisé',
        ];
        $html = '<table style="border-collapse:collapse;font-size:14px">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td style="padding:3px 14px 3px 0;color:#6b5f50">'.$label.'</td><td>'.$this->e((string) $value).'</td></tr>';
        }
        $html .= '</table><p style="white-space:pre-wrap;font-size:14px;border-left:3px solid #e6d9c3;padding-left:12px">'.$this->e($q->getDescription()).'</p>';
        if ($withPhotos && $q->getPhotos() !== []) {
            $html .= '<p style="font-size:14px"><strong>Photos du mur :</strong><br>';
            foreach ($q->getPhotos() as $i => $url) {
                $html .= '<a href="'.$this->e((string) $url).'">Photo '.($i + 1).'</a> ';
            }
            $html .= '</p>';
        }

        return $html;
    }

    /** Accusé de réception envoyé à la personne qui demande un devis. */
    public function sendQuoteAck(QuoteRequest $q): bool
    {
        $html = $this->layout('Demande de devis '.$q->getReference().' bien reçue',
            '<p>Bonjour '.$this->e($q->getName()).', merci pour votre demande de fresque. Je l’étudie et je vous réponds avec un devis, en général sous <strong>3 jours ouvrés</strong>.</p>'
            .$this->quoteSummary($q, false)
            .'<p style="font-size:13px;color:#6b5f50">Une précision à ajouter ? Répondez simplement à cet e-mail.</p>');
        $text = "Bonjour {$q->getName()},\n\nMerci pour votre demande de fresque ({$q->getReference()}). Je l'étudie et je vous réponds avec un devis, en général sous 3 jours ouvrés.\n\n"
            .'Type : '.(QuoteRequest::PROJECT_LABELS[$q->getProjectType()] ?? '')."\n".$q->getDescription()."\n";

        return $this->send($q->getEmail(), $q->getName(), 'Votre demande de devis '.$q->getReference(), $text, $html);
    }

    /** Prévient la boutique d'une nouvelle demande (la réponse va directement au client). */
    public function sendQuoteNotification(QuoteRequest $q, string $shopEmail): bool
    {
        $html = $this->layout('Nouvelle demande de devis '.$q->getReference(),
            '<p><strong>'.$this->e($q->getName()).'</strong> &lt;'.$this->e($q->getEmail()).'&gt;'.($q->getPhone() ? ' — '.$this->e($q->getPhone()) : '').'</p>'
            .$this->quoteSummary($q, true));
        $text = "Nouvelle demande de devis {$q->getReference()}\n{$q->getName()} <{$q->getEmail()}>\n\n{$q->getDescription()}\n\n".implode("\n", $q->getPhotos())."\n";

        return $this->send($shopEmail, 'Les galets d’Ambre', '[Devis] '.$q->getReference().' – '.$q->getName(), $text, $html, new Address($q->getEmail(), $q->getName()));
    }

    /** Le devis chiffré, avec le lien de la page où régler l'acompte. */
    public function sendQuote(QuoteRequest $q): bool
    {
        $amount = $this->eur(($q->getAmountCents() ?? 0) / 100);
        $deposit = ($q->depositCents() ?? 0) > 0 ? $this->eur($q->depositCents() / 100) : null;
        $url = rtrim($this->frontendUrl, '/').($q->getLang() === 'en' ? '/en/quote/' : '/devis/').$q->getPayToken();

        $html = $this->layout('Votre devis '.$q->getReference(),
            '<p>Bonjour '.$this->e($q->getName()).', voici mon devis pour votre projet de fresque.</p>'
            .'<p style="font-size:20px"><strong>'.$amount.'</strong> <span style="font-size:13px;color:#6b5f50">TTC</span></p>'
            .($q->getQuoteMessage() ? '<p style="white-space:pre-wrap;border-left:3px solid #e6d9c3;padding-left:12px">'.$this->e($q->getQuoteMessage()).'</p>' : '')
            .($deposit !== null
                ? '<p>Pour réserver votre créneau, un acompte de <strong>'.$deposit.'</strong> ('.$q->getDepositPercent().' %) est à régler en ligne :</p>'
                  .'<p style="margin:24px 0"><a href="'.$this->e($url).'" style="background:#0f2a6b;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Voir le devis et régler l’acompte</a></p>'
                : '<p><a href="'.$this->e($url).'">Voir le devis en ligne</a></p>')
            .'<p style="font-size:13px;color:#6b5f50">Une question, un ajustement à demander ? Répondez simplement à cet e-mail.</p>');
        $text = "Bonjour {$q->getName()},\n\nVoici mon devis pour votre projet de fresque ({$q->getReference()}) : $amount TTC.\n"
            .($q->getQuoteMessage() ? "\n{$q->getQuoteMessage()}\n" : '')
            .($deposit !== null ? "\nAcompte à régler en ligne : $deposit ({$q->getDepositPercent()} %).\n" : '')
            ."\nVoir le devis : $url\n";

        return $this->send($q->getEmail(), $q->getName(), 'Votre devis '.$q->getReference(), $text, $html);
    }

    /** Acompte reçu : confirmation au client et alerte à la boutique. */
    public function sendDepositReceived(QuoteRequest $q, string $shopEmail): bool
    {
        $deposit = $this->eur(($q->depositCents() ?? 0) / 100);
        $html = $this->layout('Acompte reçu, merci !',
            '<p>Bonjour '.$this->e($q->getName()).', j’ai bien reçu votre acompte de <strong>'.$deposit.'</strong> pour la fresque <strong>'.$q->getReference().'</strong>. Votre projet est réservé.</p>'
            .'<p>Je vous écris très vite pour convenir ensemble des détails et de la date.</p>');
        $ok = $this->send($q->getEmail(), $q->getName(), 'Acompte reçu – '.$q->getReference(), "Bonjour {$q->getName()},\n\nJ'ai bien reçu votre acompte de $deposit pour la fresque {$q->getReference()}. Votre projet est réservé : je vous écris très vite pour convenir des détails et de la date.\n", $html);

        $this->send($shopEmail, 'Les galets d’Ambre', '[Acompte payé] '.$q->getReference().' – '.$deposit,
            "Acompte de $deposit reçu pour le devis {$q->getReference()} ({$q->getName()} <{$q->getEmail()}>).\n",
            $this->layout('Acompte payé : '.$q->getReference(), '<p><strong>'.$this->e($q->getName()).'</strong> &lt;'.$this->e($q->getEmail()).'&gt; a réglé l’acompte de <strong>'.$deposit.'</strong>.</p>'),
            new Address($q->getEmail(), $q->getName()));

        return $ok;
    }

    // « Mot de passe oublié » : lien vers la page du site ; le jeton est dans le FRAGMENT (#…), jamais envoyé à un serveur
    public function sendPasswordReset(User $user, string $token, string $lang = 'fr'): bool
    {
        $url = rtrim($this->frontendUrl, '/').($lang === 'en' ? '/en/reset-password' : '/nouveau-mot-de-passe').'#token='.$token;
        $name = $this->e($user->getName());
        $html = $this->layout("Bonjour $name,", '<p>Vous avez demandé à réinitialiser le mot de passe de votre compte Les galets d’Ambre.</p>'
            .'<p style="margin:24px 0"><a href="'.$this->e($url).'" style="background:#0f2a6b;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Choisir un nouveau mot de passe</a></p>'
            .'<p style="font-size:13px;color:#6b5f50">Ce lien est valable 1 heure et ne peut servir qu’une fois. Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>'.$this->e($url).'</p>'
            .'<p style="font-size:13px;color:#6b5f50">Vous n’êtes pas à l’origine de cette demande ? Ignorez ce message : votre mot de passe reste inchangé.</p>');
        $text = "Bonjour {$user->getName()},\n\nPour choisir un nouveau mot de passe (lien valable 1 heure, usage unique) :\n$url\n\nVous n'êtes pas à l'origine de cette demande ? Ignorez ce message : votre mot de passe reste inchangé.\n";

        return $this->send($user->getEmail(), $user->getName(), 'Réinitialisation de votre mot de passe', $text, $html);
    }

    // Colis expédié : transporteur, numéro de suivi et lien (si renseignés dans le back-office)
    public function sendShipped(Order $order): bool
    {
        [$lines, $textLines] = $this->orderLines($order);
        $address = $this->addressLines($order->getShippingAddress());
        $carrier = (string) $order->getCarrier();
        $number = (string) $order->getTrackingNumber();
        $url = (string) $order->getTrackingUrl();

        $track = '';
        $trackText = '';
        if ($number !== '') {
            $track = '<p><strong>Transporteur :</strong> '.$this->e($carrier !== '' ? $carrier : 'colis').'<br><strong>Numéro de suivi :</strong> '.$this->e($number).'</p>';
            $trackText = 'Transporteur : '.($carrier !== '' ? $carrier : 'colis')."\nNuméro de suivi : $number\n";
            if ($url !== '') {
                $track .= '<p style="margin:24px 0"><a href="'.$this->e($url).'" style="background:#0f2a6b;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Suivre mon colis</a></p>';
                $trackText .= "Suivre mon colis : $url\n";
            }
        }
        $html = $this->layout('Votre commande est en route !',
            '<p>Bonjour '.$this->e($order->getCustomerName()).', votre commande <strong>'.$order->getReference().'</strong> vient d’être expédiée.</p>'.$track
            .'<table style="border-collapse:collapse;font-size:15px">'.$lines.'</table>'
            .($address === [] ? '' : '<p style="margin-top:20px"><strong>Adresse de livraison</strong><br>'.implode('<br>', array_map($this->e(...), $address)).'</p>')
            .'<p style="font-size:13px;color:#6b5f50">Chaque galet est emballé avec soin. Un problème à la réception ? Répondez simplement à cet e-mail.</p>');
        $text = "Bonjour {$order->getCustomerName()},\n\nVotre commande {$order->getReference()} vient d'être expédiée.\n\n$trackText\n$textLines"
            .($address === [] ? '' : "\nAdresse de livraison :\n".implode("\n", $address)."\n");

        return $this->send($order->getCustomerEmail(), $order->getCustomerName(), 'Votre commande '.$order->getReference().' est expédiée', $text, $html);
    }

    // Remboursement effectué
    /** @param array{0: string, 1: string}|null $creditNotePdf [contenu, nom du fichier] : avoir joint */
    public function sendRefund(Order $order, ?array $creditNotePdf = null): bool
    {
        $amount = $this->eur($order->getTotalCents() / 100);
        $html = $this->layout('Votre commande a été remboursée',
            '<p>Bonjour '.$this->e($order->getCustomerName()).', nous avons remboursé votre commande <strong>'.$order->getReference().'</strong> : <strong>'.$amount.'</strong>.</p>'
            .'<p>Le montant revient sur votre moyen de paiement d’origine sous 5 à 10 jours ouvrés, selon votre banque.</p>'
            .($creditNotePdf !== null ? '<p>Vous trouverez l’avoir correspondant en pièce jointe.</p>' : '')
            .'<p style="font-size:13px;color:#6b5f50">Une question ? Répondez simplement à cet e-mail.</p>');
        $text = "Bonjour {$order->getCustomerName()},\n\nNous avons remboursé votre commande {$order->getReference()} : $amount.\nLe montant revient sur votre moyen de paiement d'origine sous 5 à 10 jours ouvrés, selon votre banque.\n";

        return $this->send($order->getCustomerEmail(), $order->getCustomerName(), 'Remboursement de votre commande '.$order->getReference(), $text, $html, null, $creditNotePdf);
    }

    /** @param array{0: string, 1: string}|null $pdf [contenu, nom du fichier] : PDF joint au message */
    private function send(string $to, string $toName, string $subject, string $text, string $html, ?Address $replyTo = null, ?array $pdf = null): bool
    {
        try {
            $email = (new Email())
                ->from(new Address($this->from, 'Les galets d’Ambre'))
                ->to(new Address($to, $toName))
                ->subject($subject)->text($text)->html($html);
            if ($replyTo !== null) {
                $email->replyTo($replyTo);
            }
            if ($pdf !== null) {
                $email->attach($pdf[0], $pdf[1], 'application/pdf');
            }
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Envoi d’e-mail impossible : '.$e->getMessage(), ['to' => $to, 'subject' => $subject]);

            return false;
        }
    }

    private function layout(string $title, string $body): string
    {
        return '<div style="background:#faf6ef;padding:24px;font-family:Arial,sans-serif;color:#4a4034"><div style="max-width:560px;margin:auto;background:#fff;border:1px solid #e6d9c3;border-radius:12px;padding:28px">'
            .'<h1 style="font-size:22px;color:#0f2a6b;margin:0 0 16px">'.$title.'</h1>'.$body.'</div></div>';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function eur(int|float $n): string
    {
        return number_format((float) $n, 2, ',', ' ').' €';
    }
}
