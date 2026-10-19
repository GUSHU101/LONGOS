@extends('admin::layouts.master')

@section('title', __('admin/common.zone'))

@section('page-title-back', true)

@section('content')
  <div id="tax-classes-app" class="card" v-cloak>
    <div class="card-body h-min-600">
      @hook('admin.zones.index.content.before')
      <div class="bg-light p-4 mb-2">
        <div class="row">
          @hook('admin.zones.index.content.filter.before')
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
            <label class="filter-title">{{ __('admin/zone.country_of_origin') }}</label>
            <select v-model="filter.country_id" class="form-select">
              <option value="">{{ __('common.all') }}</option>
              <option v-for="item in countries" :value="item.id">@{{ item.name }}</option>
            </select>
          </div>
          @hook('admin.zones.index.content.filter.after')
        </div>

        <div class="row">
          <label class="filter-title"></label>
          <div class="col-auto">
            @hook('admin.zones.index.content.filter.btns.before')
            <button type="button" @click="search" class="btn btn-outline-primary btn-sm">{{ __('common.filter') }}</button>
            <button type="button" @click="resetSearch" class="btn btn-outline-secondary btn-sm">{{ __('common.reset') }}</button>
            @hook('admin.zones.index.content.filter.btns.after')
          </div>
        </div>
      </div>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div class="d-flex flex-wrap gap-2">
          @can('zones_create')
            <button type="button" class="btn btn-primary" @click="checkedCreate('add', null)">{{ __('common.add') }}</button>
          @endcan
          @can('zones_update')
            <button type="button" class="btn btn-outline-success" :disabled="!hasSelection || batchBusy" @click="batchAction('enable')">{{ __('admin/zone.batch_enable') }}</button>
            <button type="button" class="btn btn-outline-secondary" :disabled="!hasSelection || batchBusy" @click="batchAction('disable')">{{ __('admin/zone.batch_disable') }}</button>
          @endcan
          @can('zones_delete')
            <button type="button" class="btn btn-outline-danger" :disabled="!hasSelection || batchBusy" @click="batchAction('delete')">{{ __('admin/zone.batch_delete') }}</button>
          @endcan
        </div>
        <div class="d-flex align-items-center gap-2">
          <span v-if="hasSelection" class="text-muted">{{ __('admin/zone.selected_count') }}: @{{ selectedCount }}</span>
          @can('zones_update')
            <button type="button" class="btn btn-outline-primary" :disabled="syncBusy || batchBusy" @click="syncCatalog">
              <span v-if="syncBusy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ __('admin/zone.sync_now') }}
            </button>
          @endcan
        </div>
      </div>
      <div v-if="allSelected && zones.total > currentIds.length" class="alert alert-primary py-2 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
        <span v-if="!allFiltered">{{ __('admin/zone.page_selected') }}</span>
        <strong v-else>{{ __('admin/zone.all_filtered_selected') }}: @{{ zones.total }}</strong>
        <button v-if="!allFiltered" type="button" class="btn btn-sm btn-primary" @click="allFiltered = true">{{ __('admin/zone.select_all_filtered') }} (@{{ zones.total }})</button>
        <button v-else type="button" class="btn btn-sm btn-outline-primary" @click="allFiltered = false">{{ __('admin/zone.select_page_only') }}</button>
      </div>
      <div class="alert alert-light border py-2 mb-3" role="note">{{ __('admin/zone.sync_help') }}</div>
      <div class="table-push" v-if="zones.data.length">
        <table class="table table-hover">
          <thead>
            <tr>
              <th><input type="checkbox" class="form-check-input" v-model="allSelected" aria-label="{{ __('admin/zone.select_page') }}"></th>
              <th>ID</th>
              <th>{{ __('common.name') }}</th>
              <th>{{ __('currency.code') }}</th>
              <th>{{ __('admin/zone.country_of_origin') }}</th>
              <th>{{ __('common.created_at') }}</th>
              <th>{{ __('common.updated_at') }}</th>
              <th>{{ __('common.sort_order') }}</th>
              <th>{{ __('common.status') }}</th>
              @hook('admin.zones.index.content.table.head.after')
              <th class="text-end">{{ __('common.action') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="zone, index in zones.data" :key="index" class="cursor-pointer" @click="checkedCreate('edit', index)">
              <td @click.stop><input type="checkbox" class="form-check-input" :value="zone.id" v-model="selectedIds" :aria-label="zone.name"></td>
              <td>@{{ zone.id }}</td>
              <td>@{{ zone.name }}</td>
              <td>@{{ zone.code }}</td>
              <td>@{{ zone.country ? zone.country.name : '-' }}</td>
              <td>@{{ zone.created_at }}</td>
              <td>@{{ zone.updated_at }}</td>
              <td>@{{ zone.sort_order }}</td>
              <td>
                <span v-if="zone.active" class="text-success">{{ __('common.enable') }}</span>
                <span v-else class="text-secondary">{{ __('common.disable') }}</span>
              </td>
              @hook('admin.zones.index.content.table.body.after')
              <td class="text-end">
                @can('zones_delete')
                  <button class="btn btn-outline-danger btn-sm ml-1" type="button" @click.stop="deleteCustomer(zone.id, index)">{{ __('common.delete') }}</button>
                @endcan
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else><x-admin-no-data /></div>

      <el-pagination v-if="zones.data.length" :pager-count="5" layout="total, prev, pager, next" background :page-size="zones.per_page" :current-page.sync="page"
        :total="zones.total"></el-pagination>
    </div>

    <el-dialog title="{{ __('admin/common.zone') }}" :visible.sync="dialog.show" width="620px"
      @close="closeCustomersDialog('form')" :close-on-click-modal="false">

      <el-form ref="form" :rules="rules" :model="dialog.form" label-width="148px">
        @hook('admin.zones.index.content.dialog.before')

        <el-form-item label="{{ __('admin/zone.zone_name') }}" prop="name">
          <el-input v-model="dialog.form.name" placeholder="{{ __('admin/zone.zone_name') }}"></el-input>
        </el-form-item>

        <el-form-item label="{{ __('currency.code') }}" prop="code">
          <el-input v-model="dialog.form.code" placeholder="{{ __('currency.code') }}"></el-input>
        </el-form-item>

        <el-form-item label="{{ __('admin/zone.country_of_origin') }}" prop="country_id">
          <el-select v-model="dialog.form.country_id" placeholder="{{ __('admin/zone.country_of_origin') }}">
            <el-option
              v-for="item in countries"
              :key="item.id"
              :label="item.name"
              :value="item.id">
            </el-option>
          </el-select>
        </el-form-item>

        <el-form-item label="{{ __('common.sort_order') }}">
          <el-input v-model="dialog.form.sort_order" placeholder="{{ __('common.sort_order') }}"></el-input>
        </el-form-item>


        <el-form-item label="{{ __('common.status') }}">
          <el-switch v-model="dialog.form.active" :disabled="dialog.form.id == defaultZone" :active-value="1" :inactive-value="0"></el-switch>
          <span v-if="dialog.form.id == defaultZone" class="text-muted ms-3">{{ __('admin/zone.zones_default_disabled') }} <a href="{{ admin_route('settings.store_settings') }}" target="_blank" rel="noopener noreferrer">{{ __('admin/country.To_modify') }}</a></span>
        </el-form-item>

        @hook('admin.zones.index.content.dialog.after')

        <el-form-item class="mt-5">
          @hook('admin.zones.index.content.dialog.btns.before')
          <el-button type="primary" @click="addFormSubmit('form')">{{ __('common.save') }}</el-button>
          <el-button @click="closeCustomersDialog('form')">{{ __('common.cancel') }}</el-button>
          @hook('admin.zones.index.content.dialog.btns.after')
        </el-form-item>
      </el-form>
    </el-dialog>
  </div>
@endsection

@push('footer')
  @include('admin::shared.vue-image')

  <script>
    var app = new Vue({
      el: '#tax-classes-app',

      data: {
        countries: @json($countries ?? []),
        zones: @json($zones ?? []),
        selectedIds: [],
        allFiltered: false,
        batchBusy: false,
        syncBusy: false,

        defaultZone: @json(system_setting('base.zone_id')),

        page: 1,

        dialog: {
          show: false,
          index: null,
          type: 'add',
          form: {
            id: null,
            name: '',
            code: '',
            country_id: '',
            sort_order: '',
            active: 1,
          },
        },

        rules: {
          name: [{required: true, message: '{{ __('common.error_required', ['name' => __('admin/zone.zone_name')]) }}', trigger: 'blur'}, ],
          code: [{required: true, message: '{{ __('common.error_required', ['name' => __('currency.code')]) }}', trigger: 'blur'}, ],
          country_id: [{required: true, message: '{{ __('admin/zone.error_country') }}', trigger: 'blur'}, ],
        },

        filter: {
          name: bk.getQueryString('name'),
          code: bk.getQueryString('code'),
          active: bk.getQueryString('active'),
          country_id: bk.getQueryString('country_id'),
        },

        url: '{{ admin_route("zones.index") }}',

        @hook('admin.zones.index.content.vue.data')
      },

      watch: {
        page: function() {
          this.loadData();
        },
        selectedIds(value) {
          if (this.allFiltered && value.length !== this.currentIds.length) this.allFiltered = false;
        },

        @hook('admin.zones.index.content.vue.watch')
      },

      computed: {
        currentIds() {
          return (this.zones.data || []).map(item => item.id)
        },
        hasSelection() {
          return this.allFiltered || this.selectedIds.length > 0
        },
        selectedCount() {
          return this.allFiltered ? this.zones.total : this.selectedIds.length
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

          $http.get(`zones?page=${this.page}`, filter).then((res) => {
            this.zones = res.data.zones;
            this.selectedIds = [];
            this.allFiltered = false;
          })
        },

        checkedCreate(type, index) {
          this.dialog.show = true
          this.dialog.type = type
          this.dialog.index = index

          if (type == 'edit') {
            this.dialog.form = JSON.parse(JSON.stringify(this.zones.data[index]));
          }
        },

        statusChange(e, index) {
          const id = this.zones.data[index].id;

          // $http.put(`languages/${id}`).then((res) => {
          //   layer.msg(res.message);
          // })
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
          const url = this.dialog.type == 'add' ? 'zones' : 'zones/' + this.dialog.form.id;

          this.$refs[form].validate((valid) => {
            if (!valid) {
              this.$message.error('{{ __('common.error_form') }}');
              return;
            }

            $http[type](url, this.dialog.form).then((res) => {
              this.$message.success(res.message);
              if (this.dialog.type == 'add') {
                // this.zones.data.push(res.data)
                this.loadData();
              } else {
                this.zones.data[this.dialog.index] = res.data
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
            $http.delete('zones/' + id).then((res) => {
              this.$message.success(res.message);
              this.loadData();
              // self.country.data.splice(index, 1)
            })
          }).catch(()=>{})
        },

        batchAction(action) {
          if (!this.hasSelection || this.batchBusy) return;
          this.$confirm('{{ __('admin/zone.batch_confirm') }}', '{{ __('common.text_hint') }}', {
            confirmButtonText: '{{ __('common.confirm') }}',
            cancelButtonText: '{{ __('common.cancel') }}',
            type: action === 'delete' ? 'warning' : 'info'
          }).then(() => {
            this.batchBusy = true;
            const payload = this.allFiltered
              ? {action, all_filtered: true, filters: this.filter}
              : {ids: this.selectedIds, action};
            return $http.post(@json(admin_route('zones.batch')), payload).then((res) => {
              this.$message.success(res.message);
              const failures = res.data && Array.isArray(res.data.failures) ? res.data.failures : [];
              if (failures.length) {
                this.$alert(failures.map(item => `${item.name}: ${item.reason}`).join('\n'), '{{ __('admin/zone.skipped_items') }}', {type: 'warning'});
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
          $http.post(@json(admin_route('zones.sync')), {}).then((res) => {
            this.$message.success(res.message);
            this.loadData();
          }).finally(() => { this.syncBusy = false; });
        },

        closeCustomersDialog(form) {
          this.$refs[form].resetFields();
          Object.keys(this.dialog.form).forEach(key => this.dialog.form[key] = '')
          this.dialog.show = false
        },

        @hook('admin.zones.index.content.vue.methods')
      },

      @hook('admin.zones.index.content.vue.options')
    })
  </script>
@endpush
