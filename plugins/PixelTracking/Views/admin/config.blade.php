@extends('admin::layouts.master')

@php
  $settings = $plugin->getSetting() ?: [];
  $decodeProfiles = static function ($value): array {
    $profiles = is_array($value) ? $value : json_decode((string) $value, true);
    return is_array($profiles) ? array_values($profiles) : [];
  };
  $metaProfiles = $decodeProfiles(old('meta_pixels', $settings['meta_pixels'] ?? '[]'));
  $tiktokProfiles = $decodeProfiles(old('tiktok_pixels', $settings['tiktok_pixels'] ?? '[]'));
  $snapchatProfiles = $decodeProfiles(old('snapchat_pixels', $settings['snapchat_pixels'] ?? '[]'));
  $trackingHealth = \Plugin\PixelTracking\Services\TrackingHealth::forProfiles(
    \Plugin\PixelTracking\Services\PixelProfiles::meta(),
    \Plugin\PixelTracking\Services\PixelProfiles::tiktok(),
    \Plugin\PixelTracking\Services\PixelProfiles::snapchat(),
  );
  $healthEntries = array_values(array_filter($trackingHealth, 'is_array'));
  $healthSummary = [
    'healthy' => count(array_filter($healthEntries, static fn ($item) => ($item['success'] ?? false) === true)),
    'failing' => count(array_filter($healthEntries, static fn ($item) => ($item['success'] ?? true) === false)),
    'successes' => array_sum(array_map(static fn ($item) => (int) ($item['success_count'] ?? 0), $healthEntries)),
    'failures' => array_sum(array_map(static fn ($item) => (int) ($item['failure_count'] ?? 0), $healthEntries)),
  ];
  $maskToken = static function (array $profile, string $identifier): array {
    $profile['_saved_identifier'] = (string) ($profile[$identifier] ?? '');
    $profile['has_saved_token'] = !empty($profile['access_token']);
    $profile['access_token'] = '';
    return $profile;
  };
  $metaProfiles = array_map(static fn ($profile) => $maskToken($profile, 'pixel_id'), $metaProfiles);
  $tiktokProfiles = array_map(static fn ($profile) => $maskToken($profile, 'pixel_code'), $tiktokProfiles);
  $snapchatProfiles = array_map(static fn ($profile) => $maskToken($profile, 'pixel_id'), $snapchatProfiles);
@endphp

@section('title', $plugin->getLocaleName())

@section('content')
  <div class="pixel-settings" id="pixel-settings" v-cloak>
    <div class="card border-0 shadow-sm">
      <div class="card-body p-3 p-lg-4">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
          <div>
            <h4 class="mb-1">像素与服务端追踪</h4>
            <p class="text-secondary mb-0">用表单管理 Meta、TikTok 和 Snapchat，不需要编写 JSON 或粘贴配置代码。</p>
          </div>
          <span class="badge text-bg-primary px-3 py-2">LONGOS Tracking</span>
        </div>

        <div class="pixel-overview" role="status" aria-label="服务端追踪运行状态">
          <div><span>已配置通道</span><strong>{{ count($metaProfiles) + count($tiktokProfiles) + count($snapchatProfiles) }}</strong></div>
          <div><span>最近正常</span><strong class="text-success">{{ $healthSummary['healthy'] }}</strong></div>
          <div><span>最近异常</span><strong class="{{ $healthSummary['failing'] ? 'text-danger' : '' }}">{{ $healthSummary['failing'] }}</strong></div>
          <div><span>成功 / 失败投递</span><strong>{{ $healthSummary['successes'] }} / {{ $healthSummary['failures'] }}</strong></div>
        </div>

        <form action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST">
          @csrf
          @method('put')
          <input type="hidden" name="meta_pixels" :value="serializedMetaProfiles">
          <input type="hidden" name="tiktok_pixels" :value="serializedTiktokProfiles">
          <input type="hidden" name="snapchat_pixels" :value="serializedSnapchatProfiles">
          <input type="hidden" name="status" value="0">
          <input type="hidden" name="browser_tracking" value="0">
          <input type="hidden" name="server_tracking" value="0">
          <input type="hidden" name="require_consent" value="0">
          <input type="hidden" name="advanced_matching" value="0">
          <input type="hidden" name="debug_log" value="0">

          <section class="pixel-section">
            <div class="pixel-section-head"><div><h5>启用方式</h5><p>开启浏览器像素与服务端 API 后，系统自动追踪浏览、加购和付款事件。</p></div></div>
            <div class="row g-3">
              <div class="col-md-6"><label class="pixel-switch"><input type="checkbox" name="status" value="1" v-model="status"><span><strong>启用插件</strong><small>关闭后不会加载任何追踪脚本。</small></span></label></div>
              <div class="col-md-6"><label class="pixel-switch"><input type="checkbox" name="browser_tracking" value="1" v-model="browserTracking"><span><strong>浏览器像素</strong><small>用于广告平台的前端事件。</small></span></label></div>
              <div class="col-md-6"><label class="pixel-switch"><input type="checkbox" name="server_tracking" value="1" v-model="serverTracking"><span><strong>服务端追踪</strong><small>由本网站服务器直接发送转化事件。</small></span></label></div>
              <div class="col-md-6"><label class="pixel-switch"><input type="checkbox" name="advanced_matching" value="1" v-model="advancedMatching"><span><strong>高级匹配</strong><small>将订单邮箱、电话哈希后发送；请先取得访客同意。</small></span></label></div>
            </div>
          </section>

          <section class="pixel-section">
            <div class="pixel-section-head"><div><h5>Meta / Facebook</h5><p>填写像素 ID；如需服务器端转化，再填写 Conversions API 访问令牌。每个渠道最多 10 个。</p></div><button type="button" class="btn btn-outline-primary btn-sm" @click="addMeta" :disabled="metaProfiles.length >= maxProfiles"><i class="bi bi-plus-lg me-1"></i>添加 Meta 像素</button></div>
            <div class="pixel-guidance" role="note">
              <strong>去重与多站点说明</strong>
              <span>测试事件中同时出现“浏览器”和“服务器”两行是正常现象；两行事件 ID 相同才会组成去重对。多个网站可以共用同一像素，LONGOS 会按安装与租户隔离事件 ID、投递锁和健康记录，并保留各自来源 URL。共用像素仍会汇总到同一个数据集；若需要完全独立的受众与广告优化，请为每个网站使用不同像素。</span>
            </div>
            <div v-if="!metaProfiles.length" class="pixel-empty">尚未添加 Meta 像素。点击右上角按钮即可开始。</div>
            <div class="pixel-profile" v-for="(profile, index) in metaProfiles" :key="profile.uid">
              <div class="d-flex align-items-center justify-content-between mb-3"><div><strong>Meta 像素 @{{ index + 1 }}</strong><span v-if="profileHealth('meta', profile.pixel_id)" class="pixel-health ms-2" :class="profileHealth('meta', profile.pixel_id).success ? 'is-success' : 'is-error'">@{{ profileHealth('meta', profile.pixel_id).success ? '最近发送成功' : '最近发送失败' }}</span></div><button type="button" class="btn btn-sm btn-link text-danger" @click="metaProfiles.splice(index, 1)">删除</button></div>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">像素 ID</label><input v-model.trim="profile.pixel_id" class="form-control" maxlength="80" placeholder="例如：123456789012345"></div>
                <div class="col-md-6"><label class="form-label">Conversions API 访问令牌 <span class="text-secondary">（可选）</span></label><input v-model="profile.access_token" type="password" autocomplete="new-password" class="form-control" :placeholder="profile.has_saved_token ? '已安全保存；留空保持不变' : '留空即可仅使用浏览器像素'"></div>
                <div class="col-md-6"><label class="form-label">Graph API 版本</label><input v-model.trim="profile.api_version" class="form-control" maxlength="8" placeholder="v26.0"><small class="text-secondary">默认使用当前 v26.0；已保存的兼容版本不会被强制覆盖。</small></div>
                <div class="col-md-6"><label class="form-label">测试事件代码 <span class="text-secondary">（可选）</span></label><input v-model.trim="profile.test_event_code" class="form-control" maxlength="120" placeholder="仅用于 Meta 测试事件"></div>
              </div>
              <div v-if="profileHealth('meta', profile.pixel_id)" class="pixel-health-detail mt-3">@{{ healthText(profileHealth('meta', profile.pixel_id)) }}</div>
            </div>
          </section>

          <section class="pixel-section">
            <div class="pixel-section-head"><div><h5>TikTok</h5><p>填写 Pixel Code；如需 Events API，再填写访问令牌。事件参数按当前 content_ids 规范发送。</p></div><button type="button" class="btn btn-outline-primary btn-sm" @click="addTiktok" :disabled="tiktokProfiles.length >= maxProfiles"><i class="bi bi-plus-lg me-1"></i>添加 TikTok 像素</button></div>
            <div v-if="!tiktokProfiles.length" class="pixel-empty">尚未添加 TikTok 像素。此项不是必填。</div>
            <div class="pixel-profile" v-for="(profile, index) in tiktokProfiles" :key="profile.uid">
              <div class="d-flex align-items-center justify-content-between mb-3"><div><strong>TikTok 像素 @{{ index + 1 }}</strong><span v-if="profileHealth('tiktok', profile.pixel_code)" class="pixel-health ms-2" :class="profileHealth('tiktok', profile.pixel_code).success ? 'is-success' : 'is-error'">@{{ profileHealth('tiktok', profile.pixel_code).success ? '最近发送成功' : '最近发送失败' }}</span></div><button type="button" class="btn btn-sm btn-link text-danger" @click="tiktokProfiles.splice(index, 1)">删除</button></div>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Pixel Code</label><input v-model.trim="profile.pixel_code" class="form-control" maxlength="80" placeholder="例如：CXXXXXXXX"></div>
                <div class="col-md-6"><label class="form-label">Events API 访问令牌 <span class="text-secondary">（可选）</span></label><input v-model="profile.access_token" type="password" autocomplete="new-password" class="form-control" :placeholder="profile.has_saved_token ? '已安全保存；留空保持不变' : '留空即可仅使用浏览器像素'"></div>
              </div>
              <div v-if="profileHealth('tiktok', profile.pixel_code)" class="pixel-health-detail mt-3">@{{ healthText(profileHealth('tiktok', profile.pixel_code)) }}</div>
            </div>
          </section>

          <section class="pixel-section">
            <div class="pixel-section-head"><div><h5>Snapchat</h5><p>填写 Snap Pixel ID；如需服务端转化，再填写长期 Conversions API 访问令牌。浏览器与 CAPI v3 共用事件 ID 去重。</p></div><button type="button" class="btn btn-outline-primary btn-sm" @click="addSnapchat" :disabled="snapchatProfiles.length >= maxProfiles"><i class="bi bi-plus-lg me-1"></i>添加 Snapchat 像素</button></div>
            <div v-if="!snapchatProfiles.length" class="pixel-empty">尚未添加 Snapchat 像素。此项不是必填。</div>
            <div class="pixel-profile" v-for="(profile, index) in snapchatProfiles" :key="profile.uid">
              <div class="d-flex align-items-center justify-content-between mb-3"><div><strong>Snapchat 像素 @{{ index + 1 }}</strong><span v-if="profileHealth('snapchat', profile.pixel_id)" class="pixel-health ms-2" :class="profileHealth('snapchat', profile.pixel_id).success ? 'is-success' : 'is-error'">@{{ profileHealth('snapchat', profile.pixel_id).success ? '最近发送成功' : '最近发送失败' }}</span></div><button type="button" class="btn btn-sm btn-link text-danger" @click="snapchatProfiles.splice(index, 1)">删除</button></div>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Snap Pixel ID</label><input v-model.trim="profile.pixel_id" class="form-control" maxlength="36" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
                <div class="col-md-6"><label class="form-label">Conversions API 访问令牌 <span class="text-secondary">（可选）</span></label><input v-model="profile.access_token" type="password" autocomplete="new-password" class="form-control" :placeholder="profile.has_saved_token ? '已安全保存；留空保持不变' : '留空即可仅使用浏览器像素'"></div>
              </div>
              <div v-if="profileHealth('snapchat', profile.pixel_id)" class="pixel-health-detail mt-3">@{{ healthText(profileHealth('snapchat', profile.pixel_id)) }}</div>
            </div>
          </section>

          <section class="pixel-section">
            <div class="pixel-section-head"><div><h5>隐私与诊断</h5><p>只在确实接入 Cookie 同意条时开启访客同意限制。</p></div></div>
            <div class="row g-3 align-items-center">
              <div class="col-md-6"><label class="pixel-switch"><input type="checkbox" name="require_consent" value="1" v-model="requireConsent"><span><strong>要求访客同意</strong><small>只在同意 Cookie 为真时发送事件。</small></span></label></div>
              <div class="col-md-6" v-if="requireConsent"><label class="form-label">同意 Cookie 名称</label><input name="consent_cookie" class="form-control" maxlength="64" pattern="[A-Za-z0-9._-]{1,64}" value="{{ old('consent_cookie', $settings['consent_cookie'] ?? 'tracking_consent') }}"><small class="text-secondary">同意后可触发 <code>longos:tracking-consent-granted</code>，无需刷新页面即可开始追踪。</small></div>
              <div class="col-md-6"><label class="pixel-switch"><input type="checkbox" name="debug_log" value="1" v-model="debugLog"><span><strong>记录诊断日志</strong><small>仅排错时开启，正常运营建议关闭。</small></span></label></div>
            </div>
          </section>

          <button type="submit" class="btn btn-primary px-4">保存追踪设置</button>
        </form>
      </div>
    </div>
  </div>
@endsection

@push('footer')
  <style>
    .pixel-settings { max-width: 1080px; } .pixel-overview { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:18px; } .pixel-overview > div { display:flex; flex-direction:column; gap:4px; min-height:74px; padding:14px 16px; border:1px solid #e7ecf3; border-radius:12px; background:#f8fafc; } .pixel-overview span { color:#667085; font-size:12px; } .pixel-overview strong { color:#101828; font-size:20px; font-variant-numeric:tabular-nums; } .pixel-section { margin: 0 0 18px; padding: 20px; border: 1px solid #e7ecf3; border-radius: 16px; background: #fff; }
    .pixel-section-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; padding-bottom:16px; margin-bottom:16px; border-bottom:1px solid #eef1f5; } .pixel-section h5 { margin:0 0 5px; font-size:15px; } .pixel-section p { margin:0; color:#667085; font-size:13px; }
    .pixel-switch { display:flex; min-height:76px; gap:12px; align-items:flex-start; padding:15px; border:1px solid #e7ecf3; border-radius:12px; cursor:pointer; background:#fbfcfe; } .pixel-switch input { margin-top:4px; } .pixel-switch strong,.pixel-switch small { display:block; } .pixel-switch small { margin-top:4px; color:#667085; font-size:12px; line-height:1.45; }
    .pixel-profile { margin-top:12px; padding:18px; border:1px solid #dce5f2; border-radius:12px; background:#fbfdff; } .pixel-empty { padding:18px; color:#667085; border:1px dashed #cbd5e1; border-radius:12px; text-align:center; }
    .pixel-guidance { display:grid; gap:4px; margin:0 0 14px; padding:12px 14px; border:1px solid #cfe0f5; border-radius:10px; background:#f5f9ff; color:#344054; font-size:12px; line-height:1.6; } .pixel-guidance strong { color:#175cd3; font-size:12px; }
    .pixel-health { display:inline-flex; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:600; } .pixel-health.is-success { color:#08783e; background:#dcfce7; } .pixel-health.is-error { color:#b42318; background:#fee4e2; } .pixel-health-detail { padding:10px 12px; border-radius:8px; background:#f2f4f7; color:#475467; font-size:12px; overflow-wrap:anywhere; }
    @media (max-width:767.98px) { .pixel-overview { grid-template-columns:repeat(2,minmax(0,1fr)); } } @media (max-width:575.98px) { .pixel-section-head { flex-direction:column; } }
  </style>
  <script>
    new Vue({
      el: '#pixel-settings',
      data: {
        status: @json((bool) old('status', $settings['status'] ?? false)), browserTracking: @json((bool) old('browser_tracking', $settings['browser_tracking'] ?? true)), serverTracking: @json((bool) old('server_tracking', $settings['server_tracking'] ?? true)), advancedMatching: @json((bool) old('advanced_matching', $settings['advanced_matching'] ?? false)), requireConsent: @json((bool) old('require_consent', $settings['require_consent'] ?? false)), debugLog: @json((bool) old('debug_log', $settings['debug_log'] ?? false)),
        metaProfiles: @json($metaProfiles), tiktokProfiles: @json($tiktokProfiles), snapchatProfiles: @json($snapchatProfiles), trackingHealth: @json($trackingHealth), maxProfiles: 10,
      },
      computed: {
        serializedMetaProfiles() { return JSON.stringify(this.metaProfiles.filter((p) => p.pixel_id).map(({uid, has_saved_token, ...p}) => p)); },
        serializedTiktokProfiles() { return JSON.stringify(this.tiktokProfiles.filter((p) => p.pixel_code).map(({uid, has_saved_token, ...p}) => p)); },
        serializedSnapchatProfiles() { return JSON.stringify(this.snapchatProfiles.filter((p) => p.pixel_id).map(({uid, has_saved_token, ...p}) => p)); },
      },
      created() { this.metaProfiles = this.metaProfiles.map((p, i) => ({uid: `meta-${Date.now()}-${i}`, pixel_id: '', access_token: '', api_version: 'v26.0', test_event_code: '', has_saved_token: false, _saved_identifier: '', ...p})); this.tiktokProfiles = this.tiktokProfiles.map((p, i) => ({uid: `tiktok-${Date.now()}-${i}`, pixel_code: '', access_token: '', has_saved_token: false, _saved_identifier: '', ...p})); this.snapchatProfiles = this.snapchatProfiles.map((p, i) => ({uid: `snapchat-${Date.now()}-${i}`, pixel_id: '', access_token: '', has_saved_token: false, _saved_identifier: '', ...p})); },
      methods: {
        addMeta() { if (this.metaProfiles.length < this.maxProfiles) this.metaProfiles.push({uid: `meta-${Date.now()}`, pixel_id: '', access_token: '', api_version: 'v26.0', test_event_code: '', has_saved_token: false, _saved_identifier: ''}); },
        addTiktok() { if (this.tiktokProfiles.length < this.maxProfiles) this.tiktokProfiles.push({uid: `tiktok-${Date.now()}`, pixel_code: '', access_token: '', has_saved_token: false, _saved_identifier: ''}); },
        addSnapchat() { if (this.snapchatProfiles.length < this.maxProfiles) this.snapchatProfiles.push({uid: `snapchat-${Date.now()}`, pixel_id: '', access_token: '', has_saved_token: false, _saved_identifier: ''}); },
        profileHealth(channel, id) { return id ? this.trackingHealth[`${channel}:${id}`] : null; },
        healthText(health) { const status = health.http_status ? `HTTP ${health.http_status}` : '网络失败'; const message = health.message ? ` · ${health.message}` : ''; const totals = ` · 成功 ${Number(health.success_count || 0)} / 失败 ${Number(health.failure_count || 0)}`; const streak = Number(health.consecutive_failures || 0) > 1 ? ` · 连续失败 ${health.consecutive_failures} 次` : ''; return `${health.recorded_at} · ${health.event} · ${status}${totals}${streak}${message}`; },
      },
    });
  </script>
@endpush
