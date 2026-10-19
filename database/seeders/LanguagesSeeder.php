<?php

/**
 * LanguagesSeeder.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-09-05 19:42:42
 * @modified   2022-09-05 19:42:42
 */

namespace Database\Seeders;

use Beike\Models\Language;
use Illuminate\Database\Seeder;

class LanguagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $items = $this->getItems();

        foreach ($items as $item) {
            Language::query()->firstOrCreate(['code' => $item['code']], $item);
        }
    }

    public function getItems()
    {
        return [
            [
                'id'         => 1,
                'name'       => 'English',
                'code'       => 'en',
                'locale'     => '',
                'image'      => 'image/catalog/demo/services-icon/3.png',
                'sort_order' => 1,
                'active'     => 1,
            ],
        ];
    }
}
