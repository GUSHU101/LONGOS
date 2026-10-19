<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Beike\Repositories\StorePolicyRepo;

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$policies = StorePolicyRepo::normalizeSettings([
    'refund' => [
        'enabled' => '1',
        'title'   => '<strong>Returns</strong>',
        'content' => "First line\nSecond line\n\nSecond paragraph",
    ],
    'terms' => [
        'enabled' => true,
        'content' => '<h2 class="legal-title">Terms</h2><table><tr><th scope="col">Item</th></tr></table><script>alert(1)</script><a href="javascript:alert(2)">Unsafe</a>',
    ],
]);

$verify(array_keys($policies) === ['refund', 'shipping', 'privacy', 'terms', 'contact', 'payment', 'cancellation', 'cookies'], 'All eight standard storefront documents must be normalized.');
$verify($policies['refund']['enabled'] === true, 'String switch values must normalize to booleans.');
$verify($policies['refund']['title'] === 'Refund Policy', 'Policy titles must use the fixed standard label.');
$verify($policies['refund']['content'] === "<p>First line<br />\nSecond line</p><p>Second paragraph</p>", 'Plain text lines must become semantic HTML.');
$verify(str_contains($policies['terms']['content'], '<h2 class="legal-title">Terms</h2>'), 'Safe HTML headings must be retained.');
$verify(str_contains($policies['terms']['content'], '<th scope="col">Item</th>'), 'Safe HTML table attributes must be retained.');
$verify(! str_contains($policies['terms']['content'], '<script'), 'Script elements must be removed.');
$verify(! str_contains($policies['terms']['content'], 'javascript:'), 'Unsafe URL schemes must be removed.');
$verify(! StorePolicyRepo::hasMeaningfulContent('<p>&nbsp;&#8203;<br></p>'), 'Invisible-only markup must not enable a policy link.');
$verify(StorePolicyRepo::slugs() === ['refund-policy', 'shipping-policy', 'privacy-policy', 'terms-of-service', 'contact-us', 'payment-policy', 'cancellation-policy', 'cookie-policy'], 'Policy URLs must remain stable.');

fwrite(STDOUT, "Store policy assertions passed.\n");
