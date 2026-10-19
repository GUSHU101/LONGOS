@extends('layout.master')

@section('body-class', 'page-login')
@section('title', __('shop/login.index') . ' · ' . site_brand_name())

@push('header')
  <script src="{{ asset('vendor/vue/2.7/vue' . (!config('app.debug') ? '.min' : '') . '.js') }}"></script>
@endpush

@section('content')
  <div class="auth-page {{ request('iframe') ? 'auth-page--iframe' : '' }}" id="page-login" v-cloak>
    <div class="auth-shell">
      <section class="auth-story" aria-labelledby="auth-page-title">
        <div class="auth-story__content">
          <a class="auth-brand" href="{{ shop_route('home.index') }}" aria-label="{{ site_brand_name() }}">
            <img src="{{ image_origin(site_brand_asset('shop_logo')) }}" alt="{{ site_brand_name() }}">
          </a>
          <p class="auth-eyebrow">{{ __('shop/login.account_eyebrow') }}</p>
          @hookwrapper('account.login.heading')
          <h1 id="auth-page-title">{{ __('shop/login.welcome_title', ['store' => site_brand_name()]) }}</h1>
          @endhookwrapper
          <p class="auth-story__description">{{ __('shop/login.welcome_description') }}</p>

          <ul class="auth-benefits" aria-label="{{ __('shop/login.account_benefits') }}">
            <li><i class="bi bi-lightning-charge" aria-hidden="true"></i><span>{{ __('shop/login.benefit_checkout') }}</span></li>
            <li><i class="bi bi-box-seam" aria-hidden="true"></i><span>{{ __('shop/login.benefit_orders') }}</span></li>
            <li><i class="bi bi-geo-alt" aria-hidden="true"></i><span>{{ __('shop/login.benefit_addresses') }}</span></li>
          </ul>
        </div>
        <p class="auth-story__security"><i class="bi bi-shield-check" aria-hidden="true"></i>{{ __('shop/login.secure_account') }}</p>
      </section>

      <section class="auth-panel" aria-label="{{ __('shop/login.login_and_sign') }}">
        <div class="auth-tabs" role="tablist" aria-label="{{ __('shop/login.account_tabs') }}">
          <button id="auth-tab-login" type="button" role="tab" :aria-selected="activeMode === 'login' ? 'true' : 'false'" aria-controls="auth-panel-login" :tabindex="activeMode === 'login' ? 0 : -1" :class="{ 'is-active': activeMode === 'login' }" @click="activateMode('login')" @keydown.right.prevent="activateMode('register', true)">{{ __('shop/login.login') }}</button>
          <button id="auth-tab-register" type="button" role="tab" :aria-selected="activeMode === 'register' ? 'true' : 'false'" aria-controls="auth-panel-register" :tabindex="activeMode === 'register' ? 0 : -1" :class="{ 'is-active': activeMode === 'register' }" @click="activateMode('register')" @keydown.left.prevent="activateMode('login', true)">{{ __('shop/login.register') }}</button>
        </div>

        <div class="auth-feedback auth-feedback--success" role="status" aria-live="polite" v-if="statusMessage">
          <i class="bi bi-check-circle" aria-hidden="true"></i><span>@{{ statusMessage }}</span>
        </div>
        <div class="auth-feedback auth-feedback--error" role="alert" v-if="formError">
          <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>@{{ formError }}</span>
        </div>

        <div id="auth-panel-login" class="auth-tab-panel" role="tabpanel" aria-labelledby="auth-tab-login" v-show="activeMode === 'login'">
          <header class="auth-panel__heading">
            <h2>{{ __('shop/login.sign_in_title') }}</h2>
            <p>{{ __('shop/login.sign_in_description') }}</p>
          </header>

          <form ref="loginFormElement" class="auth-form" @submit.prevent="submitAccount('login')">
            @hookwrapper('account.login.email')
            <div class="auth-field">
              <label for="login-email">{{ __('shop/login.email') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.login.email }">
                <i class="bi bi-envelope" aria-hidden="true"></i>
                <input ref="loginEmail" id="login-email" v-model.trim="loginForm.email" type="email" name="email" inputmode="email" autocomplete="username" autocapitalize="none" spellcheck="false" required aria-required="true" :aria-invalid="fieldErrors.login.email ? 'true' : 'false'" :aria-describedby="fieldErrors.login.email ? 'login-email-error' : null" placeholder="{{ __('shop/login.email_address') }}" @input="clearFieldError('login', 'email')">
              </div>
              <p id="login-email-error" class="auth-field-error" v-if="fieldErrors.login.email">@{{ fieldErrors.login.email }}</p>
            </div>
            @endhookwrapper

            @hookwrapper('account.login.password')
            <div class="auth-field">
              <div class="auth-field__label-row">
                <label for="login-password">{{ __('shop/login.password') }}</label>
                @if (!request('iframe'))
                  @hookwrapper('account.login.forget_password')
                  <a class="auth-forgotten-link" data-auth-recovery-link href="{{ shop_route('forgotten.index') }}">{{ __('shop/login.forget_password') }}</a>
                  @endhookwrapper
                @endif
              </div>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.login.password }">
                <i class="bi bi-lock" aria-hidden="true"></i>
                <input id="login-password" v-model="loginForm.password" :type="showLoginPassword ? 'text' : 'password'" name="password" autocomplete="current-password" required aria-required="true" :aria-invalid="fieldErrors.login.password ? 'true' : 'false'" :aria-describedby="fieldErrors.login.password ? 'login-password-error' : null" placeholder="{{ __('shop/login.password') }}" @input="clearFieldError('login', 'password')">
                <button type="button" class="auth-password-toggle" :aria-label="showLoginPassword ? passwordLabels.hide : passwordLabels.show" :aria-pressed="showLoginPassword ? 'true' : 'false'" aria-controls="login-password" @click="showLoginPassword = !showLoginPassword"><i :class="showLoginPassword ? 'bi bi-eye-slash' : 'bi bi-eye'" aria-hidden="true"></i></button>
              </div>
              <p id="login-password-error" class="auth-field-error" v-if="fieldErrors.login.password">@{{ fieldErrors.login.password }}</p>
            </div>
            @endhookwrapper

            @hook('account.login.password.after')

            @hookwrapper('account.login.new.login')
            <button class="auth-submit" type="submit" :disabled="submitting !== null">
              <span class="spinner-border spinner-border-sm" aria-hidden="true" v-if="submitting === 'login'"></span>
              <span>@{{ submitting === 'login' ? submitLabels.signingIn : submitLabels.signIn }}</span>
              <i class="bi bi-arrow-right" aria-hidden="true" v-if="submitting !== 'login'"></i>
            </button>
            @endhookwrapper
          </form>

          @if($social_buttons)
            <div class="auth-social">
              <div class="auth-social__divider"><span>{{ __('shop/login.third_party_logins') }}</span></div>
              <div class="auth-social__buttons">
                @foreach($social_buttons as $button)
                  {!! $button !!}
                @endforeach
              </div>
            </div>
          @endif

          <p class="auth-switch-copy">{{ __('shop/login.not_already') }} <button type="button" @click="activateMode('register', true)">{{ __('shop/login.create_account') }}</button></p>
        </div>

        <div id="auth-panel-register" class="auth-tab-panel" role="tabpanel" aria-labelledby="auth-tab-register" v-show="activeMode === 'register'">
          <header class="auth-panel__heading">
            <h2>{{ __('shop/login.create_title') }}</h2>
            <p>{{ __('shop/login.create_description') }}</p>
          </header>

          <form ref="registerFormElement" class="auth-form" @submit.prevent="submitAccount('register')">
            @hookwrapper('account.login.new.email')
            <div class="auth-field">
              <label for="register-email">{{ __('shop/login.email') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.register.email }">
                <i class="bi bi-envelope" aria-hidden="true"></i>
                <input ref="registerEmail" id="register-email" v-model.trim="registerForm.email" type="email" name="email" inputmode="email" autocomplete="email" autocapitalize="none" spellcheck="false" required aria-required="true" :aria-invalid="fieldErrors.register.email ? 'true' : 'false'" :aria-describedby="fieldErrors.register.email ? 'register-email-error' : null" placeholder="{{ __('shop/login.email_address') }}" @input="clearFieldError('register', 'email')">
              </div>
              <p id="register-email-error" class="auth-field-error" v-if="fieldErrors.register.email">@{{ fieldErrors.register.email }}</p>
            </div>
            @endhookwrapper

            @hookwrapper('account.login.new.password')
            <div class="auth-field">
              <label for="register-password">{{ __('shop/login.password') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.register.password }">
                <i class="bi bi-lock" aria-hidden="true"></i>
                <input id="register-password" v-model="registerForm.password" :type="showRegisterPassword ? 'text' : 'password'" name="password" autocomplete="new-password" minlength="8" maxlength="255" required aria-required="true" :aria-invalid="fieldErrors.register.password ? 'true' : 'false'" :aria-describedby="fieldErrors.register.password ? 'register-password-error register-password-help' : 'register-password-help'" placeholder="{{ __('shop/login.password') }}" @input="clearFieldError('register', 'password')">
                <button type="button" class="auth-password-toggle" :aria-label="showRegisterPassword ? passwordLabels.hide : passwordLabels.show" :aria-pressed="showRegisterPassword ? 'true' : 'false'" aria-controls="register-password" @click="showRegisterPassword = !showRegisterPassword"><i :class="showRegisterPassword ? 'bi bi-eye-slash' : 'bi bi-eye'" aria-hidden="true"></i></button>
              </div>
              <p id="register-password-help" class="auth-field-help">{{ __('shop/login.password_requirements') }}</p>
              <p id="register-password-error" class="auth-field-error" v-if="fieldErrors.register.password">@{{ fieldErrors.register.password }}</p>
            </div>
            @endhookwrapper

            <div class="auth-field">
              <label for="register-password-confirmation">{{ __('shop/login.confirm_password') }}</label>
              <div class="auth-input-wrap" :class="{ 'is-invalid': fieldErrors.register.password_confirmation }">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <input id="register-password-confirmation" v-model="registerForm.password_confirmation" :type="showRegisterConfirmation ? 'text' : 'password'" name="password_confirmation" autocomplete="new-password" minlength="8" maxlength="255" required aria-required="true" :aria-invalid="fieldErrors.register.password_confirmation ? 'true' : 'false'" :aria-describedby="fieldErrors.register.password_confirmation ? 'register-password-confirmation-error' : null" placeholder="{{ __('shop/login.confirm_password') }}" @input="clearFieldError('register', 'password_confirmation')">
                <button type="button" class="auth-password-toggle" :aria-label="showRegisterConfirmation ? passwordLabels.hide : passwordLabels.show" :aria-pressed="showRegisterConfirmation ? 'true' : 'false'" aria-controls="register-password-confirmation" @click="showRegisterConfirmation = !showRegisterConfirmation"><i :class="showRegisterConfirmation ? 'bi bi-eye-slash' : 'bi bi-eye'" aria-hidden="true"></i></button>
              </div>
              <p id="register-password-confirmation-error" class="auth-field-error" v-if="fieldErrors.register.password_confirmation">@{{ fieldErrors.register.password_confirmation }}</p>
            </div>

            @hook('account.login.new.confirm_password.bottom')

            @hookwrapper('account.login.new.register')
            <button class="auth-submit" type="submit" :disabled="submitting !== null">
              <span class="spinner-border spinner-border-sm" aria-hidden="true" v-if="submitting === 'register'"></span>
              <span>@{{ submitting === 'register' ? submitLabels.creating : submitLabels.create }}</span>
              <i class="bi bi-arrow-right" aria-hidden="true" v-if="submitting !== 'register'"></i>
            </button>
            @endhookwrapper
          </form>

          <p class="auth-switch-copy">{{ __('shop/login.already') }} <button type="button" @click="activateMode('login', true)">{{ __('shop/login.login') }}</button></p>
        </div>
      </section>
    </div>
  </div>
@endsection

@push('add-scripts')
  <script>
    const loginStoreUrl = {{ Illuminate\Support\Js::from(shop_route('login.store')) }};
    const registerStoreUrl = {{ Illuminate\Support\Js::from(shop_route('register.store')) }};
    const accountHomeUrl = {{ Illuminate\Support\Js::from(shop_route('account.index')) }};
    const requestedAuthMode = {{ Illuminate\Support\Js::from((string) request('mode', '')) }};

    let app = new Vue({
      el: '#page-login',
      data: {
        activeMode: requestedAuthMode === 'register' ? 'register' : 'login',
        submitting: null,
        formError: '',
        statusMessage: '',
        showLoginPassword: false,
        showRegisterPassword: false,
        showRegisterConfirmation: false,
        passwordLabels: {
          show: {{ Illuminate\Support\Js::from(__('shop/login.show_password')) }},
          hide: {{ Illuminate\Support\Js::from(__('shop/login.hide_password')) }},
        },
        submitLabels: {
          signIn: {{ Illuminate\Support\Js::from(__('shop/login.login')) }},
          signingIn: {{ Illuminate\Support\Js::from(__('shop/login.signing_in')) }},
          create: {{ Illuminate\Support\Js::from(__('shop/login.create_account')) }},
          creating: {{ Illuminate\Support\Js::from(__('shop/login.creating_account')) }},
        },
        loginForm: { email: '', password: '' },
        registerForm: { email: '', password: '', password_confirmation: '' },
        fieldErrors: {
          login: { email: '', password: '' },
          register: { email: '', password: '', password_confirmation: '' },
        },
        @stack('login.vue.data')
      },
      beforeMount () {
        @hook('shop.login.vue.beforeMount')
      },
      mounted() {
        this.$nextTick(() => {
          const field = this.activeMode === 'register' ? this.$refs.registerEmail : this.$refs.loginEmail;
          if (field && window.innerWidth >= 768) field.focus();
        });
      },
      methods: {
        activateMode(mode, focusTab = false) {
          if (!['login', 'register'].includes(mode)) return;
          this.activeMode = mode;
          this.formError = '';
          this.statusMessage = '';
          this.$nextTick(() => {
            if (focusTab) {
              document.getElementById(`auth-tab-${mode}`)?.focus();
              return;
            }
            const field = mode === 'register' ? this.$refs.registerEmail : this.$refs.loginEmail;
            field?.focus();
          });
        },
        clearFieldError(mode, field) {
          this.fieldErrors[mode][field] = '';
          this.formError = '';
        },
        firstErrorField(mode) {
          const firstKey = Object.keys(this.fieldErrors[mode]).find((key) => this.fieldErrors[mode][key]);
          if (!firstKey) return;
          this.$nextTick(() => document.querySelector(`#auth-panel-${mode} [name="${firstKey}"]`)?.focus());
        },
        applyServerErrors(error, mode) {
          const payload = error && error.response && error.response.data ? error.response.data : {};
          const errors = payload.errors && typeof payload.errors === 'object' ? payload.errors : {};
          Object.keys(errors).forEach((field) => {
            if (Object.prototype.hasOwnProperty.call(this.fieldErrors[mode], field)) {
              const messages = Array.isArray(errors[field]) ? errors[field] : [errors[field]];
              this.fieldErrors[mode][field] = String(messages[0] || '');
            }
          });
          if (payload.data && payload.data.error === 'password' && mode === 'login') {
            this.fieldErrors.login.password = String(payload.message || '');
          }
          this.formError = String(payload.message || {{ Illuminate\Support\Js::from(__('shop/login.authentication_unavailable')) }});
          this.firstErrorField(mode);
        },
        submitAccount(mode) {
          if (this.submitting !== null) return;
          const formElement = mode === 'register' ? this.$refs.registerFormElement : this.$refs.loginFormElement;
          if (!formElement || !formElement.reportValidity()) return;

          this.formError = '';
          this.statusMessage = '';
          Object.keys(this.fieldErrors[mode]).forEach((field) => { this.fieldErrors[mode][field] = ''; });

          if (mode === 'register' && this.registerForm.password !== this.registerForm.password_confirmation) {
            this.fieldErrors.register.password_confirmation = {{ Illuminate\Support\Js::from(__('shop/login.password_err')) }};
            this.formError = {{ Illuminate\Support\Js::from(__('shop/login.check_form')) }};
            this.firstErrorField('register');
            return;
          }

          const url = mode === 'register' ? registerStoreUrl : loginStoreUrl;
          const payload = mode === 'register' ? { ...this.registerForm } : { ...this.loginForm };
          this.submitting = mode;
          $http.post(url, payload, {hmsg: true, hload: true}).then((res) => {
            const responseData = res && res.data ? res.data : {};
            if (responseData.pending) {
              this.loginForm.email = this.registerForm.email;
              this.loginForm.password = '';
              this.activateMode('login');
              this.statusMessage = String(res.message || '');
              return;
            }
            @if (!request('iframe'))
              window.location.assign(responseData.redirect || accountHomeUrl);
            @else
              const frameIndex = parent.layer.getFrameIndex(window.name);
              window.setTimeout(() => {
                parent.layer.close(frameIndex);
                parent.window.location.reload();
              }, 250);
            @endif
          }).catch((error) => {
            this.applyServerErrors(error, mode);
          }).finally(() => {
            this.submitting = null;
          });
        },
        @stack('login.vue.method')
      },
      @hook('shop.login.vue.options')
    });

    @hook('account.login.form.js.after')

    window.addEventListener('message', function (event) {
      if (event.origin !== window.location.origin) return;
      if (event.data && event.data.type === 'social_callback' && event.data.data === 'close_window' && window.name) {
        const frameIndex = parent.layer.getFrameIndex(window.name);
        parent.layer.close(frameIndex);
        parent.window.location.reload();
      }
    }, false);
  </script>
@endpush
