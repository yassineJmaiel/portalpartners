<?php

namespace App\Portal;

/* The role cannot read this kind of record: the answer says so, and nothing is read. */
class CopilotStop extends \RuntimeException
{
    public function __construct(public array $answer)
    {
        parent::__construct('stop');
    }
}
