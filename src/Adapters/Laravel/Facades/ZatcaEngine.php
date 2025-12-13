<?php
namespace Meita\ZatcaEngine\Adapters\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

final class ZatcaEngine extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'zatca-engine.manager';
    }
}
