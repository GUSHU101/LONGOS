@extends('admin::layouts.master')

@section('title', __('admin/common.country'))

@section('page-title-back', true)

@section('content')
  <div id="tax-classes-app" class="card" v-cloak>
    <div class="card-body h-min-600">
      @hook('admin.country.index.content.before')

      <div class="bg-light p-4 mb-2">
        <div class="row">
          @hook('admin.country.index.filter.before')
          <div class="col-xxl-20 col-xl-3 col-lg-4 col-md-4 d-flex align-items-center mb-3">
            <label class="filter-title">{{ __('product.name') }}</label>
            <input @keyup.enter="search" type="text" v-model="filter.name" class="form-control" placeholder="{{ __('product.name') }}">
          </div>

          <div class="col-xxl-20 col-xl-3 col-lg-4 col-md-4 d-flex align-items-center mb-3">
            <label class="filter-title">{{ __('currency.code') }}</label>
            <input @keyup.enter="search" type="text" v-model="filter.code" class="form-control" placeholder="{{ __('currency.code') }}">
          </div>

          <div class="col-xxl-20 col-xl-3 col-lg-4 col-md-4 d-flex align-items-center mb-3">
            <label class="filter-title">{{ __('common.status') }}</label>
            <select v-model="filter.active" class="form-select">
              <option value="">{{ __('common.all') }}</option>
              <option value="1">{{ __('common.enable') }}</option>
              <option value="0">{{ __('common.disable') }}</option>
            </select>
          </div>

          <div class="col-xxl-20 col-xl-3 col-lg-4 col-md-4 d-flex align-items-center mb-3">
            <label class="filter-title">{{ __('common.continent') }}</label>
            <select v-model="filter.continent" class="form-select">
              <option value="">{{ __('common.all') }}</option>
              @foreach ($continents as $continent)
                <option value="{{ $continent['code'] }}">{{ $continent['label'] }}</option>
              @endforeach
            </select>
          </div>

          @hook('admin.country.index.filter.after')
        </div>

        <div class="row">
          <label class="filter-title"></label>
          <div class="col-auto">
            @hook('admin.country.index.filter_buttons.before')
            <button type="button" @click="search" class="btn btn-outline-primary btn-sm">{{ __('common.filter') }}</button>
            <button type="button" @click="resetSearch" class="btn btn-outline-secondary btn-sm">{{ __('common.reset') }}</button>
            @hook('admin.country.index.filter_buttons.after')
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div class="d-flex flex-wrap gap-2">
          @hook('admin.country.index.top_buttons.before')
          @can('countries_create')
            <button type="button" class="btn btn-primary" @click="checkedCreate('add', null)">{{ __('common.add') }}</button>
          @endcan
          @can('countries_update')
            <button type="button" class="btn btn-outline-success" :disabled="!hasSelection || batchBusy" @click="batchAction('enable')">{{ __('admin/country.batch_enable') }}</button>
            <button type="button" class="btn btn-outline-secondary" :disabled="!hasSelection || batchBusy" @click="batchAction('disable')">{{ __('admin/country.batch_disable') }}</button>
          @endcan
          @can('countries_delete')
            <button type="button" class="btn btn-outline-danger" :disabled="!hasSelection || batchBusy" @click="batchAction('delete')">{{ __('admin/country.batch_delete') }}</button>
          @endcan
          @hook('admin.country.index.top_buttons.after')
        </div>
        <div class="d-flex align-items-center gap-2">
          <span v-if="hasSelection" class="text-muted">{{ __('admin/country.selected_count') }}: @{{ selectedCount }}</span>
          @can('countries_update')
            <button type="button" class="btn btn-outline-primary" :disabled="syncBusy || batchBusy" @click="syncCatalog">
              <span v-if="syncBusy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('admin/country.sync_now') }}
            </button>
          @endcan
        </div>
      </div>
      <div v-if="allSelected && country.total > currentIds.length" class="alert alert-primary py-2 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
        <span v-if="!allFiltered">{{ __('admin/country.page_selected') }}</span>
        <strong v-else>{{ __('admin/country.all_filtered_selected') }}: @{{ country.total }}</strong>
        <button v-if="!allFiltered" type="button" class="btn btn-sm btn-primary" @click="allFiltered = true">{{ __('admin/country.select_all_filtered') }} (@{{ country.total }})</button>
        <button v-else type="button" class="btn btn-sm btn-outline-primary" @click="allFiltered = false">{{ __('admin/country.select_page_only') }}</button>
      </div>
      <div class="alert alert-light border py-2 mb-3" role="note">{{ __('admin/country.sync_help') }}</div>
      <div class="table-push" v-if="country.data.length">
        <table class="table table-hover">
          <thead>
            <tr>
              <th><input type="checkbox" class="form-check-input" v-model="allSelected" aria-label="{{ __('admin/country.select_page') }}"></th>
              <th>ID</th>
              <th>{{ __('common.name') }}</th>
              <th>{{ __('currency.code') }}</th>
              <th>{{ __('common.continent') }}</th>
              <th>{{ __('common.created_at') }}</th>
              <th>{{ __('common.updated_at') }}</th>
              <th>{{ __('common.sort_order') }}</th>
              <th>{{ __('common.status') }}</th>
              @hook('admin.country.index.table.headers')
              <th class="text-end">{{ __('common.action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="country, index in country.data" :key="index" class="cursor-pointer" @click="checkedCreate('edit', index)">
              <td @click.stop><input type="checkbox" class="form-check-input" :value="country.id" v-model="selectedIds" :aria-label="country.name"></td>
              <td>@{{ country.id }}</td>
              <td>@{{ country.name }}</td>
              <td>@{{ country.code }}</td>
              <td>@{{ country.continent_format }}</td>
              <td>@{{ country.created_at }}</td>
              <td>@{{ country.updated_at }}</td>
              <td>@{{ country.sort_order }}</td>
              <td>
                <span v-if="country.active" class="text-success">{{ __('common.enable') }}</span>
                <span v-else class="text-secondary">{{ __('common.disable') }}</span>
              </td>
              @hook('admin.country.index.table.body')
              <td class="text-end">
                @hook('admin.country.index.table.body.actions.before')
                @can('countries_delete')
                  <button class="btn btn-outline-danger btn-sm ml-1" type="button" @click.stop="deleteCustomer(country.id, index)">{{ __('common.delete') }}</button>
                @endcan
                @hook('admin.country.index.table.body.actions.after')
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else><x-admin-no-data /></div>

      <el-pagination v-if="country.data.length" layout="total, prev, pager, next" background :page-size="country.per_page" :current-page.sync="page"
        :total="country.total"></el-pagination>

      @hook('admin.country.index.content.after')
    </div>

    <el-dialog title="{{ __('admin/common.country') }}" :visible.sync="dialog.show" width="600px"
      @close="closeCustomersDialog('form')" :close-on-click-modal="false">

      <el-form ref="form" :rules="rules" :model="dialog.form" label-width="130px">
        @hook('admin.country.index.dialog.before')
        <el-form-item label="{{ __('admin/country.country_name') }}" prop="name">
          <el-input v-model="dialog.form.name" placeholder="{{ __('admin/country.country_name') }}"></el-input>
        </el-form-item>

        <el-form-item label="{{ __('common.continent') }}" prop="continent">
          <el-select v-model="dialog.form.continent" placeholder="">
            @foreach ($continents as $continent)
              <el-option label="{{ $continent['label'] }}" value="{{ $continent['code'] }}"></el-option>
            @endforeach
          </el-select>
        </el-form-item>

        <el-form-item label="{{ __('common.sort_order') }}">
          <el-input v-model="dialog.form.sort_order" placeholder="{{ __('common.sort_order') }}"></el-input>
        </el-form-item>

        <el-form-item label="{{ __('currency.code') }}" prop="code">
          <el-input v-model="dialog.form.code" placeholder="{{ __('currency.code') }}"></el-input>
        </el-form-item>

        <el-form-item label="{{ __('common.status') }}">
          <el-switch v-model="dialog.form.active" :disabled="dialog.form.id == defaultCountry" :active-value="1" :inactive-value="0"></el-switch>
          <span v-if="dialog.form.id == defaultCountry" class="text-muted ms-3">{{ __('admin/country.countries_default_disabled') }} <a href="{{ admin_route('settings.store_settings') }}" target="_blank" rel="noopener noreferrer">{{ __('admin/country.To_modify') }}</a></span>
        </el-form-item>

        @hook('admin.country.index.dialog.after')

        <el-form-item class="mt-5">
          @hook('admin.country.index.dialog.submit.before')
          <el-button type="primary" @click="addFormSubmit('form')">{{ __('common.save') }}</el-button>
          <el-button @click="closeCustomersDialog('form')">{{ __('common.cancel') }}</el-button>
          @hook('admin.country.index.dialog.submit.after')
        </el-form-item>
      </el-form>
    </el-dialog>
  </div>
@endsection

@push('footer')
  @include('admin::shared.vue-image')

  <script>
    @hook('admin.country.index.script.before')

    var app = new Vue({
      el: '#tax-classes-app',

      data: {
        country: @json($countries ?? []),
        page: 1,
        selectedIds: [],
        allFiltered: false,
        batchBusy: false,
        syncBusy: false,

        defaultCountry: @json(system_setting('base.country_id')),

        dialog: {
          show: false,
          index: null,
          type: 'add',
          form: {
            id: null,
            name: '',
            continent: '',
            code: '',
            sort_order: '',
            active: 1,
          },
        },

        filter: {
          name: bk.getQueryString('name'),
          code: bk.getQueryString('code'),
          active: bk.getQueryString('active'),
          continent: bk.getQueryString('continent'),
        },

        url: '{{ admin_route("countries.index") }}',

        rules: {
          name: [{required: true,message: '{{ __('common.error_required', ['name' => __('admin/country.country_name')]) }}',trigger: 'blur'}, ],
          code: [{required: true, message: '{{ __('common.error_required', ['name' => __('currency.code')]) }}', trigger: 'blur'}, ],
        },

        @hook('admin.country.index.vue.data')
      },

      watch: {
        page: function() {
          this.loadData();
        },
        selectedIds(value) {
          if (this.allFiltered && value.length !== this.currentIds.length) this.allFiltered = false;
        },

        @hook('admin.country.index.vue.watch')
      },

      computed: {
        currentIds() {
          return (this.country.data || []).map(item => item.id)
        },
        hasSelection() {
          return this.allFiltered || this.selectedIds.length > 0
        },
        selectedCount() {
          return this.allFiltered ? this.country.total : this.selectedIds.length
        },
        allSelected: {
          get() {
            return this.currentIds.length > 0 && this.currentIds.every(id => this.selectedIds.includes(id))
          },
          set(checked) {
            this.selectedIds = checked ? [...this.currentIds] : []
          }
        }
      },

      methods: {
        loadData() {
          let filter = {}
          Object.keys(this.filter).forEach(key => {
            if (this.filter[key]) {
              filter[key] = this.filter[key]
            }
          })

          $http.get(`countries?page=${this.page}`, filter).then((res) => {
            this.country = res.data.countries;
            this.selectedIds = [];
            this.allFiltered = false;
          })
        },

        checkedCreate(type, index) {
          this.dialog.show = true
          this.dialog.type = type
          this.dialog.index = index

          if (type == 'edit') {
            this.dialog.form = JSON.parse(JSON.stringify(this.country.data[index]));
          }
        },

        statusChange(e, index) {
          const id = this.country.data[index].id;
        },

        search() {
          location = bk.objectToUrlParams(this.filter, this.url)
        },

        resetSearch() {
          this.filter = bk.clearObjectValue(this.filter)
          location = bk.objectToUrlParams(this.filter, this.url)
        },

        addFormSubmit(form) {
          const self = this;
          const type = this.dialog.type == 'add' ? 'post' : 'put';
          const url = this.dialog.type == 'add' ? 'countries' : 'countries/' + this.dialog.form.id;

          this.$refs[form].validate((valid) => {
            if (!valid) {
              this.$message.error('{{ __('common.error_form') }}');
              return;
            }

            $http[type](url, this.dialog.form).then((res) => {
              this.$message.success(res.message);
              if (this.dialog.type == 'add') {
                this.loadData();
              } else {
                this.country.data[this.dialog.index] = res.data
              }

              this.dialog.show = false
            })
          });
        },

        deleteCustomer(id, index) {
          this.$confirm('{{ __('common.confirm_delete') }}', '{{ __('common.text_hint') }}', {
            confirmButtonText: '{{ __('common.confirm') }}',
            cancelButtonText: '{{ __('common.cancel') }}',
            type: 'warning'
          }).then(() => {
            $http.delete('countries/' + id).then((res) => {
              this.$message.success(res.message);
              this.loadData();
            })
          }).catch(()=>{})
        },

        batchAction(action) {
          if (!this.hasSelection || this.batchBusy) return;
          this.$confirm('{{ __('admin/country.batch_confirm') }}', '{{ __('common.text_hint') }}', {
            confirmButtonText: '{{ __('common.confirm') }}',
            cancelButtonText: '{{ __('common.cancel') }}',
            type: action === 'delete' ? 'warning' : 'info'
          }).then(() => {
            this.batchBusy = true;
            const payload = this.allFiltered
              ? {action, all_filtered: true, filters: this.filter}
              : {ids: this.selectedIds, action};
            return $http.post(@json(admin_route('countries.batch')), payload).then((res) => {
              this.$message.success(res.message);
              const failures = res.data && Array.isArray(res.data.failures) ? res.data.failures : [];
              if (failures.length) {
                this.$alert(failures.map(item => `${item.name}: ${item.reason}`).join('\n'), '{{ __('admin/country.skipped_items') }}', {type: 'warning'});
              }
              this.selectedIds = [];
              this.allFiltered = false;
              this.loadData();
            }).finally(() => { this.batchBusy = false; });
          }).catch(() => {});
        },

        syncCatalog() {
          if (this.syncBusy || this.batchBusy) return;
          this.syncBusy = true;
          $http.post(@json(admin_route('countries.sync')), {}).then((res) => {
            this.$message.success(res.message);
            this.loadData();
          }).finally(() => { this.syncBusy = false; });
        },

        closeCustomersDialog(form) {
          this.$refs[form].resetFields();
          Object.keys(this.dialog.form).forEach(key => this.dialog.form[key] = '')
          this.dialog.show = false
        },

        @hook('admin.country.index.vue.methods')
      },

      @hook('admin.country.index.vue.options')
    })

    @hook('admin.country.index.script.after')
  </script>
@endpush
