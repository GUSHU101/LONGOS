<?php

/**
 * PagesSeeder.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-09-05 20:42:42
 * @modified   2026-10-17
 */

namespace Database\Seeders;

use Beike\Models\Page;
use Beike\Models\PageDescription;
use Illuminate\Database\Seeder;

class PagesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->getPages() as $item) {
            $id = $item['id'];
            unset($item['id']);
            Page::query()->firstOrCreate(['id' => $id], $item);
        }

        foreach ($this->getPageDescriptions() as $item) {
            $id = $item['id'];
            unset($item['id']);
            PageDescription::query()->firstOrCreate(['id' => $id], $item);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getPages(): array
    {
        return [
            $this->page(12),
            $this->page(18),
            $this->page(20),
            $this->page(21),
            $this->page(22, 1, '/image/catalog/demo/blog/post-1.webp'),
            $this->page(23, 1, '/image/catalog/demo/blog/post-2.webp'),
            $this->page(24, 1, '/image/catalog/demo/blog/post-3.webp'),
            $this->page(25, 1, '/image/catalog/demo/blog/post-4.webp'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getPageDescriptions(): array
    {
        $version = (string) config('beike.version');

        return [
            $this->description(
                55,
                18,
                'en',
                'Shipping information',
                'How delivery options, rates and tracking are shown during checkout.',
                '<h2>Shipping information</h2><p>Available delivery services and their current prices are calculated from your delivery address and cart during checkout.</p><h3>Processing and tracking</h3><p>After payment, the store will prepare your order and send tracking details when the carrier makes them available. Delivery estimates are not guarantees and can be affected by customs, weather or carrier delays.</p><h3>Before placing your order</h3><p>Please review the recipient name, telephone number and full address. Contact the store as soon as possible if the address needs to be corrected.</p>',
                'Shipping information',
                'Learn how shipping options, delivery prices and tracking work for orders from this store.',
            ),
            $this->description(
                56,
                18,
                'zh_cn',
                '配送信息',
                '说明结账时如何展示配送方式、运费与物流追踪。',
                '<h2>配送信息</h2><p>结账页会根据收货地址和购物车商品，实时展示当前可用的配送方式及费用。</p><h3>处理与追踪</h3><p>付款后，商家将安排订单处理；承运商提供单号后，系统会发送物流追踪信息。配送时效可能受海关、天气或承运商影响。</p><h3>提交订单前</h3><p>请核对收件人、联系电话和完整地址。如需修改，请尽快联系商家。</p>',
                '配送信息',
                '了解本店订单的配送方式、运费计算及物流追踪流程。',
            ),
            $this->description(
                63,
                12,
                'en',
                'Privacy policy',
                'How this store handles personal information needed to provide its services.',
                '<h2>Privacy policy</h2><p>This store processes information needed to provide shopping services, including account details, contact and shipping information, order records, payment confirmation, support messages, device information and security logs.</p><h3>How information is used</h3><ul><li>To operate checkout, payment, delivery and customer support.</li><li>To prevent fraud, protect accounts and comply with legal obligations.</li><li>To improve the store when analytics or marketing consent has been provided.</li></ul><h3>Service providers and retention</h3><p>Only the information required for a task is shared with payment, delivery, hosting or support providers. Records are retained only as long as operational, tax, dispute and legal requirements require.</p><h3>Your choices</h3><p>You may request access, correction or deletion where applicable. Use the contact details published by this store. The merchant should review this starter policy and add the identity, contact details and rules required in every market it serves.</p>',
                'Privacy policy',
                'Read how this store collects, uses, shares and protects personal information.',
            ),
            $this->description(
                64,
                12,
                'zh_cn',
                '隐私政策',
                '说明本店如何处理提供服务所需的个人信息。',
                '<h2>隐私政策</h2><p>本店仅处理提供购物服务所需的信息，包括账户资料、联系方式、收货信息、订单记录、支付确认、客服消息、设备信息与安全日志。</p><h3>信息用途</h3><ul><li>完成结账、支付、配送与客户支持。</li><li>预防欺诈、保护账户并履行法定义务。</li><li>在用户同意后用于分析或营销优化。</li></ul><h3>服务商与保存期限</h3><p>仅向支付、物流、托管或客服服务商提供完成任务所必需的信息。记录只在运营、税务、争议处理与法律要求的期限内保存。</p><h3>您的选择</h3><p>在适用法律允许的范围内，您可以请求访问、更正或删除个人信息。请使用本店公开的联系方式。商家上线前应根据经营主体、联系方式及目标市场要求审核并补充本模板。</p>',
                '隐私政策',
                '了解本店如何收集、使用、共享和保护个人信息。',
            ),
            $this->description(
                167,
                20,
                'en',
                'Terms of use',
                'Core terms for using this storefront and placing an order.',
                '<h2>Terms of use</h2><p>By browsing this store or placing an order, you agree to provide accurate information and to use the service lawfully.</p><h3>Orders and prices</h3><p>Products, availability, prices, discounts, taxes and delivery charges are confirmed during checkout. An order can be reviewed or cancelled when payment fails, stock is unavailable, fraud is suspected or the supplied information is materially incorrect.</p><h3>Content and acceptable use</h3><p>Store content may not be copied, disrupted or used to attempt unauthorized access. Product appearance can vary slightly by display and production batch.</p><h3>Local terms</h3><p>The merchant should add its legal identity, returns policy, governing law and contact details before launch. Nothing in these terms limits mandatory consumer rights.</p>',
                'Terms of use',
                'Review the main conditions for browsing this store and placing an order.',
            ),
            $this->description(
                168,
                20,
                'zh_cn',
                '使用条款',
                '说明访问本店和提交订单时适用的基本条款。',
                '<h2>使用条款</h2><p>访问本店或提交订单，即表示您同意提供准确资料并依法使用服务。</p><h3>订单与价格</h3><p>商品、库存、价格、优惠、税费和运费以结账确认结果为准。支付失败、库存不足、疑似欺诈或资料严重错误时，订单可能被审核或取消。</p><h3>内容与合理使用</h3><p>不得复制或破坏网站内容，也不得尝试未经授权的访问。商品外观可能因显示设备和生产批次存在轻微差异。</p><h3>本地规则</h3><p>商家上线前应补充经营主体、退换货政策、适用法律与联系方式。本条款不限制法律规定的消费者权利。</p>',
                '使用条款',
                '查看访问本店和提交订单时适用的主要规则。',
            ),
            $this->description(
                179,
                21,
                'en',
                'About this store',
                'A reliable storefront designed for clear product discovery and secure checkout.',
                '<h2>About this store</h2><p>This storefront is powered by LONGOS and is designed to provide clear product information, localized shopping and a secure checkout experience.</p><p>The merchant should replace this starter text with its brand story, customer promise, support details and verifiable business information before launch.</p>',
                'About this store',
                'Learn about this store, its shopping experience and customer commitment.',
            ),
            $this->description(
                180,
                21,
                'zh_cn',
                '关于本店',
                '面向清晰选购与安全结账打造的可靠店铺。',
                '<h2>关于本店</h2><p>本店由 LONGOS 提供技术支持，致力于提供清晰的商品信息、本地化购物体验与安全的结账流程。</p><p>上线前，商家应将此初始内容替换为真实的品牌故事、客户承诺、客服方式和可验证的经营信息。</p>',
                '关于本店',
                '了解本店的购物体验与客户服务承诺。',
            ),
            $this->description(
                199,
                22,
                'zh_cn',
                "LONGOS {$version} 新版本发布",
                '新版提升了多租户隔离、域名管理、结账稳定性与自动部署能力。',
                "<h2>LONGOS {$version} 已发布</h2><p>本次版本进一步提升多站点运营、租户隔离、自定义域名验证、结账与自动部署的可靠性。</p>",
                "LONGOS {$version} 新版本发布",
                '了解 LONGOS 新版本在多租户、域名、结账和部署方面的改进。',
            ),
            $this->description(
                200,
                22,
                'en',
                "LONGOS {$version} released",
                'The release improves tenant isolation, domain management, checkout reliability and automated deployment.',
                "<h2>LONGOS {$version} is available</h2><p>This release strengthens multi-store operations, tenant isolation, custom-domain verification, checkout and automated deployment.</p>",
                "LONGOS {$version} release",
                'Discover the latest LONGOS improvements for tenancy, domains, checkout and deployment.',
            ),
            $this->description(
                203,
                23,
                'zh_cn',
                'LONGOS 多租户电商 SaaS 正式上线',
                '统一完成租户建站、数据隔离、域名验证与持续运营。',
                '<h2>面向规模化运营的多租户电商平台</h2><p>LONGOS 为品牌方、跨境团队和服务商提供统一控制面，完成站点创建、数据库隔离、域名验证、证书管理和持续运营。</p>',
                'LONGOS 多租户电商 SaaS',
                '通过 LONGOS 统一管理租户建站、隔离、域名验证与运营。',
            ),
            $this->description(
                204,
                23,
                'en',
                'Introducing LONGOS multi-tenant commerce SaaS',
                'Provision, isolate, verify and operate storefronts from one control plane.',
                '<h2>Commerce built for repeatable delivery</h2><p>LONGOS gives brands, cross-border teams and agencies one control plane for site provisioning, database isolation, domain verification, certificate management and ongoing operations.</p>',
                'LONGOS multi-tenant commerce SaaS',
                'Manage storefront provisioning, isolation, domain verification and operations with LONGOS.',
            ),
            $this->description(
                209,
                24,
                'zh_cn',
                'LONGOS 多语言能力助力全球运营',
                '按站点配置语言、货币、内容与本地化结账体验。',
                '<h2>让本地化贯穿完整购物流程</h2><p>LONGOS 支持按租户配置语言、货币与内容，并让商品展示、地址填写、运费、支付和订单通知保持一致。</p>',
                'LONGOS 多语言与本地化能力',
                '使用 LONGOS 为不同市场提供一致的多语言、多货币购物体验。',
            ),
            $this->description(
                210,
                24,
                'en',
                'LONGOS localization for global operations',
                'Configure language, currency, content and checkout behavior for each storefront.',
                '<h2>Localization across the whole customer journey</h2><p>LONGOS keeps product presentation, addresses, shipping, payment and order communication aligned with each tenant market.</p>',
                'LONGOS localization for global commerce',
                'Deliver consistent multilingual and multi-currency shopping experiences with LONGOS.',
            ),
            $this->description(
                211,
                25,
                'zh_cn',
                '性能提升指南｜打造更快的 LONGOS 站点',
                '从图片、缓存、扩展与监控四方面持续改善店铺性能。',
                '<h2>稳定比单次跑分更重要</h2><p>优先压缩商品图片、启用生产缓存、减少无用扩展，并持续监控结账、支付、队列和数据库。每次升级后都应重新验证核心购买路径。</p>',
                'LONGOS 店铺性能提升指南',
                '通过图片优化、缓存、扩展治理和持续监控提升 LONGOS 店铺性能。',
            ),
            $this->description(
                212,
                25,
                'en',
                'Performance guide for a faster LONGOS storefront',
                'Improve storefront performance through images, caching, extension control and monitoring.',
                '<h2>Consistency matters more than a single benchmark</h2><p>Optimize product images, enable production caches, remove unused extensions and monitor checkout, payment, queues and the database. Re-test the main purchase journey after every upgrade.</p>',
                'LONGOS storefront performance guide',
                'Improve LONGOS storefront speed with image optimization, caching, extension control and monitoring.',
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function page(int $id, int $categoryId = 0, string $image = ''): array
    {
        return array_filter([
            'id'               => $id,
            'page_category_id' => $categoryId,
            'position'         => 0,
            'views'            => $categoryId > 0 ? 1 : 0,
            'image'            => $image,
            'author'           => 'LONGOS',
            'active'           => 1,
        ], static fn (mixed $value): bool => $value !== '');
    }

    /** @return array<string, mixed> */
    private function description(
        int $id,
        int $pageId,
        string $locale,
        string $title,
        string $summary,
        string $content,
        string $metaTitle,
        string $metaDescription,
    ): array {
        return [
            'id'               => $id,
            'page_id'          => $pageId,
            'locale'           => $locale,
            'title'            => $title,
            'summary'          => $summary,
            'content'          => $content,
            'meta_title'       => $metaTitle,
            'meta_description' => $metaDescription,
            'meta_keywords'    => '',
            'created_at'       => '2026-10-17 00:00:00',
            'updated_at'       => '2026-10-17 00:00:00',
        ];
    }
}
