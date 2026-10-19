@extends('admin::layouts.master')

@section('title', 'TEST 支付安全雷达')

@section('content')
<style>
    /* Admin ships Bootstrap, not Tailwind. Keep this screen self-contained so
       its component layout never depends on a theme/build-only utility sheet. */
    .radar-shell .hidden{display:none!important}.radar-shell .block{display:block}.radar-shell .flex{display:flex}.radar-shell .inline-flex{display:inline-flex}.radar-shell .grid{display:grid}.radar-shell .relative{position:relative}.radar-shell .absolute{position:absolute}.radar-shell .fixed{position:fixed}.radar-shell .sticky{position:sticky}.radar-shell .inset-0{inset:0}.radar-shell .top-0{top:0}.radar-shell .top-4{top:1rem}.radar-shell .top-7{top:1.75rem}.radar-shell .top-24{top:6rem}.radar-shell .top-\[44\.5\%\]{top:44.5%}.radar-shell .top-\[49\%\]{top:49%}.radar-shell .right-0{right:0}.radar-shell .right-4{right:1rem}.radar-shell .right-7{right:1.75rem}.radar-shell .right-\[8\.5\%\]{right:8.5%}.radar-shell .bottom-7{bottom:1.75rem}.radar-shell .bottom-9{bottom:2.25rem}.radar-shell .left-7{left:1.75rem}.radar-shell .z-10{z-index:10}.radar-shell .z-50{z-index:50}.radar-shell .z-\[60\]{z-index:60}.radar-shell .z-\[70\]{z-index:70}.radar-shell .z-\[90\]{z-index:90}.radar-shell .flex-1{flex:1 1 0%}.radar-shell .flex-col{flex-direction:column}.radar-shell .flex-wrap{flex-wrap:wrap}.radar-shell .items-center{align-items:center}.radar-shell .items-start{align-items:flex-start}.radar-shell .items-end{align-items:flex-end}.radar-shell .justify-between{justify-content:space-between}.radar-shell .justify-center{justify-content:center}.radar-shell .grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.radar-shell .grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.radar-shell .grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.radar-shell .gap-2{gap:.5rem}.radar-shell .gap-3{gap:.75rem}.radar-shell .gap-4{gap:1rem}.radar-shell .gap-6{gap:1.5rem}.radar-shell .space-y-2>:not([hidden])~:not([hidden]){margin-top:.5rem}.radar-shell .space-y-4>:not([hidden])~:not([hidden]){margin-top:1rem}.radar-shell .w-full{width:100%}.radar-shell .w-4{width:1rem}.radar-shell .w-12{width:3rem}.radar-shell .w-16{width:4rem}.radar-shell .w-\[105px\]{width:105px}.radar-shell .w-\[min\(380px\,calc\(100vw-2rem\)\)\]{width:min(380px,calc(100vw - 2rem))}.radar-shell .min-w-\[80px\]{min-width:80px}.radar-shell .min-w-\[2260px\]{min-width:2260px}.radar-shell .h-4{height:1rem}.radar-shell .h-12{height:3rem}.radar-shell .h-16{height:4rem}.radar-shell .h-full{height:100%}.radar-shell .max-w-2xl{max-width:42rem}.radar-shell .max-w-\[130px\]{max-width:130px}.radar-shell .max-w-\[170px\]{max-width:170px}.radar-shell .max-w-\[320px\]{max-width:320px}.radar-shell .overflow-hidden{overflow:hidden}.radar-shell .overflow-x-auto{overflow-x:auto}.radar-shell .overflow-y-auto{overflow-y:auto}.radar-shell .object-cover{object-fit:cover}.radar-shell .object-contain{object-fit:contain}.radar-shell .p-3{padding:.75rem}.radar-shell .p-4{padding:1rem}.radar-shell .p-5{padding:1.25rem}.radar-shell .p-12{padding:3rem}.radar-shell .px-2{padding-left:.5rem;padding-right:.5rem}.radar-shell .px-3{padding-left:.75rem;padding-right:.75rem}.radar-shell .px-4{padding-left:1rem;padding-right:1rem}.radar-shell .py-1{padding-top:.25rem;padding-bottom:.25rem}.radar-shell .py-2{padding-top:.5rem;padding-bottom:.5rem}.radar-shell .py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.radar-shell .py-3{padding-top:.75rem;padding-bottom:.75rem}.radar-shell .pt-5{padding-top:1.25rem}.radar-shell .\!px-0{padding-left:0!important;padding-right:0!important}.radar-shell .\!pt-0{padding-top:0!important}.radar-shell .mt-1{margin-top:.25rem}.radar-shell .mt-2{margin-top:.5rem}.radar-shell .mt-3{margin-top:.75rem}.radar-shell .mt-4{margin-top:1rem}.radar-shell .mb-1{margin-bottom:.25rem}.radar-shell .mb-3{margin-bottom:.75rem}.radar-shell .rounded-md{border-radius:.375rem}.radar-shell .rounded-lg{border-radius:.5rem}.radar-shell .rounded-xl{border-radius:.75rem}.radar-shell .rounded-2xl{border-radius:1rem}.radar-shell .rounded-\[28px\]{border-radius:28px}.radar-shell .border{border-width:1px;border-style:solid}.radar-shell .border-t{border-top-width:1px;border-top-style:solid}.radar-shell .border-b{border-bottom-width:1px;border-bottom-style:solid}.radar-shell .border-l{border-left-width:1px;border-left-style:solid}.radar-shell .border-slate-700{border-color:#334155}.radar-shell .border-slate-800{border-color:#1e293b}.radar-shell .border-sky-800{border-color:#075985}.radar-shell .border-cyan-800{border-color:#155e75}.radar-shell .border-cyan-900{border-color:#164e63}.radar-shell .border-cyan-900\/60{border-color:#164e6399}.radar-shell .border-rose-950{border-color:#4c0519}.radar-shell .border-white\/10{border-color:#ffffff1a}.radar-shell .border-white\/20{border-color:#fff3}.radar-shell .divide-y>:not([hidden])~:not([hidden]){border-top-width:1px;border-top-style:solid}.radar-shell .divide-slate-800>:not([hidden])~:not([hidden]){border-color:#1e293b}.radar-shell .bg-slate-950{background-color:#020617}.radar-shell .bg-slate-950\/20{background-color:#02061733}.radar-shell .bg-slate-950\/70{background-color:#020617b3}.radar-shell .bg-slate-950\/90{background-color:#020617e6}.radar-shell .bg-slate-950\/95{background-color:#020617f2}.radar-shell .bg-slate-900{background-color:#0f172a}.radar-shell .bg-slate-800{background-color:#1e293b}.radar-shell .bg-sky-700{background-color:#0369a1}.radar-shell .bg-sky-950{background-color:#082f49}.radar-shell .bg-cyan-700{background-color:#0e7490}.radar-shell .bg-cyan-950{background-color:#083344}.radar-shell .bg-cyan-950\/20{background-color:#08334433}.radar-shell .bg-emerald-700{background-color:#047857}.radar-shell .bg-amber-700{background-color:#b45309}.radar-shell .bg-violet-700{background-color:#6d28d9}.radar-shell .bg-rose-800{background-color:#9f1239}.radar-shell .bg-rose-950\/20{background-color:#4c051933}.radar-shell .text-left{text-align:left}.radar-shell .text-center{text-align:center}.radar-shell .text-right{text-align:right}.radar-shell .font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.radar-shell .font-semibold{font-weight:600}.radar-shell .font-bold{font-weight:700}.radar-shell .font-black{font-weight:900}.radar-shell .text-xs{font-size:.75rem}.radar-shell .text-sm{font-size:.875rem}.radar-shell .text-lg{font-size:1.125rem}.radar-shell .text-3xl{font-size:1.875rem}.radar-shell .text-\[8px\]{font-size:8px}.radar-shell .text-\[10px\]{font-size:10px}.radar-shell .text-\[11px\]{font-size:11px}.radar-shell .text-\[clamp\(19px\,4vw\,29px\)\]{font-size:clamp(19px,4vw,29px)}.radar-shell .leading-5{line-height:1.25rem}.radar-shell .uppercase{text-transform:uppercase}.radar-shell .tracking-wider{letter-spacing:.05em}.radar-shell .tracking-\[\.18em\]{letter-spacing:.18em}.radar-shell .tracking-\[0\.14em\]{letter-spacing:.14em}.radar-shell .tracking-\[0\.18em\]{letter-spacing:.18em}.radar-shell .tracking-\[0\.2em\]{letter-spacing:.2em}.radar-shell .tracking-\[0\.22em\]{letter-spacing:.22em}.radar-shell .tracking-\[0\.24em\]{letter-spacing:.24em}.radar-shell .text-white{color:#fff}.radar-shell .text-white\/60{color:#fff9}.radar-shell .text-white\/80{color:#fffc}.radar-shell .text-white\/85{color:#fffd}.radar-shell .text-slate-200{color:#e2e8f0}.radar-shell .text-slate-300{color:#cbd5e1}.radar-shell .text-slate-400{color:#94a3b8}.radar-shell .text-slate-500{color:#64748b}.radar-shell .text-slate-900{color:#0f172a}.radar-shell .text-cyan-100{color:#cffafe}.radar-shell .text-cyan-200{color:#a5f3fc}.radar-shell .text-cyan-300{color:#67e8f9}.radar-shell .text-cyan-400{color:#22d3ee}.radar-shell .text-sky-200{color:#bae6fd}.radar-shell .text-sky-300{color:#7dd3fc}.radar-shell .text-amber-300{color:#fcd34d}.radar-shell .text-rose-300{color:#fda4af}.radar-shell .text-violet-300{color:#c4b5fd}.radar-shell .shadow{box-shadow:0 1px 3px #0003}.radar-shell .shadow-2xl{box-shadow:0 25px 50px -12px #0008}.radar-shell .drop-shadow-lg{filter:drop-shadow(0 10px 8px #0006)}.radar-shell .backdrop-blur{backdrop-filter:blur(8px)}.radar-shell .backdrop-blur-sm{backdrop-filter:blur(4px)}.radar-shell .transition-transform{transition:transform .2s ease}.radar-shell .duration-200{transition-duration:.2s}.radar-shell .translate-x-full{transform:translateX(100%)}.radar-shell .-translate-y-1\/2{transform:translateY(-50%)}.radar-shell .truncate{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.radar-shell .aspect-\[1\.586\/1\]{aspect-ratio:1.586 / 1}.radar-shell .accent-violet-600{accent-color:#7c3aed}.radar-shell .outline-none{outline:0}.radar-shell .disabled\:opacity-30:disabled{opacity:.3}.radar-shell .disabled\:opacity-40:disabled{opacity:.4}.radar-shell .hover\:bg-rose-700:hover{background-color:#be123c}.radar-shell .focus\:border-cyan-500:focus{border-color:#06b6d4}.radar-shell .focus\:border-rose-600:focus{border-color:#e11d48}.radar-shell .focus\:border-sky-600:focus{border-color:#0284c7}
    @media(min-width:768px){.radar-shell .md\:flex-row{flex-direction:row}.radar-shell .md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.radar-shell .md\:items-center{align-items:center}.radar-shell .md\:justify-between{justify-content:space-between}}@media(min-width:1280px){.radar-shell .xl\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.radar-shell .xl\:flex-row{flex-direction:row}.radar-shell .xl\:items-center{align-items:center}.radar-shell .xl\:justify-between{justify-content:space-between}.radar-shell .xl\:col-span-2{grid-column:span 2/span 2}}@media(min-width:1536px){.radar-shell .\32xl\:grid-cols-9{grid-template-columns:repeat(9,minmax(0,1fr))}}
    .radar-shell{--r-bg:#030b1d;--r-panel:rgba(5,19,43,.86);--r-panel-solid:#07152c;--r-border:rgba(24,184,255,.3);--r-muted:#8fa9cf;--r-text:#edf6ff;--r-cyan:#16ddff;--r-violet:#b266ff;--r-amber:#d7ef22;min-height:100vh;background:radial-gradient(circle at 86% -10%,#1254c855 0,transparent 31rem),radial-gradient(circle at 2% 20%,#5d29b14d 0,transparent 27rem),var(--r-bg);padding:24px;color:var(--r-text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.radar-shell:before{position:fixed;z-index:0;inset:0;pointer-events:none;background-image:linear-gradient(#1a75bd14 1px,transparent 1px),linear-gradient(90deg,#1a75bd14 1px,transparent 1px);background-size:32px 32px;content:""}.radar-frame{position:relative;z-index:1;max-width:1440px;margin:0 auto}.radar-shell button,.radar-shell input,.radar-shell select{font-family:inherit}.radar-hero{position:relative;overflow:hidden;margin-bottom:14px;padding:29px 32px;border:1px solid #1175cf;border-radius:15px;background:linear-gradient(110deg,#061b42f5,#071b3ee8 55%,#160943d9);box-shadow:0 0 0 1px #0a3a8b,0 18px 42px #0009}.radar-hero:before{position:absolute;inset:0;background-image:linear-gradient(#2fcfff12 1px,transparent 1px),linear-gradient(90deg,#2fcfff12 1px,transparent 1px);background-size:34px 34px;content:""}.radar-hero:after{position:absolute;inset:auto 24% -110px auto;width:270px;height:270px;border:1px solid #27dfff66;border-radius:50%;content:"";box-shadow:0 0 0 24px #27dfff0a,0 0 45px #16d9ff88}.radar-hero-grid{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:24px}.radar-eyebrow{display:flex;align-items:center;gap:8px;color:var(--r-amber);font-size:11px;font-weight:800;letter-spacing:.16em}.radar-pulse{width:9px;height:9px;border-radius:999px;background:#b6ed16;box-shadow:0 0 12px #c4ff27}.radar-hero h1{margin:10px 0 8px;color:#f0f7ff;font-size:clamp(27px,3vw,42px);line-height:1.05;letter-spacing:-.045em;text-shadow:0 0 22px #3c9cff55}.radar-subtitle{max-width:690px;color:#bfcef0;font-size:13px;line-height:1.65}.radar-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;max-width:440px}.radar-action{min-height:40px;border:1px solid #1faed580;border-radius:9px;background:#071a36dd;padding:0 13px;color:#cceeff;font-size:12px;font-weight:700;transition:.18s ease}.radar-action:hover{transform:translateY(-1px);border-color:#45e6ff;background:#0b2950;box-shadow:0 0 15px #13ceff22}.radar-action--primary{border-color:#2ee7ff;background:linear-gradient(135deg,#04b9df,#2852f3);color:#fff;box-shadow:0 8px 22px #069ee866}.radar-status{display:flex;align-items:center;gap:7px;border:1px solid #19d4ee99;border-radius:999px;background:#041b37dd;padding:0 12px;color:#8dedff;font-size:11px}.radar-flow{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:14px;border:1px solid #24567f;border-radius:13px;background:#07162ded;overflow:hidden}.radar-flow__step{position:relative;display:flex;align-items:center;gap:10px;min-height:74px;padding:13px 18px;border-right:1px solid #24567f}.radar-flow__step:last-child{border-right:0}.radar-flow__id{display:grid;width:36px;height:36px;flex:none;place-items:center;border:2px solid #13dfff;border-radius:50%;color:#66f3ff;font-weight:850;box-shadow:0 0 16px #11c7ff40}.radar-flow__step--violet .radar-flow__id{border-color:#be70ff;color:#dda8ff}.radar-flow__text b{display:block;font-size:13px}.radar-flow__text span{display:block;margin-top:2px;color:#8fa9cf;font-size:10px}.radar-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:20px}.metric-card{position:relative;overflow:hidden;min-height:112px;border:1px solid #1b6e9c99;border-radius:13px;background:linear-gradient(145deg,#092047ed,#06162de8);padding:18px;box-shadow:0 12px 24px #0006}.metric-card:before{position:absolute;left:0;top:18px;width:3px;height:28px;border-radius:0 3px 3px 0;background:var(--metric,#35d5ff);content:""}.metric-label{color:var(--r-muted);font-size:11px;font-weight:700}.metric-value{margin-top:14px;font-size:28px;font-weight:800;letter-spacing:-.04em;line-height:1}.radar-section{margin-bottom:16px;border:1px solid #1c608c99;border-radius:13px;background:var(--r-panel);box-shadow:0 12px 30px #0005}.section-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:18px 20px 0}.section-title{color:#e5f6ff;font-size:14px;font-weight:800;letter-spacing:-.015em}.section-caption{margin-top:3px;color:var(--r-muted);font-size:11px}.radar-field{display:grid;gap:7px}.radar-field>span{color:#79cef0;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.radar-field input,.radar-field select{height:42px;border:1px solid #23547c;background:#04132b;border-radius:8px;padding:0 12px;color:#e2f3ff;font-size:12px;outline:0;transition:.18s ease}.radar-field input:focus,.radar-field select:focus{border-color:#25e1ff;box-shadow:0 0 0 3px #18dfff21}.radar-toolbar{border-color:#7842c477;background:linear-gradient(110deg,#151044dd,#082443df)}.radar-toolbar .section-title{color:#d9c4ff}.orders-panel{border:1px solid #1d6c98aa;border-radius:13px;background:var(--r-panel-solid);box-shadow:0 18px 38px #0007}.orders-panel thead{background:#0a2445!important}.orders-panel th{padding-top:15px!important;padding-bottom:15px!important;color:#78b8d9!important;font-size:10px}.orders-panel tbody tr{transition:background .15s ease}.orders-panel tbody tr:hover{background:#155e8d38}.radar-shell #detail-drawer{border-left-color:#1ddeff;background:radial-gradient(circle at 100% 0,#0e749044 0,transparent 26rem),#030d20}.radar-shell .detail-tab{transition:.18s ease}.radar-shell .detail-tab:hover{border-color:#38e9ff;color:#e0faff}.radar-shell #detail-overlay{background:#010616cc}.radar-shell #card-modal{background:#020817e8}.radar-shell .focus-ring:focus-visible{outline:3px solid #38dfff99;outline-offset:2px}@media(max-width:1180px){.radar-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.radar-shell{padding:16px}.radar-hero{padding:22px}.radar-hero-grid{align-items:flex-start;flex-direction:column}.radar-actions{justify-content:flex-start}.radar-flow{grid-template-columns:repeat(2,minmax(0,1fr))}.radar-flow__step:nth-child(2){border-right:0}.radar-flow__step:nth-child(-n+2){border-bottom:1px solid #24567f}.radar-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.metric-card{min-height:96px}.section-heading{align-items:flex-start;flex-direction:column}}
</style>
<style>
    /* 首屏检索保持在雷达主视觉内，避免把最常用动作藏进高级筛选。 */
    .radar-hero{background-image:linear-gradient(120deg,rgba(15,31,54,.98),rgba(15,29,49,.82)),linear-gradient(rgba(53,213,255,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(53,213,255,.045) 1px,transparent 1px);background-size:auto,28px 28px,28px 28px}
    .radar-global-search{position:relative;z-index:1;display:flex;align-items:center;gap:10px;max-width:800px;margin-top:24px;padding:7px 8px 7px 15px;border:1px solid rgba(53,213,255,.42);border-radius:14px;background:rgba(3,12,25,.7);box-shadow:0 0 0 1px rgba(53,213,255,.05),0 14px 30px rgba(2,6,23,.25);transition:.18s ease}
    .radar-global-search:focus-within{border-color:#67e8f9;box-shadow:0 0 0 3px rgba(53,213,255,.16),0 16px 32px rgba(2,6,23,.3)}
    .radar-global-search__icon{color:var(--r-cyan);font-size:20px;font-weight:300;line-height:1}
    .radar-global-search input{min-width:0;flex:1;border:0!important;background:transparent!important;padding:7px 0!important;color:#f8fafc;font-size:13px;outline:0;box-shadow:none!important}
    .radar-global-search input::placeholder{color:#7890ac}.radar-global-search kbd{border:1px solid #334155;border-radius:6px;background:#0f1e33;padding:3px 6px;color:#8fa2ba;font-family:inherit;font-size:10px}.radar-global-search__clear{border:0;background:transparent;color:#8fa2ba;font-size:11px}.radar-global-search__clear:hover{color:#e0f2fe}
    .radar-global-search__submit{min-height:34px;border:1px solid rgba(34,211,238,.68);border-radius:9px;background:linear-gradient(135deg,#0891b2,#2563eb);padding:0 14px;color:#fff;font-size:11px;font-weight:800;letter-spacing:.04em}.radar-global-search__submit:hover{filter:brightness(1.1)}
    .radar-search-hint{position:relative;z-index:1;margin:8px 0 0 16px;color:#8fa2ba;font-size:10px}.radar-search-hint strong{color:#a5f3fc;font-weight:700}
    @media(max-width:760px){.radar-global-search{margin-top:18px;gap:7px}.radar-global-search kbd{display:none}.radar-global-search__submit{padding:0 10px}.radar-search-hint{margin-left:0}}
</style>
<style>
    /* Operational screens are read at a distance; avoid dashboard microtype. */
    .radar-shell{font-size:15px}.radar-shell .text-\[8px\]{font-size:11px}.radar-shell .text-\[10px\]{font-size:12px}.radar-shell .text-\[11px\]{font-size:13px}.radar-shell .text-xs{font-size:13px}.radar-shell .text-sm{font-size:14px}.radar-shell .radar-eyebrow{font-size:12px}.radar-shell .radar-subtitle{font-size:15px}.radar-shell .radar-action{font-size:13px}.radar-shell .radar-status{font-size:12px}.radar-shell .section-title{font-size:17px}.radar-shell .section-caption{font-size:13px}.radar-shell .radar-field>span{font-size:11px}.radar-shell .radar-field input,.radar-shell .radar-field select{font-size:14px}.radar-shell .orders-panel th{font-size:12px}.radar-shell .orders-panel td{font-size:14px;line-height:1.45}.radar-shell .radar-flow__text b{font-size:14px}.radar-shell .radar-flow__text span{font-size:12px}.radar-shell .radar-global-search input{font-size:14px}.radar-shell .radar-search-hint{font-size:12px}.radar-shell #detail-drawer{font-size:15px}.radar-shell #detail-drawer input,.radar-shell #detail-drawer button,.radar-shell #detail-drawer select{font-size:13px}.radar-shell #detail-drawer h2{font-size:22px}.radar-shell #detail-drawer h3{font-size:15px}@media(max-width:640px){.radar-shell{padding:12px;font-size:14px}.radar-shell .orders-panel td{font-size:13px}}
</style>
<style>
    /* Dense security telemetry remains readable on ordinary laptop displays. */
    .radar-shell{font-size:16px}.radar-shell .text-\[8px\]{font-size:12px}.radar-shell .text-\[10px\]{font-size:13px}.radar-shell .text-\[11px\]{font-size:14px}.radar-shell .text-xs{font-size:14px}.radar-shell .text-sm{font-size:15px}.radar-shell .section-title{font-size:19px}.radar-shell .section-caption,.radar-shell .radar-search-hint{font-size:14px}.radar-shell .radar-field input,.radar-shell .radar-field select{height:46px;font-size:15px}.radar-shell .orders-panel th{font-size:13px}.radar-shell .orders-panel td{font-size:15px;line-height:1.5}.radar-shell .radar-action{min-height:44px;font-size:14px}.radar-shell #detail-drawer{font-size:16px}.radar-shell #detail-drawer input,.radar-shell #detail-drawer button,.radar-shell #detail-drawer select{font-size:15px}@media(max-width:640px){.radar-shell{font-size:15px}.radar-shell .orders-panel td{font-size:14px}}
</style>
<style>
    /* Sunset coast direction: navy is the operational surface; the image and
       amber accents provide atmosphere without competing with live data. */
    .radar-shell{
        --sunset-ink:#081522;
        --sunset-deep:#0d2134;
        --sunset-surface:rgba(9,25,40,.86);
        --sunset-surface-strong:rgba(7,20,33,.94);
        --sunset-line:rgba(255,201,113,.36);
        --sunset-text:#f8f4ec;
        --sunset-muted:#c2d0dc;
        --sunset-gold:#ffc15c;
        --sunset-gold-strong:#ff9d42;
        --sunset-sea:#75c9dc;
        background-color:var(--sunset-ink);
        background-image:
            linear-gradient(120deg,rgba(4,14,25,.9),rgba(4,14,25,.68) 52%,rgba(5,14,24,.86)),
            url("{{ plugin_asset('beike_shop_test_sandbox', 'admin/radar-sunset-background.webp') }}");
        background-position:center;
        background-size:cover;
        background-attachment:fixed;
        background-repeat:no-repeat;
        color:var(--sunset-text);
    }
    .radar-shell:before{background-image:linear-gradient(rgba(255,208,128,.075) 1px,transparent 1px),linear-gradient(90deg,rgba(255,208,128,.075) 1px,transparent 1px);background-size:48px 48px}
    .radar-frame{max-width:1440px}
    .radar-hero,.radar-section,.orders-panel,.metric-card{backdrop-filter:blur(14px)}
    .radar-hero{border-color:var(--sunset-line);background:linear-gradient(118deg,rgba(7,24,40,.94),rgba(12,37,53,.78) 54%,rgba(84,42,28,.67));box-shadow:0 20px 50px rgba(2,8,15,.48),inset 0 1px rgba(255,235,199,.12)}
    .radar-hero:before{background-image:linear-gradient(rgba(255,213,147,.07) 1px,transparent 1px),linear-gradient(90deg,rgba(255,213,147,.07) 1px,transparent 1px)}
    .radar-hero:after{border-color:rgba(255,198,94,.48);box-shadow:0 0 0 24px rgba(255,185,73,.06),0 0 48px rgba(255,157,66,.24)}
    .radar-eyebrow{color:#ffe1a3}.radar-pulse{background:var(--sunset-gold);box-shadow:0 0 14px rgba(255,190,80,.85)}
    .radar-subtitle,.radar-flow__text span,.section-caption{color:var(--sunset-muted)}
    .radar-action{border-color:rgba(184,221,229,.44);background:rgba(8,28,43,.72);color:#f5f1e8;box-shadow:inset 0 1px rgba(255,255,255,.05)}
    .radar-action:hover{border-color:var(--sunset-gold);background:rgba(35,50,57,.9);box-shadow:0 10px 22px rgba(2,11,19,.35),0 0 0 1px rgba(255,193,92,.15)}
    .radar-action--primary{border-color:#ffd386;background:linear-gradient(135deg,#ffad4b,#e97836);color:#1f130a;box-shadow:0 10px 24px rgba(177,79,24,.34)}
    .radar-status{border-color:rgba(140,218,230,.46);background:rgba(6,26,41,.7);color:#d6f4f7}
    .radar-global-search{border-color:rgba(255,199,103,.48);background:rgba(7,23,37,.78);box-shadow:0 16px 34px rgba(2,10,17,.32),inset 0 1px rgba(255,255,255,.05)}
    .radar-global-search:focus-within{border-color:#ffd28a;box-shadow:0 0 0 3px rgba(255,183,77,.2),0 16px 34px rgba(2,10,17,.4)}
    .radar-global-search__icon{color:var(--sunset-gold)}.radar-global-search input::placeholder{color:#adc1cd}.radar-global-search kbd{border-color:rgba(255,216,153,.35);background:rgba(34,48,57,.75);color:#f8e2b8}
    .radar-global-search__submit{border-color:#ffcf81;background:linear-gradient(135deg,#f99e43,#db6a35);color:#211209}
    .radar-flow{border-color:rgba(180,220,227,.36);background:rgba(8,26,40,.8);box-shadow:0 14px 30px rgba(2,10,17,.28)}
    .radar-flow__step{border-color:rgba(180,220,227,.25)}.radar-flow__id{border-color:#ffd084;color:#ffe6bc;box-shadow:0 0 16px rgba(255,177,74,.3)}.radar-flow__step--violet .radar-flow__id{border-color:#9ed8e5;color:#d9f8fb}
    .metric-card{border-color:rgba(190,225,230,.32);background:linear-gradient(150deg,rgba(12,40,56,.9),rgba(8,22,36,.84));box-shadow:0 14px 28px rgba(2,10,17,.32),inset 0 1px rgba(255,255,255,.05)}
    .metric-label{color:#c7d7df}.metric-value{color:#fff8e9;text-shadow:0 2px 12px rgba(1,9,17,.5)}
    .radar-section{border-color:rgba(190,225,230,.3);background:var(--sunset-surface);box-shadow:0 18px 36px rgba(2,10,17,.34),inset 0 1px rgba(255,255,255,.05)}
    .radar-toolbar{border-color:rgba(255,191,95,.38);background:linear-gradient(112deg,rgba(52,40,43,.88),rgba(10,35,49,.84))}.radar-toolbar .section-title{color:#ffe0a7}
    .section-title{color:#fff7e8}.radar-field>span{color:#d6ecf1}.radar-field input,.radar-field select{border-color:rgba(177,215,223,.38);background:rgba(5,22,35,.86);color:#f7f4ed}.radar-field input:focus,.radar-field select:focus{border-color:#ffca78;box-shadow:0 0 0 3px rgba(255,178,72,.2)}
    .orders-panel{border-color:rgba(190,225,230,.34);background:var(--sunset-surface-strong);box-shadow:0 20px 40px rgba(2,10,17,.4)}.orders-panel thead{background:rgba(26,55,70,.92)!important}.orders-panel th{color:#d7edf0!important}.orders-panel tbody tr:hover{background:rgba(71,111,123,.32)}
    .radar-shell #detail-drawer{border-left-color:rgba(255,197,98,.65);background:linear-gradient(150deg,rgba(10,33,48,.98),rgba(22,29,35,.98))}.radar-shell #detail-overlay{background:rgba(1,9,15,.76)}.radar-shell #card-modal{background:rgba(3,15,25,.91)}
    .radar-shell .detail-tab:hover{border-color:#ffca78;color:#fff0d2}.radar-shell .focus-ring:focus-visible{outline-color:#ffe09f}
    /* Readability guardrails: opaque data surfaces and a 13px minimum for
       supporting telemetry keep the sunset image from lowering contrast. */
    .radar-shell{--sunset-surface:rgba(7,22,36,.93);--sunset-surface-strong:rgba(5,18,30,.97);color:#fffdf7}
    .radar-shell .text-slate-200{color:#fffdf7}.radar-shell .text-slate-300{color:#edf4f5}.radar-shell .text-slate-400{color:#d9e5e8}.radar-shell .text-slate-500{color:#c4d4d9}
    .radar-shell .text-\[8px\]{font-size:12px!important}.radar-shell .text-\[9px\],.radar-shell .text-\[10px\]{font-size:13px!important;line-height:1.45}.radar-shell .text-\[11px\]{font-size:14px!important;line-height:1.45}
    .radar-shell .orders-panel td{color:#f4f7f5}.radar-shell .orders-panel tbody{background:rgba(5,18,30,.9)}.radar-shell .orders-panel th{color:#ecf8f7!important}
    .radar-shell .radar-field input::placeholder{color:#b9ccd1;opacity:1}.radar-shell .radar-field input,.radar-shell .radar-field select{font-size:16px;color:#fffdf7}
    .radar-shell .radar-action,.radar-shell .radar-global-search__submit{font-size:15px}.radar-shell .radar-status{font-size:13px}.radar-shell .section-caption{font-size:14px}.radar-shell .focus-ring:focus-visible{outline:3px solid #ffe09f;outline-offset:3px}
    @media(max-width:760px){.radar-shell{background-attachment:scroll}.radar-shell .text-\[8px\]{font-size:12px!important}.radar-shell .text-\[9px\],.radar-shell .text-\[10px\]{font-size:13px!important}}
    @media(prefers-reduced-motion:reduce){.radar-shell *{transition:none!important}}
    /* Unified, high-contrast action system. */
    .radar-shell{--button-radius:10px;--button-font:14px;--button-weight:750;--button-focus:#fff0bd;--button-shadow:0 8px 20px rgba(1,10,17,.24)}
    .radar-shell .radar-action,.radar-shell .radar-global-search__submit,.radar-shell .orders-panel button,.radar-shell #detail-drawer button,.radar-shell #card-modal button{
        min-height:44px;border-radius:var(--button-radius);font-family:Inter,"Noto Sans SC",system-ui,sans-serif;font-size:var(--button-font);font-weight:var(--button-weight);letter-spacing:.015em;line-height:1.2;box-shadow:var(--button-shadow);transition:transform .16s ease,box-shadow .16s ease,filter .16s ease,border-color .16s ease,background-color .16s ease;
    }
    .radar-shell .radar-action,.radar-shell .orders-panel .border-slate-600.bg-slate-800,.radar-shell #detail-drawer .border-slate-600,.radar-shell #card-modal .border-slate-600{
        border-color:rgba(180,211,216,.58)!important;background:rgba(16,46,61,.96)!important;color:#f5fbfb!important;
    }
    .radar-shell .radar-action:hover,.radar-shell .orders-panel .border-slate-600.bg-slate-800:hover,.radar-shell #detail-drawer .border-slate-600:hover,.radar-shell #card-modal .border-slate-600:hover{border-color:#a7e2e2!important;background:rgba(24,64,78,.98)!important}
    .radar-shell .radar-action--primary,.radar-shell .radar-global-search__submit{
        border:1px solid #ffd38b!important;background:linear-gradient(135deg,#ffc65d,#ec7e37)!important;color:#221408!important;text-shadow:0 1px 0 rgba(255,255,255,.2);
    }
    .radar-shell .radar-action--primary:hover,.radar-shell .radar-global-search__submit:hover{border-color:#fff0bd!important;background:linear-gradient(135deg,#ffd473,#f28b43)!important}
    .radar-shell .orders-panel .bg-emerald-700{border:1px solid #86e3ca!important;background:linear-gradient(135deg,#08765d,#075d50)!important;color:#f2fffc!important}
    .radar-shell .orders-panel .bg-emerald-700:hover{border-color:#c1ffea!important;background:linear-gradient(135deg,#0d8e70,#087061)!important}
    .radar-shell .orders-panel .bg-amber-700{border:1px solid #ffd189!important;background:linear-gradient(135deg,#bd682d,#94441f)!important;color:#fff8e9!important}
    .radar-shell .orders-panel .bg-amber-700:hover{border-color:#ffe2aa!important;background:linear-gradient(135deg,#d47b38,#a95225)!important}
    .radar-shell .orders-panel .bg-rose-800{border:1px solid #ffc0bc!important;background:linear-gradient(135deg,#9d4141,#783032)!important;color:#fff7f5!important}
    .radar-shell .orders-panel .bg-rose-800:hover{border-color:#ffd9d3!important;background:linear-gradient(135deg,#b54b49,#8f393b)!important}
    .radar-shell .orders-panel .border-cyan-700.bg-cyan-950\/70,.radar-shell #detail-drawer .border-cyan-700,.radar-shell #card-modal .border-cyan-700{
        border-color:#81dbe2!important;background:rgba(9,68,83,.97)!important;color:#ecffff!important;
    }
    .radar-shell .orders-panel .border-cyan-700.bg-cyan-950\/70:hover,.radar-shell #detail-drawer .border-cyan-700:hover,.radar-shell #card-modal .border-cyan-700:hover{border-color:#c0f7f6!important;background:rgba(10,86,101,.98)!important}
    .radar-shell .orders-panel button{padding:0 14px}.radar-shell .orders-panel .min-w-\[204px\]{min-width:230px}.radar-shell .orders-panel button[title*="复制"]{min-height:38px;padding:0 11px;font-size:13px;box-shadow:none}
    .radar-shell .radar-global-search__clear{min-height:auto;border-radius:7px;padding:5px 8px;font-size:13px;font-weight:700;color:#dceced;box-shadow:none}
    .radar-shell button:not(:disabled):hover,.radar-shell a.radar-action:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(1,10,17,.32)}
    .radar-shell button:not(:disabled):active,.radar-shell a.radar-action:active{transform:translateY(0);box-shadow:0 4px 12px rgba(1,10,17,.26)}
    .radar-shell button:disabled{cursor:not-allowed!important;opacity:.48!important;box-shadow:none!important;filter:saturate(.55)}
    .radar-shell button:focus-visible,.radar-shell a.radar-action:focus-visible{outline:3px solid var(--button-focus)!important;outline-offset:3px}
</style>
<div class="radar-shell">
<div class="radar-frame">
    <header class="radar-hero">
        <div class="radar-hero-grid">
            <div>
                <div class="radar-eyebrow"><span class="radar-pulse"></span>LONG-OS</div>
            </div>
            <div class="radar-actions">
                <a href="{{ url('/checkout') }}" target="_blank" rel="noopener" class="radar-action focus-ring inline-flex items-center">打开接管后的结账 ↗</a>
                <button id="live-toggle" type="button" class="radar-action focus-ring">● 实时监控</button>
                <button id="bin-warm-btn" type="button" class="radar-action focus-ring">预热 BIN 缓存</button>
                <button id="export-template-btn" type="button" class="radar-action radar-action--primary focus-ring">下载 Excel 模板</button>
                <div id="bin-api-status" class="radar-status">BIN API: checking...</div>
                <div id="sync-status" class="radar-status">SYNC</div>
            </div>
        </div>
        <form id="global-search-form" class="radar-global-search" role="search">
            <span class="radar-global-search__icon" aria-hidden="true">⌕</span>
            <input id="global-search" type="search" autocomplete="off" aria-label="全局搜索订单" placeholder="全局搜索：订单号、卡组织、卡号后四位、BIN、IP 或状态">
            <kbd aria-hidden="true">Enter</kbd>
            <button id="global-search-clear" type="button" class="radar-global-search__clear hidden">清除</button>
            <button type="submit" class="radar-global-search__submit focus-ring">搜索</button>
        </form>
    </header>


    <section class="radar-kpis" aria-label="风险概览">
        <div class="metric-card" style="--metric:#60a5fa"><div class="metric-label">今日流水</div><div id="kpi-total" class="metric-value">0</div></div>
        <div class="metric-card" style="--metric:#38bdf8"><div class="metric-label">等待审核</div><div id="kpi-waiting" class="metric-value text-sky-300">0</div></div>
        <div class="metric-card" style="--metric:#fb7185"><div class="metric-label">高风险 · ≥60</div><div id="kpi-risk" class="metric-value text-rose-300">0</div></div>
        <div class="metric-card" style="--metric:#fbbf24"><div class="metric-label">平均风险</div><div id="kpi-avg" class="metric-value text-amber-300">0</div></div>
        <div class="metric-card" style="--metric:#a78bfa"><div class="metric-label">唯一设备</div><div id="kpi-device" class="metric-value text-violet-300">0</div></div>
        <div class="metric-card" style="--metric:#2dd4bf"><div class="metric-label">唯一 IP</div><div id="kpi-ip" class="metric-value text-cyan-300">0</div></div>
    </section>

    <section class="radar-section p-4">
        <div class="section-heading !px-0 !pt-0"><div><div class="section-title">订单工作台</div><div class="section-caption">使用风险、BIN、状态和时间范围快速收敛待处理记录。</div></div></div>
        <div class="grid gap-3 pt-5 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-9">
            <label class="radar-field xl:col-span-2">
                <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">高级搜索（与全局搜索同步）</span>
                <input id="filter-search" type="search" placeholder="订单号 / 卡组织 / 卡号后四位 / BIN / IP / 状态..."
                       class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-xs text-white outline-none focus:border-sky-600">
            </label>

            <label class="radar-field">
                <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-cyan-400">同卡头 / BIN</span>
                <input id="filter-bin-prefix" type="search" inputmode="numeric" maxlength="8" placeholder="输入 6–8 位卡头"
                       class="w-full rounded-xl border border-cyan-900 bg-cyan-950/20 px-3 py-2.5 font-mono text-xs text-cyan-100 outline-none focus:border-cyan-500">
            </label>

            <label class="radar-field">
                <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">状态</span>
                <select id="filter-status" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-xs">
                    <option value="">全部</option>
                    <option value="OTP_REQUIRED">OTP_REQUIRED</option>
                    <option value="WAITING_REVIEW">WAITING_REVIEW</option>
                    <option value="APPROVED">APPROVED</option>
                    <option value="CARD_UNSUPPORTED">CARD_UNSUPPORTED</option>
                    <option value="CANCELLED">CANCELLED</option>
                </select>
            </label>

            <label class="radar-field">
                <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">卡类别</span>
                <select id="filter-class" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-xs">
                    <option value="">全部</option>
                    <option value="C">C · CREDIT</option>
                    <option value="D">D · DEBIT</option>
                    <option value="P">P · PREPAID</option>
                    <option value="G">G · GIFT</option>
                    <option value="U">U · OPEN DEMO</option>
                </select>
            </label>

            <label class="radar-field">
                <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">风险</span>
                <select id="filter-risk" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-xs">
                    <option value="">全部</option>
                    <option value="low">低 0–29</option>
                    <option value="medium">中 30–59</option>
                    <option value="high">高 ≥60</option>
                </select>
            </label>

            <label class="radar-field">
                <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">BIN 状态</span>
                <select id="filter-bin" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-xs">
                    <option value="">全部</option>
                    <option value="OK">OK</option>
                    <option value="LOCAL_ONLY">LOCAL_ONLY</option>
                    <option value="RATE_LIMITED">RATE_LIMITED</option>
                    <option value="AUTH_ERROR">AUTH_ERROR</option>
                    <option value="NETWORK_ERROR">NETWORK_ERROR</option>
                </select>
            </label>

            <label class="radar-field">
                <span class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">排序</span>
                <select id="filter-sort" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-xs">
                    <option value="created_at:desc">最新创建</option>
                    <option value="risk_score:desc">风险最高</option>
                    <option value="updated_at:desc">最近更新</option>
                    <option value="card_brand:asc">卡组织 A-Z</option>
                </select>
            </label>

            <div class="flex items-end gap-2">
                <button id="today-toggle" type="button" class="radar-action flex-1 focus-ring">仅今天</button>
                <button id="filter-reset" type="button" class="radar-action focus-ring">重置</button>
            </div>
        </div>
    </section>

    <section class="radar-section radar-toolbar p-5">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <div id="selection-summary" class="mt-1 text-sm font-semibold text-slate-200">尚未选择订单</div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button id="select-page" type="button" class="radar-action focus-ring">全选当前页</button>
                <button id="select-filtered" type="button" class="radar-action focus-ring">全选筛选结果</button>
                <button id="select-all-records" type="button" class="radar-action focus-ring">全库全选</button>
                <button id="clear-selection" type="button" class="radar-action focus-ring">清空选择</button>
                <button id="export-full-btn" type="button" class="radar-action radar-action--primary focus-ring disabled:opacity-40">导出最终 XLSX</button>
            </div>
        </div>
    </section>

    <section class="orders-panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[2260px] text-left">
                <thead class="bg-slate-900 text-[10px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="w-12 p-3 text-center"><input id="select-page-checkbox" type="checkbox" class="h-4 w-4 accent-violet-600"></th>
                        <th class="p-3">订单</th>
                        <th class="p-3">卡号</th>
                        <th class="p-3">卡日期 / CVV</th>
                        <th class="p-3">设备 / IP</th>
                        <th class="p-3">OTP</th>
                        <th class="p-3">审核状态</th>
                        <th class="p-3 text-right">处置 / 卡图</th>
                    </tr>
                </thead>
                <tbody id="orders-body" class="divide-y divide-slate-800"></tbody>
            </table>
        </div>

        <div id="empty-state" class="hidden p-12 text-center">
            <div class="text-3xl">📭</div>
            <div class="mt-3 text-sm font-bold text-slate-300">没有匹配的订单</div>
            <div class="mt-1 text-xs text-slate-500">调整筛选条件后再试。</div>
        </div>

        <div class="flex flex-col gap-3 border-t border-slate-800 p-4 md:flex-row md:items-center md:justify-between">
            <div id="page-summary" class="text-[10px] text-slate-500">0 records</div>
            <div class="flex items-center gap-2">
                <select id="per-page" class="rounded-lg border border-slate-700 bg-slate-950 px-2 py-2 text-[10px]">
                    <option value="10">10 / 页</option>
                    <option value="25" selected>25 / 页</option>
                    <option value="50">50 / 页</option>
                    <option value="100">100 / 页</option>
                </select>
                <button id="page-prev" type="button" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-[10px] font-bold disabled:opacity-30">上一页</button>
                <span id="page-number" class="min-w-[80px] text-center text-[10px] text-slate-400">1 / 1</span>
                <button id="page-next" type="button" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-[10px] font-bold disabled:opacity-30">下一页</button>
            </div>
        </div>
    </section>

    <div id="toast-stack" class="fixed right-4 top-4 z-[90] flex w-[min(380px,calc(100vw-2rem))] flex-col gap-2"></div>

    <div id="detail-overlay" class="fixed inset-0 z-50 hidden bg-slate-950/70 backdrop-blur-sm"></div>
    <aside id="detail-drawer" class="fixed right-0 top-0 z-[60] h-full w-full max-w-2xl translate-x-full overflow-y-auto border-l border-slate-800 bg-slate-950 shadow-2xl transition-transform duration-200">
        <div class="sticky top-0 z-10 border-b border-slate-800 bg-slate-950/95 p-5 backdrop-blur">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-[10px] font-black uppercase tracking-[0.22em] text-sky-300">Order Intelligence</div>
                    <h2 id="detail-order-id" class="mt-1 font-black"></h2>
                    <div id="detail-subtitle" class="mt-1 text-[11px] text-slate-500"></div>
                </div>
                <button id="detail-close" type="button" class="rounded-lg border border-slate-700 px-3 py-2 text-xs font-bold text-slate-300">关闭</button>
            </div>

            <div class="mt-4 grid grid-cols-4 gap-2">
                <button data-tab="summary" class="detail-tab rounded-lg bg-sky-700 px-2 py-2 text-[10px] font-bold">订单</button>
                <button data-tab="bin" class="detail-tab rounded-lg border border-slate-700 px-2 py-2 text-[10px] font-bold text-slate-400">BIN</button>
                <button data-tab="device" class="detail-tab rounded-lg border border-slate-700 px-2 py-2 text-[10px] font-bold text-slate-400">设备</button>
                <button data-tab="timeline" class="detail-tab rounded-lg border border-slate-700 px-2 py-2 text-[10px] font-bold text-slate-400">时间线</button>
            </div>
        </div>

        <div class="space-y-4 p-5">
            <section id="tab-summary" class="detail-panel space-y-4">
                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-xs font-black">测试支付数据</h3>
                            <p class="mt-1 text-[10px] text-slate-500">敏感 TEST 数据默认不加载。</p>
                        </div>
                        <div class="flex gap-2">
                            <button id="reveal-sensitive" type="button" class="rounded-lg bg-rose-800 px-3 py-2 text-[10px] font-bold text-white">揭示敏感 TEST 数据</button>
                            <button id="open-card" type="button" class="rounded-lg bg-sky-700 px-3 py-2 text-[10px] font-bold text-white">展示卡图</button>
                        </div>
                    </div>
                    <div id="payment-grid" class="mt-4 grid grid-cols-2 gap-3 text-xs"></div>
                </div>

                <div id="review-box" class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <h3 class="text-xs font-black">人工裁决</h3>
                    <label class="mt-3 block">
                        <span class="mb-1 block text-[10px] text-slate-500">审计备注</span>
                        <input id="review-reason" maxlength="250" placeholder="例如：演示放行 / OTP 超时模拟"
                               class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-xs outline-none focus:border-sky-600">
                    </label>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <button id="decision-approve" type="button" class="rounded-xl bg-emerald-700 px-4 py-3 text-xs font-black text-white">放行 TEST 支付</button>
                        <button id="decision-retry" type="button" class="rounded-xl bg-amber-700 px-4 py-3 text-xs font-black text-white">拒绝本次 OTP → 重新输入</button>
                    </div>
                </div>

                <div class="rounded-2xl border border-rose-950 bg-rose-950/20 p-4">
                    <div class="text-[10px] font-black uppercase tracking-wider text-rose-300">永久保留策略 / 手动删除</div>
                    <p class="mt-2 text-[10px] leading-5 text-slate-400">
                        订单没有自动清理、TTL 或定时删除。只有在这里输入完整订单号并手动确认，记录才会从数据库中删除。
                    </p>
                    <label class="mt-3 block">
                        <span class="mb-1 block text-[10px] text-slate-500">输入完整订单号确认删除</span>
                        <input id="delete-confirmation" autocomplete="off" placeholder="DEMO_XXXXXXXXXXXX"
                               class="w-full rounded-xl border border-rose-950 bg-slate-950 px-3 py-2.5 font-mono text-xs outline-none focus:border-rose-600">
                    </label>
                    <button id="delete-order" type="button" class="mt-3 w-full rounded-xl bg-rose-800 px-4 py-3 text-xs font-black text-white hover:bg-rose-700">
                        手动永久删除该订单
                    </button>
                </div>
            </section>

            <section id="tab-bin" class="detail-panel hidden">
                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-black">BIN Intelligence</h3>
                        <button id="refresh-bin" type="button" class="rounded-lg bg-violet-700 px-3 py-2 text-[10px] font-bold text-white">强制重新验证</button>
                    </div>
                    <div id="bin-grid" class="mt-4 grid grid-cols-2 gap-3 text-xs"></div>

                    <div class="mt-4 rounded-xl border border-cyan-900/60 bg-cyan-950/20 p-3">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="text-[10px] font-black uppercase tracking-wider text-cyan-300">同卡头历史检索</div>
                                <div id="same-bin-summary" class="mt-1 text-[10px] text-slate-400">-</div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button id="search-same-bin6" type="button" class="rounded-lg border border-cyan-800 bg-cyan-950 px-3 py-2 text-[10px] font-bold text-cyan-200">搜索相同 6 位卡头</button>
                                <button id="search-same-bin8" type="button" class="rounded-lg bg-cyan-700 px-3 py-2 text-[10px] font-bold text-white">搜索相同 8 位卡头</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-device" class="detail-panel hidden">
                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <h3 class="text-xs font-black">设备 / 网络 / 风险</h3>
                    <div id="device-grid" class="mt-4 grid grid-cols-1 gap-3 text-xs"></div>
                    <div id="risk-box" class="mt-4"></div>
                </div>
            </section>

            <section id="tab-timeline" class="detail-panel hidden">
                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-black">事件时间线</h3>
                        <button id="timeline-refresh" type="button" class="rounded-lg border border-slate-700 px-3 py-2 text-[10px] font-bold text-slate-300">刷新</button>
                    </div>
                    <div id="timeline-list" class="mt-4 space-y-2"></div>
                </div>
            </section>
        </div>
    </aside>

    <div id="card-modal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/90 p-4 backdrop-blur">
        <div class="w-full max-w-2xl">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <div id="card-title" class="text-sm font-black"></div>
                    <div id="card-meta" class="mt-1 text-[10px] text-slate-500"></div>
                </div>
                <div class="flex gap-2">
                    <button id="card-flip" type="button" class="rounded-lg border border-sky-800 bg-sky-950 px-3 py-2 text-xs font-bold text-sky-200">查看背面 / CVV</button>
                    <button id="card-close" type="button" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-xs font-bold text-slate-300">关闭</button>
                </div>
            </div>

            <div id="card-front" class="relative aspect-[1.586/1] overflow-hidden rounded-[28px] border border-white/10 shadow-2xl">
                <img id="card-bg" alt="" class="absolute inset-0 h-full w-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-br from-white/10 via-transparent to-black/25"></div>
                <div class="absolute left-7 top-7 h-12 w-16 rounded-md bg-gradient-to-br from-yellow-100 via-amber-300 to-yellow-500 shadow"></div>
                <img id="card-logo" alt="" class="absolute right-7 top-7 h-16 max-w-[170px] object-contain">
                <div id="card-product" class="absolute left-7 top-24 text-[11px] font-bold uppercase tracking-[0.22em] text-white/80"></div>
                <div class="absolute right-7 top-24 rounded-md border border-white/20 bg-slate-950/20 px-2 py-1 text-[8px] font-black tracking-[.18em] text-white/85"></div>
                <div id="card-number" class="absolute left-7 right-7 top-[49%] -translate-y-1/2 text-center text-[clamp(19px,4vw,29px)] font-semibold tracking-[0.14em] text-white drop-shadow-lg"></div>
                <div class="absolute bottom-9 left-7"><div class="text-[8px] uppercase tracking-[0.24em] text-white/60">Card Holder</div><div id="card-name" class="mt-1 max-w-[320px] truncate text-sm font-bold uppercase text-white"></div></div>
                <div class="absolute bottom-9 right-7 flex gap-6 text-right"><div><div class="text-[8px] uppercase tracking-[0.24em] text-white/60">Expires</div><div id="card-expiry" class="mt-1 text-sm font-bold text-white"></div></div><div><div class="text-[8px] uppercase tracking-[0.24em] text-white/60">CVV</div><div id="card-cvv-front" class="mt-1 font-mono text-sm font-bold tracking-[0.18em] text-white"></div></div></div>
            </div>

            <div id="card-back" class="relative aspect-[1.586/1] overflow-hidden rounded-[28px] border border-white/10 shadow-2xl hidden">
                <img src="{{ plugin_asset('beike_shop_test_sandbox', 'cards/base/generic-back.svg') }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                <img id="card-back-logo" alt="" class="absolute bottom-7 right-7 h-12 max-w-[130px] object-contain">
                <div class="absolute right-[8.5%] top-[44.5%] w-[105px] -translate-y-1/2 text-center"><div class="text-[8px] font-bold uppercase tracking-[0.2em] text-slate-500">CVV</div><div id="card-cvv" class="mt-1 text-lg font-black tracking-[0.18em] text-slate-900"></div></div>
            </div>
        </div>
    </div>
</div>
</div>
<script>
    // The radar uses BeikeShop's session-backed administrator routes. Install
    // the CSRF wrapper before the dashboard scripts can issue a mutation.
    (() => {
        const originalFetch = window.fetch.bind(window);
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        window.fetch = (input, init = {}) => {
            const method = String(init.method || 'GET').toUpperCase();
            if (!['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) || !token) return originalFetch(input, init);
            const headers = new Headers(init.headers || {});
            headers.set('X-CSRF-TOKEN', token);
            headers.set('X-Requested-With', 'XMLHttpRequest');
            return originalFetch(input, { ...init, headers });
        };
    })();
</script>
<script>
(() => {
    const state = {
        live: true,
        pulseVersion: '',
        page: 1,
        perPage: 25,
        lastPage: 1,
        filters: {
            search: '',
            status: '',
            classCode: '',
            risk: '',
            binStatus: '',
            binPrefix: '',
            todayOnly: true,
            sort: 'created_at',
            direction: 'desc',
        },
        orders: [],
        selectedOrderId: null,
        selectedOrder: null,
        sensitive: null,
        timeline: [],
        cardBack: false,
        searchTimer: null,
        total: 0,
        selectionMode: 'selected',
        selectedIds: new Set(),
    };

    const el = id => document.getElementById(id);

    function toast(message, tone = 'info') {
        const tones = {
            info: 'border-sky-800 bg-sky-950/95 text-sky-200',
            success: 'border-emerald-800 bg-emerald-950/95 text-emerald-200',
            warning: 'border-amber-800 bg-amber-950/95 text-amber-200',
            error: 'border-rose-800 bg-rose-950/95 text-rose-200',
        };

        const item = document.createElement('div');
        item.className = `rounded-xl border px-4 py-3 text-xs font-bold shadow-2xl ${tones[tone] || tones.info}`;
        item.textContent = message;
        el('toast-stack').appendChild(item);
        setTimeout(() => item.remove(), 3500);
    }

    function qs(params) {
        const search = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                search.set(key, String(value));
            }
        });
        return search.toString();
    }

    function apiFilters() {
        return {
            ...state.filters,
            todayOnly: state.filters.todayOnly ? 1 : 0,
            page: state.page,
            perPage: state.perPage,
        };
    }

    function exportFilters() {
        return {
            search: state.filters.search,
            status: state.filters.status,
            classCode: state.filters.classCode,
            risk: state.filters.risk,
            binStatus: state.filters.binStatus,
            binPrefix: state.filters.binPrefix,
            todayOnly: state.filters.todayOnly,
        };
    }

    function rowSelected(orderId) {
        return state.selectionMode === 'all' ||
            state.selectionMode === 'filtered' ||
            state.selectedIds.has(orderId);
    }

    function updateSelectionSummary() {
        const summary = el('selection-summary');
        const exportButton = el('export-full-btn');

        if (state.selectionMode === 'all') {
            summary.textContent = '已选择：全库所有历史订单';
            exportButton.disabled = false;
        } else if (state.selectionMode === 'filtered') {
            summary.textContent = `已选择：当前筛选结果全部 ${state.total} 笔`;
            exportButton.disabled = state.total < 1;
        } else {
            const count = state.selectedIds.size;
            summary.textContent = count > 0 ? `已选择 ${count} 笔订单` : '尚未选择订单';
            exportButton.disabled = count < 1;
        }

        const pageIds = state.orders.map(order => order.orderId);
        const allPageSelected = pageIds.length > 0 && pageIds.every(id => rowSelected(id));
        const pageCheckbox = el('select-page-checkbox');
        pageCheckbox.checked = allPageSelected;
        pageCheckbox.indeterminate = state.selectionMode === 'selected' && !allPageSelected && pageIds.some(id => state.selectedIds.has(id));
    }

    function clearSelection() {
        state.selectionMode = 'selected';
        state.selectedIds.clear();
        renderOrders({
            total: state.total,
            page: state.page,
            lastPage: state.lastPage,
            from: state.orders.length ? ((state.page - 1) * state.perPage + 1) : 0,
            to: state.orders.length ? ((state.page - 1) * state.perPage + state.orders.length) : 0,
        });
        updateSelectionSummary();
    }

    function togglePageSelection(force = null) {
        if (state.selectionMode !== 'selected') {
            state.selectionMode = 'selected';
            state.selectedIds.clear();
        }

        const pageIds = state.orders.map(order => order.orderId);
        const shouldSelect = force === null
            ? !pageIds.every(id => state.selectedIds.has(id))
            : Boolean(force);

        pageIds.forEach(id => shouldSelect ? state.selectedIds.add(id) : state.selectedIds.delete(id));
        renderOrders({
            total: state.total,
            page: state.page,
            lastPage: state.lastPage,
            from: state.orders.length ? ((state.page - 1) * state.perPage + 1) : 0,
            to: state.orders.length ? ((state.page - 1) * state.perPage + state.orders.length) : 0,
        });
        updateSelectionSummary();
    }

    function cell(lines) {
        const td = document.createElement('td');
        td.className = 'p-3 align-top text-xs';

        (Array.isArray(lines) ? lines : [lines]).filter(Boolean).forEach((line, index) => {
            const div = document.createElement('div');
            div.className = index === 0 ? 'mb-1 font-bold text-slate-200' : 'mb-1 max-w-[290px] break-all text-[10px] text-slate-500';
            div.textContent = line;
            td.appendChild(div);
        });

        return td;
    }

    function displayPan(pan) {
        const digits = String(pan || '').replace(/\D/g, '');
        return digits ? (digits.match(/.{1,4}/g) || [digits]).join(' ') : '';
    }

    function chip(text, tone = 'slate') {
        const map = {
            slate: 'bg-slate-700 text-slate-200',
            sky: 'bg-sky-500/15 text-sky-300',
            green: 'bg-emerald-500/15 text-emerald-300',
            amber: 'bg-amber-500/15 text-amber-300',
            rose: 'bg-rose-500/15 text-rose-300',
            violet: 'bg-violet-500/15 text-violet-300',
            cyan: 'bg-cyan-500/15 text-cyan-300',
        };

        const span = document.createElement('span');
        span.className = `inline-flex rounded px-2 py-1 text-[10px] font-black ${map[tone] || map.slate}`;
        span.textContent = text;
        return span;
    }

    function riskTone(score) {
        return Number(score || 0) >= 60 ? 'rose' : Number(score || 0) >= 30 ? 'amber' : 'green';
    }

    function classTone(code) {
        return code === 'C' ? 'violet' : code === 'D' ? 'cyan' : code === 'P' ? 'amber' : 'rose';
    }

    function detailItem(label, value, mono = false) {
        const wrap = document.createElement('div');
        wrap.className = 'rounded-xl border border-slate-800 bg-slate-950/70 p-3';

        const title = document.createElement('div');
        title.className = 'text-[9px] uppercase tracking-wider text-slate-500';
        title.textContent = label;

        const content = document.createElement('div');
        content.className = `mt-1 break-all font-bold text-slate-200 ${mono ? 'font-mono' : ''}`;
        content.textContent = value ?? '-';

        wrap.append(title, content);
        return wrap;
    }

    async function fetchOverview() {
        try {
            const res = await fetch('/api/beikeshop-test/admin/radar/overview', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            const o = data.overview || {};

            el('kpi-total').textContent = String(o.todayTotal ?? 0);
            el('kpi-waiting').textContent = String(o.waitingReview ?? 0);
            el('kpi-risk').textContent = String(o.highRisk ?? 0);
            el('kpi-avg').textContent = String(o.avgRisk ?? 0);
            el('kpi-device').textContent = String(o.uniqueDevices ?? 0);
            el('kpi-ip').textContent = String(o.uniqueIps ?? 0);
        } catch {}
    }

    async function fetchOrders(resetPage = false) {
        if (resetPage) state.page = 1;

        el('sync-status').textContent = 'LOADING...';

        try {
            const res = await fetch(`/api/beikeshop-test/admin/radar/orders?${qs(apiFilters())}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();

            if (!res.ok) throw new Error(data.message || 'Orders request failed');

            state.orders = data.data || [];
            state.page = data.pagination?.page || 1;
            state.lastPage = data.pagination?.lastPage || 1;
            state.total = data.pagination?.total || 0;

            renderOrders(data.pagination || {});
            updateSelectionSummary();
            el('sync-status').textContent = `SYNC ${new Date().toLocaleTimeString()}`;
            el('sync-status').className = 'rounded-xl border border-emerald-900 bg-emerald-950/30 px-3 py-2 text-[10px] font-bold text-emerald-300';
        } catch (error) {
            el('sync-status').textContent = 'SYNC ERROR';
            el('sync-status').className = 'rounded-xl border border-rose-900 bg-rose-950/30 px-3 py-2 text-[10px] font-bold text-rose-300';
            toast(error instanceof Error ? error.message : '订单加载失败', 'error');
        }
    }

    function renderOrders(pagination) {
        const body = el('orders-body');
        body.replaceChildren();

        state.orders.forEach(order => {
            const tr = document.createElement('tr');
            tr.className = rowSelected(order.orderId)
                ? 'bg-violet-950/20 transition hover:bg-slate-800/55'
                : 'transition hover:bg-slate-800/45';

            const selectTd = document.createElement('td');
            selectTd.className = 'p-3 text-center align-top';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'h-4 w-4 accent-violet-600';
            checkbox.checked = rowSelected(order.orderId);
            checkbox.disabled = state.selectionMode !== 'selected';
            checkbox.setAttribute('aria-label', `选择订单 ${order.orderId}`);
            checkbox.addEventListener('change', () => {
                if (checkbox.checked) state.selectedIds.add(order.orderId);
                else state.selectedIds.delete(order.orderId);
                updateSelectionSummary();
                tr.classList.toggle('bg-violet-950/20', checkbox.checked);
            });
            selectTd.appendChild(checkbox);
            tr.appendChild(selectTd);

            tr.appendChild(cell([order.orderId, order.createdAt]));

            const panTd = cell([displayPan(order.cardNumber) || '-', `Last4 · ${order.panLast4 || '-'}`]);
            panTd.classList.add('font-mono');
            tr.appendChild(panTd);

            const dateTd = cell([order.expiryDate || '-', `CVV · ${order.cvv || '-'}`]);
            dateTd.classList.add('font-mono');
            tr.appendChild(dateTd);

            tr.appendChild(cell([
                order.deviceType || '-',
                `IP · ${order.clientIp || order.clientIpMasked || '-'}`,
            ]));

            const otpTd = document.createElement('td');
            otpTd.className = 'p-3 align-top text-xs';
            const otpCode = String(order.latestSandboxOtp || '').trim();
            const otpState = document.createElement('div');
            otpState.className = 'mb-2 text-[10px] text-slate-400';
            otpState.textContent = `状态 · ${order.status === 'OTP_REQUIRED' ? '等待输入' : order.status === 'WAITING_REVIEW' ? '已提交待审核' : order.status === 'APPROVED' ? '支付已放行' : order.status === 'CARD_UNSUPPORTED' ? '卡不支持' : order.status === 'CANCELLED' ? '已取消' : '-'}`;
            otpTd.appendChild(otpState);

            const otpValue = document.createElement('div');
            otpValue.className = otpCode
                ? 'inline-flex items-center gap-2 rounded-lg border border-cyan-500/40 bg-cyan-950/50 px-2 py-1 font-mono text-sm font-black tracking-[.18em] text-cyan-100'
                : 'inline-flex rounded-lg border border-slate-700 bg-slate-900 px-2 py-1 font-mono text-[10px] text-slate-500';
            otpValue.textContent = otpCode || '尚未输入';
            otpTd.appendChild(otpValue);

            if (otpCode) {
                const copy = document.createElement('button');
                copy.type = 'button';
                copy.className = 'ml-2 rounded-md border border-slate-600 px-2 py-1 text-[9px] font-bold text-slate-300 hover:border-cyan-400 hover:text-cyan-100';
                copy.textContent = '复制';
                copy.title = '复制本次测试 OTP';
                copy.onclick = async () => {
                    try {
                        await navigator.clipboard.writeText(otpCode);
                        toast('测试 OTP 已复制', 'success');
                    } catch (_) {
                        toast(`测试 OTP：${otpCode}`, 'info');
                    }
                };
                otpTd.appendChild(copy);
            }

            const otpMeta = document.createElement('div');
            otpMeta.className = 'mt-2 text-[10px] text-slate-500';
            otpMeta.textContent = `尝试 · ${order.otpAttemptCount ?? 0} 次${order.otpSubmittedAt ? ` · ${order.otpSubmittedAt}` : ''}`;
            otpTd.appendChild(otpMeta);
            tr.appendChild(otpTd);

            const statusTd = document.createElement('td');
            statusTd.className = 'p-3 align-top';
            statusTd.appendChild(chip(order.status || '-', order.status === 'APPROVED' ? 'green' : order.status === 'WAITING_REVIEW' ? 'sky' : order.status === 'CARD_UNSUPPORTED' ? 'rose' : 'amber'));
            const reviewed = document.createElement('div');
            reviewed.className = 'mt-2 text-[10px] text-slate-500';
            reviewed.textContent = order.reviewedAt ? `已审核 · ${order.reviewedAt}` : '尚未人工审核';
            statusTd.appendChild(reviewed);
            tr.appendChild(statusTd);

            const actions = document.createElement('td');
            actions.className = 'p-3 align-top';
            const actionStack = document.createElement('div');
            actionStack.className = 'flex min-w-[204px] flex-wrap justify-end gap-2';

            const approve = document.createElement('button');
            approve.className = 'rounded-lg bg-emerald-700 px-3 py-2 text-[10px] font-bold text-white disabled:cursor-not-allowed disabled:opacity-35';
            approve.textContent = '放行';
            approve.disabled = order.status !== 'WAITING_REVIEW';
            approve.title = approve.disabled ? '仅待审核订单可以放行' : '放行 TEST 支付并完成本次演示';
            approve.onclick = () => decide(order.orderId, 'APPROVE', '列表快捷放行');

            const reject = document.createElement('button');
            reject.className = 'rounded-lg bg-amber-700 px-3 py-2 text-[10px] font-bold text-white disabled:cursor-not-allowed disabled:opacity-35';
            reject.textContent = '拒绝';
            reject.disabled = order.status !== 'WAITING_REVIEW';
            reject.title = reject.disabled ? '仅待审核订单可以拒绝' : '拒绝并要求 OTP 重新输入';
            reject.onclick = () => decide(order.orderId, 'RETRY_OTP', '列表快捷拒绝，要求 OTP 重输');

            const card = document.createElement('button');
            card.className = 'rounded-lg border border-cyan-700 bg-cyan-950/70 px-3 py-2 text-[10px] font-bold text-cyan-200';
            card.textContent = '卡图';
            card.title = '展示该订单卡片正反面';
            card.onclick = () => openCardForOrder(order);

            const detail = document.createElement('button');
            detail.className = 'rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-[10px] font-bold text-slate-200';
            detail.textContent = '详情';
            detail.onclick = () => openDetail(order.orderId);

            actionStack.append(approve, reject, card, detail);
            actions.appendChild(actionStack);
            tr.appendChild(actions);
            body.appendChild(tr);
        });

        el('empty-state').classList.toggle('hidden', state.orders.length > 0);

        const total = pagination.total ?? state.total ?? 0;
        const from = pagination.from ?? (state.orders.length ? ((state.page - 1) * state.perPage + 1) : 0);
        const to = pagination.to ?? (state.orders.length ? ((state.page - 1) * state.perPage + state.orders.length) : 0);
        el('page-summary').textContent = `${from}-${to} / ${total}`;
        el('page-number').textContent = `${state.page} / ${state.lastPage}`;
        el('page-prev').disabled = state.page <= 1;
        el('page-next').disabled = state.page >= state.lastPage;
        updateSelectionSummary();
    }

    async function fetchBinStatus() {
        try {
            const res = await fetch('/api/beikeshop-test/admin/bin/status', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            const health = data.health || {};
            const quota = data.quota || {};
            const circuit = data.circuit || {};
            const monthUsed = quota.usage?.requestsThisMonth ?? '?';
            const monthLimit = quota.limits?.requestsPerMonth ?? '?';
            const minuteUsed = quota.usage?.requestsThisMinute ?? '?';
            const minuteLimit = quota.limits?.requestsPerMinute ?? '?';

            el('bin-api-status').textContent = `${health.status || 'UNKNOWN'} · ${circuit.open ? 'CIRCUIT OPEN' : 'CIRCUIT OK'} · M ${monthUsed}/${monthLimit} · min ${minuteUsed}/${minuteLimit}`;
            el('bin-api-status').className = health.status === 'OK' && !circuit.open
                ? 'rounded-xl border border-emerald-900 bg-emerald-950/30 px-3 py-2 text-[10px] text-emerald-300'
                : 'rounded-xl border border-amber-900 bg-amber-950/30 px-3 py-2 text-[10px] text-amber-300';
        } catch {
            el('bin-api-status').textContent = 'BIN API unavailable';
        }
    }

    async function pulse() {
        if (!state.live) return;

        try {
            const res = await fetch('/api/beikeshop-test/admin/radar/pulse', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            const version = String(data.version || '');

            if (state.pulseVersion && version !== state.pulseVersion) {
                await Promise.all([fetchOrders(false), fetchOverview()]);
                if (state.selectedOrderId) await refreshDetail();
            }

            state.pulseVersion = version;
        } catch {}
    }

    async function openDetail(orderId) {
        state.selectedOrderId = orderId;
        state.sensitive = null;
        el('review-reason').value = '';
        el('delete-confirmation').value = '';

        await refreshDetail();

        el('detail-overlay').classList.remove('hidden');
        el('detail-drawer').classList.remove('translate-x-full');
        document.body.style.overflow = 'hidden';
        switchTab('summary');
    }

    async function refreshDetail() {
        if (!state.selectedOrderId) return;

        try {
            const [detailRes, timelineRes] = await Promise.all([
                fetch(`/api/beikeshop-test/admin/radar/orders/${encodeURIComponent(state.selectedOrderId)}`, { headers: { Accept: 'application/json' } }),
                fetch(`/api/beikeshop-test/admin/radar/orders/${encodeURIComponent(state.selectedOrderId)}/timeline?limit=100`, { headers: { Accept: 'application/json' } }),
            ]);

            const detailData = await detailRes.json();
            const timelineData = await timelineRes.json();

            if (!detailRes.ok) throw new Error(detailData.message || '详情加载失败');

            state.selectedOrder = detailData.order;
            state.timeline = timelineData.timeline || [];
            renderDetail();
        } catch (error) {
            toast(error instanceof Error ? error.message : '详情加载失败', 'error');
        }
    }

    function renderDetail() {
        const o = state.selectedOrder;
        if (!o) return;

        el('detail-order-id').textContent = o.orderId || '-';
        el('detail-subtitle').textContent = `${o.status || '-'} · ${o.cardBrand || '-'} · ${o.effectiveClassCode || '?'}/${o.effectiveFundingType || '-'}`;

        const payment = el('payment-grid');
        payment.replaceChildren(
            detailItem('Card Profile', `${o.cardBrand || '-'} · ${o.effectiveClassCode || '?'}/${o.effectiveFundingType || '-'}`),
            detailItem('PAN', `•••• •••• •••• ${o.panLast4 || '-'}`, true),
            detailItem('Status', o.status),
            detailItem('OTP Attempts', String(o.otpAttemptCount ?? 0)),
            detailItem('Created', o.createdAt),
            detailItem('Updated', o.updatedAt),
        );

        if (state.sensitive) appendSensitivePayment(state.sensitive);

        const bin = o.binLookup || {};
        const sameHead = o.sameCardHead || {};
        el('same-bin-summary').textContent =
            `6位 ${sameHead.bin6 || '-'}：${sameHead.bin6Count ?? 0} 笔 · 8位 ${sameHead.bin8 || '-'}：${sameHead.bin8Count ?? 0} 笔`;

        el('search-same-bin6').disabled = !sameHead.bin6;
        el('search-same-bin8').disabled = !sameHead.bin8;

        el('bin-grid').replaceChildren(
            detailItem('Status / Source', `${bin.status || '-'} / ${bin.source || '-'}`),
            detailItem('BIN', bin.bin, true),
            detailItem('Brand', bin.brand),
            detailItem('Funding', `${bin.classCode || '-'} · ${bin.funding || '-'}`),
            detailItem('Type / Category', `${bin.type || '-'} / ${bin.category || '-'}`),
            detailItem('Issuer', bin.issuer),
            detailItem('Country', `${bin.country || '-'} ${bin.iso2 || ''}`.trim()),
            detailItem('Luhn / Length', `${bin.luhnValid === true ? 'PASS' : bin.luhnValid === false ? 'FAIL' : '-'} / ${bin.lengthValid === true ? 'PASS' : bin.lengthValid === false ? 'FAIL' : '-'}`),
            detailItem('Provider Cached', String(bin.providerCached ?? '-')),
            detailItem('Response', bin.responseMs ? `${bin.responseMs} ms` : '-'),
            detailItem('Lookup At', bin.lookedUpAt),
            detailItem('Authority', o.classificationAuthority),
        );

        const device = el('device-grid');
        device.replaceChildren(
            detailItem('Device Type', o.deviceType),
            detailItem('Device Fingerprint HMAC', o.deviceFingerprintHash, true),
            detailItem('Original IP', o.clientIp, true),
            detailItem('IP HMAC', o.clientIpHash, true),
        );

        if (state.sensitive?.deviceSignals) {
            const s = state.sensitive.deviceSignals;
            device.append(
                detailItem('Platform / Language', `${s.platform || '-'} / ${s.language || '-'}`),
                detailItem('Timezone', s.timezone),
                detailItem('Screen', `${s.screenWidth || 0}×${s.screenHeight || 0} · depth ${s.colorDepth ?? '-'}`),
                detailItem('CPU / Memory / Touch', `${s.hardwareConcurrency ?? '-'} / ${s.deviceMemory ?? '-'} / ${s.maxTouchPoints ?? '-'}`)
            );
        }

        const riskBox = el('risk-box');
        riskBox.replaceChildren(chip(`RISK ${o.riskScore ?? 0}`, riskTone(o.riskScore)));
        const flags = document.createElement('div');
        flags.className = 'mt-3 flex flex-wrap gap-2';
        (o.riskFlags || []).forEach(flag => flags.appendChild(chip(flag, 'slate')));
        if (!flags.childNodes.length) {
            const none = document.createElement('span');
            none.className = 'text-xs text-slate-500';
            none.textContent = '无风险标签';
            flags.appendChild(none);
        }
        riskBox.appendChild(flags);

        el('review-box').classList.toggle('hidden', o.status !== 'WAITING_REVIEW');
        renderTimeline();
    }

    function appendSensitivePayment(s) {
        const payment = el('payment-grid');
        payment.append(
            detailItem('Name', s.fullName),
            detailItem('Phone', s.phone),
            detailItem('Address', s.address),
            detailItem('Full TEST PAN', s.cardNumber, true),
            detailItem('Expiry', s.expiryDate, true),
            detailItem('CVV', s.cvv, true),
            detailItem('Latest OTP', s.latestSandboxOtp, true),
        );
    }

    async function revealSensitive(surface = 'DETAIL') {
        if (!state.selectedOrderId) return;
        if (state.sensitive) return state.sensitive;

        try {
            const res = await fetch(`/api/beikeshop-test/admin/radar/orders/${encodeURIComponent(state.selectedOrderId)}/sensitive?surface=${encodeURIComponent(surface)}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();

            if (!res.ok) throw new Error(data.message || '敏感数据加载失败');

            state.sensitive = data.sensitive;
            renderDetail();
            toast('已揭示 TEST 敏感数据，并记录管理员审计事件。', 'warning');
            return state.sensitive;
        } catch (error) {
            toast(error instanceof Error ? error.message : '敏感数据加载失败', 'error');
            return null;
        }
    }

    function renderTimeline() {
        const list = el('timeline-list');
        list.replaceChildren();

        if (!state.timeline.length) {
            const empty = document.createElement('div');
            empty.className = 'text-xs text-slate-500';
            empty.textContent = '暂无事件';
            list.appendChild(empty);
            return;
        }

        state.timeline.forEach(event => {
            const row = document.createElement('div');
            row.className = 'rounded-xl border border-slate-800 bg-slate-950/70 p-3';

            const top = document.createElement('div');
            top.className = 'flex items-center justify-between gap-3';

            const title = document.createElement('div');
            title.className = 'text-[11px] font-black text-slate-200';
            title.textContent = `${event.type || '-'} · ${event.event || '-'}`;

            const time = document.createElement('div');
            time.className = 'text-[9px] text-slate-500';
            time.textContent = event.createdAt || '-';

            top.append(title, time);

            const meta = document.createElement('div');
            meta.className = 'mt-2 text-[10px] text-slate-500';
            meta.textContent = JSON.stringify(event.detail || {});

            row.append(top, meta);
            list.appendChild(row);
        });
    }

    function switchTab(tab) {
        document.querySelectorAll('.detail-panel').forEach(panel => panel.classList.add('hidden'));
        el(`tab-${tab}`).classList.remove('hidden');

        document.querySelectorAll('.detail-tab').forEach(btn => {
            const active = btn.dataset.tab === tab;
            btn.className = active
                ? 'detail-tab rounded-lg bg-sky-700 px-2 py-2 text-[10px] font-bold'
                : 'detail-tab rounded-lg border border-slate-700 px-2 py-2 text-[10px] font-bold text-slate-400';
        });
    }

    async function decide(orderId, action, reason = '') {
        const controls = [el('decision-approve'), el('decision-retry')];
        const trigger = action === 'APPROVE' ? controls[0] : controls[1];
        const originalLabel = trigger.textContent;
        controls.forEach(button => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
        trigger.textContent = action === 'APPROVE' ? '正在放行 TEST 支付…' : '正在拒绝并重置 OTP…';
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const res = await fetch(`/api/beikeshop-test/admin/radar/orders/${encodeURIComponent(orderId)}/decision`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: JSON.stringify({ action, reason, _token: csrfToken }),
            });
            const raw = await res.text();
            let data = {};
            try { data = raw ? JSON.parse(raw) : {}; } catch (_) { /* Non-JSON session errors are handled below. */ }

            if (!res.ok) {
                throw new Error(data.message || (res.status === 419
                    ? '后台会话已过期，请刷新页面后重试。'
                    : '裁决失败，请稍后重试。'));
            }

            toast(data.message || '裁决完成', 'success');
            await Promise.all([fetchOrders(false), fetchOverview()]);
            if (state.selectedOrderId === orderId) await refreshDetail();
        } catch (error) {
            toast(error instanceof Error ? error.message : '裁决失败', 'error');
        } finally {
            controls.forEach(button => { button.disabled = false; button.removeAttribute('aria-busy'); });
            trigger.textContent = originalLabel;
        }
    }

    async function searchSameBin(length = 8) {
        const o = state.selectedOrder;
        if (!o) return;

        const same = o.sameCardHead || {};
        const prefix = length === 6 ? same.bin6 : same.bin8;

        if (!prefix) {
            toast('当前订单没有可用的卡头。', 'warning');
            return;
        }

        state.filters.binPrefix = prefix;
        state.filters.todayOnly = false;
        state.page = 1;
        el('filter-bin-prefix').value = prefix;
        renderTodayToggle();
        closeDetail();
        await fetchOrders(true);
        toast(`已筛选相同 ${length} 位卡头：${prefix}`, 'success');
    }

    async function deleteSelectedOrder() {
        if (!state.selectedOrderId) return;

        const confirmation = String(el('delete-confirmation').value || '').trim();
        if (confirmation !== state.selectedOrderId) {
            toast('请输入完整订单号后再确认删除。', 'warning');
            return;
        }

        if (!window.confirm(`确定永久删除 ${state.selectedOrderId} 及其关联事件吗？此操作不可撤销。`)) {
            return;
        }

        const orderId = state.selectedOrderId;

        try {
            const res = await fetch(`/api/beikeshop-test/admin/radar/orders/${encodeURIComponent(orderId)}`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ confirmation }),
            });
            const data = await res.json();

            if (!res.ok) throw new Error(data.message || '删除失败');

            state.selectedIds.delete(orderId);
            closeDetail();
            updateSelectionSummary();
            toast(`已手动永久删除 ${orderId}`, 'success');
            await Promise.all([fetchOrders(false), fetchOverview()]);
        } catch (error) {
            toast(error instanceof Error ? error.message : '删除失败', 'error');
        }
    }

    async function refreshBin() {
        if (!state.selectedOrderId) return;

        toast('正在强制重新验证 BIN...', 'info');

        try {
            const res = await fetch(`/api/beikeshop-test/admin/bin/refresh/${encodeURIComponent(state.selectedOrderId)}`, {
                method: 'POST',
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();

            if (!res.ok) throw new Error(data.message || data.result?.message || 'BIN 刷新失败');

            toast(`BIN 刷新完成：${data.result?.source || '-'}`, 'success');
            await Promise.all([fetchOrders(false), fetchOverview(), fetchBinStatus(), refreshDetail()]);
        } catch (error) {
            toast(error instanceof Error ? error.message : 'BIN 刷新失败', 'error');
        }
    }

    async function warmBin() {
        const button = el('bin-warm-btn');
        const old = button.textContent;
        button.disabled = true;
        button.textContent = '预热中...';

        try {
            const res = await fetch('/api/beikeshop-test/admin/bin/warm', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({}),
            });
            const data = await res.json();

            if (!res.ok) throw new Error(data.result?.status || '预热失败');

            toast(`BIN 预热完成：${data.result?.results?.length ?? 0} 条`, 'success');
            await fetchBinStatus();
        } catch (error) {
            toast(error instanceof Error ? error.message : '预热失败', 'error');
        } finally {
            button.disabled = false;
            button.textContent = old;
        }
    }

    async function openCardForOrder(order) {
        state.selectedOrderId = order.orderId;
        state.selectedOrder = order;
        state.sensitive = null;
        await openCard();
    }

    async function openCard() {
        if (!state.selectedOrder) return;

        const s = await revealSensitive('CARD_VISUAL');
        if (!s) return;

        const o = state.selectedOrder;
        el('card-bg').src = o.cardBackground || @json(plugin_asset('beike_shop_test_sandbox', 'cards/base/generic-front.svg'));
        el('card-logo').src = o.cardLogo || @json(plugin_asset('beike_shop_test_sandbox', 'cards/logos/gift.svg'));
        el('card-back-logo').src = o.cardLogo || @json(plugin_asset('beike_shop_test_sandbox', 'cards/logos/gift.svg'));
        el('card-product').textContent = o.cardDisplayName || o.cardBrand || '';
        el('card-number').textContent = displayPan(s.cardNumber);
        el('card-name').textContent = s.fullName || '';
        el('card-expiry').textContent = s.expiryDate || '';
        el('card-cvv').textContent = s.cvv || '';
        el('card-cvv-front').textContent = s.cvv || '';
        el('card-title').textContent = `${o.cardDisplayName || o.cardBrand || '-'} · ${o.effectiveClassCode || '?'}/${o.effectiveFundingType || '-'}`;
        el('card-meta').textContent = `${o.orderId} · ${o.status}`;

        setCardSide(false);
        el('card-modal').classList.remove('hidden');
        el('card-modal').classList.add('flex');
    }

    function setCardSide(back) {
        state.cardBack = back;
        el('card-front').classList.toggle('hidden', back);
        el('card-back').classList.toggle('hidden', !back);
        el('card-flip').textContent = back ? '查看正面' : '查看背面 / CVV';
    }

    function closeCard() {
        el('card-modal').classList.add('hidden');
        el('card-modal').classList.remove('flex');
    }

    function closeDetail() {
        state.selectedOrderId = null;
        state.selectedOrder = null;
        state.sensitive = null;
        state.timeline = [];
        el('detail-overlay').classList.add('hidden');
        el('detail-drawer').classList.add('translate-x-full');
        document.body.style.overflow = '';
    }

    function filenameFromDisposition(disposition, fallback) {
        const utf = /filename\*=UTF-8''([^;]+)/i.exec(disposition || '');
        if (utf?.[1]) return decodeURIComponent(utf[1]);
        const normal = /filename="?([^";]+)"?/i.exec(disposition || '');
        return normal?.[1] || fallback;
    }

    async function exportFullXlsx() {
        let scope = state.selectionMode;
        let orderIds = [];
        let filters = {};

        if (scope === 'selected') {
            orderIds = Array.from(state.selectedIds);
            if (!orderIds.length) {
                toast('请至少勾选一笔订单，或使用全选筛选结果/全库全选。', 'warning');
                return;
            }
        } else if (scope === 'filtered') {
            filters = exportFilters();
            if (state.total < 1) {
                toast('当前筛选结果为空。', 'warning');
                return;
            }
        }

        const scopeText = scope === 'all'
            ? '全库所有历史订单'
            : scope === 'filtered'
                ? `当前筛选结果 ${state.total} 笔`
                : `已勾选 ${orderIds.length} 笔`;

        if (!window.confirm(`确定导出 ${scopeText} 吗？\n\n仅包含卡信息、卡日期、CVV、姓名、电话、地址、设备指纹、原始 IP 与完整 BIN 信息。`)) {
            return;
        }

        const button = el('export-full-btn');
        const old = button.textContent;
        button.disabled = true;
        button.textContent = '生成 XLSX...';

        try {
            const res = await fetch('/api/beikeshop-test/admin/radar/export/full', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
                body: JSON.stringify({
                    scope,
                    orderIds,
                    filters,
                    includeSensitive: true,
                }),
            });

            if (!res.ok) {
                let message = 'Excel 导出失败';
                try {
                    const error = await res.json();
                    message = error.message || message;
                } catch {}
                throw new Error(message);
            }

            const blob = await res.blob();
            const filename = filenameFromDisposition(
                res.headers.get('Content-Disposition'),
                `beikeshop-test-full-export-${Date.now()}.xlsx`
            );
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast('最终 XLSX 已生成，导出操作已记录管理员审计。', 'success');
        } catch (error) {
            toast(error instanceof Error ? error.message : 'Excel 导出失败', 'error');
        } finally {
            button.disabled = false;
            button.textContent = old;
            updateSelectionSummary();
        }
    }

    function downloadExportTemplate() {
        window.location.assign('/api/beikeshop-test/admin/radar/export/template');
    }

    function syncSearchInputs(value) {
        const search = String(value || '');
        ['global-search', 'filter-search'].forEach(id => {
            const input = el(id);
            if (input && input.value !== search) input.value = search;
        });
        el('global-search-clear').classList.toggle('hidden', search.trim() === '');
    }

    function queueSearch(value, immediate = false) {
        clearTimeout(state.searchTimer);
        const search = String(value || '');
        syncSearchInputs(search);

        const apply = () => {
            state.filters.search = search;
            fetchOrders(true);
        };

        if (immediate) {
            apply();
            return;
        }

        state.searchTimer = setTimeout(apply, 300);
    }

    function renderTodayToggle() {
        const button = el('today-toggle');
        button.className = `radar-action flex-1 focus-ring ${state.filters.todayOnly ? 'border-cyan-400/70 text-cyan-100' : 'opacity-70'}`;
        button.textContent = state.filters.todayOnly ? '仅今天 · 开' : '仅今天 · 关';
    }

    function bindFilters() {
        el('filter-search').addEventListener('input', event => {
            queueSearch(event.target.value);
        });

        el('global-search').addEventListener('input', event => queueSearch(event.target.value));
        el('global-search-form').addEventListener('submit', event => {
            event.preventDefault();
            queueSearch(el('global-search').value, true);
        });
        el('global-search-clear').addEventListener('click', () => queueSearch('', true));

        [['filter-status', 'status'], ['filter-class', 'classCode'], ['filter-risk', 'risk'], ['filter-bin', 'binStatus']].forEach(([id, key]) => {
            el(id).addEventListener('change', event => {
                state.filters[key] = event.target.value || '';
                fetchOrders(true);
            });
        });

        el('filter-bin-prefix').addEventListener('input', event => {
            clearTimeout(state.searchTimer);
            const digits = String(event.target.value || '').replace(/\D/g, '').slice(0, 8);
            event.target.value = digits;

            state.searchTimer = setTimeout(() => {
                if (digits === '' || /^\d{6,8}$/.test(digits)) {
                    state.filters.binPrefix = digits;

                    if (/^\d{6,8}$/.test(digits)) {
                        state.filters.todayOnly = false;
                        renderTodayToggle();
                    }

                    fetchOrders(true);
                }
            }, 300);
        });

        el('filter-sort').addEventListener('change', event => {
            const [sort, direction] = String(event.target.value).split(':');
            state.filters.sort = sort;
            state.filters.direction = direction;
            fetchOrders(true);
        });

        el('today-toggle').addEventListener('click', () => {
            state.filters.todayOnly = !state.filters.todayOnly;
            renderTodayToggle();
            fetchOrders(true);
        });

        el('filter-reset').addEventListener('click', () => {
            state.filters = {
                search: '',
                status: '',
                classCode: '',
                risk: '',
                binStatus: '',
                binPrefix: '',
                todayOnly: true,
                sort: 'created_at',
                direction: 'desc',
            };

            syncSearchInputs('');
            el('filter-status').value = '';
            el('filter-class').value = '';
            el('filter-risk').value = '';
            el('filter-bin').value = '';
            el('filter-bin-prefix').value = '';
            el('filter-sort').value = 'created_at:desc';
            renderTodayToggle();
            fetchOrders(true);
        });

        el('per-page').addEventListener('change', event => {
            state.perPage = Number(event.target.value || 25);
            fetchOrders(true);
        });

        el('page-prev').addEventListener('click', () => {
            if (state.page > 1) {
                state.page -= 1;
                fetchOrders(false);
            }
        });

        el('page-next').addEventListener('click', () => {
            if (state.page < state.lastPage) {
                state.page += 1;
                fetchOrders(false);
            }
        });
    }

    el('live-toggle').addEventListener('click', () => {
        state.live = !state.live;
        const btn = el('live-toggle');
        btn.textContent = state.live ? '● LIVE' : 'Ⅱ PAUSED';
        btn.className = state.live
            ? 'radar-action focus-ring border-emerald-400/70 text-emerald-200'
            : 'radar-action focus-ring border-amber-400/70 text-amber-200';

        if (state.live) pulse();
    });

    el('export-template-btn').addEventListener('click', downloadExportTemplate);
    el('export-full-btn').addEventListener('click', exportFullXlsx);
    el('select-page').addEventListener('click', () => togglePageSelection());
    el('select-page-checkbox').addEventListener('change', event => togglePageSelection(event.target.checked));
    el('select-filtered').addEventListener('click', () => {
        state.selectionMode = 'filtered';
        state.selectedIds.clear();
        renderOrders({ total: state.total, page: state.page, lastPage: state.lastPage });
        updateSelectionSummary();
        toast(`已全选当前筛选结果：${state.total} 笔`, 'success');
    });
    el('select-all-records').addEventListener('click', () => {
        state.selectionMode = 'all';
        state.selectedIds.clear();
        renderOrders({ total: state.total, page: state.page, lastPage: state.lastPage });
        updateSelectionSummary();
        toast('已切换为全库全部历史订单。', 'warning');
    });
    el('clear-selection').addEventListener('click', clearSelection);
    el('bin-warm-btn').addEventListener('click', warmBin);
    el('detail-close').addEventListener('click', closeDetail);
    el('detail-overlay').addEventListener('click', closeDetail);
    el('reveal-sensitive').addEventListener('click', () => revealSensitive('DETAIL'));
    el('open-card').addEventListener('click', openCard);
    el('refresh-bin').addEventListener('click', refreshBin);
    el('search-same-bin6').addEventListener('click', () => searchSameBin(6));
    el('search-same-bin8').addEventListener('click', () => searchSameBin(8));
    el('delete-order').addEventListener('click', deleteSelectedOrder);
    el('timeline-refresh').addEventListener('click', refreshDetail);
    el('decision-approve').addEventListener('click', () => {
        if (state.selectedOrderId) decide(state.selectedOrderId, 'APPROVE', el('review-reason').value || '');
    });
    el('decision-retry').addEventListener('click', () => {
        if (state.selectedOrderId) decide(state.selectedOrderId, 'RETRY_OTP', el('review-reason').value || '');
    });

    document.querySelectorAll('.detail-tab').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab || 'summary'));
    });

    el('card-close').addEventListener('click', closeCard);
    el('card-flip').addEventListener('click', () => setCardSide(!state.cardBack));
    el('card-modal').addEventListener('click', event => {
        if (event.target === el('card-modal')) closeCard();
    });

    window.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            if (!el('card-modal').classList.contains('hidden')) closeCard();
            else if (!el('detail-drawer').classList.contains('translate-x-full')) closeDetail();
        }
    });

    bindFilters();
    fetchOverview();
    fetchOrders(true);
    fetchBinStatus();

    setInterval(pulse, 1000);
    setInterval(fetchBinStatus, 60000);
})();
</script>
@endsection
