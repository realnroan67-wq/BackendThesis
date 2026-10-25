<?php

namespace App\Models;

use Database\Factories\ProviderApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable application snapshot and final decision; runtime writes go through the transactional service. */
class ProviderApplication extends Model
{
    /** @use HasFactory<ProviderApplicationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
