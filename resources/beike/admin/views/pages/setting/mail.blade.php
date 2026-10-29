@extends('admin::layouts.master')

@section('title', __('admin/common.settings.mail'))

@section('content-area-class', 'w-max-1200')

@section('page-title-back', admin_route('settings.index'))

@section('head-form-btns', true)

@section('content')
  @php
    $senderService = app(\App\Mail\MailSenderProfileService::class);
    $senderProfiles = $senderService->profiles();
    $senderCategories = \App\Mail\MailSenderProfileService::categories();
    $availableSenderDomains = $senderService->availableDomains();
    $senderSuggestions = collect(array_keys($senderCategories))->mapWithKeys(
        static fn ($category) => [$category => $senderService->suggestedAddresses($category, $availableSenderDomains)]
    )->all();
    $senderDomainPresets = collect($availableSenderDomains)->mapWithKeys(
        static fn ($domain) => [$domain => collect(array_keys($senderCategories))->mapWithKeys(
            static fn ($category) => [$category => $senderService->suggestedAddresses($category, [$domain])[0] ?? '']
        )->all()]
    )->all();
    $defaultSenderCategory = \App\Mail\MailSenderProfileService::DEFAULT;
    $advancedSenderCategories = array_filter(
        $senderCategories,
        static fn ($label, $category) => $category !== $defaultSenderCategory,
        ARRAY_FILTER_USE_BOTH
    );
    $oldSenderProfiles = old('mail_senders', []);
    $oldSenderProfiles = is_array($oldSenderProfiles) ? $oldSenderProfiles : [];
    $advancedSendersCustomized = collect(array_keys($advancedSenderCategories))->contains(function ($category) use ($senderProfiles, $oldSenderProfiles) {
        $profile = array_key_exists($category, $oldSenderProfiles)
            ? (array) $oldSenderProfiles[$category]
            : $senderProfiles[$category];

        return collect(['address', 'name', 'reply_to'])->contains(
            static fn ($field) => trim((string) ($profile[$field] ?? '')) !== ''
        );
    });
    $savedMailEngine = old('mail_engine', system_setting('base.mail_engine', ''));
    if ($savedMailEngine === '' && config('mail.relay.enabled')) {
        $savedMailEngine = 'self_hosted';
    }
    $dnsSyncAvailable = app(\App\Mail\CloudflareMailDnsService::class)->configured();
    $relayRuntimeValues = (array) data_get($mailRelayRuntime, 'values', []);
    $relayForm = old('mail_relay', $relayRuntimeValues);
    $relayForm = is_array($relayForm) ? $relayForm : $relayRuntimeValues;
    $relayMode = in_array(data_get($relayForm, 'mode'), ['internal', 'external'], true)
        ? data_get($relayForm, 'mode')
        : 'internal';
    $relayNode = (array) data_get($relayForm, 'node', \App\Mail\MailRelayNodeProfile::defaults());
    $mailPageData = [
        'mailEngine' => $savedMailEngine,
        'testRecipient' => old('mail_test_recipient', $senderProfiles[\App\Mail\MailSenderProfileService::DEFAULT]['address']),
        'testUrl' => admin_route('settings.mail.test'),
        'diagnosticsUrl' => admin_route('settings.mail.diagnostics'),
        'dnsSyncUrl' => admin_route('settings.mail.dns-sync'),
        'dnsSyncAvailable' => $dnsSyncAvailable,
        'senderDomainPresets' => $senderDomainPresets,
        'labels' => [
            'testRecipient' => __('admin/setting.mail_test_recipient'),
            'testButton' => __('admin/setting.mail_test_button'),
            'testSending' => __('admin/setting.mail_test_sending'),
            'testSent' => __('admin/setting.mail_test_sent'),
            'testFailed' => __('admin/setting.mail_test_failed'),
            'diagnosticsButton' => __('admin/setting.mail_diagnostics_button'),
            'diagnosticsRunning' => __('admin/setting.mail_diagnostics_running'),
            'diagnosticsFailed' => __('admin/setting.mail_diagnostics_failed'),
            'dnsSyncButton' => __('admin/setting.mail_dns_sync_button'),
            'dnsSyncRunning' => __('admin/setting.mail_dns_sync_running'),
            'dnsSyncConfirm' => __('admin/setting.mail_dns_sync_confirm'),
            'dnsSyncFailed' => __('admin/setting.mail_dns_sync_failed'),
            'dnsSyncPropagation' => __('admin/setting.mail_dns_sync_propagation'),
            'dnsSyncExternalHelp' => __('admin/setting.mail_dns_sync_external_help'),
            'senderDomainApplied' => __('admin/setting.mail_sender_domain_applied'),
            'dnsRecordPending' => __('admin/setting.mail_dns_record_pending'),
            'dnsRecordCopied' => __('admin/setting.mail_dns_record_copied'),
        ],
    ];
  @endphp
  @if (session('success'))
    <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
  @endif
  @if (session('error'))
    <div class="alert alert-danger">
      {{ session('error') }}
    </div>
  @endif
  @if($canManageMailRelay && $mailRelayRuntime)
    <div class="card mb-4" id="mail-relay-topology">
      <div class="card-header bg-white d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 py-3">
        <div>
          <div class="d-flex align-items-center gap-2">
            <h5 class="mb-0">{{ __('admin/setting.mail_relay_runtime_title') }}</h5>
            <span class="badge {{ data_get($mailRelayRuntime, 'source') === 'control_panel' ? 'bg-primary' : (data_get($mailRelayRuntime, 'source') === 'invalid' ? 'bg-danger' : 'bg-secondary') }}">
              {{ __('admin/setting.mail_relay_runtime_source_' . data_get($mailRelayRuntime, 'source', 'deployment')) }}
            </span>
          </div>
          <div class="text-secondary font-size-12 mt-1">{{ __('admin/setting.mail_relay_runtime_help') }}</div>
        </div>
        <a class="btn btn-outline-primary btn-sm" href="#mail-diagnostics" aria-label="{{ __('admin/setting.mail_diagnostics_button') }}">
          <i class="bi bi-heart-pulse me-1"></i>{{ __('admin/setting.mail_diagnostics_button') }}
        </a>
      </div>
      <div class="card-body">
        @if(data_get($mailRelayRuntime, 'error'))
          <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-octagon me-1"></i>{{ data_get($mailRelayRuntime, 'error') }}
          </div>
        @endif

        <div class="row g-3 mb-4" aria-label="{{ __('admin/setting.mail_relay_runtime_steps') }}">
          <div class="col-md-4">
            <div class="border rounded h-100 p-3">
              <div class="text-primary fw-bold mb-1">01 · {{ __('admin/setting.mail_relay_step_topology') }}</div>
              <div class="text-secondary font-size-12">{{ __('admin/setting.mail_relay_step_topology_help') }}</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded h-100 p-3">
              <div class="text-primary fw-bold mb-1">02 · {{ __('admin/setting.mail_relay_step_identity') }}</div>
              <div class="text-secondary font-size-12">{{ __('admin/setting.mail_relay_step_identity_help') }}</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded h-100 p-3">
              <div class="text-primary fw-bold mb-1">03 · {{ __('admin/setting.mail_relay_step_dns') }}</div>
              <div class="text-secondary font-size-12">{{ __('admin/setting.mail_relay_step_dns_help') }}</div>
            </div>
          </div>
        </div>

        <form action="{{ admin_route('settings.mail.relay.store') }}" method="POST" id="mail-relay-runtime-form" class="needs-validation" novalidate>
          @csrf
          <fieldset class="mb-4">
            <legend class="form-label fw-bold mb-2">{{ __('admin/setting.mail_relay_mode') }}</legend>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="border rounded p-3 d-flex gap-3 h-100 mail-relay-mode-card" for="mail-relay-mode-internal">
                  <input class="form-check-input mt-1" type="radio" name="mail_relay[mode]" id="mail-relay-mode-internal" value="internal" @checked($relayMode === 'internal')>
                  <span>
                    <span class="fw-bold d-block"><i class="bi bi-box-seam me-1"></i>{{ __('admin/setting.mail_relay_mode_internal') }}</span>
                    <span class="text-secondary font-size-12">{{ __('admin/setting.mail_relay_mode_internal_help') }}</span>
                  </span>
                </label>
              </div>
              <div class="col-md-6">
                <label class="border rounded p-3 d-flex gap-3 h-100 mail-relay-mode-card" for="mail-relay-mode-external">
                  <input class="form-check-input mt-1" type="radio" name="mail_relay[mode]" id="mail-relay-mode-external" value="external" @checked($relayMode === 'external')>
                  <span>
                    <span class="fw-bold d-block"><i class="bi bi-hdd-network me-1"></i>{{ __('admin/setting.mail_relay_mode_external') }}</span>
                    <span class="text-secondary font-size-12">{{ __('admin/setting.mail_relay_mode_external_help') }}</span>
                  </span>
                </label>
              </div>
            </div>
          </fieldset>

          <div id="mail-relay-internal-notice" class="alert alert-light border mb-4 {{ $relayMode === 'internal' ? '' : 'd-none' }}">
            <i class="bi bi-info-circle me-1"></i>{{ __('admin/setting.mail_relay_internal_locked') }}
          </div>

          <div id="mail-relay-external-fields" class="border rounded bg-light p-3 mb-4 {{ $relayMode === 'external' ? '' : 'd-none' }}">
            <label class="form-label fw-bold" for="mail-relay-endpoint">{{ __('admin/setting.mail_relay_endpoint') }}</label>
            <input class="form-control font-monospace" type="url" id="mail-relay-endpoint" name="mail_relay[endpoint]"
                   value="{{ data_get($relayForm, 'endpoint', '') }}" placeholder="https://relay-api.example.com" maxlength="2048"
                   inputmode="url" autocomplete="off" spellcheck="false" aria-describedby="mail-relay-endpoint-help">
            <div id="mail-relay-endpoint-help" class="form-text">{{ __('admin/setting.mail_relay_endpoint_help') }}</div>
          </div>

          <div class="row g-3">
            <div class="col-lg-6">
              <label class="form-label fw-bold" for="mail-relay-hostname">{{ __('admin/setting.mail_relay_hostname') }}</label>
              <input class="form-control font-monospace" type="text" id="mail-relay-hostname" name="mail_relay[hostname]"
                     value="{{ data_get($relayForm, 'hostname', '') }}" placeholder="mailout.example.com" maxlength="253"
                     required autocomplete="off" spellcheck="false">
              <div class="form-text">{{ __('admin/setting.mail_relay_hostname_help') }}</div>
            </div>
            <div class="col-lg-6">
              <label class="form-label fw-bold" for="mail-relay-public-ips">{{ __('admin/setting.mail_relay_public_ips') }}</label>
              <input class="form-control font-monospace" type="text" id="mail-relay-public-ips" name="mail_relay[public_ips]"
                     value="{{ implode(', ', (array) data_get($relayForm, 'public_ips', [])) }}" placeholder="your-server-public-ip"
                     autocomplete="off" spellcheck="false" aria-describedby="mail-relay-ptr-preview">
              <div class="form-text">{{ __('admin/setting.mail_relay_public_ips_help') }}</div>
            </div>
            <div class="col-lg-6">
              <label class="form-label fw-bold" for="mail-relay-domains">{{ __('admin/setting.mail_relay_domains') }}</label>
              <textarea class="form-control font-monospace" id="mail-relay-domains" name="mail_relay[domains]" rows="2" required
                        autocomplete="off" spellcheck="false" placeholder="example.com">{{ implode("\n", (array) data_get($relayForm, 'domains', [])) }}</textarea>
              <div class="form-text">{{ __('admin/setting.mail_relay_domains_help') }}</div>
            </div>
            <div class="col-lg-6">
              <label class="form-label fw-bold" for="mail-relay-allowed-domains">{{ __('admin/setting.mail_relay_allowed_domains') }}</label>
              <textarea class="form-control font-monospace" id="mail-relay-allowed-domains" name="mail_relay[allowed_sender_domains]" rows="2" required
                        autocomplete="off" spellcheck="false" placeholder="example.com">{{ implode("\n", (array) data_get($relayForm, 'allowed_sender_domains', [])) }}</textarea>
              <div class="form-text">{{ __('admin/setting.mail_relay_allowed_domains_help') }}</div>
            </div>
            <div class="col-lg-6">
              <label class="form-label fw-bold" for="mail-relay-platform-domain">{{ __('admin/setting.mail_relay_platform_domain') }}</label>
              <input class="form-control font-monospace" type="text" id="mail-relay-platform-domain" name="mail_relay[platform_sender_domain]"
                     value="{{ data_get($relayForm, 'platform_sender_domain', '') }}" placeholder="example.com" maxlength="253"
                     required autocomplete="off" spellcheck="false">
              <div class="form-text">{{ __('admin/setting.mail_relay_platform_domain_help') }}</div>
            </div>
            <div class="col-lg-6">
              <label class="form-label fw-bold" for="mail-relay-dkim-selector">{{ __('admin/setting.mail_relay_dkim_selector') }}</label>
              <input class="form-control font-monospace" type="text" id="mail-relay-dkim-selector" name="mail_relay[dkim_selector]"
                     value="{{ data_get($relayForm, 'dkim_selector', 'mail2026') }}" placeholder="mail2026" maxlength="63" pattern="[a-z0-9][a-z0-9_-]{0,62}"
                     required autocomplete="off" spellcheck="false">
              <div class="form-text">{{ __('admin/setting.mail_relay_dkim_selector_help') }}</div>
            </div>
          </div>

          <div id="mail-relay-auto-detect-row" class="form-check form-switch mt-4 {{ $relayMode === 'internal' ? '' : 'd-none' }}">
            <input type="hidden" name="mail_relay[auto_detect_public_ips]" value="0">
            <input class="form-check-input" type="checkbox" role="switch" id="mail-relay-auto-detect" name="mail_relay[auto_detect_public_ips]" value="1"
                   @checked((bool) data_get($relayForm, 'auto_detect_public_ips', true))>
            <label class="form-check-label fw-bold" for="mail-relay-auto-detect">{{ __('admin/setting.mail_relay_auto_detect') }}</label>
            <div class="form-text">{{ __('admin/setting.mail_relay_auto_detect_help') }}</div>
          </div>

          <div class="alert alert-info mt-4 mb-0" role="status">
            <div class="fw-bold mb-2"><i class="bi bi-arrow-left-right me-1"></i>{{ __('admin/setting.mail_relay_ptr_title') }}</div>
            <pre id="mail-relay-ptr-preview" class="font-monospace text-break bg-transparent border-0 p-0 mb-0" style="white-space: pre-wrap">—</pre>
            <div class="font-size-12 mt-2">{{ __('admin/setting.mail_relay_ptr_help') }}</div>
          </div>

          <details id="mail-relay-node-config" class="border rounded mt-4" @if($relayMode === 'external') open @endif>
            <summary class="px-3 py-3 fw-bold" style="cursor: pointer">{{ __('admin/setting.mail_relay_node_config_title') }}</summary>
            <div class="px-3 pb-3">
              <div class="text-secondary font-size-12 mb-3">{{ __('admin/setting.mail_relay_node_config_help') }}</div>

              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label fw-bold" for="mail-relay-node-inet-protocols">{{ __('admin/setting.mail_relay_node_inet_protocols') }}</label>
                  <select class="form-select" id="mail-relay-node-inet-protocols" name="mail_relay[node][inet_protocols]">
                    @foreach(['ipv4', 'ipv6', 'all'] as $protocol)
                      <option value="{{ $protocol }}" @selected(data_get($relayNode, 'inet_protocols', 'ipv4') === $protocol)>{{ $protocol }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold" for="mail-relay-node-http-port">{{ __('admin/setting.mail_relay_node_http_port') }}</label>
                  <input class="form-control font-monospace" type="number" min="1024" max="65535" id="mail-relay-node-http-port"
                         name="mail_relay[node][relay_http_port]" value="{{ data_get($relayNode, 'relay_http_port', 8788) }}" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold" for="mail-relay-node-volume-prefix">{{ __('admin/setting.mail_relay_node_volume_prefix') }}</label>
                  <input class="form-control font-monospace" type="text" maxlength="63" pattern="[a-z0-9][a-z0-9-]{0,62}"
                         id="mail-relay-node-volume-prefix" name="mail_relay[node][volume_prefix]"
                         value="{{ data_get($relayNode, 'volume_prefix', 'longos-remote-mail') }}" required autocomplete="off" spellcheck="false">
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold" for="mail-relay-node-tunnel-protocol">{{ __('admin/setting.mail_relay_node_tunnel_protocol') }}</label>
                  <select class="form-select" id="mail-relay-node-tunnel-protocol" name="mail_relay[node][tunnel_protocol]">
                    @foreach(['auto', 'quic', 'http2'] as $protocol)
                      <option value="{{ $protocol }}" @selected(data_get($relayNode, 'tunnel_protocol', 'auto') === $protocol)>{{ $protocol }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold" for="mail-relay-node-tunnel-log-level">{{ __('admin/setting.mail_relay_node_tunnel_log_level') }}</label>
                  <select class="form-select" id="mail-relay-node-tunnel-log-level" name="mail_relay[node][tunnel_log_level]">
                    @foreach(['debug', 'info', 'warn', 'error', 'fatal'] as $level)
                      <option value="{{ $level }}" @selected(data_get($relayNode, 'tunnel_log_level', 'info') === $level)>{{ $level }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label fw-bold" for="mail-relay-node-metrics-address">{{ __('admin/setting.mail_relay_node_metrics_address') }}</label>
                  <input class="form-control font-monospace" type="text" id="mail-relay-node-metrics-address"
                         name="mail_relay[node][tunnel_metrics_address]" value="{{ data_get($relayNode, 'tunnel_metrics_address', '127.0.0.1:20246') }}"
                         pattern="127\.0\.0\.1:[0-9]{4,5}" required autocomplete="off" spellcheck="false">
                </div>
                <div class="col-12">
                  <label class="form-label fw-bold" for="mail-relay-node-cloudflared-image">{{ __('admin/setting.mail_relay_node_cloudflared_image') }}</label>
                  <input class="form-control font-monospace" type="text" id="mail-relay-node-cloudflared-image"
                         name="mail_relay[node][cloudflared_image]" value="{{ data_get($relayNode, 'cloudflared_image', 'cloudflare/cloudflared:2026.10.5') }}"
                         pattern="cloudflare/cloudflared:[0-9][a-zA-Z0-9._-]{0,63}" required autocomplete="off" spellcheck="false">
                  <div class="form-text">{{ __('admin/setting.mail_relay_node_network_help') }}</div>
                </div>
              </div>

              <div class="border-top mt-4 pt-3">
                <div class="fw-bold mb-1">{{ __('admin/setting.mail_relay_node_resources') }}</div>
                <div class="text-secondary font-size-12 mb-3">{{ __('admin/setting.mail_relay_node_resources_help') }}</div>
                <div class="row g-3">
                  @foreach([
                    ['relay_memory_limit', 'mail-relay-node-relay-memory-limit', 'mail_relay_node_relay_memory_limit', '768m'],
                    ['relay_memory_reservation', 'mail-relay-node-relay-memory-reservation', 'mail_relay_node_relay_memory_reservation', '256m'],
                    ['relay_pids_limit', 'mail-relay-node-relay-pids-limit', 'mail_relay_node_relay_pids_limit', 256],
                    ['tunnel_memory_limit', 'mail-relay-node-tunnel-memory-limit', 'mail_relay_node_tunnel_memory_limit', '256m'],
                    ['tunnel_memory_reservation', 'mail-relay-node-tunnel-memory-reservation', 'mail_relay_node_tunnel_memory_reservation', '64m'],
                    ['tunnel_pids_limit', 'mail-relay-node-tunnel-pids-limit', 'mail_relay_node_tunnel_pids_limit', 128],
                  ] as [$field, $id, $label, $default])
                    @php
                      $isPidsLimit = str_ends_with($field, 'pids_limit');
                    @endphp
                    <div class="col-md-4">
                      <label class="form-label" for="{{ $id }}">{{ __('admin/setting.' . $label) }}</label>
                      <input class="form-control font-monospace" type="{{ $isPidsLimit ? 'number' : 'text' }}" id="{{ $id }}" name="mail_relay[node][{{ $field }}]"
                             value="{{ data_get($relayNode, $field, $default) }}" required autocomplete="off" spellcheck="false"
                             @if($isPidsLimit) min="32" max="4096" @else pattern="[1-9][0-9]{0,5}[kKmMgG]" maxlength="7" @endif>
                    </div>
                  @endforeach
                </div>
              </div>

              <div class="border-top mt-4 pt-3">
                <div class="fw-bold mb-1">{{ __('admin/setting.mail_relay_node_public_config') }}</div>
                <div class="text-secondary font-size-12 mb-2">{{ __('admin/setting.mail_relay_node_public_config_help') }}</div>
                <pre class="bg-dark text-white rounded p-3 mb-3 text-break" style="white-space: pre-wrap" id="mail-relay-node-config-preview" aria-live="polite"></pre>
                <div class="alert alert-warning py-2 font-size-12">
                  <i class="bi bi-shield-lock me-1"></i>{{ __('admin/setting.mail_relay_node_secret_names') }}
                  <code class="d-block mt-1">LONGOS_RELAY_SHARED_SECRET · CLOUD_MAIL_RELAY_SHARED_SECRET · TUNNEL_TOKEN</code>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <button class="btn btn-outline-secondary btn-sm" type="button" id="mail-relay-copy-node-config">
                    <i class="bi bi-clipboard me-1"></i>{{ __('admin/setting.mail_relay_node_config_copy') }}
                  </button>
                  <button class="btn btn-outline-secondary btn-sm" type="button" id="mail-relay-download-node-config">
                    <i class="bi bi-download me-1"></i>{{ __('admin/setting.mail_relay_node_config_download') }}
                  </button>
                </div>
              </div>
            </div>
          </details>

          <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 border-top mt-4 pt-4">
            <div class="font-size-12">
              <div class="fw-bold mb-1">{{ __('admin/setting.mail_relay_secret_status') }}</div>
              <span class="badge {{ data_get($mailRelayRuntime, 'secret_status.longos') ? 'bg-success' : 'bg-warning text-dark' }} me-1">LONGOS HMAC</span>
              <span class="badge {{ data_get($mailRelayRuntime, 'secret_status.cloud_mail') ? 'bg-success' : 'bg-warning text-dark' }} me-1">Cloud Mail HMAC</span>
              <span class="badge {{ data_get($mailRelayRuntime, 'secret_status.distinct') ? 'bg-success' : 'bg-danger' }}">{{ __('admin/setting.mail_relay_secret_distinct') }}</span>
              <div class="text-secondary mt-1">{{ __('admin/setting.mail_relay_secret_help') }}</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="submit" name="operation" value="reset" class="btn btn-outline-secondary" formnovalidate
                      onclick="return window.confirm('{{ __('admin/setting.mail_relay_runtime_reset_confirm') }}')">
                {{ __('admin/setting.mail_relay_runtime_reset_button') }}
              </button>
              <button type="submit" name="operation" value="save" class="btn btn-primary">
                <i class="bi bi-check2-circle me-1"></i>{{ __('admin/setting.mail_relay_runtime_save') }}
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  @endif
  <div class="card h-min-600">
    <div class="card-body">
      @hook('admin.setting.mail.content.before')
      <form action="{{ admin_route('settings.store') }}" class="needs-validation" novalidate method="POST" id="form-app">
        @csrf
        <input type="hidden" name="return_url" value="{{ url()->full() }}"/>

        @hook('admin.setting.mail.before')

        <div class="border rounded p-3 mb-4" aria-live="polite" id="mail-diagnostics">
          <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
            <div>
              <div class="fw-bold">{{ __('admin/setting.mail_diagnostics_title') }}</div>
              <div class="text-secondary font-size-12">{{ __('admin/setting.mail_diagnostics_help') }}</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button v-if="dnsSyncAvailable" type="button" class="btn btn-primary" :disabled="diagnosing || syncingDns" @click="syncMailDns">
                <i class="bi bi-magic"></i>
                @{{ syncingDns ? source.dnsSyncRunning : source.dnsSyncButton }}
              </button>
              <button type="button" class="btn btn-outline-primary" :disabled="diagnosing || syncingDns" @click="runDiagnostics">
                <i class="bi bi-heart-pulse"></i>
                @{{ diagnosing ? source.diagnosticsRunning : source.diagnosticsButton }}
              </button>
            </div>
          </div>
          <div v-if="!dnsSyncAvailable" class="alert alert-info py-2 px-3 mb-0 mt-3">
            <i class="bi bi-shield-check me-1"></i>@{{ source.dnsSyncExternalHelp }}
          </div>
          <div v-if="diagnosticChecks.length" class="mt-3 d-grid gap-2">
            <div v-for="check in diagnosticChecks" :key="check.key" class="alert py-2 px-3 mb-0"
                 :class="check.status === 'ok' ? 'alert-success' : (check.status === 'warning' ? 'alert-warning' : 'alert-danger')">
              <i class="bi me-1" :class="check.status === 'ok' ? 'bi-check-circle' : (check.status === 'warning' ? 'bi-exclamation-triangle' : 'bi-x-circle')"></i>
              @{{ check.message }}
            </div>
          </div>
          <div v-if="dnsSyncMessage" class="alert alert-success py-2 px-3 mb-0 mt-3">
            <i class="bi bi-check-circle me-1"></i>@{{ dnsSyncMessage }}
          </div>
          <div v-for="warning in dnsSyncWarnings" :key="warning" class="alert alert-warning py-2 px-3 mb-0 mt-2">
            <i class="bi bi-exclamation-triangle me-1"></i>@{{ warning }}
          </div>
          <div v-if="diagnosticError" class="alert alert-danger py-2 px-3 mb-0 mt-3">@{{ diagnosticError }}</div>
          <div v-if="dnsRecords.length" class="mt-4">
            <div class="fw-bold">{{ __('admin/setting.mail_dns_records_title') }}</div>
            <div class="text-secondary font-size-12 mb-2">{{ __('admin/setting.mail_dns_records_help') }}</div>
            <div class="table-responsive border rounded">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th style="width: 70px">Type</th>
                    <th>{{ __('admin/setting.mail_dns_record_name') }}</th>
                    <th>{{ __('admin/setting.mail_dns_record_value') }}</th>
                    <th style="width: 150px"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(record, index) in dnsRecords" :key="`${record.type}:${record.name}:${index}`">
                    <td><span class="badge bg-secondary">@{{ record.type }}</span></td>
                    <td><code class="user-select-all text-break" v-text="record.name"></code></td>
                    <td>
                      <code v-if="record.available" class="user-select-all text-break" v-text="record.value"></code>
                      <span v-else class="text-warning" v-text="source.dnsRecordPending"></span>
                      <div class="text-secondary font-size-12">
                        @{{ record.mode === 'if_missing' ? source.dnsModeIfMissing : (record.mode === 'merged' ? source.dnsModeMerged : source.dnsModeManaged) }}
                      </div>
                    </td>
                    <td class="text-end">
                      <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!record.available" @click="copyDnsValue(record.value)">
                        <i class="bi bi-clipboard"></i> {{ __('admin/setting.mail_dns_record_copy') }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <x-admin-form-switch name="use_queue" title="{{ __('admin/setting.use_queue') }}" value="{{ old('use_queue', system_setting('base.use_queue', '0')) }}">
          <div class="help-text font-size-12 lh-base">{{__('admin/setting.use_queue_text')}}https://docs.beikeshop.com/config/queue.html</div>
        </x-admin-form-switch>
        <x-admin::form.row :title="__('admin/setting.mail_sender_profiles')">
          <div class="help-text font-size-12 lh-base mb-2">{{ __('admin/setting.mail_sender_profiles_info') }}</div>
          @if (config('mail.relay.platform_sender_domain'))
            <div class="alert alert-light border py-2 mb-0 font-size-12">
              {{ __('admin/setting.mail_sender_platform_rule', ['domain' => config('mail.relay.platform_sender_domain'), 'suffix' => current_site() && !data_get(current_site()?->meta, 'control_site', false) ? '+' . current_site()?->code : '-']) }}
            </div>
          @endif
          <div class="alert {{ $availableSenderDomains ? 'alert-success' : 'alert-warning' }} py-2 mt-2 mb-0 font-size-12">
            <div class="fw-bold mb-1">{{ __('admin/setting.mail_sender_available_domains') }}</div>
            @forelse($availableSenderDomains as $senderDomain)
              <span class="d-inline-flex align-items-center border rounded bg-light px-2 py-1 me-1 mb-1 gap-2">
                <span class="font-monospace">{{ $senderDomain }}</span>
                <span class="text-secondary">{{ $senderDomain === strtolower(trim((string) config('mail.relay.platform_sender_domain'), '.')) ? __('admin/setting.mail_sender_shared_fallback') : __('admin/setting.mail_sender_tenant_domain') }}</span>
                <button type="button" class="btn btn-link btn-sm p-0" data-domain="{{ $senderDomain }}" @click="applySenderDomain($event.currentTarget.dataset.domain)">{{ __('admin/setting.mail_sender_apply_domain') }}</button>
              </span>
            @empty
              <span>{{ __('admin/setting.mail_sender_no_available_domains') }}</span>
            @endforelse
            <div class="mt-1">{{ __('admin/setting.mail_sender_domain_switch_help') }}</div>
          </div>
        </x-admin::form.row>

        @foreach([$defaultSenderCategory => $senderCategories[$defaultSenderCategory]] as $senderCategory => $senderLabelKey)
          @php
            $senderProfile = $senderProfiles[$senderCategory];
            $senderTitle = __('admin/setting.' . $senderLabelKey);
            $senderCategorySuggestions = $senderSuggestions[$senderCategory] ?? [];
            $senderSuggestion = $senderCategorySuggestions[0] ?? '';
            $senderSuggestionListId = 'mail-sender-suggestions-' . $senderCategory;
          @endphp
          <div class="border rounded mb-3 pt-2">
            <div class="px-3 pb-1 fw-bold">{{ $senderTitle }}</div>
            <x-admin-form-input name="mail_senders[{{ $senderCategory }}][address]" type="email" title="{{ __('admin/setting.mail_sender_address') }}" value="{{ old('mail_senders.' . $senderCategory . '.address', $senderProfile['address']) }}" placeholder="{{ $senderSuggestion }}" list="{{ $senderSuggestionListId }}" autocomplete="email">
              <div class="help-text font-size-12 lh-base">
                {{ $senderCategory === \App\Mail\MailSenderProfileService::DEFAULT ? __('admin/setting.mail_sender_default_required') : __('admin/setting.mail_sender_inherit') }}
                @if($senderSuggestion) {{ __('admin/setting.mail_sender_suggestion', ['address' => $senderSuggestion]) }} @endif
              </div>
            </x-admin-form-input>
            <datalist id="{{ $senderSuggestionListId }}">
              @foreach($senderCategorySuggestions as $suggestedAddress)
                <option value="{{ $suggestedAddress }}"></option>
              @endforeach
            </datalist>
            <x-admin-form-input name="mail_senders[{{ $senderCategory }}][name]" title="{{ __('admin/setting.mail_sender_name') }}" value="{{ old('mail_senders.' . $senderCategory . '.name', $senderProfile['name']) }}">
            </x-admin-form-input>
            <x-admin-form-input name="mail_senders[{{ $senderCategory }}][reply_to]" type="email" title="{{ __('admin/setting.mail_sender_reply_to') }}" value="{{ old('mail_senders.' . $senderCategory . '.reply_to', $senderProfile['reply_to']) }}">
              <div class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_sender_reply_to_info') }}</div>
            </x-admin-form-input>
          </div>
        @endforeach

        <details class="border rounded mb-4" @if($advancedSendersCustomized) open @endif>
          <summary class="px-3 py-3 fw-bold" style="cursor: pointer">
            {{ __('admin/setting.mail_sender_advanced') }}
            <span class="d-block fw-normal text-secondary font-size-12 mt-1">{{ __('admin/setting.mail_sender_advanced_info') }}</span>
          </summary>
          <div class="px-3 pb-1">
            @foreach($advancedSenderCategories as $senderCategory => $senderLabelKey)
              @php
                $senderProfile = $senderProfiles[$senderCategory];
                $senderTitle = __('admin/setting.' . $senderLabelKey);
                $senderCategorySuggestions = $senderSuggestions[$senderCategory] ?? [];
                $senderSuggestion = $senderCategorySuggestions[0] ?? '';
                $senderSuggestionListId = 'mail-sender-suggestions-' . $senderCategory;
              @endphp
              <div class="border rounded mb-3 pt-2">
                <div class="px-3 pb-1 fw-bold">{{ $senderTitle }}</div>
                <x-admin-form-input name="mail_senders[{{ $senderCategory }}][address]" type="email" title="{{ __('admin/setting.mail_sender_address') }}" value="{{ old('mail_senders.' . $senderCategory . '.address', $senderProfile['address']) }}" placeholder="{{ $senderSuggestion }}" list="{{ $senderSuggestionListId }}" autocomplete="email">
                  <div class="help-text font-size-12 lh-base">
                    {{ __('admin/setting.mail_sender_inherit') }}
                    @if($senderSuggestion) {{ __('admin/setting.mail_sender_suggestion', ['address' => $senderSuggestion]) }} @endif
                  </div>
                </x-admin-form-input>
                <datalist id="{{ $senderSuggestionListId }}">
                  @foreach($senderCategorySuggestions as $suggestedAddress)
                    <option value="{{ $suggestedAddress }}"></option>
                  @endforeach
                </datalist>
                <x-admin-form-input name="mail_senders[{{ $senderCategory }}][name]" title="{{ __('admin/setting.mail_sender_name') }}" value="{{ old('mail_senders.' . $senderCategory . '.name', $senderProfile['name']) }}">
                </x-admin-form-input>
                <x-admin-form-input name="mail_senders[{{ $senderCategory }}][reply_to]" type="email" title="{{ __('admin/setting.mail_sender_reply_to') }}" value="{{ old('mail_senders.' . $senderCategory . '.reply_to', $senderProfile['reply_to']) }}">
                  <div class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_sender_reply_to_info') }}</div>
                </x-admin-form-input>
              </div>
            @endforeach
          </div>
        </details>
        <x-admin::form.row title="{{ __('admin/setting.mail_engine') }}">
          <select name="mail_engine" v-model="mail_engine" class="form-select wp-200 me-3">
            <option :value="item.code" v-for="item, index in source.mailEngines" :key="index">@{{ item.name }}</option>
          </select>
          <div v-if="mail_engine == 'log'" class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_log') }}</div>
          <div v-if="mail_engine == 'self_hosted'" class="help-text font-size-12 lh-base">
            {{ __('admin/setting.self_hosted_mail_info', ['hostname' => config('mail.relay.hostname') ?: '-', 'domains' => config('mail.relay.domains') ?: '-']) }}
          </div>
        </x-admin::form.row>

        <div v-if="mail_engine == 'smtp'">
          <x-admin-form-input name="smtp[host]" required title="{{ __('admin/setting.smtp_host') }}" value="{{ old('smtp.host', system_setting('base.smtp.host', '')) }}">
          </x-admin-form-input>
          <x-admin-form-input name="smtp[username]" title="{{ __('admin/setting.smtp_username') }}" value="{{ old('smtp.username', system_setting('base.smtp.username', '')) }}">
          </x-admin-form-input>
          <x-admin-form-input name="smtp[password]" type="password" title="{{ __('admin/setting.smtp_password') }}" value="" autocomplete="new-password">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_secret_keep') }}</div>
          </x-admin-form-input>
          <x-admin::form.row title="{{ __('admin/setting.smtp_encryption') }}">
            @php
              $smtpEncryption = strtolower(old('smtp.encryption', system_setting('base.smtp.encryption', 'tls')));
            @endphp
            <select name="smtp[encryption]" class="form-select wp-200">
              <option value="tls" @selected($smtpEncryption === 'tls')>TLS (recommended, port 587)</option>
              <option value="ssl" @selected($smtpEncryption === 'ssl')>SSL (port 465)</option>
              <option value="none" @selected(in_array($smtpEncryption, ['', 'none', 'null'], true))>{{ __('admin/setting.smtp_encryption_none') }}</option>
            </select>
          </x-admin::form.row>
          <x-admin-form-input name="smtp[port]" type="number" required title="{{ __('admin/setting.smtp_port') }}" value="{{ old('smtp.port', system_setting('base.smtp.port', '587')) }}">
          </x-admin-form-input>
          <x-admin-form-input name="smtp[timeout]" type="number" required title="{{ __('admin/setting.smtp_timeout') }}" value="{{ old('smtp.timeout', system_setting('base.smtp.timeout', '10')) }}">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.smtp_timeout_info') }}</div>
          </x-admin-form-input>
        </div>

        <div v-if="mail_engine == 'sendmail'">
          <x-admin-form-input name="sendmail[path]" :placeholder="222" required title="{{ __('admin/setting.sendmail_path') }}" value="{{ old('sendmail.path', system_setting('base.sendmail.path', '')) }}">
            <div class="help-text font-size-12 lh-base">系统 sendmail 执行路径, 一般为 /usr/sbin/sendmail -bs</div>
          </x-admin-form-input>
        </div>

        <div v-if="mail_engine == 'mailgun'">
          <x-admin-form-input name="mailgun[domain]" required title="{{ __('admin/setting.mailgun_domain') }}" value="{{ old('mailgun.domain', system_setting('base.mailgun.domain', '')) }}">
          </x-admin-form-input>
          <x-admin-form-input name="mailgun[secret]" type="password" title="{{ __('admin/setting.mailgun_secret') }}" value="" autocomplete="new-password">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_secret_keep') }}</div>
          </x-admin-form-input>
          <x-admin-form-input name="mailgun[endpoint]" required title="{{ __('admin/setting.mailgun_endpoint') }}" value="{{ old('mailgun.endpoint', system_setting('base.mailgun.endpoint', 'api.mailgun.net')) }}">
          </x-admin-form-input>
        </div>

        <div v-if="mail_engine == 'sendcloud'">
          <x-admin-form-input name="sendcloud[api_user]" required title="{{ __('admin/setting.sendcloud_api_user') }}" value="{{ old('sendcloud.api_user', system_setting('base.sendcloud.api_user', '')) }}">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.sendcloud_api_user_info') }}</div>
          </x-admin-form-input>
          <x-admin-form-input name="sendcloud[api_key]" type="password" title="{{ __('admin/setting.sendcloud_api_key') }}" value="" autocomplete="new-password">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_secret_keep') }}</div>
          </x-admin-form-input>
          <x-admin-form-input name="sendcloud[endpoint]" title="{{ __('admin/setting.sendcloud_endpoint') }}" value="{{ old('sendcloud.endpoint', system_setting('base.sendcloud.endpoint', 'https://api.sendcloud.net')) }}">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.sendcloud_endpoint_info') }}</div>
          </x-admin-form-input>
        </div>

        <div v-if="mail_engine == 'sendgrid'">
          <x-admin-form-input name="sendgrid[api_key]" type="password" title="{{ __('admin/setting.sendgrid_api_key') }}" value="" autocomplete="new-password">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_secret_keep') }}</div>
          </x-admin-form-input>
          <x-admin-form-input name="sendgrid[endpoint]" title="{{ __('admin/setting.sendgrid_endpoint') }}" value="{{ old('sendgrid.endpoint', system_setting('base.sendgrid.endpoint', 'https://api.sendgrid.com')) }}">
            <div class="help-text font-size-12 lh-base">{{ __('admin/setting.sendgrid_endpoint_info') }}</div>
          </x-admin-form-input>
        </div>

        <div v-if="mail_engine != ''">
          <x-admin::form.row :title="__('admin/setting.email_send_admin')">
            <div class="input-group wp-400">
              <input name="mail_alert[]" type="hidden" value="">
              <div class="form-check mt-2 me-3">
                <input class="form-check-input" type="checkbox" id="check-input-register" name="mail_alert[]" value="register" {{ in_array('register', old('mail_alert', system_setting('base.mail_alert', []))) ? 'checked' : '' }}>
                <label class="form-check-label" for="check-input-register">{{ __('admin/setting.email_type_register') }}</label>
              </div>
              <div class="form-check mt-2 me-3">
                <input class="form-check-input" type="checkbox" id="check-input-order" name="mail_alert[]" value="order" {{ in_array('order', old('mail_alert', system_setting('base.mail_alert', []))) ? 'checked' : '' }}>
                <label class="form-check-label" for="check-input-order">{{ __('admin/setting.email_type_order') }}</label>
              </div>
              <div class="form-check mt-2 me-3">
                <input class="form-check-input" type="checkbox" id="check-input-return" name="mail_alert[]" value="return" {{ in_array('return', old('mail_alert', system_setting('base.mail_alert', []))) ? 'checked' : '' }}>
                <label class="form-check-label" for="check-input-return">{{ __('admin/setting.email_type_return') }}</label>
              </div>
            </div>
          </x-admin::form.row>
          <x-admin::form.row :title="__('admin/setting.email_send_customer')">
            <input name="mail_customer[]" type="hidden" value="">
            <div class="input-group wp-400">
              <div class="form-check mt-2 me-3">
                <input class="form-check-input" type="checkbox" id="check-input-customer-register" name="mail_customer[]" value="register" {{ in_array('register', old('mail_customer', system_setting('base.mail_customer', []))) ? 'checked' : '' }}>
                <label class="form-check-label" for="check-input-customer-register">{{ __('admin/setting.email_type_register') }}</label>
              </div>
              <div class="form-check mt-2 me-3">
                <input class="form-check-input" type="checkbox" id="check-input-customer-order" name="mail_customer[]" value="order" {{ in_array('order', old('mail_customer', system_setting('base.mail_customer', []))) ? 'checked' : '' }}>
                <label class="form-check-label" for="check-input-customer-order">{{ __('admin/setting.email_type_order') }}</label>
              </div>
              <div class="form-check mt-2 me-3">
                <input class="form-check-input" type="checkbox" id="check-input-customer-return" name="mail_customer[]" value="return" {{ in_array('return', old('mail_customer', system_setting('base.mail_customer', []))) ? 'checked' : '' }}>
                <label class="form-check-label" for="check-input-customer-return">{{ __('admin/setting.email_type_return') }}</label>
              </div>
            </div>
            <div class="help-text lh-base smtp-qq-hint d-none">{{ __('admin/setting.smtp_qq_hint') }}</div>
          </x-admin::form.row>
        </div>

        @hook('admin.setting.mail.after')

        <x-admin::form.row :title="__('admin/setting.mail_test_title')">
          <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center">
            <input v-model.trim="testRecipient" type="email" class="form-control wp-400" :placeholder="source.testRecipientLabel" @keyup.enter.prevent="sendTestMail">
            <button type="button" class="btn btn-outline-primary" :disabled="testing || !testRecipient" @click="sendTestMail">
              <i class="bi bi-send"></i>@{{ testing ? source.testSendingLabel : source.testButtonLabel }}
            </button>
          </div>
          <div class="help-text font-size-12 lh-base mt-2">{{ __('admin/setting.mail_test_info') }}</div>
        </x-admin::form.row>

        <x-admin-form-input name="mail_log_retention_days" type="number" min="1" max="365" required title="{{ __('admin/setting.mail_log_retention_days') }}" value="{{ old('mail_log_retention_days', system_setting('base.mail_log_retention_days', 30)) }}">
          <div class="help-text font-size-12 lh-base">{{ __('admin/setting.mail_log_retention_days_help') }}</div>
        </x-admin-form-input>

        <x-admin::form.row title="">
          <button type="submit" class="btn btn-primary d-none mt-4">{{ __('common.submit') }}</button>
        </x-admin::form.row>
      </form>
      @hook('admin.setting.mail.content.after')
    </div>
  </div>
@endsection

@push('footer')
  <script>
    const mailPageData = {!! json_encode($mailPageData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};

    let app = new Vue({
      el: '#form-app',
      data: {
        mail_engine: mailPageData.mailEngine,
        testRecipient: mailPageData.testRecipient,
        testing: false,
        diagnosing: false,
        syncingDns: false,
        dnsSyncAvailable: mailPageData.dnsSyncAvailable === true,
        diagnosticChecks: [],
        dnsRecords: [],
        diagnosticError: '',
        dnsSyncMessage: '',
        dnsSyncWarnings: [],

        source: {
          mailEngines: [
            {name: '{{ __('admin/builder.text_no') }}', code: ''},
            {name: '{{ __('admin/setting.mail_engine_self_hosted') }}', code: 'self_hosted'},
            {name: 'SMTP', code: 'smtp'},
            {name: 'Sendmail', code: 'sendmail'},
            {name: 'Mailgun', code: 'mailgun'},
            {name: 'SendCloud', code: 'sendcloud'},
            {name: 'SendGrid', code: 'sendgrid'},
            {name: 'Log', code: 'log'},
          ],
          testRecipientLabel: mailPageData.labels.testRecipient,
          testButtonLabel: mailPageData.labels.testButton,
          testSendingLabel: mailPageData.labels.testSending,
          diagnosticsButton: mailPageData.labels.diagnosticsButton,
          diagnosticsRunning: mailPageData.labels.diagnosticsRunning,
          dnsSyncButton: mailPageData.labels.dnsSyncButton,
          dnsSyncRunning: mailPageData.labels.dnsSyncRunning,
          dnsSyncConfirm: mailPageData.labels.dnsSyncConfirm,
          dnsSyncPropagation: mailPageData.labels.dnsSyncPropagation,
          dnsSyncExternalHelp: mailPageData.labels.dnsSyncExternalHelp,
          dnsRecordPending: mailPageData.labels.dnsRecordPending,
          dnsRecordCopied: mailPageData.labels.dnsRecordCopied,
          dnsModeManaged: '{{ __('admin/setting.mail_dns_record_mode_managed') }}',
          dnsModeMerged: '{{ __('admin/setting.mail_dns_record_mode_merged') }}',
          dnsModeIfMissing: '{{ __('admin/setting.mail_dns_record_mode_if_missing') }}',
        },
      },
      methods: {
        applySenderDomain(domain) {
          const presets = mailPageData.senderDomainPresets?.[domain] || {};
          Object.entries(presets).forEach(([category, address]) => {
            const input = this.$el.querySelector(`[name="mail_senders[${category}][address]"]`);
            if (!input || !address) return;
            input.value = address;
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
          });
          layer.msg(mailPageData.labels.senderDomainApplied);
        },
        runDiagnostics() {
          if (this.diagnosing) return;
          this.diagnosing = true;
          this.diagnosticError = '';
          $.ajax({
            url: mailPageData.diagnosticsUrl,
            method: 'POST',
            headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json'},
          }).done((response) => {
            const payload = response?.data?.data || response?.data || {};
            this.diagnosticChecks = Array.isArray(payload.checks) ? payload.checks : [];
            this.dnsRecords = Array.isArray(payload.dns_records) ? payload.dns_records : [];
          }).fail((xhr) => {
            this.diagnosticError = xhr.responseJSON?.message || mailPageData.labels.diagnosticsFailed;
          }).always(() => { this.diagnosing = false; });
        },
        syncMailDns() {
          if (!this.dnsSyncAvailable || this.syncingDns || !window.confirm(this.source.dnsSyncConfirm)) return;
          this.syncingDns = true;
          this.diagnosticError = '';
          this.dnsSyncMessage = '';
          this.dnsSyncWarnings = [];
          $.ajax({
            url: mailPageData.dnsSyncUrl,
            method: 'POST',
            headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json'},
          }).done((response) => {
            const payload = response?.data?.data || response?.data || {};
            const sync = payload.sync || {};
            this.diagnosticChecks = [];
            this.dnsSyncWarnings = Array.isArray(sync.warnings) ? sync.warnings : [];
            this.dnsSyncMessage = `${response?.message || mailPageData.labels.dnsSyncButton} ${this.source.dnsSyncPropagation}`;
            layer.msg(response?.message || mailPageData.labels.dnsSyncButton);
          }).fail((xhr) => {
            this.diagnosticError = xhr.responseJSON?.message || mailPageData.labels.dnsSyncFailed;
          }).always(() => { this.syncingDns = false; });
        },
        async copyDnsValue(value) {
          if (!value) return;
          try {
            await navigator.clipboard.writeText(value);
          } catch (error) {
            const input = document.createElement('textarea');
            input.value = value;
            input.setAttribute('readonly', 'readonly');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            input.remove();
          }
          layer.msg(this.source.dnsRecordCopied);
        },
        sendTestMail() {
          if (!this.testRecipient || this.testing) return;
          this.testing = true;
          $.ajax({
            url: mailPageData.testUrl,
            method: 'POST',
            data: {recipient: this.testRecipient},
            headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json'},
          }).done((response) => {
            layer.msg(response.message || mailPageData.labels.testSent);
          }).fail((xhr) => {
            layer.msg(xhr.responseJSON?.message || mailPageData.labels.testFailed);
          }).always(() => { this.testing = false; });
        },
      },
    });

    @if($canManageMailRelay && $mailRelayRuntime)
      (() => {
        const form = document.getElementById('mail-relay-runtime-form');
        if (!form) return;

        const modeInputs = Array.from(form.querySelectorAll('input[name="mail_relay[mode]"]'));
        const endpointWrap = document.getElementById('mail-relay-external-fields');
        const internalNotice = document.getElementById('mail-relay-internal-notice');
        const endpoint = document.getElementById('mail-relay-endpoint');
        const publicIps = document.getElementById('mail-relay-public-ips');
        const hostname = document.getElementById('mail-relay-hostname');
        const domains = document.getElementById('mail-relay-domains');
        const allowedDomains = document.getElementById('mail-relay-allowed-domains');
        const selector = document.getElementById('mail-relay-dkim-selector');
        const platformDomain = document.getElementById('mail-relay-platform-domain');
        const autoDetectWrap = document.getElementById('mail-relay-auto-detect-row');
        const autoDetect = document.getElementById('mail-relay-auto-detect');
        const ptrPreview = document.getElementById('mail-relay-ptr-preview');
        const nodeConfigWrap = document.getElementById('mail-relay-node-config');
        const nodeConfig = document.getElementById('mail-relay-node-config-preview');
        const copyButton = document.getElementById('mail-relay-copy-node-config');
        const downloadButton = document.getElementById('mail-relay-download-node-config');
        const nodeProfileVersion = {{ \App\Mail\MailRelayNodeProfile::VERSION }};
        const nodeProfileInputs = {
          INET_PROTOCOLS: document.getElementById('mail-relay-node-inet-protocols'),
          RELAY_HTTP_PORT: document.getElementById('mail-relay-node-http-port'),
          MAIL_NODE_VOLUME_PREFIX: document.getElementById('mail-relay-node-volume-prefix'),
          TUNNEL_PROTOCOL: document.getElementById('mail-relay-node-tunnel-protocol'),
          TUNNEL_LOG_LEVEL: document.getElementById('mail-relay-node-tunnel-log-level'),
          TUNNEL_METRICS_ADDRESS: document.getElementById('mail-relay-node-metrics-address'),
          CLOUDFLARED_IMAGE: document.getElementById('mail-relay-node-cloudflared-image'),
          RELAY_MEMORY_LIMIT: document.getElementById('mail-relay-node-relay-memory-limit'),
          RELAY_MEMORY_RESERVATION: document.getElementById('mail-relay-node-relay-memory-reservation'),
          RELAY_PIDS_LIMIT: document.getElementById('mail-relay-node-relay-pids-limit'),
          TUNNEL_MEMORY_LIMIT: document.getElementById('mail-relay-node-tunnel-memory-limit'),
          TUNNEL_MEMORY_RESERVATION: document.getElementById('mail-relay-node-tunnel-memory-reservation'),
          TUNNEL_PIDS_LIMIT: document.getElementById('mail-relay-node-tunnel-pids-limit'),
        };
        const deploymentDefaults = {!! json_encode(data_get($mailRelayRuntime, 'deployment_values', []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};

        const list = (value) => value.split(/[\s,]+/).map((item) => item.trim()).filter(Boolean);
        const selectedMode = () => modeInputs.find((input) => input.checked)?.value || 'internal';
        const identityInputs = [hostname, domains, allowedDomains, selector, platformDomain];
        const exportInputs = [endpoint, publicIps, ...identityInputs, ...Object.values(nodeProfileInputs)];
        let previousMode = selectedMode();
        let externalDraft = null;

        const canExport = () => {
          const invalid = exportInputs.find((input) => !input.checkValidity());
          if (!invalid) return true;
          form.classList.add('was-validated');
          invalid.reportValidity();
          invalid.focus();
          return false;
        };

        const update = () => {
          const external = selectedMode() === 'external';
          endpointWrap.classList.toggle('d-none', !external);
          internalNotice.classList.toggle('d-none', external);
          autoDetectWrap.classList.toggle('d-none', external);
          nodeConfigWrap.classList.toggle('d-none', !external);
          endpoint.required = external;
          publicIps.required = external;
          identityInputs.forEach((input) => { input.readOnly = !external; });

          form.querySelectorAll('.mail-relay-mode-card').forEach((card) => {
            const active = card.querySelector('input')?.checked === true;
            card.classList.toggle('border-primary', active);
            card.classList.toggle('bg-light', active);
          });

          const ips = list(publicIps.value);
          const mailHost = hostname.value.trim().toLowerCase();
          ptrPreview.textContent = ips.length && mailHost
            ? ips.map((ip) => `${ip} → ${mailHost}`).join('\n')
            : '{{ __('admin/setting.mail_relay_public_ips_help') }}';

          nodeConfig.textContent = [
            `MAIL_NODE_PROFILE_VERSION=${nodeProfileVersion}`,
            `MAIL_HOSTNAME=${mailHost}`,
            `MAIL_PUBLIC_IPS=${ips.join(',')}`,
            `MAIL_DOMAINS=${list(domains.value).join(',')}`,
            `ALLOWED_SENDER_DOMAINS=${list(allowedDomains.value).join(',')}`,
            `DKIM_SELECTOR=${selector.value.trim().toLowerCase()}`,
            ...Object.entries(nodeProfileInputs).map(([name, input]) => `${name}=${input.value.trim()}`),
          ].join('\n');
        };

        modeInputs.forEach((input) => input.addEventListener('change', () => {
          const nextMode = selectedMode();
          if (previousMode === 'external' && nextMode === 'internal') {
            externalDraft = {
              endpoint: endpoint.value,
              publicIps: publicIps.value,
              autoDetect: autoDetect.checked,
              hostname: hostname.value,
              domains: domains.value,
              allowedDomains: allowedDomains.value,
              platformDomain: platformDomain.value,
              selector: selector.value,
            };
            publicIps.value = (deploymentDefaults.public_ips || []).join(', ');
            autoDetect.checked = deploymentDefaults.auto_detect_public_ips !== false;
            hostname.value = deploymentDefaults.hostname || '';
            domains.value = (deploymentDefaults.domains || []).join('\n');
            allowedDomains.value = (deploymentDefaults.allowed_sender_domains || []).join('\n');
            platformDomain.value = deploymentDefaults.platform_sender_domain || '';
            selector.value = deploymentDefaults.dkim_selector || 'mail2026';
          } else if (previousMode === 'internal' && nextMode === 'external' && externalDraft) {
            endpoint.value = externalDraft.endpoint;
            publicIps.value = externalDraft.publicIps;
            autoDetect.checked = externalDraft.autoDetect;
            hostname.value = externalDraft.hostname;
            domains.value = externalDraft.domains;
            allowedDomains.value = externalDraft.allowedDomains;
            platformDomain.value = externalDraft.platformDomain;
            selector.value = externalDraft.selector;
          }
          previousMode = nextMode;
          update();
        }));
        [endpoint, publicIps, hostname, domains, allowedDomains, selector, platformDomain, ...Object.values(nodeProfileInputs)]
          .forEach((input) => input.addEventListener('input', update));
        Object.values(nodeProfileInputs).forEach((input) => input.addEventListener('change', update));

        form.addEventListener('submit', (event) => {
          if (event.submitter?.value === 'reset') return;
          if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
          }
          form.classList.add('was-validated');
        });

        copyButton.addEventListener('click', async () => {
          if (!canExport()) return;
          const value = nodeConfig.textContent || '';
          try {
            await navigator.clipboard.writeText(value);
          } catch (error) {
            const field = document.createElement('textarea');
            field.value = value;
            field.setAttribute('readonly', 'readonly');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            document.execCommand('copy');
            field.remove();
          }
          layer.msg('{{ __('admin/setting.mail_relay_node_config_copied') }}');
        });

        downloadButton.addEventListener('click', () => {
          if (!canExport()) return;
          const blob = new Blob([`${nodeConfig.textContent || ''}\n`], {type: 'text/plain;charset=utf-8'});
          const link = document.createElement('a');
          const url = URL.createObjectURL(blob);
          link.href = url;
          link.download = `${hostname.value.trim().toLowerCase() || 'longos'}-mail-node.env`;
          document.body.appendChild(link);
          link.click();
          link.remove();
          URL.revokeObjectURL(url);
          layer.msg('{{ __('admin/setting.mail_relay_node_config_downloaded') }}');
        });

        update();
      })();
    @endif
  </script>
@endpush
