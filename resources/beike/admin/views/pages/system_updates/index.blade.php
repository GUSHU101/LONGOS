@extends('admin::layouts.master')

@section('title', __('admin/setting.system_update_title'))
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('settings.index'))

@section('content')
  <div id="system-update-app">
    @if (!$enabled)
      <div class="alert alert-info">当前由 GitHub 与 Coolify 管理不可变镜像更新。请在 Coolify 的 Deployments 页面部署或回滚版本；后台不会覆盖容器内代码。</div>
    @endif

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
          <div class="text-secondary mb-1">当前版本</div>
          <div class="fs-4 fw-bold">v{{ $currentVersion }}</div>
        </div></div>
      </div>
      <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
          <div class="text-secondary mb-1">{{ $enabled ? '可用版本' : '发布来源' }}</div>
          @if ($enabled)
            <div class="fs-4 fw-bold" v-text="release ? 'v' + release.version : '尚未检查'"></div>
          @else
            <div class="fs-4 fw-bold">Git / Coolify</div>
          @endif
        </div></div>
      </div>
      <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
          <div class="text-secondary mb-1">更新状态</div>
          @if ($enabled)
            <div class="fs-5 fw-bold" v-text="statusLabel"></div>
          @else
            <div class="fs-5 fw-bold text-success">不可变镜像</div>
          @endif
        </div></div>
      </div>
    </div>

    @if ($enabled)
    <div class="card mb-4">
      <div class="card-header"><h6 class="card-title mb-0">GitHub Release 连接</h6></div>
      <div class="card-body">
        <div class="alert alert-info">
          仅使用 GitHub Release 中的 <code>{{ config('longos.updater.asset_name') }}</code>。系统会验证 GitHub 返回的 SHA-256 和包内逐文件清单；分支 ZIP 不会被安装。
        </div>
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label">GitHub 仓库</label>
            <input class="form-control" v-model.trim="form.repository" placeholder="GUSHU101/your-longos-repository" autocomplete="off">
            <div class="form-text">公开仓库不需要令牌；私有仓库请使用只有 Contents: Read 权限的细粒度令牌。</div>
          </div>
          <div class="col-md-5">
            <label class="form-label">更新通道</label>
            <select class="form-select" v-model="form.channel">
              <option value="stable">稳定版（推荐）</option>
              <option value="prerelease">包含预发布版</option>
            </select>
          </div>
          <div class="col-md-7">
            <label class="form-label">GitHub 只读令牌（可选）</label>
            <input class="form-control" v-model="form.token" type="password" autocomplete="new-password"
                   :placeholder="configuration.has_token ? '已安全保存；留空保持不变' : 'github_pat_…'">
            <div class="form-text">令牌使用 APP_KEY 加密并保存于 <code>storage/app/private</code>，页面永不回显。</div>
          </div>
          <div class="col-md-5 d-flex align-items-end">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="clear-update-token" v-model="form.clear_token">
              <label class="form-check-label" for="clear-update-token">删除已保存的令牌</label>
            </div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <button class="btn btn-primary" type="button" :disabled="busy" @click="saveSettings">
            <i class="bi bi-check2-circle me-1"></i>保存连接
          </button>
          <button class="btn btn-outline-primary" type="button" :disabled="busy || !form.repository" @click="checkRelease">
            <i class="bi bi-arrow-repeat me-1"></i>检查更新
          </button>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <h6 class="card-title mb-0">安全更新</h6>
        <span class="badge" :class="statusBadge" v-text="statusLabel"></span>
      </div>
      <div class="card-body">
        <div class="alert alert-light border">
          <div class="fw-bold mb-2">更新时始终保留</div>
          <div class="row g-2 small">
            <div class="col-md-4"><i class="bi bi-check-circle text-success me-1"></i><code>.env</code> 与 APP_KEY</div>
            <div class="col-md-4"><i class="bi bi-check-circle text-success me-1"></i>主站及全部租户数据库</div>
            <div class="col-md-4"><i class="bi bi-check-circle text-success me-1"></i><code>storage</code>、商品图与上传文件</div>
            <div class="col-md-4"><i class="bi bi-check-circle text-success me-1"></i>Coolify 配置与 Caddy 证书</div>
            <div class="col-md-4"><i class="bi bi-check-circle text-success me-1"></i>邮件 DKIM、队列与幂等数据</div>
            <div class="col-md-4"><i class="bi bi-check-circle text-success me-1"></i>可验证代码及数据库回滚备份</div>
          </div>
          <div class="form-text mt-2">Coolify 模式由 GitHub 构建不可变镜像并自动迁移。应用内文件覆盖更新器默认关闭，请在 Coolify Deployments 中发布或回滚。</div>
        </div>

        <div v-if="release" class="border rounded p-3 mb-3">
          <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
            <div>
              <div class="fw-bold" v-text="release.name || release.tag"></div>
              <div class="small text-secondary">发布时间：<span v-text="formatTime(release.published_at)"></span></div>
              <div class="small text-secondary">发布包：<span v-text="formatBytes(release.asset.size)"></span> · SHA-256 已提供</div>
            </div>
            <a v-if="release.html_url" class="btn btn-sm btn-outline-secondary align-self-start" :href="release.html_url" target="_blank" rel="noopener noreferrer">查看发布说明</a>
          </div>
          <pre v-if="release.notes" class="mt-3 mb-0 p-3 bg-light border rounded text-wrap" style="max-height:220px;overflow:auto" v-text="release.notes"></pre>
        </div>

        <div class="progress mb-2" style="height: 10px" role="progressbar" :aria-valuenow="Number(state.progress || 0)" aria-valuemin="0" aria-valuemax="100">
          <div class="progress-bar" :class="state.status === 'failed' ? 'bg-danger' : (state.status === 'rolled_back' ? 'bg-warning' : '')" :style="{width: Number(state.progress || 0) + '%'}"></div>
        </div>
        <div class="small mb-3" :class="state.status === 'failed' ? 'text-danger' : 'text-secondary'" v-text="state.message"></div>

        <button class="btn btn-success" type="button"
                :disabled="busy || running || state.recovery_required || !release || !release.update_available || !enabled"
                @click="startUpdate">
          <i class="bi bi-cloud-arrow-down me-1"></i>
          <span v-text="running ? '正在更新…' : '备份并一键更新'"></span>
        </button>
        <button v-if="state.recoverable" class="btn btn-warning ms-2" type="button" :disabled="busy" @click="recoverUpdate">
          <i class="bi bi-arrow-counterclockwise me-1"></i>恢复中断更新
        </button>
        <div class="form-text mt-2">开始后先完成下载、校验和资源预检，再进入维护模式并等待已执行队列任务安全结束，然后备份一致数据库快照。失败时自动恢复；不需要删除旧项目。</div>
      </div>
    </div>

    <div class="card" v-if="state.logs && state.logs.length">
      <div class="card-header"><h6 class="card-title mb-0">更新记录</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <thead><tr><th style="width:220px">时间</th><th>内容</th></tr></thead>
          <tbody><tr v-for="(log, index) in state.logs" :key="index"><td class="text-secondary" v-text="formatTime(log.time)"></td><td v-text="log.message"></td></tr></tbody>
        </table></div>
      </div>
    </div>
    @else
      <div class="card">
        <div class="card-header"><h6 class="card-title mb-0">Coolify 安全发布</h6></div>
        <div class="card-body">
          <p class="mb-3">当前容器中的代码不会被后台覆盖。将通过审核的代码推送到 Coolify 绑定的 Git 分支，或在 Coolify Deployments 中点击 Deploy。</p>
          <ol class="mb-3 ps-3">
            <li class="mb-2">Coolify 使用锁文件重新构建 PHP 依赖和前端资源。</li>
            <li class="mb-2">新容器在共享锁下自动执行未完成的 SaaS 升级。</li>
            <li class="mb-2">健康检查通过后才接收流量；数据库、上传、插件、DKIM 和邮件队列都保留在持久卷。</li>
            <li>如需回滚，在 Coolify 选择上一个成功部署；包含不可逆数据库迁移时同时恢复对应备份。</li>
          </ol>
          <div class="alert alert-warning mb-0">发布前请先在 Coolify 备份 MySQL 和所有 LONGOS 持久卷。不要删除旧容器或 <code>storage/installed</code> 来强制重装。</div>
        </div>
      </div>
    @endif
  </div>
@endsection

@push('footer')
<script>
new Vue({
  el: '#system-update-app',
  data: {
    enabled: @json($enabled),
    busy: false,
    timer: null,
    configuration: @json($configuration),
    form: {
      repository: @json($configuration['repository'] ?? ''),
      channel: @json($configuration['channel'] ?? 'stable'),
      token: '',
      clear_token: false,
    },
    release: null,
    state: @json($state),
    urls: {
      store: @json(admin_route('system_updates.store')),
      check: @json(admin_route('system_updates.check')),
      start: @json(admin_route('system_updates.start')),
      status: @json(admin_route('system_updates.status')),
      recover: @json(admin_route('system_updates.recover')),
    }
  },
  computed: {
    running() { return ['pending', 'running'].includes(this.state.status); },
    statusLabel() {
      return ({idle:'待检查',pending:'等待执行',running:'正在更新',completed:'更新成功',rolled_back:'已自动回滚',failed:'需要处理'})[this.state.status] || this.state.status || '未知';
    },
    statusBadge() {
      return this.state.status === 'completed' ? 'text-bg-success' : (this.state.status === 'failed' ? 'text-bg-danger' : (this.state.status === 'rolled_back' ? 'text-bg-warning' : 'text-bg-primary'));
    }
  },
  mounted() { if (this.enabled && this.running) this.beginPolling(); },
  beforeDestroy() { if (this.timer) clearInterval(this.timer); },
  methods: {
    csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; },
    async request(url, options = {}) {
      const {headers = {}, ...requestOptions} = options;
      const response = await fetch(url, {
        credentials: 'same-origin',
        ...requestOptions,
        headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':this.csrf(), ...headers},
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.status === 'fail') throw new Error(payload.message || '请求失败。');
      return payload.data;
    },
    notify(message, ok = true) { layer.msg(message, {icon: ok ? 1 : 2}); },
    async persistSettings(showNotice = true) {
      const data = await this.request(this.urls.store, {method:'POST', body:JSON.stringify(this.form)});
      this.configuration = data;
      this.form.token = '';
      this.form.clear_token = false;
      if (showNotice) this.notify('GitHub 更新设置已保存。');
      return data;
    },
    async saveSettings() {
      this.busy = true;
      try {
        await this.persistSettings(true);
      } catch (error) { this.notify(error.message, false); }
      finally { this.busy = false; }
    },
    async checkRelease() {
      this.busy = true;
      try {
        await this.persistSettings(false);
        this.release = await this.request(this.urls.check, {method:'POST', body:'{}'});
        this.notify(this.release.update_available ? '发现新版本。' : '当前已经是最新版本。');
      } catch (error) { this.notify(error.message, false); }
      finally { this.busy = false; }
    },
    startUpdate() {
      if (!this.release || !this.release.update_available) return;
      layer.confirm(
        '系统将先备份代码和全部数据库，再从 v' + this.release.current_version + ' 更新到 v' + this.release.version + '。更新期间会短暂进入维护模式，确认继续吗？',
        {title:'LONGOS 安全更新', btn:['确认更新','取消']},
        async (index) => {
          layer.close(index);
          this.busy = true;
          try {
            this.state = await this.request(this.urls.start, {
              method:'POST',
              body:JSON.stringify({version:this.release.version, asset_id:this.release.asset.id})
            });
            this.notify('更新已开始，可以离开本页面。');
            this.beginPolling();
          } catch (error) { this.notify(error.message, false); }
          finally { this.busy = false; }
        }
      );
    },
    recoverUpdate() {
      layer.confirm('已检测到更新进程不再运行。系统将根据落盘清单恢复代码和必要的数据库快照，确认继续吗？', {
        title:'恢复中断更新', btn:['确认恢复','取消']
      }, async (index) => {
        layer.close(index);
        this.busy = true;
        try {
          this.state = await this.request(this.urls.recover, {method:'POST', body:'{}'});
          this.notify(this.state.message, this.state.status === 'rolled_back');
        } catch (error) { this.notify(error.message, false); }
        finally { this.busy = false; }
      });
    },
    beginPolling() {
      if (this.timer) clearInterval(this.timer);
      this.pollStatus();
      this.timer = setInterval(this.pollStatus, 2500);
    },
    async pollStatus() {
      try {
        this.state = await this.request(this.urls.status, {method:'GET', headers:{'Content-Type':'application/json'}});
        if (!this.running && this.timer) {
          clearInterval(this.timer); this.timer = null;
          if (this.state.status === 'completed') this.notify(this.state.message);
          if (this.state.status === 'rolled_back' || this.state.status === 'failed') this.notify(this.state.message, false);
        }
      } catch (_) {
        // Code replacement may briefly restart PHP-FPM/opcache. Keep polling;
        // the read-only status route is maintenance-mode exempt.
      }
    },
    formatBytes(value) {
      const bytes = Number(value || 0); if (!bytes) return '0 B';
      const units = ['B','KB','MB','GB']; const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
      return (bytes / Math.pow(1024, index)).toFixed(index ? 1 : 0) + ' ' + units[index];
    },
    formatTime(value) { if (!value) return '-'; const date = new Date(value); return Number.isNaN(date.getTime()) ? value : date.toLocaleString(); }
  }
});
</script>
@endpush
