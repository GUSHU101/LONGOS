@php
  use Plugin\Paypal\Services\PaypalSettings;

  $setting = $plugin->getSetting() ?: [];
  $apiMode = old('api_mode', $setting['api_mode'] ?? 'rest') === 'nvp' ? 'nvp' : 'rest';
  $sandboxMode = (string) old('sandbox_mode', $setting['sandbox_mode'] ?? 1) === '1';
  $status = (string) old('status', $setting['status'] ?? 0) === '1';
  $activeEnvironment = $sandboxMode ? 'sandbox' : 'live';
  $hasSecret = static fn (string $name): bool => PaypalSettings::hasStoredSecret($setting, $name);
@endphp

<form class="needs-validation" novalidate action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST" id="form-app">
  @csrf
  {{ method_field('put') }}

  @if ($errors->any())
    <div class="alert alert-danger paypal-error-summary" role="alert" tabindex="-1" id="paypal-form-errors">
      <strong>{{ __('common.error_form') }}</strong>
      <ul class="mb-0 mt-2 ps-3">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <div
    class="paypal-config"
    data-ready-label="{{ __('Paypal::setting.configuration_ready') }}"
    data-incomplete-label="{{ __('Paypal::setting.configuration_incomplete') }}"
    data-disabled-label="{{ __('Paypal::setting.configuration_disabled') }}"
    data-sandbox-label="{{ __('Paypal::setting.sandbox_credentials') }}"
    data-live-label="{{ __('Paypal::setting.live_credentials') }}"
    data-rest-label="{{ __('Paypal::setting.api_mode_rest') }}"
    data-nvp-label="{{ __('Paypal::setting.api_mode_nvp') }}">
    <header class="paypal-overview" aria-labelledby="paypal-settings-title">
      <div>
        <span class="paypal-eyebrow">{{ __('Paypal::setting.checkout_solution') }}</span>
        <h5 id="paypal-settings-title">{{ __('Paypal::setting.settings_title') }}</h5>
        <p>{{ __('Paypal::setting.settings_intro') }}</p>
      </div>
      <div class="paypal-overview-status">
        <span class="paypal-status-badge" id="paypal-configuration-status" role="status" aria-live="polite"></span>
        <dl>
          <div>
            <dt>{{ __('Paypal::setting.environment_title') }}</dt>
            <dd id="paypal-environment-summary"></dd>
          </div>
          <div>
            <dt>{{ __('Paypal::setting.api_mode') }}</dt>
            <dd id="paypal-api-summary"></dd>
          </div>
        </dl>
      </div>
    </header>

    <section class="paypal-section" aria-labelledby="paypal-operation-title">
      <div class="paypal-section-heading">
        <div>
          <h6 id="paypal-operation-title">{{ __('Paypal::setting.operation_title') }}</h6>
          <p>{{ __('Paypal::setting.operation_desc') }}</p>
        </div>
      </div>

      <div class="paypal-control-grid">
        <fieldset class="paypal-fieldset">
          <legend>{{ __('admin/common.status') }}</legend>
          <div class="paypal-choice-grid paypal-choice-grid-compact">
            <label class="paypal-choice" for="paypal-status-enabled">
              <input type="radio" name="status" id="paypal-status-enabled" value="1" @checked($status)>
              <span>
                <strong>{{ __('Paypal::setting.enabled') }}</strong>
                <small>{{ __('Paypal::setting.status_enabled_desc') }}</small>
              </span>
            </label>
            <label class="paypal-choice" for="paypal-status-disabled">
              <input type="radio" name="status" id="paypal-status-disabled" value="0" @checked(! $status)>
              <span>
                <strong>{{ __('Paypal::setting.disabled') }}</strong>
                <small>{{ __('Paypal::setting.status_disabled_desc') }}</small>
              </span>
            </label>
          </div>
        </fieldset>

        <fieldset class="paypal-fieldset">
          <legend>{{ __('Paypal::setting.environment_title') }}</legend>
          <div class="paypal-choice-grid paypal-choice-grid-compact">
            <label class="paypal-choice" for="paypal-environment-sandbox">
              <input type="radio" name="sandbox_mode" id="paypal-environment-sandbox" value="1" @checked($sandboxMode)>
              <span>
                <strong>{{ __('Paypal::setting.sandbox_credentials') }}</strong>
                <small>{{ __('Paypal::setting.sandbox_desc') }}</small>
              </span>
            </label>
            <label class="paypal-choice" for="paypal-environment-live">
              <input type="radio" name="sandbox_mode" id="paypal-environment-live" value="0" @checked(! $sandboxMode)>
              <span>
                <strong>{{ __('Paypal::setting.live_credentials') }}</strong>
                <small>{{ __('Paypal::setting.live_desc') }}</small>
              </span>
            </label>
          </div>
        </fieldset>
      </div>
    </section>

    <section class="paypal-section" aria-labelledby="paypal-credentials-title">
      <div class="paypal-section-heading">
        <div>
          <h6 id="paypal-credentials-title">{{ __('Paypal::setting.credentials_title') }}</h6>
          <p>{{ __('Paypal::setting.credentials_desc') }}</p>
        </div>
        <a
          href="https://developer.paypal.com/dashboard/applications/sandbox"
          target="_blank"
          rel="noopener noreferrer"
          class="btn btn-sm btn-outline-secondary"
          data-paypal-dashboard="sandbox">
          {{ __('Paypal::setting.open_paypal_dashboard') }}
        </a>
        <a
          href="https://developer.paypal.com/dashboard/applications/live"
          target="_blank"
          rel="noopener noreferrer"
          class="btn btn-sm btn-outline-secondary"
          data-paypal-dashboard="live"
          hidden>
          {{ __('Paypal::setting.open_paypal_dashboard') }}
        </a>
      </div>

      <div class="paypal-security-note" role="note">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <div>
          <strong>{{ __('Paypal::setting.account_notice_title') }}</strong>
          <span>{{ __('Paypal::setting.account_notice') }}</span>
        </div>
      </div>

      @foreach (['sandbox', 'live'] as $environment)
        <div data-paypal-environment-panel="{{ $environment }}" @if ($activeEnvironment !== $environment) hidden @endif>
          <div data-paypal-mode-panel="rest" @if ($apiMode !== 'rest') hidden @endif>
            <div class="row g-3">
              <div class="col-12 col-xl-6">
                <x-admin-form-input
                  name="{{ $environment }}_client_id"
                  :title="__('Paypal::setting.' . $environment . '_client_id')"
                  :error="$errors->first($environment . '_client_id')"
                  value="{{ old($environment . '_client_id', $setting[$environment . '_client_id'] ?? '') }}"
                  data-paypal-credential="rest"
                  data-paypal-environment="{{ $environment }}"
                  aria-label="{{ __('Paypal::setting.' . $environment . '_client_id') }}"
                  autocomplete="off" />
              </div>
              <div class="col-12 col-xl-6">
                <x-admin-form-input
                  name="{{ $environment }}_secret"
                  :title="__('Paypal::setting.' . $environment . '_secret')"
                  type="password"
                  :error="$errors->first($environment . '_secret')"
                  value=""
                  :placeholder="$hasSecret($environment . '_secret') ? __('Paypal::setting.secret_keep_placeholder') : __('Paypal::setting.secret_enter_placeholder')"
                  data-paypal-credential="rest"
                  data-paypal-environment="{{ $environment }}"
                  data-paypal-secret="1"
                  data-paypal-saved="{{ $hasSecret($environment . '_secret') ? '1' : '0' }}"
                  aria-label="{{ __('Paypal::setting.' . $environment . '_secret') }}"
                  autocomplete="new-password" />
                <div class="paypal-secret-state {{ $hasSecret($environment . '_secret') ? 'is-saved' : 'is-missing' }}">
                  <i class="bi {{ $hasSecret($environment . '_secret') ? 'bi-check-circle-fill' : 'bi-exclamation-circle' }}" aria-hidden="true"></i>
                  {{ $hasSecret($environment . '_secret') ? __('Paypal::setting.secret_saved') : __('Paypal::setting.secret_missing') }}
                </div>
              </div>
            </div>
          </div>

          <div data-paypal-mode-panel="nvp" @if ($apiMode !== 'nvp') hidden @endif>
            <div class="paypal-legacy-warning" role="note">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
              <span>{{ __('Paypal::setting.nvp_warning') }}</span>
            </div>
            <div class="row g-3">
              <div class="col-12">
                <x-admin-form-input
                  name="{{ $environment }}_api_username"
                  :title="__('Paypal::setting.' . $environment . '_api_username')"
                  :error="$errors->first($environment . '_api_username')"
                  value="{{ old($environment . '_api_username', $setting[$environment . '_api_username'] ?? '') }}"
                  data-paypal-credential="nvp"
                  data-paypal-environment="{{ $environment }}"
                  aria-label="{{ __('Paypal::setting.' . $environment . '_api_username') }}"
                  autocomplete="off" />
              </div>
              <div class="col-12 col-xl-6">
                <x-admin-form-input
                  name="{{ $environment }}_api_password"
                  :title="__('Paypal::setting.' . $environment . '_api_password')"
                  type="password"
                  :error="$errors->first($environment . '_api_password')"
                  value=""
                  :placeholder="$hasSecret($environment . '_api_password') ? __('Paypal::setting.secret_keep_placeholder') : __('Paypal::setting.secret_enter_placeholder')"
                  data-paypal-credential="nvp"
                  data-paypal-environment="{{ $environment }}"
                  data-paypal-secret="1"
                  data-paypal-saved="{{ $hasSecret($environment . '_api_password') ? '1' : '0' }}"
                  aria-label="{{ __('Paypal::setting.' . $environment . '_api_password') }}"
                  autocomplete="new-password" />
                <div class="paypal-secret-state {{ $hasSecret($environment . '_api_password') ? 'is-saved' : 'is-missing' }}">
                  <i class="bi {{ $hasSecret($environment . '_api_password') ? 'bi-check-circle-fill' : 'bi-exclamation-circle' }}" aria-hidden="true"></i>
                  {{ $hasSecret($environment . '_api_password') ? __('Paypal::setting.secret_saved') : __('Paypal::setting.secret_missing') }}
                </div>
              </div>
              <div class="col-12 col-xl-6">
                <x-admin-form-input
                  name="{{ $environment }}_api_signature"
                  :title="__('Paypal::setting.' . $environment . '_api_signature')"
                  type="password"
                  :error="$errors->first($environment . '_api_signature')"
                  value=""
                  :placeholder="$hasSecret($environment . '_api_signature') ? __('Paypal::setting.secret_keep_placeholder') : __('Paypal::setting.secret_enter_placeholder')"
                  data-paypal-credential="nvp"
                  data-paypal-environment="{{ $environment }}"
                  data-paypal-secret="1"
                  data-paypal-saved="{{ $hasSecret($environment . '_api_signature') ? '1' : '0' }}"
                  aria-label="{{ __('Paypal::setting.' . $environment . '_api_signature') }}"
                  autocomplete="new-password" />
                <div class="paypal-secret-state {{ $hasSecret($environment . '_api_signature') ? 'is-saved' : 'is-missing' }}">
                  <i class="bi {{ $hasSecret($environment . '_api_signature') ? 'bi-check-circle-fill' : 'bi-exclamation-circle' }}" aria-hidden="true"></i>
                  {{ $hasSecret($environment . '_api_signature') ? __('Paypal::setting.secret_saved') : __('Paypal::setting.secret_missing') }}
                </div>
              </div>
            </div>
          </div>
        </div>
      @endforeach
    </section>

    <details class="paypal-advanced" @if ($apiMode === 'nvp' || $errors->hasAny(['sandbox_merchant_id', 'live_merchant_id'])) open @endif>
      <summary>
        <span>
          <strong>{{ __('Paypal::setting.advanced_title') }}</strong>
          <small>{{ __('Paypal::setting.advanced_desc') }}</small>
        </span>
        <i class="bi bi-chevron-down" aria-hidden="true"></i>
      </summary>
      <div class="paypal-advanced-content">
        <fieldset class="paypal-fieldset">
          <legend>{{ __('Paypal::setting.api_mode') }}</legend>
          <p class="paypal-fieldset-description">{{ __('Paypal::setting.api_mode_desc') }}</p>
          <div class="paypal-choice-grid">
            <label class="paypal-choice" for="paypal-api-rest">
              <input type="radio" name="api_mode" id="paypal-api-rest" value="rest" @checked($apiMode === 'rest')>
              <span>
                <strong>{{ __('Paypal::setting.rest_title') }}</strong>
                <small>{{ __('Paypal::setting.rest_desc') }}</small>
              </span>
            </label>
            <label class="paypal-choice" for="paypal-api-nvp">
              <input type="radio" name="api_mode" id="paypal-api-nvp" value="nvp" @checked($apiMode === 'nvp')>
              <span>
                <strong>{{ __('Paypal::setting.nvp_title') }}</strong>
                <small>{{ __('Paypal::setting.nvp_desc') }}</small>
              </span>
            </label>
          </div>
        </fieldset>

        <div class="paypal-optional-field" data-paypal-merchant-panel="sandbox" @if (! $sandboxMode) hidden @endif>
          <x-admin-form-input
            name="sandbox_merchant_id"
            :title="__('Paypal::setting.sandbox_merchant_id')"
            :description="__('Paypal::setting.merchant_id_desc')"
            :error="$errors->first('sandbox_merchant_id')"
            maxlength="13"
            pattern="[2-9A-HJ-NP-Z]{13}"
            value="{{ old('sandbox_merchant_id', $setting['sandbox_merchant_id'] ?? '') }}"
            aria-label="{{ __('Paypal::setting.sandbox_merchant_id') }}"
            autocomplete="off" />
        </div>
        <div class="paypal-optional-field" data-paypal-merchant-panel="live" @if ($sandboxMode) hidden @endif>
          <x-admin-form-input
            name="live_merchant_id"
            :title="__('Paypal::setting.live_merchant_id')"
            :description="__('Paypal::setting.merchant_id_desc')"
            :error="$errors->first('live_merchant_id')"
            maxlength="13"
            pattern="[2-9A-HJ-NP-Z]{13}"
            value="{{ old('live_merchant_id', $setting['live_merchant_id'] ?? '') }}"
            aria-label="{{ __('Paypal::setting.live_merchant_id') }}"
            autocomplete="off" />
        </div>
      </div>
    </details>
  </div>

  <x-admin::form.row title="">
    <button type="submit" class="btn btn-primary d-none btn-lg mt-4">{{ __('common.submit') }}</button>
  </x-admin::form.row>
</form>

<style>
  .paypal-config {
    --paypal-space-1: 8px;
    --paypal-space-2: 16px;
    --paypal-space-3: 24px;
    --paypal-border: #dfe5ec;
    --paypal-muted: #667085;
    --paypal-ink: #17213a;
    --paypal-blue: #0070ba;
    --paypal-blue-soft: #edf7ff;
    display: grid;
    gap: var(--paypal-space-2);
    max-width: 1040px;
  }

  .paypal-overview,
  .paypal-section,
  .paypal-advanced {
    border: 1px solid var(--paypal-border);
    border-radius: 16px;
    background: #fff;
  }

  .paypal-overview {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--paypal-space-3);
    padding: var(--paypal-space-3);
    background: linear-gradient(135deg, #fff 0%, #f5faff 100%);
  }

  .paypal-overview h5,
  .paypal-section h6 {
    color: var(--paypal-ink);
    font-weight: 700;
    margin: 0 0 var(--paypal-space-1);
  }

  .paypal-overview p,
  .paypal-section-heading p,
  .paypal-fieldset-description {
    color: var(--paypal-muted);
    line-height: 1.5;
    margin: 0;
  }

  .paypal-eyebrow {
    display: block;
    color: var(--paypal-blue);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .08em;
    margin-bottom: var(--paypal-space-1);
    text-transform: uppercase;
  }

  .paypal-overview-status {
    flex: 0 0 260px;
  }

  .paypal-status-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    padding: 6px 10px;
  }

  .paypal-status-badge.is-ready { color: #08783e; background: #e8f7ef; }
  .paypal-status-badge.is-incomplete { color: #9a5b00; background: #fff4dc; }
  .paypal-status-badge.is-disabled { color: #5b6473; background: #eef1f5; }

  .paypal-overview-status dl {
    display: grid;
    gap: var(--paypal-space-1);
    margin: var(--paypal-space-2) 0 0;
  }

  .paypal-overview-status dl div {
    display: flex;
    justify-content: space-between;
    gap: var(--paypal-space-2);
  }

  .paypal-overview-status dt { color: var(--paypal-muted); font-weight: 400; }
  .paypal-overview-status dd { color: var(--paypal-ink); font-weight: 600; margin: 0; }
  .paypal-section { padding: var(--paypal-space-3); }

  .paypal-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--paypal-space-2);
    border-bottom: 1px solid var(--paypal-border);
    margin-bottom: var(--paypal-space-3);
    padding-bottom: var(--paypal-space-2);
  }

  .paypal-control-grid,
  .paypal-choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--paypal-space-2);
  }

  .paypal-fieldset { min-width: 0; margin: 0; }

  .paypal-fieldset legend {
    color: var(--paypal-ink);
    float: none;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: var(--paypal-space-1);
  }

  .paypal-fieldset-description {
    font-size: 13px;
    margin: calc(var(--paypal-space-1) * -0.5) 0 var(--paypal-space-2);
  }

  .paypal-choice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    min-height: 88px;
    border: 1px solid var(--paypal-border);
    border-radius: 12px;
    cursor: pointer;
    margin: 0;
    padding: var(--paypal-space-2);
    transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
  }

  .paypal-choice:has(input:checked) {
    border-color: var(--paypal-blue);
    background: var(--paypal-blue-soft);
    box-shadow: 0 0 0 1px var(--paypal-blue);
  }

  .paypal-choice:focus-within {
    outline: 3px solid rgba(0, 112, 186, .2);
    outline-offset: 2px;
  }

  .paypal-choice input { flex: 0 0 auto; margin-top: 4px; }
  .paypal-choice span, .paypal-choice strong, .paypal-choice small { display: block; }
  .paypal-choice strong { color: var(--paypal-ink); margin-bottom: 4px; }
  .paypal-choice small { color: var(--paypal-muted); line-height: 1.45; }

  .paypal-security-note,
  .paypal-legacy-warning {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    border-radius: 12px;
    line-height: 1.5;
    margin-bottom: var(--paypal-space-3);
    padding: 12px var(--paypal-space-2);
  }

  .paypal-security-note { color: #15537b; background: var(--paypal-blue-soft); }
  .paypal-security-note strong, .paypal-security-note span { display: block; }
  .paypal-legacy-warning { color: #815000; background: #fff7e6; }

  .paypal-secret-state {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    margin-top: -8px;
  }

  .paypal-secret-state.is-saved { color: #08783e; }
  .paypal-secret-state.is-missing { color: #9a5b00; }
  .paypal-advanced { overflow: hidden; }

  .paypal-advanced summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--paypal-space-2);
    cursor: pointer;
    list-style: none;
    padding: var(--paypal-space-2) var(--paypal-space-3);
  }

  .paypal-advanced summary::-webkit-details-marker { display: none; }
  .paypal-advanced summary:focus-visible { outline: 3px solid rgba(0, 112, 186, .25); outline-offset: -3px; }
  .paypal-advanced summary strong, .paypal-advanced summary small { display: block; }
  .paypal-advanced summary strong { color: var(--paypal-ink); }
  .paypal-advanced summary small { color: var(--paypal-muted); margin-top: 2px; }
  .paypal-advanced summary i { transition: transform .15s ease; }
  .paypal-advanced[open] summary i { transform: rotate(180deg); }

  .paypal-advanced-content {
    display: grid;
    gap: var(--paypal-space-3);
    border-top: 1px solid var(--paypal-border);
    padding: var(--paypal-space-3);
  }

  .paypal-optional-field { max-width: 640px; }
  .paypal-config [hidden] { display: none !important; }

  @media (max-width: 767.98px) {
    .paypal-overview, .paypal-section-heading { flex-direction: column; }
    .paypal-overview-status { flex-basis: auto; width: 100%; }
    .paypal-control-grid, .paypal-choice-grid, .paypal-choice-grid-compact { grid-template-columns: 1fr; }
    .paypal-section, .paypal-overview, .paypal-advanced-content { padding: var(--paypal-space-2); }
  }

  @media (prefers-reduced-motion: reduce) {
    .paypal-choice,
    .paypal-advanced summary i {
      transition: none;
    }
  }
</style>

@push('footer')
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const root = document.querySelector('.paypal-config');
      if (!root) return;

      const errorSummary = document.getElementById('paypal-form-errors');
      if (errorSummary) errorSummary.focus();

      root.querySelectorAll('input[name]').forEach((input) => {
        if (!input.id) input.id = 'paypal-' + input.name.replace(/[^a-z0-9_-]/gi, '-');
        const row = input.closest('.mb-3');
        const label = row ? row.querySelector('label') : null;
        if (label && !label.htmlFor) label.htmlFor = input.id;

        const feedback = input.parentElement ? input.parentElement.querySelector('.invalid-feedback') : null;
        if (feedback && input.classList.contains('is-invalid')) {
          feedback.id = input.id + '-error';
          input.setAttribute('aria-invalid', 'true');
          input.setAttribute('aria-describedby', feedback.id);
        }
      });

      const selectedValue = (name, fallback) => {
        const selected = root.querySelector('input[name="' + name + '"]:checked');
        return selected ? selected.value : fallback;
      };

      const activeEnvironment = () => selectedValue('sandbox_mode', '1') === '1' ? 'sandbox' : 'live';
      const activeMode = () => selectedValue('api_mode', 'rest');
      const isEnabled = () => selectedValue('status', '0') === '1';
      const hasCredential = (input) => input.value.trim() !== ''
        || (input.dataset.paypalSecret === '1' && input.dataset.paypalSaved === '1');

      const credentialsComplete = () => {
        const selector = '[data-paypal-credential="' + activeMode() + '"][data-paypal-environment="' + activeEnvironment() + '"]';
        return Array.from(root.querySelectorAll(selector)).every(hasCredential);
      };

      const refresh = () => {
        const environment = activeEnvironment();
        const mode = activeMode();

        root.querySelectorAll('[data-paypal-environment-panel]').forEach((panel) => {
          panel.hidden = panel.dataset.paypalEnvironmentPanel !== environment;
        });
        root.querySelectorAll('[data-paypal-mode-panel]').forEach((panel) => {
          panel.hidden = panel.dataset.paypalModePanel !== mode;
        });
        root.querySelectorAll('[data-paypal-merchant-panel]').forEach((panel) => {
          panel.hidden = panel.dataset.paypalMerchantPanel !== environment;
        });
        root.querySelectorAll('[data-paypal-dashboard]').forEach((link) => {
          link.hidden = link.dataset.paypalDashboard !== environment;
        });

        root.querySelectorAll('[data-paypal-credential]').forEach((input) => {
          const active = isEnabled()
            && input.dataset.paypalCredential === mode
            && input.dataset.paypalEnvironment === environment;
          input.required = active && !(input.dataset.paypalSecret === '1' && input.dataset.paypalSaved === '1');
          input.setAttribute('aria-required', input.required ? 'true' : 'false');
        });

        document.getElementById('paypal-environment-summary').textContent = environment === 'sandbox'
          ? root.dataset.sandboxLabel
          : root.dataset.liveLabel;
        document.getElementById('paypal-api-summary').textContent = mode === 'rest'
          ? root.dataset.restLabel
          : root.dataset.nvpLabel;

        const status = document.getElementById('paypal-configuration-status');
        status.className = 'paypal-status-badge';
        if (!isEnabled()) {
          status.textContent = root.dataset.disabledLabel;
          status.classList.add('is-disabled');
        } else if (credentialsComplete()) {
          status.textContent = root.dataset.readyLabel;
          status.classList.add('is-ready');
        } else {
          status.textContent = root.dataset.incompleteLabel;
          status.classList.add('is-incomplete');
        }
      };

      root.querySelectorAll('input').forEach((input) => {
        input.addEventListener('change', refresh);
        input.addEventListener('input', refresh);
      });

      refresh();
    });
  </script>
@endpush
