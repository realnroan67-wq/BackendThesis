<?php

namespace App\Models;

use Database\Factories\OnboardingAuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnboardingAuditEvent extends Model
{
    /** @use HasFactory<OnboardingAuditEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
