<?php

namespace Database\Seeders;

use App\Saas\Support\DatabaseReadiness;
use Beike\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     * @throws \Throwable
     */
    public function run()
    {
        // `db:seed` is also invoked by interrupted provisioning retries. Once
        // a complete base store exists, the legacy seeders below must not run
        // again because several of them intentionally rebuild lookup tables.
        // Re-running those seeders would delete merchant settings and catalog
        // data and can fail on plugin foreign keys in MySQL.
        if (DatabaseReadiness::isSeeded(DB::getDefaultConnection())) {
            $this->command?->info('LONGOS base data already exists; destructive reseeding was skipped.');

            return;
        }

        $this->call([
            BrandsSeeder::class,
            CategoriesSeeder::class,
            CountriesSeeder::class,
            CurrenciesSeeder::class,
            CustomerGroupsSeeder::class,
            LanguagesSeeder::class,
            PagesSeeder::class,
            PageCategoriesSeeder::class,
            PluginsSeeder::class,
            ZonesSeeder::class,
            ProductsSeeder::class,
            AttributesSeeder::class,
            SettingsSeeder::class,
            ThemeSeeder::class,
            RmaReasonsSeeder::class,
        ]);
    }

    /**
     * @throws \Throwable
     */
    private function duplicate(): void
    {
        $baseProduct = Product::with(['productCategories', 'descriptions', 'skus'])->findOrFail(2);
        $newProduct  = $baseProduct->replicate();
        $newProduct->saveOrFail();
        $newProductId = $newProduct->id;

        if ($newProductId) {
            $relations = $baseProduct->getRelations();
            foreach ($relations as $relation) {
                foreach ($relation as $relationRecord) {
                    $newRelationship             = $relationRecord->replicate();
                    $newRelationship->product_id = $newProductId;
                    $newRelationship->push();
                }
            }
        }
    }
}
