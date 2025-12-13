<?php
namespace Meita\ZatcaEngine\Core;

use Meita\ZatcaEngine\Invoice\InvoiceBuilder;

final class Engine
{
    public function __construct(private readonly Context $context) {}

    public function context(): Context
    {
        return $this->context;
    }

    public function invoice(): InvoiceBuilder
    {
        return new InvoiceBuilder($this->context);
    }
}
