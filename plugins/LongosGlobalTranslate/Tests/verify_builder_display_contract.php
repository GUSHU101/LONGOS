<?php

// Runtime-independent PHP regression check for active published Builder
// display-field discovery. Run with PHP 8.2+, mbstring and DOM extensions:
// php LongosGlobalTranslate/Tests/verify_builder_display_contract.php
require dirname(__DIR__) . '/Services/ContentScanner.php';

use Plugin\LongosGlobalTranslate\Services\ContentScanner;

$fields = ContentScanner::builderDisplayFields([
    'slides' => [
        ['heading' => ['en' => 'Our best picks', 'fr' => 'Nos choix'], 'buttonLabel' => 'Browse the collection', 'image' => 'https://cdn.example.com/a.webp'],
        ['heading' => 'Hidden slide', 'visible' => false],
        ['heading' => 'Second visible slide', 'description' => 'Discover more'],
    ],
    'blocks' => [
        ['headline' => 'Fresh arrivals', 'cta_text' => 'Shop now', 'seo_title' => 'Secret SEO title', 'meta_description' => 'SEO summary'],
        ['title' => 'Disabled card', 'show' => false],
        ['title' => 'Another card', 'price' => '99.95', 'customer_name' => 'Private shopper', 'email' => 'private@example.com'],
    ],
    'seo'         => ['title' => 'Never send to translator'],
    'tracking'    => ['label' => 'Pixel tracking'],
    'footer_text' => 'Limited-time offer',
    'logo_src'    => 'https://cdn.example.com/logo.svg',
    'alt_text'    => 'Only image metadata',
]);

$plain  = $fields['plain'];
$expect = static function (bool $pass, string $name): void {
    if (! $pass) {
        throw new RuntimeException('FAIL: ' . $name);
    }
};

$expect(($plain['slides.0.heading'] ?? null) === 'Our best picks', 'English locale map');
$expect(($plain['slides.0.buttonLabel'] ?? null) === 'Browse the collection', 'Camel-case button');
$expect(($plain['slides.2.description'] ?? null) === 'Discover more', 'Visible nested card');
$expect(($plain['blocks.0.headline'] ?? null) === 'Fresh arrivals', 'New module headline');
$expect(($plain['blocks.0.cta_text'] ?? null) === 'Shop now', 'New module CTA');
$expect(($plain['footer_text'] ?? null) === 'Limited-time offer', 'Public merchant copy');
foreach (['slides.1.heading', 'slides.0.image', 'blocks.0.seo_title', 'blocks.0.meta_description',
    'blocks.1.title', 'blocks.2.price', 'blocks.2.customer_name', 'blocks.2.email',
    'seo.title', 'tracking.label', 'logo_src', 'alt_text'] as $privateField) {
    $expect(! array_key_exists($privateField, $plain), 'Private or hidden field: ' . $privateField);
}
$expect(! str_contains(json_encode($plain, JSON_UNESCAPED_UNICODE), 'Nos choix'), 'No non-English locale');

echo "PASS: visible published Builder text, dynamic fields and privacy exclusions\n";
