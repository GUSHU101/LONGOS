@extends('admin::layouts.master')

@section('title', __('LongosInstantTranslate::instant.title'))

@section('content')
@php
  $enabled = array_values((array) $settings['enabled_languages']);
@endphp
<style>
.lit-admin{--ink:#172033;--muted:#667085;--line:#e2e7ef;--primary:#2458d3;max-width:1180px;margin:auto;color:#344054}.lit-admin h1,.lit-admin h2{color:var(--ink)}.lit-head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.15rem}.lit-head h1{margin:0 0 .3rem;font-size:1.75rem}.lit-head p{margin:0;color:var(--muted)}.lit-version{padding:.3rem .55rem;border-radius:99px;background:#eef3ff;color:#315fcd;font-size:.75rem;font-weight:700}.lit-alert{margin-bottom:1rem;padding:1rem 1.1rem;border:1px solid #fecaca;border-radius:.75rem;background:#fff7f7;color:#991b1b}.lit-alert strong{display:block;margin-bottom:.2rem}.lit-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:1rem}.lit-card{grid-column:span 6;border:1px solid var(--line);border-radius:.85rem;background:#fff}.lit-card.full{grid-column:span 12}.lit-card header{padding:1rem 1.1rem .45rem}.lit-card header h2{margin:0;font-size:1.05rem}.lit-card header p{margin:.25rem 0 0;color:var(--muted);font-size:.82rem;line-height:1.5}.lit-card__body{padding:.8rem 1.1rem 1.1rem}.lit-switch{display:flex;align-items:flex-start;gap:.65rem;margin:.7rem 0}.lit-switch input{margin-top:.25rem;accent-color:var(--primary)}.lit-switch strong,.lit-switch small{display:block}.lit-switch small{color:var(--muted);font-size:.78rem}.lit-engine{padding:.8rem;border-radius:.65rem;background:#f7f9fc;font-size:.82rem}.lit-engine code{overflow-wrap:anywhere}.lit-risk{margin:.7rem 0 0;color:#8a5b00;line-height:1.55}.lit-languages{display:grid;grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr));gap:.5rem}.lit-language{display:flex;align-items:center;gap:.5rem;min-height:44px;padding:.55rem .65rem;border:1px solid var(--line);border-radius:.6rem}.lit-language input{accent-color:var(--primary)}.lit-language small{display:block;color:var(--muted)}.lit-fields{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}.lit-field label{display:block;margin-bottom:.35rem;font-size:.82rem;font-weight:650}.lit-field input,.lit-field select{width:100%;min-height:42px;border:1px solid #ccd3df;border-radius:.5rem;padding:.5rem .65rem}.lit-preview{display:flex;align-items:center;justify-content:center;min-height:150px;margin-top:.9rem;border:1px dashed #bdc8d8;border-radius:.7rem;background:linear-gradient(145deg,#f7f9fc,#fff)}.lit-preview__button{display:inline-flex;align-items:center;gap:.5rem;min-height:48px;padding:.65rem .9rem;border:1px solid #ccd7ee;border-radius:99px;background:#fff;box-shadow:0 .6rem 1.6rem rgba(17,28,50,.14)}.lit-health{width:100%;border-collapse:collapse}.lit-health th,.lit-health td{padding:.65rem;border-bottom:1px solid var(--line);text-align:left;font-size:.82rem}.lit-health th{color:var(--muted);font-size:.75rem}.lit-state{display:inline-flex;padding:.18rem .45rem;border-radius:99px;background:#ecfdf3;color:#027a48;font-size:.72rem;font-weight:700}.lit-state.bad{background:#fef3f2;color:#b42318}.lit-note{padding:.8rem;border:1px solid #b9d5ff;border-radius:.65rem;background:#f5f9ff;color:#1849a9;font-size:.82rem;line-height:1.55}.lit-actions{display:flex;justify-content:flex-end;margin-top:1rem}.lit-actions .btn{min-width:10rem}.lit-client{margin-top:.75rem;padding:.75rem;border-radius:.6rem;background:#f7f9fc;font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;overflow-wrap:anywhere}@media(max-width:850px){.lit-card{grid-column:span 12}}@media(max-width:560px){.lit-fields{grid-template-columns:1fr}.lit-head{display:block}.lit-version{display:inline-block;margin-top:.7rem}}
.lit-metrics{display:grid;grid-template-columns:repeat(5,1fr);gap:.6rem}.lit-metric{padding:.75rem;border:1px solid var(--line);border-radius:.6rem;background:#fafbfc}.lit-metric strong{display:block;font-size:1.2rem;color:var(--ink)}.lit-provider-table{width:100%;border-collapse:collapse}.lit-provider-table th,.lit-provider-table td{padding:.7rem;border-bottom:1px solid var(--line);text-align:left;vertical-align:top;font-size:.8rem}.lit-inline{display:inline-flex;gap:.35rem;margin:.15rem}.lit-provider-fields{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}@media(max-width:850px){.lit-metrics{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.lit-provider-fields{grid-template-columns:1fr}}
</style>
<div class="lit-admin">
  <div class="lit-head">
    <div><h1>{{ __('LongosInstantTranslate::instant.title') }}</h1><p>{{ __('LongosInstantTranslate::instant.subtitle') }}</p></div>
    <span class="lit-version">v{{ \Plugin\LongosInstantTranslate\Services\InstantTranslateSettings::VERSION }}</span>
  </div>

  @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
  @if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  @unless($schemaReady)<div class="lit-alert" role="alert"><strong>翻译库迁移尚未完成</strong>等待 LONGOS 自动升级流程执行本插件迁移；在此之前不会向任何服务商发送请求。</div>@endunless

  <form method="post" action="{{ admin_route('longos_instant_translate.settings.update') }}">
    @csrf
    @method('PUT')
    <div class="lit-grid">
      <section class="lit-card">
        <header><h2>{{ __('LongosInstantTranslate::instant.runtime') }}</h2><p>{{ __('LongosInstantTranslate::instant.runtime_help') }}</p></header>
        <div class="lit-card__body">
          <label class="lit-switch"><input type="checkbox" name="runtime_enabled" value="1" @checked(old('runtime_enabled', $settings['runtime_enabled']))><span><strong>{{ __('LongosInstantTranslate::instant.runtime') }}</strong><small>Public catalog and content pages only</small></span></label>
          <div class="lit-note"><strong>{{ __('LongosInstantTranslate::instant.privacy') }}</strong><br>{{ __('LongosInstantTranslate::instant.privacy_body') }}<br><em>{{ __('LongosInstantTranslate::instant.seo') }}</em></div>
        </div>
      </section>

      <section class="lit-card">
        <header><h2>{{ __('LongosInstantTranslate::instant.engine') }}</h2></header>
        <div class="lit-card__body">
          <div class="lit-engine"><strong>xnx3/translate {{ $engine['version'] }}</strong><br>channel: <code>{{ $engine['channel'] }}</code><br>commit: <code>{{ $engine['commit'] }}</code></div>
          <p class="lit-risk">{{ __('LongosInstantTranslate::instant.engine_risk') }}</p>
        </div>
      </section>

      <section class="lit-card">
        <header><h2>{{ __('LongosInstantTranslate::instant.automatic') }}</h2><p>Manual visitor choice always has the highest priority.</p></header>
        <div class="lit-card__body">
          <label class="lit-switch"><input type="checkbox" name="auto_country" value="1" @checked(old('auto_country', $settings['auto_country']))><span><strong>{{ __('LongosInstantTranslate::instant.country') }}</strong><small>Untrusted CF-IPCountry headers are rejected.</small></span></label>
          <label class="lit-switch"><input type="checkbox" name="browser_fallback" value="1" @checked(old('browser_fallback', $settings['browser_fallback']))><span><strong>{{ __('LongosInstantTranslate::instant.browser') }}</strong><small>Unsupported results fall back to the original English page.</small></span></label>
        </div>
      </section>

      <section class="lit-card">
        <header><h2>{{ __('LongosInstantTranslate::instant.widget') }}</h2></header>
        <div class="lit-card__body">
          <div class="lit-fields">
            <div class="lit-field"><label for="lit-position">{{ __('LongosInstantTranslate::instant.position') }}</label><select id="lit-position" name="position"><option value="right" @selected(old('position',$settings['position'])==='right')>{{ __('LongosInstantTranslate::instant.right') }}</option><option value="left" @selected(old('position',$settings['position'])==='left')>{{ __('LongosInstantTranslate::instant.left') }}</option></select></div>
            <div class="lit-field"><label for="lit-theme">{{ __('LongosInstantTranslate::instant.theme') }}</label><select id="lit-theme" name="theme">@foreach(['system','light','dark'] as $theme)<option value="{{ $theme }}" @selected(old('theme',$settings['theme'])===$theme)>{{ __('LongosInstantTranslate::instant.'.$theme) }}</option>@endforeach</select></div>
            <div class="lit-field"><label for="lit-bottom">{{ __('LongosInstantTranslate::instant.bottom_offset') }}</label><input id="lit-bottom" type="number" name="bottom_offset" min="16" max="240" value="{{ old('bottom_offset',$settings['bottom_offset']) }}"></div>
            <div class="lit-field"><label for="lit-mobile">{{ __('LongosInstantTranslate::instant.mobile_offset') }}</label><input id="lit-mobile" type="number" name="mobile_bottom_offset" min="16" max="260" value="{{ old('mobile_bottom_offset',$settings['mobile_bottom_offset']) }}"></div>
            <div class="lit-field"><label for="lit-accent">{{ __('LongosInstantTranslate::instant.accent') }}</label><input id="lit-accent" type="color" name="accent_color" value="{{ old('accent_color',$settings['accent_color']) }}"></div>
            <div class="lit-field"><label for="lit-label">{{ __('LongosInstantTranslate::instant.label_mode') }}</label><select id="lit-label" name="label_mode"><option value="short" @selected(old('label_mode',$settings['label_mode'])==='short')>{{ __('LongosInstantTranslate::instant.short') }}</option><option value="name" @selected(old('label_mode',$settings['label_mode'])==='name')>{{ __('LongosInstantTranslate::instant.name') }}</option></select></div>
          </div>
          <div class="lit-preview" aria-label="{{ __('LongosInstantTranslate::instant.preview') }}"><span class="lit-preview__button"><span aria-hidden="true">◎</span><b id="lit-preview-label">EN</b></span></div>
        </div>
      </section>

      <section class="lit-card full">
        <header><h2>{{ __('LongosInstantTranslate::instant.languages') }}</h2><p>{{ __('LongosInstantTranslate::instant.languages_help') }}</p></header>
        <div class="lit-card__body"><div class="lit-languages">
          @foreach($languages as $id => $language)
            <label class="lit-language"><input type="checkbox" name="enabled_languages[]" value="{{ $id }}" @checked(in_array($id,old('enabled_languages',$enabled),true)) @disabled($id==='english')><span>{{ $language['native'] }}<small>{{ $language['short'] }}</small></span></label>
            @if($id==='english')<input type="hidden" name="enabled_languages[]" value="english">@endif
          @endforeach
        </div></div>
      </section>

      <section class="lit-card full">
        <header><h2>本地翻译库</h2><p>先读取租户本地译文；未命中时才由 xnx3 在访客浏览器即时翻译。后台每次只处理一条，失败自动退避，不阻塞店面。</p></header>
        <div class="lit-card__body">
          <div class="lit-metrics">
            @foreach(['total'=>'总条目','ready'=>'可用','pending'=>'待处理','review'=>'待复核','failed'=>'失败'] as $key=>$metricLabel)<div class="lit-metric"><strong>{{ number_format($libraryStats[$key]) }}</strong><small>{{ $metricLabel }}</small></div>@endforeach
          </div>
          <label class="lit-switch"><input type="checkbox" name="library_enabled" value="1" @checked(old('library_enabled',$settings['library_enabled']))><span><strong>前台优先使用本地库</strong><small>浏览器只向本站批量查询当前页面文本，不会触发建库。</small></span></label>
          <label class="lit-switch"><input type="checkbox" name="library_build_enabled" value="1" @checked(old('library_build_enabled',$settings['library_build_enabled']))><span><strong>允许后台慢速建库</strong><small>必须同时存在已验证并开启派发的服务商账户。</small></span></label>
          <div class="lit-field" style="max-width:18rem"><label for="lit-build-interval">每条间隔（秒）</label><input id="lit-build-interval" type="number" name="build_interval_seconds" min="5" max="3600" value="{{ old('build_interval_seconds',$settings['build_interval_seconds']) }}"></div>
        </div>
      </section>

      <section class="lit-card full">
        <header><h2>{{ __('LongosInstantTranslate::instant.health') }}</h2></header>
        <div class="lit-card__body">
          <div class="table-responsive"><table class="lit-health"><thead><tr><th>Asset</th><th>{{ __('LongosInstantTranslate::instant.source_asset') }}</th><th>{{ __('LongosInstantTranslate::instant.published_asset') }}</th><th>{{ __('LongosInstantTranslate::instant.current_asset') }}</th></tr></thead><tbody>
          @foreach($diagnostics['assets'] as $file => $asset)<tr><td><code>{{ $file }}</code></td>@foreach(['source','published','current'] as $key)<td><span class="lit-state {{ $asset[$key] ? '' : 'bad' }}">{{ $asset[$key] ? __('LongosInstantTranslate::instant.ready') : ($key==='current' ? __('LongosInstantTranslate::instant.stale') : __('LongosInstantTranslate::instant.missing')) }}</span></td>@endforeach</tr>@endforeach
          </tbody></table></div>
          <p class="mt-3 mb-1"><span class="lit-state {{ $diagnostics['vendor_hash_verified'] ? '' : 'bad' }}">SHA-256</span> {{ __($diagnostics['vendor_hash_verified'] ? 'LongosInstantTranslate::instant.vendor_verified' : 'LongosInstantTranslate::instant.vendor_unverified') }}</p>
          <p class="mb-1"><span class="lit-state {{ $diagnostics['cloudflare_request'] ? '' : 'bad' }}">Cloudflare</span> {{ __($diagnostics['cloudflare_request'] ? 'LongosInstantTranslate::instant.cloudflare_verified' : 'LongosInstantTranslate::instant.cloudflare_unverified') }} @if($diagnostics['verified_country'])({{ $diagnostics['verified_country'] }})@endif</p>
          <p class="mb-1"><span class="lit-state {{ $schemaReady ? '' : 'bad' }}">Library</span> {{ $schemaReady ? 'Tenant-local library schema is ready.' : 'Migration pending.' }}</p>
          <h3 class="h6 mt-3">{{ __('LongosInstantTranslate::instant.browser_health') }}</h3><div id="lit-client-health" class="lit-client" data-health-key="{{ $clientHealthKey }}">{{ __('LongosInstantTranslate::instant.browser_health_empty') }}</div>
        </div>
      </section>
    </div>
    <div class="lit-actions"><button class="btn btn-primary" type="submit">{{ __('LongosInstantTranslate::instant.save') }}</button></div>
  </form>

  <div class="lit-grid mt-4">
    <section class="lit-card full">
      <header><h2>翻译服务商</h2><p>密钥只在服务端加密保存，不回显、不写日志。优先级数字越小越先尝试；硅基流动免费状态以官方实时价格页为准。</p></header>
      <div class="lit-card__body">
        <div class="table-responsive"><table class="lit-provider-table"><thead><tr><th>优先级</th><th>服务商 / 账户</th><th>本月本地账本</th><th>验证</th><th>派发</th><th>最近状态</th><th>操作</th></tr></thead><tbody>
        @forelse($providerAccounts as $account)
          <tr><td>{{ $account['priority'] }}</td><td><strong>{{ $providerProfiles[$account['provider']]['label'] ?? $account['provider'] }}</strong><br><small>{{ $account['label'] }}</small></td><td><small>已用 {{ number_format($account['usage']['committed']) }} · 预留 {{ number_format($account['usage']['reserved']) }} · 不确定 {{ number_format($account['usage']['uncertain']) }}<br>请求 {{ number_format($account['usage']['requests']) }} · 上限 {{ $account['provider']==='siliconflow' ? '按 RPM/TPM' : number_format($account['monthly_character_limit']) }}</small></td><td>{{ $account['verified_at'] ? '已验证' : '待验证' }}</td><td>{{ $account['dispatch_enabled'] ? '开启' : '关闭' }}</td><td><code>{{ $account['last_error_code'] ?: 'OK' }}</code></td><td>
            <form class="lit-inline" method="post" action="{{ admin_route('longos_instant_translate.providers.verify',['account'=>$account['id']]) }}">@csrf<button class="btn btn-sm btn-outline-primary" type="submit">验证</button></form>
            <form class="lit-inline" method="post" action="{{ admin_route('longos_instant_translate.providers.dispatch',['account'=>$account['id']]) }}">@csrf @method('PUT')<input type="hidden" name="enabled" value="{{ $account['dispatch_enabled'] ? 0 : 1 }}"><button class="btn btn-sm btn-outline-secondary" type="submit">{{ $account['dispatch_enabled'] ? '暂停' : '开启派发' }}</button></form>
          </td></tr>
        @empty<tr><td colspan="7">尚未配置服务商账户。</td></tr>@endforelse
        </tbody></table></div>

        <details class="mt-3" @if(!$providerAccounts) open @endif><summary><strong>添加或轮换 API 账户</strong></summary>
          <form class="mt-3" method="post" action="{{ admin_route('longos_instant_translate.providers.store') }}">@csrf
            <div class="lit-provider-fields">
              <div class="lit-field"><label for="lit-provider">服务商</label><select id="lit-provider" name="provider">@foreach($providerProfiles as $code=>$profile)<option value="{{ $code }}">{{ $profile['label'] }}</option>@endforeach</select></div>
              <div class="lit-field"><label for="lit-provider-label">账户标识</label><input id="lit-provider-label" name="label" maxlength="64" required placeholder="例如：生产环境主账号"></div>
              <div id="lit-credential-fields" style="display:contents"></div>
              <div class="lit-field"><label>优先级</label><input type="number" name="priority" min="1" max="1000" value="100" required></div>
              <div class="lit-field"><label>每秒请求上限</label><input type="number" name="qps_limit" min="1" max="10" value="1" required></div>
              <div class="lit-field"><label>RPM 上限</label><input type="number" name="rpm_limit" min="1" max="10000" value="60" required></div>
              <div class="lit-field"><label>TPM 上限</label><input type="number" name="tpm_limit" min="1" max="5000000" value="80000" required></div>
              <div class="lit-field"><label>本地月字符上限（非硅基流动）</label><input type="number" name="monthly_character_limit" min="0" value="0"><small>0 表示禁止非硅基流动服务商消耗额度。</small></div>
              <label class="lit-switch"><input type="checkbox" name="enabled" value="1" checked><span><strong>启用账户</strong><small>保存后仍需手动验证和开启派发。</small></span></label>
            </div>
            <button class="btn btn-primary mt-3" type="submit">加密保存</button>
          </form>
        </details>
      </div>
    </section>

    <section class="lit-card full">
      <header><h2>扫描与慢速建库</h2><p>只扫描公开商品、分类、页面、已发布装修文案和代码内静态界面文本；不会读取客户、订单、地址或支付数据。</p></header>
      <div class="lit-card__body">
        <form class="lit-inline" method="post" action="{{ admin_route('longos_instant_translate.library.scan') }}">@csrf<button class="btn btn-outline-primary" type="submit" @disabled(!$schemaReady)>扫描公开内容</button></form>
        <form class="lit-inline" method="post" action="{{ admin_route('longos_instant_translate.library.process-one') }}">@csrf<button class="btn btn-outline-secondary" type="submit" @disabled(!$schemaReady)>立即处理一条</button></form>
      </div>
    </section>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
  var accent=document.getElementById('lit-accent'), label=document.getElementById('lit-label'), preview=document.querySelector('.lit-preview__button'), previewLabel=document.getElementById('lit-preview-label');
  function refreshPreview(){preview.style.color=accent.value;preview.style.borderColor=accent.value;previewLabel.textContent=label.value==='name'?'English':'EN';}
  accent.addEventListener('input',refreshPreview);label.addEventListener('change',refreshPreview);refreshPreview();
  var health=document.getElementById('lit-client-health');try{var value=localStorage.getItem(health.dataset.healthKey);if(value){health.textContent=JSON.stringify(JSON.parse(value),null,2);}}catch(error){}
  var profiles=@json($providerProfiles),provider=document.getElementById('lit-provider'),fields=document.getElementById('lit-credential-fields');
  function renderCredentials(){var profile=profiles[provider.value]||{fields:[]};fields.textContent='';profile.fields.forEach(function(field){var wrap=document.createElement('div');wrap.className='lit-field';var fieldLabel=document.createElement('label');fieldLabel.textContent=field.label;var input;if(field.type==='select'){input=document.createElement('select');Object.entries(profile.models||{}).forEach(function(entry){var option=document.createElement('option');option.value=entry[0];option.textContent=entry[1];input.appendChild(option);});}else{input=document.createElement('input');input.type=field.type||'text';input.autocomplete=field.type==='password'?'new-password':'off';}input.name='credentials['+field.key+']';input.required=true;if(field.default)input.value=field.default;wrap.append(fieldLabel,input);fields.appendChild(wrap);});}
  provider.addEventListener('change',renderCredentials);renderCredentials();
});
</script>
@endsection
