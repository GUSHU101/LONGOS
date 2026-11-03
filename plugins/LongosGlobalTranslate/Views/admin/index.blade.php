@extends('admin::layouts.master')

@section('title', __('LongosGlobalTranslate::translate.title'))

@section('content')
@php
  $enabledLocales = implode(', ', (array) ($settings['enabled_locales'] ?? []));
  $widget = (array) ($settings['widget'] ?? []);
  $modeLabels = ['preview_only' => '仅预览', 'free_only' => '仅免费额度', 'offline_dictionary' => '仅已发布词典'];
  $providerLabels = ['aliyun' => '阿里云', 'volcengine' => '火山引擎', 'baidu' => '百度翻译'];
  $publicationStateLabels = ['published' => '已发布', 'superseded' => '已替换'];
  $jobStatusLabels = ['planned' => '待执行', 'queued' => '排队中', 'running' => '处理中', 'completed' => '已完成', 'failed' => '失败', 'paused' => '已暂停'];
@endphp
<style>
  .lgt-admin{--lgt-primary:#155eef;--lgt-ink:#101828;--lgt-muted:#667085;--lgt-border:#e4e7ec;max-width:1440px;margin:auto}.lgt-admin .nav-pills{gap:.45rem;overflow-x:auto;flex-wrap:nowrap;padding-bottom:.35rem}.lgt-admin .nav-link{white-space:nowrap;border:1px solid var(--lgt-border);color:#344054;background:#fff}.lgt-admin .nav-link.active{border-color:var(--lgt-primary);background:var(--lgt-primary);color:#fff}.lgt-admin .lgt-tab{display:none}.lgt-admin .lgt-tab.active{display:block}.lgt-admin .lgt-stat{min-height:112px;border:1px solid var(--lgt-border);border-radius:.8rem;background:#fff}.lgt-admin .lgt-setup-lead{border:1px solid #c7d7fe;border-radius:1rem;background:linear-gradient(120deg,#f5f8ff,#fff);padding:1.5rem}.lgt-admin .lgt-status{position:sticky;bottom:1rem;z-index:4;display:none;max-width:720px;margin:1rem auto 0;box-shadow:0 .5rem 1.5rem rgba(16,24,40,.16)}.lgt-admin .lgt-table-cell{max-width:25rem;overflow-wrap:anywhere;white-space:pre-wrap}.lgt-admin .form-text{color:var(--lgt-muted)}@media(max-width:767px){.lgt-admin .card-body{padding:1rem}.lgt-admin .lgt-stat{min-height:auto}.lgt-admin .lgt-setup-lead{padding:1rem}}
</style>
<style>
  .lgt-country-picker{display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:.5rem;max-height:21rem;overflow:auto;background:#fcfcfd}
  .lgt-country-option{display:flex;align-items:flex-start;gap:.55rem;padding:.65rem .75rem;border:1px solid var(--lgt-border);border-radius:.6rem;background:#fff;cursor:pointer}
  .lgt-country-option:hover{border-color:var(--lgt-primary);background:#f5f8ff}.lgt-country-option input{margin-top:.2rem}.lgt-country-option small{display:block;color:var(--lgt-muted)}
  @media(max-width:767px){.lgt-country-picker{grid-template-columns:1fr;max-height:18rem}}
</style>
<main class="lgt-admin py-3" aria-labelledby="lgt-title">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div><h1 id="lgt-title" class="h3 mb-1">{{ __('LongosGlobalTranslate::translate.title') }}</h1><p class="text-secondary mb-0">{{ __('LongosGlobalTranslate::translate.preview') }}</p></div>
    <span class="badge text-bg-light border px-3 py-2">{{ __('LongosGlobalTranslate::translate.mode') }}：{{ $modeLabels[$settings['mode']] ?? $settings['mode'] }}</span>
  </div>
  <nav class="nav nav-pills mb-4" aria-label="翻译工作区">
    <button class="nav-link active" type="button" data-lgt-tab="setup">基础配置</button><button class="nav-link" type="button" data-lgt-tab="plan">翻译任务</button><button class="nav-link" type="button" data-lgt-tab="library">翻译库</button><button class="nav-link" type="button" data-lgt-tab="widget">前台语言选择器</button><button class="nav-link" type="button" data-lgt-tab="operations">发布与审计</button>
  </nav>

  <section class="lgt-tab active" data-lgt-panel="setup"><div class="lgt-setup-lead mb-3"><div class="row align-items-center g-3"><div class="col-lg"><span class="badge text-bg-primary mb-2">第一步</span><h2 class="h4 mb-2">配置语言与翻译 API</h2><p class="mb-0 text-secondary">先添加展示语言，再保存 API 凭据。凭据会加密保存；未完成验证与授权前，系统不会发送任何商品文本。</p></div><div class="col-lg-auto"><span class="small text-secondary d-block">源语言</span><strong>英语（已锁定）</strong></div></div></div><div class="row g-3">
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">展示模式</div><strong class="d-block fs-5 mt-2">{{ $modeLabels[$diagnostics['mode']] ?? $diagnostics['mode'] }}</strong><span class="small text-secondary">不会改写核心源语言</span></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">已审核条目</div><strong class="d-block fs-5 mt-2">{{ (int) ($latest_coverage['approved'] ?? 0) }}</strong><span class="small text-secondary">只有已审核内容可以发布</span></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">待更新条目</div><strong class="d-block fs-5 mt-2">{{ (int) ($latest_coverage['stale'] ?? 0) }}</strong><span class="small text-secondary">源文本变更后需要重新翻译</span></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">服务商派发</div><strong class="d-block fs-5 mt-2">{{ $diagnostics['dispatch_enabled'] ? '已启用免费额度派发' : '默认关闭' }}</strong><span class="small text-secondary">V1 永不产生超额付费</span></div></div>
  </div><div class="card mt-3"><div class="card-body"><h2 class="h5">安全发布流程</h2><ol class="mb-0 ps-3"><li>仅扫描已公开的受支持内容字段。</li><li>逐项审核或人工修订目标语言文本。</li><li>按语言发布不可变的版本快照。</li><li>通过快照创建新版本进行回滚，不覆盖历史。</li></ol></div></div></section>

  <section class="lgt-tab" data-lgt-panel="setup"><div class="row g-3"><div class="col-12 col-xl-7"><div class="card"><div class="card-body"><h2 class="h5">翻译 API 服务商</h2><p class="form-text">凭据仅加密保存，页面与接口不会回传。重新保存后必须重新验证，派发保持关闭。</p>
  <form id="lgt-provider-form" class="row g-3" novalidate><div class="col-md-4"><label class="form-label" for="lgt-provider">翻译服务商</label><select id="lgt-provider" class="form-select">@foreach($providerProfiles as $providerCode => $profile)<option value="{{ $providerCode }}">{{ $profile['label'] }}</option>@endforeach</select><div class="form-text" id="lgt-provider-quota-help">V1 仅允许免费额度范围内派发。</div></div><div class="col-md-8"><label class="form-label" for="lgt-billing">账户标识</label><input id="lgt-billing" class="form-control" required maxlength="255" autocomplete="off" aria-describedby="lgt-billing-help"><div class="form-text" id="lgt-billing-help">仅作租户内识别，保存前会散列处理。</div></div><div class="col-md-6"><label class="form-label" id="lgt-key-one-label" for="lgt-key-one">AccessKey ID</label><input id="lgt-key-one" class="form-control" required autocomplete="off" aria-describedby="lgt-key-one-help"><div class="form-text" id="lgt-key-one-help"></div></div><div class="col-md-6"><label class="form-label" id="lgt-key-two-label" for="lgt-key-two">AccessKey Secret</label><input id="lgt-key-two" type="password" class="form-control" required autocomplete="new-password" aria-describedby="lgt-key-two-help"><div class="form-text" id="lgt-key-two-help"></div></div><div class="col-md-4"><label class="form-label" for="lgt-free-quota">本周期免费字符额度</label><input id="lgt-free-quota" class="form-control" type="number" min="0" value="0"><div class="form-text">填 0 表示禁止派发。</div></div><div class="col-md-4"><label class="form-label" for="lgt-soft-cap">本地安全上限</label><input id="lgt-soft-cap" class="form-control" type="number" min="0" value="0"><div class="form-text">建议小于或等于免费额度。</div></div><div class="col-md-4"><label class="form-label" for="lgt-qps">每秒请求上限（QPS）</label><input id="lgt-qps" class="form-control" type="number" min="1" max="10" value="1"><div class="form-text">按服务商后台限额填写，建议从 1 开始。</div></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lgt-provider-enabled"><label class="form-check-label" for="lgt-provider-enabled">保存为已启用（仍需完成验证后才可派发）</label></div></div><div class="col-12"><button class="btn btn-primary" type="submit">加密保存 API 配置</button></div></form>
  </div></div></div><div class="col-12 col-xl-5"><div class="card"><div class="card-body"><h2 class="h5">已配置账户</h2><p class="form-text">验证只会向服务商发送一次小请求，费用会记入本地账本；系统不会自动验证或自动派发。</p><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>服务商</th><th>凭据</th><th>验证状态</th><th>派发状态</th><th>操作</th></tr></thead><tbody>@forelse($accounts as $account)<tr><td>{{ $providerLabels[$account['provider']] ?? $account['provider'] }}</td><td>{{ $account['credentials_configured'] ? '已配置' : '未配置' }}</td><td>{{ $account['verified_at'] ?: '未验证' }}</td><td>{{ $account['dispatch_enabled'] ? '已开启' : '已关闭' }}</td><td class="text-nowrap">@if(!$account['verified_at'])<button type="button" class="btn btn-sm btn-outline-primary lgt-verify-provider" data-id="{{ $account['id'] }}">验证</button>@else<button type="button" class="btn btn-sm btn-outline-secondary lgt-dispatch-provider" data-id="{{ $account['id'] }}" data-enabled="{{ $account['dispatch_enabled'] ? '0' : '1' }}">{{ $account['dispatch_enabled'] ? '关闭派发' : '开启派发' }}</button>@endif</td></tr>@empty<tr><td colspan="5" class="text-secondary">尚未配置翻译服务商账户。</td></tr>@endforelse</tbody></table></div></div></div></div></div></section>

  <section class="lgt-tab" data-lgt-panel="plan"><div class="card"><div class="card-body"><h2 class="h5">扫描可公开翻译内容</h2><p class="form-text">仅索引已启用商品、分类、页面及已发布的受支持装修模块。订单、客户资料、结账、支付、评论和在线聊天均不会被读取。</p><form id="lgt-scan-form" class="d-flex flex-wrap gap-3 align-items-center"><fieldset class="d-flex flex-wrap gap-3"><legend class="visually-hidden">内容范围</legend>@foreach(['product'=>'商品','category'=>'分类','page'=>'页面','builder'=>'已发布装修模块'] as $key=>$name)<div class="form-check"><input class="form-check-input" type="checkbox" name="scope" value="{{ $key }}" id="scan-{{ $key }}" checked><label class="form-check-label" for="scan-{{ $key }}">{{ $name }}</label></div>@endforeach</fieldset><button class="btn btn-outline-primary" type="submit">立即扫描</button></form><pre id="lgt-scan-result" class="bg-light border rounded p-3 mt-3 mb-0 small" hidden></pre></div></div><div class="card mt-3"><div class="card-body"><h2 class="h5">创建翻译任务</h2><p class="form-text">仅预览模式绝不会调用服务商。仅免费额度模式在没有单独完成验证并获准派发的账户时会安全暂停。</p><form id="lgt-plan-form" class="row g-3"><div class="col-md-6"><label class="form-label" for="lgt-plan-locales">目标语言</label><input id="lgt-plan-locales" class="form-control" value="{{ $enabledLocales }}" placeholder="fr, de, es" required><div class="form-text">填写已启用语言代码，并以英文逗号分隔。</div></div><div class="col-md-6"><label class="form-label">扫描范围</label><div class="d-flex gap-3 flex-wrap pt-2">@foreach(['product'=>'商品','category'=>'分类','page'=>'页面','builder'=>'装修模块'] as $key=>$name)<div class="form-check"><input class="form-check-input" type="checkbox" value="{{ $key }}" name="plan_scope" id="plan-{{ $key }}" checked><label class="form-check-label" for="plan-{{ $key }}">{{ $name }}</label></div>@endforeach</div></div><div class="col-12"><button class="btn btn-primary" type="submit">创建安全任务</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="library"><div class="card"><div class="card-body"><div class="d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h5 mb-1">翻译库</h2><p class="form-text mb-0">人工操作会进行乐观锁版本校验；已锁定的条目不会被机器翻译结果覆盖。</p></div><button id="lgt-load-library" class="btn btn-outline-primary" type="button">加载最新 100 条</button></div><div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>源内容</th><th>语言</th><th>状态</th><th>译文</th><th>审核</th></tr></thead><tbody id="lgt-library-items"><tr><td colspan="5" class="text-secondary">加载条目后即可审核与修订。</td></tr></tbody></table></div></div></div></section>

  <section class="lgt-tab" data-lgt-panel="setup"><div class="card mt-3"><div class="card-body"><h2 class="h5">展示语言设置</h2><form id="lgt-settings-form" class="row g-3"><div class="col-md-4"><label class="form-label" for="lgt-mode">运行模式</label><select id="lgt-mode" class="form-select"><option value="preview_only" @selected($settings['mode']==='preview_only')>仅预览，不调用 API</option><option value="free_only" @selected($settings['mode']==='free_only')>仅使用免费额度</option><option value="offline_dictionary" @selected($settings['mode']==='offline_dictionary')>仅使用已发布词典</option></select></div><div class="col-md-4"><label class="form-label" for="lgt-source">原始语言</label><input id="lgt-source" class="form-control" value="{{ $settings['source_locale'] }}" readonly aria-describedby="lgt-source-help"><div id="lgt-source-help" class="form-text">原始英文已锁定；插件仅替换前台展示文本。</div></div><div class="col-md-4"><label class="form-label" for="lgt-locales">启用的展示语言</label><input id="lgt-locales" class="form-control" value="{{ $enabledLocales }}" placeholder="fr, de, es"><div class="form-text">用逗号分隔，例如：fr, de, es。</div></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lgt-accept-language" @checked($settings['accept_language_enabled'])><label class="form-check-label" for="lgt-accept-language">仅在访客未手动选择且无可信国家规则时，使用浏览器语言偏好</label></div></div><div class="col-12"><button class="btn btn-primary" type="submit">保存语言设置</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="widget"><div class="card"><div class="card-body"><h2 class="h5">前台语言选择器</h2><p class="form-text">还需在装修模块中启用插件嵌入。选择器只会同源读取受限词典，且不会在购物车、结账、登录或账户页面加载。</p><form id="lgt-widget-form" class="row g-3"><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lgt-widget-enabled" @checked($widget['enabled'] ?? false)><label class="form-check-label" for="lgt-widget-enabled">启用前台语言选择器</label></div></div><div class="col-md-4"><label class="form-label" for="lgt-position">显示位置</label><select id="lgt-position" class="form-select"><option value="right" @selected(($widget['position']??'right')==='right')>右下角</option><option value="left" @selected(($widget['position']??'right')==='left')>左下角</option></select></div><div class="col-md-4"><label class="form-label" for="lgt-bottom">桌面端底部间距</label><input id="lgt-bottom" class="form-control" type="number" min="16" max="240" value="{{ $widget['bottom_offset'] ?? 84 }}"></div><div class="col-md-4"><label class="form-label" for="lgt-mobile-bottom">移动端底部间距</label><input id="lgt-mobile-bottom" class="form-control" type="number" min="16" max="260" value="{{ $widget['mobile_bottom_offset'] ?? 88 }}"></div><div class="col-md-4"><label class="form-label" for="lgt-theme">外观主题</label><select id="lgt-theme" class="form-select"><option value="system" @selected(($widget['theme']??'system')==='system')>跟随系统</option><option value="light" @selected(($widget['theme']??'system')==='light')>浅色</option><option value="dark" @selected(($widget['theme']??'system')==='dark')>深色</option></select></div><div class="col-12"><button class="btn btn-primary" type="submit">保存选择器设置</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="operations"><div class="card mb-3"><div class="card-body"><h2 class="h5">不可变发布版本</h2><form id="lgt-publish-form" class="d-flex flex-wrap align-items-end gap-2"><div><label class="form-label" for="lgt-publish-locale">语言代码</label><input id="lgt-publish-locale" class="form-control" placeholder="fr" required></div><button class="btn btn-primary" type="submit">发布已审核版本</button></form><div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>语言</th><th>版本</th><th>状态</th><th>发布时间</th><th>回滚</th></tr></thead><tbody>@forelse($publications as $publication)<tr><td>{{ $publication->locale }}</td><td>{{ $publication->revision }}</td><td>{{ $publicationStateLabels[$publication->state] ?? $publication->state }}</td><td>{{ $publication->published_at }}</td><td>@if(in_array($publication->state,['published','superseded'],true))<button type="button" class="btn btn-sm btn-outline-secondary lgt-rollback" data-id="{{ $publication->id }}" data-locale="{{ $publication->locale }}">恢复此版本</button>@endif</td></tr>@empty<tr><td colspan="5" class="text-secondary">尚未发布任何翻译版本。</td></tr>@endforelse</tbody></table></div></div></div><div class="card"><div class="card-body"><h2 class="h5">最近任务与安全状态</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>ID</th><th>状态</th><th>模式</th><th>进度</th><th>创建时间</th></tr></thead><tbody>@forelse($jobs as $job)<tr><td>{{ $job->public_id }}</td><td>{{ $jobStatusLabels[$job->status] ?? $job->status }}</td><td>{{ $modeLabels[$job->mode] ?? $job->mode }}</td><td>{{ $job->completed_items }}/{{ $job->total_items }}；失败 {{ $job->failed_items }}</td><td>{{ $job->created_at }}</td></tr>@empty<tr><td colspan="5" class="text-secondary">尚未创建翻译任务。</td></tr>@endforelse</tbody></table></div><pre class="bg-light border rounded p-3 mb-0 small" aria-label="技术诊断信息">{{ json_encode($diagnostics, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div></div></section>
  <section class="lgt-tab" data-lgt-panel="library"><div class="card mt-3"><div class="card-body"><h2 class="h5">人工修订</h2><p class="form-text">使用当前条目版本保存。每次修订都会形成可审计版本，也可锁定译文以阻止后续机器翻译覆盖。</p><form id="lgt-manual-form" class="row g-3"><div class="col-md-3"><label class="form-label" for="lgt-manual-id">翻译条目 ID</label><input id="lgt-manual-id" class="form-control" type="number" min="1" required readonly></div><div class="col-md-3"><label class="form-label" for="lgt-manual-version">当前版本</label><input id="lgt-manual-version" class="form-control" type="number" min="1" required readonly></div><div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input id="lgt-manual-lock" class="form-check-input" type="checkbox"><label class="form-check-label" for="lgt-manual-lock">锁定此人工译文</label></div></div><div class="col-12"><label class="form-label" for="lgt-manual-text">前台展示译文</label><textarea id="lgt-manual-text" class="form-control" rows="5" maxlength="100000" required></textarea><div class="form-text">V1 契约不接受或重建 HTML，仅保存纯文本展示内容。</div></div><div class="col-12"><button class="btn btn-primary" type="submit">保存人工修订</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="setup"><div class="card mt-3"><div class="card-body"><h2 class="h5">按系统国家/地区快捷启用语言</h2><p class="form-text">此列表直接读取“系统设置 → 国家/地区”中已启用的项目。多选后，系统会去重启用对应的展示语言，并为所选国家创建可信默认语言规则；不会删除任何已有手工配置。</p><div class="row g-3"><div class="col-lg-6"><label class="form-label" for="lgt-country-search">搜索已启用国家/地区</label><input id="lgt-country-search" class="form-control" type="search" placeholder="输入国家名称或 ISO 代码，例如 France、FR" autocomplete="off"><div class="form-text">仅显示插件支持推荐语言的国家/地区。</div></div><div class="col-lg-6"><div class="d-flex justify-content-between align-items-end gap-2 h-100"><p id="lgt-country-selection-summary" class="mb-0 text-secondary">正在加载系统国家/地区…</p><button id="lgt-country-clear" class="btn btn-sm btn-outline-secondary" type="button">清空选择</button></div></div><div class="col-12"><div id="lgt-country-options" class="lgt-country-picker border rounded p-2" aria-live="polite"></div><p id="lgt-country-empty" class="form-text mb-0" hidden>没有符合条件的已启用国家/地区，或这些国家暂没有受支持的推荐语言。</p></div><div class="col-12"><button id="lgt-apply-country-recommendations" class="btn btn-primary" type="button" disabled>应用所选国家的语言建议</button><div class="form-text">保存后会更新“启用的展示语言”和下方国家默认语言列表。访客手动选择的语言始终优先。</div></div></div><hr class="my-4"><h3 class="h6">单个国家/地区自定义覆盖</h3><p class="form-text">当系统推荐语言不符合您的运营计划时，可在此手动指定。该语言必须已在“展示语言设置”中启用。</p><form id="lgt-country-rule-form" class="row g-3"><div class="col-md-4"><label class="form-label" for="lgt-country">国家/地区代码</label><input id="lgt-country" class="form-control text-uppercase" maxlength="2" pattern="[A-Za-z]{2}" placeholder="FR" required></div><div class="col-md-5"><label class="form-label" for="lgt-country-locale">展示语言代码</label><input id="lgt-country-locale" class="form-control" maxlength="24" placeholder="fr" required></div><div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input id="lgt-country-enabled" class="form-check-input" type="checkbox" checked><label class="form-check-label" for="lgt-country-enabled">启用</label></div></div><div class="col-12"><button class="btn btn-outline-primary" type="submit">保存自定义国家语言</button></div></form><div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>国家/地区</th><th>语言</th><th>状态</th></tr></thead><tbody>@forelse($countryRules as $rule)<tr><td>{{ $rule->country }}</td><td>{{ $rule->locale }}</td><td>{{ $rule->enabled ? '已启用' : '已停用' }}</td></tr>@empty<tr><td colspan="3" class="text-secondary">尚未保存国家默认语言。</td></tr>@endforelse</tbody></table></div></div></div></section>

  <div id="lgt-status" class="lgt-status alert" role="status" aria-live="polite"></div>
</main>

<script>
(() => { <?php $lgtRoutes = ['settings' => admin_route('longos_global_translate.settings.save'), 'provider' => admin_route('longos_global_translate.providers.save'), 'scan' => admin_route('longos_global_translate.scan'), 'plan' => admin_route('longos_global_translate.jobs.plan'), 'translations' => admin_route('longos_global_translate.translations.index'), 'publish' => admin_route('longos_global_translate.publications.publish'), 'rollback' => rtrim(admin_route('longos_global_translate.index'), '/') . '/publications/']; ?>const routes=<?php echo json_encode($lgtRoutes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>; const csrf=document.querySelector('meta[name="csrf-token"]')?.content||''; const status=document.getElementById('lgt-status'); const inform=(message,error=false)=>{status.textContent=message;status.className=`lgt-status alert ${error?'alert-danger':'alert-success'}`;status.style.display='block'}; const api=async(url,method='GET',body)=>{const response=await fetch(url,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:body?JSON.stringify(body):undefined});const payload=await response.json().catch(()=>({}));if(!response.ok)throw new Error(payload.message||Object.values(payload.errors||{}).flat()[0]||'Request failed');return payload}; document.querySelectorAll('[data-lgt-tab]').forEach(button=>button.addEventListener('click',()=>{document.querySelectorAll('[data-lgt-tab]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('[data-lgt-panel]').forEach(panel=>panel.classList.toggle('active',panel.dataset.lgtPanel===button.dataset.lgtTab))})); const csv=value=>value.split(',').map(item=>item.trim()).filter(Boolean); const settingsPayload=()=>({mode:document.getElementById('lgt-mode').value,source_locale:document.getElementById('lgt-source').value,enabled_locales:csv(document.getElementById('lgt-locales').value),accept_language_enabled:document.getElementById('lgt-accept-language').checked,widget:{enabled:document.getElementById('lgt-widget-enabled').checked,position:document.getElementById('lgt-position').value,bottom_offset:Number(document.getElementById('lgt-bottom').value),mobile_bottom_offset:Number(document.getElementById('lgt-mobile-bottom').value),theme:document.getElementById('lgt-theme').value}}); document.getElementById('lgt-settings-form').addEventListener('submit',async event=>{event.preventDefault();try{await api(routes.settings,'PUT',settingsPayload());inform('Language policy saved.')}catch(error){inform(error.message,true)}});document.getElementById('lgt-widget-form').addEventListener('submit',async event=>{event.preventDefault();try{await api(routes.settings,'PUT',settingsPayload());inform('Storefront selector settings saved. Enable the app embed to display it.')}catch(error){inform(error.message,true)}});document.getElementById('lgt-provider-form').addEventListener('submit',async event=>{event.preventDefault();const provider=document.getElementById('lgt-provider').value,first=document.getElementById('lgt-key-one').value,second=document.getElementById('lgt-key-two').value,credentials=provider==='aliyun'?{access_key_id:first,access_key_secret:second}:provider==='volcengine'?{access_key_id:first,secret_access_key:second}:{appid:first,secret:second};try{const result=await api(routes.provider,'POST',{provider,billing_identity:document.getElementById('lgt-billing').value,free_quota:Number(document.getElementById('lgt-free-quota').value),soft_cap:Number(document.getElementById('lgt-soft-cap').value),qps_limit:Number(document.getElementById('lgt-qps').value),enabled:document.getElementById('lgt-provider-enabled').checked,credentials});event.target.reset();inform(result.notice)}catch(error){inform(error.message,true)}});document.getElementById('lgt-scan-form').addEventListener('submit',async event=>{event.preventDefault();const scope=[...event.currentTarget.querySelectorAll('input[name="scope"]:checked')].map(item=>item.value);try{const result=await api(routes.scan,'POST',{scope});const output=document.getElementById('lgt-scan-result');output.textContent=JSON.stringify(result.report,null,2);output.hidden=false;inform('Scan completed.')}catch(error){inform(error.message,true)}});document.getElementById('lgt-plan-form').addEventListener('submit',async event=>{event.preventDefault();const scope=[...event.currentTarget.querySelectorAll('input[name="plan_scope"]:checked')].map(item=>item.value);try{const result=await api(routes.plan,'POST',{locales:csv(document.getElementById('lgt-plan-locales').value),scope});inform(`Plan ${result.job.public_id||result.job.id} created safely.`)}catch(error){inform(error.message,true)}});document.getElementById('lgt-load-library').addEventListener('click',async()=>{try{const result=await api(routes.translations),body=document.getElementById('lgt-library-items');body.replaceChildren();result.items.forEach(item=>{const row=document.createElement('tr'),source=document.createElement('td'),locale=document.createElement('td'),state=document.createElement('td'),text=document.createElement('td'),actions=document.createElement('td'),approve=document.createElement('button');source.className=text.className='lgt-table-cell';source.textContent=`${item.kind} #${item.entity_id} · ${item.field}\n${item.source_text}`;locale.textContent=item.locale;state.textContent=item.status+(item.locked?' · locked':'');text.textContent=item.text||'—';approve.type='button';approve.className='btn btn-sm btn-outline-primary';approve.textContent='Approve';approve.disabled=!item.text||item.status==='approved';approve.addEventListener('click',async()=>{try{await api(`${routes.translations}/${item.id}/review`,'POST',{version:item.version,approved:true});inform('Translation approved; refresh the library to see the new version.')}catch(error){inform(error.message,true)}});actions.append(approve);row.append(source,locale,state,text,actions);body.append(row)});if(!result.items.length)body.innerHTML='<tr><td colspan="5" class="text-secondary">No translation rows yet.</td></tr>'}catch(error){inform(error.message,true)}});document.getElementById('lgt-publish-form').addEventListener('submit',async event=>{event.preventDefault();try{const result=await api(routes.publish,'POST',{locale:document.getElementById('lgt-publish-locale').value});inform(`Published immutable revision ${result.publication.revision}. Reload to view history.`)}catch(error){inform(error.message,true)}});document.querySelectorAll('.lgt-rollback').forEach(button=>button.addEventListener('click',async()=>{if(!confirm(`Restore publication ${button.dataset.id}? This creates a new immutable revision.`))return;try{const result=await api(`${routes.rollback}${button.dataset.id}/rollback`,'POST',{locale:button.dataset.locale});inform(`Restored as revision ${result.publication.revision}. Reload to view history.`)}catch(error){inform(error.message,true)}})); })();
</script>
<script>
(() => {
  const base = @json(rtrim(admin_route('longos_global_translate.index'), '/') . '/providers/');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const status = document.getElementById('lgt-status');
  const message = (text, error = false) => { status.textContent = text; status.className = `lgt-status alert ${error ? 'alert-danger' : 'alert-success'}`; status.style.display = 'block'; };
  const post = async (url, payload) => { const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify(payload)}); const body = await response.json().catch(()=>({})); if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] || '请求失败，请检查填写内容与网络连接后重试。'); return body; };
  document.querySelectorAll('.lgt-verify-provider').forEach(button => button.addEventListener('click', async () => {
    if (!confirm('是否向该服务商发送一次小型验证请求？该请求可能消耗免费额度，并会记录在本地账本中。')) return;
    try { const result = await post(`${base}${button.dataset.id}/verify`, {confirm_provider_charge:true}); message(result.notice); button.disabled = true; } catch (error) { message(error.message, true); }
  }));
  document.querySelectorAll('.lgt-dispatch-provider').forEach(button => button.addEventListener('click', async () => {
    const enabled = button.dataset.enabled === '1';
    if (enabled && !confirm('是否为仅免费额度任务开启服务商派发？系统仍会禁止产生超额付费。')) return;
    try { const result = await post(`${base}${button.dataset.id}/dispatch`, {enabled, confirm_dispatch: enabled}); message(result.notice || (enabled ? '已开启服务商派发。' : '已关闭服务商派发。')); button.disabled = true; } catch (error) { message(error.message, true); }
  }));
})();
</script>
<script>
(() => {
  const routes = {
    translations: @json(admin_route('longos_global_translate.translations.index')),
    update: @json(rtrim(admin_route('longos_global_translate.index'), '/') . '/translations/'),
    countryRule: @json(admin_route('longos_global_translate.country-rules.save')),
  };
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const status = document.getElementById('lgt-status');
  const inform = (text, error = false) => {
    status.textContent = text;
    status.className = `lgt-status alert ${error ? 'alert-danger' : 'alert-success'}`;
    status.style.display = 'block';
  };
  const api = async (url, method = 'GET', payload) => {
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
      body: payload === undefined ? undefined : JSON.stringify(payload),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] || '请求失败，请检查填写内容与网络连接后重试。');
    return body;
  };
  const manual = {
    id: document.getElementById('lgt-manual-id'),
    version: document.getElementById('lgt-manual-version'),
    text: document.getElementById('lgt-manual-text'),
    lock: document.getElementById('lgt-manual-lock'),
  };
  const selectForManualEdit = item => {
    manual.id.value = String(item.id);
    manual.version.value = String(item.version);
    manual.text.value = item.text || '';
    manual.lock.checked = Boolean(item.locked);
    manual.text.focus();
    inform(`已载入翻译条目 #${item.id}，可以开始人工修订。`);
  };
  const load = document.getElementById('lgt-load-library');
  load.addEventListener('click', async event => {
    // This capture listener replaces the lightweight initial renderer with an
    // editor-aware one while preserving a single, CSRF-protected data path.
    event.preventDefault();
    event.stopImmediatePropagation();
    try {
      const result = await api(routes.translations);
      const body = document.getElementById('lgt-library-items');
      body.replaceChildren();
      if (!result.items.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5; cell.className = 'text-secondary'; cell.textContent = '暂时没有可审核的翻译条目。';
        row.append(cell); body.append(row); return;
      }
      result.items.forEach(item => {
        const row = document.createElement('tr');
        const source = document.createElement('td');
        const locale = document.createElement('td');
        const state = document.createElement('td');
        const text = document.createElement('td');
        const actions = document.createElement('td');
        source.className = text.className = 'lgt-table-cell';
        const kindLabels = {product: '商品', category: '分类', page: '页面', builder: '装修模块'};
        const statusLabels = {pending: '待处理', queued: '排队中', translating: '翻译中', translated: '待审核', approved: '已审核', stale: '待更新', failed: '失败'};
        const qualityLabels = {SOURCE_ECHO: '疑似未翻译原文', REPEATED_OUTPUT: '疑似重复输出', LENGTH_OUTLIER: '长度异常', TRUNCATED_OUTPUT: '疑似截断', EMPTY_OUTPUT: '空译文'};
        source.textContent = `${kindLabels[item.kind] || item.kind} #${item.entity_id} · ${item.field}\n${item.source_text}`;
        locale.textContent = item.locale;
        state.textContent = (statusLabels[item.status] || item.status) + (item.quality_code ? ` · ${qualityLabels[item.quality_code] || item.quality_code}` : '') + (item.locked ? ' · 已锁定' : '');
        text.textContent = item.text || '—';
        const edit = document.createElement('button');
        edit.type = 'button'; edit.className = 'btn btn-sm btn-outline-secondary me-1'; edit.textContent = '修订';
        edit.addEventListener('click', () => selectForManualEdit(item));
        const approve = document.createElement('button');
        approve.type = 'button'; approve.className = 'btn btn-sm btn-outline-primary'; approve.textContent = '审核通过';
        approve.disabled = !item.text || item.status === 'approved';
        approve.addEventListener('click', async () => {
          try {
            await api(`${routes.update}${item.id}/review`, 'POST', {version: item.version, approved: true});
            inform('译文已审核通过；发布前请重新加载翻译库确认最新版本。');
          } catch (error) { inform(error.message, true); }
        });
        actions.append(edit, approve); row.append(source, locale, state, text, actions); body.append(row);
      });
    } catch (error) { inform(error.message, true); }
  }, true);
  document.getElementById('lgt-manual-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await api(`${routes.update}${manual.id.value}`, 'PUT', {
        version: Number(manual.version.value), text: manual.text.value, lock: manual.lock.checked,
      });
      manual.version.value = String(result.translation.version);
      manual.lock.checked = Boolean(result.translation.locked);
      inform('人工修订已保存。请审核确认后再发布新的不可变版本。');
    } catch (error) { inform(error.message, true); }
  });
  document.getElementById('lgt-country-rule-form').addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const result = await api(routes.countryRule, 'PUT', {
        country: document.getElementById('lgt-country').value.trim(),
        locale: document.getElementById('lgt-country-locale').value.trim(),
        enabled: document.getElementById('lgt-country-enabled').checked,
      });
      inform(`国家默认语言 ${result.rule.country} → ${result.rule.locale} 已保存。刷新页面后可查看最新列表。`);
    } catch (error) { inform(error.message, true); }
  });
})();
</script>
<script>
(() => {
  const status = document.getElementById('lgt-status');
  if (!status) return;

  const localizeMessage = value => {
    const exact = {
      'Language policy saved.': '语言策略已保存。',
      'Storefront selector settings saved. Enable the app embed to display it.': '前台语言选择器已保存。请同时在装修模块中启用插件嵌入。',
      'Scan completed.': '扫描已完成。',
      'Request failed': '请求失败，请检查填写内容与网络连接后重试。',
      'Translation approved; refresh the library to see the new version.': '译文已审核通过；请刷新翻译库查看最新版本。',
      'No translation rows yet.': '暂时没有可审核的翻译条目。',
    };
    if (exact[value]) return exact[value];
    let match = value.match(/^Plan (.+) created safely\.$/);
    if (match) return `翻译任务 ${match[1]} 已安全创建。`;
    match = value.match(/^Published immutable revision (.+)\. Reload to view history\.$/);
    if (match) return `不可变版本 ${match[1]} 已发布。刷新页面可查看历史记录。`;
    match = value.match(/^Restored as revision (.+)\. Reload to view history\.$/);
    if (match) return `已恢复为版本 ${match[1]}。刷新页面可查看历史记录。`;
    return value;
  };

  new MutationObserver(() => {
    const localized = localizeMessage(status.textContent.trim());
    if (localized !== status.textContent) status.textContent = localized;
  }).observe(status, {childList: true, characterData: true, subtree: true});

  const nativeConfirm = window.confirm.bind(window);
  window.confirm = value => nativeConfirm(
    String(value).replace(
      /^Restore publication (.+)\? This creates a new immutable revision\.$/,
      '确认恢复发布记录 $1 吗？此操作会创建新的不可变版本。',
    ),
  );
})();
</script>
<script>
(() => {
  const providerProfiles = @json($providerProfiles);
  const provider = document.getElementById('lgt-provider');
  const firstKey = document.getElementById('lgt-key-one');
  const secondKey = document.getElementById('lgt-key-two');
  const updateProviderProfile = (clearCredentials = false) => {
    const profile = providerProfiles[provider.value];
    if (!profile) return;
    const [first, second] = profile.credential_fields;
    document.getElementById('lgt-key-one-label').textContent = first.label;
    document.getElementById('lgt-key-two-label').textContent = second.label;
    document.getElementById('lgt-key-one-help').textContent = first.help;
    document.getElementById('lgt-key-two-help').textContent = second.help;
    document.getElementById('lgt-billing-help').textContent = profile.account_help;
    document.getElementById('lgt-provider-quota-help').textContent = profile.quota_help;
    firstKey.placeholder = first.placeholder;
    secondKey.placeholder = second.placeholder;
    firstKey.name = `credentials[${first.key}]`;
    secondKey.name = `credentials[${second.key}]`;
    if (clearCredentials) {
      firstKey.value = '';
      secondKey.value = '';
    }
  };
  provider.addEventListener('change', () => updateProviderProfile(true));
  document.getElementById('lgt-provider-form').addEventListener('reset', () => window.setTimeout(() => updateProviderProfile(), 0));
  updateProviderProfile();
})();
</script>
<script>
(() => {
  const countries = @json($countries);
  const applyUrl = @json(admin_route('longos_global_translate.country-rules.recommendations.apply'));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const search = document.getElementById('lgt-country-search');
  const options = document.getElementById('lgt-country-options');
  const summary = document.getElementById('lgt-country-selection-summary');
  const empty = document.getElementById('lgt-country-empty');
  const clear = document.getElementById('lgt-country-clear');
  const apply = document.getElementById('lgt-apply-country-recommendations');
  const status = document.getElementById('lgt-status');
  const selected = new Set();
  const selectedCountries = () => countries.filter(country => selected.has(country.code));
  const showStatus = (text, error = false) => {
    status.textContent = text;
    status.className = `lgt-status alert ${error ? 'alert-danger' : 'alert-success'}`;
    status.style.display = 'block';
  };
  const render = () => {
    const keyword = search.value.trim().toLocaleLowerCase();
    const visible = countries.filter(country => country.locale && `${country.name} ${country.code} ${country.locale} ${country.locale_label}`.toLocaleLowerCase().includes(keyword));
    options.replaceChildren();
    visible.forEach(country => {
      const label = document.createElement('label');
      const checkbox = document.createElement('input');
      const text = document.createElement('span');
      label.className = 'lgt-country-option';
      checkbox.type = 'checkbox'; checkbox.value = country.code; checkbox.checked = selected.has(country.code);
      checkbox.addEventListener('change', () => { checkbox.checked ? selected.add(country.code) : selected.delete(country.code); render(); });
      text.textContent = `${country.name}（${country.code}）`;
      const hint = document.createElement('small'); hint.textContent = `推荐：${country.locale_label}（${country.locale}）`;
      text.append(hint); label.append(checkbox, text); options.append(label);
    });
    const languageCount = new Set(selectedCountries().map(country => country.locale)).size;
    summary.textContent = selected.size === 0 ? `可选国家/地区：${countries.filter(country => country.locale).length} 个` : `已选择 ${selected.size} 个国家/地区，将启用 ${languageCount} 种去重语言`;
    empty.hidden = visible.length !== 0;
    apply.disabled = selected.size === 0;
  };
  search.addEventListener('input', render);
  clear.addEventListener('click', () => { selected.clear(); render(); search.focus(); });
  apply.addEventListener('click', async () => {
    const countryCodes = [...selected];
    if (countryCodes.length === 0) return;
    apply.disabled = true;
    apply.textContent = '正在保存联动设置…';
    try {
      const response = await fetch(applyUrl, {method: 'PUT', credentials: 'same-origin', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({countries: countryCodes})});
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.message || Object.values(payload.errors || {}).flat()[0] || '保存失败，请检查所选国家/地区后重试。');
      const locales = payload.settings?.enabled_locales || [];
      document.getElementById('lgt-locales').value = locales.join(', ');
      document.getElementById('lgt-plan-locales').value = locales.join(', ');
      showStatus(payload.notice || '国家/地区与展示语言已联动保存。');
      selected.clear(); render();
    } catch (error) {
      showStatus(error.message, true);
    } finally {
      apply.textContent = '应用所选国家的语言建议';
      apply.disabled = selected.size === 0;
    }
  });
  render();
})();
</script>
<script>
(() => {
  const form = document.getElementById('lgt-provider-form');
  const qps = document.getElementById('lgt-qps');
  if (!form || !qps || document.getElementById('lgt-failover-priority')) return;

  const column = document.createElement('div');
  column.className = 'col-md-4';
  column.innerHTML = '<label class="form-label" for="lgt-failover-priority">自动切换优先级</label><input id="lgt-failover-priority" class="form-control" type="number" min="1" max="1000" value="100" required><div class="form-text">数字越小越优先；本账号免费额度耗尽后自动切换到下一已验证账号。</div>';
  qps.closest('.col-md-4')?.insertAdjacentElement('afterend', column);

  // This document-capture handler replaces the legacy submit handler so
  // priority is part of the same encrypted credential save operation, never
  // a second partial update. It runs before the form's bubble-phase handler.
  document.addEventListener('submit', async event => {
    if (event.target !== form) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const provider = document.getElementById('lgt-provider').value;
    const first = document.getElementById('lgt-key-one').value;
    const second = document.getElementById('lgt-key-two').value;
    const credentials = provider === 'aliyun'
      ? {access_key_id: first, access_key_secret: second}
      : provider === 'volcengine'
        ? {access_key_id: first, secret_access_key: second}
        : {appid: first, secret: second};
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const endpoint = @json(admin_route('longos_global_translate.providers.save'));
    const status = document.getElementById('lgt-status');
    try {
      const response = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin',
        headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
        body: JSON.stringify({
          provider,
          billing_identity: document.getElementById('lgt-billing').value,
          free_quota: Number(document.getElementById('lgt-free-quota').value),
          soft_cap: Number(document.getElementById('lgt-soft-cap').value),
          qps_limit: Number(document.getElementById('lgt-qps').value),
          failover_priority: Number(document.getElementById('lgt-failover-priority').value),
          enabled: document.getElementById('lgt-provider-enabled').checked,
          credentials,
        }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] || '保存失败，请检查 API 凭据和额度设置。');
      form.reset();
      status.textContent = `${body.notice || 'API 配置已保存。'} 自动切换优先级：${body.account.failover_priority}。`;
      status.className = 'lgt-status alert alert-success';
      status.style.display = 'block';
    } catch (error) {
      status.textContent = error.message;
      status.className = 'lgt-status alert alert-danger';
      status.style.display = 'block';
    }
  }, true);
})();
</script>
@endsection
