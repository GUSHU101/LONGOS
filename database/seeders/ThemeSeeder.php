<?php

/**
 * ThemeSeeder.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-03-16 14:26:53
 * @modified   2023-03-16 14:26:53
 */

namespace Database\Seeders;

use Beike\Repositories\SettingRepo;
use Illuminate\Database\Seeder;

class ThemeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     * @throws \Exception
     */
    public function run()
    {
        // Persist the complete demo theme in one settings transaction. This
        // avoids a half-imported tenant storefront when one of the later
        // settings fails and refreshes the tenant runtime cache only once.
        SettingRepo::update('system', 'base', [
            'menu_setting'   => $this->getMenuSetting(),
            'design_setting' => $this->getHomeSetting(),
            'footer_setting' => $this->getFooterSetting(),
        ]);
    }

    /**
     * 设置头部菜单数据
     *
     * @return array
     * @throws \Exception
     */
    private function getMenuSetting(): array
    {
        return [
            'menus' => [
                [
                    'isFull' => false,
                    'badge'  => [
                        'isShow' => false,
                        'name'   => [
                            'en'    => '',
                            'zh_cn' => '',
                        ],
                        'bg_color'   => '#fd560f',
                        'text_color' => '#ffffff',
                    ],
                    'link' => [
                        'type'  => 'category',
                        'value' => 100006,
                        'text'  => [],
                        'link'  => '',
                    ],
                    'name' => [
                        'en'    => 'Sports',
                        'zh_cn' => '运动潮吧',
                    ],
                    'isChildren'    => false,
                    'childrenGroup' => [
                        [
                            'name' => [
                                'en'    => 'leading the fashion',
                                'zh_cn' => '引领时尚',
                            ],
                            'type'  => '',
                            'image' => [
                                'image' => [],
                                'link'  => [
                                    'type'  => 'product',
                                    'value' => '',
                                    'text'  => [],
                                ],
                            ],
                            'children' => [
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100005,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100008,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 5,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100003,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100010,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100010,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => [
                                'en'    => 'Special offer',
                                'zh_cn' => '特价优惠',
                            ],
                            'type'  => 'link',
                            'image' => [
                                'image' => [],
                                'link'  => [
                                    'type'  => 'product',
                                    'value' => '',
                                    'text'  => [],
                                ],
                            ],
                            'children' => [
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100008,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 6,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100003,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 7,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 8,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 2,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => [
                                'en'    => 'Popular products',
                                'zh_cn' => '爆款商品',
                            ],
                            'type'  => 'image',
                            'image' => [
                                'image' => [
                                    'en'    => 'image/catalog/demo/banner/2_en.jpg',
                                    'zh_cn' => 'image/catalog/demo/banner/2.jpg',
                                ],
                                'link' => [
                                    'type'  => 'product',
                                    'value' => 1,
                                    'text'  => [],
                                    'link'  => '',
                                ],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'isFull' => false,
                    'badge'  => [
                        'isShow' => false,
                        'name'   => [
                            'en'    => 'NEW',
                            'zh_cn' => '新品',
                        ],
                        'bg_color'   => '#7628A2',
                        'text_color' => '#ffffff',
                    ],
                    'link' => [
                        'type'  => 'category',
                        'value' => 100003,
                        'text'  => [],
                        'link'  => '',
                    ],
                    'name' => [
                        'en'    => 'Fashion',
                        'zh_cn' => '时尚潮流',
                    ],
                    'isChildren'    => false,
                    'childrenGroup' => [
                        [
                            'name' => [
                                'en'    => 'global purchase',
                                'zh_cn' => '全球购',
                            ],
                            'type'  => 'link',
                            'image' => [
                                'image' => [],
                                'link'  => [
                                    'type'  => 'product',
                                    'value' => '',
                                    'text'  => [],
                                ],
                            ],
                            'children' => [
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 8,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link'       => '',
                                        'new_window' => true,
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100003,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 7,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'page',
                                        'value' => 21,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => [
                                'en'    => 'Fashion',
                                'zh_cn' => '时尚',
                            ],
                            'type'  => 'image',
                            'image' => [
                                'image' => [
                                    'en'    => 'image/catalog/demo/product/16.webp',
                                    'zh_cn' => 'image/catalog/demo/product/16.webp',
                                ],
                                'link' => [
                                    'type'  => 'product',
                                    'value' => 4,
                                    'text'  => [],
                                    'link'  => '',
                                ],
                            ],
                            'children' => [
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'custom',
                                        'value' => 'https://www.baidu.com',
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => 'baidu ',
                                        ],
                                        'link'       => '',
                                        'new_window' => true,
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => [
                                'en'    => 'recommended',
                                'zh_cn' => '店长推荐',
                            ],
                            'type'  => 'image',
                            'image' => [
                                'image' => [
                                    'en'    => 'image/catalog/demo/product/13.webp',
                                    'zh_cn' => 'image/catalog/demo/product/13.webp',
                                ],
                                'link' => [
                                    'type'  => 'product',
                                    'value' => 2,
                                    'text'  => [],
                                    'link'  => '',
                                ],
                            ],
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'isFull' => false,
                    'badge'  => [
                        'isShow' => false,
                        'name'   => [
                            'en'    => '',
                            'zh_cn' => '',
                        ],
                        'bg_color'   => '#fd560f',
                        'text_color' => '#ffffff',
                    ],
                    'link' => [
                        'type'  => 'category',
                        'value' => 100007,
                        'text'  => [],
                        'link'  => '',
                    ],
                    'name' => [
                        'en'    => 'Clearance Sale',
                        'zh_cn' => '清仓特卖',
                    ],
                    'isChildren'    => false,
                    'childrenGroup' => [
                        [
                            'name' => [
                                'en'    => 'Big promotion',
                                'zh_cn' => '大牌促销',
                            ],
                            'type'  => '',
                            'image' => [
                                'image' => [],
                                'link'  => [
                                    'type'  => 'product',
                                    'value' => '',
                                    'text'  => [],
                                ],
                            ],
                            'children' => [
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 2,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 8,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 9,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 1,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 6,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => [
                                'en'    => 'Activity of gift',
                                'zh_cn' => '活动礼品',
                            ],
                            'type'  => '',
                            'image' => [
                                'image' => [],
                                'link'  => [
                                    'type'  => 'product',
                                    'value' => '',
                                    'text'  => [],
                                ],
                            ],
                            'children' => [
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100003,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100006,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100012,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100006,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'category',
                                        'value' => 100010,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'name' => [
                                'en'    => 'All three fold',
                                'zh_cn' => '全场三折',
                            ],
                            'type'  => '',
                            'image' => [
                                'image' => [],
                                'link'  => [
                                    'type'  => 'product',
                                    'value' => '',
                                    'text'  => [],
                                ],
                            ],
                            'children' => [
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 1,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 3,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 7,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 8,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                                [
                                    'name' => [],
                                    'link' => [
                                        'type'  => 'brand',
                                        'value' => 9,
                                        'text'  => [
                                            'en'    => '',
                                            'zh_cn' => '',
                                        ],
                                        'link' => '',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'isFull' => false,
                    'badge'  => [
                        'isShow' => false,
                        'name'   => [
                            'en'    => 'Hot',
                            'zh_cn' => '热卖',
                        ],
                        'bg_color'   => '#FF4D00',
                        'text_color' => '#ffffff',
                    ],
                    'link' => [
                        'type'  => 'category',
                        'value' => 100018,
                        'text'  => [],
                        'link'  => '',
                    ],
                    'name' => [
                        'en'    => 'Hot',
                        'zh_cn' => '热卖特惠',
                    ],
                    'isChildren'    => false,
                    'childrenGroup' => [],
                ],
                [
                    'isFull' => false,
                    'badge'  => [
                        'isShow' => false,
                        'name'   => [
                            'en'    => 'Sales',
                            'zh_cn' => '大牌直销',
                        ],
                        'bg_color'   => '#00C5C5',
                        'text_color' => '#ffffff',
                    ],
                    'link' => [
                        'type'  => 'static',
                        'value' => 'brands.index',
                        'text'  => [],
                        'link'  => '',
                    ],
                    'name' => [
                        'en'    => 'Brand',
                        'zh_cn' => '国际潮牌',
                    ],
                    'isChildren'    => false,
                    'childrenGroup' => [],
                ],
                [
                    'isFull' => false,
                    'badge'  => [
                        'isShow' => false,
                        'name'   => [
                            'en'    => '',
                            'zh_cn' => '',
                        ],
                        'bg_color'   => '',
                        'text_color' => '',
                    ],
                    'link' => [
                        'type'  => 'page_category',
                        'value' => '1',
                        'text'  => [],
                        'link'  => '',
                    ],
                    'name' => [
                        'en'    => 'News',
                        'zh_cn' => '公司新闻',
                    ],
                    'isChildren'    => false,
                    'childrenGroup' => [],
                ],
                [
                    'isFull' => false,
                    'badge'  => [
                        'isShow' => false,
                        'name'   => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'bg_color'   => null,
                        'text_color' => null,
                    ],
                    'link' => [
                        'type'  => 'static',
                        'value' => 'latest_products',
                        'text'  => [],
                        'link'  => '',
                    ],
                    'name' => [
                        'zh_cn' => '最新商品',
                        'en'    => 'Latest Products',
                    ],
                    'isChildren'    => false,
                    'childrenGroup' => [],
                ],
            ],
        ];
    }

    /**
     * 设置首页装修数据
     *
     * @return mixed
     * @throws \Exception
     */
    private function getHomeSetting(): array
    {
        return [
            'modules' => [
                [
                    'code'    => 'img_text_slideshow_2',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'floor' => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'module_size' => 'container-fluid',
                        'scroll_text' => [
                            'text' => [
                                'zh_cn' => 'Fashion Gala: Up to 80% Off!',
                                'en'    => 'Fashion Gala: Up to 80% Off!',
                            ],
                            'bg'        => '#ffffff',
                            'color'     => '#333333',
                            'font_size' => '14',
                            'padding'   => '20',
                        ],
                        'images' => [
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/banner/text-image-banner-3.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'sub_title' => [
                                    'zh_cn' => '促销服装专场',
                                    'en'    => 'New Arrivals',
                                ],
                                'title' => [
                                    'zh_cn' => '全场5折限时促销，好礼不断！',
                                    'en'    => '50% off sitewide for a limited time',
                                ],
                                'description' => [
                                    'zh_cn' => '即刻起购买者享受终身质保',
                                    'en'    => 'Immediately buyers enjoy a lifetime warranty',
                                ],
                                'text_position' => 'center',
                                'show'          => false,
                                'link'          => [
                                    'type'  => 'product',
                                    'value' => 35,
                                    'link'  => '',
                                ],
                            ],
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/banner/text-image-banner-4.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'show'      => true,
                                'sub_title' => [
                                    'zh_cn' => '促销服装专场',
                                    'en'    => 'Fashion',
                                ],
                                'title' => [
                                    'zh_cn' => '时尚，新品上市，首单立减',
                                    'en'    => 'Beauty Feast, Fashion Awards',
                                ],
                                'text_position' => 'center',
                                'description'   => [
                                    'zh_cn' => '即刻起购买者享受终身质保',
                                    'en'    => 'Immediately buyers enjoy a lifetime warranty',
                                ],
                                'link' => [
                                    'type'  => 'product',
                                    'value' => 39,
                                    'link'  => '',
                                ],
                            ],
                        ],
                    ],
                    'module_id' => '842uoi2l6SwF8oYF',
                    'name'      => '图文幻灯片2',
                    'view_path' => '',
                ],
                [
                    'code'    => 'icons',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'module_size' => 'container-fluid',
                        'title'       => [
                            'zh_cn' => '最受欢迎的分类',
                            'en'    => 'Top Collections',
                        ],
                        'floor' => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'images' => [
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/multiple-img/1.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'category',
                                    'value' => 100010,
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '清仓',
                                    'en'    => 'Clearance',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '',
                                    'en'    => '',
                                ],
                                'show' => false,
                            ],
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/multiple-img/2.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'category',
                                    'value' => 100003,
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '爆款',
                                    'en'    => 'Hot',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '',
                                    'en'    => '',
                                ],
                                'show' => false,
                            ],
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/multiple-img/4.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'category',
                                    'value' => 100005,
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '上衣',
                                    'en'    => 'Jacket',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '',
                                    'en'    => '',
                                ],
                                'show' => false,
                            ],
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/multiple-img/5.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'category',
                                    'value' => 100007,
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '时尚',
                                    'en'    => 'Fashion',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '',
                                    'en'    => '',
                                ],
                                'show' => false,
                            ],
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/multiple-img/6.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'category',
                                    'value' => 100012,
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '个性',
                                    'en'    => 'Personal',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '',
                                    'en'    => '',
                                ],
                                'show' => true,
                            ],
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/multiple-img/7.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'category',
                                    'value' => 100018,
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '新品',
                                    'en'    => 'New Arrivals',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '',
                                    'en'    => '',
                                ],
                                'show' => true,
                            ],
                        ],
                        'sub_title' => [
                            'zh_cn' => '通过我们出色的系列展现您的风格——时尚与精致的完美结合。',
                            'en'    => 'Express your style with our standout collection—fashion meets sophistication.',
                        ],
                    ],
                    'module_id' => 'gdd6GBoNVu02XJVg',
                    'name'      => '图标模块',
                    'view_path' => '',
                ],
                [
                    'code'    => 'tab_product',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'module_size' => 'container-fluid',
                        'floor'       => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'editableTabsValue' => '0',
                        'tabs'              => [
                            [
                                'title' => [
                                    'zh_cn' => '时尚单品',
                                    'en'    => 'Fashion sheet',
                                ],
                                'products' => [
                                    1,
                                    2,
                                    3,
                                    4,
                                    5,
                                    6,
                                    7,
                                    8,
                                ],
                            ],
                            [
                                'title' => [
                                    'zh_cn' => '潮流穿搭',
                                    'en'    => 'Trendy outfits',
                                ],
                                'products' => [
                                    51,
                                    9,
                                    10,
                                    11,
                                    12,
                                    13,
                                    14,
                                    15,
                                ],
                            ],
                            [
                                'title' => [
                                    'zh_cn' => '最新促销',
                                    'en'    => 'Promotions',
                                ],
                                'products' => [
                                    39,
                                    15,
                                    1,
                                    4,
                                    13,
                                    7,
                                    8,
                                    4,
                                ],
                            ],
                        ],
                        'title' => [
                            'zh_cn' => '推荐商品',
                            'en'    => 'Fashion items',
                        ],
                    ],
                    'module_id' => 's6e7e3vucriazzbi',
                    'name'      => '选项卡商品',
                ],
                [
                    'code'    => 'img_text_banner_multiple',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'module_size' => 'container-fluid',
                        'title'       => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'sub_title' => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'floor' => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'images' => [
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/banner/banner-7.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'product',
                                    'value' => '1',
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '限时抢购',
                                    'en'    => 'Limited Time Offer',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '新品上市尽享时尚潮流',
                                    'en'    => 'New arrivals, trendsetting',
                                ],
                                'show' => false,
                            ],
                            [
                                'image' => [
                                    'src' => '/image/catalog/demo/banner/banner-6.webp',
                                    'alt' => [
                                        'zh_cn' => '',
                                        'en'    => '',
                                    ],
                                ],
                                'link' => [
                                    'type'  => 'product',
                                    'value' => '2',
                                    'link'  => '',
                                ],
                                'text' => [
                                    'zh_cn' => '全场7折',
                                    'en'    => '30% Off All Items',
                                ],
                                'sub_text' => [
                                    'zh_cn' => '限时折扣不容错过',
                                    'en'    => 'Grab limited offers',
                                ],
                                'show' => false,
                            ],
                        ],
                    ],
                    'module_id' => 'AZcJcG0DwTAV4ALX',
                    'name'      => '多图文横幅',
                    'view_path' => '',
                ],
                [
                    'code'    => 'product',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'floor' => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'module_size' => 'container-fluid',
                        'products'    => [
                            35,
                            15,
                            14,
                            12,
                        ],
                        'title' => [
                            'zh_cn' => '节日特惠',
                            'en'    => 'Holiday Specials',
                        ],
                    ],
                    'module_id' => 'EwEAIxp8LX3gVSQE',
                    'name'      => '商品模块',
                    'view_path' => '',
                ],
                [
                    'code'    => 'img_text_banner',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'floor' => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'module_size' => 'container-fluid',
                        'bg_color'    => '#FFFFFF',
                        'text_color'  => '#171717',
                        'btn_bg'      => '#101010',
                        'btn_color'   => '#FFFFFF',
                        'image'       => [
                            'src' => '/image/catalog/demo/banner/text-image-banner-9.webp',
                            'alt' => [
                                'zh_cn' => '',
                                'en'    => '',
                            ],
                        ],
                        'title' => [
                            'zh_cn' => '限时闪购：抢购您心仪的时尚单品，错过不再有！',
                            'en'    => 'Limited Time Flash Sale: Grab Your Favorite Fashion Styles Before They’re Gone!',
                        ],
                        'description' => [
                            'zh_cn' => '立即升级您的衣橱，享受独家限时优惠！潮流服饰、配饰、鞋履应有尽有，无论是约会、通勤还是日常休闲，都能找到心仪款式。折扣超值，数量有限，抓紧时间购买，错过就没有机会了！',
                            'en'    => "Lorem Ipsum is simply dummy text of the printing and typesetting industry lorem Ipsum has been the industry's standard dummy text ever sinces the typesetting remaining essentially unchanged.",
                        ],
                        'link' => [
                            'type'  => 'product',
                            'value' => '10',
                            'link'  => '',
                        ],
                        'sub_title' => [
                            'zh_cn' => '精选时尚单品，短期限时抢购中',
                            'en'    => 'Exclusive Fashion Sale: Stunning Styles at Incredible Prices for a Short Time Only',
                        ],
                        'image_position' => 'left',
                        'text_position'  => 'left',
                        'text_max_width' => '600',
                    ],
                    'module_id' => 'jJDh4AJ82QFDc94d',
                    'name'      => '图文横幅',
                    'view_path' => '',
                ],
                [
                    'code'    => 'brand',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'module_size' => 'container-fluid',
                        'floor'       => [
                            'en'    => '',
                            'zh_cn' => '',
                        ],
                        'full'  => true,
                        'title' => [
                            'en'    => 'Recommended Brand',
                            'zh_cn' => '推荐品牌',
                        ],
                        'brands' => [
                            1,
                            2,
                            3,
                            4,
                            5,
                            6,
                            7,
                            8,
                            9,
                            10,
                            11,
                            12,
                        ],
                    ],
                    'module_id' => 'yln7isoqlxovqz3k',
                    'name'      => '品牌模块',
                ],
                [
                    'code'    => 'page',
                    'content' => [
                        'style' => [
                            'background_color' => '',
                        ],
                        'module_size' => 'container-fluid',
                        'floor'       => [
                            'zh_cn' => '',
                            'en'    => '',
                        ],
                        'items' => [
                            22,
                            23,
                            24,
                            25,
                        ],
                        'title' => [
                            'zh_cn' => '新闻博客',
                            'en'    => 'News Blog',
                        ],
                    ],
                    'module_id' => '24P9p4bRwk1nbtXE',
                    'name'      => '文章模块',
                    'view_path' => '',
                ],
            ],
        ];
    }

    /**
     * 设置底部装修数据
     */
    private function getFooterSetting(): array
    {
        return [
            'services' => [
                'enable' => true,
                'items'  => [
                    [
                        'image' => '',
                        'title' => [
                            'en'    => 'Secure checkout',
                            'zh_cn' => '安全结账',
                        ],
                        'sub_title' => [
                            'en'    => 'Encrypted payment processing',
                            'zh_cn' => '加密付款处理',
                        ],
                        'show' => true,
                    ],
                    [
                        'image' => '',
                        'title' => [
                            'en'    => 'Protected payment',
                            'zh_cn' => '付款保护',
                        ],
                        'sub_title' => [
                            'en'    => 'Trusted checkout providers',
                            'zh_cn' => '可信的结账服务',
                        ],
                        'show' => true,
                    ],
                    [
                        'image' => '',
                        'title' => [
                            'en'    => 'Tracked delivery',
                            'zh_cn' => '物流追踪',
                        ],
                        'sub_title' => [
                            'en'    => 'Follow your order after dispatch',
                            'zh_cn' => '发货后追踪订单',
                        ],
                        'show' => true,
                    ],
                ],
            ],
            'content' => [
                'intro' => [
                    'logo' => 'image/longos-logo.svg',
                    'text' => [
                        'en'    => '<p>Building reliable, flexible commerce experiences for global customers.</p>',
                        'zh_cn' => '<p>打造可靠、灵活且面向全球客户的品牌体验。</p>',
                    ],
                    'social_network' => [
                        [
                            'image' => '/image/catalog/demo/social/twitter.png',
                            'link'  => '/',
                            'show'  => false,
                        ],
                        [
                            'image' => '/image/catalog/demo/social/facebook.png',
                            'link'  => '/',
                            'show'  => false,
                        ],
                        [
                            'image' => '/image/catalog/demo/social/youtube.png',
                            'link'  => '/',
                            'show'  => false,
                        ],
                        [
                            'image' => '/image/catalog/demo/social/instagram.png',
                            'link'  => '/',
                            'show'  => false,
                        ],
                        [
                            'image' => '/image/catalog/demo/social/pinterest.png',
                            'link'  => '/',
                            'show'  => false,
                        ],
                    ],
                ],
                'link1' => [
                    'title' => [],
                    'links' => [],
                ],
                'link2' => [
                    'title' => [],
                    'links' => [],
                ],
                'link3' => [
                    // The former demo-only "Other" group duplicated the
                    // account, brand and legal navigation. Standard policies
                    // now live in the fixed footer legal bar instead.
                    'title' => [],
                    'links' => [],
                ],
                'contact' => [
                    'telephone' => '',
                    'address'   => [
                        'en'    => '',
                        'zh_cn' => '',
                    ],
                    'email' => false,
                ],
            ],
            'bottom' => [
                'copyright' => [
                    'en'    => '',
                    'zh_cn' => '',
                ],
                'image'           => '',
                'payments_enable' => true,
                'payments'        => ['visa', 'mastercard', 'amex', 'paypal', 'apple_pay', 'google_pay', 'klarna'],
            ],
        ];
    }

    /**
     * 设置头部装修数据
     */
    private function getHeaderSetting(): array
    {
        return [
            'header_ads' => [
                'bg_color' => '#333333',
                'color'    => '#ffffff',
                'active'   => false,
                'items'    => [
                    [
                        'title' => [
                            'zh_cn' => '春日时尚焕新季 全场新品限时5折起',
                            'en'    => 'Spring Fashion Refresh Season: Limited Time 50% Off on New Arrivals',
                        ],
                        'link' => [
                            'type'  => 'category',
                            'value' => 100003,
                            'link'  => '',
                        ],
                    ],
                    [
                        'title' => [
                            'zh_cn' => '春季潮流新品发布 特惠5折起购',
                            'en'    => 'Spring Trend Collection Launch: Special 50% Off Offer',
                        ],
                        'link' => [
                            'type'  => 'category',
                            'value' => 100010,
                            'link'  => '',
                        ],
                    ],
                    [
                        'title' => [
                            'zh_cn' => '春意浓情特卖会 全场5折起狂欢',
                            'en'    => 'Spring Affection Sale Event: Up to 50% Off on All Items',
                        ],
                        'link' => [
                            'type'  => 'category',
                            'value' => 100010,
                            'link'  => '',
                        ],
                    ],
                    [
                        'title' => [
                            'zh_cn' => '春季新品大放价 5折起享时尚',
                            'en'    => 'Spring New Arrivals Mega Sale: Enjoy 50% Off on Fashion',
                        ],
                        'link' => [
                            'type'  => 'category',
                            'value' => 100003,
                            'link'  => '',
                        ],
                    ],
                    [
                        'title' => [
                            'zh_cn' => '春日风尚大促销 精选商品5折起',
                            'en'    => 'Spring Style Promotion: Select Items Starting at 50% Off',
                        ],
                        'link' => [
                            'type'  => 'category',
                            'value' => 100007,
                            'link'  => '',
                        ],
                    ],
                ],
            ],
        ];
    }
}
