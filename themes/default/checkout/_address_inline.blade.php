@php
  $checkoutAccountAddressesUrl = shop_route('account.addresses.index');
  $checkoutCountryZonesUrlTemplate = shop_route('countries.zones.index', ['id' => '__COUNTRY_ID__']);
  $checkoutAddressSuggestionsUrl = shop_route('checkout.address-suggestions');
  $checkoutUserCountryUrl = shop_route('user-country');
@endphp

<div id="checkout-address-app" v-cloak>
  <form ref="addressForm" class="checkout-address-form" v-if="!source.isLogin || editorOpen" @submit.prevent="saveAddress" @change="maybeCommitGuestAddress" :aria-busy="saving ? 'true' : 'false'" :aria-describedby="formError ? 'checkout-address-error' : null">
    <section class="section checkout-contact-section">
      <div class="section-header">
        <h2 class="section-title">{{ __('shop/checkout.contact_information') }}</h2>
        <span class="section-subtitle" v-if="!source.isLogin">{{ __('shop/checkout.already_have_account') }} <button type="button" class="checkout-login-link guest-checkout-login">{{ __('shop/checkout.login') }}</button></span>
      </div>
      <div class="field">
        <label for="checkout-address-email">{{ __('shop/checkout.email_address') }}</label>
        <input id="checkout-address-email" type="email" autocomplete="email" placeholder="you@example.com" v-model.trim="addressForm.email" @input="markAddressDirty" :readonly="source.isLogin" required aria-required="true">
      </div>
    </section>

    <section class="section checkout-delivery-section">
      <div class="section-header"><h2 class="section-title">@{{ shippingRequired ? messages.shippingAddress : messages.billingAddress }}</h2></div>

      <div v-if="source.isLogin && source.addresses.length" class="checkout-saved-addresses" role="radiogroup" :aria-label="shippingRequired ? messages.shippingAddress : messages.billingAddress">
        <div v-for="(address, index) in source.addresses" :key="address.id" :class="['checkout-address-row', address.id == selectedAddressId ? 'active' : '']">
          <button type="button" class="checkout-address-choice" role="radio"
                  :disabled="saving"
                  :tabindex="address.id == selectedAddressId || (!selectedAddressId && index === 0) ? 0 : -1"
                  :aria-checked="address.id == selectedAddressId ? 'true' : 'false'"
                  @keydown.left.prevent="moveAddressSelection(index, -1)" @keydown.up.prevent="moveAddressSelection(index, -1)"
                  @keydown.right.prevent="moveAddressSelection(index, 1)" @keydown.down.prevent="moveAddressSelection(index, 1)"
                  @click="selectSavedAddress(address)">
            <span class="checkout-address-choice__radio" aria-hidden="true"></span>
            <span><strong>@{{ address.name }}</strong><small>@{{ addressSummary(address) }}</small></span>
          </button>
          <button type="button" class="checkout-address-choice__edit" :disabled="saving" @click="editSavedAddress(address)">{{ __('shop/checkout.edit') }}</button>
        </div>
        <button type="button" class="checkout-new-address" :disabled="saving" @click="startNewAddress">＋ {{ __('shop/checkout.add_new_address') }}</button>
      </div>

      <div class="row-2">
        <div class="field">
          <label for="checkout-address-first-name">{{ __('shop/checkout.first_name') }}</label>
          <input id="checkout-address-first-name" :autocomplete="shippingRequired ? 'shipping given-name' : 'billing given-name'" v-model.trim="addressForm.first_name" @input="syncFullName(); markAddressDirty()" placeholder="{{ __('shop/checkout.first_name') }}" maxlength="64" required aria-required="true">
        </div>
        <div class="field">
          <label for="checkout-address-last-name">{{ __('shop/checkout.last_name') }}</label>
          <input id="checkout-address-last-name" :autocomplete="shippingRequired ? 'shipping family-name' : 'billing family-name'" v-model.trim="addressForm.last_name" @input="syncFullName(); markAddressDirty()" placeholder="{{ __('shop/checkout.last_name') }}" maxlength="64" required aria-required="true">
        </div>
      </div>

      <div class="country-phone-grid">
        <div class="field">
          <label for="checkout-address-country">{{ __('shop/checkout.country_region') }}</label>
          <select id="checkout-address-country" v-model.number="addressForm.country_id" @change="markAddressDirty(); clearAddressSuggestions(); countryChange(addressForm.country_id, true)" :autocomplete="shippingRequired ? 'shipping country' : 'billing country'" required aria-required="true">
            <option value="" disabled>{{ __('shop/checkout.select_country_region') }}</option>
            <option v-for="country in source.countries" :key="country.id" :value="country.id">@{{ country.name }}</option>
          </select>
        </div>
        <div class="field">
          <label for="checkout-address-phone">{{ __('shop/checkout.phone_number') }}</label>
          <div class="phone-wrapper">
            <span class="phone-country">@{{ addressForm.calling_code || '+' }}</span>
            <input id="checkout-address-phone" :autocomplete="shippingRequired ? 'shipping tel-national' : 'billing tel-national'" inputmode="tel" v-model.trim="addressForm.phone" @input="markAddressDirty" placeholder="{{ __('shop/checkout.phone_number') }}" maxlength="32" :required="phoneRequired" :aria-required="phoneRequired ? 'true' : 'false'">
          </div>
          <div class="field-help">{{ __('shop/checkout.carrier_phone_help') }}</div>
        </div>
      </div>

      <div class="field address-autocomplete">
        <label for="checkout-address-line1">{{ __('shop/checkout.address') }}</label>
        <div class="address-autocomplete__control">
          <input id="checkout-address-line1"
                 :autocomplete="shippingRequired ? 'shipping address-line1' : 'billing address-line1'"
                 v-model.trim="addressForm.address_1"
                 @input="onAddressLineInput"
                 @change="syncBrowserAutofill()"
                 @keydown.down.prevent="moveSuggestion(1)"
                 @keydown.up.prevent="moveSuggestion(-1)"
                 @keydown.enter="handleAddressEnter"
                 @keydown.esc="clearAddressSuggestions()"
                 @blur="deferSuggestionClose"
                 placeholder="{{ __('shop/checkout.street_address') }}"
                 maxlength="255" required aria-required="true" role="combobox" aria-autocomplete="list"
                 aria-haspopup="listbox" :aria-busy="addressLookupLoading ? 'true' : 'false'"
                 aria-controls="checkout-address-suggestions" :aria-expanded="addressSuggestions.length ? 'true' : 'false'"
                 :aria-activedescendant="activeSuggestion >= 0 ? 'checkout-address-suggestion-' + activeSuggestion : null"
                 aria-describedby="checkout-address-lookup-status">
          <button class="address-autocomplete__search" type="button" @click="searchAddressNow" :disabled="addressLookupLoading || String(addressForm.address_1 || '').trim().length < 3">@{{ messages.addressLookupSearch }}</button>
          <div id="checkout-address-suggestions" class="address-suggestions" v-if="addressSuggestions.length" role="listbox" :aria-label="messages.addressSuggestions">
            <button v-for="(suggestion, index) in addressSuggestions" :id="'checkout-address-suggestion-' + index" :key="suggestion.label + index"
                    class="address-suggestion" type="button" role="option" :aria-selected="index === activeSuggestion ? 'true' : 'false'"
                    @mousedown.prevent @mouseenter="activeSuggestion = index" @click="selectAddressSuggestion(suggestion)">
              <strong>@{{ suggestion.address_1 }}</strong>
              <small>@{{ suggestionDetail(suggestion) }}</small>
            </button>
          </div>
        </div>
        <div id="checkout-address-lookup-status" class="address-helper address-autocomplete-status" :data-loading="addressLookupLoading ? 'true' : 'false'" aria-live="polite"><span class="address-helper-icon" aria-hidden="true">✦</span>@{{ addressLookupMessage || messages.addressLookupHelp }}</div>
        <div class="address-attribution"><a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">{{ __('shop/checkout.address_lookup_attribution') }}</a></div>
      </div>

      <div class="field">
        <label for="checkout-address-line2">{{ __('shop/checkout.address_line_2') }} <span style="font-weight:400;color:#666;">({{ __('shop/checkout.optional') }})</span></label>
        <input id="checkout-address-line2" :autocomplete="shippingRequired ? 'shipping address-line2' : 'billing address-line2'" v-model.trim="addressForm.address_2" @input="markAddressDirty" placeholder="{{ __('shop/checkout.address_line_2') }}" maxlength="255">
      </div>

      <div class="address-location-grid">
        <div class="field">
          <label for="checkout-address-city">{{ __('shop/checkout.city') }}</label>
          <input id="checkout-address-city" :autocomplete="shippingRequired ? 'shipping address-level2' : 'billing address-level2'" v-model.trim="addressForm.city" @input="markAddressDirty" @change="markAddressDirty(); syncBrowserAutofill()" placeholder="{{ __('shop/checkout.city') }}" maxlength="120" required aria-required="true">
        </div>
        <div class="field">
          <label for="checkout-address-zone">{{ __('shop/checkout.state') }}</label>
          <select id="checkout-address-zone" v-model.number="addressForm.zone_id" @change="markAddressDirty" :disabled="zonesLoading || zonesLoadFailed || !source.zones.length" :required="source.zones.length" :aria-required="source.zones.length ? 'true' : 'false'" :aria-invalid="zonesLoadFailed ? 'true' : 'false'" :aria-describedby="zonesLoadFailed ? 'checkout-zone-error' : null" :autocomplete="shippingRequired ? 'shipping address-level1' : 'billing address-level1'">
            <option value="">@{{ zonesLoading ? messages.loadingRegions : (source.zones.length ? messages.selectState : messages.regionNotRequired) }}</option>
            <option v-for="zone in source.zones" :key="zone.id" :value="zone.id">@{{ zone.name }}</option>
          </select>
          <div id="checkout-zone-error" class="field-error" role="alert" v-if="zonesLoadFailed">
            <span>@{{ messages.regionsLoadFailed }}</span>
            <button type="button" class="field-retry" @click="retryZones">@{{ messages.retry }}</button>
          </div>
        </div>
        <div class="field">
          <label for="checkout-address-postal-code">{{ __('shop/checkout.zip_code') }}</label>
          <input id="checkout-address-postal-code" :autocomplete="shippingRequired ? 'shipping postal-code' : 'billing postal-code'" v-model.trim="addressForm.zipcode" @input="markAddressDirty" @change="markAddressDirty(); syncBrowserAutofill()" placeholder="{{ __('shop/checkout.zip_code') }}" maxlength="32">
        </div>
      </div>

      <div class="checkout-address-actions">
        <button v-if="source.isLogin" class="address-save-button" type="submit" :disabled="saving || zonesLoading || zonesLoadFailed"><span v-if="saving">@{{ messages.saving }}</span><span v-else>@{{ shippingRequired ? messages.useThisAddress : messages.saveBillingAddress }}</span></button>
        <span v-else class="address-auto-apply" aria-live="polite">
          <span class="address-auto-apply__icon" aria-hidden="true">&#10003;</span>
          @{{ saving ? messages.addressApplying : messages.addressAutoApply }}
        </span>
        <button type="button" class="checkout-login-link" v-if="source.isLogin && source.addresses.length" :disabled="saving" @click="cancelEdit">{{ __('shop/checkout.cancel') }}</button>
        <span id="checkout-address-error" class="text-danger small" role="alert" v-if="formError">@{{ formError }}</span>
      </div>
    </section>
  </form>

  <template v-else>
    <section class="section checkout-contact-section">
      <div class="section-header"><h2 class="section-title">{{ __('shop/checkout.contact_information') }}</h2></div>
      <div class="field"><label for="checkout-saved-email">{{ __('shop/checkout.email_address') }}</label><input id="checkout-saved-email" type="email" :value="addressForm.email" readonly></div>
    </section>
    <section class="section checkout-delivery-section">
      <div class="section-header"><h2 class="section-title">@{{ shippingRequired ? messages.shippingAddress : messages.billingAddress }}</h2></div>
      <div class="checkout-saved-addresses" role="radiogroup" :aria-label="shippingRequired ? messages.shippingAddress : messages.billingAddress">
        <div v-for="(address, index) in source.addresses" :key="address.id" :class="['checkout-address-row', address.id == selectedAddressId ? 'active' : '']">
          <button type="button" class="checkout-address-choice" role="radio"
                  :disabled="saving"
                  :tabindex="address.id == selectedAddressId || (!selectedAddressId && index === 0) ? 0 : -1"
                  :aria-checked="address.id == selectedAddressId ? 'true' : 'false'"
                  @keydown.left.prevent="moveAddressSelection(index, -1)" @keydown.up.prevent="moveAddressSelection(index, -1)"
                  @keydown.right.prevent="moveAddressSelection(index, 1)" @keydown.down.prevent="moveAddressSelection(index, 1)"
                  @click="selectSavedAddress(address)">
            <span class="checkout-address-choice__radio" aria-hidden="true"></span>
            <span><strong>@{{ address.name }}</strong><small>@{{ addressSummary(address) }}</small></span>
          </button>
          <button type="button" class="checkout-address-choice__edit" :disabled="saving" @click="editSavedAddress(address)">{{ __('shop/checkout.edit') }}</button>
        </div>
        <button type="button" class="checkout-new-address" :disabled="saving" @click="startNewAddress">＋ {{ __('shop/checkout.add_new_address') }}</button>
      </div>
    </section>
  </template>

  <input type="hidden" v-model="form.shipping_address_id">
  <input type="hidden" v-model="form.payment_address_id">
</div>

@push('add-scripts')
<script>
  const checkoutAccountAddressesUrl = {!! json_encode($checkoutAccountAddressesUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  const checkoutCountryZonesUrlTemplate = {!! json_encode($checkoutCountryZonesUrlTemplate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  const checkoutAddressSuggestionsUrl = {!! json_encode($checkoutAddressSuggestionsUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  const checkoutUserCountryUrl = {!! json_encode($checkoutUserCountryUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  const checkoutAddressMessages = {!! \Illuminate\Support\Js::from([
    'shippingAddress' => __('shop/checkout.shipping_address'),
    'billingAddress' => __('shop/checkout.billing_address'),
    'loadingRegions' => __('shop/checkout.loading_regions'),
    'selectState' => __('shop/checkout.select_state'),
    'regionNotRequired' => __('shop/checkout.region_not_required'),
    'saving' => __('shop/checkout.saving'),
    'useThisAddress' => __('shop/checkout.use_this_address'),
    'saveBillingAddress' => __('shop/checkout.save_billing_address'),
    'completeDetails' => __('shop/checkout.complete_contact_details'),
    'selectRegion' => __('shop/checkout.select_region_error'),
    'invalidEmail' => __('shop/checkout.invalid_email'),
    'unableSave' => __('shop/checkout.unable_save_address'),
    'unableUpdate' => __('shop/checkout.unable_update_checkout'),
    'checkoutUpdated' => __('shop/checkout.checkout_updated'),
    'addressLookupHelp' => __('shop/checkout.address_lookup_help'),
    'addressLookupSearch' => __('shop/checkout.address_lookup_search'),
    'addressLookupSearching' => __('shop/checkout.address_lookup_searching'),
    'addressLookupEmpty' => __('shop/checkout.address_lookup_empty'),
    'addressLookupUnavailable' => __('shop/checkout.address_lookup_unavailable'),
    'addressLookupFilled' => __('shop/checkout.address_lookup_filled'),
    'addressSuggestions' => __('shop/checkout.address_suggestions'),
    'regionsLoadFailed' => __('shop/checkout.regions_load_failed'),
    'countryUnavailable' => __('shop/checkout.country_unavailable'),
    'retry' => __('shop/checkout.retry'),
    'saveChanges' => __('shop/checkout.save_address_changes'),
    'addressAutoApply' => __('shop/checkout.address_auto_apply'),
    'addressApplying' => __('shop/checkout.address_applying'),
  ]) !!};

  var checkoutAddressApp = new Vue({
    el: '#checkout-address-app',
    data: {
      form: { shipping_address_id: {!! \Illuminate\Support\Js::from($current['shipping_address_id'] ?? 0) !!}, payment_address_id: {!! \Illuminate\Support\Js::from($current['payment_address_id'] ?? 0) !!} },
      shippingRequired: {!! \Illuminate\Support\Js::from($shipping_require ?? true) !!},
      phoneRequired: {!! \Illuminate\Support\Js::from(system_setting('base.address_phoner_equired', '0') == '1') !!},
      source: {
        addresses: {!! \Illuminate\Support\Js::from($addresses ?? []) !!},
        countries: {!! \Illuminate\Support\Js::from($countries ?? []) !!},
        zones: [],
        guest_shipping_address: {!! \Illuminate\Support\Js::from($current['guest_shipping_address'] ?? null) !!},
        guest_payment_address: {!! \Illuminate\Support\Js::from($current['guest_payment_address'] ?? null) !!},
        isLogin: config.isLogin,
      },
      addressForm: {},
      editorOpen: false,
      zonesLoading: false,
      zonesLoadFailed: false,
      zoneRequestId: 0,
      addressLookupRequestId: 0,
      addressLookupTimer: null,
      addressLookupAbortController: null,
      addressLookupCache: {},
      addressSuggestions: [],
      activeSuggestion: -1,
      addressLookupMessage: '',
      addressLookupLoading: false,
      addressDirty: false,
      addressCommitted: false,
      addressRevision: 0,
      regionValidationBlocked: false,
      saving: false,
      formError: '',
      messages: checkoutAddressMessages,
    },
    computed: {
      selectedAddressId() {
        return this.shippingRequired ? this.form.shipping_address_id : this.form.payment_address_id;
      },
      readyForSubmit() {
        if (this.source.isLogin) return Boolean(this.selectedAddressId) && !this.editorOpen;
        const committed = this.shippingRequired ? this.source.guest_shipping_address : this.source.guest_payment_address;
        return Boolean(committed) && !this.addressDirty;
      },
    },
    created() {
      const existing = this.source.isLogin
        ? this.source.addresses.find((item) => item.id == (this.shippingRequired ? this.form.shipping_address_id : this.form.payment_address_id))
        : (this.shippingRequired ? this.source.guest_shipping_address : this.source.guest_payment_address);
      this.loadAddress(existing || this.emptyAddress(), true, Boolean(existing));
      this.editorOpen = !this.source.isLogin || !existing;
      if (!existing) this.detectVisitorCountry();
    },
    methods: {
      emptyAddress() {
        return { id: null, name: '', first_name: '', last_name: '', email: {!! \Illuminate\Support\Js::from((string) (current_customer()?->email ?? '')) !!}, calling_code: '', phone: '', country_id: {!! \Illuminate\Support\Js::from((int) $country_id) !!}, zone_id: '', city: '', zipcode: '', address_1: '', address_2: '', default: false };
      },
      addressSummary(address) {
        const location = [address.address_1, address.address_2, address.city, address.zone, address.zipcode, address.country]
          .map((item) => String(item || '').trim()).filter(Boolean).join(', ');
        const phone = [address.calling_code, address.phone].map((item) => String(item || '').trim()).filter(Boolean).join(' ');
        return [location, phone].filter(Boolean).join(' · ');
      },
      loadAddress(address, loadZones = true, committed = true) {
        this.addressForm = Object.assign(this.emptyAddress(), JSON.parse(JSON.stringify(address || {})));
        this.splitFullName();
        this.addressDirty = !committed;
        this.addressCommitted = committed;
        this.regionValidationBlocked = false;
        if (loadZones) {
          this.countryChange(this.addressForm.country_id, false);
        } else {
          this.zoneRequestId += 1;
          this.source.zones = [];
          this.zonesLoading = false;
          this.zonesLoadFailed = false;
        }
      },
      startNewAddress() {
        this.formError = '';
        this.loadAddress(this.emptyAddress(), true, false);
        this.editorOpen = true;
        this.clearAddressSuggestions();
        this.detectVisitorCountry();
        this.syncAddressSubmitState();
      },
      cancelEdit() {
        const selected = this.source.addresses.find((item) => item.id == this.selectedAddressId);
        this.loadAddress(selected || this.emptyAddress(), false, true);
        this.editorOpen = false;
        this.formError = '';
        this.syncAddressSubmitState();
      },
      editSavedAddress(address) {
        this.formError = '';
        this.loadAddress(address, true, false);
        this.editorOpen = true;
        this.clearAddressSuggestions();
        this.syncAddressSubmitState();
      },
      moveAddressSelection(index, direction) {
        if (!this.source.addresses.length) return;
        const nextIndex = (index + direction + this.source.addresses.length) % this.source.addresses.length;
        this.selectSavedAddress(this.source.addresses[nextIndex], () => {
          this.$nextTick(() => document.querySelectorAll('.checkout-address-choice')[nextIndex]?.focus());
        });
      },
      countryChange(countryId, resetZone = false) {
        const requestId = ++this.zoneRequestId;
        const previousZoneId = this.addressForm.zone_id;
        this.zonesLoadFailed = false;
        if (!countryId) { this.source.zones = []; this.addressForm.zone_id = ''; this.zonesLoading = false; return Promise.resolve([]); }
        this.zonesLoading = true;
        const zonesUrl = checkoutCountryZonesUrlTemplate.replace('__COUNTRY_ID__', encodeURIComponent(countryId));
        return $http.get(zonesUrl, null, {hmsg: true, hload: true, timeout: 15000}).then((res) => {
          if (requestId !== this.zoneRequestId) return [];
          const wasRegionValidationBlocked = this.regionValidationBlocked;
          this.regionValidationBlocked = false;
          this.source.zones = Array.isArray(res.data?.zones) ? res.data.zones : [];
          const country = res.data.country || {};
          if (!country.id) {
            this.addressForm.country_id = '';
            this.addressForm.zone_id = '';
            this.addressDirty = true;
            this.editorOpen = true;
            this.formError = this.messages.countryUnavailable;
            this.syncAddressSubmitState();
            return [];
          }
          this.addressForm.calling_code = country.calling_code || '';
          const selectedZoneIsValid = this.source.zones.some((zone) => zone.id == previousZoneId);
          const regionIsValid = !this.source.zones.length || selectedZoneIsValid;
          if (resetZone || !selectedZoneIsValid) this.addressForm.zone_id = '';
          if (!resetZone && this.addressCommitted && !regionIsValid) {
            this.addressDirty = true;
            this.editorOpen = true;
            this.formError = this.messages.selectRegion;
            this.syncAddressSubmitState();
          } else if (wasRegionValidationBlocked && this.addressCommitted && !this.addressDirty) {
            this.editorOpen = false;
            this.formError = '';
            this.syncAddressSubmitState();
          }
          return this.source.zones;
        }).catch(() => {
          if (requestId !== this.zoneRequestId) return [];
          this.source.zones = [];
          this.addressForm.zone_id = '';
          this.zonesLoadFailed = true;
          this.formError = this.messages.unableUpdate;
          if (this.addressCommitted) {
            this.regionValidationBlocked = true;
            this.editorOpen = true;
            this.syncAddressSubmitState();
          }
          return [];
        }).finally(() => {
          if (requestId !== this.zoneRequestId) return;
          this.zonesLoading = false;
          this.$nextTick(() => this.maybeCommitGuestAddress());
        });
      },
      retryZones() {
        this.formError = '';
        return this.countryChange(this.addressForm.country_id, false);
      },
      splitFullName() {
        if (this.addressForm.first_name || this.addressForm.last_name) {
          this.syncFullName();
          return;
        }
        const parts = String(this.addressForm.name || '').trim().split(/\s+/).filter(Boolean);
        this.addressForm.first_name = parts.length > 1 ? parts.slice(0, -1).join(' ') : (parts[0] || '');
        this.addressForm.last_name = parts.length > 1 ? parts[parts.length - 1] : '';
      },
      syncFullName() {
        this.addressForm.name = [this.addressForm.first_name, this.addressForm.last_name]
          .map((item) => String(item || '').trim()).filter(Boolean).join(' ');
      },
      markAddressDirty() {
        this.addressDirty = true;
        this.addressRevision += 1;
        this.formError = '';
        this.syncAddressSubmitState();
      },
      syncAddressSubmitState() {
        if (typeof window.syncCheckoutSubmitState === 'function') window.syncCheckoutSubmitState();
      },
      focusAddressEditor(message = '') {
        this.formError = message || this.messages.saveChanges;
        this.editorOpen = true;
        this.$nextTick(() => {
          this.$refs.addressForm?.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
          const target = this.$refs.addressForm?.querySelector(':invalid') || this.$refs.addressForm?.querySelector('input, select, .address-save-button');
          target?.focus();
        });
      },
      detectVisitorCountry() {
        return $http.get(checkoutUserCountryUrl, null, {hmsg: true, hload: true, timeout: 8000}).then((res) => {
          const code = String(res.data?.countryCode || '').toUpperCase();
          const country = this.source.countries.find((item) => String(item.code || '').toUpperCase() === code);
          if (!country || this.addressForm.id || this.addressForm.address_1) return;
          if (this.addressForm.country_id != country.id) {
            this.addressForm.country_id = country.id;
            this.markAddressDirty();
            return this.countryChange(country.id, true);
          }
          if (!this.addressForm.calling_code && res.data?.callingCode) this.addressForm.calling_code = res.data.callingCode;
        }).catch(() => undefined);
      },
      scheduleAddressLookup() {
        window.clearTimeout(this.addressLookupTimer);
        this.abortAddressLookupRequest();
        const requestId = ++this.addressLookupRequestId;
        this.addressSuggestions = [];
        this.activeSuggestion = -1;
        this.addressLookupLoading = false;
        this.addressLookupMessage = this.messages.addressLookupHelp;
        if (String(this.addressForm.address_1 || '').trim().length < 3) return;

        // A complete US/Canadian address is commonly pasted into this field
        // (for example: "3333 164th St SW; Lynnwood, WA 98087-3241"). Do
        // not make that normal checkout path wait for an optional external
        // geocoder or force the customer to select a suggestion. The parser
        // is deliberately conservative: it only accepts an unambiguous,
        // country-matching city/region/postal suffix and never replaces fields
        // the customer has already entered independently.
        this.applyStructuredAddressInput();

        // Address lookup is an optional assist, never a reason to interrupt
        // typing. Wait for a deliberate pause and retain only the newest
        // request as the customer keeps editing the street field.
        this.addressLookupTimer = window.setTimeout(() => this.lookupAddressSuggestions(requestId), 500);
      },
      onAddressLineInput() {
        this.markAddressDirty();
        this.scheduleAddressLookup();
      },
      structuredAddressParts(value) {
        if (this.addressForm.city || this.addressForm.zipcode || this.addressForm.zone_id) return null;

        const country = this.source.countries.find((item) => item.id == this.addressForm.country_id);
        const countryCode = String(country?.code || '').toUpperCase();
        if (!['US', 'CA'].includes(countryCode)) return null;

        const compact = String(value || '').replace(/\s+/g, ' ').trim();
        // We intentionally require both a city/region separator and a
        // country-specific postal code. This prevents a normal street entry
        // from being guessed at or rewritten while the customer is typing.
        const match = compact.match(/^(.+?)(?:\s*[;,]\s*)([A-Za-z][A-Za-z .'-]{1,80})\s*,\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?|[A-Za-z]\d[A-Za-z][ -]?\d[A-Za-z]\d)$/);
        if (!match) return null;

        const [, street, city, regionCode, postalCode] = match;
        const normalizedPostalCode = String(postalCode).trim().toUpperCase().replace(/\s+/g, ' ');
        const isUsPostalCode = /^\d{5}(?:-\d{4})?$/.test(normalizedPostalCode);
        const isCanadianPostalCode = /^[A-Z]\d[A-Z][ -]?\d[A-Z]\d$/.test(normalizedPostalCode);
        if ((countryCode === 'US' && !isUsPostalCode) || (countryCode === 'CA' && !isCanadianPostalCode)) return null;

        return {
          address_1: String(street).trim(),
          city: String(city).replace(/\s+/g, ' ').trim(),
          zone_code: String(regionCode).trim().toUpperCase(),
          zipcode: normalizedPostalCode,
        };
      },
      applyStructuredAddressInput() {
        const parts = this.structuredAddressParts(this.addressForm.address_1);
        if (!parts) return false;

        const revision = this.addressRevision;
        this.addressForm.address_1 = parts.address_1;
        this.addressForm.city = parts.city;
        this.addressForm.zipcode = parts.zipcode;
        this.addressSuggestions = [];
        this.activeSuggestion = -1;
        this.addressLookupLoading = false;
        this.addressLookupMessage = this.messages.addressLookupFilled;

        const applyZoneAndCommit = () => {
          // Do not overwrite a newer manual edit while the country-zone list
          // is loading in the background.
          if (revision !== this.addressRevision || this.addressForm.address_1 !== parts.address_1) return;
          this.applyLookupZone('', parts.zone_code);
          this.$nextTick(() => this.maybeCommitGuestAddress());
        };

        if (this.source.zones.length) {
          applyZoneAndCommit();
        } else if (this.addressForm.country_id) {
          // The initial country-zone request can still be in flight when a
          // browser autofill or paste occurs. Requesting it here is safe—the
          // latest request wins—and keeps the automatic guest save intact.
          this.countryChange(this.addressForm.country_id, false).then(applyZoneAndCommit);
        }

        return true;
      },
      lookupAddressSuggestions(requestId = null) {
        if (requestId === null) requestId = ++this.addressLookupRequestId;
        if (requestId !== this.addressLookupRequestId) return Promise.resolve([]);
        const query = String(this.addressForm.address_1 || '').trim();
        if (query.length < 3) {
          this.clearAddressSuggestions(false);
          this.addressLookupMessage = this.messages.addressLookupHelp;
          return Promise.resolve([]);
        }

        this.addressLookupLoading = true;
        this.addressLookupMessage = this.messages.addressLookupSearching;

        const params = {
          q: query,
          lang: String(document.documentElement.lang || navigator.language || 'en').replace('_', '-'),
        };
        const selectedCountry = this.source.countries.find((item) => item.id == this.addressForm.country_id);
        const selectedCountryCode = String(selectedCountry?.code || '').toUpperCase();
        if (/^[A-Z]{2}$/.test(selectedCountryCode)) params.country_code = selectedCountryCode;

        const cacheKey = this.addressLookupCacheKey(query, params.lang, selectedCountryCode);
        const cached = this.readAddressLookupCache(cacheKey);
        if (cached !== null) {
          this.applyAddressSuggestions(cached);
          return Promise.resolve(this.addressSuggestions);
        }

        const controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
        this.addressLookupAbortController = controller;
        const requestConfig = { timeout: 6000 };
        if (controller) requestConfig.signal = controller.signal;

        // Use Axios directly so this low-priority helper never invokes the
        // application's blocking layer loading mask. The form stays editable
        // while the request is in flight, including on slow provider links.
        return $http.axiosApi.post(checkoutAddressSuggestionsUrl, params, requestConfig).then((response) => {
          if (requestId !== this.addressLookupRequestId || query !== String(this.addressForm.address_1 || '').trim()) return [];
          const suggestions = Array.isArray(response?.data?.data) ? response.data.data : [];
          this.writeAddressLookupCache(cacheKey, suggestions);
          this.applyAddressSuggestions(suggestions);
          return this.addressSuggestions;
        }).catch((error) => {
          if (requestId !== this.addressLookupRequestId || this.isAddressLookupCanceled(error) || query !== String(this.addressForm.address_1 || '').trim()) return [];
          this.addressSuggestions = [];
          this.activeSuggestion = -1;
          this.addressLookupMessage = this.messages.addressLookupUnavailable;
          return [];
        }).finally(() => {
          if (requestId !== this.addressLookupRequestId) return;
          this.addressLookupLoading = false;
          if (this.addressLookupAbortController === controller) this.addressLookupAbortController = null;
        });
      },
      searchAddressNow() {
        window.clearTimeout(this.addressLookupTimer);
        this.abortAddressLookupRequest();
        const requestId = ++this.addressLookupRequestId;
        this.addressSuggestions = [];
        this.activeSuggestion = -1;

        return this.lookupAddressSuggestions(requestId);
      },
      addressLookupCacheKey(query, language, countryCode) {
        return [String(language || 'en').toLowerCase(), String(countryCode || '').toUpperCase(), String(query || '').trim().toLowerCase()].join('\u0000');
      },
      readAddressLookupCache(cacheKey) {
        const entry = this.addressLookupCache[cacheKey];
        if (!entry || Number(entry.expiresAt || 0) <= Date.now()) {
          if (entry) delete this.addressLookupCache[cacheKey];
          return null;
        }

        return Array.isArray(entry.suggestions) ? entry.suggestions.map((item) => Object.assign({}, item)) : null;
      },
      writeAddressLookupCache(cacheKey, suggestions) {
        const keys = Object.keys(this.addressLookupCache);
        if (keys.length >= 24) {
          keys.sort((left, right) => Number(this.addressLookupCache[left]?.savedAt || 0) - Number(this.addressLookupCache[right]?.savedAt || 0))
            .slice(0, keys.length - 23)
            .forEach((key) => delete this.addressLookupCache[key]);
        }

        const now = Date.now();
        this.addressLookupCache[cacheKey] = {
          savedAt: now,
          // Preserve a short negative cache too, so an empty result does not
          // repeatedly consume the checkout rate limit while the user edits.
          expiresAt: now + (suggestions.length ? 5 * 60 * 1000 : 60 * 1000),
          suggestions: suggestions.map((item) => Object.assign({}, item)),
        };
      },
      applyAddressSuggestions(suggestions) {
        this.addressSuggestions = Array.isArray(suggestions) ? suggestions : [];
        this.activeSuggestion = this.addressSuggestions.length ? 0 : -1;
        this.addressLookupMessage = this.addressSuggestions.length ? this.messages.addressLookupHelp : this.messages.addressLookupEmpty;
      },
      abortAddressLookupRequest() {
        const controller = this.addressLookupAbortController;
        this.addressLookupAbortController = null;
        if (controller && !controller.signal.aborted) controller.abort();
      },
      isAddressLookupCanceled(error) {
        return error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError' || error?.name === 'AbortError';
      },
      suggestionDetail(suggestion) {
        return [suggestion.city, suggestion.zone, suggestion.zipcode, suggestion.country].filter(Boolean).join(', ');
      },
      moveSuggestion(direction) {
        if (!this.addressSuggestions.length) return;
        this.activeSuggestion = (this.activeSuggestion + direction + this.addressSuggestions.length) % this.addressSuggestions.length;
        this.$nextTick(() => document.getElementById(`checkout-address-suggestion-${this.activeSuggestion}`)?.scrollIntoView({block: 'nearest'}));
      },
      selectActiveSuggestion(event) {
        if (!this.addressSuggestions.length || this.activeSuggestion < 0) return;
        event?.preventDefault();
        this.selectAddressSuggestion(this.addressSuggestions[this.activeSuggestion]);
      },
      handleAddressEnter(event) {
        event?.preventDefault();
        if (this.addressSuggestions.length && this.activeSuggestion >= 0) {
          this.selectAddressSuggestion(this.addressSuggestions[this.activeSuggestion]);
          return;
        }

        this.searchAddressNow();
      },
      async selectAddressSuggestion(suggestion) {
        window.clearTimeout(this.addressLookupTimer);
        this.abortAddressLookupRequest();
        const selectionId = ++this.addressLookupRequestId;
        this.addressSuggestions = [];
        this.activeSuggestion = -1;
        this.addressLookupLoading = false;
        this.markAddressDirty();
        const selectedAddress = String(suggestion.address_1 || this.addressForm.address_1 || '');
        this.addressForm.address_1 = selectedAddress;
        if (suggestion.address_2) this.addressForm.address_2 = String(suggestion.address_2);
        this.addressForm.city = String(suggestion.city || this.addressForm.city || '');
        this.addressForm.zipcode = String(suggestion.zipcode || this.addressForm.zipcode || '');

        const countryCode = String(suggestion.country_code || '').toUpperCase();
        const country = this.source.countries.find((item) => String(item.code || '').toUpperCase() === countryCode);
        if (country) {
          this.addressForm.country_id = country.id;
          await this.countryChange(country.id, true);
        } else if (this.addressForm.country_id && !this.source.zones.length) {
          await this.countryChange(this.addressForm.country_id, false);
        }

        if (selectionId !== this.addressLookupRequestId || selectedAddress !== String(this.addressForm.address_1 || '')) return;

        this.applyLookupZone(suggestion.zone, suggestion.zone_code);

        this.addressLookupMessage = this.messages.addressLookupFilled;
        this.$nextTick(() => {
          document.getElementById('checkout-address-city')?.focus();
          this.maybeCommitGuestAddress();
        });
      },
      normalizeLookupText(value) {
        return String(value || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').trim().toLowerCase();
      },
      applyLookupZone(zoneName, zoneCode) {
        const normalizedName = this.normalizeLookupText(zoneName);
        const normalizedCode = this.normalizeLookupText(zoneCode);
        const zoneCodeSuffix = normalizedCode.includes('-') ? normalizedCode.split('-').pop() : normalizedCode;
        const zone = this.source.zones.find((item) => {
          const name = this.normalizeLookupText(item.name);
          const code = this.normalizeLookupText(item.code);
          const comparableName = normalizedName.length > 2 && name.length > 2
            && (name.includes(normalizedName) || normalizedName.includes(name));

          return (normalizedName && name === normalizedName)
            || comparableName
            || (normalizedCode && (code === normalizedCode || code === zoneCodeSuffix));
        });
        if (zone) this.addressForm.zone_id = zone.id;

        return Boolean(zone);
      },
      clearAddressSuggestions(cancelPending = true) {
        window.clearTimeout(this.addressLookupTimer);
        if (cancelPending) {
          this.abortAddressLookupRequest();
          this.addressLookupRequestId += 1;
        }
        this.addressSuggestions = [];
        this.activeSuggestion = -1;
        this.addressLookupLoading = false;
      },
      deferSuggestionClose() {
        window.setTimeout(() => this.clearAddressSuggestions(), 180);
      },
      syncBrowserAutofill() {
        this.markAddressDirty();
        this.$nextTick(() => {
          const countryId = Number(document.getElementById('checkout-address-country')?.value || 0);
          if (countryId && countryId !== Number(this.addressForm.country_id)) {
            this.clearAddressSuggestions();
            this.addressForm.country_id = countryId;
            this.countryChange(countryId, true).then(() => this.scheduleAddressLookup());
            return;
          }

          // Some browser/password-manager autofill implementations dispatch
          // a change event without an input event. Run the same non-blocking
          // parser/lookup path only after Vue has synchronized its model, so
          // a complete address is never left with an empty city, region, or
          // postal code. Keeping a single dirty revision lets a pending zone
          // request apply its matching region instead of being discarded.
          this.scheduleAddressLookup();
        });
      },
      selectSavedAddress(address, callback) {
        if (this.saving) return;
        this.saving = true;
        this.formError = '';
        const payload = this.shippingRequired
          ? { shipping_address_id: address.id, payment_address_id: address.id }
          : { payment_address_id: address.id };
        return this.refreshCheckout(payload, () => {
          this.loadAddress(address, true, true);
          this.editorOpen = false;
          this.syncAddressSubmitState();
          if (typeof callback === 'function') callback();
        });
      },
      validateAddressForm(report = true) {
        this.syncFullName();
        this.formError = '';
        if (this.zonesLoading || this.zonesLoadFailed) {
          this.formError = this.messages.unableUpdate;
          return false;
        }
        const address = this.addressForm;
        if (!address.first_name || !address.last_name || !address.name || !address.country_id || !address.address_1 || !address.city || (this.phoneRequired && !address.phone) || (!this.source.isLogin && !address.email)) {
          this.formError = this.messages.completeDetails;
          if (report) this.$refs.addressForm?.reportValidity();
          return false;
        }
        if (!this.source.isLogin && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address.email)) {
          this.formError = this.messages.invalidEmail;
          if (report) this.$refs.addressForm?.reportValidity();
          return false;
        }
        if (this.source.zones.length && !address.zone_id) {
          this.formError = this.messages.selectRegion;
          if (report) this.$refs.addressForm?.reportValidity();
          return false;
        }

        return true;
      },
      addressIsComplete() {
        const address = this.addressForm;
        if (this.zonesLoading || this.zonesLoadFailed) return false;
        if (!address.first_name || !address.last_name || !address.country_id || !address.address_1 || !address.city) return false;
        if (this.phoneRequired && !address.phone) return false;
        if (!this.source.isLogin && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address.email || '')) return false;
        return !this.source.zones.length || Boolean(address.zone_id);
      },
      maybeCommitGuestAddress() {
        if (this.source.isLogin || this.saving || !this.addressDirty || !this.addressIsComplete()) return;
        this.saveAddress({focusOnError: false});
      },
      saveAddress(options = {}) {
        if (this.saving) return Promise.resolve(false);
        if (!this.validateAddressForm(options.focusOnError !== false)) {
          if (options.focusOnError) {
            this.focusAddressEditor(this.formError);
            if (typeof window.showCheckoutError === 'function') window.showCheckoutError(this.formError);
            layer.msg(this.formError);
          }
          return Promise.resolve(false);
        }

        const address = JSON.parse(JSON.stringify(this.addressForm));
        const revision = this.addressRevision;
        this.saving = true;
        this.syncAddressSubmitState();
        if (!this.source.isLogin) {
          const payload = this.shippingRequired
            ? { guest_shipping_address: address, guest_payment_address: address }
            : { guest_payment_address: address };
          return this.refreshCheckout(payload, (res) => {
            this.source.guest_shipping_address = res.current.guest_shipping_address;
            this.source.guest_payment_address = res.current.guest_payment_address;
            if (revision === this.addressRevision) {
              this.loadAddress(this.shippingRequired ? this.source.guest_shipping_address : this.source.guest_payment_address, true, true);
            }
            this.syncAddressSubmitState();
          }).then((res) => Boolean(res));
        }
        const method = address.id ? 'put' : 'post';
        const url = `${checkoutAccountAddressesUrl}${address.id ? '/' + address.id : ''}`;
        return $http[method](url, address, {hmsg: true, hload: true}).then((res) => {
          const saved = res.data;
          const index = this.source.addresses.findIndex((item) => item.id == saved.id);
          if (index === -1) this.source.addresses.push(saved); else this.$set(this.source.addresses, index, saved);
          this.loadAddress(saved, false, true);
          const payload = this.shippingRequired
            ? { shipping_address_id: saved.id, payment_address_id: saved.id }
            : { payment_address_id: saved.id };
          return this.refreshCheckout(payload, () => { this.editorOpen = false; this.syncAddressSubmitState(); });
        }).catch((error) => {
          this.formError = error?.responseJSON?.message || error?.response?.data?.message || this.messages.unableSave;
          if (typeof window.showCheckoutError === 'function') window.showCheckoutError(this.formError);
          return null;
        }).then((res) => Boolean(res)).finally(() => { this.saving = false; this.syncAddressSubmitState(); });
      },
      refreshCheckout(payload, callback) {
        return enqueueCheckoutUpdate(payload).then((res) => {
          if (!res || res.status === 'fail' || !res.current) {
            throw checkoutFailure(res?.message || this.messages.unableUpdate);
          }
          this.form.shipping_address_id = res.current.shipping_address_id ?? 0;
          this.form.payment_address_id = res.current.payment_address_id ?? 0;
          updateTotal(res.totals);
          updateShippingMethods(res.shipping_methods, res.current.shipping_method_code);
          updatePaymentMethods(res.payment_methods, res.current.payment_method_code);
          if (typeof window.clearCheckoutError === 'function') window.clearCheckoutError();
          if (typeof announceCheckout === 'function') announceCheckout(this.messages.checkoutUpdated);
          if (typeof callback === 'function') callback(res);
          return res;
        }).catch((error) => {
          this.formError = typeof checkoutErrorMessage === 'function'
            ? checkoutErrorMessage(error, this.messages.unableUpdate)
            : (error?.responseJSON?.message || error?.response?.data?.message || this.messages.unableUpdate);
          if (typeof window.showCheckoutError === 'function') window.showCheckoutError(this.formError);
          else if (typeof announceCheckout === 'function') announceCheckout(this.formError);
          layer.msg(this.formError);
          return null;
        }).finally(() => { this.saving = false; this.syncAddressSubmitState(); });
      },
    },
  });
</script>
@endpush
