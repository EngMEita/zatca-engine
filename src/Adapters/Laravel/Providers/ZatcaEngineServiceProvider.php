<?php
namespace Meita\ZatcaEngine\Adapters\Laravel\Providers;

use Illuminate\Support\ServiceProvider;
use Meita\ZatcaEngine\Core\Context;
use Meita\ZatcaEngine\Core\Engine;

final class ZatcaEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../../config/zatca-engine.php', 'zatca-engine');

        $this->app->singleton('zatca-engine.manager', function () {
            return new class {
                public function company(string $key = 'default'): Engine
                {
                    $companies = config('zatca-engine.companies', []);
                    $cfg = $companies[$key] ?? $companies['default'] ?? null;
                    if (!$cfg) {
                        throw new \InvalidArgumentException("ZATCA company profile not found: {$key}");
                    }
                    $ctx = Context::fromArray(array_merge(['company_key'=>$key], $cfg));
                    return new Engine($ctx);
                }
            };
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../../../config/zatca-engine.php' => config_path('zatca-engine.php'),
        ], 'zatca-engine-config');
    }
}
