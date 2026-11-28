<?php

namespace App\Console\Commands;

use App\Services\LegacyEntitlementMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:entitlement-readiness {--organization= : 只檢查指定的組織 ID}')]
#[Description('列出尚未啟用訂閱授權的組織，若啟用後會失去哪些產品功能（只讀取，不修改資料）。')]
class ReportEntitlementReadiness extends Command
{
    public function handle(LegacyEntitlementMigration $migration): int
    {
        $organizationOption = $this->option('organization');
        $organizations = $migration->pendingOrganizations($organizationOption ? (int) $organizationOption : null);

        if ($organizations->isEmpty()) {
            $this->info('沒有尚未啟用訂閱授權的組織。');

            return self::SUCCESS;
        }

        $rows = [];
        $readyCount = 0;

        foreach ($organizations as $organization) {
            $missingFeatures = $migration->missingFeatureLabels($organization);

            if ($missingFeatures === []) {
                $readyCount++;
            }

            $rows[] = [
                $organization->getKey(),
                $organization->name,
                $missingFeatures === [] ? '可啟用' : '會失去 '.count($missingFeatures).' 項',
                implode('、', $missingFeatures) ?: '—',
            ];
        }

        $this->table(['組織 ID', '組織', '啟用後', '會失去的功能'], $rows);
        $this->info("共 {$organizations->count()} 個組織，其中 {$readyCount} 個啟用後不會失去功能。");

        return self::SUCCESS;
    }
}
