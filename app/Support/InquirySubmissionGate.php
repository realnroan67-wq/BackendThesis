<?php

namespace App\Support;

use App\Exceptions\InquiryException;

/** Release control only; participant authorization and eligibility remain authoritative. */
class InquirySubmissionGate
{
    public static function ensureEnabled(): void
    {
        if (config('features.inquiry_submission_enabled', false) !== true) {
            throw new InquiryException(503, 'INQUIRY_SUBMISSION_DISABLED', 'Inquiry submission is currently unavailable.');
        }
    }
}
