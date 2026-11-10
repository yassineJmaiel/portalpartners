<?php

namespace App\Portal;

/* A job that must not be tried again. */
class JobGaveUp extends \RuntimeException
{
}
