<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class SiteConnection extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'status',
        'connector_version',
        'credential_ciphertext',
        'credential_version',
        'connector_token_hash',
        'connection_intent',
        'intent_expires_at',
        'connected_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'intent_expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = [
        'credential_ciphertext',
        'connector_token_hash',
        'connection_intent',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(ConnectorCapability::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(ConnectorHeartbeat::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->revoked_at === null;
    }

    public function generateIntent(int $ttlMinutes = 30): string
    {
        $intent = \Illuminate\Support\Str::random(32);

        $this->update([
            'connection_intent' => $intent,
            'intent_expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $intent;
    }

    public function consumeIntent(string $intent): bool
    {
        if ($this->connection_intent !== $intent) {
            return false;
        }

        if ($this->intent_expires_at && $this->intent_expires_at->isPast()) {
            return false;
        }

        $this->update([
            'connection_intent' => null,
            'intent_expires_at' => null,
        ]);

        return true;
    }

    public function activate(string $connectorVersion): string
    {
        $token = \Illuminate\Support\Str::random(64);

        $this->update([
            'status' => 'active',
            'connector_version' => $connectorVersion,
            'connector_token_hash' => hash('sha256', $token),
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $token;
    }

    public function revoke(): void
    {
        $this->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'credential_ciphertext' => null,
            'credential_version' => $this->credential_version + 1,
        ]);
    }

    public function encryptCredentials(string $plaintext): void
    {
        $this->update([
            'credential_ciphertext' => Crypt::encryptString($plaintext),
            'credential_version' => $this->credential_version + 1,
        ]);
    }

    public function decryptCredentials(): ?string
    {
        if (! $this->credential_ciphertext) {
            return null;
        }

        return Crypt::decryptString($this->credential_ciphertext);
    }
}
