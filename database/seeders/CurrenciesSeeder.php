<?php

/**
 * CurrenciesSeeder.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-09-05 19:42:42
 * @modified   2022-09-05 19:42:42
 */

namespace Database\Seeders;

use Beike\Models\Currency;
use Illuminate\Database\Seeder;

class CurrenciesSeeder extends Seeder
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
            $code = $item['code'];
            Currency::query()->firstOrCreate(['code' => $code], $item);
        }
    }

    public function getItems()
    {
        return [
            [
                'id'            => 1,
                'name'          => 'USD',
                'code'          => 'USD',
                'symbol_left'   => '$',
                'symbol_right'  => '',
                'decimal_place' => 2,
                'value'         => 1,
                'active'        => 1,
            ],
        ];
    }
}
