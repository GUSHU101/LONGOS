@extends('admin::layouts.master')

@section('title', __('admin/plugin.plugin_list'))

@section('body-class', 'page-plugins')

@section('page-title-right')
  <div class="d-flex align-items-center gap-2">
    <!-- Signature Secret Button -->
    <div id="signature-secret-app" v-cloak style="display: none;">
      <el-button
        v-if="!signatureStatus.has_secret"
        size="small"
        type="warning"
        :loading="fetchingSecret"
        @click="fetchSignatureSecret">
        <i class="bi bi-key me-1"></i>
        @{{ getSecretRetryButtonText() }}
      </el-button>
    </div>

    <button type="button" class="btn btn-outline-secondary" onclick="window.longosPluginDiagnostics && window.longosPluginDiagnostics()"><i class="bi bi-shield-check me-1"></i>插件自检</button>
    <a href="{{ admin_route('plugin_customization.index') }}" class="btn btn-outline-primary"><i class="bi bi-wechat me-1"></i>{{ __('admin/plugin.customization_contact') }}</a>
  </div>
@endsection

@section('page-title-after')
<span class="text-secondary small">统一管理扩展、主题与店铺功能</span>
@endsection

@section('content')
  <div id="plugins-app" class="plugins-workbench" v-cloak>
    <section class="plugin-overview-card">
      <div class="plugin-overview-copy"><span>LONGOS EXTENSIONS</span><h2>功能与插件工作台</h2><p>查看运行状态、补齐配置、执行升级，并从一个页面进入每项功能。</p></div>
      <div class="plugin-overview-stats">
        <button type="button" @click="statusFilter = 'all'"><strong>@{{ plugins.length }}</strong><span>全部扩展</span></button>
        <button type="button" @click="statusFilter = 'enabled'"><strong>@{{ summary.enabled }}</strong><span>已启用</span></button>
        <button type="button" @click="statusFilter = 'attention'"><strong :class="{'text-warning': summary.attention}">@{{ summary.attention }}</strong><span>需要处理</span></button>
      </div>
      <button type="button" class="plugin-diagnostics-button" :disabled="diagnosticsLoading" @click="runDiagnostics"><i :class="diagnosticsLoading ? 'el-icon-loading' : 'bi bi-shield-check'"></i>@{{ diagnosticsLoading ? '正在检查…' : '运行完整自检' }}</button>
    </section>
    <section class="plugin-filter-bar">
      <label class="plugin-search"><i class="bi bi-search"></i><input v-model.trim="pluginKeyword" type="search" placeholder="搜索插件、功能或用途"></label>
      <div class="plugin-filter-pills"><button type="button" :class="{active:typeFilter==='all'}" @click="typeFilter='all'">全部</button><button v-for="type in availableTypes" :key="type" type="button" :class="{active:typeFilter===type}" @click="typeFilter=type">@{{ typeLabel(type) }}</button></div>
      <div class="plugin-status-filter"><button type="button" :class="{active:statusFilter==='all'}" @click="statusFilter='all'">所有状态</button><button type="button" :class="{active:statusFilter==='enabled'}" @click="statusFilter='enabled'">已启用</button><button type="button" :class="{active:statusFilter==='attention'}" @click="statusFilter='attention'">待处理</button></div>
    </section>
    <div class="card plugin-grid-card">
    <div class="card-body h-min-600">
      <div class="plugins-wrap">
        <div v-if="filteredPlugins.length">
          <div class="row g-4">
            <div v-for="plugin in filteredPlugins" :key="plugin.code" class="col-12 col-md-6 col-xl-4 col-xxl-3">
              <div class="card plugin-item overflow-hidden" :class="{'is-attention': pluginHealth(plugin).status === 'attention'}" :data-code="plugin.code">
                <div class="card-body position-relative">
                  <div class="type-format position-absolute font-size-12 text-secondary top-0 end-0 rounded-3 bg-light px-3 py-1">@{{ plugin.type_format }}</div>
                  <span class="plugin-health" :class="pluginHealth(plugin).status"><i></i>@{{ pluginHealthLabel(plugin) }}</span>
                  <div class="d-flex mb-3 align-items-center">
                    <div class="me-2 wh-70 cursor-pointer" style="flex: 0 0 70px;" @click.prevent="showPluginDetail(plugin.code)">
                      <img :src="plugin.icon" class="img-fluid rounded-3">
                    </div>
                    <div class="plugin-describe">
                      <h6 class="plugin-name mb-1">@{{ plugin.name }}</h6>
                      <div class="text-secondary">@{{ plugin.version }}</div>
                    </div>
                  </div>
                  <div class="plugin-description text-secondary" :title="plugin.description" v-html="plugin.description"></div>
                  <div class="plugin-readiness" v-if="plugin.installed"><div><span>配置完整度</span><strong>@{{ configurationText(plugin) }}</strong></div><div class="plugin-readiness-track"><i :style="{width: configurationPercent(plugin) + '%'}"></i></div><small v-if="pluginHealth(plugin).pending_migrations">@{{ pluginHealth(plugin).pending_migrations }} 项迁移将在启用时自动执行</small></div>
                  <div v-if="pluginHealth(plugin).issues && pluginHealth(plugin).issues.length" class="plugin-inline-issue"><i class="bi bi-exclamation-triangle"></i><span>@{{ pluginHealth(plugin).issues[0] }}</span></div>

                  <div class="d-flex align-items-center justify-content-between mt-4">
                    <div>
                      <div v-if="plugin.installed">
                        <a v-if="plugin.type != 'theme'" class="btn btn-sm btn-dark" :href="plugin.edit_url"><i class="bi bi-sliders me-1"></i>设置</a>
                        <a v-else :style="!plugin.status ? 'cursor: not-allowed' : ''" :class="['btn btn-sm btn-outline-secondary', !plugin.status ? 'disabled' : '' ]" href="{{ admin_route('theme.index') }}">{{ __('admin/plugin.to_enable') }}</a>
                        <button type="button" v-if="!free_plugin_codes.includes(plugin.code)" class="btn btn-sm btn-outline-secondary" @click="openCustomization">{{ __('admin/plugin.customization_contact') }}</button>
                        <a v-if="plugin.can_update" class="btn btn-sm btn-outline-success" @click="openCustomization">{{ __('admin/plugin.customization_contact') }}</a>
                        <a class="btn btn-outline-danger btn-sm" @click="installedPlugin(plugin.code, 'uninstall')">{{ __('admin/common.uninstall') }}</a>
                      </div>
                      <div v-else>
                        <a class="btn btn-outline-success btn-sm" @click="installedPlugin(plugin.code, 'install')"><i class="bi bi-plus-lg me-1"></i>{{ __('admin/common.install') }}</a>
                      </div>
                    </div>
                    <div>
                      <el-switch :disabled="!plugin.installed" v-model="plugin.status" @change="(e) => {pluginStatusChange(e, plugin.code)}"></el-switch>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else>
          <x-admin-no-data>
            <x-slot:text>
              {{ __('common.no_data') }} <a href="{{ admin_route('plugin_customization.index') }}"><i class="bi bi-wechat"></i> {{ __('admin/plugin.customization_contact') }}</a>
            </x-slot>
          </x-admin-no-data>
        </div>
      </div>
    </div>
    </div>
  </div>
@endsection

@push('footer')
  <script>
    const plugins = @json($plugins ?? []);

    // 签名密钥状态管理
    let signatureSecretApp = new Vue({
      el: '#signature-secret-app',
      data: {
        signatureStatus: {
          has_secret: false,
          secret_length: 0,
          secret_preview: '',
        },
        fetchingSecret: false,
      },
      mounted() {
        this.loadSignatureSecretStatus();
      },
      methods: {
        loadSignatureSecretStatus() {
          $http.get('{{ admin_route('settings.signature_secret_status') }}', null, {hload: true}).then((res) => {
            if (res.data) {
              this.signatureStatus = res.data;
            }
          }).catch((err) => {
            console.error('{{ trans('admin/plugin.load_signature_secret_failed') }}:', err);
          });
        },
        fetchSignatureSecret() {
          this.fetchingSecret = true;
          $http.post('{{ admin_route('settings.fetch_signature_secret') }}').then((res) => {
            this.fetchingSecret = false;
            if (res.data) {
              layer.msg(res.message || '{{ trans('admin/plugin.signature_secret_fetched') }}');
              this.loadSignatureSecretStatus();
            }
          }).catch((err) => {
            this.fetchingSecret = false;
            const message = err.response?.data?.message || err.message || '{{ trans('admin/plugin.signature_secret_fetch_failed') }}';
            layer.msg(message, () => {});
          });
        },
        getSecretRetryButtonText() {
          if (this.fetchingSecret) {
            return '{{ trans('admin/plugin.fetching') }}';
          }
          return '{{ trans('admin/plugin.retry_fetch_signature_secret') }}';
        }
      }
    });

    let app = new Vue({
      el: '#plugins-app',

      data: {
        plugins: plugins,
        free_plugin_codes: @json(config('app.free_plugin_codes')),
        statusLoading: {},
        statusRollbackGuard: {},
        pluginKeyword: '',
        typeFilter: 'all',
        statusFilter: 'all',
        diagnosticsLoading: false,
        healthByCode: {},
      },

      computed: {
        availableTypes() { return [...new Set(this.plugins.map(plugin => plugin.type).filter(Boolean))]; },
        summary() { return { enabled: this.plugins.filter(plugin => Number(plugin.status) === 1).length, attention: Object.values(this.healthByCode).filter(item => item.status === 'attention').length }; },
        filteredPlugins() {
          const keyword = this.pluginKeyword.toLocaleLowerCase();
          return this.plugins.filter(plugin => {
            const health = this.pluginHealth(plugin);
            const matchesKeyword = !keyword || [plugin.name, plugin.description, plugin.code, plugin.type_format].some(value => String(value || '').toLocaleLowerCase().includes(keyword));
            const matchesType = this.typeFilter === 'all' || plugin.type === this.typeFilter;
            const matchesStatus = this.statusFilter === 'all' || (this.statusFilter === 'enabled' && Number(plugin.status) === 1) || (this.statusFilter === 'attention' && health.status === 'attention');
            return matchesKeyword && matchesType && matchesStatus;
          });
        },
      },

      beforeMount() {

      },

      methods: {
        runDiagnostics() {
          this.diagnosticsLoading = true;
          $http.get('{{ admin_route('plugins.diagnostics') }}').then((res) => {
            const result = res.data || {};
            this.healthByCode = (result.plugins || []).reduce((all, plugin) => { all[plugin.code] = plugin; return all; }, {});
            const rows = (result.plugins || []).map((plugin) => {
              const issues = (plugin.issues || []).length ? plugin.issues.join('<br>') : '检查通过';
              return `<tr><td>${this.escapeDiagnosticsHtml(plugin.name || plugin.code)}</td><td>${plugin.status === 'healthy' ? '<span class="text-success">通过</span>' : '<span class="text-warning">需处理</span>'}</td><td>${this.escapeDiagnosticsHtml(issues).replace(/&lt;br&gt;/g, '<br>')}</td></tr>`;
            }).join('');
            layer.open({type: 1, title: `插件自检：${result.healthy || 0} 通过，${result.attention || 0} 需处理`, area: ['min(780px, calc(100vw - 32px))', 'auto'], content: `<div class="p-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>插件</th><th>结果</th><th>说明</th></tr></thead><tbody>${rows}</tbody></table></div></div>`});
          }).catch((err) => layer.msg(this.resolveApiErrorMessage(err, 'diagnostics'))).finally(() => { this.diagnosticsLoading = false; });
        },

        loadDiagnostics() { $http.get('{{ admin_route('plugins.diagnostics') }}').then((res) => { const result = res.data || {}; this.healthByCode = (result.plugins || []).reduce((all, plugin) => { all[plugin.code] = plugin; return all; }, {}); }).catch(() => {}); },
        pluginHealth(plugin) { return this.healthByCode[plugin.code] || {status: 'pending', issues: [], configuration: {required: 0, complete: 0}, pending_migrations: 0}; },
        pluginHealthLabel(plugin) { return {healthy: '运行正常', attention: '需要处理', pending: '等待检查'}[this.pluginHealth(plugin).status] || '等待检查'; },
        typeLabel(type) { return ({payment:'支付', shipping:'配送', marketing:'营销', analysis:'分析', feature:'功能', service:'服务', theme:'主题'})[type] || type; },
        configurationText(plugin) { const config = this.pluginHealth(plugin).configuration || {}; return config.required ? `${config.complete || 0}/${config.required}` : '无需配置'; },
        configurationPercent(plugin) { const config = this.pluginHealth(plugin).configuration || {}; return !config.required ? 100 : Math.round((config.complete || 0) / config.required * 100); },

        escapeDiagnosticsHtml(value) {
          // Keep entity values in JavaScript Unicode form. HTML character
          // references inside an inline script are decoded by browsers before
          // JavaScript parses them, which previously turned `&#39;` into an
          // invalid sequence of adjacent quote characters.
          const entities = {
            '\u0026': '\u0026amp;',
            '\u003c': '\u0026lt;',
            '\u003e': '\u0026gt;',
            '\u0027': '\u0026#39;',
            '\u0022': '\u0026quot;',
          };

          return String(value || '').replace(/[&<>'"]/g, (character) => entities[character]);
        },

        openCustomization() {
          window.location.href = @json(admin_route('plugin_customization.index'));
        },

        showPluginDetail(code) {
          if (!this.free_plugin_codes.includes(code)) {
            this.openCustomization();
          }
        },

        pluginStatusChange(e, code) {
          if (this.statusRollbackGuard[code]) {
            this.$delete(this.statusRollbackGuard, code);
            return;
          }

          if (this.statusLoading[code]) {
            return;
          }

          this.$set(this.statusLoading, code, true);

          $http.put(`plugins/${code}/status`, {status: e * 1}).then((res) => {
            layer.msg(res.message || '{{ __("common.updated_success") }}');
          }).catch((err) => {
            this.$set(this.statusRollbackGuard, code, true);

            const plugin = this.plugins.find(item => item.code === code); if (plugin) plugin.status = Number(!e);

            layer.msg(this.resolveApiErrorMessage(err, code));
          }).finally(() => {
            this.$set(this.statusLoading, code, false);
          });
        },

        resolveApiErrorMessage(err, code) {
          const message = err?.response?.data?.message || err?.message || '';
          const lowerMessage = message.toLowerCase();

          // Check for generic server error messages (either in Chinese or English)
          if (!message || lowerMessage.includes('server') || lowerMessage.includes('系统开小差了') || lowerMessage.includes('temporarily unavailable')) {
            return `{{ trans('admin/plugin.plugin_status_update_failed', ['code' => '${code}']) }}`;
          }

          if (message.includes('No query results for model')) {
            return `{{ trans('admin/plugin.plugin_not_found_or_inactive', ['code' => '${code}']) }}`;
          }

          return message;
        },

        installedPlugin(code, type) {
          if (type == 'uninstall') {
            layer.confirm('{{ __('admin/plugin.uninstall_hint') }}', {
              title: "{{ __('common.text_hint') }}",
              btn: ['{{ __('common.cancel') }}', '{{ __('common.confirm') }}'],
              area: ['400px'],
              btn2: () => {
                this.installedPluginXhr(code, type);
              }
            })
            return;
          }

          this.installedPluginXhr(code, type);
        },

        installedPluginXhr(code, type) {
          $http.post(`plugins/${code}/${type}`).then((res) => {
            layer.msg(res.message)
            const plugin = this.plugins.find(item => item.code === code); if (plugin) plugin.installed = type == 'install'; this.loadDiagnostics();
          })
        },

        updatePlugin(code) {
          this.openCustomization();
        },

        pluginServiceExpired(code) {
          return Promise.resolve({expired: false});
        }
      },

      mounted() {
        window.longosPluginDiagnostics = () => this.runDiagnostics();
        this.loadDiagnostics();
        // 高亮并滚动到指定插件
        const urlParams = new URLSearchParams(window.location.search);
        const highlightCode = urlParams.get('highlight');
        if (highlightCode) {
          this.$nextTick(() => {
            const targetRow = $('.plugin-item[data-code=' + highlightCode + ']');
            if (targetRow.length) {
              targetRow.addClass('border-primary overflow-hidden');
              $('#content').animate({scrollTop: targetRow.offset().top - 30}, 500);
              setTimeout(() => {
                targetRow.removeClass('border-primary overflow-hidden');
              }, 3000);
            }
          });
        }
      }
    })

    window.addEventListener('message', function (event) {
      if (event.data.type == 'marketing_buy_services_close_pop') {
        layer.closeAll();
        location.reload();
      }
    });
  </script>
@endpush
