@extends('admin::layouts.master')

@section('title', __('admin/common.account_index'))

@section('content-area-class', 'w-max-1400')

@section('page-title-back', true)

@section('content')
<div id="integration-token-app" class="card h-min-600" v-cloak>
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
      <div>
        <h5 class="mb-2">{{ __('admin/account.create_token') }}</h5>
        <p class="text-secondary mb-0">{{ __('admin/account.integration_intro') }}</p>
      </div>
      @if($integration_available)
      <button type="button" class="btn btn-primary" @click="createDialog = true">
        <i class="bi bi-plus-lg me-1"></i>{{ __('admin/account.create_cloud_mail_token') }}
      </button>
      @endif
    </div>

    @if($integration_available)
    <div class="alert alert-info d-flex flex-column gap-1">
      <div><strong>{{ __('admin/account.relay_url') }}:</strong> <code>{{ $relay_url }}</code></div>
      <div><strong>{{ __('admin/account.worker_secret') }}:</strong> <code>LONGOS_INTEGRATION_TOKEN</code></div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead>
          <tr>
            <th>{{ __('admin/account.token_name') }}</th>
            <th>{{ __('admin/account.token_hint') }}</th>
            <th>{{ __('admin/account.abilities') }}</th>
            <th>{{ __('admin/account.last_used_at') }}</th>
            <th>{{ __('admin/account.expires_at') }}</th>
            <th>{{ __('admin/account.token_status') }}</th>
            <th class="text-center wp-100">{{ __('common.action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in tokens" :key="item.id">
            <td>@{{ item.name }}</td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <code class="text-break">@{{ revealedTokens[item.id] || ('longos_it_••••••••...' + (item.token_hint || '--------')) }}</code>
                <button
                  v-if="item.can_reveal"
                  type="button"
                  class="btn btn-outline-secondary btn-sm flex-shrink-0"
                  :disabled="Boolean(revealingTokenIds[item.id])"
                  :title="revealedTokens[item.id] ? labels.hideToken : labels.showToken"
                  :aria-label="revealedTokens[item.id] ? labels.hideToken : labels.showToken"
                  @click="reveal(item)"
                >
                  <span v-if="revealingTokenIds[item.id]" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                  <i v-else class="bi" :class="revealedTokens[item.id] ? 'bi-eye-slash' : 'bi-eye'"></i>
                </button>
                <button
                  v-if="revealedTokens[item.id]"
                  type="button"
                  class="btn btn-outline-primary btn-sm flex-shrink-0"
                  title="{{ __('admin/account.copy_token') }}"
                  aria-label="{{ __('admin/account.copy_token') }}"
                  @click="copy(revealedTokens[item.id])"
                ><i class="bi bi-clipboard"></i></button>
              </div>
              <small v-if="item.last_revealed_at" class="d-block text-secondary mt-1">
                {{ __('admin/account.last_revealed_at') }}: @{{ formatDate(item.last_revealed_at, '-') }} ·
                {{ __('admin/account.reveal_count') }}: @{{ item.reveal_count || 0 }}
              </small>
            </td>
            <td>
              <span v-for="ability in item.abilities" :key="ability" class="badge bg-light text-dark border me-1">@{{ ability }}</span>
            </td>
            <td>@{{ formatDate(item.last_used_at, labels.neverUsed) }}</td>
            <td>@{{ formatDate(item.expires_at, '-') }}</td>
            <td><span class="badge" :class="status(item).className">@{{ status(item).label }}</span></td>
            <td class="text-center">
              <button v-if="!item.revoked_at" type="button" class="btn btn-outline-danger btn-sm" @click="revoke(item)">
                {{ __('admin/account.revoke_token') }}
              </button>
            </td>
          </tr>
          <tr v-if="tokens.length === 0">
            <td colspan="7" class="text-center text-secondary py-5">{{ __('common.no_data') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    @else
    <div class="alert alert-warning mb-0" role="alert">
      {{ __('admin/account.integration_control_only') }}
    </div>
    @endif
  </div>

  @if($integration_available)
  <el-dialog title="{{ __('admin/account.create_cloud_mail_token') }}" :visible.sync="createDialog" width="520px" :close-on-click-modal="false">
    <el-form label-position="top">
      <el-form-item label="{{ __('admin/account.token_name') }}">
        <el-input v-model.trim="form.name" maxlength="80" show-word-limit></el-input>
      </el-form-item>
      <el-form-item label="{{ __('admin/account.token_expires') }}">
        <el-select v-model="form.expires_days" class="w-100">
          <el-option label="{{ __('admin/account.token_days', ['days' => 90]) }}" :value="90"></el-option>
          <el-option label="{{ __('admin/account.token_days', ['days' => 365]) }}" :value="365"></el-option>
          <el-option label="{{ __('admin/account.token_days', ['days' => 730]) }}" :value="730"></el-option>
        </el-select>
      </el-form-item>
    </el-form>
    <span slot="footer">
      <el-button @click="createDialog = false">{{ __('common.cancel') }}</el-button>
      <el-button type="primary" :loading="creating" @click="createToken">{{ __('common.confirm') }}</el-button>
    </span>
  </el-dialog>

  <el-dialog title="{{ __('admin/account.token_created') }}" :visible.sync="tokenDialog" width="620px" :close-on-click-modal="false" :close-on-press-escape="false">
    <el-alert type="warning" :closable="false" show-icon class="mb-3" title="{{ __('admin/account.token_once_notice') }}"></el-alert>
    <label class="form-label">LONGOS_INTEGRATION_TOKEN</label>
    <div class="input-group">
      <textarea class="form-control font-monospace" rows="3" readonly :value="createdToken"></textarea>
      <button type="button" class="btn btn-outline-primary" @click="copy(createdToken)"><i class="bi bi-clipboard"></i></button>
    </div>
    <label class="form-label mt-3">LONGOS_RELAY_URL</label>
    <div class="input-group">
      <input class="form-control font-monospace" readonly :value="relayUrl">
      <button type="button" class="btn btn-outline-primary" @click="copy(relayUrl)"><i class="bi bi-clipboard"></i></button>
    </div>
    <span slot="footer"><el-button type="primary" @click="closeTokenDialog">{{ __('common.confirm') }}</el-button></span>
  </el-dialog>
  @endif
</div>
@endsection

@push('footer')
<script>
  new Vue({
    el: '#integration-token-app',
    data: {
      tokens: @json($tokens),
      relayUrl: @json($relay_url),
      createDialog: false,
      tokenDialog: false,
      creating: false,
      createdToken: '',
      revealedTokens: {},
      revealingTokenIds: {},
      form: {name: @json(__('admin/account.token_name_default')), expires_days: 365},
      labels: {
        neverUsed: @json(__('admin/account.never_used')),
        hideToken: @json(__('admin/account.hide_token')),
        showToken: @json(__('admin/account.show_token')),
      },
    },
    methods: {
      formatDate(value, fallback) {
        if (!value) return fallback;
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? fallback : date.toLocaleString();
      },
      status(item) {
        if (item.revoked_at) return {label: @json(__('admin/account.revoked')), className: 'bg-secondary'};
        if (item.expires_at && new Date(item.expires_at).getTime() <= Date.now()) {
          return {label: @json(__('admin/account.expired')), className: 'bg-warning text-dark'};
        }
        return {label: @json(__('admin/account.active')), className: 'bg-success'};
      },
      createToken() {
        if (!this.form.name) return;
        this.creating = true;
        $http.post(@json(admin_route('account.tokens.store')), this.form).then(res => {
          const data = res.data || {};
          this.createdToken = data.plain_text_token || '';
          this.relayUrl = data.relay_url || this.relayUrl;
          this.$set(this.revealedTokens, data.id, this.createdToken);
          this.tokens.unshift({
            id: data.id,
            name: this.form.name,
            token_hint: data.token_hint,
            can_reveal: true,
            abilities: ['cloud-mail:send', 'cloud-mail:health'],
            created_at: new Date().toISOString(),
            last_used_at: null,
            last_revealed_at: null,
            reveal_count: 0,
            expires_at: data.expires_at,
            revoked_at: null,
          });
          this.createDialog = false;
          this.tokenDialog = true;
          layer.msg(res.message);
        }).finally(() => { this.creating = false; });
      },
      reveal(item) {
        if (this.revealedTokens[item.id]) {
          this.$delete(this.revealedTokens, item.id);
          return;
        }

        if (this.revealingTokenIds[item.id]) return;
        this.$set(this.revealingTokenIds, item.id, true);
        const url = @json(admin_route('account.tokens.reveal', ['token' => '__TOKEN__'])).replace('__TOKEN__', item.id);
        $http.post(url, {}).then(res => {
          const data = res.data || {};
          const token = data.plain_text_token || '';
          if (token) this.$set(this.revealedTokens, item.id, token);
          item.last_revealed_at = data.last_revealed_at || item.last_revealed_at;
          item.reveal_count = Number(data.reveal_count || item.reveal_count || 0);
          layer.msg(res.message);
        }).finally(() => { this.$delete(this.revealingTokenIds, item.id); });
      },
      revoke(item) {
        layer.confirm(@json(__('admin/account.revoke_confirm')), () => {
          const url = @json(admin_route('account.tokens.destroy', ['token' => '__TOKEN__'])).replace('__TOKEN__', item.id);
          axios.delete(url).then(res => {
            item.revoked_at = new Date().toISOString();
            layer.msg(res.data.message);
          });
        });
      },
      async copy(value) {
        try {
          await navigator.clipboard.writeText(value);
        } catch (error) {
          const textarea = document.createElement('textarea');
          textarea.value = value;
          textarea.style.position = 'fixed';
          textarea.style.opacity = '0';
          document.body.appendChild(textarea);
          textarea.select();
          document.execCommand('copy');
          textarea.remove();
        }
        layer.msg(@json(__('admin/account.copy_success')));
      },
      closeTokenDialog() {
        this.createdToken = '';
        this.tokenDialog = false;
      },
    },
  });
</script>
@endpush
