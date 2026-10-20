@extends('admin::layouts.master')

@section('title', '租户站点')

@section('content-area-class', 'w-max-1400')

@section('content')
<div id="saas-sites-app" v-cloak>
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
          <h5 class="mb-1">LONGOS SaaS 租户站点</h5>
          <div class="text-muted" v-if="databaseMode === 'prefix'">在这里创建子站，系统会自动分配独立数据表命名空间和 <span class="font-monospace">code.@{{ baseDomain }}</span> 子域名，无需服务器 root 或手工创建数据库。</div>
          <div class="text-muted" v-else>在这里创建子站，系统会自动创建独立数据库并分配 <span class="font-monospace">code.@{{ baseDomain }}</span> 子域名。</div>
        </div>
        <span class="badge bg-success">SaaS 已启用</span>
      </div>
      <div class="alert alert-info mt-3 mb-0 py-2">
        域名前置条件：DNS 泛解析 <span class="font-monospace">*.@{{ baseDomain }}</span>
        <span v-if="dnsTargets.length"> → <span class="font-monospace">@{{ dnsTargets.join(', ') }}</span></span>；
        Coolify Caddy 会按数据库授权自动签发并续期精确域名证书，无需手工绑定虚拟主机。
      </div>
      <div class="alert alert-light border mt-2 mb-0 py-2">
        邮件发件域与网站访问域独立管理。先在邮局完成 DKIM/签名并加入 <span class="font-monospace">MAIL_RELAY_ALLOWED_SENDER_DOMAINS</span>，再在下方将它唯一分配给租户。例如店铺可使用 <span class="font-monospace">www.example.com</span>，发件域单独分配 <span class="font-monospace">example.com</span>。
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-xl-5">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="mb-3">创建租户子站</h5>
          <div class="mb-3">
            <label class="form-label">站点名称</label>
            <input v-model.trim="form.name" class="form-control" maxlength="255" placeholder="例如：海洋精品店">
          </div>
          <div class="mb-3">
            <label class="form-label">子域名标识</label>
            <div class="input-group">
              <input v-model="form.code" @input="normalizeCode" class="form-control" maxlength="63" placeholder="ocean-shop">
              <span class="input-group-text">.@{{ baseDomain }}</span>
            </div>
            <div class="form-text">仅支持小写字母、数字和连字号，同时用作站点唯一标识。</div>
          </div>
          <div class="mb-3">
            <label class="form-label">租户管理员邮箱</label>
            <input v-model.trim="form.admin_email" type="email" class="form-control" placeholder="admin@example.com">
          </div>
          <div class="mb-3">
            <label class="form-label">初始密码 <span class="text-muted fw-normal">（可留空）</span></label>
            <input v-model="form.admin_password" type="password" class="form-control" minlength="12" maxlength="128" autocomplete="new-password" placeholder="留空则自动生成强密码">
          </div>
          <button type="button" class="btn btn-primary" :disabled="!canCreate || creating" @click="createSite">
            <span v-if="creating" class="spinner-border spinner-border-sm me-2"></span>
            @{{ creating ? (databaseMode === 'prefix' ? '正在初始化租户数据与子站…' : '正在创建数据库与初始化子站…') : '创建租户子站' }}
          </button>
          <div class="form-text mt-2">首次创建需运行数据库迁移和初始化，请等待页面返回结果。</div>
        </div>
      </div>
    </div>

    <div class="col-xl-7">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="mb-3">上次建站结果</h5>
          <div v-if="!lastResult" class="text-muted py-5 text-center">创建成功后，这里会显示子站地址和初始管理账号。</div>
          <div v-else>
            <div class="alert" :class="lastResult.ok ? 'alert-success' : 'alert-danger'">
              @{{ lastResult.ok ? '租户子站已创建并上线。' : (lastResult.error || '建站失败。') }}
            </div>
            <dl v-if="lastResult.ok" class="row mb-0">
              <dt class="col-sm-3">站点地址</dt><dd class="col-sm-9"><a :href="lastResult.url" target="_blank" rel="noopener">@{{ lastResult.url }}</a></dd>
              <dt class="col-sm-3">后台地址</dt><dd class="col-sm-9"><a :href="adminUrl(lastResult.url)" target="_blank" rel="noopener">@{{ adminUrl(lastResult.url) }}</a></dd>
              <dt class="col-sm-3">管理员</dt><dd class="col-sm-9 font-monospace">@{{ lastResult.admin_email }}</dd>
              <dt class="col-sm-3">初始密码</dt><dd class="col-sm-9"><code>@{{ lastResult.admin_password }}</code> <button class="btn btn-link btn-sm py-0" @click="copy(lastResult.admin_password)">复制</button></dd>
              <dt class="col-sm-3">@{{ databaseMode === 'prefix' ? '租户数据标识' : '独立数据库' }}</dt><dd class="col-sm-9 font-monospace">@{{ lastResult.database }}</dd>
            </dl>
            <div v-if="lastResult.ok" class="text-danger small mt-3">初始密码只在本次创建结果中显示，请立即安全保存。</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card h-min-600">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">租户站点列表</h5>
        <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="loading" @click="loadSites">刷新</button>
      </div>
      <div v-if="loading" class="text-center text-muted py-5">正在读取站点…</div>
      <div v-else class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr><th>站点</th><th>访问域名</th><th style="min-width: 260px">邮件发件域</th><th>@{{ databaseMode === 'prefix' ? '数据标识' : '独立数据库' }}</th><th>状态</th><th class="text-end">操作</th></tr></thead>
          <tbody>
            <tr v-for="site in sites" :key="site.id">
              <td><strong>@{{ site.name }}</strong><div class="text-muted small font-monospace">@{{ site.code }}<span v-if="site.meta && site.meta.control_site"> · 控制站</span></div></td>
              <td><a v-if="primaryDomain(site)" :href="domainUrl(primaryDomain(site))" target="_blank" rel="noopener">@{{ primaryDomain(site).host }}</a><span v-else class="text-muted">无</span></td>
              <td>
                <div v-if="platformSenderDomain" class="text-muted small mb-2">
                  共享兜底：<span class="font-monospace">@{{ platformSenderDomain }}</span>
                </div>
                <div v-if="mailDomains(site).length" class="d-flex flex-wrap gap-1 mb-2">
                  <span v-for="item in mailDomains(site)" :key="item.id" class="badge text-bg-light border d-inline-flex align-items-center gap-1">
                    @{{ item.domain }}
                    <button type="button" class="btn-close" style="font-size: .55rem" :aria-label="`解除 ${item.domain}`"
                      :disabled="senderDomainBusyKey === `${site.id}:${item.id}`" @click="removeSenderDomain(site, item)"></button>
                  </span>
                </div>
                <div v-if="!isControlSite(site) && assignableSenderDomains(site).length" class="input-group input-group-sm">
                  <select class="form-select" v-model="senderDomainDrafts[site.id]">
                    <option value="">选择已签名域名…</option>
                    <option v-for="domain in assignableSenderDomains(site)" :key="domain" :value="domain">@{{ domain }}</option>
                  </select>
                  <button type="button" class="btn btn-outline-primary" :disabled="!senderDomainDrafts[site.id] || senderDomainBusyKey !== null" @click="assignSenderDomain(site)">分配</button>
                </div>
                <div v-else-if="!isControlSite(site) && !signedSenderDomains.length" class="text-muted small">邮局尚未配置独立发件域。</div>
                <div v-else-if="isControlSite(site)" class="text-muted small">主站不占用租户独立发件域。</div>
              </td>
              <td class="font-monospace small">@{{ site.database_name }}</td>
              <td>
                <span class="badge" :class="statusClass(site.status)">@{{ statusLabel(site.status) }}</span>
                <div v-if="site.status === 'deleting' && site.meta && site.meta.deletion_last_error" class="text-danger small mt-1" style="max-width: 280px; white-space: normal;">
                  @{{ site.meta.deletion_last_error }}
                </div>
              </td>
              <td class="text-end text-nowrap">
                <a v-if="primaryDomain(site)" :href="adminUrl(domainUrl(primaryDomain(site)))" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">进入后台</a>
                <button v-if="!(site.meta && site.meta.control_site) && site.status === 'active'" class="btn btn-outline-warning btn-sm ms-1" @click="setStatus(site, 'suspended')">暂停</button>
                <button v-if="!(site.meta && site.meta.control_site) && site.status === 'suspended'" class="btn btn-outline-success btn-sm ms-1" @click="setStatus(site, 'active')">恢复</button>
                <button v-if="!(site.meta && site.meta.control_site)" type="button" class="btn btn-outline-danger btn-sm ms-1"
                  :disabled="deletingSiteId === site.id" @click="deleteSite(site)">
                  @{{ deletingSiteId === site.id ? '正在删除…' : (site.status === 'deleting' ? '重试删除' : '删除') }}
                </button>
              </td>
            </tr>
            <tr v-if="!sites.length"><td colspan="6" class="text-center text-muted py-5">尚未创建租户子站。</td></tr>
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
    el: '#saas-sites-app',
    data: {
      baseDomain: @json($baseDomain),
      dnsTargets: @json(array_values($dnsTargets)),
      databaseMode: @json($databaseMode),
      adminPath: @json($adminPath),
      platformSenderDomain: @json($platformSenderDomain),
      signedSenderDomains: @json(array_values($senderDomains)),
      senderDomainOwners: @json($senderDomainOwners),
      loading: true,
      creating: false,
      deletingSiteId: null,
      senderDomainBusyKey: null,
      senderDomainDrafts: {},
      sites: [],
      lastResult: null,
      pendingCreateKey: null,
      pendingCreateSignature: null,
      form: {name: '', code: '', admin_email: '', admin_password: ''},
    },
    computed: {
      canCreate() {
        return this.form.name && this.form.code && this.form.admin_email && this.baseDomain
          && (!this.form.admin_password || this.form.admin_password.length >= 12);
      },
    },
    mounted() { this.loadSites(); },
    methods: {
      adminUrl(baseUrl) {
        return String(baseUrl || '').replace(/\/$/, '') + '/' + this.adminPath;
      },
      normalizeCode() {
        this.form.code = String(this.form.code || '').toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/^-+/, '').slice(0, 63);
      },
      loadSites() {
        this.loading = true;
        return $http.get('saas/sites', {per_page: 100}, {hload: true}).then((res) => {
          this.sites = Array.isArray(res.data) ? res.data : [];
          this.sites.forEach((site) => {
            if (!Object.prototype.hasOwnProperty.call(this.senderDomainDrafts, site.id)) {
              this.$set(this.senderDomainDrafts, site.id, '');
            }
          });
        }).finally(() => { this.loading = false; });
      },
      mailDomains(site) {
        return site && Array.isArray(site.mail_sender_domains) ? site.mail_sender_domains : [];
      },
      isControlSite(site) {
        return Boolean(site && site.meta && site.meta.control_site);
      },
      assignableSenderDomains(site) {
        if (this.isControlSite(site)) return [];
        return this.signedSenderDomains.filter((domain) => !this.senderDomainOwners[domain]);
      },
      assignSenderDomain(site) {
        const domain = this.senderDomainDrafts[site.id];
        if (!domain || this.senderDomainBusyKey !== null) return;
        this.senderDomainBusyKey = `${site.id}:new`;
        $http.post(`saas/sites/${site.id}/sender-domains`, {domain}).then(() => {
          this.$set(this.senderDomainOwners, domain, String(site.id));
          this.$message.success('发件域已分配。');
          this.$set(this.senderDomainDrafts, site.id, '');
          return this.loadSites();
        }).finally(() => { this.senderDomainBusyKey = null; });
      },
      removeSenderDomain(site, item) {
        if (!site || !item || this.senderDomainBusyKey !== null) return;
        this.$confirm(`解除 ${item.domain} 后，该租户已配置的相关发件地址将自动回退到其他已分配域或平台兜底域。确定继续吗？`, '解除发件域', {type: 'warning'})
          .then(() => {
            this.senderDomainBusyKey = `${site.id}:${item.id}`;
            return $http.delete(`saas/sites/${site.id}/sender-domains/${item.id}`).then(() => {
              this.$delete(this.senderDomainOwners, item.domain);
              this.$message.success('发件域已解除。');
              return this.loadSites();
            }).finally(() => { this.senderDomainBusyKey = null; });
          }).catch(() => {});
      },
      createSite() {
        if (!this.canCreate || this.creating) return;
        this.creating = true;
        this.lastResult = null;
        const definition = {
          name: this.form.name,
          code: this.form.code,
          admin_email: this.form.admin_email,
        };
        if (this.form.admin_password) definition.admin_password = this.form.admin_password;
        const signature = JSON.stringify(definition);
        if (!this.pendingCreateKey || this.pendingCreateSignature !== signature) {
          this.pendingCreateKey = window.crypto && typeof window.crypto.randomUUID === 'function'
            ? window.crypto.randomUUID()
            : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                const r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
              });
          this.pendingCreateSignature = signature;
        }
        const key = this.pendingCreateKey;

        $http.axiosApi.post('saas/sites/batch', {sites: [definition]}, {headers: {'Idempotency-Key': key}})
          .then((response) => {
            const result = response.data && Array.isArray(response.data.data) ? response.data.data[0] : null;
            if (!result) throw new Error(response.data?.message || '服务器未返回建站结果。');
            this.lastResult = result;
            this.pendingCreateKey = null;
            this.pendingCreateSignature = null;
            if (!result.ok) throw new Error(result.error || '建站失败。');
            this.$message.success('租户子站已创建。');
            this.form = {name: '', code: '', admin_email: '', admin_password: ''};
            return this.loadSites();
          })
          .catch((error) => {
            const status = Number(error.response?.status || 0);
            // A timeout or an in-progress idempotency conflict is ambiguous:
            // the server may still be provisioning. Keep the same key so a
            // retry returns the original encrypted result instead of starting
            // a conflicting operation.
            if ((status > 0 && status < 500 && status !== 409) || status === 422) {
              this.pendingCreateKey = null;
              this.pendingCreateSignature = null;
            }
            const errors = error.response?.data?.errors;
            const first = errors ? Object.values(errors).flat()[0] : null;
            const message = first || error.response?.data?.message || error.message || '建站失败。';
            if (!this.lastResult) this.lastResult = {ok: false, error: message};
            this.$message.error(message);
          })
          .finally(() => { this.creating = false; });
      },
      setStatus(site, status) {
        const action = status === 'active' ? '恢复' : '暂停';
        this.$confirm(`确定${action}租户站点 ${site.name} 吗？`, `${action}站点`, {type: 'warning'})
          .then(() => $http.request('patch', `saas/sites/${site.id}/status`, {status}).then(() => this.loadSites()))
          .catch(() => {});
      },
      deleteSite(site) {
        if (!site || this.deletingSiteId !== null || (site.meta && site.meta.control_site)) return;
        const warning = `此操作会先下线子站，然后永久删除它的租户数据、订单、商品、媒体文件和域名绑定，且无法撤销。\n\n请输入站点标识“${site.code}”确认：`;
        this.$prompt(warning, '永久删除租户子站', {
          confirmButtonText: '永久删除',
          cancelButtonText: '取消',
          type: 'error',
          closeOnClickModal: false,
          closeOnPressEscape: true,
          inputPlaceholder: site.code,
          inputValidator: (value) => value === site.code || `请完整输入 ${site.code}`,
        }).then(({value}) => {
          this.deletingSiteId = site.id;
          site.status = 'deleting';
          return $http.delete(`saas/sites/${site.id}`, {
            confirmation: value,
            delete_data: true,
          }, {hload: true}).then(() => {
            this.$message.success('租户子站已永久删除。');
          }).catch((error) => {
            const errors = error.response?.data?.errors;
            const first = errors ? Object.values(errors).flat()[0] : null;
            this.$message.error(first || error.response?.data?.message || error.message || '租户子站删除失败。');
          }).finally(() => this.loadSites());
        }).catch((action) => {
          if (action !== 'cancel' && action !== 'close') {
            this.$message.error(action?.message || '无法打开删除确认。');
          }
        }).finally(() => { this.deletingSiteId = null; });
      },
      primaryDomain(site) {
        const domains = Array.isArray(site.domains) ? site.domains : [];
        return domains.find((domain) => domain.is_primary && domain.status === 'active')
          || domains.find((domain) => domain.status === 'active') || null;
      },
      domainUrl(domain) { return `${domain.scheme || 'https'}://${domain.host}`; },
      statusLabel(status) { return {active: '已上线', suspended: '已暂停', provisioning: '初始化中', ready: '待发布', activating: '发布中', failed: '失败', deleting: '删除中'}[status] || status; },
      statusClass(status) { return {active: 'bg-success', suspended: 'bg-secondary', provisioning: 'bg-info text-dark', ready: 'bg-info text-dark', activating: 'bg-warning text-dark', failed: 'bg-danger', deleting: 'bg-danger'}[status] || 'bg-secondary'; },
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
    },
  });
</script>
@endpush
