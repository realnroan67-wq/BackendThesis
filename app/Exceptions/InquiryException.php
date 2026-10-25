<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InquiryException extends HttpException
{
    public function __construct(int $status, public readonly string $errorCode, string $message, array $headers = [])
    {
        parent::__construct($status, $message, headers: $headers);
    }
}
