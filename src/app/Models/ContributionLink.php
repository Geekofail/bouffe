<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Le lien « qui apporte quoi » d'une réception ou d'un séjour (lot 42, 42.2), pour ceux qui n'ont
 * pas Bouffe. Un seul lien actif par évènement ; valable jusqu'à quelques jours après, révocable.
 * L'empreinte sert à le retrouver ; le jeton est gardé **chiffré** pour pouvoir recopier l'adresse.
 *
 * @property int $id
 * @property int|null $meal_occasion_id
 * @property int|null $stay_id
 * @property string $token_hash
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['household_id', 'meal_occasion_id', 'stay_id', 'token_hash', 'token', 'expires_at', 'revoked_at', 'views', 'last_viewed_at', 'created_by'])]
class ContributionLink extends Model
{
    use Concerns\BelongsToHousehold;

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_viewed_at' => 'datetime', 'views' => 'integer'];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
