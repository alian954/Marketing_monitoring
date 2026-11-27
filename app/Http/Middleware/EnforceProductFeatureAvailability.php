<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Services\ProductRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceProductFeatureAvailability
{
    public function __construct(private readonly ProductRegistry $productRegistry) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requirements = $this->productRegistry->featureRequirementsForRoute($request->route()?->getName());

        if ($requirements === []) {
            return $next($request);
        }

        foreach ($requirements as $requirement) {
            $manifest = $this->productRegistry->find($requirement['product_code']);

            abort_unless(
                $manifest !== null && $this->productRegistry->declaresFeature(
                    $requirement['product_code'],
                    $requirement['feature_key'],
                ),
                404,
            );

            if ($manifest['lifecycle'] === 'maintenance') {
                abort(503, '此產品目前處於維護狀態。');
            }

            abort_unless($manifest['lifecycle'] === 'active', 404);

            $product = Product::query()
                ->where('product_code', $requirement['product_code'])
                ->first();

            if (! $product instanceof Product) {
                continue;
            }

            if ($product->lifecycle === 'maintenance') {
                abort(503, '此產品目前處於維護狀態。');
            }

            abort_unless($product->lifecycle === 'active', 404);
            abort_unless(
                $product->features()->where('feature_key', $requirement['feature_key'])->exists(),
                503,
                '產品功能目錄尚未同步。',
            );
        }

        return $next($request);
    }
}
