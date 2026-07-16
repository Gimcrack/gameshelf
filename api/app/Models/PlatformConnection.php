<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use Database\Factories\PlatformConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'platform',
    'external_account_id',
    'auth_token',
    'refresh_token',
    'token_expires_at',
    'last_synced_at',
    'status',
])]
#[Hidden(['auth_token', 'refresh_token'])]
class PlatformConnection extends Model
{
    /** @use HasFactory<PlatformConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // V2: tokens encrypted at rest.
            'auth_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'status' => ConnectionStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ownedGames(): HasMany
    {
        return $this->hasMany(OwnedGame::class);
    }

    public function familyMember(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FamilyMember::class);
    }
}
