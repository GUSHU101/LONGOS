@extends('admin::layouts.master')

@section('title', '域名管理')

@section('content-area-class', 'w-max-1200')

@section('content')
<div id="saas-domain-app" v-cloak>
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
          <h5 class="mb-1">店铺域名</h5>
          <div class="text-muted">管理平台子域名和自有域名。所有新域名在所有权验证和发布完成前都不会接收店铺流量。</div>
        </div>
        <span class="badge" :class="edge.healthy ? 'bg-success' : (tls.automatic ? 'bg-danger' : 'bg-warning text-dark')">
          @{{ edge.healthy ? 'Caddy HTTPS 已验证' : (tls.automatic ? 'Caddy 边缘未就绪' : 'HTTPS 需部署方配置') }}
        </span>
      </div>
      <div class="alert mt-3 mb-0" :class="edge.healthy ? 'alert-success' : (tls.automatic ? 'alert-danger' : 'alert-warning')">@{{ tls.message }}</div>
    </div>
  </div>

  <div v-if="edge.warning" class="alert alert-danger mb-4">
    <strong>边缘服务未就绪：</strong>@{{ edge.warning }}
  </div>

  <div v-if="cdn.provider === 'cloudflare'" class="alert alert-info mb-4">@{{ cdn.message }}</div>

  <div class="row g-4 mb-4">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-body">
          <h6>更换平台子域名</h6>
          <p class="text-muted small">旧子域名会停止路由并永久保留占用，防止被其他租户接管。</p>
          <el-input v-model.trim="subdomain" maxlength="63" placeholder="new-brand" @input="subdomain = normalizeLabel(subdomain)">
            <template slot="append">.@{{ baseDomain || '未配置根域名' }}</template>
          </el-input>
          <el-button type="primary" class="mt-3" :loading="savingSubdomain" :disabled="!subdomain || !baseDomain" @click="changeSubdomain">保存新子域名</el-button>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-body">
          <h6>添加自有域名</h6>
          <p class="text-muted small">例如 shop.example.com。先验证所有权和流量指向，再自动启用 HTTPS。</p>
          <div v-if="!loading && !dnsTargets.length" class="alert alert-danger py-2 small">
            平台尚未配置 <span class="font-monospace">SAAS_DNS_TARGETS</span>，请联系平台管理员完成后再绑定。
          </div>
          <el-input v-model.trim="customDomain" maxlength="253" placeholder="shop.example.com" @blur="customDomain = normalizeDomain(customDomain)" @keyup.enter.native="addDomain"></el-input>
          <el-button type="primary" class="mt-3" :loading="addingDomain" :disabled="!customDomain || !dnsTargets.length" @click="addDomain">添加并生成验证记录</el-button>
        </div>
      </div>
    </div>
  </div>

  <div class="card h-min-600">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">已绑定域名</h5>
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loading" @click="load">刷新</button>
      </div>

      <div v-if="loading" class="text-center text-muted py-5">正在读取域名配置…</div>
      <div v-else class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
          <tr>
            <th>域名</th>
            <th>类型</th>
            <th>状态</th>
            <th>路由</th>
            <th>HTTPS</th>
            <th class="text-end">操作</th>
          </tr>
          </thead>
          <tbody>
          <template v-for="domain in domains">
            <tr :key="'domain-' + domain.id">
              <td>
                <a v-if="domain.operational" :href="'https://' + domain.host" target="_blank" rel="noopener"><strong>@{{ domain.host }}</strong></a>
                <strong v-else>@{{ domain.host }}</strong>
                <div v-if="domain.is_primary" class="small text-success">规范主域名</div>
              </td>
              <td>@{{ domain.origin === 'platform' ? '平台子域名' : '自有域名' }}</td>
              <td>
                <span class="badge" :class="statusClass(domain.effective_status)">@{{ statusLabel(domain.effective_status) }}</span>
                <div v-if="domain.publication_error" class="small text-danger mt-1 text-wrap" style="max-width: 300px">@{{ domain.publication_error }}</div>
              </td>
              <td>@{{ domain.routing_type === 'primary' ? '主域直连' : (domain.routing_type === 'alias' ? '别名直连' : '跳转到主域') }}</td>
              <td><span :class="domain.tls.ready ? 'text-success' : (domain.tls.automatic && !edge.healthy ? 'text-danger' : 'text-warning')">@{{ tlsStatusLabel(domain) }}</span></td>
              <td class="text-end text-nowrap">
                <button v-if="domain.capabilities.verify" type="button" class="btn btn-primary btn-sm" @click="verify(domain)">@{{ domain.verified_at ? '重新发布检测' : '验证并启用' }}</button>
                <button v-if="domain.capabilities.rotate" type="button" class="btn btn-outline-secondary btn-sm ms-1" @click="rotate(domain)">重置 TXT</button>
                <button v-if="domain.capabilities.make_primary" type="button" class="btn btn-outline-primary btn-sm ms-1" @click="makePrimary(domain)">设为主域</button>
                <select v-if="domain.capabilities.set_routing" class="form-select form-select-sm d-inline-block ms-1" style="width: 118px" :value="domain.routing_type" @change="setRouting(domain, $event.target.value)">
                  <option value="redirect">跳转</option>
                  <option value="alias">别名</option>
                </select>
                <button v-if="domain.capabilities.remove" type="button" class="btn btn-outline-danger btn-sm ms-1" @click="removeDomain(domain)">解绑</button>
              </td>
            </tr>
            <tr v-if="domain.verification || domain.capabilities.verify" :key="'verification-' + domain.id" class="table-light">
              <td colspan="6">
                <div v-if="domain.verification" class="small fw-semibold mb-2">请在 DNS 服务商添加以下所有权记录：</div>
                <div class="alert alert-info py-2 small">
                  <div class="fw-semibold mb-1">流量解析记录（必须与所有权 TXT 记录同时存在）</div>
                  <div v-for="record in trafficRecords" :key="record.type + record.value">
                    <span class="font-monospace">@{{ record.type }}</span>
                    <span class="font-monospace">@{{ domain.host }}</span>
                    → <span class="font-monospace">@{{ record.value }}</span>
                  </div>
                  <div v-if="!trafficRecords.length" class="text-danger">平台尚未配置 SAAS_DNS_TARGETS，当前无法完成验证。</div>
                </div>
                <div v-if="cdn.provider === 'cloudflare'" class="alert alert-info py-2 small">
                  Cloudflare：所有权 TXT 保持“仅 DNS”；网站 A/AAAA/CNAME 可开启橙色云代理；SSL/TLS 加密模式必须选择 <strong>Full (strict)</strong>。请勿对后台、API、购物车或结账路径设置“缓存所有内容”。
                </div>
                <div v-if="!tls.automatic" class="alert alert-warning py-2 small">
                  当前使用外部 HTTPS。请在外部入口绑定 <span class="font-monospace">@{{ domain.host }}</span> 并部署包含此域名的有效证书；LONGOS 会真实访问 HTTPS 确认后才标记启用。
                </div>
                <div v-if="domain.verification" class="row g-2 small">
                  <div class="col-md-2"><span class="text-muted">类型</span><div class="font-monospace">@{{ domain.verification.type }}</div></div>
                  <div class="col-md-4"><span class="text-muted">主机记录</span><div class="font-monospace text-break">@{{ domain.verification.name }} <button class="btn btn-link btn-sm p-0" @click="copy(domain.verification.name)">复制</button></div></div>
                  <div class="col-md-6"><span class="text-muted">记录值</span><div class="font-monospace text-break">@{{ domain.verification.value }} <button class="btn btn-link btn-sm p-0" @click="copy(domain.verification.value)">复制</button></div></div>
                </div>
                <div v-if="domain.verification && domain.verification.expires_at" class="text-muted small mt-2">验证记录有效期至：@{{ formatDate(domain.verification.expires_at) }}。过期后可点击“重置 TXT”生成新记录。
                </div>
                <div v-if="domain.verification_error" class="text-danger small mt-2">@{{ domain.verification_error }}</div>
              </td>
            </tr>
          </template>
          <tr v-if="!domains.length"><td colspan="6" class="text-center text-muted py-5">尚无域名</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
@endsection

@push('footer')
<script>
  new Vue({
    el: '#saas-domain-app',
    data: {
      loading: true,
      savingSubdomain: false,
      addingDomain: false,
      baseDomain: '',
      subdomain: '',
      customDomain: '',
      tls: {automatic: false, driver: ''},
      edge: {observed: false, healthy: false, mode: 'auto', reason: null, warning: null},
      cdn: {provider: null, mode: 'off', message: ''},
      dnsTargets: [],
      domains: [],
    },
    mounted() { this.load(); },
    computed: {
      trafficRecords() {
        return this.dnsTargets.map((value) => ({
          value,
          type: this.dnsRecordType(value),
        }));
      },
    },
    methods: {
      normalizeLabel(value) { return String(value || '').toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 63); },
      normalizeDomain(value) {
        return String(value || '').trim().toLowerCase()
          .replace(/^https?:\/\//, '')
          .split('/')[0]
          .replace(/\.$/, '');
      },
      dnsRecordType(value) {
        const target = String(value || '').trim();
        if (/^(?:\d{1,3}\.){3}\d{1,3}$/.test(target)) return 'A';
        if (target.includes(':')) return 'AAAA';
        return 'CNAME';
      },
      formatDate(value) {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? value : (window.bk?.formatDateTime?.(date) || date.toLocaleString());
      },
      load() {
        this.loading = true;
        return $http.get('saas/domains').then((res) => {
          this.baseDomain = res.base_domain || '';
          this.tls = res.tls || {automatic: false, driver: ''};
          this.edge = res.edge || {observed: false, healthy: false, mode: 'auto', reason: null, warning: null};
          this.cdn = res.cdn || {provider: null, mode: 'off', message: ''};
          this.dnsTargets = Array.isArray(res.dns_targets) ? res.dns_targets : [];
          this.domains = Array.isArray(res.domains) ? res.domains : [];
        }).finally(() => { this.loading = false; });
      },
      changeSubdomain() {
        if (!this.subdomain) return;
        this.savingSubdomain = true;
        $http.request('patch', 'saas/domains/subdomain', {subdomain: this.subdomain}).then(() => {
          this.$message.success(this.edge.healthy
            ? '平台子域名已更新，Caddy 将自动签发 HTTPS。'
            : '平台子域名已更新；当前边缘未就绪，请先根据页面提示完成 HTTPS 入口。');
          this.subdomain = '';
          return this.load();
        }).finally(() => { this.savingSubdomain = false; });
      },
      addDomain() {
        this.customDomain = this.normalizeDomain(this.customDomain);
        if (!this.customDomain || !this.dnsTargets.length) return;
        this.addingDomain = true;
        $http.post('saas/domains', {domain: this.customDomain}).then(() => {
          this.$message.success('域名已提交，请完成 DNS TXT 验证。');
          this.customDomain = '';
          return this.load();
        }).finally(() => { this.addingDomain = false; });
      },
      verify(domain) {
        $http.post(`saas/domains/${domain.id}/verify`, {}).then((res) => {
          const ready = res && res.data ? res.data.operational : false;
          this.$message.success(ready ? '域名的 DNS、Web 入口和 HTTPS 已验证，现在可正常使用。' : '域名已验证，正在完成发布。');
          return this.load();
        });
      },
      rotate(domain) {
        $http.post(`saas/domains/${domain.id}/rotate`, {}).then(() => {
          this.$message.success('已生成新的 TXT 验证记录。');
          return this.load();
        });
      },
      makePrimary(domain) {
        this.$confirm(`确定将 ${domain.host} 设为主域名吗？`, '切换主域名', {type: 'warning'})
          .then(() => $http.request('patch', `saas/domains/${domain.id}/primary`, {}).then(() => this.load()))
          .catch(() => {});
      },
      setRouting(domain, mode) {
        $http.request('patch', `saas/domains/${domain.id}/routing`, {mode}).then(() => this.load());
      },
      removeDomain(domain) {
        this.$confirm(`解绑 ${domain.host} 后将立即停止路由，是否继续？`, '解绑域名', {type: 'warning'})
          .then(() => $http.delete(`saas/domains/${domain.id}`).then(() => this.load()))
          .catch(() => {});
      },
      copy(value) {
        const text = String(value || '');
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(() => this.$message.success('已复制'));
          return;
        }
        const input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
        this.$message.success('已复制');
      },
      tlsStatusLabel(domain) {
        if (domain.tls.ready) return '已就绪';
        if (domain.tls.automatic && this.edge.healthy) return '待自动签发';
        if (domain.tls.automatic) return 'Caddy 边缘未就绪';
        return '待回源验证';
      },
      statusLabel(status) { return {active: '已启用', publication_failed: '部署未完成', activating: '发布中', pending: '待验证/待发布', failed: '失败', retired: '已停用'}[status] || status; },
      statusClass(status) { return {active: 'bg-success', publication_failed: 'bg-danger', activating: 'bg-info text-dark', pending: 'bg-warning text-dark', failed: 'bg-danger', retired: 'bg-secondary'}[status] || 'bg-secondary'; },
    },
  });
</script>
@endpush
