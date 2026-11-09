<?php

namespace Plugin\LongosGlobalTranslate\Services;

/**
 * A deterministic, source-controlled translation profile for public commerce
 * copy.  It is deliberately code-owned instead of being populated from shop
 * visits: no customer, checkout, order, payment, address or account value can
 * become a rule, glossary term or provider context.
 *
 * The profile has three jobs:
 *
 *  1. describe target-language grammar/localisation expectations;
 *  2. provide a small high-confidence cross-border commerce terminology set;
 *  3. classify an already-approved public field into a non-sensitive context
 *     accepted by providers which support contextual translation.
 *
 * Merchant-maintained glossary records remain supported and take precedence
 * over these defaults in TranslationJobService.
 */
final class CommerceTranslationProfile
{
    public const VERSION = 'commerce-profile-2026-11-07';

    /** @var array<string, array{language:string,grammar:string,style:string}> */
    private const LANGUAGE_RULES = [
        'ar'    => ['language' => 'Arabic', 'grammar' => 'Use Modern Standard Arabic with natural agreement, dual/plural forms, right-to-left punctuation and Arabic numerals only where natural.', 'style' => 'Clear, concise neutral retail copy.'],
        'de'    => ['language' => 'German', 'grammar' => 'Use natural German word order, compounds and plural forms; use German punctuation.', 'style' => 'Clear, concise retail copy using neutral polite wording.'],
        'es'    => ['language' => 'Spanish', 'grammar' => 'Use natural Spanish agreement, verb tense and punctuation for the target market.', 'style' => 'Clear, concise neutral retail copy.'],
        'fr'    => ['language' => 'French', 'grammar' => 'Use natural French agreement, articles, plural forms and French punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'id'    => ['language' => 'Indonesian', 'grammar' => 'Use natural Indonesian word order, classifiers, affixes and punctuation; avoid literal English syntax.', 'style' => 'Clear, concise neutral retail copy.'],
        'it'    => ['language' => 'Italian', 'grammar' => 'Use natural Italian agreement, articles, plural forms and punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'ja'    => ['language' => 'Japanese', 'grammar' => 'Use natural Japanese word order, particles, counters and Japanese punctuation; avoid unnecessary pronouns.', 'style' => 'Clear, concise neutral retail copy.'],
        'ko'    => ['language' => 'Korean', 'grammar' => 'Use natural Korean word order, particles, counters and polite commerce register.', 'style' => 'Clear, concise neutral retail copy.'],
        'nl'    => ['language' => 'Dutch', 'grammar' => 'Use natural Dutch word order, compounds, plural forms and punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'pl'    => ['language' => 'Polish', 'grammar' => 'Use natural Polish inflection, agreement, plural forms and punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'pt'    => ['language' => 'Portuguese', 'grammar' => 'Use natural Portuguese agreement, verb tense, plural forms and punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'ru'    => ['language' => 'Russian', 'grammar' => 'Use natural Russian case, agreement, plural forms and punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'th'    => ['language' => 'Thai', 'grammar' => 'Use natural Thai classifier usage, word order and Thai punctuation; do not insert spaces inside a word.', 'style' => 'Clear, concise neutral retail copy.'],
        'tr'    => ['language' => 'Turkish', 'grammar' => 'Use natural Turkish suffixes, word order, plural forms and punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'vi'    => ['language' => 'Vietnamese', 'grammar' => 'Use natural Vietnamese classifiers, word order, diacritics and punctuation.', 'style' => 'Clear, concise neutral retail copy.'],
        'zh-cn' => ['language' => 'Simplified Chinese', 'grammar' => 'Use idiomatic Simplified Chinese word order, measure words and Chinese punctuation.', 'style' => 'Clear, concise customer-facing retail copy.'],
        'zh-tw' => ['language' => 'Traditional Chinese', 'grammar' => 'Use idiomatic Traditional Chinese word order, measure words and Traditional Chinese punctuation.', 'style' => 'Clear, concise customer-facing retail copy.'],
    ];

    /** @var list<string> */
    private const INVARIANT_TERMS = [
        'LONGOS', 'Shopvella', 'PayPal', 'Stripe', 'Apple', 'Samsung', 'Google', 'iPhone', 'iPad', 'AirPods',
        'MagSafe', 'USB-C', 'USB', 'Bluetooth', 'Wi-Fi', 'Qi', 'NFC', 'HDMI', 'SKU', 'IMEI',
    ];

    /**
     * High-confidence phrases that must remain consistent across catalogue,
     * merchandising, shipping and after-sales pages. Values are restored after
     * MT so the provider cannot silently substitute a different retail term.
     *
     * @var array<string, array<string, string>>
     */
    private const COMMERCE_TERMS = [
        'ar' => [
            'Phone case'      => 'جراب هاتف', 'Screen protector' => 'واقي الشاشة', 'Wireless charger' => 'شاحن لاسلكي',
            'Free shipping'   => 'شحن مجاني', 'Worldwide shipping' => 'شحن عالمي', 'Order tracking' => 'تتبع الطلب',
            'Tracking number' => 'رقم التتبع', 'Return policy' => 'سياسة الإرجاع', '30-day returns' => 'إرجاع خلال 30 يوماً',
            'Refund'          => 'استرداد المبلغ', 'Replacement' => 'استبدال', 'Shipping address' => 'عنوان الشحن', 'Customer support' => 'دعم العملاء',
        ],
        'de' => [
            'Phone case'      => 'Handyhülle', 'Screen protector' => 'Displayschutzfolie', 'Wireless charger' => 'Kabelloses Ladegerät',
            'Free shipping'   => 'Kostenloser Versand', 'Worldwide shipping' => 'Weltweiter Versand', 'Order tracking' => 'Sendungsverfolgung',
            'Tracking number' => 'Sendungsnummer', 'Return policy' => 'Rückgabebedingungen', '30-day returns' => '30 Tage Rückgaberecht',
            'Refund'          => 'Rückerstattung', 'Replacement' => 'Ersatzlieferung', 'Shipping address' => 'Lieferadresse', 'Customer support' => 'Kundenservice',
        ],
        'es' => [
            'Phone case'      => 'Funda para teléfono', 'Screen protector' => 'Protector de pantalla', 'Wireless charger' => 'Cargador inalámbrico',
            'Free shipping'   => 'Envío gratis', 'Worldwide shipping' => 'Envío internacional', 'Order tracking' => 'Seguimiento del pedido',
            'Tracking number' => 'Número de seguimiento', 'Return policy' => 'Política de devoluciones', '30-day returns' => 'Devoluciones en 30 días',
            'Refund'          => 'Reembolso', 'Replacement' => 'Reemplazo', 'Shipping address' => 'Dirección de envío', 'Customer support' => 'Atención al cliente',
        ],
        'fr' => [
            'Phone case'      => 'Coque de téléphone', 'Screen protector' => 'Protection d’écran', 'Wireless charger' => 'Chargeur sans fil',
            'Free shipping'   => 'Livraison gratuite', 'Worldwide shipping' => 'Livraison internationale', 'Order tracking' => 'Suivi de commande',
            'Tracking number' => 'Numéro de suivi', 'Return policy' => 'Politique de retour', '30-day returns' => 'Retours sous 30 jours',
            'Refund'          => 'Remboursement', 'Replacement' => 'Remplacement', 'Shipping address' => 'Adresse de livraison', 'Customer support' => 'Service client',
        ],
        'id' => [
            'Phone case'      => 'Casing ponsel', 'Screen protector' => 'Pelindung layar', 'Wireless charger' => 'Pengisi daya nirkabel',
            'Free shipping'   => 'Gratis ongkir', 'Worldwide shipping' => 'Pengiriman internasional', 'Order tracking' => 'Pelacakan pesanan',
            'Tracking number' => 'Nomor pelacakan', 'Return policy' => 'Kebijakan pengembalian', '30-day returns' => 'Pengembalian dalam 30 hari',
            'Refund'          => 'Pengembalian dana', 'Replacement' => 'Penggantian', 'Shipping address' => 'Alamat pengiriman', 'Customer support' => 'Layanan pelanggan',
        ],
        'it' => [
            'Phone case'      => 'Custodia per telefono', 'Screen protector' => 'Pellicola protettiva', 'Wireless charger' => 'Caricatore wireless',
            'Free shipping'   => 'Spedizione gratuita', 'Worldwide shipping' => 'Spedizione internazionale', 'Order tracking' => 'Tracciamento dell’ordine',
            'Tracking number' => 'Numero di tracciamento', 'Return policy' => 'Politica di reso', '30-day returns' => 'Resi entro 30 giorni',
            'Refund'          => 'Rimborso', 'Replacement' => 'Sostituzione', 'Shipping address' => 'Indirizzo di spedizione', 'Customer support' => 'Assistenza clienti',
        ],
        'ja' => [
            'Phone case'      => 'スマホケース', 'Screen protector' => '保護フィルム', 'Wireless charger' => 'ワイヤレス充電器',
            'Free shipping'   => '送料無料', 'Worldwide shipping' => '世界配送', 'Order tracking' => '注文追跡',
            'Tracking number' => '追跡番号', 'Return policy' => '返品ポリシー', '30-day returns' => '30日間返品保証',
            'Refund'          => '返金', 'Replacement' => '交換', 'Shipping address' => '配送先住所', 'Customer support' => 'カスタマーサポート',
        ],
        'ko' => [
            'Phone case'      => '휴대폰 케이스', 'Screen protector' => '액정 보호 필름', 'Wireless charger' => '무선 충전기',
            'Free shipping'   => '무료 배송', 'Worldwide shipping' => '전 세계 배송', 'Order tracking' => '주문 추적',
            'Tracking number' => '운송장 번호', 'Return policy' => '반품 정책', '30-day returns' => '30일 이내 반품',
            'Refund'          => '환불', 'Replacement' => '교환', 'Shipping address' => '배송지 주소', 'Customer support' => '고객 지원',
        ],
        'nl' => [
            'Phone case'      => 'Telefoonhoesje', 'Screen protector' => 'Schermbeschermer', 'Wireless charger' => 'Draadloze oplader',
            'Free shipping'   => 'Gratis verzending', 'Worldwide shipping' => 'Wereldwijde verzending', 'Order tracking' => 'Bestelling volgen',
            'Tracking number' => 'Trackingsnummer', 'Return policy' => 'Retourbeleid', '30-day returns' => '30 dagen retourneren',
            'Refund'          => 'Terugbetaling', 'Replacement' => 'Vervanging', 'Shipping address' => 'Verzendadres', 'Customer support' => 'Klantenservice',
        ],
        'pl' => [
            'Phone case'      => 'Etui na telefon', 'Screen protector' => 'Szkło ochronne', 'Wireless charger' => 'Ładowarka bezprzewodowa',
            'Free shipping'   => 'Darmowa dostawa', 'Worldwide shipping' => 'Dostawa na cały świat', 'Order tracking' => 'Śledzenie zamówienia',
            'Tracking number' => 'Numer śledzenia', 'Return policy' => 'Polityka zwrotów', '30-day returns' => 'Zwrot w ciągu 30 dni',
            'Refund'          => 'Zwrot środków', 'Replacement' => 'Wymiana', 'Shipping address' => 'Adres dostawy', 'Customer support' => 'Obsługa klienta',
        ],
        'pt' => [
            'Phone case'      => 'Capa para telemóvel', 'Screen protector' => 'Protetor de ecrã', 'Wireless charger' => 'Carregador sem fios',
            'Free shipping'   => 'Envio grátis', 'Worldwide shipping' => 'Envio mundial', 'Order tracking' => 'Rastreio da encomenda',
            'Tracking number' => 'Número de rastreio', 'Return policy' => 'Política de devoluções', '30-day returns' => 'Devoluções em 30 dias',
            'Refund'          => 'Reembolso', 'Replacement' => 'Substituição', 'Shipping address' => 'Morada de entrega', 'Customer support' => 'Apoio ao cliente',
        ],
        'ru' => [
            'Phone case'      => 'Чехол для телефона', 'Screen protector' => 'Защитное стекло', 'Wireless charger' => 'Беспроводное зарядное устройство',
            'Free shipping'   => 'Бесплатная доставка', 'Worldwide shipping' => 'Доставка по всему миру', 'Order tracking' => 'Отслеживание заказа',
            'Tracking number' => 'Номер отслеживания', 'Return policy' => 'Политика возврата', '30-day returns' => 'Возврат в течение 30 дней',
            'Refund'          => 'Возврат средств', 'Replacement' => 'Замена', 'Shipping address' => 'Адрес доставки', 'Customer support' => 'Служба поддержки',
        ],
        'th' => [
            'Phone case'      => 'เคสโทรศัพท์', 'Screen protector' => 'ฟิล์มกันรอยหน้าจอ', 'Wireless charger' => 'ที่ชาร์จไร้สาย',
            'Free shipping'   => 'จัดส่งฟรี', 'Worldwide shipping' => 'จัดส่งทั่วโลก', 'Order tracking' => 'ติดตามคำสั่งซื้อ',
            'Tracking number' => 'หมายเลขติดตามพัสดุ', 'Return policy' => 'นโยบายการคืนสินค้า', '30-day returns' => 'คืนสินค้าได้ภายใน 30 วัน',
            'Refund'          => 'คืนเงิน', 'Replacement' => 'เปลี่ยนสินค้า', 'Shipping address' => 'ที่อยู่จัดส่ง', 'Customer support' => 'ฝ่ายบริการลูกค้า',
        ],
        'tr' => [
            'Phone case'      => 'Telefon kılıfı', 'Screen protector' => 'Ekran koruyucu', 'Wireless charger' => 'Kablosuz şarj cihazı',
            'Free shipping'   => 'Ücretsiz kargo', 'Worldwide shipping' => 'Dünya çapında gönderim', 'Order tracking' => 'Sipariş takibi',
            'Tracking number' => 'Takip numarası', 'Return policy' => 'İade politikası', '30-day returns' => '30 gün içinde iade',
            'Refund'          => 'Para iadesi', 'Replacement' => 'Değişim', 'Shipping address' => 'Teslimat adresi', 'Customer support' => 'Müşteri desteği',
        ],
        'vi' => [
            'Phone case'      => 'Ốp lưng điện thoại', 'Screen protector' => 'Miếng dán bảo vệ màn hình', 'Wireless charger' => 'Bộ sạc không dây',
            'Free shipping'   => 'Miễn phí vận chuyển', 'Worldwide shipping' => 'Giao hàng toàn cầu', 'Order tracking' => 'Theo dõi đơn hàng',
            'Tracking number' => 'Mã theo dõi', 'Return policy' => 'Chính sách đổi trả', '30-day returns' => 'Đổi trả trong 30 ngày',
            'Refund'          => 'Hoàn tiền', 'Replacement' => 'Đổi hàng', 'Shipping address' => 'Địa chỉ giao hàng', 'Customer support' => 'Hỗ trợ khách hàng',
        ],
        'zh-cn' => [
            'Phone case'      => '手机壳', 'Screen protector' => '屏幕保护膜', 'Wireless charger' => '无线充电器',
            'Free shipping'   => '免费配送', 'Worldwide shipping' => '全球配送', 'Order tracking' => '订单追踪',
            'Tracking number' => '物流单号', 'Return policy' => '退货政策', '30-day returns' => '30 天内退货',
            'Refund'          => '退款', 'Replacement' => '换货', 'Shipping address' => '配送地址', 'Customer support' => '客户服务',
        ],
        'zh-tw' => [
            'Phone case'      => '手機殼', 'Screen protector' => '螢幕保護貼', 'Wireless charger' => '無線充電器',
            'Free shipping'   => '免運費', 'Worldwide shipping' => '全球配送', 'Order tracking' => '訂單追蹤',
            'Tracking number' => '物流單號', 'Return policy' => '退貨政策', '30-day returns' => '30 天內退貨',
            'Refund'          => '退款', 'Replacement' => '換貨', 'Shipping address' => '配送地址', 'Customer support' => '客戶服務',
        ],
    ];

    /** @return array<string, string> exact source phrase => required output */
    public static function protectedTerms(string $sourceLocale, string $targetLocale): array
    {
        if (self::locale($sourceLocale) !== 'en') {
            return [];
        }

        $terms = array_fill_keys(self::INVARIANT_TERMS, '');
        foreach ($terms as $term => $replacement) {
            $terms[$term] = $term;
        }
        $terms = array_replace($terms, self::COMMERCE_TERMS[self::locale($targetLocale)] ?? []);
        uksort($terms, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return $terms;
    }

    /**
     * The returned context is intentionally an enum/field description only:
     * it never contains source copy, visitor IP, or transactional data.
     *
     * @return array{kind:string,field:string,domain:string,prompt:string,profile_version:string}
     */
    public static function context(string $sourceLocale, string $targetLocale, string $kind, string $field): array
    {
        $target  = self::locale($targetLocale);
        $rules   = self::LANGUAGE_RULES[$target] ?? [
            'language' => $targetLocale,
            'grammar'  => 'Use natural target-language grammar, part of speech, tense, agreement, plurality, word order and punctuation.',
            'style'    => 'Clear, concise neutral retail copy.',
        ];
        $domain  = self::domain($kind, $field);
        $prompt  = sprintf(
            'Cross-border e-commerce storefront. Field type: %s. Translate from English into %s. Keep part of speech, tense, grammatical number and attribute/value relationships accurate. Apply these grammar and localisation rules: %s %s Preserve protected placeholders and approved product terminology exactly.',
            $domain,
            $rules['language'],
            $rules['grammar'],
            $rules['style'],
        );

        // Keep provider-side Context small, deterministic and free of text
        // copied from a tenant's public content.
        return [
            'kind'            => $kind,
            'field'           => $field,
            'domain'          => $domain,
            'prompt'          => mb_substr($prompt, 0, 480),
            'profile_version' => self::VERSION,
        ];
    }

    private static function domain(string $kind, string $field): string
    {
        if ($kind === 'product') {
            return str_starts_with($field, 'variables.') ? 'catalogue attribute or product variant label' : (str_contains($field, 'content') ? 'product description' : 'product title');
        }
        if (in_array($kind, ['category', 'page_category'], true)) {
            return 'catalogue navigation or collection copy';
        }
        if ($kind === 'page') {
            return str_contains($field, 'content') ? 'store policy or editorial page body' : 'store policy or editorial page heading';
        }
        if ($kind === 'builder') {
            return str_contains($field, '.node.') ? 'storefront marketing body copy' : 'storefront marketing label or heading';
        }
        if ($kind === CommerceUiCatalog::KIND) {
            return 'static storefront, cart, checkout or account interface label';
        }

        return 'storefront navigation or merchant-authored copy';
    }

    private static function locale(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));

        return match ($locale) {
            'zh'    => 'zh-cn',
            'pt-br' => 'pt',
            default => $locale,
        };
    }
}
