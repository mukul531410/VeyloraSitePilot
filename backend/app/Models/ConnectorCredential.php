<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Credential statuses: primary is current, overlap is temporarily valid, revoked is invalid. */
class ConnectorCredential extends Model
{
    use HasUlids;

    public const STATUS_PRIMARY = 'primary';

    public const STATUS_OVERLAP = 'overlap';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'site_connection_id', 'secret_ciphertext', 'version', 'status',
        'issued_at', 'overlap_expires_at', 'revoked_at',
    ];

    protected $hidden = ['secret_ciphertext'];

    protected $casts = [
        'version' => 'integer',
        'issued_at' => 'datetime',
        'overlap_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SiteConnection::class, 'site_connection_id');
    }
}
