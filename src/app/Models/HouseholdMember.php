<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Appartenance d'un compte à un foyer, avec son rôle dans ce foyer (25.3).
 *
 * @property int $household_id
 * @property int $user_id
 * @property UserRole $role
 */
class HouseholdMember extends Pivot
{
    protected $table = 'household_user';

    public $incrementing = true;

    protected function casts(): array
    {
        return ['role' => UserRole::class, 'last_active_at' => 'datetime'];
    }
}
