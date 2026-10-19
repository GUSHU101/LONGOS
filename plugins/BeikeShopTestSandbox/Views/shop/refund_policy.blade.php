@extends('layout.master')

@section('title', 'Refund Policy')
@section('minimal-checkout', '1')

@section('content')
    <main class="bts-policy" id="main-content">
        <style>
            .bts-policy{min-height:100vh;background:#fff;color:#25283a;font:15px/1.65 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.bts-policy *{box-sizing:border-box}.bts-policy__bar{border-bottom:1px solid #e8e9ef}.bts-policy__bar-inner{display:flex;align-items:center;justify-content:space-between;gap:18px;width:min(100% - 40px,1080px);margin:0 auto;padding:20px 0}.bts-policy__brand{color:#272a40;font-size:19px;font-weight:760;letter-spacing:-.03em;text-decoration:none}.bts-policy__back{color:#5c50db;font-size:14px;font-weight:650;text-decoration:none}.bts-policy__wrap{width:min(100% - 40px,760px);margin:0 auto;padding:72px 0 96px}.bts-policy__eyebrow{margin:0;color:#6559f5;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.bts-policy h1{margin:12px 0 14px;font-size:clamp(32px,5vw,48px);letter-spacing:-.055em;line-height:1.05}.bts-policy__lead{max-width:650px;margin:0;color:#626779;font-size:17px}.bts-policy__meta{margin:26px 0 42px;border:1px solid #e4e5eb;border-radius:12px;background:#fafaff;padding:16px 18px;color:#606579;font-size:13px}.bts-policy section{padding:27px 0;border-top:1px solid #e8e9ef}.bts-policy h2{margin:0 0 10px;font-size:20px;letter-spacing:-.025em}.bts-policy p,.bts-policy li{color:#555a6d}.bts-policy p{margin:0}.bts-policy ul{margin:12px 0 0;padding-left:22px}.bts-policy a{color:#5c50db;text-underline-offset:3px}.bts-policy a:focus-visible{outline:3px solid #a6a1ff;outline-offset:3px}@media(max-width:600px){.bts-policy__bar-inner,.bts-policy__wrap{width:min(100% - 32px,760px)}.bts-policy__wrap{padding:48px 0 68px}.bts-policy__bar-inner{padding:16px 0}.bts-policy__lead{font-size:16px}}
        </style>
        <header class="bts-policy__bar"><div class="bts-policy__bar-inner"><a class="bts-policy__brand" href="{{ url('/') }}">{{ site_brand_name() }}</a><a class="bts-policy__back" href="{{ url('/checkout') }}">Return to checkout</a></div></header>
        <article class="bts-policy__wrap">
            <p class="bts-policy__eyebrow">Customer policy</p>
            <h1>Refund Policy</h1>
            <p class="bts-policy__lead">Shop with confidence. Eligible items may be returned or exchanged at no charge within 30 days of delivery.</p>
            <p class="bts-policy__meta">Last updated: {{ now()->format('F j, Y') }} · This policy applies to orders placed through this store.</p>

            <section><h2>Free 30-day returns and exchanges</h2><p>You may request a return or exchange within 30 days after delivery. We do not charge a return handling fee. When an item is faulty, incorrect, or damaged in transit, we cover the applicable return shipping costs.</p></section>
            <section><h2>Eligibility</h2><ul><li>Items must be complete, clean, and suitable for resale;</li><li>Please keep your order details, accessories, gifts, and original packaging where applicable;</li><li>Custom, consumable, opened hygiene-sensitive, or otherwise non-returnable items may be excluded where permitted by law.</li></ul></section>
            <section><h2>Refund method and timing</h2><p>After the returned item is received and checked, the refund is issued to the original payment method. The time for funds to appear depends on your card issuer or payment provider.</p></section>
            <section><h2>How to request a return</h2><p>Use the after-sales option in your order details and include the order number and a brief description of the request. We will confirm the next steps as soon as possible.</p></section>
            <section><h2>Payments and privacy</h2><p>Payment services and privacy information are governed by the <a href="https://stripe.com/legal/consumer" target="_blank" rel="noopener noreferrer">Stripe Consumer Terms</a> and <a href="https://stripe.com/privacy" target="_blank" rel="noopener noreferrer">Stripe Privacy Policy</a>. This policy does not replace consumer rights granted by applicable law.</p></section>
        </article>
    </main>
@endsection
