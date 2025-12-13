<?php
namespace Meita\ZatcaEngine\Validators;

use RuntimeException;

final class Phase2ValidationException extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct("ZATCA Phase 2 validation failed.");
    }
}
