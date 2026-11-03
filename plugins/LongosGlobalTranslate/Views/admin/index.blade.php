@extends('admin::layouts.master')

@section('title', __('LongosGlobalTranslate::translate.title'))

@section('content')
@php
  $enabledLocales = implode(', ', (array) ($settings['enabled_locales'] ?? []));
  $widget = (array) ($settings['widget'] ?? []);
@endphp
<style>
  .lgt-admin{max-width:1440px;margin:auto}.lgt-admin .nav-pills{gap:.45rem;overflow-x:auto;flex-wrap:nowrap;padding-bottom:.35rem}.lgt-admin .nav-link{white-space:nowrap;border:1px solid #e9ecef;color:#344054;background:#fff}.lgt-admin .nav-link.active{border-color:#155eef;background:#155eef;color:#fff}.lgt-admin .lgt-tab{display:none}.lgt-admin .lgt-tab.active{display:block}.lgt-admin .lgt-stat{min-height:112px;border:1px solid #e9ecef;border-radius:.8rem;background:#fff}.lgt-admin .lgt-status{position:sticky;bottom:1rem;z-index:4;display:none;max-width:720px;margin:1rem auto 0;box-shadow:0 .5rem 1.5rem rgba(16,24,40,.16)}.lgt-admin .lgt-table-cell{max-width:25rem;overflow-wrap:anywhere;white-space:pre-wrap}.lgt-admin .form-text{color:#667085}@media(max-width:767px){.lgt-admin .card-body{padding:1rem}.lgt-admin .lgt-stat{min-height:auto}}
</style>
<main class="lgt-admin py-3" aria-labelledby="lgt-title">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
    <div><h1 id="lgt-title" class="h3 mb-1">{{ __('LongosGlobalTranslate::translate.title') }}</h1><p class="text-secondary mb-0">{{ __('LongosGlobalTranslate::translate.preview') }}</p></div>
    <span class="badge text-bg-light border px-3 py-2">{{ __('LongosGlobalTranslate::translate.mode') }}: {{ $settings['mode'] }}</span>
  </div>
  <nav class="nav nav-pills mb-4" aria-label="Translation workspace">
    <button class="nav-link active" type="button" data-lgt-tab="overview">1. Overview</button><button class="nav-link" type="button" data-lgt-tab="providers">2. Providers</button><button class="nav-link" type="button" data-lgt-tab="plan">3. Scan & plan</button><button class="nav-link" type="button" data-lgt-tab="library">4. Library</button><button class="nav-link" type="button" data-lgt-tab="languages">5. Languages</button><button class="nav-link" type="button" data-lgt-tab="widget">6. Widget</button><button class="nav-link" type="button" data-lgt-tab="operations">7. Operations</button>
  </nav>

  <section class="lgt-tab active" data-lgt-panel="overview"><div class="row g-3">
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">Presentation mode</div><strong class="d-block fs-5 mt-2">{{ $diagnostics['mode'] }}</strong><span class="small text-secondary">Core locale untouched</span></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">Approved entries</div><strong class="d-block fs-5 mt-2">{{ (int) ($latest_coverage['approved'] ?? 0) }}</strong><span class="small text-secondary">Only approved rows publish</span></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">Stale entries</div><strong class="d-block fs-5 mt-2">{{ (int) ($latest_coverage['stale'] ?? 0) }}</strong><span class="small text-secondary">Source changed after translation</span></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="lgt-stat p-3"><div class="small text-secondary">Provider dispatch</div><strong class="d-block fs-5 mt-2">Off by default</strong><span class="small text-secondary">Paid cap is permanently 0 in V1</span></div></div>
  </div><div class="card mt-3"><div class="card-body"><h2 class="h5">Safe publication flow</h2><ol class="mb-0 ps-3"><li>Scan public supported fields only.</li><li>Review or manually edit each target entry.</li><li>Publish an immutable revision per language.</li><li>Roll back by creating a new revision from a snapshot.</li></ol></div></div></section>

  <section class="lgt-tab" data-lgt-panel="providers"><div class="row g-3"><div class="col-12 col-xl-7"><div class="card"><div class="card-body"><h2 class="h5">Provider account</h2><p class="form-text">Secrets are encrypted at rest and never returned. Saving credentials resets verification and disables dispatch.</p>
  <form id="lgt-provider-form" class="row g-3" novalidate><div class="col-md-4"><label class="form-label" for="lgt-provider">Provider</label><select id="lgt-provider" class="form-select"><option value="aliyun">Alibaba Cloud</option><option value="volcengine">Volcengine</option><option value="baidu">Baidu</option></select></div><div class="col-md-8"><label class="form-label" for="lgt-billing">Billing identity</label><input id="lgt-billing" class="form-control" required maxlength="255" autocomplete="off"><div class="form-text">A tenant-local label which is hashed before storage.</div></div><div class="col-md-6"><label class="form-label" for="lgt-key-one">Access key / App ID</label><input id="lgt-key-one" class="form-control" required autocomplete="off"></div><div class="col-md-6"><label class="form-label" for="lgt-key-two">Secret</label><input id="lgt-key-two" type="password" class="form-control" required autocomplete="new-password"></div><div class="col-md-4"><label class="form-label" for="lgt-free-quota">Free quota</label><input id="lgt-free-quota" class="form-control" type="number" min="0" value="0"></div><div class="col-md-4"><label class="form-label" for="lgt-soft-cap">Soft cap</label><input id="lgt-soft-cap" class="form-control" type="number" min="0" value="0"></div><div class="col-md-4"><label class="form-label" for="lgt-qps">QPS limit</label><input id="lgt-qps" class="form-control" type="number" min="1" max="10" value="1"></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lgt-provider-enabled"><label class="form-check-label" for="lgt-provider-enabled">Store as enabled (dispatch stays off until controlled verification succeeds)</label></div></div><div class="col-12"><button class="btn btn-primary" type="submit">Encrypt and save provider account</button></div></form>
  </div></div></div><div class="col-12 col-xl-5"><div class="card"><div class="card-body"><h2 class="h5">Configured accounts</h2><p class="form-text">Verification sends exactly one small provider request and records its cost in the local ledger. It is never automatic.</p><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Provider</th><th>Credential</th><th>Verified</th><th>Dispatch</th><th>Action</th></tr></thead><tbody>@forelse($accounts as $account)<tr><td>{{ $account['provider'] }}</td><td>{{ $account['credentials_configured'] ? 'Configured' : 'Missing' }}</td><td>{{ $account['verified_at'] ?: 'Not verified' }}</td><td>{{ $account['dispatch_enabled'] ? 'On' : 'Off' }}</td><td class="text-nowrap">@if(!$account['verified_at'])<button type="button" class="btn btn-sm btn-outline-primary lgt-verify-provider" data-id="{{ $account['id'] }}">Verify</button>@else<button type="button" class="btn btn-sm btn-outline-secondary lgt-dispatch-provider" data-id="{{ $account['id'] }}" data-enabled="{{ $account['dispatch_enabled'] ? '0' : '1' }}">{{ $account['dispatch_enabled'] ? 'Disable' : 'Enable' }}</button>@endif</td></tr>@empty<tr><td colspan="5" class="text-secondary">No provider account configured.</td></tr>@endforelse</tbody></table></div></div></div></div></div></section>

  <section class="lgt-tab" data-lgt-panel="plan"><div class="card"><div class="card-body"><h2 class="h5">Scan public source content</h2><p class="form-text">Only active products, categories, pages and supported published builder modules are indexed. Orders, customer data, checkout, payments, reviews and chat are excluded.</p><form id="lgt-scan-form" class="d-flex flex-wrap gap-3 align-items-center"><fieldset class="d-flex flex-wrap gap-3"><legend class="visually-hidden">Content sources</legend>@foreach(['product'=>'Products','category'=>'Categories','page'=>'Pages','builder'=>'Published builder modules'] as $key=>$name)<div class="form-check"><input class="form-check-input" type="checkbox" name="scope" value="{{ $key }}" id="scan-{{ $key }}" checked><label class="form-check-label" for="scan-{{ $key }}">{{ $name }}</label></div>@endforeach</fieldset><button class="btn btn-outline-primary" type="submit">Scan now</button></form><pre id="lgt-scan-result" class="bg-light border rounded p-3 mt-3 mb-0 small" hidden></pre></div></div><div class="card mt-3"><div class="card-body"><h2 class="h5">Create translation plan</h2><p class="form-text">Preview-only plans never call a provider. Free-only queues pause safely unless a separately verified, dispatch-authorized account exists.</p><form id="lgt-plan-form" class="row g-3"><div class="col-md-6"><label class="form-label" for="lgt-plan-locales">Target locales</label><input id="lgt-plan-locales" class="form-control" value="{{ $enabledLocales }}" placeholder="fr, de, es" required><div class="form-text">Comma-separated enabled locales.</div></div><div class="col-md-6"><label class="form-label">Source scope</label><div class="d-flex gap-3 flex-wrap pt-2">@foreach(['product'=>'Products','category'=>'Categories','page'=>'Pages','builder'=>'Builder'] as $key=>$name)<div class="form-check"><input class="form-check-input" type="checkbox" value="{{ $key }}" name="plan_scope" id="plan-{{ $key }}" checked><label class="form-check-label" for="plan-{{ $key }}">{{ $name }}</label></div>@endforeach</div></div><div class="col-12"><button class="btn btn-primary" type="submit">Create safe plan</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="library"><div class="card"><div class="card-body"><div class="d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h5 mb-1">Translation library</h2><p class="form-text mb-0">Human actions use optimistic version checks. Locked rows cannot be overwritten by a machine result.</p></div><button id="lgt-load-library" class="btn btn-outline-primary" type="button">Load latest 100</button></div><div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>Source</th><th>Locale</th><th>Status</th><th>Translation</th><th>Review</th></tr></thead><tbody id="lgt-library-items"><tr><td colspan="5" class="text-secondary">Load entries to review them.</td></tr></tbody></table></div></div></div></section>

  <section class="lgt-tab" data-lgt-panel="languages"><div class="card"><div class="card-body"><h2 class="h5">Language policy</h2><form id="lgt-settings-form" class="row g-3"><div class="col-md-4"><label class="form-label" for="lgt-mode">Mode</label><select id="lgt-mode" class="form-select"><option value="preview_only" @selected($settings['mode']==='preview_only')>Preview only</option><option value="free_only" @selected($settings['mode']==='free_only')>Free quota only</option><option value="offline_dictionary" @selected($settings['mode']==='offline_dictionary')>Offline dictionary</option></select></div><div class="col-md-4"><label class="form-label" for="lgt-source">Original language</label><input id="lgt-source" class="form-control" value="{{ $settings['source_locale'] }}" readonly aria-describedby="lgt-source-help"><div id="lgt-source-help" class="form-text">English source is locked. This plugin changes presentation text only.</div></div><div class="col-md-4"><label class="form-label" for="lgt-locales">Enabled presentation languages</label><input id="lgt-locales" class="form-control" value="{{ $enabledLocales }}" placeholder="fr, de, es"></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lgt-accept-language" @checked($settings['accept_language_enabled'])><label class="form-check-label" for="lgt-accept-language">Use browser Accept-Language only after manual choice and trusted country rule are unavailable</label></div></div><div class="col-12"><button class="btn btn-primary" type="submit">Save language policy</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="widget"><div class="card"><div class="card-body"><h2 class="h5">Storefront language selector</h2><p class="form-text">Enable the page-builder app embed too. The selector only makes bounded same-origin dictionary reads and never mounts on cart, checkout, sign-in or account routes.</p><form id="lgt-widget-form" class="row g-3"><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="lgt-widget-enabled" @checked($widget['enabled'] ?? false)><label class="form-check-label" for="lgt-widget-enabled">Enable storefront selector</label></div></div><div class="col-md-4"><label class="form-label" for="lgt-position">Position</label><select id="lgt-position" class="form-select"><option value="right" @selected(($widget['position']??'right')==='right')>Bottom right</option><option value="left" @selected(($widget['position']??'right')==='left')>Bottom left</option></select></div><div class="col-md-4"><label class="form-label" for="lgt-bottom">Desktop offset</label><input id="lgt-bottom" class="form-control" type="number" min="16" max="240" value="{{ $widget['bottom_offset'] ?? 84 }}"></div><div class="col-md-4"><label class="form-label" for="lgt-mobile-bottom">Mobile offset</label><input id="lgt-mobile-bottom" class="form-control" type="number" min="16" max="260" value="{{ $widget['mobile_bottom_offset'] ?? 88 }}"></div><div class="col-md-4"><label class="form-label" for="lgt-theme">Theme</label><select id="lgt-theme" class="form-select"><option value="system" @selected(($widget['theme']??'system')==='system')>System</option><option value="light" @selected(($widget['theme']??'system')==='light')>Light</option><option value="dark" @selected(($widget['theme']??'system')==='dark')>Dark</option></select></div><div class="col-12"><button class="btn btn-primary" type="submit">Save selector settings</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="operations"><div class="card mb-3"><div class="card-body"><h2 class="h5">Immutable publications</h2><form id="lgt-publish-form" class="d-flex flex-wrap align-items-end gap-2"><div><label class="form-label" for="lgt-publish-locale">Locale</label><input id="lgt-publish-locale" class="form-control" placeholder="fr" required></div><button class="btn btn-primary" type="submit">Publish approved revision</button></form><div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Locale</th><th>Revision</th><th>State</th><th>Published</th><th>Rollback</th></tr></thead><tbody>@forelse($publications as $publication)<tr><td>{{ $publication->locale }}</td><td>{{ $publication->revision }}</td><td>{{ $publication->state }}</td><td>{{ $publication->published_at }}</td><td>@if(in_array($publication->state,['published','superseded'],true))<button type="button" class="btn btn-sm btn-outline-secondary lgt-rollback" data-id="{{ $publication->id }}" data-locale="{{ $publication->locale }}">Restore</button>@endif</td></tr>@empty<tr><td colspan="5" class="text-secondary">No publication yet.</td></tr>@endforelse</tbody></table></div></div></div><div class="card"><div class="card-body"><h2 class="h5">Recent jobs and safety state</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>ID</th><th>Status</th><th>Mode</th><th>Progress</th><th>Created</th></tr></thead><tbody>@forelse($jobs as $job)<tr><td>{{ $job->public_id }}</td><td>{{ $job->status }}</td><td>{{ $job->mode }}</td><td>{{ $job->completed_items }}/{{ $job->total_items }}; failed {{ $job->failed_items }}</td><td>{{ $job->created_at }}</td></tr>@empty<tr><td colspan="5" class="text-secondary">No job created.</td></tr>@endforelse</tbody></table></div><pre class="bg-light border rounded p-3 mb-0 small">{{ json_encode($diagnostics, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div></div></section>
  <section class="lgt-tab" data-lgt-panel="library"><div class="card mt-3"><div class="card-body"><h2 class="h5">Manual correction</h2><p class="form-text">Use the current row version. Saving a correction creates an auditable version and can lock the text against future machine replacement.</p><form id="lgt-manual-form" class="row g-3"><div class="col-md-3"><label class="form-label" for="lgt-manual-id">Translation ID</label><input id="lgt-manual-id" class="form-control" type="number" min="1" required readonly></div><div class="col-md-3"><label class="form-label" for="lgt-manual-version">Current version</label><input id="lgt-manual-version" class="form-control" type="number" min="1" required readonly></div><div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input id="lgt-manual-lock" class="form-check-input" type="checkbox"><label class="form-check-label" for="lgt-manual-lock">Lock this human translation</label></div></div><div class="col-12"><label class="form-label" for="lgt-manual-text">Translated presentation text</label><textarea id="lgt-manual-text" class="form-control" rows="5" maxlength="100000" required></textarea><div class="form-text">HTML is not accepted or reconstructed by this V1 contract.</div></div><div class="col-12"><button class="btn btn-primary" type="submit">Save manual correction</button></div></form></div></div></section>

  <section class="lgt-tab" data-lgt-panel="languages"><div class="card mt-3"><div class="card-body"><h2 class="h5">Trusted country defaults</h2><p class="form-text">A country default is used only when the visitor has not selected a language. The country comes from the existing trusted-proxy policy; this plugin never reads an untrusted header directly.</p><form id="lgt-country-rule-form" class="row g-3"><div class="col-md-4"><label class="form-label" for="lgt-country">Country</label><input id="lgt-country" class="form-control text-uppercase" maxlength="2" pattern="[A-Za-z]{2}" placeholder="FR" required></div><div class="col-md-5"><label class="form-label" for="lgt-country-locale">Presentation language</label><input id="lgt-country-locale" class="form-control" maxlength="24" placeholder="fr" required></div><div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input id="lgt-country-enabled" class="form-check-input" type="checkbox" checked><label class="form-check-label" for="lgt-country-enabled">Enabled</label></div></div><div class="col-12"><button class="btn btn-outline-primary" type="submit">Save country default</button></div></form><div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>Country</th><th>Language</th><th>State</th></tr></thead><tbody>@forelse($countryRules as $rule)<tr><td>{{ $rule->country }}</td><td>{{ $rule->locale }}</td><td>{{ $rule->enabled ? 'Enabled' : 'Disabled' }}</td></tr>@empty<tr><td colspan="3" class="text-secondary">No country defaults saved.</td></tr>@endforelse</tbody></table></div></div></div></section>

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
  const post = async (url, payload) => { const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf}, body:JSON.stringify(payload)}); const body = await response.json().catch(()=>({})); if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] || 'Request failed'); return body; };
  document.querySelectorAll('.lgt-verify-provider').forEach(button => button.addEventListener('click', async () => {
    if (!confirm('Send one small verification request to this provider? It may consume free quota and will be recorded in the local ledger.')) return;
    try { const result = await post(`${base}${button.dataset.id}/verify`, {confirm_provider_charge:true}); message(result.notice); button.disabled = true; } catch (error) { message(error.message, true); }
  }));
  document.querySelectorAll('.lgt-dispatch-provider').forEach(button => button.addEventListener('click', async () => {
    const enabled = button.dataset.enabled === '1';
    if (enabled && !confirm('Enable provider dispatch for free-quota-only jobs? Paid overage remains forbidden.')) return;
    try { const result = await post(`${base}${button.dataset.id}/dispatch`, {enabled, confirm_dispatch: enabled}); message(result.notice || (enabled ? 'Provider dispatch enabled.' : 'Provider dispatch disabled.')); button.disabled = true; } catch (error) { message(error.message, true); }
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
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] || 'Request failed');
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
    inform(`Ready to edit translation #${item.id}.`);
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
        cell.colSpan = 5; cell.className = 'text-secondary'; cell.textContent = 'No translation rows yet.';
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
        source.textContent = `${item.kind} #${item.entity_id} · ${item.field}\n${item.source_text}`;
        locale.textContent = item.locale;
        state.textContent = item.status + (item.locked ? ' · locked' : '');
        text.textContent = item.text || '—';
        const edit = document.createElement('button');
        edit.type = 'button'; edit.className = 'btn btn-sm btn-outline-secondary me-1'; edit.textContent = 'Edit';
        edit.addEventListener('click', () => selectForManualEdit(item));
        const approve = document.createElement('button');
        approve.type = 'button'; approve.className = 'btn btn-sm btn-outline-primary'; approve.textContent = 'Approve';
        approve.disabled = !item.text || item.status === 'approved';
        approve.addEventListener('click', async () => {
          try {
            await api(`${routes.update}${item.id}/review`, 'POST', {version: item.version, approved: true});
            inform('Translation approved. Reload the library before publishing.');
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
      inform('Manual correction saved. Review it, then publish a new immutable revision.');
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
      inform(`Country default ${result.rule.country} → ${result.rule.locale} saved. Reload to see the updated list.`);
    } catch (error) { inform(error.message, true); }
  });
})();
</script>
@endsection
