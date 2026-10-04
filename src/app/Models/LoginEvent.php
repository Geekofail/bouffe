<?php

namespace App\Models;

use App\Support\DeviceLabel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal des connexions d'un compte (lot 25, 27.5). Conservé BOUFFE_LOGIN_JOURNAL_DAYS jours.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device
 * @property \Illuminate\Support\Carbon|null $created_at
 */
#[Fillable(['user_id', 'type', 'ip_address', 'user_agent', 'device', 'created_at'])]
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    /** type => [libellé, icône, gravité (ok · warn · error)] */
    public const TYPES = [
        'login' => ['Connexion', 'check', 'ok'],
        'failed' => ['Mot de passe refusé', 'warning', 'warn'],
        'two_factor_failed' => ['Code de vérification refusé', 'warning', 'warn'],
        'recovery_code' => ['Connexion avec un code de secours', 'lock', 'warn'],
        'locked' => ['Trop d\'essais : connexion bloquée un moment', 'warning', 'error'],
        'password_reset' => ['Mot de passe réinitialisé', 'lock', 'ok'],
        'password_changed' => ['Mot de passe changé', 'lock', 'ok'],
        'two_factor_enabled' => ['Double authentification activée', 'lock', 'ok'],
        'two_factor_disabled' => ['Double authentification désactivée', 'lock', 'warn'],
        'revoked' => ['Appareil déconnecté', 'logout', 'ok'],
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    public function icon(): string
    {
        return self::TYPES[$this->type][1] ?? 'info';
    }

    public function severity(): string
    {
        return self::TYPES[$this->type][2] ?? 'ok';
    }

    public function deviceLabel(): string
    {
        return DeviceLabel::describe($this->user_agent);
    }
}
