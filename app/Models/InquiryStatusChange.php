<?php

namespace App\Models;

use Database\Factories\InquiryStatusChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal immutable audit; no API exposes or edits these records. */
#[Guarded(['*'])]
class InquiryStatusChange extends Model
{
    /** @use HasFactory<InquiryStatusChangeFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Inquiry, $this> */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
