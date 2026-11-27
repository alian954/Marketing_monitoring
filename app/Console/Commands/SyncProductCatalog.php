<?php

namespace App\Console\Commands;

use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:sync')]
#[Description('同步程式碼中已登錄的產品與功能至中央產品目錄。')]
class SyncProductCatalog extends Command
{
    public function handle(ProductCatalogSeeder $seeder): int
    {
        $seeder->run();

        $this->info('中央產品目錄已同步。');

        return self::SUCCESS;
    }
}
