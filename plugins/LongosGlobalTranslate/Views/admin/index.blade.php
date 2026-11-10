@extends('admin::layouts.master')

@section('title', __('LongosGlobalTranslate::translate.title'))

@section('content')
@php
    $enabledLocales = array_values(array_unique((array) ($settings['enabled_locales'] ?? [])));
    $widget = (array) ($settings['widget'] ?? []);
    $modeLabels = ['preview_only' => '仅预览', 'free_only' => '仅免费额度', 'offline_dictionary' => '仅已发布词典'];
    $providerLabels = ['aliyun' => '阿里云', 'volcengine' => '火山引擎', 'baidu' => '百度翻译', 'deepl' => 'DeepL', 'google' => 'Google Cloud', 'azure' => 'Microsoft Azure', 'siliconflow' => '硅基流动 · 免费 AI'];
    $jobStatusLabels = ['planned' => '待执行', 'queued' => '排队中', 'scanning' => '扫描中', 'running' => '翻译中', 'completed' => '已完成', 'partial_failed' => '部分失败', 'failed' => '失败', 'cancelled' => '已取消', 'paused' => '已暂停', 'pausing' => '暂停中'];
    $localeCatalog = ['ar' => '阿拉伯语', 'de' => '德语', 'es' => '西班牙语', 'fr' => '法语', 'id' => '印度尼西亚语', 'it' => '意大利语', 'ja' => '日语', 'ko' => '韩语', 'nl' => '荷兰语', 'pl' => '波兰语', 'pt' => '葡萄牙语', 'ru' => '俄语', 'th' => '泰语', 'tr' => '土耳其语', 'vi' => '越南语', 'zh-cn' => '简体中文', 'zh-tw' => '繁体中文'];
    $verifiedAccounts = collect($accounts)->filter(static fn (array $account): bool => !empty($account['verified_at']))->count();
    $dispatchReady = (bool) ($diagnostics['dispatch_enabled'] ?? false);
    $activeJobs = collect($jobs)->filter(static fn (object $job): bool => in_array((string) $job->status, ['planned', 'queued', 'scanning', 'running', 'pausing'], true))->count();
    $publishedLocales = (int) ($library_health['published_languages'] ?? 0);
    $nextPanel = 'library';
    $nextActionTitle = '维护现有翻译';
    $nextActionLabel = '查看任务';
    if ($enabledLocales === []) {
        $nextPanel = 'languages';
        $nextActionTitle = '先启用目标语言';
        $nextActionLabel = '设置语言';
    } elseif ($verifiedAccounts === 0 || ! $dispatchReady) {
        $nextPanel = 'providers';
        $nextActionTitle = $verifiedAccounts === 0 ? '连接并验证翻译服务商' : '开启服务商派发';
        $nextActionLabel = '管理服务商';
    } elseif ($publishedLocales === 0 && ($settings['mode'] ?? '') !== 'free_only') {
        $nextPanel = 'languages';
        $nextActionTitle = '选择免费额度翻译模式';
        $nextActionLabel = '调整模式';
    } elseif ($publishedLocales === 0) {
        $nextActionTitle = '开始第一批翻译';
        $nextActionLabel = '开始翻译';
    }
    $lgtRoutes = [
        'settings' => admin_route('longos_global_translate.settings.save'),
        'country' => admin_route('longos_global_translate.country-rules.save'),
        'countryRecommendations' => admin_route('longos_global_translate.country-rules.recommendations.apply'),
        'providers' => admin_route('longos_global_translate.providers.save'),
        'providerBase' => rtrim(admin_route('longos_global_translate.index'), '/') . '/providers/',
        'scan' => admin_route('longos_global_translate.scan'),
        'plan' => admin_route('longos_global_translate.jobs.plan'),
        'retryDefinite' => rtrim(admin_route('longos_global_translate.index'), '/') . '/jobs/__job__/retry-definite',
        'jobBase' => rtrim(admin_route('longos_global_translate.index'), '/') . '/jobs/',
        'translations' => admin_route('longos_global_translate.translations.index'),
        'bulkDeleteTranslations' => admin_route('longos_global_translate.translations.bulk-delete'),
        'bulkResetLibrary' => admin_route('longos_global_translate.library.bulk-reset'),
        'libraryBase' => rtrim(admin_route('longos_global_translate.index'), '/') . '/library/',
        'translationBase' => rtrim(admin_route('longos_global_translate.index'), '/') . '/translations/',
        'publish' => admin_route('longos_global_translate.publications.publish'),
        'quickPublish' => admin_route('longos_global_translate.publications.quick'),
        'publicationBase' => rtrim(admin_route('longos_global_translate.index'), '/') . '/publications/',
        'status' => admin_route('longos_global_translate.status'),
        'glossarySave' => admin_route('longos_global_translate.glossary.protected.save'),
        'glossaryBase' => rtrim(admin_route('longos_global_translate.index'), '/') . '/glossary/protected/',
    ];
@endphp

<style>
.lgt-workspace{--lgt-ink:#101828;--lgt-text:#344054;--lgt-muted:#667085;--lgt-line:#dfe5ef;--lgt-bg:#f7f9fc;--lgt-primary:#155eef;--lgt-safe:#eff8ff;max-width:1400px;margin:auto;color:var(--lgt-text)}
.lgt-skip{position:absolute;top:-5rem;left:1rem;z-index:9999;background:#101828;color:#fff;padding:.7rem 1rem;border-radius:.5rem}.lgt-skip:focus{top:1rem;color:#fff}.lgt-workspace h1,.lgt-workspace h2,.lgt-workspace h3{color:var(--lgt-ink)}
.lgt-hero{position:relative;overflow:hidden;border:1px solid #c5d7ff;border-radius:1.25rem;padding:clamp(1.25rem,2.7vw,2.3rem);background:radial-gradient(circle at 90% 0,#dbe8ff,transparent 34rem),linear-gradient(135deg,#f7faff,#fff)}.lgt-hero:after{content:"";position:absolute;right:-5rem;bottom:-8rem;width:19rem;height:19rem;border:2.3rem solid rgba(21,94,239,.07);border-radius:50%}
.lgt-hero>*{position:relative;z-index:1}.lgt-eyebrow{display:inline-flex;align-items:center;gap:.45rem;padding:.3rem .65rem;border-radius:99px;background:#e8f0ff;color:#1849a9;font-weight:700;font-size:.78rem}.lgt-eyebrow:before{content:"";width:.45rem;height:.45rem;border-radius:50%;background:#2e90fa}.lgt-hero h1{font-size:clamp(1.55rem,2.7vw,2.4rem);letter-spacing:-.04em;margin:.85rem 0 .5rem;font-weight:760}.lgt-hero p{max-width:53rem;margin:0;line-height:1.7}.lgt-hero-actions{display:flex;gap:.7rem;flex-wrap:wrap;margin-top:1.2rem}.lgt-mode{display:inline-flex;align-items:center;gap:.45rem;margin-top:1rem;padding:.4rem .65rem;border:1px solid #c7d7fe;border-radius:.55rem;background:#fff;font-size:.82rem}.lgt-dot{width:.5rem;height:.5rem;border-radius:50%;background:#12b76a}.lgt-dot.warning{background:#f79009}.lgt-dot.muted{background:#98a2b3}
.lgt-steps{display:grid;grid-template-columns:repeat(5,minmax(9rem,1fr));gap:.55rem;overflow:auto;margin:1rem 0}.lgt-step{min-width:9rem;text-align:left;border:1px solid var(--lgt-line);border-radius:.75rem;background:#fff;padding:.75rem;color:#475467}.lgt-step:hover{border-color:#9db9ff}.lgt-step[aria-current="step"]{border-color:var(--lgt-primary);background:#f3f6ff;color:#123a93;box-shadow:0 .35rem 1rem rgba(21,94,239,.1)}.lgt-step b{display:inline-grid;place-items:center;width:1.35rem;height:1.35rem;margin-right:.35rem;border-radius:50%;background:#98a2b3;color:#fff;font-size:.72rem}.lgt-step[aria-current="step"] b{background:var(--lgt-primary)}.lgt-step small{display:block;padding-left:1.7rem;margin-top:.25rem;color:var(--lgt-muted);font-size:.72rem}
.lgt-panel[hidden]{display:none!important}.lgt-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:1rem}.lgt-12{grid-column:span 12}.lgt-8{grid-column:span 8}.lgt-7{grid-column:span 7}.lgt-6{grid-column:span 6}.lgt-5{grid-column:span 5}.lgt-4{grid-column:span 4}.lgt-card{border:1px solid var(--lgt-line);border-radius:1rem;background:#fff;box-shadow:0 .2rem .9rem rgba(16,24,40,.035)}.lgt-card-h{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;padding:1.15rem 1.2rem .75rem}.lgt-card-b{padding:1.1rem 1.2rem 1.25rem}.lgt-card h2{font-size:1.07rem;margin:0;font-weight:720}.lgt-desc{margin:.3rem 0 0;color:var(--lgt-muted);font-size:.88rem;line-height:1.55}
.lgt-stat{padding:1.05rem 1.15rem;min-height:7.9rem}.lgt-stat-label{display:flex;justify-content:space-between;color:var(--lgt-muted);font-size:.85rem}.lgt-stat-value{margin:.8rem 0 .2rem;font-size:1.75rem;line-height:1;font-weight:760;letter-spacing:-.04em;color:var(--lgt-ink)}.lgt-stat-help{font-size:.78rem;color:var(--lgt-muted)}.lgt-safe{display:flex;gap:.8rem;border:1px solid #b2ddff;border-radius:.8rem;padding:1rem;background:var(--lgt-safe);color:#175cd3}.lgt-safe b{display:grid;place-items:center;flex:0 0 1.65rem;height:1.65rem;border-radius:50%;background:#d1e9ff}.lgt-safe strong{display:block;color:#1849a9}.lgt-safe p{margin:.15rem 0 0;font-size:.85rem;line-height:1.55}
.lgt-guide{display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem}.lgt-guide>div{border:1px solid var(--lgt-line);border-radius:.75rem;padding:.9rem}.lgt-guide em{font-style:normal;font-size:.76rem;color:#175cd3;font-weight:700}.lgt-guide h3{font-size:.95rem;margin:.5rem 0 .25rem}.lgt-guide p{margin:0;font-size:.82rem;line-height:1.5;color:var(--lgt-muted)}
.lgt-fields{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:1rem}.lgt-field{grid-column:span 6}.lgt-field.third{grid-column:span 4}.lgt-field.full{grid-column:span 12}.lgt-field label,.lgt-field legend{display:block;margin-bottom:.4rem;font-size:.87rem;font-weight:650}.lgt-required:after{content:" *";color:#b42318}.lgt-field .form-text{font-size:.78rem;line-height:1.45;color:var(--lgt-muted);margin:.35rem 0 0}.lgt-field input,.lgt-field select,.lgt-field textarea{min-height:2.65rem;border-color:#d0d5dd;border-radius:.55rem}.lgt-field textarea{min-height:7rem}.lgt-field input:focus,.lgt-field select:focus,.lgt-field textarea:focus,.lgt-step:focus-visible{outline:3px solid rgba(46,144,250,.3);outline-offset:2px;box-shadow:none}
.lgt-languages{display:grid;grid-template-columns:repeat(auto-fill,minmax(9rem,1fr));gap:.5rem}.lgt-check{display:flex;gap:.5rem;align-items:center;min-height:2.7rem;border:1px solid var(--lgt-line);border-radius:.6rem;padding:.5rem .6rem;cursor:pointer}.lgt-check:hover{border-color:#98b4ff;background:#f8faff}.lgt-check input,.lgt-country input,.lgt-scope input{accent-color:var(--lgt-primary)}.lgt-check small{display:block;color:var(--lgt-muted);font-size:.72rem}
.lgt-country-tools{display:flex;gap:.65rem;align-items:end;justify-content:space-between;flex-wrap:wrap}.lgt-country-search{flex:1 1 18rem;max-width:34rem}.lgt-country-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.5rem;max-height:22rem;overflow:auto;padding:.55rem;border:1px solid var(--lgt-line);border-radius:.75rem;background:#fafcff}.lgt-country{display:flex;gap:.55rem;align-items:flex-start;border:1px solid var(--lgt-line);border-radius:.58rem;padding:.65rem;background:#fff;cursor:pointer}.lgt-country strong,.lgt-country small{display:block}.lgt-country strong{font-size:.84rem}.lgt-country small{font-size:.73rem;color:var(--lgt-muted)}
.lgt-disclosure{border:1px solid var(--lgt-line);border-radius:.75rem;background:#fff}.lgt-disclosure summary{padding:.9rem 1rem;cursor:pointer;font-weight:650;font-size:.9rem}.lgt-disclosure[open] summary{border-bottom:1px solid var(--lgt-line)}.lgt-disclosure>div{padding:1rem}.lgt-scope-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:.55rem}.lgt-scope{display:flex;align-items:flex-start;gap:.5rem;border:1px solid var(--lgt-line);border-radius:.65rem;padding:.7rem;cursor:pointer}.lgt-scope strong,.lgt-scope small{display:block}.lgt-scope strong{font-size:.84rem}.lgt-scope small{font-size:.72rem;color:var(--lgt-muted);line-height:1.35}.lgt-action{display:flex;gap:.65rem;align-items:center;flex-wrap:wrap;margin-top:1rem}.lgt-auto-publish{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:1rem;padding:.9rem 1rem;border:1px solid #bfdbfe;border-radius:.7rem;background:#f8fbff}.lgt-auto-publish strong{display:block;color:#1e3a8a}@media(max-width:720px){.lgt-auto-publish{grid-template-columns:1fr;gap:.65rem}}
.lgt-table-wrap{overflow:auto;border:1px solid var(--lgt-line);border-radius:.75rem}.lgt-table{width:100%;min-width:45rem;border-collapse:collapse}.lgt-table th,.lgt-table td{padding:.72rem .78rem;text-align:left;vertical-align:top;border-bottom:1px solid var(--lgt-line);font-size:.84rem}.lgt-table th{background:#f8fafc;color:#475467;font-size:.76rem}.lgt-table tr:last-child td{border-bottom:0}.lgt-cell{max-width:23rem;white-space:pre-wrap;overflow-wrap:anywhere}.lgt-empty{text-align:center;color:var(--lgt-muted);padding:1.3rem!important}.lgt-state{display:inline-flex;border-radius:99px;padding:.2rem .45rem;background:#ecfdf3;color:#027a48;font-size:.74rem;font-weight:650}.lgt-state.neutral{background:#f2f4f7;color:#475467}.lgt-state.warning{background:#fffaeb;color:#b54708}.lgt-failure{display:block;margin-top:.3rem;color:#b42318;font-size:.73rem;line-height:1.35}.lgt-scan{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;margin-top:1rem}.lgt-scan>div{background:var(--lgt-bg);border-radius:.65rem;padding:.75rem}.lgt-scan b{display:block;font-size:1.2rem;color:var(--lgt-ink)}.lgt-scan span{font-size:.74rem;color:var(--lgt-muted)}
.lgt-live{display:inline-flex;gap:.35rem;align-items:center;color:var(--lgt-muted);font-size:.77rem}.lgt-toast{display:none;position:fixed;right:1.2rem;bottom:1.2rem;z-index:1100;max-width:min(34rem,calc(100vw - 2rem));padding:.85rem 1rem;border:1px solid #a6f4c5;border-radius:.7rem;background:#ecfdf3;color:#027a48;box-shadow:0 1rem 2rem rgba(16,24,40,.16)}.lgt-toast.show{display:block}.lgt-toast.error{border-color:#fecdca;background:#fef3f2;color:#b42318}
@media(max-width:1080px){.lgt-8,.lgt-7,.lgt-6,.lgt-5,.lgt-4{grid-column:span 12}.lgt-guide{grid-template-columns:1fr}}@media(max-width:700px){.lgt-field,.lgt-field.third{grid-column:span 12}.lgt-languages{grid-template-columns:repeat(2,1fr)}.lgt-country-list{grid-template-columns:1fr}.lgt-scan{grid-template-columns:repeat(2,1fr)}.lgt-hero-actions .btn{width:100%}}
.lgt-locale-health{display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:.7rem}.lgt-locale-tile{border:1px solid var(--lgt-line);padding:.9rem 1rem;border-radius:.8rem;background:#fff}.lgt-locale-tile h3{font-size:.97rem;margin:0 0 .5rem}.lgt-locale-tile p{font-size:.8rem;margin:.4rem 0;color:var(--lgt-muted)}.lgt-locale-tile .lgt-meters{display:flex;gap:.6rem;flex-wrap:wrap;font-size:.8rem}.lgt-mini-bar{height:.4rem;border-radius:.4rem;background:#e6eaf2;overflow:hidden;margin:.65rem 0}.lgt-mini-bar>span{display:block;height:100%;background:#3478f6}.lgt-review-controls{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:.75rem}.lgt-review-controls select,.lgt-review-controls input{max-width:15rem;min-width:8rem}.lgt-review-pages{display:flex;justify-content:space-between;gap:.75rem;align-items:center;margin-top:.75rem;flex-wrap:wrap}.lgt-protected-terms{display:flex;flex-wrap:wrap;gap:.5rem}.lgt-protected-terms>span{display:inline-flex;gap:.3rem;align-items:center;padding:.3rem .5rem;border:1px solid var(--lgt-line);border-radius:.5rem;background:#f8fafc}.lgt-protected-terms button{border:0;background:transparent;color:#b42318}
.lgt-auto-status{display:block;margin-top:.35rem;color:#175cd3;font-size:.75rem;line-height:1.35}.lgt-pause{display:block;margin-top:.35rem;color:#9a6700;font-size:.75rem;line-height:1.35}
.lgt-quota{min-width:13rem}.lgt-quota-head{display:flex;justify-content:space-between;gap:.5rem;font-size:.78rem}.lgt-quota-bar{height:.45rem;margin:.35rem 0;border-radius:99px;background:#e4e7ec;overflow:hidden}.lgt-quota-bar>span{display:block;height:100%;background:#2e90fa}.lgt-quota small{display:block;color:var(--lgt-muted);line-height:1.4}.lgt-danger{border-color:#fecdca;background:#fffbfa}.lgt-danger .lgt-card-h{border-bottom:1px solid #fee4e2}.lgt-danger h2{color:#b42318}
.lgt-workspace{max-width:1220px;--lgt-line:#e6e9ee;--lgt-primary:#2458d3}
.lgt-hero{border:0;border-bottom:1px solid var(--lgt-line);border-radius:0;padding:1rem .1rem 1.25rem;background:transparent}
.lgt-hero:after{display:none}.lgt-hero h1{font-size:1.65rem;margin:.35rem 0;letter-spacing:-.025em}.lgt-hero .lgt-mode{margin-top:.65rem;border:0;padding:.25rem 0;background:transparent;color:var(--lgt-muted)}
.lgt-hero-actions{margin-top:.85rem}.lgt-steps{display:flex;gap:.25rem;margin:1rem 0 1.2rem;border-bottom:1px solid var(--lgt-line);overflow-x:auto}
.lgt-step{min-width:auto;white-space:nowrap;border:0;border-bottom:3px solid transparent;border-radius:0;padding:.68rem 1rem;margin-bottom:-1px;font-weight:650;background:transparent}
.lgt-step[aria-current="step"]{border:0;border-bottom:3px solid var(--lgt-primary);border-radius:0;background:transparent;box-shadow:none;color:var(--lgt-primary)}
.lgt-step b,.lgt-step small{display:none}.lgt-step:hover{border-bottom-color:#aac2f2}
.lgt-card{border-radius:.8rem;box-shadow:none}.lgt-card-h{padding:1rem 1.1rem .45rem}.lgt-card-b{padding:.8rem 1.1rem 1.1rem}.lgt-card h2{font-size:1.02rem}.lgt-desc{font-size:.81rem;line-height:1.4}
.lgt-primary-action{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.lgt-primary-action .btn{min-width:10rem}
.lgt-quiet-tools{border:1px solid var(--lgt-line);border-radius:.8rem;background:#fff}.lgt-quiet-tools summary{padding:.85rem 1.1rem;font-weight:650;cursor:pointer}.lgt-quiet-tools[open] summary{border-bottom:1px solid var(--lgt-line)}
.lgt-quiet-tools>div{padding:1rem}.lgt-inline-muted{color:var(--lgt-muted);font-size:.8rem}
.lgt-account-list{order:-1}
.lgt-reset-locales{max-width:32rem;max-height:12.5rem;overflow-y:auto;padding:.45rem;border:1px solid var(--lgt-line);border-radius:.6rem;grid-template-columns:repeat(auto-fill,minmax(8.5rem,1fr))}.lgt-reset-locales .lgt-check{font-size:.82rem;background:#fff}
#lgt-panel-languages .lgt-field>.form-text,#lgt-provider-form .lgt-field>.form-text{display:none}
#lgt-panel-languages .lgt-field:focus-within>.form-text,#lgt-provider-form .lgt-field:focus-within>.form-text{display:block}
.lgt-step:focus-visible,.lgt-quiet-tools summary:focus-visible{outline:3px solid rgba(46,144,250,.35);outline-offset:-3px}
@media(max-width:700px){.lgt-hero h1{font-size:1.35rem}.lgt-step{padding:.65rem .75rem}.lgt-primary-action>.btn{width:100%}}
.lgt-workspace{--lgt-ink:#17233a;--lgt-text:#34445c;--lgt-muted:#718096;--lgt-line:#e5eaf2;--lgt-primary:#315fcd;max-width:1280px;font-family:inherit}
.lgt-workspace .btn{border-radius:.55rem;font-weight:600}.lgt-workspace .btn-primary{background:#315fcd;border-color:#315fcd}.lgt-workspace .btn-primary:hover{background:#234db1;border-color:#234db1}
.lgt-hero{padding:.55rem 0 1.15rem}.lgt-hero-main{display:flex;justify-content:space-between;align-items:center;gap:1.5rem;flex-wrap:wrap}.lgt-overline{display:block;font-size:.68rem;letter-spacing:.13em;font-weight:750;color:#5174a9;text-transform:uppercase}
.lgt-hero-main h1{display:flex;align-items:center;gap:.65rem;margin:.25rem 0 .4rem;font-size:clamp(1.45rem,2.8vw,2rem);font-weight:760;letter-spacing:-.035em}.lgt-release{font-size:.64rem;font-weight:700;letter-spacing:0;color:#6e7e94;background:#eef2f8;border-radius:99px;padding:.25rem .5rem}
.lgt-hero-main p{font-size:.87rem;color:var(--lgt-muted);margin:0}.lgt-hero-actions{margin:0}.lgt-topline{display:flex;flex-wrap:wrap;gap:.8rem;align-items:center;margin-top:.95rem;color:#66758b;font-size:.77rem}.lgt-topline>span+span:before{content:'·';margin-right:.8rem;color:#b2bdcb}.lgt-status-pill{display:inline-flex;align-items:center;gap:.38rem;font-weight:650;color:#4d6387}
.lgt-steps{gap:1.2rem;margin:1rem 0 1.25rem;padding:0 .1rem}.lgt-step{font-size:.9rem;padding:.7rem .2rem;color:#68768a;transition:color .15s ease,border-color .15s ease}.lgt-step[aria-selected="true"]{color:var(--lgt-primary);border-bottom-color:var(--lgt-primary)}.lgt-step:hover{color:var(--lgt-primary)}
.lgt-next-action{display:flex;align-items:center;gap:1rem;padding:1.2rem 1.35rem;background:linear-gradient(105deg,#eff5ff 0%,#f8fbff 62%,#fff 100%);border:1px solid #cadcf9;border-radius:.9rem}.lgt-next-icon{flex:0 0 2.6rem;height:2.6rem;border-radius:.72rem;display:grid;place-items:center;background:#dbe8ff;color:#2f59b8;font-size:1.55rem;font-weight:700}.lgt-next-action>div:nth-child(2){flex:1;min-width:10rem}.lgt-next-action h2{margin:.1rem 0 .15rem;font-size:1.08rem;font-weight:760}.lgt-next-action p{margin:0;color:#60728c;font-size:.82rem}.lgt-next-action .btn{white-space:nowrap}
.lgt-stat{min-height:6.9rem;padding:1.1rem 1.2rem}.lgt-stat-label{font-size:.78rem;font-weight:650}.lgt-stat-value{font-size:1.9rem;margin:.5rem 0 .25rem}.lgt-stat-help{font-size:.74rem}.lgt-3{grid-column:span 3}.lgt-card{border-radius:.85rem}.lgt-card-h{align-items:center}.lgt-locale-tile{border-radius:.7rem}
.lgt-subnav{display:flex;gap:.35rem;flex-wrap:wrap;margin:-.2rem 0 1.05rem;padding:.35rem;background:#f1f4f9;border:1px solid #e3e9f2;border-radius:.75rem;width:max-content;max-width:100%}.lgt-subnav-btn{border:0;border-radius:.55rem;padding:.62rem 1.05rem;background:transparent;color:#5d6b7e;font-size:.86rem;font-weight:650;cursor:pointer}.lgt-subnav-btn[aria-pressed="true"]{background:#fff;color:#2559bc;box-shadow:0 1px 5px rgba(24,45,81,.12)}.lgt-subnav-btn:focus-visible,.lgt-step:focus-visible{outline:3px solid #9ac2ff;outline-offset:2px}
[data-lgt-workview-content][hidden]{display:none!important}.lgt-review-controls{gap:.6rem}.lgt-review-controls select,.lgt-review-controls input{max-width:unset}.lgt-review-controls input[type="search"]{flex:1;min-width:12rem}.lgt-table th{font-weight:700}.lgt-quiet-tools>summary,.lgt-disclosure>summary{list-style-position:inside}
@media(max-width:960px){.lgt-3{grid-column:span 6}}@media(max-width:700px){.lgt-hero-main{align-items:stretch}.lgt-hero-actions{width:100%;display:grid;grid-template-columns:1fr 1fr}.lgt-hero-actions .btn{width:100%}.lgt-next-action{flex-wrap:wrap;padding:1rem}.lgt-next-action .btn{width:100%}.lgt-3{grid-column:span 6}.lgt-steps{gap:.85rem}.lgt-subnav{width:100%}.lgt-subnav-btn{flex:1;padding:.7rem .35rem}.lgt-review-controls>*{flex:1 1 9rem}}
@media(prefers-reduced-motion:reduce){.lgt-step{transition:none}}
@media(min-width:1081px){#lgt-provider-details>.lgt-grid>.lgt-7{grid-column:span 9}#lgt-provider-details>.lgt-grid>.lgt-5{grid-column:span 3}}
</style>

<a href="#lgt-main" class="lgt-skip">跳到翻译工作台主内容</a>
<main id="lgt-main" class="lgt-workspace py-3" aria-labelledby="lgt-title">
  <header class="lgt-hero"><div class="lgt-hero-main"><div><span class="lgt-overline">LONGOS / TRANSLATION</span><h1 id="lgt-title">翻译工作台 <span class="lgt-release">v1.17.0</span></h1><p>管理翻译、审核和发布。每个子站独立配置。</p></div><div class="lgt-hero-actions"><button class="btn btn-primary" type="button" data-lgt-go="library" data-lgt-library-view="jobs">开始翻译</button><button class="btn btn-outline-secondary" type="button" data-lgt-go="publish">发布译文</button></div></div><div class="lgt-topline"><span class="lgt-status-pill"><span class="lgt-dot {{ $diagnostics['dispatch_enabled'] ? '' : 'warning' }}"></span>{{ $diagnostics['dispatch_enabled'] ? '可派发' : '未开启派发' }}</span><span>{{ $modeLabels[$settings['mode']] ?? $settings['mode'] }}</span><span>{{ count($enabledLocales) }} 种目标语言</span></div></header>

  <p id="lgt-readiness-summary" class="lgt-inline-muted my-2" role="status" aria-live="polite">{{ ! $library_health['ready_to_display'] ? '尚无可展示的已发布词典' : '已发布词典可用' }}</p>
  <div id="lgt-readiness-counts" class="visually-hidden">已翻译 {{ $library_health['translated'] }} · 已发布语言 {{ $library_health['published_languages'] }}</div>
  <nav class="lgt-steps" role="tablist" aria-label="翻译工作台导航">
    <button type="button" role="tab" class="lgt-step" id="lgt-tab-overview" aria-controls="lgt-panel-overview" data-lgt-go="overview" aria-selected="true">概览</button>
    <button type="button" role="tab" class="lgt-step" id="lgt-tab-library" aria-controls="lgt-panel-library" data-lgt-go="library" aria-selected="false" tabindex="-1">翻译与译文</button>
    <button type="button" role="tab" class="lgt-step" id="lgt-tab-publish" aria-controls="lgt-panel-publish" data-lgt-go="publish" aria-selected="false" tabindex="-1">发布</button>
    <button type="button" role="tab" class="lgt-step" id="lgt-tab-languages" aria-controls="lgt-panel-languages" data-lgt-go="languages" aria-selected="false" tabindex="-1">语言与地区</button>
    <button type="button" role="tab" class="lgt-step" id="lgt-tab-providers" aria-controls="lgt-panel-providers" data-lgt-go="providers" aria-selected="false" tabindex="-1">服务商</button>
  </nav>

  <section class="lgt-panel" id="lgt-panel-overview" role="tabpanel" aria-labelledby="lgt-tab-overview" data-lgt-panel="overview">
    <div class="lgt-grid">
      <section class="lgt-next-action lgt-12" aria-labelledby="lgt-next-title"><div class="lgt-next-icon" aria-hidden="true">→</div><div><span class="lgt-overline">建议下一步</span><h2 id="lgt-next-title">{{ $nextActionTitle }}</h2><p id="lgt-next-detail">{{ $publishedLocales > 0 ? '已发布语言可在前台使用；继续维护新译文。' : '完成必要配置后即可开始翻译并发布。' }}</p></div><button id="lgt-next-button" type="button" class="btn btn-primary" data-lgt-go="{{ $nextPanel }}">{{ $nextActionLabel }}</button></section>
      <article class="lgt-card lgt-stat lgt-3"><div class="lgt-stat-label">启用语言</div><div id="lgt-enabled-count" class="lgt-stat-value">{{ count($enabledLocales) }}</div><div class="lgt-stat-help">目标语言数</div></article>
      <article class="lgt-card lgt-stat lgt-3"><div class="lgt-stat-label">已验证服务商</div><div id="lgt-verified-count" class="lgt-stat-value">{{ $verifiedAccounts }}</div><div class="lgt-stat-help">密钥已验证</div></article>
      <article class="lgt-card lgt-stat lgt-3"><div class="lgt-stat-label">已发布语言</div><div id="lgt-published-count" class="lgt-stat-value">{{ $publishedLocales }}</div><div class="lgt-stat-help">前台可调用</div></article>
      <article class="lgt-card lgt-stat lgt-3"><div class="lgt-stat-label">进行中任务</div><div id="lgt-active-count" class="lgt-stat-value">{{ $activeJobs }}</div><div class="lgt-stat-help">状态自动刷新</div></article>
      <section class="lgt-card lgt-12"><div class="lgt-card-h"><div><h2 id="lgt-coverage-title">语言进度</h2></div><button type="button" class="btn btn-outline-secondary btn-sm" data-lgt-go="library" data-lgt-library-view="translations">管理译文</button></div><div class="lgt-card-b"><div id="lgt-locale-health" class="lgt-locale-health" role="status" aria-live="polite">读取中…</div></div></section>
      <details class="lgt-quiet-tools lgt-12" id="lgt-diagnostics"><summary>诊断与运行状态 <span id="lgt-issue-count" class="lgt-inline-muted ms-2">检查中…</span></summary><div><div class="d-flex justify-content-between align-items-center gap-2"><span id="lgt-selfcheck-summary" class="small" role="status">正在检查…</span><button type="button" id="lgt-selfcheck-refresh" class="btn btn-outline-secondary btn-sm">重新检查</button></div><div id="lgt-selfcheck-issues" class="small mt-2" aria-live="polite"></div></div></details>
    </div>
  </section>

  <section id="lgt-panel-languages" class="lgt-panel" role="tabpanel" aria-labelledby="lgt-tab-languages" data-lgt-panel="languages" hidden>
    <div class="lgt-grid">
      <section class="lgt-card lgt-12"><div class="lgt-card-h"><div><h2>展示语言与自动匹配</h2><p class="lgt-desc">先定义可展示语言，再关联国家／地区。首次访问会直接自动应用已发布的本地词典；访客手动选择始终优先。</p></div><span class="badge text-bg-light border">英语（en）为锁定源语言</span></div><div class="lgt-card-b"><form id="lgt-settings-form" novalidate><div class="lgt-fields"><div class="lgt-field third"><label for="lgt-mode">展示模式</label><select id="lgt-mode" class="form-select"><option value="preview_only" @selected(($settings['mode'] ?? '') === 'preview_only')>仅预览，不调用 API</option><option value="free_only" @selected(($settings['mode'] ?? '') === 'free_only')>仅使用免费额度</option><option value="offline_dictionary" @selected(($settings['mode'] ?? '') === 'offline_dictionary')>仅使用已发布词典</option></select><p class="form-text">正式上线推荐“仅已发布词典”。</p></div><div class="lgt-field third"><label for="lgt-source">原始语言</label><input id="lgt-source" class="form-control" value="{{ $settings['source_locale'] ?? 'en' }}" readonly><p class="form-text">锁定以保护源内容与多租户一致性。</p></div><div class="lgt-field third"><label>自动匹配顺序</label><div class="form-control bg-light d-flex align-items-center">手动选择 → 可信 IP 国家／地区 → 浏览器语言</div><p class="form-text">仅使用经过反向代理验证的地区代码或本地 GeoIP，不读取客户资料。</p></div><div class="lgt-field full"><fieldset><legend>启用的展示语言</legend><input id="lgt-locales" type="hidden" value="{{ implode(',', $enabledLocales) }}"><div class="lgt-languages">@foreach($localeCatalog as $code => $label)<label class="lgt-check"><input name="enabled_locale" type="checkbox" value="{{ $code }}" @checked(in_array($code, $enabledLocales, true))><span>{{ $label }}<small>{{ $code }}</small></span></label>@endforeach</div><p class="form-text">这些语言会同步给任务、发布版本和前台选择器；没有启用的语言不能进入任务。</p></fieldset></div><div class="lgt-field full"><div class="form-check"><input id="lgt-geo-auto" type="checkbox" class="form-check-input" @checked($settings['geo_auto_switch_enabled'] ?? true)><label class="form-check-label" for="lgt-geo-auto">根据访问者 IP 自动应用已发布的地区语言</label><p class="form-text mb-0">首次访问无需确认；只有目标语言已经发布词典时才会切换，手动选择会保存为优先设置。</p></div></div><div class="lgt-field full"><div class="form-check"><input id="lgt-accept-language" type="checkbox" class="form-check-input" @checked($settings['accept_language_enabled'] ?? false)><label class="form-check-label" for="lgt-accept-language">没有可信地区规则时，允许以浏览器语言作为回退</label></div></div><div class="lgt-field full"><div class="lgt-auto-publish"><div><strong>翻译完成后自动上线</strong><p class="form-text mb-0">任务结束后，系统只会自动批准通过基础安全检查的机器译文并创建不可变词典版本；失败、质量告警、人工编辑和已锁定译文会保留在审核队列，不会被自动上架。</p></div><div class="form-check"><input id="lgt-auto-publish-safe" type="checkbox" class="form-check-input" @checked(($settings['publication']['auto_publish_safe'] ?? true))><label class="form-check-label" for="lgt-auto-publish-safe">自动发布安全译文</label></div><div class="form-check"><input id="lgt-auto-enable-widget" type="checkbox" class="form-check-input" @checked(($settings['publication']['auto_enable_widget'] ?? true))><label class="form-check-label" for="lgt-auto-enable-widget">发布后自动开启前台语言选择器</label></div></div></div></div><div class="lgt-action"><button class="btn btn-primary" type="submit">保存语言策略</button><span class="small text-secondary">保存不会自动翻译或改写任何内容。</span></div></form></div></section>
      <details class="lgt-quiet-tools lgt-12" id="lgt-country-options"><summary>高级：国家／地区自动匹配规则</summary><div class="lgt-grid">
      <section class="lgt-card lgt-8"><div class="lgt-card-h"><div><h2>按系统国家／地区快捷启用语言</h2><p class="lgt-desc">从已启用国家中添加语言规则。</p></div></div><div class="lgt-card-b"><div class="lgt-country-tools"><div class="lgt-country-search"><label class="visually-hidden" for="lgt-country-search">搜索国家或地区</label><input id="lgt-country-search" class="form-control" type="search" placeholder="搜索国家或 ISO 代码"></div><div class="small text-secondary"><span id="lgt-country-summary">正在准备…</span> <button id="lgt-country-clear" class="btn btn-link btn-sm p-0 ms-2" type="button">清空</button></div></div><div id="lgt-country-list" class="lgt-country-list mt-3" aria-live="polite"></div><p id="lgt-country-empty" class="form-text" hidden>没有匹配的已启用国家／地区。</p><div class="lgt-action"><button id="lgt-country-apply" type="button" class="btn btn-outline-primary" disabled>应用所选国家的语言建议</button></div></div></section>
      <section class="lgt-4"><details class="lgt-disclosure"><summary>高级：单个国家语言覆盖</summary><div><p class="lgt-desc mb-3">用于覆盖系统建议。目标语言必须先在上方启用。</p><form id="lgt-country-rule-form" novalidate><div class="mb-3"><label class="form-label" for="lgt-country">国家／地区代码</label><input id="lgt-country" class="form-control text-uppercase" maxlength="2" placeholder="FR" required></div><div class="mb-3"><label class="form-label" for="lgt-country-locale">展示语言代码</label><input id="lgt-country-locale" class="form-control" maxlength="24" placeholder="fr" required></div><div class="form-check mb-3"><input id="lgt-country-enabled" class="form-check-input" type="checkbox" checked><label class="form-check-label" for="lgt-country-enabled">启用默认规则</label></div><button class="btn btn-outline-primary w-100" type="submit">保存覆盖</button></form></div></details></section>
      <section class="lgt-card lgt-12"><div class="lgt-card-h"><div><h2>当前国家默认规则</h2></div></div><div class="lgt-table-wrap"><table class="lgt-table"><thead><tr><th>国家／地区</th><th>展示语言</th><th>状态</th></tr></thead><tbody id="lgt-country-rules">@forelse($countryRules as $rule)<tr><td>{{ $rule->country }}</td><td>{{ $rule->locale }}</td><td>{{ $rule->enabled ? '已启用' : '已停用' }}</td></tr>@empty<tr><td class="lgt-empty" colspan="3">尚未设置国家默认语言。</td></tr>@endforelse</tbody></table></div></section>
      </div></details>
    </div>
  </section>

  <section class="lgt-panel" id="lgt-panel-providers" role="tabpanel" aria-labelledby="lgt-tab-providers" data-lgt-panel="providers" hidden>
    <div class="lgt-grid">
      <details class="lgt-quiet-tools lgt-12" id="lgt-provider-details" @if(count($accounts) === 0) open @endif><summary>添加或编辑 API 账户</summary><div class="lgt-grid">
      <section class="lgt-card lgt-7"><div class="lgt-card-h"><div><h2 id="lgt-provider-form-title">添加翻译 API 服务商</h2><p id="lgt-provider-form-description" class="lgt-desc">不同平台使用各自的凭据格式。凭据仅在保存时提交并立即加密，页面、日志和状态接口均不会回传密钥。</p></div></div><div class="lgt-card-b"><form id="lgt-provider-form" novalidate><div id="lgt-provider-edit-note" class="lgt-safe mb-3" hidden><b>i</b><div><strong>正在调整已有账户的额度与策略</strong><p class="mb-0">账户标识和旧密钥不会回显。留空 API 字段即可仅保存额度、限速、优先级等设置；填写完整的新密钥会要求重新验证。</p></div></div><div class="lgt-fields"><div class="lgt-field"><label class="lgt-required" for="lgt-provider">翻译服务商</label><select id="lgt-provider" class="form-select">@foreach($providerProfiles as $code => $profile)<option value="{{ $code }}">{{ $profile['label'] }}</option>@endforeach</select><p id="lgt-quota-help" class="form-text"></p></div><div class="lgt-field"><label class="lgt-required" for="lgt-billing">账户标识</label><input id="lgt-billing" class="form-control" maxlength="255" placeholder="例如：生产环境 RAM 用户" autocomplete="off" required><p id="lgt-billing-help" class="form-text"></p><p id="lgt-billing-lock-note" class="form-text" hidden>账户标识仅以不可逆摘要保留，编辑时不能查看或更改。</p></div><div class="lgt-field"><label id="lgt-key-one-label" class="lgt-required" for="lgt-key-one"></label><input id="lgt-key-one" class="form-control" autocomplete="off" required><p id="lgt-key-one-help" class="form-text"></p></div><div class="lgt-field"><label id="lgt-key-two-label" class="lgt-required" for="lgt-key-two"></label><input id="lgt-key-two" class="form-control" type="password" autocomplete="new-password" required><p id="lgt-key-two-help" class="form-text"></p></div><div class="lgt-field third"><label for="lgt-free-quota">本周期免费字符额度</label><input id="lgt-free-quota" class="form-control" type="number" min="0" value="0"><p class="form-text">填 0 表示不能派发。</p></div><div class="lgt-field third"><label for="lgt-soft-cap">本地安全上限</label><input id="lgt-soft-cap" class="form-control" type="number" min="0" value="0"><p class="form-text">建议不超过免费额度。</p></div><div class="lgt-field third"><label for="lgt-qps">每秒请求上限</label><input id="lgt-qps" class="form-control" type="number" min="1" max="10" value="1"><p class="form-text">建议从 1 开始。</p></div><div class="lgt-field full"><details class="lgt-disclosure"><summary>高级额度与故障转移设置</summary><div><div class="lgt-fields"><div class="lgt-field third"><label for="lgt-priority">故障转移优先级</label><input id="lgt-priority" class="form-control" type="number" min="1" max="1000" value="100"><p class="form-text">数字越小越优先；额度耗尽时自动尝试下一个已验证账户。</p></div><div class="lgt-field third"><label for="lgt-buffer">外部用量缓冲</label><input id="lgt-buffer" class="form-control" type="number" min="0" value="0"></div><div class="lgt-field third"><label for="lgt-timezone">额度周期时区</label><select id="lgt-timezone" class="form-select"><option value="UTC">UTC</option><option value="Asia/Shanghai">Asia/Shanghai</option><option value="America/Los_Angeles">America/Los_Angeles</option></select></div><div class="lgt-field"><label for="lgt-tier">套餐备注</label><input id="lgt-tier" class="form-control" maxlength="48" placeholder="例如：免费版"></div><div class="lgt-field"><label for="lgt-console-link">服务商控制台链接（可选）</label><input id="lgt-console-link" class="form-control" type="url" maxlength="512" placeholder="https://…"></div></div></div></details></div><div class="lgt-field full"><div class="form-check"><input id="lgt-provider-enabled" class="form-check-input" type="checkbox"><label id="lgt-provider-enabled-label" class="form-check-label" for="lgt-provider-enabled">保存为可验证账户（派发仍保持关闭）</label></div></div></div><div class="lgt-action"><button id="lgt-provider-submit" type="submit" class="btn btn-primary">加密保存并进入验证</button><button id="lgt-provider-cancel" type="button" class="btn btn-outline-secondary" hidden>取消编辑</button><span id="lgt-provider-form-help" class="small text-secondary">不会自动扣费、验证或派发。</span></div></form></div></section>
      <details class="lgt-quiet-tools lgt-5" id="lgt-provider-safety"><summary>费用与派发规则</summary><div><p class="lgt-desc">保存密钥后，先验证账户再开启派发。系统按本地额度和优先级尝试服务商，结果不确定时会安全暂停。</p><p class="lgt-desc mt-2">本地限制不能替代服务商实际账单；验证请求也可能消耗额度。</p></div></details>
      </div></details>
      <section class="lgt-card lgt-12 lgt-account-list"><div class="lgt-card-h"><div><h2>API 账户</h2></div><button id="lgt-provider-new" class="btn btn-primary btn-sm" type="button">添加账户</button></div><div class="lgt-table-wrap"><table class="lgt-table"><thead><tr><th>优先级</th><th>服务商与账户</th><th>本周期本地安全额度</th><th>凭据</th><th>验证</th><th>派发</th><th>操作</th></tr></thead><tbody>@forelse($accounts as $account)
        @php
          $usage = (array) ($account['local_usage'] ?? []);
        @endphp
      <tr><td>{{ $account['failover_priority'] ?? 100 }}</td><td><strong>{{ $providerLabels[$account['provider']] ?? $account['provider'] }}</strong><small class="d-block text-secondary">{{ $account['account_tier'] ?: '未命名账户' }} · #{{ $account['id'] }}</small></td><td><div class="lgt-quota"><div class="lgt-quota-head"><strong>剩余 {{ number_format((int)($usage['remaining']??0)) }}</strong><span>{{ (int)($usage['used_percent']??0) }}%</span></div><div class="lgt-quota-bar"><span style="width:{{ (int)($usage['used_percent']??0) }}%"></span></div><small>{{ $usage['billing_cycle']??'当前周期' }} · 已提交 {{ number_format((int)($usage['committed']??0)) }} · 处理中 {{ number_format((int)(($usage['reserved']??0)+($usage['sending']??0))) }} · 不确定 {{ number_format((int)($usage['uncertain']??0)) }}</small><small>本地上限 {{ number_format((int)($usage['local_limit']??0)) }} · {{ $account['qps_limit']??1 }} QPS</small></div></td><td>{{ $account['credentials_configured'] ? '已加密配置' : '未配置' }}</td><td>@if($account['verified_at'])<span class="lgt-state">已验证</span>@else<span class="lgt-state warning">待验证</span>@endif</td><td>@if($account['dispatch_enabled'])<span class="lgt-state">已开启</span>@else<span class="lgt-state neutral">已关闭</span>@endif</td><td><div class="d-flex flex-wrap gap-1"><button type="button" class="btn btn-sm btn-outline-secondary lgt-provider-edit" data-id="{{ $account['id'] }}">编辑</button>@if(!$account['enabled'])<span class="small text-secondary align-self-center">已停用</span>@elseif(!$account['verified_at'])<button type="button" class="btn btn-sm btn-outline-primary lgt-verify" data-id="{{ $account['id'] }}">验证账户</button>@else<button type="button" class="btn btn-sm btn-outline-secondary lgt-dispatch" data-id="{{ $account['id'] }}" data-enable="{{ $account['dispatch_enabled'] ? '0' : '1' }}">{{ $account['dispatch_enabled'] ? '关闭派发' : '开启派发' }}</button>@endif<button type="button" class="btn btn-sm btn-outline-danger lgt-provider-delete" data-id="{{ $account['id'] }}">删除</button></div></td></tr>@empty<tr><td class="lgt-empty" colspan="7">尚未配置服务商账户。</td></tr>@endforelse</tbody></table></div></section>
    </div>
  </section>

  <section class="lgt-panel" id="lgt-panel-library" role="tabpanel" aria-labelledby="lgt-tab-library" data-lgt-panel="library" hidden>
    <div class="lgt-subnav" role="group" aria-label="翻译与译文管理"><button id="lgt-worktab-jobs" class="lgt-subnav-btn" type="button" data-lgt-workview="jobs" aria-pressed="true">任务进度</button><button id="lgt-worktab-translations" class="lgt-subnav-btn" type="button" data-lgt-workview="translations" aria-pressed="false">翻译库与审核</button></div>
    <div class="lgt-grid">
      <section class="lgt-card lgt-12"><div class="lgt-card-b lgt-primary-action"><div><h2>开始翻译</h2><p class="lgt-desc">扫描公开内容，跳过已有有效译文。</p><p class="lgt-inline-muted mb-0">{{ $diagnostics['dispatch_enabled'] ? 'API 已授权，可以创建翻译任务。' : 'API 派发尚未开启，请先配置服务商。' }}</p></div><div class="d-flex flex-wrap gap-2 align-items-center"><button id="lgt-quick-plan" type="button" class="btn btn-primary">扫描并开始</button><button type="button" class="btn btn-outline-secondary" data-lgt-go="languages">选择语言</button></div></div></section>
      <section class="lgt-card lgt-12"><div class="lgt-card-h"><div><h2>翻译任务</h2><p class="lgt-desc">自动刷新进度；可停止、重试或归档任务。</p></div><span class="lgt-live"><i class="lgt-dot"></i><span id="lgt-live">读取状态…</span></span></div><div class="lgt-table-wrap"><table class="lgt-table"><thead><tr><th>任务</th><th>状态</th><th>模式</th><th>进度</th><th>更新时间</th><th>操作</th></tr></thead><tbody id="lgt-jobs"><tr><td class="lgt-empty" colspan="6">正在加载任务…</td></tr></tbody></table></div></section>
      <details class="lgt-quiet-tools lgt-12" id="lgt-custom-workflow"><summary>自定义扫描和任务范围</summary><div class="lgt-grid">
      <section class="lgt-card lgt-12"><div class="lgt-card-h"><div><h2>扫描可公开翻译内容</h2><p class="lgt-desc">只扫描显式白名单字段。全前台固定界面文案默认已选中：涵盖导航、搜索、商品、购物车、结账、账户、订单、售后、支付和分页的默认主题静态文本；绝不包含客户输入、地址、订单号、金额、商品 SKU 或支付资料。</p></div></div><div class="lgt-card-b"><form id="lgt-scan-form"><div class="lgt-scope-grid">@foreach(['product'=>['商品','已启用商品名称'],'category'=>['分类','公开分类名称'],'page'=>['页面','公开页面标题'],'builder'=>['装修模块','已发布受支持的纯文本字段'],'commerce_ui'=>['全前台固定界面文案','默认主题全部安全静态标签，不含动态客户或交易数据']] as $key=>[$label,$help])<label class="lgt-scope"><input type="checkbox" name="scope" value="{{ $key }}" checked><span><strong>{{ $label }}</strong><small>{{ $help }}</small></span></label>@endforeach</div><div class="lgt-action"><button class="btn btn-outline-primary" type="submit">扫描并更新索引</button><span class="small text-secondary">扫描本身不调用外部翻译服务。</span></div></form><div id="lgt-scan-result" class="lgt-scan" hidden aria-live="polite"></div><p id="lgt-scan-note" class="form-text" hidden></p></div></section>
      <section class="lgt-card lgt-6"><div class="lgt-card-h"><div><h2>创建翻译任务</h2><p class="lgt-desc">任务只处理已启用的目标语言。默认包含全前台固定界面文案；不能安全派发时会暂停，不会产生超额付费。</p></div></div><div class="lgt-card-b"><form id="lgt-plan-form"><div class="lgt-field full"><label for="lgt-plan-locales" class="lgt-required">本次目标语言</label><select id="lgt-plan-locales" class="form-select" multiple size="6">@foreach($localeCatalog as $code=>$label)<option value="{{ $code }}" @selected(in_array($code, $enabledLocales, true)) @disabled(!in_array($code, $enabledLocales, true))>{{ $label }}（{{ $code }}）</option>@endforeach</select><p class="form-text">已自动选中启用的语言；也可以手动取消不需要的语言。</p></div><fieldset class="mt-3"><legend class="form-label">任务范围</legend><div class="lgt-scope-grid">@foreach(['product'=>'商品','category'=>'分类','page'=>'页面','builder'=>'装修模块','commerce_ui'=>'全前台固定界面文案'] as $key=>$label)<label class="lgt-scope"><input type="checkbox" name="plan_scope" value="{{ $key }}" checked><span><strong>{{ $label }}</strong></span></label>@endforeach</div></fieldset><div class="lgt-action"><button class="btn btn-primary" type="submit">创建安全翻译任务</button></div></form></div></section>
      </div></details>
      <section class="lgt-card lgt-6"><div class="lgt-card-h"><div><h2>查找并审核翻译库</h2><p class="lgt-desc">每页 25 条，可筛选语言、状态、内容类型与关键词。选定一种语言后还能查看尚未翻译的扫描原文。人工修改使用版本锁。</p></div></div><div class="lgt-card-b pt-0">
        <form id="lgt-library-filter" class="lgt-review-controls" aria-label="筛选翻译条目"><select id="lgt-library-locale" class="form-select form-select-sm" aria-label="翻译语言"><option value="">全部语言</option>@foreach($localeCatalog as $code=>$label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select><select id="lgt-library-status" class="form-select form-select-sm" aria-label="审核状态"><option value="">全部状态</option><option value="translated">待审核（正常）</option><option value="needs_review">质量异常（需人工处理）</option><option value="approved">已审核</option><option value="stale">原文已变更</option><option value="pending">待处理</option></select><select id="lgt-library-kind" class="form-select form-select-sm" aria-label="内容类型"><option value="">所有内容</option><option value="product">商品</option><option value="category">分类</option><option value="page">页面</option><option value="builder">装修模块</option><option value="commerce_ui">固定界面</option></select><input id="lgt-library-query" class="form-control form-control-sm" maxlength="100" type="search" placeholder="搜索原文或译文" aria-label="关键词"><button id="lgt-library-load" class="btn btn-outline-primary btn-sm" type="submit">查询</button></form>
        <div class="d-flex flex-wrap gap-2 align-items-center my-2"><label class="small text-secondary d-flex align-items-center gap-1"><input type="checkbox" id="lgt-library-checkall"> 选择本页</label><span id="lgt-library-selected-count" class="small text-secondary">已选择 0 条</span><button id="lgt-library-delete-selected" type="button" class="btn btn-outline-danger btn-sm" disabled>批量删除选中译文</button></div>
        <div class="lgt-table-wrap"><table class="lgt-table"><thead><tr><th>选择</th><th>内容</th><th>语言</th><th>状态</th><th>译文</th><th>操作</th></tr></thead><tbody id="lgt-library-items"><tr><td class="lgt-empty" colspan="6">使用上方筛选器加载审核条目。</td></tr></tbody></table></div>
        <div class="lgt-review-pages"><small id="lgt-library-pageinfo" class="text-secondary">尚未加载</small><div class="d-flex gap-2"><button id="lgt-library-prev" type="button" class="btn btn-outline-secondary btn-sm" disabled>上一页</button><button id="lgt-library-next" type="button" class="btn btn-outline-secondary btn-sm" disabled>下一页</button></div></div>
      </div></section>
      <section class="lgt-12"><details id="lgt-editor" class="lgt-disclosure"><summary>人工修订</summary><div><p class="lgt-desc mb-3">先从翻译库点击“修订”载入条目。锁定后的人工译文不会被机器结果覆盖。</p><form id="lgt-manual-form"><div class="lgt-fields"><div class="lgt-field third"><label for="lgt-manual-id">翻译条目 ID</label><input id="lgt-manual-id" class="form-control" readonly required></div><div class="lgt-field third"><label for="lgt-manual-version">当前版本</label><input id="lgt-manual-version" class="form-control" readonly required></div><div class="lgt-field third"><label>&nbsp;</label><div class="form-check pt-2"><input id="lgt-manual-lock" class="form-check-input" type="checkbox"><label class="form-check-label" for="lgt-manual-lock">锁定人工译文</label></div></div><div class="lgt-field full"><label for="lgt-manual-text" class="lgt-required">前台展示译文</label><textarea id="lgt-manual-text" class="form-control" maxlength="100000" required></textarea><p class="form-text">只保存纯文本。品牌名称、SKU 等不需要翻译的内容请保留原样。</p></div></div><div class="lgt-action"><button class="btn btn-primary" type="submit">保存人工修订</button></div></form></div></details></section>
      <section class="lgt-12"><details class="lgt-disclosure"><summary>高级 · 品牌名称 / SKU 保护词（新增）</summary><div><p class="lgt-desc mb-2">翻译时原样保留品牌、系列名和固定型号，例如 NYBGBAR、Shopvella、iPhone 16 Pro。仅对之后新生成的译文生效；已有译文需要人工修订，不会自动重复调用 API。</p>
        <form id="lgt-glossary-form" class="d-flex flex-wrap gap-2 mb-3"><label for="lgt-glossary-term" class="visually-hidden">需保留的品牌或型号</label><input id="lgt-glossary-term" class="form-control" style="max-width:300px" minlength="2" maxlength="120" placeholder="输入需要原样保留的品牌或型号" required><button class="btn btn-outline-primary" type="submit">添加保护词</button></form>
        <div id="lgt-glossary-terms" class="lgt-protected-terms">@forelse($glossaryTerms as $term)<span data-glossary-id="{{ $term->id }}"><span>{{ $term->term }}</span><button type="button" class="lgt-glossary-delete" data-id="{{ $term->id }}" aria-label="删除保护词 {{ $term->term }}" title="删除保护词">×</button></span>@empty<span id="lgt-glossary-empty" class="text-secondary">尚未添加保护词</span>@endforelse</div>
      </div></details></section>
      <details class="lgt-quiet-tools lgt-12" id="lgt-library-maintenance"><summary>数据清理与整库重置</summary><div>
      <section class="lgt-card lgt-12 lgt-danger"><div class="lgt-card-h"><div><h2>翻译库维护</h2><p class="lgt-desc">删除会影响前台已发布内容，操作前请备份。请先停止对应任务。</p></div></div><div class="lgt-card-b"><form id="lgt-library-reset-form" class="d-flex flex-wrap align-items-end gap-2"><div><label for="lgt-library-reset-locale" class="form-label">要重置的目标语言</label><select id="lgt-library-reset-locale" class="form-select" required><option value="">请选择</option>
        @foreach($localeCatalog as $code => $label)
          @if(strtolower($code) !== strtolower($settings['source_locale'] ?? 'en'))
            <option value="{{ $code }}">{{ $label }}（{{ $code }}）</option>
          @endif
        @endforeach
      </select></div><div><label for="lgt-library-reset-confirm" class="form-label">输入 RESET + 语言代码确认</label><input id="lgt-library-reset-confirm" class="form-control" placeholder="例如 RESET FR" maxlength="40" required></div><button class="btn btn-outline-danger" type="submit">重置该语言翻译库</button></form>
      <hr><form id="lgt-library-bulk-reset-form" class="d-flex flex-wrap align-items-end gap-2"><div><span class="form-label d-block" id="lgt-library-bulk-locales-label">勾选要清空的语言</span><div id="lgt-library-bulk-locales" class="lgt-languages lgt-reset-locales" role="group" aria-labelledby="lgt-library-bulk-locales-label">@foreach($localeCatalog as $code=>$label) @if(strtolower($code) !== strtolower($settings['source_locale'] ?? 'en'))<label class="lgt-check"><input type="checkbox" value="{{ $code }}"><span>{{ $label }} <small>{{ $code }}</small></span></label>@endif @endforeach</div></div><div><label for="lgt-library-bulk-confirm" class="form-label">确认删除</label><input id="lgt-library-bulk-confirm" class="form-control" maxlength="200" placeholder="勾选后显示确认文字" required><small id="lgt-library-bulk-hint" class="text-secondary">请先勾选语言</small></div><button class="btn btn-danger" type="submit">清空选中语言</button></form><p class="form-text mt-2 mb-0">源语言不会显示。运行中或暂停的任务会阻止删除；计费和审计记录始终保留。</p></div></section>
      </div></details>
    </div>
  </section>

  <section class="lgt-panel" id="lgt-panel-publish" role="tabpanel" aria-labelledby="lgt-tab-publish" data-lgt-panel="publish" hidden>
    <div class="lgt-grid">
      <section class="lgt-card lgt-12"><div class="lgt-card-b"><div class="lgt-primary-action"><div><h2>发布译文</h2><p class="lgt-desc">仅自动审核格式检查合格的机器译文，其他内容留待人工审核。</p></div><div class="d-flex gap-2 flex-wrap"><select id="lgt-quick-locale" class="form-select" style="max-width:210px" aria-label="要发布的语言"><option value="">选择语言</option>@foreach($localeCatalog as $code=>$label)<option value="{{ $code }}" @disabled(!in_array($code, $enabledLocales, true))>{{ $label }}（{{ $code }}）</option>@endforeach</select><button type="button" id="lgt-quick-publish" class="btn btn-primary">审核并发布</button></div></div><label class="form-check mt-2"><input class="form-check-input" id="lgt-quick-widget" type="checkbox" checked><span class="form-check-label">同时开启语言选择器</span></label><p class="lgt-inline-muted mt-2 mb-0" id="lgt-quick-publish-note">发布前建议抽查译文准确性。</p></div></section>
      <details class="lgt-quiet-tools lgt-12" id="lgt-publish-options"><summary>发布选项与前台显示设置</summary><div class="lgt-grid">
      <section class="lgt-card lgt-5"><div class="lgt-card-h"><div><h2>不可变发布版本</h2><p class="lgt-desc">发布只会打包该语言已审核的本地译文。结账、账户和支付固定界面在访问时同样只读取此快照。</p></div></div><div class="lgt-card-b"><form id="lgt-publish-form"><label for="lgt-publish-locale" class="form-label">选择要发布的语言</label><select id="lgt-publish-locale" class="form-select" required><option value="">请选择语言</option>@foreach($localeCatalog as $code=>$label)<option value="{{ $code }}" @disabled(!in_array($code, $enabledLocales, true))>{{ $label }}（{{ $code }}）{{ in_array($code, $enabledLocales, true) ? '' : ' · 未启用' }}</option>@endforeach</select><div class="lgt-action"><button class="btn btn-primary" type="submit">发布新版本</button></div></form></div></section>
      <section class="lgt-card lgt-7"><div class="lgt-card-h"><div><h2>前台语言选择器</h2><p class="lgt-desc">悬浮按钮只负责手动切换；即使隐藏按钮，IP 地区自动本地化仍可继续运行。</p></div></div><div class="lgt-card-b"><form id="lgt-widget-form"><div class="lgt-fields"><div class="lgt-field third"><label>启用状态</label><div class="form-check pt-2"><input id="lgt-widget-enabled" class="form-check-input" type="checkbox" @checked($widget['enabled'] ?? false)><label class="form-check-label" for="lgt-widget-enabled">显示语言选择器</label></div></div><div class="lgt-field third"><label for="lgt-position">桌面端位置</label><select id="lgt-position" class="form-select"><option value="right" @selected(($widget['position'] ?? 'right') === 'right')>右下角</option><option value="left" @selected(($widget['position'] ?? '') === 'left')>左下角</option></select></div><div class="lgt-field third"><label for="lgt-theme">显示主题</label><select id="lgt-theme" class="form-select"><option value="system" @selected(($widget['theme'] ?? 'system') === 'system')>跟随系统</option><option value="light" @selected(($widget['theme'] ?? '') === 'light')>浅色</option><option value="dark" @selected(($widget['theme'] ?? '') === 'dark')>深色</option></select></div><div class="lgt-field"><label for="lgt-bottom">桌面端底部间距</label><input id="lgt-bottom" type="number" class="form-control" min="16" max="240" value="{{ $widget['bottom_offset'] ?? 84 }}"></div><div class="lgt-field"><label for="lgt-mobile-bottom">移动端底部间距</label><input id="lgt-mobile-bottom" type="number" class="form-control" min="16" max="260" value="{{ $widget['mobile_bottom_offset'] ?? 88 }}"><p class="form-text">避免与客服与底部导航重叠。</p></div></div><div class="lgt-action"><button class="btn btn-outline-primary" type="submit">保存前台选择器设置</button></div></form></div></section>
      </div></details>
      <section class="lgt-card lgt-12"><div class="lgt-card-h"><div><h2>已发布版本</h2><p class="lgt-desc">恢复历史版本会创建一个新版本，保留完整审计历史。</p></div></div><div class="lgt-table-wrap"><table class="lgt-table"><thead><tr><th>语言</th><th>版本</th><th>状态</th><th>发布时间</th><th>操作</th></tr></thead><tbody>@forelse($publications as $publication)<tr><td>{{ $publication->locale }}</td><td>{{ $publication->revision }}</td><td>{{ $publication->state === 'published' ? '当前版本' : '历史版本' }}</td><td>{{ $publication->published_at }}</td><td><button type="button" class="btn btn-sm btn-outline-secondary lgt-rollback" data-id="{{ $publication->id }}" data-locale="{{ $publication->locale }}">恢复为新版本</button></td></tr>@empty<tr><td class="lgt-empty" colspan="5">尚未发布任何语言版本。</td></tr>@endforelse</tbody></table></div></section>
    </div>
  </section>
  <div id="lgt-toast" class="lgt-toast" role="status" aria-live="polite" aria-atomic="true"></div>
</main>

<script>
(() => {
  'use strict';
  const routes = @json($lgtRoutes);
  const profiles = @json($providerProfiles), countries = @json($countries), configuredAccounts = @json($accounts), modeLabels = @json($modeLabels), jobStatusLabels = @json($jobStatusLabels), localeLabels = @json($localeCatalog);
  let dispatchReady = @json($dispatchReady);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const $ = (s,p=document) => p.querySelector(s), $$ = (s,p=document) => [...p.querySelectorAll(s)];
  const toast = $('#lgt-toast'); let toastTimer;
  const notify = (text,error=false) => { clearTimeout(toastTimer); toast.textContent=text; toast.classList.toggle('error',error); toast.classList.add('show'); toastTimer=setTimeout(()=>toast.classList.remove('show'),error?7000:4500); };
  const api = async (url,method='GET',body) => { const r=await fetch(url,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:body===undefined?undefined:JSON.stringify(body)}); const d=await r.json().catch(()=>({})); if(!r.ok) throw new Error(Object.values(d.errors||{}).flat().find(Boolean)||d.message||'请求未完成，请检查填写内容后重试。'); return d; };
  const busy = async (button,work) => { const label=button.textContent; button.disabled=true; button.textContent='处理中…'; try{return await work();}finally{button.disabled=false;button.textContent=label;} };

  // One task per view: jobs and translation editing never compete for the
  // same horizontal space, but their existing forms/endpoints stay intact.
  const workviews={
    jobs:[$('#lgt-quick-plan')?.closest('.lgt-card'),$('#lgt-jobs')?.closest('.lgt-card'),$('#lgt-custom-workflow')],
    translations:[$('#lgt-library-filter')?.closest('.lgt-card'),$('#lgt-editor')?.closest('section.lgt-12'),$('#lgt-glossary-form')?.closest('section.lgt-12'),$('#lgt-library-maintenance')],
  };
  const translationsTableCard=$('#lgt-library-filter')?.closest('.lgt-card');
  translationsTableCard?.classList.remove('lgt-6');translationsTableCard?.classList.add('lgt-12');
  for(const [name,items] of Object.entries(workviews))items.filter(Boolean).forEach(el=>el.dataset.lgtWorkviewContent=name);
  let currentWorkview='jobs';
  const selectWorkview=(view,updateUrl=true)=>{
    currentWorkview=view==='translations'?'translations':'jobs';
    $$('[data-lgt-workview-content]').forEach(el=>el.hidden=el.dataset.lgtWorkviewContent!==currentWorkview);
    $$('[data-lgt-workview]').forEach(b=>{const active=b.dataset.lgtWorkview===currentWorkview;b.setAttribute('aria-pressed',String(active));});
    if(updateUrl&&!$('#lgt-panel-library').hidden)history.replaceState(null,'',`#lgt-library-${currentWorkview}`);
    if(currentWorkview==='translations'&&!libraryLoaded)loadLibrary(1).catch(x=>notify(x.message,true));
  };
  const showPanel=(name,scroll=false,workview='jobs')=>{
    const target=$(`[data-lgt-panel="${name}"]`);if(!target)return;
    $$('[data-lgt-panel]').forEach(p=>p.hidden=p!==target);
    $$('.lgt-step').forEach(b=>{const active=b.dataset.lgtGo===name;b.setAttribute('aria-selected',String(active));b.tabIndex=active?0:-1;});
    if(name==='library')selectWorkview(workview,false);
    history.replaceState(null,'',name==='library'?`#lgt-library-${currentWorkview}`:`#lgt-${name}`);
    if(scroll)target.scrollIntoView({behavior:'smooth',block:'start'});
  };
  $$('[data-lgt-go]').forEach(b=>b.addEventListener('click',()=>showPanel(b.dataset.lgtGo,true,b.dataset.lgtLibraryView||'jobs')));
  $$('[data-lgt-workview]').forEach(b=>b.addEventListener('click',()=>selectWorkview(b.dataset.lgtWorkview)));
  $$('.lgt-step').forEach(tab=>tab.addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;const tabs=$$('.lgt-step');const index=tabs.indexOf(tab);const next=e.key==='Home'?0:e.key==='End'?tabs.length-1:e.key==='ArrowLeft'?(index+tabs.length-1)%tabs.length:(index+1)%tabs.length;e.preventDefault();tabs[next].focus();showPanel(tabs[next].dataset.lgtGo); }));
  selectWorkview('jobs',false);

  const checks=$$('input[name="enabled_locale"]');
  const enabled=()=>checks.filter(x=>x.checked).map(x=>x.value);
  const syncLocales=()=>{const list=enabled();$('#lgt-locales').value=list.join(',');$('#lgt-enabled-count').textContent=list.length;$$('#lgt-plan-locales option').forEach(o=>{o.disabled=!list.includes(o.value);o.selected=list.includes(o.value);});$$('#lgt-publish-locale option, #lgt-quick-locale option').forEach(o=>{if(o.value){o.disabled=!list.includes(o.value);if(o.disabled)o.selected=false;}});};
  checks.forEach(x=>x.addEventListener('change',syncLocales));syncLocales();
  const settingsPayload=()=>({mode:$('#lgt-mode').value,source_locale:$('#lgt-source').value,enabled_locales:enabled(),geo_auto_switch_enabled:$('#lgt-geo-auto').checked,accept_language_enabled:$('#lgt-accept-language').checked,publication:{auto_publish_safe:$('#lgt-auto-publish-safe').checked,auto_enable_widget:$('#lgt-auto-enable-widget').checked},widget:{enabled:$('#lgt-widget-enabled').checked,position:$('#lgt-position').value,bottom_offset:Number($('#lgt-bottom').value),mobile_bottom_offset:Number($('#lgt-mobile-bottom').value),theme:$('#lgt-theme').value}});
  $('#lgt-settings-form').addEventListener('submit',async e=>{e.preventDefault();const b=$('button[type="submit"]',e.currentTarget);await busy(b,async()=>{await api(routes.settings,'PUT',settingsPayload());syncLocales();notify('语言策略已保存。现在可配置服务商或使用已有本地词典。');refresh();}).catch(x=>notify(x.message,true));});
  $('#lgt-widget-form').addEventListener('submit',async e=>{e.preventDefault();const b=$('button[type="submit"]',e.currentTarget);await busy(b,async()=>{await api(routes.settings,'PUT',settingsPayload());notify('前台语言选择器设置已保存。前台只读取已发布本地词典。');}).catch(x=>notify(x.message,true));});

  const provider=$('#lgt-provider'),key1=$('#lgt-key-one'),key2=$('#lgt-key-two'),providerForm=$('#lgt-provider-form'); let editingAccount=null;
  const modelSelector=document.createElement('select');
  modelSelector.id='lgt-free-model';modelSelector.className='form-select';modelSelector.hidden=true;modelSelector.disabled=true;
  key2.insertAdjacentElement('afterend',modelSelector);
  const sfRateFields=document.createElement('div');
  sfRateFields.className='lgt-field full';
  sfRateFields.innerHTML='<div class="lgt-fields"><div class="lgt-field third"><label for="lgt-rpm">该账户每分钟请求上限 (RPM)</label><input id="lgt-rpm" class="form-control" type="number" min="1" max="10000" value="1000"><p class="form-text">按官方账户与模型限制填写。</p></div><div class="lgt-field third"><label for="lgt-tpm">该账户每分钟 Token 上限 (TPM)</label><input id="lgt-tpm" class="form-control" type="number" min="1" max="5000000" value="80000"><p class="form-text">使用保守 Token 预留，响应后以真实用量结算。</p></div><div class="lgt-field third"><p class="form-text mt-3">每个 API 账户与模型独立计数；同一账户的 worker 共享窗口，触发 429 后自动冷却。</p></div></div>';
  const rateOnlyNote=document.createElement('div');
  rateOnlyNote.className='lgt-field full';rateOnlyNote.hidden=true;
  rateOnlyNote.innerHTML='<div class="lgt-safe"><b>i</b><div><strong>免费模型采用仅速率限制</strong><p class="mb-0">不设置字符额度；系统按该 API 账户的 RPM/TPM 控制并发，同时保留处理字符与 Token 审计。</p></div></div>';
  $('#lgt-qps').closest('.lgt-field').insertAdjacentElement('afterend',rateOnlyNote);
  $('#lgt-qps').closest('.lgt-field').insertAdjacentElement('afterend',sfRateFields);
  const profile=clear=>{
    const p=profiles[provider.value];if(!p)return;
    const [a,b]=p.credential_fields,editing=editingAccount!==null,multi=Array.isArray(p.model_options);
    const rateOnly=provider.value==='siliconflow';
    sfRateFields.hidden=!multi;sfRateFields.style.display=multi?'':'none';rateOnlyNote.hidden=!rateOnly;
    $('#lgt-free-quota').closest('.lgt-field').hidden=rateOnly;$('#lgt-soft-cap').closest('.lgt-field').hidden=rateOnly;$('#lgt-buffer').closest('.lgt-field').hidden=rateOnly;
    $('#lgt-free-quota').disabled=rateOnly;$('#lgt-soft-cap').disabled=rateOnly;$('#lgt-buffer').disabled=rateOnly;
    if(rateOnly){$('#lgt-free-quota').value='0';$('#lgt-soft-cap').value='0';$('#lgt-buffer').value='0';}
    $('#lgt-key-one-label').textContent=a.label;$('#lgt-key-two-label').textContent=b.label;
    $('#lgt-key-one-help').textContent=editing?`${a.help} 留空可保留已加密凭据。`:a.help;
    $('#lgt-key-two-help').textContent=multi?b.help:(editing?`${b.help} 留空可保留已加密凭据。`:b.help);
    $('#lgt-billing-help').textContent=p.account_help;$('#lgt-quota-help').textContent=p.quota_help;
    key1.placeholder=editing?'留空保留现有凭据':a.placeholder;
    key2.placeholder=editing?'留空保留现有凭据':b.placeholder;
    key1.type=a.input_type||'text';key2.type=b.input_type||'password';
    key1.dataset.key=a.key;key2.dataset.key=b.key;
    key1.required=!editing;key2.required=!editing&&!multi;
    key2.hidden=multi;key2.disabled=multi;key2.style.display=multi?'none':'';
    modelSelector.hidden=!multi;modelSelector.disabled=!multi;modelSelector.style.display=multi?'':'none';
    $('#lgt-key-two-label').htmlFor=multi?modelSelector.id:key2.id;
    if(multi){
      const selected=(editing?editingAccount?.selected_model:null)||p.default_model;
      modelSelector.replaceChildren();
      p.model_options.forEach(item=>{const option=document.createElement('option');option.value=item.value;option.textContent=item.label;modelSelector.append(option);});
      modelSelector.value=selected;
      key2.value=selected;
    }
    if(editing&&multi){
      $('#lgt-provider-form-description').textContent='可直接切换已核实的免费翻译模型，无需重新填写原 API Key。模型变化会暂停派发，需重新验证。';
      $('#lgt-provider-form-help').textContent='仅改额度不影响验证；切换模型后需重新验证。';
    }
    $('#lgt-key-one-label').classList.toggle('lgt-required',!editing);
    $('#lgt-key-two-label').classList.toggle('lgt-required',!editing);
    if(clear){key1.value='';if(!multi)key2.value='';}
  };
  const resetProviderForm=()=>{editingAccount=null;providerForm.reset();$('#lgt-rpm').value=1000;$('#lgt-tpm').value=80000;provider.disabled=false;const billing=$('#lgt-billing'),billingLabel=$('label[for="lgt-billing"]');billing.disabled=false;billing.required=true;billingLabel.classList.add('lgt-required');billing.placeholder='例如：生产环境 RAM 用户';$('#lgt-billing-lock-note').hidden=true;$('#lgt-provider-edit-note').hidden=true;$('#lgt-provider-cancel').hidden=true;$('#lgt-provider-form-title').textContent='添加翻译 API 服务商';$('#lgt-provider-form-description').textContent='不同平台使用各自的凭据格式。凭据仅在保存时提交并立即加密，页面、日志和状态接口均不会回传密钥。';$('#lgt-provider-submit').textContent='加密保存并进入验证';$('#lgt-provider-enabled-label').textContent='保存为可验证账户（派发仍保持关闭）';$('#lgt-provider-form-help').textContent='不会自动扣费、验证或派发。';profile(true);};
  const editProvider=(id)=>{const account=configuredAccounts.find(x=>Number(x.id)===Number(id));if(!account){notify('未找到该服务商账户，请刷新页面后重试。',true);return;}$('#lgt-provider-details').open=true;editingAccount=account;provider.value=account.provider;provider.disabled=true;const billing=$('#lgt-billing'),billingLabel=$('label[for="lgt-billing"]');billing.value=`账户 #${account.id}（标识受保护）`;billing.disabled=true;billing.required=false;billingLabel.classList.remove('lgt-required');$('#lgt-billing-lock-note').hidden=false;$('#lgt-free-quota').value=account.free_quota??0;$('#lgt-soft-cap').value=account.soft_cap??0;$('#lgt-buffer').value=account.external_usage_buffer??0;$('#lgt-qps').value=account.qps_limit??1;$('#lgt-rpm').value=account.rpm_limit??1000;$('#lgt-tpm').value=account.tpm_limit??80000;$('#lgt-priority').value=account.failover_priority??100;$('#lgt-timezone').value=account.cycle_timezone||'UTC';$('#lgt-tier').value=account.account_tier||'';$('#lgt-console-link').value=account.source_link||'';$('#lgt-provider-enabled').checked=Boolean(account.enabled);$('#lgt-provider-edit-note').hidden=false;$('#lgt-provider-cancel').hidden=false;$('#lgt-provider-form-title').textContent=`编辑 ${profiles[account.provider]?.label||account.provider} 的额度与策略`;$('#lgt-provider-form-description').textContent='更换完整新凭据才会重新验证，留空保留原密钥。';$('#lgt-provider-submit').textContent='保存账户调整';$('#lgt-provider-enabled-label').textContent='启用此账户（关闭会同时停止派发）';$('#lgt-provider-form-help').textContent='仅调整额度会保留当前验证状态。';const advanced=$('details',providerForm);if(advanced)advanced.open=true;profile(true);providerForm.scrollIntoView({behavior:'smooth',block:'start'});key1.focus();};
  provider.addEventListener('change',()=>{
    profile(true);
    if(!editingAccount&&provider.value==='siliconflow'){
      $('#lgt-qps').value='1';$('#lgt-rpm').value='1000';$('#lgt-tpm').value='80000';
    }
  });profile(false);
  $('#lgt-provider-cancel').addEventListener('click',resetProviderForm);
  $('#lgt-provider-new').addEventListener('click',()=>{resetProviderForm();$('#lgt-provider-details').open=true;$('#lgt-provider-form').scrollIntoView({behavior:'smooth',block:'start'});provider.focus();});
  $$('.lgt-provider-edit').forEach(b=>b.addEventListener('click',()=>editProvider(b.dataset.id)));
  $$('.lgt-provider-delete').forEach(button=>button.addEventListener('click',async()=>{
    const id=Number(button.dataset.id),account=configuredAccounts.find(x=>Number(x.id)===id);
    if(!account){notify('账户已变更，请刷新后重试。',true);return;}
    const providerName=profiles[account.provider]?.label||account.provider;
    if(!confirm(`确定删除 ${providerName}（账户 #${id}）？\n\n将立即停止其后续派发、清除保存的 API 密钥并从列表移除；历史翻译用量和审计记录会保留。若仍有在途请求，系统会拒绝删除。`))return;
    await busy(button,async()=>{const result=await api(`${routes.providerBase}${id}`,'DELETE',{confirm_delete:true});notify(result.notice||'服务商账户已删除。');setTimeout(()=>location.reload(),700);}).catch(error=>notify(error.message,true));
  }));
  providerForm.addEventListener('submit',async e=>{
    e.preventDefault();const form=e.currentTarget;if(!form.reportValidity())return;
    const multi=Array.isArray(profiles[provider.value]?.model_options);
    const selectedModel=multi?modelSelector.value:key2.value;
    const rotating=key1.value.trim()!==''||(!multi&&key2.value.trim()!=='');
    const modelChanged=Boolean(multi&&editingAccount&&selectedModel!==editingAccount.selected_model);
    if(editingAccount&&rotating&&(key1.value.trim()===''||(!multi&&key2.value.trim()===''))){
      notify('如需更换 API 凭据，请完整填写密钥；若只调整额度，请将密钥留空。',true);return;
    }
    if(multi&&!profiles[provider.value].model_options.some(x=>x.value===selectedModel)){notify('请选择支持的免费翻译模型。',true);return;}
    const credentials=multi
      ? (rotating?{api_key:key1.value,model:selectedModel}:(modelChanged?{model:selectedModel}:{}))
      : { [key1.dataset.key]:key1.value,[key2.dataset.key]:key2.value };
    const b=$('#lgt-provider-submit');await busy(b,async()=>{
      const payload={account_tier:$('#lgt-tier').value.trim(),free_quota:Number($('#lgt-free-quota').value||0),soft_cap:Number($('#lgt-soft-cap').value||0),external_usage_buffer:Number($('#lgt-buffer').value||0),cycle_timezone:$('#lgt-timezone').value,qps_limit:Number($('#lgt-qps').value||1),rpm_limit:Number($('#lgt-rpm').value||1000),tpm_limit:Number($('#lgt-tpm').value||80000),failover_priority:Number($('#lgt-priority').value||100),enabled:$('#lgt-provider-enabled').checked,source_link:$('#lgt-console-link').value.trim(),credentials:rotating||modelChanged?credentials:{}};
      const d=editingAccount?await api(`${routes.providerBase}${editingAccount.id}`,'PUT',payload):await api(routes.providers,'POST',{...payload,provider:provider.value,billing_identity:$('#lgt-billing').value.trim(),credentials});
      notify(d.notice||(editingAccount?'账户设置已更新。':'API 凭据已加密保存，请完成验证。'));
      setTimeout(()=>location.reload(),700);
    }).catch(x=>notify(x.message,true));
  });

  const picked=new Set(), list=$('#lgt-country-list');
  const updateCountry=()=>{$('#lgt-country-summary').textContent=`已选择 ${picked.size} 个国家／地区`;$ ('#lgt-country-apply').disabled=!picked.size;};
  const renderCountries=()=>{const q=$('#lgt-country-search').value.trim().toLocaleLowerCase();const rows=countries.filter(c=>c.locale&&`${c.name} ${c.code} ${c.locale}`.toLocaleLowerCase().includes(q));list.replaceChildren();rows.forEach(c=>{const l=document.createElement('label'),i=document.createElement('input'),s=document.createElement('span'),strong=document.createElement('strong'),small=document.createElement('small');l.className='lgt-country';i.type='checkbox';i.value=c.code;i.checked=picked.has(c.code);i.addEventListener('change',()=>{i.checked?picked.add(c.code):picked.delete(c.code);updateCountry();});strong.textContent=`${c.name}（${c.code}）`;small.textContent=`推荐：${c.locale_label}（${c.locale}）`;s.append(strong,small);l.append(i,s);list.append(l);});$('#lgt-country-empty').hidden=rows.length!==0;};
  $('#lgt-country-search').addEventListener('input',renderCountries);$('#lgt-country-clear').addEventListener('click',()=>{picked.clear();renderCountries();updateCountry();});renderCountries();updateCountry();
  $('#lgt-country-apply').addEventListener('click',async e=>{await busy(e.currentTarget,async()=>{const d=await api(routes.countryRecommendations,'PUT',{countries:[...picked]});checks.forEach(i=>i.checked=(d.settings.enabled_locales||[]).includes(i.value));syncLocales();picked.clear();renderCountries();updateCountry();notify(d.notice||'国家／地区语言建议已保存。');}).catch(x=>notify(x.message,true));});
  $('#lgt-country-rule-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;if(!f.reportValidity())return;const b=$('button[type="submit"]',f);await busy(b,async()=>{const d=await api(routes.country,'PUT',{country:$('#lgt-country').value.trim(),locale:$('#lgt-country-locale').value.trim(),enabled:$('#lgt-country-enabled').checked});const body=$('#lgt-country-rules');if($('.lgt-empty',body))body.replaceChildren();const r=document.createElement('tr');[d.rule.country,d.rule.locale,d.rule.enabled?'已启用':'已停用'].forEach(v=>{const c=document.createElement('td');c.textContent=v;r.append(c);});body.prepend(r);f.reset();$('#lgt-country-enabled').checked=true;notify('国家默认语言已保存。');}).catch(x=>notify(x.message,true));});

  const scopes=(form,name)=>$$(`input[name="${name}"]:checked`,form).map(x=>x.value);
  const showScan=r=>{const out=$('#lgt-scan-result');out.replaceChildren();[['发现公开条目',r.found],['原文有变更',r.changed],['已审核译文',r.approved],['待重新翻译',r.stale]].forEach(([label,value])=>{const d=document.createElement('div'),b=document.createElement('b'),s=document.createElement('span');b.textContent=String(value||0);s.textContent=label;d.append(b,s);out.append(d);});out.hidden=false;const n=$('#lgt-scan-note'),notes=[];if((r.unsupported_components||[]).length)notes.push(`未纳入扫描的装修组件：${r.unsupported_components.join('、')}`);if((r.errors||[]).length)notes.push(`扫描提示：${r.errors.join('、')}`);n.textContent=notes.join('；');n.hidden=!notes.length;};
  $('#lgt-scan-form').addEventListener('submit',async e=>{e.preventDefault();const b=$('button[type="submit"]',e.currentTarget);await busy(b,async()=>{const d=await api(routes.scan,'POST',{scope:scopes(e.currentTarget,'scope')});showScan(d.report||{});notify('扫描完成，现在可创建翻译任务。');}).catch(x=>notify(x.message,true));});
  $('#lgt-quick-plan').addEventListener('click',async e=>{
    const locales=enabled();
    if(!locales.length){notify('请先在「语言策略」勾选语言并保存。',true);showPanel('languages',true);return;}
    if(!dispatchReady){notify('请先在「API 账户」完成验证并开启派发。',true);showPanel('providers',true);return;}
    if($('#lgt-mode').value!=='free_only'){
      notify('当前不是「仅免费额度」模式。请在语言策略中切换并保存，否则任务只会停留在待执行。',true);
      showPanel('languages',true);return;
    }
    const scope=scopes($('#lgt-plan-form'),'plan_scope');
    if(!scope.length){notify('请选择至少一种公开内容。',true);return;}
    await busy(e.currentTarget,async()=>{
      // Save what the operator actually selected before creating jobs.
      // Otherwise a freshly checked language is rejected by the server as disabled.
      await api(routes.settings,'PUT',settingsPayload());
      const d=await api(routes.plan,'POST',{locales,scope});
      showScan(d.coverage||{});
      const stats=d.statistics||{};notify(`扫描完成：新任务条目 ${stats.new_items||0} 条，复用译文 ${stats.reused_translations||0} 条，需人工处理 ${stats.needs_manual_attention||0} 条。任务状态：${d.job?.status||'未知'}。`);
      showPanel('library',true);refresh();
    }).catch(x=>notify(x.message,true));
  });

  $('#lgt-plan-form').addEventListener('submit',async e=>{e.preventDefault();const locales=[...$('#lgt-plan-locales').selectedOptions].map(o=>o.value),scope=scopes(e.currentTarget,'plan_scope');if(!locales.length){notify('请先启用并选择至少一种目标语言。',true);return;}if(!scope.length){notify('请至少选择一类公开内容。',true);return;}const b=$('button[type="submit"]',e.currentTarget);await busy(b,async()=>{await api(routes.settings,'PUT',settingsPayload());const d=await api(routes.plan,'POST',{locales,scope});const st=d.statistics||{};notify(`任务已创建：新增 ${st.new_items||0} 条，复用 ${st.reused_translations||0} 条。`);$('#lgt-custom-workflow').open=false;showPanel('library',true);refresh();}).catch(x=>notify(x.message,true));});

  const kind=v=>({product:'商品',category:'分类',page:'页面',builder:'装修模块',commerce_ui:'固定界面文案'})[v]||v;
  const selectedTranslationIds=new Set();
  let currentLibraryItems=[], libraryPage=1, libraryLoaded=false, libraryRequestId=0;
  const updateLibrarySelection=()=>{
    const available=currentLibraryItems.filter(i=>Number(i.id)>0).map(i=>Number(i.id));
    const all=available.length>0 && available.every(id=>selectedTranslationIds.has(id));
    const check=$('#lgt-library-checkall');check.checked=all;check.indeterminate=!all&&available.some(id=>selectedTranslationIds.has(id));check.disabled=!available.length;
    $('#lgt-library-selected-count').textContent=`已选择 ${selectedTranslationIds.size} 条（当前页）`;
    $('#lgt-library-delete-selected').disabled=selectedTranslationIds.size===0;
  };
  const renderLibrary=items=>{
    currentLibraryItems=items;selectedTranslationIds.clear();
    const body=$('#lgt-library-items');body.replaceChildren();
    if(!items.length){body.innerHTML='<tr><td class="lgt-empty" colspan="6">没有符合条件的条目。请选择具体语言查看尚未翻译的原文，或先扫描公开内容。</td></tr>';updateLibrarySelection();return;}
    items.forEach(i=>{
      const r=document.createElement('tr'),selection=document.createElement('td'),a=document.createElement('td'),b=document.createElement('td'),c=document.createElement('td'),d=document.createElement('td'),actions=document.createElement('td');
      a.className=d.className='lgt-cell';a.textContent=`${kind(i.kind)} #${i.entity_id} · ${i.field}\n${i.source_text}`;b.textContent=i.locale;
      c.textContent=`${({pending:'未翻译',translated:'待审核',needs_review:'质量异常',approved:'已审核',stale:'待更新'})[i.status]||i.status}${i.locked?' · 已锁定':''}`;
      d.textContent=i.text||'—';
      if(Number(i.id)>0){const check=document.createElement('input');check.type='checkbox';check.className='lgt-library-item-check';check.setAttribute('aria-label',`选择译文 ${i.id}`);check.addEventListener('change',()=>{check.checked?selectedTranslationIds.add(Number(i.id)):selectedTranslationIds.delete(Number(i.id));updateLibrarySelection();});selection.append(check);}
      const edit=document.createElement('button'),approve=document.createElement('button');edit.type=approve.type='button';edit.className='btn btn-sm btn-outline-secondary me-1';approve.className='btn btn-sm btn-outline-primary';edit.textContent='修订';approve.textContent='审核通过';edit.disabled=!i.id;approve.disabled=!i.id||!i.text||i.status==='approved';
      edit.addEventListener('click',()=>{$('#lgt-manual-id').value=i.id;$('#lgt-manual-version').value=i.version;$('#lgt-manual-text').value=i.text||'';$('#lgt-manual-lock').checked=!!i.locked;$('#lgt-editor').open=true;$('#lgt-manual-text').focus();});
      approve.addEventListener('click',async()=>{await busy(approve,async()=>{await api(`${routes.translationBase}${i.id}/review`,'POST',{version:i.version,approved:true});notify('译文已审核通过。');await loadLibrary(libraryPage);}).catch(x=>notify(x.message,true));});
      actions.append(edit,approve);r.append(selection,a,b,c,d,actions);body.append(r);
    });
    updateLibrarySelection();
  };
  $('#lgt-library-checkall').addEventListener('change',e=>{selectedTranslationIds.clear();if(e.currentTarget.checked)currentLibraryItems.filter(i=>Number(i.id)>0).forEach(i=>selectedTranslationIds.add(Number(i.id)));$$('.lgt-library-item-check').forEach(x=>x.checked=e.currentTarget.checked);updateLibrarySelection();});
  $('#lgt-library-delete-selected').addEventListener('click',async e=>{
    const ids=[...selectedTranslationIds].sort((a,b)=>a-b),confirmation=`DELETE ${ids.length}`;
    if(!ids.length)return;
    if(!confirm(`将永久删除本页选中的 ${ids.length} 条译文、其所有修订版本，以及各历史发布快照中的对应内容。删除后前台将不再显示这些译文。任务与计费证据保留。确认继续吗？`))return;
    const typed=prompt(`为确认删除，请输入 ${confirmation}`, '');if(typed!==confirmation){notify('确认文字不匹配，未执行删除。',true);return;}
    await busy(e.currentTarget,async()=>{const d=await api(routes.bulkDeleteTranslations,'DELETE',{ids,confirmation});notify(`已删除 ${d.result.translations} 条译文、${d.result.published_items} 条发布内容。`);await loadLibrary(libraryPage);await refresh();}).catch(x=>notify(x.message,true));
  });
  const loadLibrary=async(page=1)=>{
    const requestId=++libraryRequestId;
    const q=new URLSearchParams({page:String(page)});
    [['locale','#lgt-library-locale'],['status','#lgt-library-status'],['kind','#lgt-library-kind'],['q','#lgt-library-query']].forEach(([key,id])=>{const v=$(id).value.trim();if(v)q.set(key,v);});
    const d=await api(`${routes.translations}?${q}`);
    if(requestId!==libraryRequestId)return;
    if(page>1&&!(d.items||[]).length&&Number(d.total)>0){await loadLibrary(page-1);return;}
    libraryPage=d.page||page;libraryLoaded=true;
    renderLibrary(d.items||[]);
    $('#lgt-library-pageinfo').textContent=`第 ${libraryPage} 页 · 共 ${d.total||0} 条 · 每页 ${d.per_page||25} 条`;
    $('#lgt-library-prev').disabled=libraryPage<=1;
    $('#lgt-library-next').disabled=!d.has_more;
  };
  $('#lgt-library-filter').addEventListener('submit',async e=>{e.preventDefault();const b=$('button[type="submit"]',e.currentTarget);await busy(b,()=>loadLibrary(1)).catch(x=>notify(x.message,true));});
  $('#lgt-library-prev').addEventListener('click',()=>loadLibrary(Math.max(1,libraryPage-1)).catch(x=>notify(x.message,true)));
  $('#lgt-library-next').addEventListener('click',()=>loadLibrary(libraryPage+1).catch(x=>notify(x.message,true)));

  $('#lgt-manual-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;if(!f.reportValidity()||!$('#lgt-manual-id').value){notify('请先从翻译库中选择一个条目。',true);return;}const b=$('button[type="submit"]',f);await busy(b,async()=>{const d=await api(`${routes.translationBase}${$('#lgt-manual-id').value}`,'PUT',{version:Number($('#lgt-manual-version').value),text:$('#lgt-manual-text').value,lock:$('#lgt-manual-lock').checked});$('#lgt-manual-version').value=d.translation.version;notify('人工修订已保存，请审核通过后再发布。');await loadLibrary(libraryPage);}).catch(x=>notify(x.message,true));});
  $('#lgt-library-reset-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;if(!f.reportValidity())return;const locale=$('#lgt-library-reset-locale').value.toLowerCase(),confirmation=$('#lgt-library-reset-confirm').value.trim(),expected=`RESET ${locale.toUpperCase()}`;if(confirmation!==expected){notify(`请输入 ${expected} 才能继续。`,true);return;}if(!confirm(`将删除 ${locale.toUpperCase()} 的全部译文和发布快照，但保留任务与用量审计。确认继续吗？`))return;const b=$('button[type="submit"]',f);await busy(b,async()=>{const d=await api(`${routes.libraryBase}${encodeURIComponent(locale)}`,'DELETE',{confirmation});notify(`已重置 ${locale.toUpperCase()}：删除 ${d.result.translations} 条译文、${d.result.publications} 个发布版本。`);setTimeout(()=>location.reload(),900);}).catch(x=>notify(x.message,true));});
  const bulkLocales=()=>$$('#lgt-library-bulk-locales input[type="checkbox"]:checked').map(o=>o.value.toLowerCase()).sort();
  const bulkConfirmation=()=>`RESET ${bulkLocales().join(',').toUpperCase()}`;
  $('#lgt-library-bulk-locales').addEventListener('change',()=>{$('#lgt-library-bulk-hint').textContent=bulkLocales().length?`请输入：${bulkConfirmation()}`:'先选择语言';});
  $('#lgt-library-bulk-reset-form').addEventListener('submit',async e=>{
    e.preventDefault();const f=e.currentTarget;if(!f.reportValidity())return;
    const locales=bulkLocales(),confirmation=$('#lgt-library-bulk-confirm').value.trim(),expected=bulkConfirmation();
    if(!locales.length||confirmation!==expected){notify(`确认文字应为 ${expected}。`,true);return;}
    if(!confirm(`将一次删除 ${locales.join('、').toUpperCase()} 的全部译文与所有发布版本。无法撤销，前台相应语言将失去已发布翻译。确认继续吗？`))return;
    const b=$('button[type="submit"]',f);await busy(b,async()=>{
      const d=await api(routes.bulkResetLibrary,'DELETE',{locales,confirmation});
      notify(`已重置 ${d.result.locales.length} 种语言，删除 ${d.result.totals.translations} 条译文、${d.result.totals.publications} 个发布版本。`);
      setTimeout(()=>location.reload(),900);
    }).catch(x=>notify(x.message,true));
  });

  $('#lgt-quick-publish').addEventListener('click',async e=>{
    const locale=$('#lgt-quick-locale').value;
    if(!locale){notify('请先选择需要发布的语言。',true);return;}
    if(!confirm(`将自动批准 ${locale} 中通过格式检查的机器译文并发布。自动检查不代表语义准确，请先抽查内容；异常译文仍需人工审核。确认继续吗？`))return;
    await busy(e.currentTarget,async()=>{
      const d=await api(routes.quickPublish,'POST',{locale,confirm_auto_review:true,enable_widget:$('#lgt-quick-widget').checked});
      $('#lgt-quick-publish-note').textContent=`已审核 ${d.auto_approved} 条，尚有 ${d.needs_manual_review} 条需要人工审核；发布版本 ${d.publication.revision}，有效条目 ${d.publication.coverage.approved}。`;
      notify(d.publication.unchanged ? `${locale} 的已发布译文没有变化，继续使用版本 ${d.publication.revision}。` : `已创建 ${locale} 发布版本 ${d.publication.revision}。请去前台检查实际页面。`);
      await refresh();
    }).catch(x=>notify(x.message,true));
  });

  $('#lgt-publish-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget;if(!f.reportValidity())return;const b=$('button[type="submit"]',f);await busy(b,async()=>{const d=await api(routes.publish,'POST',{locale:$('#lgt-publish-locale').value});notify(d.publication.unchanged ? `译文未变化，仍使用 ${d.publication.locale} 版本 ${d.publication.revision}。` : `已发布 ${d.publication.locale} 的不可变版本 ${d.publication.revision}。`);setTimeout(()=>location.reload(),900);}).catch(x=>notify(x.message,true));});
  $$('.lgt-rollback').forEach(b=>b.addEventListener('click',async()=>{if(!confirm(`确认将 ${b.dataset.locale} 的历史版本恢复为新的不可变版本吗？`))return;await busy(b,async()=>{const d=await api(`${routes.publicationBase}${b.dataset.id}/rollback`,'POST',{locale:b.dataset.locale});notify(`已创建恢复版本 ${d.publication.revision}。`);setTimeout(()=>location.reload(),900);}).catch(x=>notify(x.message,true));}));
  const siliconflowReminder=id=>configuredAccounts.find(a=>Number(a.id)===Number(id))?.provider==='siliconflow'?'\n\n请先核对 https://siliconflow.cn/pricing：Hunyuan-MT-7B 目前标注免费，但定价可能变化；系统不会自动检查价格。':'';
  $$('.lgt-verify').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('确认发送一次小型验证请求吗？该请求可能消耗免费额度。'+siliconflowReminder(b.dataset.id)))return;await busy(b,async()=>{const d=await api(`${routes.providerBase}${b.dataset.id}/verify`,'POST',{confirm_provider_charge:true});notify(d.notice||'账户验证成功。');setTimeout(()=>location.reload(),900);}).catch(x=>notify(x.message,true));}));
  $$('.lgt-dispatch').forEach(b=>b.addEventListener('click',async()=>{const enabled=b.dataset.enable==='1';if(enabled&&!confirm('确认开启该账户的翻译派发吗？本地额度限制不能替代官方价格和账单检查。'+siliconflowReminder(b.dataset.id)))return;await busy(b,async()=>{const d=await api(`${routes.providerBase}${b.dataset.id}/dispatch`,'POST',{enabled,confirm_dispatch:enabled});notify(d.notice||'派发状态已保存。');setTimeout(()=>location.reload(),900);}).catch(x=>notify(x.message,true));}));

  const glossaryRoot=$('#lgt-glossary-terms');
  $('#lgt-glossary-form').addEventListener('submit',async e=>{e.preventDefault();const field=$('#lgt-glossary-term');if(!e.currentTarget.reportValidity())return;const button=$('button[type="submit"]',e.currentTarget);await busy(button,async()=>{
    const result=await api(routes.glossarySave,'POST',{term:field.value.trim()});
    const id=result.term.id; if(!glossaryRoot.querySelector(`[data-glossary-id="${id}"]`)){
      $('#lgt-glossary-empty')?.remove();const item=document.createElement('span'),label=document.createElement('span'),del=document.createElement('button');item.dataset.glossaryId=String(id);label.textContent=result.term.term;del.type='button';del.className='lgt-glossary-delete';del.dataset.id=String(id);del.textContent='×';del.setAttribute('aria-label',`删除保护词 ${result.term.term}`);item.append(label,del);glossaryRoot.append(item);
    }field.value='';notify('保护词已保存。仅对未来生成的译文生效，旧译文不会自动重译。');
  }).catch(x=>notify(x.message,true));});
  glossaryRoot.addEventListener('click',async e=>{const b=e.target.closest('.lgt-glossary-delete');if(!b)return;if(!confirm('确定删除该保护词吗？已有译文不会变化。'))return;await busy(b,async()=>{await api(`${routes.glossaryBase}${encodeURIComponent(b.dataset.id)}`,'DELETE');b.closest('[data-glossary-id]')?.remove();if(!glossaryRoot.children.length)glossaryRoot.textContent='尚未添加保护词';notify('已删除保护词。');}).catch(x=>notify(x.message,true));});
  const renderJobs=jobs=>{
    const body=$('#lgt-jobs');if(!body)return;body.replaceChildren();
    if(!jobs.length){body.innerHTML='<tr><td class="lgt-empty" colspan="6">暂无任务，或任务已从列表删除。</td></tr>';return;}
    jobs.forEach(j=>{
      const r=document.createElement('tr'),id=document.createElement('td'),state=document.createElement('td'),mode=document.createElement('td'),progress=document.createElement('td'),time=document.createElement('td'),actions=document.createElement('td');
      id.textContent=`#${String(j.public_id||j.id).slice(0,8)}`;id.title=String(j.public_id||j.id);state.textContent=jobStatusLabels[j.status]||j.status;mode.textContent=modeLabels[j.mode]||j.mode;
      progress.textContent=`${j.completed_items}/${j.total_items}；失败 ${j.failed_items}`;
      if(Number(j.inflight_items||0)>0){const s=document.createElement('span');s.className='lgt-pause';s.textContent=`仍有 ${j.inflight_items} 条在途请求，不能删除任务或重置相关语言。`;progress.append(s);}
      (j.failure_summary||[]).forEach(x=>{const e=document.createElement('span');e.className='lgt-failure';e.textContent=`${x.total} 条：${x.label}（${x.code}）`;progress.append(e);});
      if(j.status==='paused'&&j.pause_reason){const e=document.createElement('span');e.className='lgt-pause';e.textContent=`暂停原因：${j.pause_reason.label}`;progress.append(e);}
      const auto=j.auto_publication||null;
      if(auto){const e=document.createElement('span');e.className='lgt-auto-status';const published=Array.isArray(auto.published)?auto.published:[];if(published.length)e.textContent=`已自动发布：${published.map(x=>`${String(x.locale).toUpperCase()} v${x.revision}`).join('、')}；前台读取本地词典。`;else if(auto.state==='no_safe_results')e.textContent='没有可自动发布的安全译文。';else e.textContent='自动发布未完成，可在发布区手动处理。';progress.append(e);}
      if(Number(j.retryable_failure_count||0)>0&&['completed','partial_failed'].includes(j.status)){const retry=document.createElement('button');retry.type='button';retry.className='btn btn-sm btn-outline-primary mt-2';retry.textContent=`安全重试 ${j.retryable_failure_count} 条未计费失败项`;retry.addEventListener('click',async()=>{if(!confirm('只会重新发送确认未计费的失败项，不会重试结果不确定的请求。确认继续吗？'))return;await busy(retry,async()=>{const d=await api(routes.retryDefinite.replace('__job__',encodeURIComponent(String(j.id))),'POST',{confirm_safe_retry:true});notify(d.notice||'安全重试已开始。');await refresh();}).catch(x=>notify(x.message,true));});progress.append(retry);}
      if(['planned','queued','scanning','running','pausing','paused'].includes(j.status)){
        const stop=document.createElement('button');stop.type='button';stop.className='btn btn-sm btn-outline-warning me-1 mb-1';stop.textContent='停止任务';
        stop.addEventListener('click',async()=>{if(!confirm('停止后不会派发新的翻译请求，但已发送的 API 调用无法撤回，仍可能完成计费和译文写入。确认停止吗？'))return;await busy(stop,async()=>{const d=await api(`${routes.jobBase}${j.id}/cancel`,'POST',{confirm_cancel:true});notify(d.notice);await refresh();}).catch(x=>notify(x.message,true));});actions.append(stop);
      }
      if(['cancelled','completed','partial_failed','failed'].includes(j.status)){
        const del=document.createElement('button');del.type='button';del.className='btn btn-sm btn-outline-danger mb-1';del.textContent='删除任务';del.disabled=Number(j.inflight_items||0)>0;del.title='从列表中归档，保留翻译 API 计费和审计记录';
        del.addEventListener('click',async()=>{if(!confirm('任务将从管理列表移除，但不会抹除已翻译内容、API 使用量及必要审计记录。确认删除吗？'))return;await busy(del,async()=>{const d=await api(`${routes.jobBase}${j.id}`,'DELETE',{confirm_archive:true});notify(d.notice);await refresh();}).catch(x=>notify(x.message,true));});actions.append(del);
      }
      if(j.status==='cancelled'&&Number(j.inflight_items||0)>0){
        const recover=document.createElement('button');recover.type='button';recover.className='btn btn-sm btn-outline-secondary mb-1';recover.textContent='恢复超时请求';recover.title='仅用于已停止超过 30 分钟、仍有僵死领取记录的任务';
        recover.addEventListener('click',async()=>{if(!confirm('仅对超过 30 分钟仍未结束的领取记录做保守恢复：可能产生的费用保留为不确定用量，绝不重新发送请求。确认检查吗？'))return;await busy(recover,async()=>{const d=await api(`${routes.jobBase}${j.id}/recover-claims`,'POST',{confirm_recovery:true});notify(d.notice);await refresh();}).catch(x=>notify(x.message,true));});actions.append(recover);
      }
      const timestamp=j.updated_at||j.created_at;const parsed=timestamp?new Date(timestamp):null;time.textContent=parsed&&!Number.isNaN(parsed.getTime())?parsed.toLocaleString('zh-CN',{month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}):timestamp||'—';r.append(id,state,mode,progress,time,actions);body.append(r);
    });
  };
  const renderAccountUsage=accounts=>{(accounts||[]).forEach(account=>{
    const button=$(`.lgt-provider-edit[data-id="${CSS.escape(String(account.id))}"]`),quota=button?.closest('tr')?.querySelector('.lgt-quota'),usage=account.local_usage||null;if(!quota||!usage)return;
    const committed=Number(usage.committed||0).toLocaleString('zh-CN'),processing=Number(usage.reserved||0)+Number(usage.sending||0),uncertain=Number(usage.uncertain||0).toLocaleString('zh-CN'),strong=quota.querySelector('.lgt-quota-head strong'),rate=quota.querySelector('.lgt-quota-head span'),bar=quota.querySelector('.lgt-quota-bar span'),details=quota.querySelectorAll('small');
    if(usage.budget_enforced===false){if(strong)strong.textContent='仅速率限制';if(rate)rate.textContent=`${account.rpm_limit||1000} RPM`;if(bar)bar.style.width='100%';if(details[0])details[0].textContent=`${usage.billing_cycle||'当前周期'} · 已提交 ${committed} · 处理中 ${processing.toLocaleString('zh-CN')} · 不确定 ${uncertain}`;if(details[1])details[1].textContent=`${account.rpm_limit||1000} RPM · ${Number(account.tpm_limit||80000).toLocaleString('zh-CN')} TPM · ${account.qps_limit||1} QPS`;return;}
    const remaining=Number(usage.remaining||0).toLocaleString('zh-CN'),limit=Number(usage.local_limit||0).toLocaleString('zh-CN'),percent=Math.max(0,Math.min(100,Number(usage.used_percent||0)));if(strong)strong.textContent=`剩余 ${remaining}`;if(rate)rate.textContent=`${percent}%`;if(bar)bar.style.width=`${percent}%`;if(details[0])details[0].textContent=`${usage.billing_cycle||'当前周期'} · 已提交 ${committed} · 处理中 ${processing.toLocaleString('zh-CN')} · 不确定 ${uncertain}`;if(details[1])details[1].textContent=`本地上限 ${limit} · ${account.qps_limit||1} QPS`;
  });};
  renderAccountUsage(configuredAccounts);
  const renderSelfcheck = data => {
    const runtime=data.runtime||{};
    const version=runtime.version||'未知';
    const asset=runtime.asset_current===true?'前端脚本已同步':(runtime.asset_current===false?'前端脚本版本不匹配':'尚无法验证脚本');
    const queue=runtime.queue_connection||'未知';
    $('#lgt-selfcheck-summary').textContent=`插件 ${version} · ${asset} · 队列驱动 ${queue} · ${data.generated_at||''}`;
    const container=$('#lgt-selfcheck-issues');container.replaceChildren();
    const issues=Array.isArray(data.actionable_issues)?data.actionable_issues:[];
    $('#lgt-issue-count').textContent=issues.length?`${issues.length} 项需处理`:'未发现配置异常';
    if(!issues.length){container.textContent='未发现明显的配置问题；请在实际前台抽查译文。';return;}
    const ul=document.createElement('ul');ul.className='mb-0 ps-3';
    issues.slice(0,8).forEach(message=>{const li=document.createElement('li');li.textContent=message;ul.append(li);});container.append(ul);
  };
  const renderLocaleHealth = data => {
    const wrap=$('#lgt-locale-health');wrap.replaceChildren();
    const entries=Array.isArray(data?.locales)?data.locales:[];
    if(!entries.length){wrap.textContent='尚未启用目标语言。请先进入「语言策略」保存至少一种语言。';return;}
    entries.forEach(i=>{
      const card=document.createElement('article'),heading=document.createElement('h3'),meters=document.createElement('div'),progress=document.createElement('div'),bar=document.createElement('span'),status=document.createElement('p'),button=document.createElement('button');
      card.className='lgt-locale-tile';heading.textContent=`${localeLabels[i.locale]||String(i.locale).toUpperCase()} · ${String(i.locale).toUpperCase()}`;meters.className='lgt-meters';
      [['已翻译',`${i.reusable||0}/${i.sources||0}`],['已发布',i.published_items]].forEach(([name,value])=>{const el=document.createElement('span');el.textContent=`${name} ${value||0}`;meters.append(el);});
      progress.className='lgt-mini-bar';bar.style.width=`${Math.min(100,Math.max(0,Math.round((Number(i.published_items)||0)*100/Math.max(1,Number(i.sources)||1))))}%`;progress.append(bar);
      status.textContent=i.next_action||'尚未检查';button.type='button';button.className='btn btn-outline-primary btn-sm';
      const needsWork=Number(i.reusable||0)<Number(i.sources||0),needsReview=Number(i.approved||0)<Number(i.reusable||0),needsPublish=Number(i.published_items||0)<Number(i.approved||0);
      button.textContent=needsWork?'继续翻译':needsReview?'审核译文':needsPublish?'发布':'查看发布';
      button.addEventListener('click',()=>{if(needsWork){showPanel('library',true,'jobs');}else if(needsReview){$('#lgt-library-locale').value=i.locale;$('#lgt-library-status').value='';const loaded=libraryLoaded;showPanel('library',true,'translations');if(loaded)loadLibrary(1).catch(x=>notify(x.message,true));}else{$('#lgt-quick-locale').value=i.locale;showPanel('publish',true);}});
      card.append(heading,meters,progress,status,button);wrap.append(card);
    });
  };
  const renderNextAction = data => {
    const accounts=data.accounts||[],jobs=data.jobs||[],h=data.library_health||{};
    const verified=accounts.filter(a=>Boolean(a.verified_at)).length;
    const active=jobs.filter(j=>['planned','queued','scanning','running','pausing'].includes(j.status)).length;
    dispatchReady=accounts.some(a=>Boolean(a.dispatch_enabled));
    $('#lgt-verified-count').textContent=verified;
    $('#lgt-published-count').textContent=Number(h.published_languages||0);
    $('#lgt-active-count').textContent=active;
    let target='library',view='jobs',title='开始翻译',detail='扫描公开内容，只翻译当前需要更新的文本。',label='前往任务';
    if(enabled().length===0){target='languages';title='先启用目标语言';detail='选择要向访客展示的语言并保存。';label='设置语言';}
    else if(!verified){target='providers';title='连接翻译服务商';detail='添加并验证账户，翻译请求才可安全派发。';label='管理服务商';}
    else if(!dispatchReady){target='providers';title='开启已验证账户的派发';detail='账户已验证，但翻译请求仍处于关闭状态。';label='配置派发';}
    else if(active){title='查看正在执行的翻译';detail=`当前有 ${active} 个进行中的任务。`;label='查看进度';}
    else if($('#lgt-mode').value!=='free_only'&&Number(h.published_languages||0)===0){target='languages';title='切换到免费额度翻译模式';detail='当前模式不允许直接派发新任务；先切换并保存。';label='调整模式';}
    else if(Number(h.approved||0)>0&&Number(h.published_languages||0)===0){target='publish';title='译文已就绪，等待发布';detail='先审核抽查，再发布至前台词典。';label='去发布';}
    else if(Number(h.published_languages||0)>0){target='library';view='translations';title='维护现有翻译库';detail='查看翻译质量、调整译文，按需要重新发布。';label='查看译文';}
    $('#lgt-next-title').textContent=title;$('#lgt-next-detail').textContent=detail;
    const button=$('#lgt-next-button');button.textContent=label;button.dataset.lgtGo=target;button.dataset.lgtLibraryView=view;
  };
  let refreshTimer=null,refreshing=false;
  const refresh=async()=>{
    if(refreshTimer!==null){clearTimeout(refreshTimer);refreshTimer=null;}
    if(refreshing)return;
    if(document.hidden){refreshTimer=setTimeout(refresh,30000);return;}
    refreshing=true;let nextPoll=25000;
    try{
      const d=await api(routes.status);if(!d.ready)return;
      renderSelfcheck(d);renderJobs(d.jobs||[]);renderAccountUsage(d.accounts||[]);
      const h=d.library_health;renderLocaleHealth(h);renderNextAction(d);
      if(h){
        $('#lgt-readiness-summary').textContent=!h.ready_to_display?'前台尚无可用词典，请检查语言进度。':(h.commerce_ui_ready_to_display?'前台译文已发布。':'已发布部分译文，仍需检查固定界面覆盖。');
        $('#lgt-readiness-counts').textContent=`公开原文 ${h.source_entries} · 已翻译 ${h.translated} · 已审核 ${h.approved} · 已发布语言 ${h.published_languages} · 固定界面 ${h.commerce_ui_sources||0}/${h.commerce_ui_expected||0} · 自动本地化 ${h.presentation_enabled?'开启':'关闭'} · 浮窗 ${h.widget_enabled?'显示':'隐藏'}`;
      }
      $('#lgt-live').textContent=`更新于 ${new Date(d.generated_at).toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}`;
      if((d.jobs||[]).some(j=>['planned','queued','scanning','running','pausing'].includes(j.status)))nextPoll=6000;
    }catch(_){$('#lgt-live').textContent='状态暂不可用';nextPoll=35000;}
    finally{refreshing=false;refreshTimer=setTimeout(refresh,nextPoll);}
  };
  $('#lgt-selfcheck-refresh').addEventListener('click',refresh);
  const deepLink=location.hash.match(/^#lgt-(overview|library(?:-(jobs|translations))?|publish|languages|providers)$/)?.[1];
  if(deepLink)showPanel(deepLink.startsWith('library')?'library':deepLink,false,deepLink.endsWith('translations')?'translations':'jobs');
  window.addEventListener('hashchange',()=>{const m=location.hash.match(/^#lgt-(overview|library(?:-(jobs|translations))?|publish|languages|providers)$/);if(m)showPanel(m[1].startsWith('library')?'library':m[1],false,m[1].endsWith('translations')?'translations':'jobs');});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  refresh();
})();
</script>
@endsection
