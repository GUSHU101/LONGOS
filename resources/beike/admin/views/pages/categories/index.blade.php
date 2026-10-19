@extends('admin::layouts.master')

@section('title', __('admin/common.category'))

@section('body-class', 'page-categories')

@section('content')
  @if ($errors->has('error'))
  <x-admin-alert type="danger" msg="{{ $errors->first('error') }}" class="mt-4"/>
  @endif

  @if (session()->has('success'))
  <x-admin-alert type="success" msg="{{ session('success') }}" class="mt-4"/>
  @endif

  <div id="category-app" class="card h-min-600" v-cloak>
    <div class="card-body">
      @hook('admin.categories.index.content.before')

      <div class="d-flex">
        @hook('admin.categories.index.top_buttons.before')
        <a href="{{ admin_route('categories.create') }}" class="btn btn-primary">{{ __('admin/category.categories_create') }}</a>
        @hook('admin.categories.create.after')
        <div class="ms-4 wp-300">
          <el-input
            v-model="filterKeyword"
            clearable
            style="max-width: 360px;"
            placeholder="{{ __('common.input') }}"
            size="small"
            @input="handleFilterInput"
            @clear="clearFilter">
          </el-input>
        </div>
      </div>

      @if (!($smart_assignment_available ?? false))
        <div class="alert alert-warning mt-3 mb-0" role="status">
          {{ __('admin/category.smart_upgrade_pending') }}
        </div>
      @endif


      @hook('admin.categories.list.before')
      <div class="mt-4 categories-wrap" style="" v-if="categories.length">
        <el-tree
          :data="categories"
          :expand-on-click-node="false"
          :filter-node-method="filterNode"
          node-key="id"
          ref="tree"
          @node-click="(node, data) => handleNodeClick(node, data)">
          <div class="custom-tree-node d-flex align-items-center justify-content-between w-100" slot-scope="{ node, data }">
            <div><span>@{{ data.id }} - @{{ data.name }}</span></div>
            <div class="d-flex align-items-center">
              <span class="me-4">{{ __('common.sort_order') }} - @{{ data.position }}</span>
              <span class="me-4 badge bg-success" v-if="data.active">{{ __('common.enabled') }}</span>
              <span class="me-4 badge bg-secondary" v-else>{{ __('common.disabled') }}</span>
              <div>
                @can('categories_update')
                  @can('products_update')
                    @if ($smart_assignment_available ?? false)
                    <button
                      class="btn btn-outline-primary btn-sm me-1"
                      :disabled="!data.active"
                      @click.stop="openSmartProducts(data)">{{ __('admin/category.smart_add_products') }}</button>
                    @endif
                  @endcan
                @endcan
                {{-- <button class="btn btn-outline-secondary btn-sm" @click.stop="toEdit(data.url_edit)">{{ __('common.edit') }}</button> --}}
                <button class="btn btn-outline-danger btn-sm" @click.stop="removeCategory(node, data)">{{ __('common.delete') }}</button>
                @hook('admin.categories.delete.after')
              </div>
            </div>
          </div>
        </el-tree>
      </div>
      <div v-else><x-admin-no-data /></div>

      @hook('admin.categories.index.content.after')
    </div>

    @if ($smart_assignment_available ?? false)
    <el-dialog
      :title="smartDialogTitle"
      :visible.sync="smartDialogVisible"
      width="900px"
      top="4vh"
      :close-on-click-modal="false"
      @closed="resetSmartDialog">
      <p class="text-secondary">{{ __('admin/category.smart_description') }}</p>
      <div class="row g-3 mb-3">
        <div class="col-md-7">
          <label class="form-label">{{ __('admin/category.smart_name_keywords') }}</label>
          <el-input
            v-model="smartKeywords"
            type="textarea"
            :rows="3"
            maxlength="2000"
            show-word-limit
            placeholder="{{ __('admin/category.smart_name_keywords_placeholder') }}">
          </el-input>
        </div>
        <div class="col-md-5">
          <label class="form-label">{{ __('admin/category.smart_brands') }}</label>
          <el-select
            v-model="smartBrandIds"
            multiple
            filterable
            class="w-100"
            placeholder="{{ __('admin/category.smart_brands_placeholder') }}">
            <el-option v-for="brand in brands" :key="brand.id" :label="brand.name" :value="brand.id"></el-option>
          </el-select>
        </div>
        <div class="col-12 d-flex align-items-center justify-content-between">
          <div>
            <el-switch v-model="smartAutoEnabled"></el-switch>
            <span class="ms-2">{{ __('admin/category.smart_auto_enabled') }}</span>
            <div class="small text-secondary mt-1">{{ __('admin/category.smart_auto_help') }}</div>
          </div>
          <el-button type="primary" plain :loading="smartLoading" @click="loadSmartProducts">
            {{ __('admin/category.smart_rescan') }}
          </el-button>
        </div>
      </div>

      <div v-loading="smartLoading">
        <div class="alert alert-light border" v-if="smartMeta.scanned !== null">
          <div>@{{ smartSummary }}</div>
          <div class="text-warning mt-1" v-if="smartMeta.truncated">{{ __('admin/category.smart_truncated') }}</div>
        </div>

        <div class="d-flex gap-2 mb-2" v-if="smartCandidates.length">
          <el-button size="mini" @click="selectAllSmartProducts">{{ __('admin/category.smart_select_all') }}</el-button>
          <el-button size="mini" @click="smartSelectedIds = []">{{ __('admin/category.smart_clear') }}</el-button>
        </div>

        <div class="table-responsive" style="max-height: 440px; overflow-y: auto;" v-if="smartCandidates.length">
          <table class="table table-hover align-middle mb-0">
            <thead class="bg-white" style="position: sticky; top: 0; z-index: 1;">
              <tr>
                <th style="width: 44px;"><input type="checkbox" class="form-check-input" v-model="smartAllSelected"></th>
                <th>{{ __('admin/category.smart_product') }}</th>
                <th style="width: 100px;">{{ __('admin/category.smart_score') }}</th>
                <th>{{ __('admin/category.smart_match_reason') }}</th>
                <th style="width: 90px;">{{ __('admin/category.smart_status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="candidate in smartCandidates" :key="candidate.id">
                <td><input type="checkbox" class="form-check-input" :value="candidate.id" v-model="smartSelectedIds"></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img :src="candidate.image" :alt="candidate.name" class="rounded border" style="width: 44px; height: 44px; object-fit: contain;">
                    <div><div>@{{ candidate.name }}</div><small class="text-secondary">ID: @{{ candidate.id }}</small></div>
                  </div>
                </td>
                <td><span class="badge bg-primary">@{{ candidate.score }}</span></td>
                <td><span class="text-secondary">@{{ candidate.matched_keywords.join(', ') }}</span></td>
                <td>
                  <span class="badge bg-success" v-if="candidate.active">{{ __('common.enabled') }}</span>
                  <span class="badge bg-secondary" v-else>{{ __('common.disabled') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="text-center text-secondary py-5" v-else-if="!smartLoading">
          {{ __('admin/category.smart_empty') }}
        </div>
      </div>

      <span slot="footer" class="dialog-footer">
        <el-button @click="smartDialogVisible = false">{{ __('common.cancel') }}</el-button>
        <el-button :loading="smartSavingRule" @click="saveSmartRule">{{ __('admin/category.smart_save_rule') }}</el-button>
        <el-button
          type="primary"
          :loading="smartApplying"
          :disabled="!smartSelectedIds.length"
          @click="applySmartProducts">{{ __('admin/category.smart_apply') }} (@{{ smartSelectedIds.length }})</el-button>
      </span>
    </el-dialog>
    @endif
  </div>
@endsection

@push('footer')
  <script>
    @hook('admin.categories.index.script.before')

    let category_app = new Vue({
      el: '#category-app',
      data: {
        categories: @json($categories),
        brands: @json($brands ?? []),
        filterKeyword: '',
        filterTimer: null,
        visibleNodeMap: {},
        expandedParentIds: [],
        smartDialogVisible: false,
        smartCategory: {id: null, name: ''},
        smartKeywords: '',
        smartBrandIds: [],
        smartAutoEnabled: false,
        smartCandidates: [],
        smartSelectedIds: [],
        smartLoading: false,
        smartApplying: false,
        smartSavingRule: false,
        smartMeta: {scanned: null, eligible: 0, total_matches: 0, returned: 0, truncated: false},
        smartFeatureAvailable: @json((bool) ($smart_assignment_available ?? false)),
        smartUrlTemplate: @json($smart_products_url_template ?? ''),
        smartRuleUrlTemplate: @json($smart_rule_url_template ?? ''),
        smartTitleTemplate: @json(__('admin/category.smart_title', ['category' => '__CATEGORY__'])),
        smartSummaryTemplate: @json(__('admin/category.smart_summary', [
          'scanned' => '__SCANNED__',
          'eligible' => '__ELIGIBLE__',
          'matches' => '__MATCHES__',
          'returned' => '__RETURNED__',
        ])),
        defaultProps: {
          children: 'children',
          label: 'name'
        },
        @hook('admin.categories.index.vue.data')
      },

      computed: {
        smartDialogTitle() {
          return this.smartTitleTemplate.replace('__CATEGORY__', this.smartCategory.name || '')
        },

        smartSummary() {
          return this.smartSummaryTemplate
            .replace('__SCANNED__', this.smartMeta.scanned ?? 0)
            .replace('__ELIGIBLE__', this.smartMeta.eligible ?? 0)
            .replace('__MATCHES__', this.smartMeta.total_matches ?? 0)
            .replace('__RETURNED__', this.smartMeta.returned ?? 0)
        },

        smartAllSelected: {
          get() {
            return this.smartCandidates.length > 0 && this.smartSelectedIds.length === this.smartCandidates.length
          },
          set(selected) {
            this.smartSelectedIds = selected ? this.smartCandidates.map(item => item.id) : []
          }
        },

        @hook('admin.categories.index.vue.computed')
      },

      methods: {
        handleNodeClick(node, data) {
          window.location.href = data.data.url_edit
        },

        handleFilterInput() {
          clearTimeout(this.filterTimer);
          this.filterTimer = setTimeout(() => {
            this.applyTreeFilter();
          }, 300);
        },

        clearFilter() {
          this.filterKeyword = '';
          this.applyTreeFilter();
        },

        applyTreeFilter() {
          if (!this.$refs.tree) return;

          const keyword = (this.filterKeyword || '').toString().trim().toLowerCase();
          if (!keyword) {
            this.visibleNodeMap = {};
            this.expandedParentIds = [];
            this.$refs.tree.filter('');

            return;
          }

          const visibleNodeMap = {};
          const expandedParentMap = {};

          const walk = (nodes, parentIds = []) => {
            (nodes || []).forEach((item) => {
              const idValue = item.id === undefined || item.id === null ? '' : item.id;
              const nameValue = item.name === undefined || item.name === null ? '' : item.name;
              const idStr = idValue.toString().toLowerCase();
              const nameStr = nameValue.toString().toLowerCase();
              const matched = idStr.includes(keyword) || nameStr.includes(keyword);

              if (matched) {
                visibleNodeMap[item.id] = true;
                parentIds.forEach((parentId) => {
                  visibleNodeMap[parentId] = true;
                  expandedParentMap[parentId] = true;
                });
              }

              walk(item.children || [], parentIds.concat(item.id));
            });
          };

          walk(this.categories);
          this.visibleNodeMap = visibleNodeMap;
          this.expandedParentIds = Object.keys(expandedParentMap).map((id) => {
            const parsedId = Number(id);
            return Number.isNaN(parsedId) ? id : parsedId;
          });
          this.$refs.tree.filter(keyword);

          this.$nextTick(() => {
            this.expandedParentIds.forEach((id) => {
              const treeNode = this.$refs.tree.getNode(id);
              if (treeNode) {
                treeNode.expanded = true;
              }
            });
          });
        },

        filterNode(value, data) {
          if (!value) return true;

          return !!this.visibleNodeMap[data.id];
        },

        smartUrl() {
          return this.smartUrlTemplate.replace('__CATEGORY__', this.smartCategory.id)
        },

        smartRuleUrl() {
          return this.smartRuleUrlTemplate.replace('__CATEGORY__', this.smartCategory.id)
        },

        smartRulePayload() {
          return {
            keywords: this.smartKeywords || '',
            brand_ids: this.smartBrandIds,
            enabled: this.smartAutoEnabled,
          }
        },

        openSmartProducts(category) {
          if (!this.smartFeatureAvailable) return
          if (!category.active) {
            this.$message.warning('{{ __('admin/category.smart_category_disabled') }}')
            return
          }

          this.smartCategory = category
          const savedBrandIds = Array.isArray(category.auto_assign_brand_ids) ? category.auto_assign_brand_ids : []
          const hasSavedRule = !!category.auto_assign_keywords || savedBrandIds.length > 0 || !!category.auto_assign_enabled
          this.smartKeywords = hasSavedRule ? (category.auto_assign_keywords || '') : category.name
          this.smartBrandIds = savedBrandIds.slice()
          this.smartAutoEnabled = !!category.auto_assign_enabled
          this.smartDialogVisible = true
          this.$nextTick(() => this.loadSmartProducts())
        },

        loadSmartProducts() {
          if (!this.smartCategory.id || this.smartLoading) return

          this.smartLoading = true
          this.smartCandidates = []
          this.smartSelectedIds = []
          $http.get(this.smartUrl(), {
            keywords: this.smartKeywords || '',
            brand_ids: this.smartBrandIds,
            limit: 200,
          }, {hload: true}).then((res) => {
            const data = res.data || {}
            this.smartCandidates = data.candidates || []
            this.smartSelectedIds = this.smartCandidates.map(item => item.id)
            this.smartMeta = {
              scanned: data.scanned ?? 0,
              eligible: data.eligible ?? 0,
              total_matches: data.total_matches ?? 0,
              returned: data.returned ?? 0,
              truncated: !!data.truncated,
            }
          }).finally(() => {
            this.smartLoading = false
          })
        },

        selectAllSmartProducts() {
          this.smartSelectedIds = this.smartCandidates.map(item => item.id)
        },

        saveSmartRule() {
          if (!this.smartCategory.id || this.smartSavingRule) return

          this.smartSavingRule = true
          $http.put(this.smartRuleUrl(), this.smartRulePayload()).then((res) => {
            this.smartCategory.auto_assign_keywords = (res.data.keywords || []).join(', ')
            this.smartCategory.auto_assign_brand_ids = res.data.brand_ids || []
            this.smartCategory.auto_assign_enabled = !!res.data.enabled
            this.$message.success(res.message)
          }).finally(() => {
            this.smartSavingRule = false
          })
        },

        applySmartProducts() {
          if (!this.smartSelectedIds.length) {
            this.$message.warning('{{ __('admin/category.smart_select_required') }}')
            return
          }

          this.smartApplying = true
          $http.post(this.smartUrl(), {
            product_ids: this.smartSelectedIds,
            keywords: this.smartKeywords || '',
            brand_ids: this.smartBrandIds,
            auto_enabled: this.smartAutoEnabled,
          }).then((res) => {
            this.smartCategory.auto_assign_keywords = this.smartKeywords || ''
            this.smartCategory.auto_assign_brand_ids = this.smartBrandIds.slice()
            this.smartCategory.auto_assign_enabled = this.smartAutoEnabled
            this.$message.success(res.message)
            this.smartDialogVisible = false
          }).finally(() => {
            this.smartApplying = false
          })
        },

        resetSmartDialog() {
          if (this.smartApplying) return

          this.smartCategory = {id: null, name: ''}
          this.smartKeywords = ''
          this.smartBrandIds = []
          this.smartAutoEnabled = false
          this.smartCandidates = []
          this.smartSelectedIds = []
          this.smartMeta = {scanned: null, eligible: 0, total_matches: 0, returned: 0, truncated: false}
        },

        removeCategory(node, data) {
          this.$confirm('{{ __('common.confirm_delete') }}', '{{ __('common.text_hint') }}', {
            confirmButtonText: '{{ __('common.confirm') }}',
            cancelButtonText: '{{ __('common.cancel') }}',
            type: 'warning'
          }).then(() => {
            $http.delete(`/categories/${data.id}`).then((res) => {
              layer.msg(res.message);
              this.$refs.tree.remove(data.id)
            })
          }).catch(()=>{})
        },

        toEdit(url) {
          window.location.href = url
        },

        @hook('admin.categories.index.vue.methods')
      },

      @hook('admin.categories.index.vue.options')
    });

    @hook('admin.categories.index.script.after')
  </script>
@endpush
