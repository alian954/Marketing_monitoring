<?php

namespace Tests\Unit;

use App\Services\ProductRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProductRegistryTest extends TestCase
{
    public function test_it_loads_the_current_product_manifests_and_declared_features(): void
    {
        /** @var array<string, array<string, mixed>> $manifests */
        $manifests = require dirname(__DIR__, 2).'/config/products.php';
        $registry = new ProductRegistry($manifests);

        $this->assertSame(['website-monitoring', 'marketing-insights'], array_keys($registry->all()));
        $this->assertSame('網站可靠度監測', $registry->find('website-monitoring')['name']);
        $this->assertTrue($registry->declaresFeature('marketing-insights', 'marketing.analytics.ga4'));
        $this->assertFalse($registry->declaresFeature('marketing-insights', 'marketing.billing'));
        $this->assertFalse($registry->declaresFeature('unknown-product', 'marketing.analytics.ga4'));
    }

    public function test_a_new_product_can_be_registered_from_its_manifest_without_registry_changes(): void
    {
        /** @var array<string, array<string, mixed>> $manifests */
        $manifests = require dirname(__DIR__, 2).'/config/products.php';
        $manifests['customer-management'] = [
            'product_code' => 'customer-management',
            'name' => '客戶管理',
            'version' => '0.1.0',
            'manifest_version' => 1,
            'lifecycle' => 'active',
            'entry_route' => 'clients.index',
            'feature_keys' => ['customers.directory'],
            'feature_labels' => ['customers.directory' => '客戶目錄'],
        ];

        $registry = new ProductRegistry($manifests);

        $this->assertSame('客戶管理', $registry->find('customer-management')['name']);
        $this->assertTrue($registry->declaresFeature('customer-management', 'customers.directory'));
    }

    public function test_it_rejects_a_manifest_whose_declared_product_code_does_not_match_its_key(): void
    {
        $registry = new ProductRegistry([
            'website-monitoring' => [
                'product_code' => 'other-product',
                'name' => '網站可靠度監測',
                'version' => '0.1.0',
                'manifest_version' => 1,
                'lifecycle' => 'active',
                'entry_route' => 'monitors.index',
                'feature_keys' => [],
                'feature_labels' => [],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $registry->all();
    }

    public function test_it_rejects_duplicate_feature_keys(): void
    {
        $registry = new ProductRegistry([
            'website-monitoring' => [
                'product_code' => 'website-monitoring',
                'name' => '網站可靠度監測',
                'version' => '0.1.0',
                'manifest_version' => 1,
                'lifecycle' => 'active',
                'entry_route' => 'monitors.index',
                'feature_keys' => ['monitoring.targets', 'monitoring.targets'],
                'feature_labels' => ['monitoring.targets' => '監測目標'],
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $registry->all();
    }
}
