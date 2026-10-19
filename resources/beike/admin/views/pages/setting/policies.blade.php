@extends('admin::layouts.master')

@section('title', __('admin/common.settings.policies'))
@section('content-area-class', 'w-max-1200')
@section('page-title-back', admin_route('settings.index'))
@section('head-form-btns', true)

@push('header')
  <script src="{{ asset('vendor/tinymce/5.9.1/tinymce.min.js') }}"></script>
  <style>
    .policy-profile-grid { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .policy-profile-grid .span-2 { grid-column: 1 / -1; }
    .policy-fixed-title { color: #111827; font-size: 1rem; font-weight: 650; }
    .policy-template-actions { align-items: center; display: flex; flex-wrap: wrap; gap: .75rem; }
    .policy-document-index { display: grid; gap: .65rem; grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .policy-document-index__item { align-items: center; background: #fff; border: 1px solid #d0d5dd; border-radius: .65rem; color: #344054; display: flex; gap: .6rem; justify-content: space-between; min-height: 3.25rem; padding: .65rem .8rem; text-decoration: none; }
    .policy-document-index__item:hover { border-color: #84adff; color: #175cd3; }
    .policy-document-index__item:focus-visible { box-shadow: 0 0 0 3px rgba(21, 94, 239, .18); outline: 2px solid #155eef; outline-offset: 2px; }
    .policy-document-index__item span { font-size: .82rem; font-weight: 700; }
    .policy-document-index__item small { border-radius: 999px; color: #667085; flex: 0 0 auto; font-size: .67rem; padding: .2rem .45rem; }
    .policy-document-index__item small.is-published { background: #ecfdf3; color: #027a48; }
    .policy-document-card { scroll-margin-top: 5rem; }
    @media (max-width: 767.98px) {
      .policy-profile-grid { grid-template-columns: minmax(0, 1fr); }
      .policy-profile-grid .span-2 { grid-column: auto; }
      .policy-document-index { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 479.98px) { .policy-document-index { grid-template-columns: minmax(0, 1fr); } }
  </style>
@endpush

@section('content')
  @php
    $profile = old('store_policy_profile', $policyProfile);
  @endphp

  @if (session('success'))
    <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
  @endif
  @if (session('error'))
    <x-admin-alert type="danger" msg="{{ session('error') }}" class="mt-4"/>
  @endif

  <div class="alert alert-info mt-4" role="status">{{ __('admin/setting.policies_help') }}</div>

  <form action="{{ admin_route('settings.store') }}" class="needs-validation" novalidate method="POST" id="form-app">
    @csrf
    <input type="hidden" name="return_url" value="{{ url()->full() }}"/>

    <section class="card mb-4" aria-labelledby="policy-template-heading">
      <div class="card-header">
        <h5 class="card-title mb-1" id="policy-template-heading">{{ __('admin/setting.policy_template_title') }}</h5>
        <p class="text-secondary mb-0">{{ __('admin/setting.policy_template_help') }}</p>
      </div>
      <div class="card-body">
        <div class="alert alert-warning" role="note">
          <i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>{{ __('admin/setting.policy_template_legal_notice') }}
        </div>

        <div class="policy-profile-grid">
          <div>
            <label class="form-label" for="policy-business-name">{{ __('admin/setting.policy_business_name') }}</label>
            <input class="form-control" id="policy-business-name" type="text" maxlength="120" required autocomplete="organization"
                   name="store_policy_profile[business_name]" value="{{ $profile['business_name'] ?? '' }}" data-policy-profile="business_name">
          </div>
          <div>
            <label class="form-label" for="policy-contact-email">{{ __('admin/setting.policy_contact_email') }}</label>
            <input class="form-control" id="policy-contact-email" type="email" maxlength="190" required autocomplete="email"
                   name="store_policy_profile[contact_email]" value="{{ $profile['contact_email'] ?? '' }}" data-policy-profile="contact_email">
          </div>
          <div>
            <label class="form-label" for="policy-contact-phone">{{ __('admin/setting.policy_contact_phone') }}</label>
            <input class="form-control" id="policy-contact-phone" type="tel" maxlength="60" autocomplete="tel"
                   name="store_policy_profile[contact_phone]" value="{{ $profile['contact_phone'] ?? '' }}" data-policy-profile="contact_phone">
          </div>
          <div>
            <label class="form-label" for="policy-support-hours">{{ __('admin/setting.policy_support_hours') }}</label>
            <input class="form-control" id="policy-support-hours" type="text" maxlength="180"
                   name="store_policy_profile[support_hours]" value="{{ $profile['support_hours'] ?? '' }}" data-policy-profile="support_hours"
                   aria-describedby="policy-support-hours-help">
            <div class="form-text" id="policy-support-hours-help">{{ __('admin/setting.policy_support_hours_help') }}</div>
          </div>
          <div>
            <label class="form-label" for="policy-support-response">{{ __('admin/setting.policy_support_response') }}</label>
            <div class="input-group">
              <input class="form-control" id="policy-support-response" type="number" min="1" max="336" required
                     name="store_policy_profile[support_response_hours]" value="{{ $profile['support_response_hours'] ?? 48 }}" data-policy-profile="support_response_hours">
              <span class="input-group-text">{{ __('admin/setting.policy_hours') }}</span>
            </div>
          </div>
          <div>
            <label class="form-label" for="policy-cancellation-window">{{ __('admin/setting.policy_cancellation_window') }}</label>
            <div class="input-group">
              <input class="form-control" id="policy-cancellation-window" type="number" min="0" max="168" required
                     name="store_policy_profile[cancellation_window_hours]" value="{{ $profile['cancellation_window_hours'] ?? 2 }}" data-policy-profile="cancellation_window_hours">
              <span class="input-group-text">{{ __('admin/setting.policy_hours') }}</span>
            </div>
            <div class="form-text">{{ __('admin/setting.policy_cancellation_window_help') }}</div>
          </div>
          <div class="span-2">
            <label class="form-label" for="policy-business-address">{{ __('admin/setting.policy_business_address') }}</label>
            <input class="form-control" id="policy-business-address" type="text" maxlength="500" required autocomplete="street-address"
                   name="store_policy_profile[business_address]" value="{{ $profile['business_address'] ?? '' }}" data-policy-profile="business_address">
            <div class="form-text">{{ __('admin/setting.policy_business_address_help') }}</div>
          </div>
          <div>
            <label class="form-label" for="policy-jurisdiction">{{ __('admin/setting.policy_jurisdiction') }}</label>
            <input class="form-control" id="policy-jurisdiction" type="text" maxlength="160" required
                   name="store_policy_profile[jurisdiction]" value="{{ $profile['jurisdiction'] ?? '' }}" data-policy-profile="jurisdiction">
            <div class="form-text">{{ __('admin/setting.policy_jurisdiction_help') }}</div>
          </div>
          <div>
            <label class="form-label" for="policy-effective-date">{{ __('admin/setting.policy_effective_date') }}</label>
            <input class="form-control" id="policy-effective-date" type="date" required
                   name="store_policy_profile[effective_date]" value="{{ $profile['effective_date'] ?? '' }}" data-policy-profile="effective_date">
          </div>
          <div>
            <label class="form-label" for="policy-return-window">{{ __('admin/setting.policy_return_window') }}</label>
            <div class="input-group">
              <input class="form-control" id="policy-return-window" type="number" min="1" max="365" required
                     name="store_policy_profile[return_window_days]" value="{{ $profile['return_window_days'] ?? 30 }}" data-policy-profile="return_window_days">
              <span class="input-group-text">{{ __('admin/setting.policy_calendar_days') }}</span>
            </div>
          </div>
          <div>
            <label class="form-label" for="policy-refund-days">{{ __('admin/setting.policy_refund_days') }}</label>
            <div class="input-group">
              <input class="form-control" id="policy-refund-days" type="number" min="1" max="60" required
                     name="store_policy_profile[refund_processing_days]" value="{{ $profile['refund_processing_days'] ?? 10 }}" data-policy-profile="refund_processing_days">
              <span class="input-group-text">{{ __('admin/setting.policy_business_days') }}</span>
            </div>
          </div>
          <div>
            <label class="form-label" for="policy-processing-min">{{ __('admin/setting.policy_processing_days') }}</label>
            <div class="input-group">
              <input class="form-control" id="policy-processing-min" type="number" min="0" max="30" required aria-label="{{ __('admin/setting.policy_minimum') }}"
                     name="store_policy_profile[processing_days_min]" value="{{ $profile['processing_days_min'] ?? 1 }}" data-policy-profile="processing_days_min">
              <span class="input-group-text">–</span>
              <input class="form-control" type="number" min="0" max="60" required aria-label="{{ __('admin/setting.policy_maximum') }}"
                     name="store_policy_profile[processing_days_max]" value="{{ $profile['processing_days_max'] ?? 3 }}" data-policy-profile="processing_days_max">
              <span class="input-group-text">{{ __('admin/setting.policy_business_days') }}</span>
            </div>
          </div>
          <div>
            <label class="form-label" for="policy-transit-min">{{ __('admin/setting.policy_transit_days') }}</label>
            <div class="input-group">
              <input class="form-control" id="policy-transit-min" type="number" min="1" max="90" required aria-label="{{ __('admin/setting.policy_minimum') }}"
                     name="store_policy_profile[transit_days_min]" value="{{ $profile['transit_days_min'] ?? 5 }}" data-policy-profile="transit_days_min">
              <span class="input-group-text">–</span>
              <input class="form-control" type="number" min="1" max="180" required aria-label="{{ __('admin/setting.policy_maximum') }}"
                     name="store_policy_profile[transit_days_max]" value="{{ $profile['transit_days_max'] ?? 10 }}" data-policy-profile="transit_days_max">
              <span class="input-group-text">{{ __('admin/setting.policy_business_days') }}</span>
            </div>
          </div>
          <div>
            <label class="form-label" for="policy-return-shipping">{{ __('admin/setting.policy_return_shipping') }}</label>
            <select class="form-select" id="policy-return-shipping" name="store_policy_profile[return_shipping]" data-policy-profile="return_shipping">
              <option value="customer" @selected(($profile['return_shipping'] ?? 'customer') === 'customer')>{{ __('admin/setting.policy_paid_by_customer') }}</option>
              <option value="merchant" @selected(($profile['return_shipping'] ?? '') === 'merchant')>{{ __('admin/setting.policy_paid_by_merchant') }}</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="policy-international">{{ __('admin/setting.policy_international_shipping') }}</label>
            <select class="form-select" id="policy-international" name="store_policy_profile[international_shipping]" data-policy-profile="international_shipping">
              <option value="1" @selected((bool) ($profile['international_shipping'] ?? true))>{{ __('common.yes') }}</option>
              <option value="0" @selected(! (bool) ($profile['international_shipping'] ?? true))>{{ __('common.no') }}</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="policy-duties">{{ __('admin/setting.policy_duties') }}</label>
            <select class="form-select" id="policy-duties" name="store_policy_profile[duties_responsibility]" data-policy-profile="duties_responsibility">
              <option value="customer" @selected(($profile['duties_responsibility'] ?? 'customer') === 'customer')>{{ __('admin/setting.policy_paid_by_customer') }}</option>
              <option value="merchant" @selected(($profile['duties_responsibility'] ?? '') === 'merchant')>{{ __('admin/setting.policy_paid_by_merchant') }}</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="policy-data-sale">{{ __('admin/setting.policy_data_sale') }}</label>
            <select class="form-select" id="policy-data-sale" name="store_policy_profile[data_sale]" data-policy-profile="data_sale">
              <option value="no" @selected(($profile['data_sale'] ?? 'no') === 'no')>{{ __('admin/setting.policy_data_sale_no') }}</option>
              <option value="yes" @selected(($profile['data_sale'] ?? '') === 'yes')>{{ __('admin/setting.policy_data_sale_yes') }}</option>
            </select>
          </div>
        </div>

        <hr class="my-4">
        <div class="policy-template-actions">
          <button class="btn btn-primary" type="button" id="policy-generate-button">
            <i class="bi bi-stars me-1" aria-hidden="true"></i>{{ __('admin/setting.policy_generate_all') }}
          </button>
          <span class="text-secondary">{{ __('admin/setting.policy_generate_draft_help') }}</span>
          <span id="policy-generate-status" class="ms-auto" role="status" aria-live="polite"></span>
        </div>
      </div>
    </section>

    <nav class="card card-body mb-4" aria-label="{{ __('admin/setting.policy_document_navigation') }}">
      <div class="policy-document-index">
        @foreach ($policies as $policyKey => $policy)
          @php
            $isPublished = $policy['enabled'] && \Beike\Repositories\StorePolicyRepo::hasMeaningfulContent($policy['content']);
          @endphp
          <a class="policy-document-index__item" href="#policy-{{ $policyKey }}-heading">
            <span>{{ __('admin/setting.policy_' . $policyKey) }}</span>
            <small @class(['is-published' => $isPublished])>{{ $isPublished ? __('admin/setting.policy_status_published') : __('admin/setting.policy_status_draft') }}</small>
          </a>
        @endforeach
      </div>
    </nav>

    @foreach ($policies as $policyKey => $policy)
      @php
        $enabled = old("store_policies.{$policyKey}.enabled", $policy['enabled']);
        $content = old("store_policies.{$policyKey}.content", $policy['content']);
        $fieldId = 'policy-' . $policyKey;
        $definition = \Beike\Repositories\StorePolicyRepo::DEFINITIONS[$policyKey];
      @endphp
      <section class="card mb-4 policy-document-card" aria-labelledby="{{ $fieldId }}-heading">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div>
            <h5 class="card-title mb-1" id="{{ $fieldId }}-heading">{{ __('admin/setting.policy_' . $policyKey) }}</h5>
            <div class="policy-fixed-title">{{ $definition['default_title'] }}</div>
          </div>
          <div class="d-flex flex-wrap align-items-center gap-2">
            @if ($policy['enabled'] && \Beike\Repositories\StorePolicyRepo::hasMeaningfulContent($policy['content']))
              <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener noreferrer"
                 href="{{ shop_route('policies.show', ['policy' => $definition['slug']]) }}">
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>{{ __('admin/setting.policy_preview') }}
              </a>
            @endif
            <code>/policies/{{ $definition['slug'] }}</code>
          </div>
        </div>
        <div class="card-body">
          <input type="hidden" name="store_policies[{{ $policyKey }}][title]" value="{{ $definition['default_title'] }}">
          <x-admin::form.row title="{{ __('admin/setting.policy_enabled') }}">
            <div class="mb-1 mt-2">
              <div class="form-check form-check-inline">
                <input class="form-check-input" id="{{ $fieldId }}-enabled" type="radio"
                       name="store_policies[{{ $policyKey }}][enabled]" value="1" @checked((bool) $enabled)>
                <label class="form-check-label" for="{{ $fieldId }}-enabled">{{ __('common.enable') }}</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" id="{{ $fieldId }}-disabled" type="radio"
                       name="store_policies[{{ $policyKey }}][enabled]" value="0" @checked(! (bool) $enabled)>
                <label class="form-check-label" for="{{ $fieldId }}-disabled">{{ __('common.disable') }}</label>
              </div>
            </div>
          </x-admin::form.row>

          <x-admin::form.row title="{{ __('admin/setting.policy_content') }}">
            <div class="w-max-1000">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-2" aria-label="{{ __('admin/setting.policy_input_formats') }}">
                <span class="badge bg-light text-dark border">{{ __('admin/setting.policy_plain_text') }}</span>
                <span class="badge bg-light text-dark border">HTML</span>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" data-policy-html-source="{{ $fieldId }}-content" aria-controls="{{ $fieldId }}-content">
                  <i class="bi bi-code-slash me-1" aria-hidden="true"></i>{{ __('admin/setting.policy_edit_html') }}
                </button>
              </div>
              <textarea id="{{ $fieldId }}-content" name="store_policies[{{ $policyKey }}][content]" data-policy-key="{{ $policyKey }}"
                        data-tinymce-height="520" aria-describedby="{{ $fieldId }}-content-help" class="form-control tinymce">{{ $content }}</textarea>
              <div id="{{ $fieldId }}-content-help" class="help-text font-size-12 lh-base mt-2">{{ __('admin/setting.policy_content_help') }}</div>
            </div>
          </x-admin::form.row>
        </div>
      </section>
    @endforeach

    <x-admin::form.row title="">
      <button type="submit" class="btn btn-primary d-none mt-4">{{ __('common.submit') }}</button>
    </x-admin::form.row>
  </form>
@endsection

@push('footer')
  <script>
    document.addEventListener('click', function (event) {
      const sourceButton = event.target.closest('[data-policy-html-source]');
      if (sourceButton) {
        const editorId = sourceButton.getAttribute('data-policy-html-source');
        const openSourceEditor = function () {
          const editor = window.tinymce?.get(editorId);
          if (!editor) return false;
          editor.focus();
          editor.execCommand('mceCodeEditor');
          return true;
        };
        if (!openSourceEditor()) {
          window.setTimeout(function () {
            if (!openSourceEditor() && window.layer) layer.msg(@json(__('admin/setting.policy_editor_loading')));
          }, 300);
        }
        return;
      }

      const generateButton = event.target.closest('#policy-generate-button');
      if (!generateButton) return;

      const form = document.getElementById('form-app');
      const status = document.getElementById('policy-generate-status');
      const policyFields = Array.from(form.querySelectorAll('textarea[data-policy-key]'));
      const hasExistingContent = policyFields.some(function (field) {
        const editor = window.tinymce?.get(field.id);
        const value = editor ? editor.getContent({ format: 'text' }) : field.value.replace(/<[^>]*>/g, '');
        return value.trim() !== '';
      });
      if (hasExistingContent && !window.confirm(@json(__('admin/setting.policy_generate_confirm')))) return;

      const payload = new FormData();
      payload.append('_token', form.querySelector('input[name="_token"]').value);
      form.querySelectorAll('[data-policy-profile]').forEach(function (field) {
        payload.append('profile[' + field.dataset.policyProfile + ']', field.value);
      });

      generateButton.disabled = true;
      status.className = 'ms-auto text-secondary';
      status.textContent = @json(__('admin/setting.policy_generating'));

      fetch(@json(admin_route('settings.policies.generate')), {
        method: 'POST', body: payload, credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(async function (response) {
        const result = await response.json().catch(function () { return null; });
        if (!response.ok || !result || result.status !== 'success') {
          throw new Error(result?.message || @json(__('admin/setting.policy_generate_failed')));
        }

        Object.entries(result.data.profile || {}).forEach(function ([key, value]) {
          const field = form.querySelector('[data-policy-profile="' + key + '"]');
          if (field) field.value = typeof value === 'boolean' ? (value ? '1' : '0') : value;
        });
        Object.entries(result.data.policies || {}).forEach(function ([key, policy]) {
          const textarea = form.querySelector('textarea[data-policy-key="' + key + '"]');
          const enabled = form.querySelector('#policy-' + key + '-enabled');
          if (enabled) enabled.checked = true;
          if (!textarea) return;
          textarea.value = policy.content;
          const editor = window.tinymce?.get(textarea.id);
          if (editor) editor.setContent(policy.content);
        });

        status.className = 'ms-auto text-success';
        status.textContent = result.message;
        window.setTimeout(function () { policyFields[0]?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 100);
      }).catch(function (error) {
        status.className = 'ms-auto text-danger';
        status.textContent = error.message;
      }).finally(function () {
        generateButton.disabled = false;
      });
    });
  </script>
@endpush
