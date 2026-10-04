<?php

namespace App\Mail;

use App\Models\User;
use App\Support\DeviceLabel;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail court de Bouffe (lot 25) : titre, quelques paragraphes, un bouton. Sert à la
 * réinitialisation du mot de passe, aux alertes de connexion, à la sauvegarde de la semaine et
 * aux erreurs signalées à l'administrateur.
 */
class Notice extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $paragraphs  texte brut (échappé à l'affichage)
     * @param  array{0: string, 1: string}|null  $action  [libellé, adresse]
     */
    public function __construct(
        public string $subjectLine,
        public string $title,
        public array $paragraphs,
        public ?array $action = null,
        public ?string $footer = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bouffe — '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notice', text: 'mail.notice-text');
    }

    /* ================================================================ Messages */

    /** 27.6 — lien valable config('auth.passwords.users.expire') minutes. */
    public static function passwordReset(User $user, string $token): self
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return new self(
            'réinitialiser votre mot de passe',
            'Nouveau mot de passe',
            [
                "Bonjour {$user->name},",
                'Quelqu\'un (vous, sans doute) a demandé à changer le mot de passe de votre compte Bouffe.',
                "Le lien ci-dessous est valable {$minutes} minutes et ne sert qu'une fois.",
            ],
            ['Choisir un nouveau mot de passe', route('password.reset', ['token' => $token, 'email' => $user->email])],
            'Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre mot de passe ne change pas.',
        );
    }

    /** 27.5 — connexion depuis un appareil jamais vu sur ce compte. */
    public static function newDevice(User $user, ?string $userAgent, ?string $ip): self
    {
        return new self(
            'nouvelle connexion à votre compte',
            'Nouvelle connexion',
            [
                "Bonjour {$user->name},",
                'Votre compte Bouffe vient d\'être ouvert depuis un appareil qu\'il ne connaissait pas :',
                DeviceLabel::describe($userAgent).($ip ? " — adresse {$ip}" : '').' — le '.now()->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm').'.',
                'Si c\'est vous, il n\'y a rien à faire.',
            ],
            ['Voir mes appareils', route('account.show').'#appareils'],
            'Si ce n\'est pas vous : changez votre mot de passe tout de suite, puis déconnectez les autres appareils depuis « Mon compte ».',
        );
    }
}
