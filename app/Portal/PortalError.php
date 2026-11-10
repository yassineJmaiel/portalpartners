<?php

namespace App\Portal;

/* A refusal a person can act on. CrmError is the same thing, said by the CRM. */
class PortalError extends \RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $details = [], public array $extra = [])
    {
        parent::__construct($message);
    }
}
