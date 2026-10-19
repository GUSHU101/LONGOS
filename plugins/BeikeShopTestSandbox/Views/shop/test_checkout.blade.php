@php
    $inputMode = \Plugin\BeikeShopTestSandbox\Support\TestCardCatalog::inputMode();
    $cartItems = array_values($checkoutCart['carts'] ?? []);
    $cartQuantity = (int) ($checkoutCart['quantity'] ?? 0);
    $cartAmount = $checkoutCart['amount_format'] ?? currency_format(0);
    $checkoutCountries = $checkoutCountries ?? [
        ['code' => 'US', 'name' => 'United States'], ['code' => 'CA', 'name' => 'Canada'],
        ['code' => 'GB', 'name' => 'United Kingdom'], ['code' => 'AU', 'name' => 'Australia'],
        ['code' => 'CN', 'name' => 'China'], ['code' => 'JP', 'name' => 'Japan'],
    ];
@endphp

<section class="bts-checkout" data-api="{{ url('/api/beikeshop-test/checkout') }}" data-checkout-url="{{ url('/checkout') }}" data-input-mode="{{ $inputMode }}" data-order-total="{{ $cartAmount }}">
    <style>
        .bts-checkout{--bts-ink:#1f2239;--bts-soft:#656a7e;--bts-muted:#8b90a3;--bts-line:#e4e5eb;--bts-panel:#fff;--bts-violet:#6156f5;--bts-indigo:#202447;min-height:100vh;background:#fff;color:var(--bts-ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.bts-checkout *{box-sizing:border-box}.bts-skip{position:absolute;z-index:10;left:12px;top:-60px;border-radius:8px;background:#111827;padding:10px 14px;color:#fff;text-decoration:none}.bts-skip:focus{top:12px}.bts-shell{display:grid;min-height:100vh;grid-template-columns:minmax(340px,43%) minmax(0,57%)}.bts-summary{display:flex;background:radial-gradient(circle at 22% 12%,#4d50901f,transparent 28rem),linear-gradient(145deg,#1b1e3e,#111329);color:#f8f8ff;padding:clamp(28px,5vw,76px)}.bts-summary-inner{display:flex;width:min(100%,470px);flex:1;flex-direction:column}.bts-brand{display:inline-flex;align-items:center;gap:10px;color:#fff;font-size:22px;font-weight:760;letter-spacing:-.035em;text-decoration:none}.bts-brand-mark{display:grid;grid-template-columns:repeat(3,4px);gap:3px;width:22px;height:22px;align-content:center;justify-content:center;transform:rotate(15deg)}.bts-brand-mark i{width:4px;height:4px;border-radius:99px;background:#b49aff}.bts-brand-mark i:nth-child(2),.bts-brand-mark i:nth-child(4){background:#fff}.bts-return{display:inline-flex;align-items:center;gap:10px;width:max-content;margin-top:38px;color:#e3e4f5;font-size:14px;text-decoration:none}.bts-return:hover{text-decoration:underline;text-underline-offset:4px}.bts-order{display:grid;grid-template-columns:136px minmax(0,1fr);gap:23px;margin-top:48px}.bts-order-art{position:relative;min-height:164px;overflow:hidden;border-radius:15px;background:linear-gradient(145deg,#b9b8ce,#ecebf0 52%,#bebcc4);box-shadow:0 12px 25px #0003}.bts-order-art:before{position:absolute;left:31px;top:31px;width:72px;height:72px;border:9px solid #262944;border-radius:50%;box-shadow:0 20px 0 -6px #373a55;content:""}.bts-order-art:after{position:absolute;right:-27px;bottom:-23px;width:94px;height:94px;border:9px solid #7a7796;border-radius:50%;content:""}.bts-order-info{padding-top:8px}.bts-order-info h1{margin:0;font-size:clamp(20px,2vw,25px);font-weight:680;letter-spacing:-.035em}.bts-order-info p{margin:9px 0 0;color:#d6d8ec;line-height:1.55}.bts-order-meta{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:25px;color:#eeeefd;font-size:14px}.bts-divider{height:1px;margin:34px 0;background:#ffffff1f}.bts-total-list{display:grid;gap:16px;color:#ebebf7}.bts-total-line,.bts-total{display:flex;align-items:center;justify-content:space-between;gap:16px}.bts-total-line span:first-child{color:#dedff0}.bts-total-line strong{font-weight:560}.bts-total{margin-top:1px}.bts-total>span{font-size:20px;font-weight:650}.bts-total-price{display:flex;align-items:baseline;gap:8px}.bts-total-price small{color:#c2c4dd;font-size:12px}.bts-total-price strong{font-size:30px;letter-spacing:-.04em}.bts-secure{display:flex;gap:14px;margin-top:46px;color:#e6e6f6}.bts-shield{display:grid;width:31px;height:35px;flex:0 0 auto;place-items:center;border:1.5px solid currentColor;border-radius:10px 10px 14px 14px;font-size:16px}.bts-secure b{display:block;font-size:14px}.bts-secure p{max-width:260px;margin:4px 0 0;color:#c9cbdf;font-size:13px}.bts-summary-footer{display:flex;flex-wrap:wrap;gap:10px;margin-top:auto;padding-top:48px;color:#bfc1d8;font-size:12px}.bts-summary-footer a{color:inherit;text-decoration:none}.bts-summary-footer a:hover{text-decoration:underline}.bts-content{display:flex;background:#fff;padding:clamp(30px,6vw,84px)}.bts-form{width:min(100%,690px);margin:auto}.bts-section+.bts-section{margin-top:37px}.bts-section h2{margin:0 0 16px;font-size:19px;font-weight:710;letter-spacing:-.025em}.bts-field{display:grid;gap:7px;margin-top:12px;color:#4e5266;font-size:13px;font-weight:640}.bts-field input,.bts-field select{width:100%;min-height:54px;border:1px solid var(--bts-line);border-radius:9px;background:#fff;padding:12px 13px;color:var(--bts-ink);font:inherit;outline:0;transition:border-color .16s ease,box-shadow .16s ease}.bts-field input::placeholder{color:#a2a5b2}.bts-field input:focus,.bts-field select:focus,.bts-card-fields:focus-within{border-color:var(--bts-violet);box-shadow:0 0 0 3px #6156f51c}.bts-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.bts-profile{display:flex;align-items:center;gap:12px;min-height:54px;border:1px solid var(--bts-violet);border-radius:9px;background:#faf9ff;padding:0 15px;color:#493ddb}.bts-profile-icon{display:grid;width:23px;height:18px;place-items:center;border:2px solid currentColor;border-radius:4px;font-size:0}.bts-profile-icon:after{width:12px;height:2px;background:currentColor;content:""}.bts-profile label{flex:1;font-size:14px;font-weight:680}.bts-profile select{width:auto;min-height:38px;border:0;background:transparent;color:#3f36ba;font:inherit;font-weight:650;outline:0}.bts-payment-label{margin:17px 0 8px;color:var(--bts-soft);font-size:13px}.bts-card-fields{overflow:hidden;border:1px solid var(--bts-line);border-radius:9px;background:#fff;transition:border-color .16s ease,box-shadow .16s ease}.bts-card-number{display:flex;align-items:center;min-height:54px;border-bottom:1px solid var(--bts-line)}.bts-card-number input{width:100%;min-width:0;border:0;background:transparent;padding:14px;color:var(--bts-ink);font:16px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.05em;outline:0}.bts-card-network{display:flex;align-items:center;gap:6px;padding-right:12px}.bts-card-network span{display:inline-grid;min-width:30px;height:20px;place-items:center;border:1px solid #dfe1e8;border-radius:4px;padding:0 4px;color:#5358a3;font-size:8px;font-weight:850}.bts-card-network span:nth-child(2){color:#ba4c4c}.bts-card-network span:nth-child(3){color:#2e8c93}.bts-card-sub{display:grid;grid-template-columns:1fr 1fr}.bts-card-sub .bts-field{position:relative;margin:0}.bts-card-sub .bts-field+.bts-field{border-left:1px solid var(--bts-line)}.bts-card-sub label{position:absolute;z-index:1;left:13px;top:7px;color:var(--bts-muted);font-size:11px;font-weight:540}.bts-card-sub input{min-height:56px;border:0;border-radius:0;padding:24px 13px 6px;font-size:14px}.bts-consent{display:flex;align-items:flex-start;gap:10px;margin-top:18px;color:#5e6374;font-size:13px}.bts-consent input{width:18px;height:18px;flex:0 0 auto;margin:1px 0 0;accent-color:var(--bts-violet)}.bts-pay{width:100%;min-height:54px;margin-top:24px;border:0;border-radius:8px;background:linear-gradient(90deg,#6559f5,#5434df);box-shadow:0 8px 18px #604de032;color:#fff;font:700 16px inherit;cursor:pointer;transition:transform .16s ease,filter .16s ease,box-shadow .16s ease}.bts-pay:hover{transform:translateY(-1px);filter:brightness(1.04);box-shadow:0 12px 22px #604de040}.bts-pay:disabled{cursor:not-allowed;opacity:.6;transform:none}.bts-pay:focus-visible,.bts-profile select:focus-visible,.bts-summary a:focus-visible,.bts-consent input:focus-visible{outline:3px solid #9e9aff;outline-offset:3px}.bts-status{margin:18px 0 0;border-left:3px solid #6156f5;background:#f7f6ff;padding:12px 14px;color:#45446b;font-size:13px}.bts-status--error{border-color:#c43f55;background:#fff2f4;color:#9e263b}.bts-error{min-height:1.4em;margin:12px 0 0;color:#b4233c;font-size:13px}.bts-terms{display:flex;justify-content:center;gap:8px;margin:17px auto 0;max-width:530px;color:#888c99;font-size:11px;line-height:1.55;text-align:center}.bts-terms a{color:#5a5d69;text-decoration:underline;text-underline-offset:2px}.bts-otp{margin-top:22px;border:1px solid #dcd9ff;border-radius:11px;background:#faf9ff;padding:20px}.bts-otp h2{margin:0;font-size:17px}.bts-otp p{margin:6px 0 14px;color:#646283;font-size:13px}.bts-otp-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}.bts-otp input{min-height:48px;border:1px solid #c9c4ff;border-radius:8px;padding:10px;color:#2c2865;font:700 18px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.2em;text-align:center}.bts-otp button{min-height:48px;border:0;border-radius:8px;background:#27244a;padding:0 17px;color:#fff;font-weight:700;cursor:pointer}.bts-otp small{display:block;margin-top:10px;color:#77748e}.bts-checkout button:focus-visible,.bts-checkout input:focus-visible,.bts-checkout select:focus-visible{outline:3px solid #8079ff;outline-offset:2px}@media (max-width:900px){.bts-shell{grid-template-columns:1fr}.bts-summary{padding:28px 24px}.bts-summary-inner{max-width:none}.bts-order{grid-template-columns:94px minmax(0,1fr);margin-top:30px}.bts-order-art{min-height:118px}.bts-order-art:before{left:22px;top:21px;width:51px;height:51px;border-width:7px;box-shadow:0 16px 0 -5px #373a55}.bts-divider{margin:27px 0}.bts-secure{margin-top:28px}.bts-summary-footer{display:none}.bts-content{padding:38px 24px 52px}.bts-form{max-width:690px}}@media (max-width:520px){.bts-summary,.bts-content{padding-left:16px;padding-right:16px}.bts-brand{font-size:20px}.bts-order{gap:15px}.bts-order-info h1{font-size:19px}.bts-order-info p{display:none}.bts-order-meta{margin-top:13px}.bts-two,.bts-card-sub,.bts-otp-row{grid-template-columns:1fr}.bts-card-sub .bts-field+.bts-field{border-top:1px solid var(--bts-line);border-left:0}.bts-card-number input{font-size:14px}.bts-card-network span:nth-child(3){display:none}.bts-total-price strong{font-size:26px}}
        @media (prefers-reduced-motion:reduce){.bts-checkout *{scroll-behavior:auto!important;transition:none!important}}
    </style>
    <style>
        .bts-checkout .sr-only{position:absolute;width:1px;height:1px;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}.bts-card-number input{flex:1}.bts-card-number input[readonly]{cursor:default;color:#54596a}.bts-reveal{display:inline-grid;width:44px;height:44px;flex:0 0 44px;place-items:center;border:0;background:transparent;color:#666b7c;cursor:pointer}.bts-reveal:hover{color:var(--bts-violet)}.bts-reveal svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round;stroke-width:1.8}.bts-card-sub-field{position:relative;min-width:0}.bts-card-sub-field+.bts-card-sub-field{border-left:1px solid var(--bts-line)}.bts-card-sub-field>label{position:absolute;z-index:1;left:13px;top:7px;color:var(--bts-muted);font-size:11px;font-weight:540}.bts-card-sub-field>input{width:100%;min-height:56px;border:0;border-radius:0;padding:24px 50px 6px 13px;color:var(--bts-ink);font:14px inherit;outline:0}.bts-card-sub-field .bts-reveal{position:absolute;right:3px;bottom:5px}.bts-order-list{display:grid;gap:22px}.bts-order-list .bts-order{margin-top:0}.bts-order-art img{width:100%;height:100%;object-fit:cover}@media(max-width:520px){.bts-card-sub-field+.bts-card-sub-field{border-top:1px solid var(--bts-line);border-left:0}}
    </style>
    <style>
        .bts-email-feedback{min-height:1.2em;color:#b4233c;font-size:12px;font-weight:540}.bts-address-control{margin-top:12px}.bts-address-control .bts-field{margin-top:0}.bts-address-feedback{display:block;min-height:1.2em;margin-top:7px;color:#676b7b;font-size:12px}.bts-phone-layout,.bts-phone-control{display:grid;gap:12px}.bts-phone-layout{grid-template-columns:112px minmax(0,1fr)}.bts-phone-control{grid-template-columns:96px minmax(0,1fr)}.bts-phone-layout .bts-field,.bts-phone-control .bts-field{min-width:0}.bts-phone-prefix{color:#4a426f!important;background:#faf9ff!important;font-variant-numeric:tabular-nums}.bts-profile-label{flex:1;color:#493ddb;font-size:14px;font-weight:680}.bts-profile-note{margin-left:auto;color:#74708c;font-size:12px;font-weight:560}.bts-summary h1,.bts-summary strong,.bts-summary b,.bts-summary .bts-total,.bts-summary .bts-order-meta{color:#f5f7ff!important}.bts-summary .bts-order-info p,.bts-summary .bts-total-line span:first-child,.bts-summary .bts-secure p{color:#ced4ed!important}.bts-summary-footer{background:transparent!important;border:0!important;color:#c9d0e9!important}.bts-summary-footer a{color:#e6e9fb!important}.bts-card-network{pointer-events:none}.bts-card-network span{min-width:36px}.bts-terms{border-radius:8px;background:#fafafd;padding:12px 14px;color:#626779}.bts-terms a{color:#4d46bf;font-weight:650}.bts-status{border-radius:0 8px 8px 0}@media(max-width:640px){.bts-phone-layout{grid-template-columns:1fr}.bts-phone-control{grid-template-columns:90px minmax(0,1fr)}.bts-profile-note{display:none}.bts-terms{font-size:11px;text-align:left}.bts-card-network span{min-width:31px;font-size:7px}}@media(max-width:360px){.bts-phone-control{grid-template-columns:78px minmax(0,1fr)}.bts-card-network{gap:3px;padding-right:5px}.bts-card-network span{min-width:27px;padding:0 2px}.bts-card-number input{padding-left:10px;padding-right:6px;font-size:13px}}
    </style>
    <style>
        /* Checkout controls use comfortable desktop type and preserve a single-column
           reading order for phone then country. */
        .bts-checkout{font-size:16px}.bts-form{max-width:720px}.bts-section h2{font-size:21px}.bts-field{font-size:14px}.bts-field input,.bts-field select{font-size:16px}.bts-phone-layout{grid-template-columns:minmax(0,1fr)}.bts-country-field{width:min(100%,360px)}.bts-phone-control{grid-template-columns:104px minmax(0,1fr)}.bts-address-control{position:relative}.bts-address-suggestions{position:relative;z-index:3;max-height:264px;margin-top:8px;overflow:auto;border:1px solid #d8d8ea;border-radius:10px;background:#fff;box-shadow:0 12px 28px #1e22401a}.bts-address-suggestion{display:grid;width:100%;min-height:52px;gap:2px;border:0;border-bottom:1px solid #ececf3;background:#fff;padding:10px 14px;color:#242843;text-align:left;cursor:pointer}.bts-address-suggestion:last-child{border-bottom:0}.bts-address-suggestion:hover,.bts-address-suggestion:focus-visible{background:#f4f2ff;outline:3px solid #8079ff;outline-offset:-3px}.bts-address-suggestion strong{font-size:14px}.bts-address-suggestion small{color:#666b7d;font-size:13px}.bts-billing{margin-top:24px;border-top:1px solid #ececf2;padding-top:22px}.bts-billing h3{margin:0 0 4px;font-size:18px;letter-spacing:-.02em}.bts-billing-error{display:block;min-height:1.35em;margin-top:7px;color:#b4233c;font-size:13px;font-weight:600}.bts-consent{font-size:14px}.bts-card-network{display:grid;min-width:104px;gap:1px;padding:5px 12px 5px 4px;text-align:right}.bts-card-network__name{min-width:0!important;height:auto!important;border:0!important;background:transparent!important;padding:0!important;color:#4046a7!important;font-size:12px!important;font-weight:850!important;line-height:1.15}.bts-card-network__state{color:#777b90;font-size:10px;font-weight:650;line-height:1.15}.bts-card-network[data-network="visa"] .bts-card-network__name{color:#1434cb!important}.bts-card-network[data-network="mastercard"] .bts-card-network__name{color:#c04025!important}.bts-card-network[data-network="amex"] .bts-card-network__name{color:#006fcf!important}.bts-card-network[data-network="discover"] .bts-card-network__name{color:#d35c0d!important}.bts-card-network[data-network="dinersclub"] .bts-card-network__name{color:#0079be!important}.bts-card-network[data-network="jcb"] .bts-card-network__name{color:#097a47!important}.bts-card-network[data-network="unionpay"] .bts-card-network__name{color:#c72d43!important}.bts-3ds,.bts-card-loader{width:min(calc(100% - 32px),460px);max-width:460px;border:0;border-radius:18px;background:#fff;padding:0;overflow:hidden;box-shadow:0 28px 80px #10142a55}.bts-3ds::backdrop,.bts-card-loader::backdrop{background:#10142a99;backdrop-filter:blur(5px)}.bts-3ds-frame{display:block;width:100%;height:min(720px,calc(100vh - 32px));border:0;background:#fff}.bts-3ds-frame:focus-visible{outline:3px solid #8079ff;outline-offset:-3px}.bts-card-loader__body{display:grid;min-height:290px;place-items:center;align-content:center;padding:34px 28px;text-align:center}.bts-card-loader__stage{position:relative;width:236px;height:74px;margin-bottom:13px}.bts-card-loader__track{position:absolute;left:24px;right:24px;top:36px;height:2px;background:linear-gradient(90deg,#1434cb33,#ef6a003d);overflow:hidden}.bts-card-loader__track:after{position:absolute;inset:-2px 0;background:linear-gradient(90deg,transparent,#fff,transparent);animation:bts-network-scan 1s ease-in-out infinite;content:""}.bts-card-loader__visa,.bts-card-loader__mastercard{position:absolute;z-index:1;top:8px;display:grid;width:76px;height:56px;place-items:center;border-radius:11px;background:#fff;box-shadow:0 8px 22px #1d27521f}.bts-card-loader__visa{left:0;border:1px solid #dfe5ff;color:#1434cb;font-family:Arial,sans-serif;font-size:20px;font-weight:900;font-style:italic;letter-spacing:-1px;animation:bts-visa-exchange 1s ease-in-out infinite}.bts-card-loader__mastercard{right:0;border:1px solid #f2ded6}.bts-card-loader__mastercard i{position:absolute;width:31px;height:31px;border-radius:50%}.bts-card-loader__mastercard i:first-child{left:15px;background:#eb001b}.bts-card-loader__mastercard i:last-child{right:15px;background:#f79e1b;mix-blend-mode:multiply}.bts-card-loader__match{position:absolute;z-index:2;left:50%;top:20px;display:grid;width:34px;height:34px;place-items:center;border:2px solid #6759f5;border-radius:50%;background:#fff;color:#4c3dde;font-size:18px;font-weight:900;transform:translateX(-50%);box-shadow:0 0 0 0 #6659f544;animation:bts-network-match 1s ease-in-out infinite}.bts-card-loader__eyebrow{margin:0 0 5px;color:#6357e8;font-size:11px;font-weight:850;letter-spacing:.12em}.bts-card-loader h2{margin:0;color:#222542;font-size:clamp(20px,5vw,25px);letter-spacing:-.035em}.bts-card-loader p:last-child{margin:9px 0 0;color:#656a7e;font-size:14px;line-height:1.5}@keyframes bts-visa-exchange{0%,100%{transform:translateX(0)}50%{transform:translateX(48px)}}@keyframes bts-network-scan{0%{transform:translateX(-120%)}100%{transform:translateX(120%)}}@keyframes bts-network-match{0%,100%{box-shadow:0 0 0 0 #6659f544;transform:translateX(-50%) scale(1)}50%{box-shadow:0 0 0 12px #6659f500;transform:translateX(-50%) scale(1.12)}}@media(prefers-reduced-motion:reduce){.bts-card-loader__track:after,.bts-card-loader__visa,.bts-card-loader__match{animation:none}}@media(max-width:640px){.bts-checkout{font-size:15px}.bts-content{padding-left:18px;padding-right:18px}.bts-phone-control{grid-template-columns:88px minmax(0,1fr)}.bts-country-field{width:100%}.bts-field input,.bts-field select{font-size:16px}.bts-3ds,.bts-card-loader{width:calc(100% - 24px);max-width:none}.bts-3ds-frame{height:calc(100vh - 24px)}.bts-card-network{min-width:76px;padding-right:8px}.bts-card-network__state{font-size:9px}}@media(max-width:360px){.bts-phone-control{grid-template-columns:76px minmax(0,1fr)}.bts-card-network{min-width:64px}.bts-card-network__state{display:none}.bts-card-loader__stage{transform:scale(.88)}}
    </style>

    <style>
        /* Reading comfort: checkout and challenge text must remain legible at
           normal desktop distance and on compact touch screens. */
        .bts-checkout{font-size:17px}.bts-section h2{font-size:22px}.bts-field{font-size:15px}.bts-field input,.bts-field select{font-size:17px}.bts-payment-label,.bts-consent,.bts-status,.bts-error{font-size:15px}.bts-card-sub-field>label{font-size:12px}.bts-card-sub-field>input{font-size:16px}.bts-card-number input{font-size:17px}.bts-card-network__name{font-size:13px!important}.bts-card-network__state{font-size:11px}.bts-card-loader p:last-child{font-size:16px}@media(max-width:640px){.bts-checkout{font-size:16px}.bts-section h2{font-size:21px}.bts-field{font-size:14px}.bts-field input,.bts-field select{font-size:16px}.bts-payment-label,.bts-consent,.bts-status,.bts-error{font-size:14px}}
    </style>

    <style>
        /* Both network marks converge during the short TEST SANDBOX verification. */
        .bts-card-loader__mastercard{animation:bts-mastercard-exchange 1s ease-in-out infinite}
        @keyframes bts-mastercard-exchange{0%,100%{transform:translateX(0)}50%{transform:translateX(-48px)}}
        @media(prefers-reduced-motion:reduce){.bts-card-loader__mastercard{animation:none}}
    </style>
    <a class="bts-skip" href="#checkout-form">Skip to checkout</a>
    <div class="bts-shell">
        <aside class="bts-summary" aria-label="Order summary">
            <div class="bts-summary-inner">
                <a class="bts-brand" href="{{ url('/') }}" aria-label="Return to the home page"><span class="bts-brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span></a>
                <a class="bts-return" href="{{ url('/') }}"><span aria-hidden="true">←</span>Return to store</a>

                <div class="bts-order-list">
                    @foreach ($cartItems as $item)
                        <article class="bts-order">
                            <div class="bts-order-art" aria-hidden="true">@if (!empty($item['image']))<img src="{{ image_resize($item['image'], 240, 280) }}" alt="">@endif</div>
                            <div class="bts-order-info"><h1>{{ $item['name'] ?? '' }}</h1>@if (!empty($item['variant_labels']))<p>{{ $item['variant_labels'] }}</p>@endif<div class="bts-order-meta"><span>Qty {{ $item['quantity'] ?? 0 }}</span><strong>{!! $item['subtotal_format'] ?? $item['price_format'] ?? '' !!}</strong></div></div>
                        </article>
                    @endforeach
                </div>

                <div class="bts-divider"></div>
                <div class="bts-total-list"><div class="bts-total-line"><span>Items ({{ $cartQuantity }})</span><strong>{!! $cartAmount !!}</strong></div></div>
                <div class="bts-divider"></div>
                <div class="bts-total"><span>Total</span><div class="bts-total-price"><strong>{!! $cartAmount !!}</strong></div></div>

                <div class="bts-secure"><span class="bts-shield" aria-hidden="true">✓</span><div><b>Secure checkout</b><p>Your payment details are encrypted in transit and at rest.</p></div></div>
                <footer class="bts-summary-footer"><a href="https://stripe.com/privacy" target="_blank" rel="noopener noreferrer">Privacy</a><span>·</span><a href="https://stripe.com/legal/consumer" target="_blank" rel="noopener noreferrer">Terms</a><span>·</span><a href="{{ route('shop.beikeshop_test_sandbox.refund_policy') }}">Refunds</a></footer>
            </div>
        </aside>

        <main id="checkout-main" class="bts-content">
            <form id="checkout-form" class="bts-form" data-form autocomplete="on" novalidate>
                <div class="bts-section">
                    <label class="bts-field" for="checkout-email">Email<input id="checkout-email" data-email type="email" autocomplete="email" placeholder="name@example.com" required aria-required="true" aria-describedby="checkout-email-feedback"></label><small id="checkout-email-feedback" class="bts-email-feedback" data-email-feedback aria-live="polite"></small>
                </div>

                <section class="bts-section" aria-labelledby="delivery-title">
                    <h2 id="delivery-title">Delivery information</h2>
                    <label class="bts-field" for="checkout-name">Full name<input id="checkout-name" data-name type="text" autocomplete="name" maxlength="80" required aria-required="true"></label>
                    <div class="bts-phone-layout"><label class="bts-field" for="checkout-phone">Phone<div class="bts-phone-control"><input class="bts-phone-prefix" data-dial-code type="text" inputmode="tel" autocomplete="tel-country-code" value="" maxlength="8" required aria-label="Country calling code" placeholder="+1"><input id="checkout-phone" data-phone type="tel" inputmode="tel" autocomplete="tel-national" maxlength="24" required aria-required="true" placeholder="Phone number"></div></label><label class="bts-field bts-country-field" for="checkout-country">Country / region<select id="checkout-country" data-country autocomplete="country" required aria-required="true"><option value="" selected disabled>Select country / region</option>@foreach ($checkoutCountries as $country)<option value="{{ $country['code'] }}">{{ $country['name'] }}</option>@endforeach</select></label></div>
                    <div class="bts-address-control"><label class="bts-field" for="checkout-address">Street address<input id="checkout-address" data-address type="text" autocomplete="street-address" maxlength="160" required aria-required="true" aria-autocomplete="list" aria-controls="checkout-address-suggestions" aria-expanded="false" aria-describedby="checkout-address-feedback"></label><div id="checkout-address-suggestions" class="bts-address-suggestions" data-address-suggestions role="listbox" aria-label="Suggested addresses" hidden></div><small id="checkout-address-feedback" class="bts-address-feedback" data-address-feedback aria-live="polite">Start typing to see address suggestions. Select one to fill city, postal code and country / region.</small></div>
                    <div class="bts-two"><label class="bts-field" for="checkout-city">City<input id="checkout-city" data-city type="text" autocomplete="address-level2" placeholder="City"></label><label class="bts-field" for="checkout-postcode">Postal code<input id="checkout-postcode" data-postcode type="text" autocomplete="postal-code" placeholder="Postal code"></label></div>
                </section>

                <section class="bts-section" aria-labelledby="payment-title">
                    <h2 id="payment-title">Payment method</h2>
                    <div class="bts-profile"><span class="bts-profile-icon" aria-hidden="true"></span><span class="bts-profile-label">Card</span><span class="bts-profile-note">Stripe</span></div>
                    <p class="bts-payment-label">Card information</p>
                    <div class="bts-card-fields">
                        <div class="bts-card-number"><label class="sr-only" for="checkout-pan">Card number</label><input id="checkout-pan" data-pan type="text" inputmode="numeric" autocomplete="cc-number" placeholder="1234 1234 1234 1234" maxlength="23" required aria-required="true"><button class="bts-reveal" type="button" data-pan-reveal aria-label="Show full card number" aria-pressed="false" title="Show full card number"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button><div class="bts-card-network" data-card-network data-network="card" aria-live="polite" aria-label="Card network detection"><span class="bts-card-network__name" data-card-network-name>Card</span><small class="bts-card-network__state" data-card-network-state>Enter card number</small></div></div>
                        <div class="bts-card-sub"><div class="bts-card-sub-field"><label for="checkout-expiry">Expiration date</label><input id="checkout-expiry" data-expiry type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM / YY" maxlength="5" required aria-required="true"></div><div class="bts-card-sub-field"><label for="checkout-cvv">Security code</label><input id="checkout-cvv" data-cvv type="password" inputmode="numeric" autocomplete="cc-csc" placeholder="CVC" maxlength="4" required aria-required="true"><button class="bts-reveal" type="button" data-cvv-reveal aria-label="Show security code" aria-pressed="false" title="Show security code"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></button></div></div>
                    </div>
                    <section class="bts-billing" aria-labelledby="billing-title"><h3 id="billing-title">Billing address</h3><div class="bts-address-control"><label class="bts-field" for="checkout-billing-address">Billing street address<input id="checkout-billing-address" data-billing-address type="text" autocomplete="billing street-address" maxlength="160" required aria-required="true" aria-autocomplete="list" aria-controls="checkout-billing-address-suggestions" aria-expanded="false" aria-describedby="checkout-billing-error checkout-billing-address-feedback" placeholder="Start typing a billing address"></label><div id="checkout-billing-address-suggestions" class="bts-address-suggestions" data-billing-address-suggestions role="listbox" aria-label="Suggested billing addresses" hidden></div><small id="checkout-billing-address-feedback" class="bts-address-feedback" data-billing-address-feedback aria-live="polite">Start typing to see billing address suggestions. You can also enter an address manually.</small></div><small id="checkout-billing-error" class="bts-billing-error" data-billing-error aria-live="polite"></small><label class="bts-consent"><input data-consent type="checkbox"><span>My billing address is the same as my delivery address.</span></label></section>
                    <button class="bts-pay" type="submit" data-submit>Pay {!! $cartAmount !!}</button>
                    <p class="bts-error" data-error role="alert" aria-live="assertive"></p>
                    <p class="bts-terms"><span aria-hidden="true">▣</span><span>By completing your purchase, you agree to the <a href="https://stripe.com/legal/consumer" target="_blank" rel="noopener noreferrer">Stripe Consumer Terms</a>, <a href="https://stripe.com/privacy" target="_blank" rel="noopener noreferrer">Stripe Privacy Policy</a> and our <a href="{{ route('shop.beikeshop_test_sandbox.refund_policy') }}">Refund Policy</a>.</span></p>
                    <p class="bts-status" data-state aria-live="polite">Complete your payment details to continue.</p>
                </section>
            </form>
        </main>
    </div>
    <dialog class="bts-card-loader" data-card-loader aria-labelledby="card-loader-title"><div class="bts-card-loader__body" role="status" aria-live="assertive"><div class="bts-card-loader__stage" aria-hidden="true"><span class="bts-card-loader__track"></span><span class="bts-card-loader__visa">VISA</span><span class="bts-card-loader__mastercard"><i></i><i></i></span><span class="bts-card-loader__match">✓</span></div><p class="bts-card-loader__eyebrow"> CARD NETWORK CHECK</p><h2 id="card-loader-title">Verifying card organization</h2><p data-card-loader-text>Matching card network and preparing secure verification…</p></div></dialog>
    <dialog class="bts-3ds" data-3ds aria-label="3D Secure verification"><iframe class="bts-3ds-frame" data-3ds-frame title="
    3D Secure verification" sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox"></iframe></dialog>

    <script>
    (() => {
        const root = document.currentScript.closest('.bts-checkout');
        const api = root.dataset.api;
        const threeDsTemplates = {
            visa: @json(route('shop.beikeshop_test_sandbox.three_ds', ['network' => 'visa'])),
            mastercard: @json(route('shop.beikeshop_test_sandbox.three_ds', ['network' => 'mastercard'])),
            amex: @json(route('shop.beikeshop_test_sandbox.three_ds', ['network' => 'amex'])),
            discover: @json(route('shop.beikeshop_test_sandbox.three_ds', ['network' => 'discover'])),
            dinersclub: @json(route('shop.beikeshop_test_sandbox.three_ds', ['network' => 'dinersclub'])),
            jcb: @json(route('shop.beikeshop_test_sandbox.three_ds', ['network' => 'jcb'])),
            unionpay: @json(route('shop.beikeshop_test_sandbox.three_ds', ['network' => 'unionpay'])),
        };
        const $ = selector => root.querySelector(selector);
        const state = { orderId: null, challengeId: null, poll: null, events: null, realtimeFallback: null, addressTimer: null, addressRequest: 0, billingAddressTimer: null, billingAddressRequest: 0, loaderTimer: null, redirectTimer: null, challengeStartedAt: null, challengeExpiresAt: null, pan: '', panVisible: false, cvvVisible: false, pageActive: true, redirecting: false };
        const showDialogSafely = dialog => {
            if (!dialog) return false;
            try {
                if (typeof dialog.showModal === 'function') {
                    if (!dialog.open) dialog.showModal();
                } else {
                    dialog.setAttribute('open', '');
                    dialog.style.display = 'block';
                }
                return true;
            } catch (_) {
                return false;
            }
        };
        const closeDialogSafely = dialog => {
            if (!dialog) return;
            try {
                if (typeof dialog.close === 'function' && dialog.open) dialog.close();
                else { dialog.removeAttribute('open'); dialog.style.display = 'none'; }
            } catch (_) {
                dialog.removeAttribute('open');
                dialog.style.display = 'none';
            }
        };
        const digits = value => String(value || '').replace(/\D/g, '');
        const formatPan = (value, amex) => {
            const pan = digits(value).slice(0, 19);
            return amex ? [pan.slice(0, 4), pan.slice(4, 10), pan.slice(10, 15)].filter(Boolean).join(' ') : pan.replace(/(\d{4})(?=\d)/g, '$1 ');
        };
        const deviceSignals = () => ({ userAgent:navigator.userAgent || '', platform:navigator.platform || '', language:navigator.language || '', timezone:Intl.DateTimeFormat().resolvedOptions().timeZone || '', screenWidth:screen.width || 0, screenHeight:screen.height || 0, colorDepth:screen.colorDepth || 0, hardwareConcurrency:navigator.hardwareConcurrency || 0, deviceMemory:typeof navigator.deviceMemory === 'number' ? navigator.deviceMemory : null, maxTouchPoints:navigator.maxTouchPoints || 0, cookieEnabled:navigator.cookieEnabled });
        const setState = (text, error = false) => { const status = $('[data-state]'); status.textContent = text; status.classList.toggle('bts-status--error', error); };
        const jsonBody = async response => { try { return await response.json(); } catch (_) { return null; } };
        const showTransientFailure = () => { $('[data-error]').textContent = ''; setState('The service is temporarily unavailable. Please try again shortly.', true); };
        // Country calling codes cover the enabled BeikeShop catalogue. The
        // prefix remains editable for shared codes and exceptional territories.
        const dialCodes = { AF:'+93',AL:'+355',DZ:'+213',AS:'+1684',AD:'+376',AO:'+244',AI:'+1264',AG:'+1268',AR:'+54',AM:'+374',AW:'+297',AU:'+61',AT:'+43',AZ:'+994',BS:'+1242',BH:'+973',BD:'+880',BB:'+1246',BY:'+375',BE:'+32',BZ:'+501',BJ:'+229',BM:'+1441',BT:'+975',BO:'+591',BA:'+387',BW:'+267',BR:'+55',IO:'+246',BN:'+673',BG:'+359',BF:'+226',BI:'+257',CV:'+238',KH:'+855',CM:'+237',CA:'+1',KY:'+1345',CF:'+236',TD:'+235',CL:'+56',CN:'+86',CX:'+61',CC:'+61',CO:'+57',KM:'+269',CG:'+242',CD:'+243',CK:'+682',CR:'+506',CI:'+225',HR:'+385',CU:'+53',CW:'+599',CY:'+357',CZ:'+420',DK:'+45',DJ:'+253',DM:'+1767',DO:'+1809',EC:'+593',EG:'+20',SV:'+503',GQ:'+240',ER:'+291',EE:'+372',SZ:'+268',ET:'+251',FK:'+500',FO:'+298',FJ:'+679',FI:'+358',FR:'+33',GF:'+594',PF:'+689',GA:'+241',GM:'+220',GE:'+995',DE:'+49',GH:'+233',GI:'+350',GR:'+30',GL:'+299',GD:'+1473',GP:'+590',GU:'+1671',GT:'+502',GG:'+44',GN:'+224',GW:'+245',GY:'+592',HT:'+509',HN:'+504',HK:'+852',HU:'+36',IS:'+354',IN:'+91',ID:'+62',IR:'+98',IQ:'+964',IE:'+353',IM:'+44',IL:'+972',IT:'+39',JM:'+1876',JP:'+81',JE:'+44',JO:'+962',KZ:'+7',KE:'+254',KI:'+686',KP:'+850',KR:'+82',KW:'+965',KG:'+996',LA:'+856',LV:'+371',LB:'+961',LS:'+266',LR:'+231',LY:'+218',LI:'+423',LT:'+370',LU:'+352',MO:'+853',MG:'+261',MW:'+265',MY:'+60',MV:'+960',ML:'+223',MT:'+356',MH:'+692',MQ:'+596',MR:'+222',MU:'+230',YT:'+262',MX:'+52',FM:'+691',MD:'+373',MC:'+377',MN:'+976',ME:'+382',MS:'+1664',MA:'+212',MZ:'+258',MM:'+95',NA:'+264',NR:'+674',NP:'+977',NL:'+31',NC:'+687',NZ:'+64',NI:'+505',NE:'+227',NG:'+234',NU:'+683',NF:'+672',MK:'+389',MP:'+1670',NO:'+47',OM:'+968',PK:'+92',PW:'+680',PS:'+970',PA:'+507',PG:'+675',PY:'+595',PE:'+51',PH:'+63',PN:'+64',PL:'+48',PT:'+351',PR:'+1787',QA:'+974',RE:'+262',RO:'+40',RU:'+7',RW:'+250',BL:'+590',SH:'+290',KN:'+1869',LC:'+1758',MF:'+590',PM:'+508',VC:'+1784',WS:'+685',SM:'+378',ST:'+239',SA:'+966',SN:'+221',RS:'+381',SC:'+248',SL:'+232',SG:'+65',SX:'+1721',SK:'+421',SI:'+386',SB:'+677',SO:'+252',ZA:'+27',GS:'+500',SS:'+211',ES:'+34',LK:'+94',SD:'+249',SR:'+597',SJ:'+47',SE:'+46',CH:'+41',SY:'+963',TW:'+886',TJ:'+992',TZ:'+255',TH:'+66',TL:'+670',TG:'+228',TK:'+690',TO:'+676',TT:'+1868',TN:'+216',TR:'+90',TM:'+993',TC:'+1649',TV:'+688',UG:'+256',UA:'+380',AE:'+971',GB:'+44',US:'+1',UY:'+598',UZ:'+998',VU:'+678',VA:'+379',VE:'+58',VN:'+84',VG:'+1284',VI:'+1340',WF:'+681',EH:'+212',YE:'+967',ZM:'+260',ZW:'+263' };
        const normaliseEmail = value => String(value || '').trim().replace(/\s+/g, '').replace(/＠/g, '@').toLowerCase();
        const isEmail = value => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u.test(value);
        const validateEmail = (showMessage = false) => {
            const input = $('[data-email]'); const feedback = $('[data-email-feedback]');
            input.value = normaliseEmail(input.value);
            const valid = isEmail(input.value);
            input.setAttribute('aria-invalid', String(showMessage && !valid));
            feedback.textContent = showMessage && !valid ? 'Enter a valid email address, for example name@example.com.' : '';
            return valid;
        };
        const syncDialCode = () => { const code = dialCodes[$('[data-country]').value]; if (code) $('[data-dial-code]').value = code; };
        const addressNote = text => { $('[data-address-feedback]').textContent = text; };
        const countryOption = code => Array.from($('[data-country]').options).some(option => option.value === code);
        const fillCountry = country => { if (country && countryOption(country)) { $('[data-country]').value = country; syncDialCode(); return true; } return false; };
        const clearAddressSuggestions = () => { const list = $('[data-address-suggestions]'); list.replaceChildren(); list.hidden = true; $('[data-address]').setAttribute('aria-expanded', 'false'); };
        const fillAddressParts = address => {
            const city = address.city || address.town || address.village || address.locality || address.municipality || address.county || '';
            const country = String(address.country_code || address.countrycode || '').toUpperCase();
            if (city) $('[data-city]').value = city;
            if (address.postcode) $('[data-postcode]').value = address.postcode;
            fillCountry(country);
            return Boolean(city || address.postcode || (country && countryOption(country)));
        };
        const mailboxSuggestion = query => {
            if (!/\bP(?:OST)?\.?\s*O(?:FFICE)?\.?\s*BOX\b/i.test(query)) return null;
            const withoutBox = query.replace(/^.*?\bBOX\s*[A-Z0-9-]+[\s,]*/i, '').trim();
            const match = withoutBox.match(/^(.+?)[\s,]+([A-Z]{2})\s+(\d{5}(?:-\d{4})?)\s*$/i);
            const usStates = new Set(['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC']);
            if (!match || !usStates.has(match[2].toUpperCase())) return null;
            return { label: `Use ${match[1].trim()}, ${match[2].toUpperCase()} ${match[3]}`, detail: 'P.O. Box address details', street: query, address: { city: match[1].trim(), postcode: match[3], countrycode: 'US' } };
        };
        const renderAddressSuggestions = suggestions => {
            const list = $('[data-address-suggestions]');
            list.replaceChildren();
            if (!suggestions.length) { list.hidden = true; $('[data-address]').setAttribute('aria-expanded', 'false'); return; }
            suggestions.forEach(suggestion => {
                const option = document.createElement('button'); option.type = 'button'; option.className = 'bts-address-suggestion'; option.setAttribute('role', 'option');
                const label = document.createElement('strong'); label.textContent = suggestion.label;
                const detail = document.createElement('small'); detail.textContent = suggestion.detail;
                option.append(label, detail);
                option.addEventListener('click', () => { $('[data-address]').value = suggestion.street; fillAddressParts(suggestion.address); clearAddressSuggestions(); syncBillingWhenMatched(); addressNote('Address details filled from your selected suggestion. Please review before continuing.'); });
                list.append(option);
            });
            list.hidden = false; $('[data-address]').setAttribute('aria-expanded', 'true');
        };
        const findAddressSuggestions = async () => {
            const query = $('[data-address]').value.trim();
            if (query.length < 3) { clearAddressSuggestions(); return addressNote('Enter at least 3 characters to see address suggestions.'); }
            const requestId = ++state.addressRequest;
            addressNote('Searching address suggestions…');
            const localSuggestion = mailboxSuggestion(query);
            if (localSuggestion) { renderAddressSuggestions([localSuggestion]); return addressNote('Select the suggested P.O. Box details to fill the address fields.'); }
            try {
                const url = new URL('https://photon.komoot.io/api/');
                url.search = new URLSearchParams({ q: query, limit: '5', lang: 'en' }).toString();
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const result = await jsonBody(response);
                if (requestId !== state.addressRequest) return;
                if (!response.ok || !Array.isArray(result?.features)) throw new Error();
                const suggestions = result.features.map(feature => {
                    const p = feature?.properties || {}; const street = [p.housenumber, p.street || p.name].filter(Boolean).join(' ').trim() || query;
                    const detail = [p.city || p.town || p.village || p.locality, p.state, p.postcode, p.country].filter(Boolean).join(', ');
                    return { label: street, detail: detail || 'Address suggestion', street, address: { city: p.city || p.town || p.village || p.locality || '', postcode: p.postcode || '', countrycode: p.countrycode || '' } };
                }).filter(suggestion => suggestion.label);
                renderAddressSuggestions(suggestions);
                addressNote(suggestions.length ? 'Choose an address suggestion to fill the remaining delivery fields.' : 'No address suggestions found. You can complete the fields manually.');
            } catch (_) {
                if (requestId === state.addressRequest) { clearAddressSuggestions(); addressNote('Address suggestions are temporarily unavailable. You can complete the fields manually.'); }
            }
        };
        const billingAddressNote = text => { $('[data-billing-address-feedback]').textContent = text; };
        const clearBillingAddressSuggestions = () => {
            const list = $('[data-billing-address-suggestions]');
            list.replaceChildren();
            list.hidden = true;
            $('[data-billing-address]').setAttribute('aria-expanded', 'false');
        };
        const renderBillingAddressSuggestions = suggestions => {
            const list = $('[data-billing-address-suggestions]');
            list.replaceChildren();
            if (!suggestions.length) {
                list.hidden = true;
                $('[data-billing-address]').setAttribute('aria-expanded', 'false');
                return;
            }
            suggestions.forEach(suggestion => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'bts-address-suggestion';
                option.setAttribute('role', 'option');
                const label = document.createElement('strong'); label.textContent = suggestion.label;
                const detail = document.createElement('small'); detail.textContent = suggestion.detail;
                option.append(label, detail);
                option.addEventListener('click', () => {
                    $('[data-billing-address]').value = suggestion.value;
                    $('[data-billing-error]').textContent = '';
                    clearBillingAddressSuggestions();
                    billingAddressNote('Billing address selected. Please review it before continuing.');
                });
                list.append(option);
            });
            list.hidden = false;
            $('[data-billing-address]').setAttribute('aria-expanded', 'true');
        };
        const findBillingAddressSuggestions = async () => {
            const query = $('[data-billing-address]').value.trim();
            if (query.length < 3) {
                clearBillingAddressSuggestions();
                return billingAddressNote('Enter at least 3 characters to see billing address suggestions.');
            }
            const requestId = ++state.billingAddressRequest;
            billingAddressNote('Searching billing address suggestions…');
            const localSuggestion = mailboxSuggestion(query);
            if (localSuggestion) {
                renderBillingAddressSuggestions([{ label: localSuggestion.label, detail: localSuggestion.detail, value: localSuggestion.street }]);
                return billingAddressNote('Select the suggested P.O. Box address or continue typing.');
            }
            try {
                const url = new URL('https://photon.komoot.io/api/');
                url.search = new URLSearchParams({ q: query, limit: '5', lang: 'en' }).toString();
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const result = await jsonBody(response);
                if (requestId !== state.billingAddressRequest) return;
                if (!response.ok || !Array.isArray(result?.features)) throw new Error();
                const suggestions = result.features.map(feature => {
                    const p = feature?.properties || {};
                    const street = [p.housenumber, p.street || p.name].filter(Boolean).join(' ').trim() || query;
                    const detail = [p.city || p.town || p.village || p.locality, p.state, p.postcode, p.country].filter(Boolean).join(', ');
                    const value = [street, p.postcode, p.city || p.town || p.village || p.locality, p.state, p.country].filter(Boolean).join(', ').slice(0, 160);
                    return { label: street, detail: detail || 'Billing address suggestion', value };
                }).filter(suggestion => suggestion.value);
                renderBillingAddressSuggestions(suggestions);
                billingAddressNote(suggestions.length ? 'Choose a billing address suggestion or continue entering it manually.' : 'No billing address suggestions found. You can complete the field manually.');
            } catch (_) {
                if (requestId === state.billingAddressRequest) {
                    clearBillingAddressSuggestions();
                    billingAddressNote('Billing address suggestions are temporarily unavailable. You can complete the field manually.');
                }
            }
        };
        const maskPan = value => { const pan = digits(value); if (pan.length <= 4) return pan; return `${pan.slice(0, 4)}  ••••  ••••  ${pan.slice(-4)}`; };
        const isAmex = () => /^3[47]/.test(state.pan);
        const networkFromPrefix = pan => {
            if (/^4/.test(pan)) return { key: 'visa', label: 'Visa' };
            if (/^(?:5[1-5]|2(?:2[2-9]|[3-6]\d|7[01]|720))/.test(pan)) return { key: 'mastercard', label: 'Mastercard' };
            if (/^3[47]/.test(pan)) return { key: 'amex', label: 'American Express' };
            if (/^3(?:0[0-5]|[68])/.test(pan)) return { key: 'dinersclub', label: 'Diners Club' };
            if (/^35(?:2[89]|[3-8]\d)/.test(pan)) return { key: 'jcb', label: 'JCB' };
            if (/^6(?:011|5|4[4-9]|22[1-9]|2[3-9]\d|[3-4]\d{2}|5(?:[0-8]\d|9[0-9]))/.test(pan)) return { key: 'discover', label: 'Discover' };
            if (/^62/.test(pan)) return { key: 'unionpay', label: 'UnionPay' };
            return { key: 'visa', label: 'Card' };
        };
        const cardNetwork = pan => ({ ...networkFromPrefix(pan), ready: /^\d{12,19}$/.test(pan) });
        const renderCardNetwork = () => { const network = cardNetwork(state.pan); const chip = $('[data-card-network]'); const hasDigits = Boolean(state.pan); chip.dataset.network = network.ready ? network.key : 'card'; $('[data-card-network-name]').textContent = network.ready ? network.label : (hasDigits ? 'Detecting…' : 'Card'); $('[data-card-network-state]').textContent = network.ready ? '3DS ready' : (hasDigits ? 'Continue entering' : 'Enter card number'); chip.setAttribute('aria-label', network.ready ? `${network.label} detected. 3D Secure challenge ready.` : (hasDigits ? 'Card organization detection in progress.' : 'Enter a card number to detect its organization.')); return network; };
        const renderPan = () => { const input = $('[data-pan]'); const reveal = $('[data-pan-reveal]'); input.value = !state.pan ? '' : (state.panVisible ? formatPan(state.pan, isAmex()) : maskPan(state.pan)); input.readOnly = Boolean(state.pan && !state.panVisible); input.setAttribute('aria-label', state.panVisible ? 'Full card number' : 'Masked card number'); reveal.setAttribute('aria-pressed', String(state.panVisible)); reveal.setAttribute('aria-label', state.panVisible ? 'Hide full card number' : 'Show full card number'); reveal.title = state.panVisible ? 'Hide full card number' : 'Show full card number'; renderCardNetwork(); };
        const renderCvv = () => { const input = $('[data-cvv]'); const reveal = $('[data-cvv-reveal]'); input.type = state.cvvVisible ? 'text' : 'password'; reveal.setAttribute('aria-pressed', String(state.cvvVisible)); reveal.setAttribute('aria-label', state.cvvVisible ? 'Hide security code' : 'Show security code'); reveal.title = state.cvvVisible ? 'Hide security code' : 'Show security code'; };
        const challengeDate = () => new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(state.challengeStartedAt || new Date());
        const maskedChallengePan = network => { const last4 = state.pan.slice(-4) || '••••'; return network.key === 'amex' ? `•••• •••••• •${last4}` : `•••• •••• •••• ${last4}`; };
        const postChallenge = (type, payload = {}) => {
            const frame = $('[data-3ds-frame]');
            if (frame?.contentWindow && state.challengeId) frame.contentWindow.postMessage({ type: `beikeshop-test:${type}`, challengeId: state.challengeId, ...payload }, window.location.origin);
        };
        const closeRealtime = () => {
            clearTimeout(state.realtimeFallback);
            state.realtimeFallback = null;
            if (state.events) state.events.close();
            state.events = null;
        };
        const returnToCheckout = () => {
            const destination = new URL(root.dataset.checkoutUrl, window.location.origin);
            if (destination.href === window.location.href) {
                window.location.reload();
                return;
            }
            try { window.location.assign(destination.href); } catch (_) { window.location.href = destination.href; }
        };
        const challengePayload = () => {
            const network = renderCardNetwork();
            return {
                challengeId: state.challengeId,
                cardholder: $('[data-name]').value.trim() || 'Customer',
                amount: root.dataset.orderTotal || '—',
                date: challengeDate(),
                card: maskedChallengePan(network),
            };
        };
        const applyServerState = (orderId, challengeId, serverState) => {
            if (!serverState || state.orderId !== orderId || state.challengeId !== challengeId || state.redirecting) return;
            if (serverState === 'APPROVED') { completeApprovedPayment(); return; }
            if (serverState === 'CARD_UNSUPPORTED') { showUnsupportedCardAndReturn(); return; }
            if (serverState === 'OTP_REQUIRED') {
                restartThreeDsAfterRejection();
                setState('Verification code is incorrect or has expired.', true);
                return;
            }
            if (serverState === 'WAITING_REVIEW') {
                setState('Verification code submitted. Waiting for manual review…');
                return;
            }
            if (serverState === 'CANCELLED') {
                cancelSandboxSession();
            }
        };
        const startRealtime = () => {
            closeRealtime();
            const streamOrderId = state.orderId;
            const streamChallengeId = state.challengeId;
            if (!streamOrderId || !streamChallengeId || typeof EventSource !== 'function') { poll(); return; }

            const source = new EventSource(`${api}/events/${encodeURIComponent(streamOrderId)}`);
            state.events = source;
            source.addEventListener('state', event => {
                try {
                    const payload = JSON.parse(event.data || '{}');
                    if (!payload?.success) throw new Error();
                    clearTimeout(state.realtimeFallback);
                    state.realtimeFallback = null;
                    applyServerState(streamOrderId, streamChallengeId, payload.order?.state);
                } catch (_) {
                    // A malformed stream frame never changes checkout state.
                }
            });
            source.onopen = () => {
                if (state.events !== source) return;
                clearTimeout(state.realtimeFallback);
                state.realtimeFallback = null;
            };
            source.onerror = () => {
                if (state.events !== source || state.orderId !== streamOrderId || state.challengeId !== streamChallengeId) return;
                clearTimeout(state.realtimeFallback);
                state.realtimeFallback = setTimeout(() => {
                    if (state.events !== source || state.orderId !== streamOrderId || state.challengeId !== streamChallengeId) return;
                    source.close();
                    state.events = null;
                    poll(300);
                }, 4000);
            };
        };
        const showUnsupportedCardAndReturn = () => {
            if (state.redirecting) return;
            state.redirecting = true;
            clearTimeout(state.poll);
            closeRealtime();
            postChallenge('terminal', { message: 'This card is not supported for this purchase. Please try another card.', buttonText: 'Returning…' });
            setState('This card is not supported for this purchase. Please try another card.', true);
            state.redirectTimer = window.setTimeout(() => {
                closeThreeDs();
                returnToCheckout();
            }, 1800);
        };
        const completeApprovedPayment = () => {
            if (state.redirecting) return;
            state.redirecting = true;
            clearTimeout(state.poll);
            closeRealtime();
            closeCardLoader();
            postChallenge('terminal', { message: 'This payment method is not supported.', buttonText: 'Returning…' });
            setState('This payment method is not supported.', true);
            $('[data-submit]').disabled = true;
            state.redirectTimer = window.setTimeout(() => {
                closeThreeDs();
                returnToCheckout();
            }, 1800);
        };
        const submitSandboxOtp = async code => {
            const submittingOrderId = state.orderId;
            const submittingChallengeId = state.challengeId;
            const expired = state.challengeExpiresAt && Date.now() >= state.challengeExpiresAt;
            if (!submittingOrderId || !submittingChallengeId || expired || !/^(\d{4}|\d{6})$/.test(code)) {
                postChallenge('otp-error', { message: expired ? 'This TEST verification code expired. Enter a new code.' : 'Enter a valid TEST verification code.' });
                return;
            }
            try {
                const response = await fetch(`${api}/verify`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ orderId: submittingOrderId, sandboxCode: code }) });
                const payload = await jsonBody(response);
                if (state.orderId !== submittingOrderId || state.challengeId !== submittingChallengeId) return;
                if (!response.ok || !payload?.success) throw new Error(payload?.message || 'The TEST OTP could not be submitted.');
                postChallenge('otp-waiting');
                setState('Verification code submitted. Waiting for manual review…');
                startRealtime();
            } catch (error) {
                postChallenge('otp-error', { message: error instanceof Error ? error.message : 'The TEST OTP could not be submitted. Please try again.' });
                setState('The TEST OTP could not be submitted. Please try again.', true);
            }
        };
        const prepareThreeDsFrame = (retryMessage = '') => {
            const frame = $('[data-3ds-frame]');
            const network = renderCardNetwork();
            if (!frame) return;
            const templateUrl = new URL(threeDsTemplates[network.key] || threeDsTemplates.visa, window.location.origin);
            templateUrl.searchParams.set('sandbox', String(Date.now()));
            frame.src = templateUrl.toString();
        };
        const restartThreeDsAfterRejection = () => {
            if (!state.pageActive || state.redirecting) return;
            state.challengeStartedAt = new Date();
            state.challengeExpiresAt = Date.now() + 300000;
            if (!showDialogSafely($('[data-3ds]'))) return;
            postChallenge('reset-3ds', { message: 'Verification code is incorrect or has expired.' });
        };
        const newChallengeId = () => typeof crypto?.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
        const openThreeDs = (retryMessage = '') => { if (!state.pageActive) return; const dialog = $('[data-3ds]'); if (!state.challengeStartedAt) state.challengeStartedAt = new Date(); state.challengeExpiresAt = Date.now() + 300000; state.challengeId = newChallengeId(); if (!showDialogSafely(dialog)) return; prepareThreeDsFrame(retryMessage); };
        const closeThreeDs = () => { closeDialogSafely($('[data-3ds]')); clearTimeout(state.poll); closeRealtime(); };
        const cancelSandboxSession = () => {
            const cancelledOrderId = state.orderId;
            closeThreeDs();
            closeCardLoader();
            state.orderId = null;
            state.challengeId = null;
            state.challengeStartedAt = null;
            state.challengeExpiresAt = null;
            state.redirecting = false;
            $('[data-submit]').disabled = false;
            setState('verification was cancelled. You can edit the checkout details and try again.');
            window.setTimeout(() => { $('[data-billing-address]')?.focus(); }, 0);

            if (!cancelledOrderId) return;
            fetch(`${api}/cancel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ orderId: cancelledOrderId }),
            }).then(async response => {
                const payload = await jsonBody(response);
                if (!response.ok || !payload?.success) throw new Error(payload?.message || 'Unable to close TEST session.');
            }).catch(() => {
                if (state.orderId) return;
                // The checkout remains editable even if the audit-only
                // cancellation request is unavailable; no payment is made.
                setState('Checkout is editable. The TEST session close will be retried on the next checkout attempt.', true);
            });
        };
        const closeCardLoader = () => { clearTimeout(state.loaderTimer); closeDialogSafely($('[data-card-loader]')); };
        const openCardLoader = () => {
            if (!state.pageActive) return;
            const loader = $('[data-card-loader]'); const network = renderCardNetwork(); const name = network.label === 'Card' ? 'card organization' : network.label;
            $('[data-card-loader-text]').textContent = `Verifying ${name} …`;
            if (!showDialogSafely(loader)) return; clearTimeout(state.loaderTimer);
            state.loaderTimer = window.setTimeout(() => { closeCardLoader(); openThreeDs(); }, 1050);
        };
        const poll = (delay = 900) => {
            clearTimeout(state.poll);
            const polledOrderId = state.orderId;
            const polledChallengeId = state.challengeId;
            if (!state.pageActive || !polledOrderId || !polledChallengeId || state.redirecting) return;
            state.poll = setTimeout(async () => {
                if (!state.pageActive || state.orderId !== polledOrderId || state.challengeId !== polledChallengeId || state.redirecting) return;
                try {
                    const response = await fetch(`${api}/status/${encodeURIComponent(polledOrderId)}`, { headers:{Accept:'application/json'} });
                    if (!response.ok) throw new Error();
                    const payload = await jsonBody(response);
                    if (state.orderId !== polledOrderId || state.challengeId !== polledChallengeId) return;
                    const status = payload?.order?.state;
                    if (status === 'APPROVED') { completeApprovedPayment(); return; }
                    if (status === 'CARD_UNSUPPORTED') { showUnsupportedCardAndReturn(); return; }
                    if (status === 'OTP_REQUIRED') { restartThreeDsAfterRejection(); setState('Verification code is incorrect or has expired.', true); return; }
                    poll(900);
                } catch (_) {
                    if (state.orderId === polledOrderId && state.challengeId === polledChallengeId) poll(Math.min(Math.round(delay * 1.6), 4000));
                }
            }, delay);
        };

        window.addEventListener('message', event => {
            const frame = $('[data-3ds-frame]');
            if (event.origin !== window.location.origin || event.source !== frame?.contentWindow || !event.data?.type) return;
            const data = event.data;
            if (data.type === 'beikeshop-test:challenge-ready' && state.orderId && state.challengeId) {
                postChallenge('challenge-init', { ...challengePayload() });
                return;
            }
            if (!data.challengeId || data.challengeId !== state.challengeId || !state.orderId) return;
            if (data.type === 'beikeshop-test:otp-submit') submitSandboxOtp(digits(data.code));
            if (data.type === 'beikeshop-test:challenge-cancel') {
                cancelSandboxSession();
            }
        });

        $('[data-pan-reveal]').addEventListener('click', () => { state.panVisible = !state.panVisible; renderPan(); if (state.panVisible) { $('[data-pan]').focus(); $('[data-pan]').setSelectionRange($('[data-pan]').value.length, $('[data-pan]').value.length); } });
        $('[data-cvv-reveal]').addEventListener('click', () => { state.cvvVisible = !state.cvvVisible; renderCvv(); if (state.cvvVisible) $('[data-cvv]').focus(); });
        $('[data-pan]').addEventListener('focus', () => { state.panVisible = true; renderPan(); });
        $('[data-pan]').addEventListener('blur', () => { state.panVisible = false; renderPan(); });
        $('[data-pan]').addEventListener('input', () => { state.panVisible = true; state.pan = digits($('[data-pan]').value).slice(0, 19); renderPan(); });
        $('[data-expiry]').addEventListener('input', () => { const value = digits($('[data-expiry]').value).slice(0, 4); $('[data-expiry]').value = value.length > 2 ? `${value.slice(0, 2)}/${value.slice(2)}` : value; });
        $('[data-email]').addEventListener('blur', () => validateEmail(true));
        $('[data-email]').addEventListener('input', () => { $('[data-email]').setAttribute('aria-invalid', 'false'); $('[data-email-feedback]').textContent = ''; });
        const deliveryAddressValue = () => [$('[data-address]').value.trim(), $('[data-city]').value.trim(), $('[data-postcode]').value.trim(), $('[data-country]').selectedOptions[0]?.textContent.trim()].filter(Boolean).join(', ').slice(0, 160);
        const syncBillingWhenMatched = () => { if (!$('[data-consent]').checked) return; $('[data-billing-address]').value = deliveryAddressValue(); $('[data-billing-error]').textContent = ''; };
        $('[data-country]').addEventListener('change', () => { syncDialCode(); syncBillingWhenMatched(); });
        $('[data-address]').addEventListener('input', () => { clearTimeout(state.addressTimer); addressNote('Searching starts after you pause typing. Select a suggestion to fill the remaining fields.'); syncBillingWhenMatched(); state.addressTimer = setTimeout(findAddressSuggestions, 500); });
        $('[data-address]').addEventListener('blur', () => { window.setTimeout(clearAddressSuggestions, 180); });
        $('[data-city]').addEventListener('input', syncBillingWhenMatched);
        $('[data-postcode]').addEventListener('input', syncBillingWhenMatched);
        $('[data-consent]').addEventListener('change', syncBillingWhenMatched);
        $('[data-billing-address]').addEventListener('input', () => {
            $('[data-billing-error]').textContent = '';
            clearTimeout(state.billingAddressTimer);
            billingAddressNote('Searching starts after you pause typing. You can also enter an address manually.');
            state.billingAddressTimer = setTimeout(findBillingAddressSuggestions, 500);
        });
        $('[data-billing-address]').addEventListener('blur', () => { window.setTimeout(clearBillingAddressSuggestions, 180); });
        $('[data-card-loader]').addEventListener('cancel', event => { event.preventDefault(); });
        $('[data-3ds]').addEventListener('cancel', event => { event.preventDefault(); cancelSandboxSession(); });

        $('[data-form]').addEventListener('submit', async event => {
            event.preventDefault();
            const pan = state.pan; const cvv = digits($('[data-cvv]').value); const expiry = $('[data-expiry]').value; const error = $('[data-error]');
            const validCvv = /^\d{3,4}$/.test(cvv);
            error.textContent = '';
            if (state.orderId) return error.textContent = 'A verification is already in progress. Please complete the current 3D Secure challenge.';
            if (!validateEmail(true)) return error.textContent = 'Enter a valid email address.';
            if (!/^\d{12,19}$/.test(pan)) return error.textContent = 'Enter a card number with 12 to 19 digits.';
            const phone = `${$('[data-dial-code]').value} ${digits($('[data-phone]').value)}`.trim();
            const billingAddress = $('[data-billing-address]').value.trim();
            if (!billingAddress) { $('[data-billing-error]').textContent = 'Enter your billing address before paying.'; $('[data-billing-address]').focus(); return error.textContent = 'Billing address is required.'; }
            if (!/^(0[1-9]|1[0-2])\/\d{2}$/.test(expiry) || !validCvv || $('[data-name]').value.trim().length < 2 || !$('[data-country]').value || !/^\+\d{1,4}$/.test($('[data-dial-code]').value.trim()) || digits($('[data-phone]').value).length < 4 || $('[data-address]').value.trim().length < 6) return error.textContent = 'Complete all required payment and delivery details.';
            try {
                $('[data-submit]').disabled = true;
                setState('Processing your payment details…');
                const response = await fetch(api, { method:'POST', headers:{'Content-Type':'application/json',Accept:'application/json'}, body:JSON.stringify({fullName:$('[data-name]').value.trim(),phone,billingAddress,cardNumber:pan,expiryDate:expiry,cvv,deviceSignals:deviceSignals(),demoConsent:true}) });
                const payload = await jsonBody(response);
                if (!response.ok || !payload?.success) throw new Error();
                state.orderId = payload.orderId; state.challengeStartedAt = new Date(); setState('Card organization verified. Preparing the secure challenge…'); openCardLoader();
            } catch (_) { error.textContent = ''; setState('Payment details cannot be processed right now. Please check them and try again.', true); } finally { $('[data-submit]').disabled = Boolean(state.orderId); }
        });
        window.addEventListener('error', event => { event.preventDefault(); showTransientFailure(); });
        window.addEventListener('unhandledrejection', event => { event.preventDefault(); showTransientFailure(); });
        window.addEventListener('pagehide', () => { state.pageActive = false; [state.poll, state.addressTimer, state.billingAddressTimer, state.loaderTimer, state.redirectTimer].forEach(timer => clearTimeout(timer)); }, { once: true });
        renderPan();
        renderCvv();
    })();
    </script>
</section>
