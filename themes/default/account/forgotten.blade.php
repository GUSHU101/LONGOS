@extends('layout.master')

@section('body-class', 'page-forgotten')
@section('title', __('shop/forgotten.index') . ' · ' . site_brand_name())

@push('header')
  <script src="{{ asset('vendor/vue/2.7/vue' . (!config('app.debug') ? '.min' : '') . '.js') }}"></script>
@endpush

@section('content')
  <main class="auth-page auth-recovery-page" id="page-forgotten" v-cloak>
    <div class="auth-shell auth-recovery-shell">
      <section class="auth-story" aria-labelledby="recovery-page-title">
        <div class="auth-story__content">
          <a class="auth-brand" href="{{ shop_route('home.index') }}" aria-label="{{ site_brand_name() }}">
            <img src="{{ image_origin(site_brand_asset('shop_logo')) }}" alt="{{ site_brand_name() }}">
          </a>
          <p class="auth-eyebrow">{{ __('shop/forgotten.recovery_eyebrow') }}</p>
          <h1 id="recovery-page-title">{{ __('shop/forgotten.follow_prompt') }}</h1>
          <p class="auth-story__description">{{ __('shop/forgotten.recovery_description') }}</p>
        </div>
        <p class="auth-story__security"><i class="bi bi-shield-check" aria-hidden="true"></i>{{ __('shop/login.secure_account') }}</p>
      </section>

      <section class="auth-panel" aria-labelledby="recovery-form-title">
        <ol class="auth-steps" aria-label="{{ __('shop/forgotten.recovery_steps') }}">
          <li :class="{ 'is-active': !isCode, 'is-complete': isCode }" :aria-current="!isCode ? 'step' : null"><span>1</span>{{ __('shop/forgotten.step_email') }}</li>
          <li :class="{ 'is-active': isCode }" :aria-current="isCode ? 'step' : null"><span>2</span>{{ __('shop/forgotten.step_secure') }}</li>
        </ol>

        <header class="auth-panel__heading">
          <h2 id="recovery-form-title">@{{ isCode ? labels.secureTitle : labels.emailTitle }}</h2>
          <p>@{{ isCode ? labels.secureDescription : labels.emailDescription }}</p>
        </header>

        <div class="auth-feedback auth-feedback--success" role="status" aria-live="polite" v-if="statusMessage">
          <i class="bi bi-check-circle" aria-hidden="true"></i><span>@{{ statusMessage }}</span>
        </div>
        <div class="auth-feedback auth-feedback--error" role="alert" v-if="formError">
          <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>@{{ formError }}</span>
        </div>

        <form ref="recoveryForm" class="auth-form" @submit.prevent="submitRecovery">
          <template v-if="!isCode">
            <div class="auth-field">
              <label for="recovery-email">{{ __('shop/forgotten.email') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.email }">
                <i class="bi bi-envelope" aria-hidden="true"></i>
                <input ref="email" id="recovery-email" v-model.trim="form.email" type="email" name="email" inputmode="email" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="255" required aria-required="true" :aria-invalid="fieldErrors.email ? 'true' : 'false'" :aria-describedby="fieldErrors.email ? 'recovery-email-error' : null" placeholder="{{ __('shop/forgotten.email_address') }}" @input="clearError('email')">
              </div>
              <p id="recovery-email-error" class="auth-field-error" v-if="fieldErrors.email">@{{ fieldErrors.email }}</p>
            </div>
          </template>

          <template v-else>
            <div class="auth-recovery-account">
              <span>{{ __('shop/forgotten.email_sent_to') }}</span>
              <strong>@{{ form.email }}</strong>
              <button type="button" @click="backToEmail">{{ __('shop/forgotten.change_email') }}</button>
            </div>

            <div class="auth-field">
              <label for="recovery-code">{{ __('shop/forgotten.verification_code') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.code }">
                <i class="bi bi-key" aria-hidden="true"></i>
                <input ref="code" id="recovery-code" v-model.trim="form.code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required aria-required="true" :aria-invalid="fieldErrors.code ? 'true' : 'false'" :aria-describedby="fieldErrors.code ? 'recovery-code-error' : null" placeholder="{{ __('shop/forgotten.code_placeholder') }}" @input="normaliseCode">
              </div>
              <p id="recovery-code-error" class="auth-field-error" v-if="fieldErrors.code">@{{ fieldErrors.code }}</p>
            </div>

            <div class="auth-field">
              <label for="recovery-password">{{ __('shop/forgotten.password') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.password }">
                <i class="bi bi-lock" aria-hidden="true"></i>
                <input id="recovery-password" v-model="form.password" :type="showPassword ? 'text' : 'password'" name="password" autocomplete="new-password" minlength="8" maxlength="255" required aria-required="true" :aria-invalid="fieldErrors.password ? 'true' : 'false'" :aria-describedby="fieldErrors.password ? 'recovery-password-error recovery-password-help' : 'recovery-password-help'" placeholder="{{ __('shop/forgotten.password') }}" @input="clearError('password')">
                <button type="button" class="auth-password-toggle" :aria-label="showPassword ? passwordLabels.hide : passwordLabels.show" :aria-pressed="showPassword ? 'true' : 'false'" aria-controls="recovery-password" @click="showPassword = !showPassword"><i :class="showPassword ? 'bi bi-eye-slash' : 'bi bi-eye'" aria-hidden="true"></i></button>
              </div>
              <p id="recovery-password-help" class="auth-field-help">{{ __('shop/login.password_requirements') }}</p>
              <p id="recovery-password-error" class="auth-field-error" v-if="fieldErrors.password">@{{ fieldErrors.password }}</p>
            </div>

            <div class="auth-field">
              <label for="recovery-password-confirmation">{{ __('shop/forgotten.confirm_password') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.password_confirmation }">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <input id="recovery-password-confirmation" v-model="form.password_confirmation" :type="showConfirmation ? 'text' : 'password'" name="password_confirmation" autocomplete="new-password" minlength="8" maxlength="255" required aria-required="true" :aria-invalid="fieldErrors.password_confirmation ? 'true' : 'false'" :aria-describedby="fieldErrors.password_confirmation ? 'recovery-password-confirmation-error' : null" placeholder="{{ __('shop/forgotten.confirm_password') }}" @input="clearError('password_confirmation')">
                <button type="button" class="auth-password-toggle" :aria-label="showConfirmation ? passwordLabels.hide : passwordLabels.show" :aria-pressed="showConfirmation ? 'true' : 'false'" aria-controls="recovery-password-confirmation" @click="showConfirmation = !showConfirmation"><i :class="showConfirmation ? 'bi bi-eye-slash' : 'bi bi-eye'" aria-hidden="true"></i></button>
              </div>
              <p id="recovery-password-confirmation-error" class="auth-field-error" v-if="fieldErrors.password_confirmation">@{{ fieldErrors.password_confirmation }}</p>
            </div>
          </template>

          <button class="auth-submit" type="submit" :disabled="submitting">
            <span class="spinner-border spinner-border-sm" aria-hidden="true" v-if="submitting"></span>
            <span>@{{ submitLabel }}</span>
            <i class="bi bi-arrow-right" aria-hidden="true" v-if="!submitting"></i>
          </button>
        </form>

        <div class="auth-recovery-links">
          <button type="button" v-if="isCode" @click="backToEmail"><i class="bi bi-arrow-left" aria-hidden="true"></i>{{ __('shop/forgotten.to_back') }}</button>
          <a href="{{ shop_route('login.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>{{ __('shop/forgotten.back_to_sign_in') }}</a>
        </div>
      </section>
    </div>
  </main>
@endsection

@push('add-scripts')
  <script>
    const forgottenPasswordUrl = {{ Illuminate\Support\Js::from(shop_route('forgotten.password')) }};
    const forgottenSendCodeUrl = {{ Illuminate\Support\Js::from(shop_route('forgotten.send_code')) }};
    const loginUrl = {{ Illuminate\Support\Js::from(shop_route('login.index')) }};
    const recoveryQuery = new URLSearchParams(window.location.search);

    new Vue({
      el: '#page-forgotten',
      data: {
        isCode: Boolean(recoveryQuery.get('code')),
        submitting: false,
        formError: '',
        statusMessage: '',
        showPassword: false,
        showConfirmation: false,
        form: {
          email: recoveryQuery.get('email') || '',
          code: (recoveryQuery.get('code') || '').replace(/\D/g, '').slice(0, 6),
          password: '',
          password_confirmation: '',
        },
        fieldErrors: { email: '', code: '', password: '', password_confirmation: '' },
        labels: {
          emailTitle: {{ Illuminate\Support\Js::from(__('shop/forgotten.email_title')) }},
          emailDescription: {{ Illuminate\Support\Js::from(__('shop/forgotten.email_forCode')) }},
          secureTitle: {{ Illuminate\Support\Js::from(__('shop/forgotten.secure_title')) }},
          secureDescription: {{ Illuminate\Support\Js::from(__('shop/forgotten.enter_password')) }},
          send: {{ Illuminate\Support\Js::from(__('shop/forgotten.send_code')) }},
          sending: {{ Illuminate\Support\Js::from(__('shop/forgotten.sending')) }},
          update: {{ Illuminate\Support\Js::from(__('shop/forgotten.update_password')) }},
          updating: {{ Illuminate\Support\Js::from(__('shop/forgotten.updating')) }},
          unavailable: {{ Illuminate\Support\Js::from(__('shop/forgotten.recovery_unavailable')) }},
        },
        passwordLabels: {
          show: {{ Illuminate\Support\Js::from(__('shop/login.show_password')) }},
          hide: {{ Illuminate\Support\Js::from(__('shop/login.hide_password')) }},
        },
      },
      computed: {
        submitLabel() {
          if (this.submitting) return this.isCode ? this.labels.updating : this.labels.sending;
          return this.isCode ? this.labels.update : this.labels.send;
        },
      },
      mounted() {
        this.$nextTick(() => (this.isCode ? this.$refs.code : this.$refs.email)?.focus());
      },
      methods: {
        clearError(field) {
          this.fieldErrors[field] = '';
          this.formError = '';
        },
        normaliseCode() {
          this.form.code = this.form.code.replace(/\D/g, '').slice(0, 6);
          this.clearError('code');
        },
        backToEmail() {
          this.isCode = false;
          this.form.code = '';
          this.form.password = '';
          this.form.password_confirmation = '';
          this.formError = '';
          this.statusMessage = '';
          Object.keys(this.fieldErrors).forEach((field) => { this.fieldErrors[field] = ''; });
          this.$nextTick(() => this.$refs.email?.focus());
        },
        applyServerErrors(error) {
          const payload = error && error.response && error.response.data ? error.response.data : {};
          const errors = payload.errors && typeof payload.errors === 'object' ? payload.errors : {};
          Object.keys(errors).forEach((field) => {
            if (!Object.prototype.hasOwnProperty.call(this.fieldErrors, field)) return;
            const messages = Array.isArray(errors[field]) ? errors[field] : [errors[field]];
            this.fieldErrors[field] = String(messages[0] || '');
          });
          this.formError = String(payload.message || this.labels.unavailable);
          const firstField = Object.keys(this.fieldErrors).find((field) => this.fieldErrors[field]);
          this.$nextTick(() => document.querySelector(`#page-forgotten [name="${firstField || (this.isCode ? 'code' : 'email')}"]`)?.focus());
        },
        submitRecovery() {
          if (this.submitting || !this.$refs.recoveryForm.reportValidity()) return;
          this.formError = '';
          this.statusMessage = '';
          Object.keys(this.fieldErrors).forEach((field) => { this.fieldErrors[field] = ''; });

          if (this.isCode && this.form.password !== this.form.password_confirmation) {
            this.fieldErrors.password_confirmation = {{ Illuminate\Support\Js::from(__('shop/forgotten.password_err')) }};
            this.formError = {{ Illuminate\Support\Js::from(__('shop/login.check_form')) }};
            this.$nextTick(() => document.getElementById('recovery-password-confirmation')?.focus());
            return;
          }

          this.submitting = true;
          const url = this.isCode ? forgottenPasswordUrl : forgottenSendCodeUrl;
          $http.post(url, { ...this.form }, {hmsg: true, hload: true}).then((res) => {
            this.statusMessage = String(res.message || '');
            if (this.isCode) {
              window.setTimeout(() => window.location.assign(loginUrl), 900);
              return;
            }
            this.isCode = true;
            this.$nextTick(() => this.$refs.code?.focus());
          }).catch((error) => this.applyServerErrors(error)).finally(() => {
            this.submitting = false;
          });
        },
      },
    });
  </script>
@endpush
