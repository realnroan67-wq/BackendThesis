<?php

return [
    // Default-off release control. Only an explicit boolean true enables creation.
    'registration_enabled' => env('REGISTRATION_ENABLED', false) === true,
    'inquiry_submission_enabled' => env('INQUIRY_SUBMISSION_ENABLED', false) === true,
];
