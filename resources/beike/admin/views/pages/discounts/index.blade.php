@extends('admin::layouts.master')

@section('title', __('admin/common.discounts_index'))

@section('content')
  <div id="discount-app" class="card h-min-600" v-cloak>
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
        <div>
          <h5 class="mb-1">{{ __('admin/discount.title') }}</h5>
          <div class="text-muted small">{{ __('admin/discount.description') }}</div>
        </div>
        <button type="button" class="btn btn-primary" @click="openCreate">{{ __('admin/discount.add') }}</button>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
          <tr>
            <th>ID</th>
            <th>{{ __('admin/discount.name') }}</th>
            <th>{{ __('admin/discount.mode') }}</th>
            <th>{{ __('admin/discount.type') }}</th>
            <th>{{ __('admin/discount.rule') }}</th>
            <th>{{ __('admin/discount.status') }}</th>
            <th>{{ __('admin/discount.period') }}</th>
            <th class="text-end">{{ __('common.action') }}</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="(discount, index) in discounts" :key="discount.id">
            <td>@{{ discount.id }}</td>
            <td><strong>@{{ discount.name }}</strong><div class="small text-muted" v-if="discount.code">{{ __('admin/discount.code') }}：@{{ discount.code }}</div></td>
            <td><span :class="discount.mode === 'automatic' ? 'badge bg-primary' : 'badge bg-dark'">@{{ modeLabel(discount.mode) }}</span></td>
            <td>@{{ typeLabel(discount.type) }}</td>
            <td>@{{ ruleSummary(discount) }}</td>
            <td><span :class="discount.status ? 'text-success' : 'text-muted'">@{{ discount.status ? labels.enabled : labels.disabled }}</span></td>
            <td class="small text-muted">@{{ dateRange(discount) }}</td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-outline-primary btn-sm" @click="openEdit(discount, index)">{{ __('common.edit') }}</button>
              <button type="button" class="btn btn-outline-danger btn-sm ms-1" @click="remove(discount, index)">{{ __('common.delete') }}</button>
            </td>
          </tr>
          <tr v-if="!discounts.length"><td colspan="8" class="text-center py-5 text-muted">{{ __('admin/discount.empty') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <el-dialog :title="dialog.form.id ? labels.edit : labels.add" :visible.sync="dialog.show" width="760px" :close-on-click-modal="false" @close="resetForm">
      <el-form ref="discountForm" :model="dialog.form" :rules="rules" label-width="132px">
        <el-form-item label="{{ __('admin/discount.campaign_name') }}" prop="name"><el-input v-model="dialog.form.name" placeholder="{{ __('admin/discount.name_placeholder') }}"></el-input></el-form-item>
        <el-form-item label="{{ __('admin/discount.mode') }}" prop="mode">
          <el-radio-group v-model="dialog.form.mode"><el-radio label="automatic">{{ __('admin/discount.automatic') }}</el-radio><el-radio label="code">{{ __('admin/discount.discount_code') }}</el-radio></el-radio-group>
        </el-form-item>
        <el-form-item label="{{ __('admin/discount.discount_code') }}" prop="code" v-if="dialog.form.mode === 'code'"><el-input v-model="dialog.form.code" placeholder="WELCOME10" @input="dialog.form.code = dialog.form.code.toUpperCase()"></el-input></el-form-item>
        <el-form-item label="{{ __('admin/discount.type') }}" prop="type">
          <el-select v-model="dialog.form.type" class="w-100">
            <el-option label="{{ __('admin/discount.product_amount') }}" value="product_amount"></el-option>
            <el-option label="{{ __('admin/discount.buy_x_get_y') }}" value="buy_x_get_y"></el-option>
            <el-option label="{{ __('admin/discount.order_amount') }}" value="order_amount"></el-option>
            <el-option label="{{ __('admin/discount.free_shipping') }}" value="free_shipping"></el-option>
          </el-select>
          <div class="form-text">@{{ typeDescription(dialog.form.type) }}</div>
        </el-form-item>
        <template v-if="isValueDiscount">
          <el-form-item label="{{ __('admin/discount.value') }}" prop="value">
            <div class="d-flex gap-2"><el-select v-model="dialog.form.value_type" style="width: 150px"><el-option label="{{ __('admin/discount.percentage') }}" value="percentage"></el-option><el-option label="{{ __('admin/discount.fixed') }}" value="fixed"></el-option></el-select><el-input v-model.number="dialog.form.value" type="number" min="0"><template slot="append">@{{ dialog.form.value_type === 'percentage' ? '%' : 'USD' }}</template></el-input></div>
          </el-form-item>
        </template>
        <template v-if="dialog.form.type === 'buy_x_get_y'">
          <el-form-item label="{{ __('admin/discount.buy_get') }}"><div class="d-flex gap-2"><el-input v-model.number="dialog.form.buy_quantity" type="number" min="1"><template slot="prepend">{{ __('admin/discount.buy') }}</template></el-input><el-input v-model.number="dialog.form.get_quantity" type="number" min="1"><template slot="prepend">{{ __('admin/discount.get') }}</template></el-input></div></el-form-item>
          <el-form-item label="{{ __('admin/discount.gift_product_ids') }}"><el-input v-model="dialog.form.gift_product_ids" placeholder="{{ __('admin/discount.gift_product_ids_help') }}"></el-input></el-form-item>
        </template>
        <el-form-item label="{{ __('admin/discount.minimum_amount') }}"><el-input v-model.number="dialog.form.minimum_amount" type="number" min="0" placeholder="0"><template slot="append">USD</template></el-input><div class="form-text">{{ __('admin/discount.minimum_amount_help') }}</div></el-form-item>
        <el-form-item label="{{ __('admin/discount.minimum_quantity') }}"><el-input v-model.number="dialog.form.minimum_quantity" type="number" min="0" step="1" placeholder="0"><template slot="append">{{ __('admin/discount.items') }}</template></el-input><div class="form-text">{{ __('admin/discount.minimum_quantity_help') }}</div></el-form-item>
        <el-form-item label="{{ __('admin/discount.product_ids') }}" v-if="['product_amount', 'buy_x_get_y'].includes(dialog.form.type)"><el-input v-model="dialog.form.product_ids" placeholder="{{ __('admin/discount.product_ids_help') }}"></el-input></el-form-item>
        <el-form-item label="{{ __('admin/discount.starts_at') }}"><el-date-picker v-model="dialog.form.starts_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" placeholder="{{ __('admin/discount.unlimited') }}" class="w-100"></el-date-picker></el-form-item>
        <el-form-item label="{{ __('admin/discount.ends_at') }}"><el-date-picker v-model="dialog.form.ends_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" placeholder="{{ __('admin/discount.unlimited') }}" class="w-100"></el-date-picker></el-form-item>
        <el-form-item label="{{ __('admin/discount.status') }}"><el-switch v-model="dialog.form.status" active-text="{{ __('common.enable') }}" inactive-text="{{ __('common.disable') }}"></el-switch></el-form-item>
        <el-form-item><el-button type="primary" @click="save">{{ __('common.save') }}</el-button><el-button @click="dialog.show = false">{{ __('common.cancel') }}</el-button></el-form-item>
      </el-form>
    </el-dialog>
  </div>
@endsection

@push('footer')
  <script>
    new Vue({
      el: '#discount-app',
      data: {
        discounts: @json($discounts ?? []),
        labels: @json(__('admin/discount.js')),
        dialog: {show: false, index: null, form: {}},
        rules: {name: [{required: true, message: @json(__('admin/discount.name_required')), trigger: 'blur'}], mode: [{required: true}], type: [{required: true}]},
      },
      computed: {isValueDiscount() { return ['product_amount', 'order_amount'].includes(this.dialog.form.type); }},
      methods: {
        defaults() { return {id: null, name: '', mode: 'automatic', code: '', type: 'product_amount', value_type: 'percentage', value: 10, minimum_amount: 0, minimum_quantity: 0, buy_quantity: 1, get_quantity: 1, product_ids: '', gift_product_ids: '', starts_at: '', ends_at: '', status: true}; },
        modeLabel(mode) { return mode === 'automatic' ? this.labels.automatic : this.labels.discount_code; },
        typeLabel(type) { return this.labels.types[type] || type; },
        typeDescription(type) { return this.labels.type_descriptions[type] || ''; },
        thresholdSummary(item) { const parts = []; if (Number(item.minimum_amount) > 0) parts.push(this.labels.minimum_amount_summary.replace(':amount', item.minimum_amount)); if (Number(item.minimum_quantity) > 0) parts.push(this.labels.minimum_quantity_summary.replace(':quantity', item.minimum_quantity)); return parts.join(this.labels.and); },
        ruleSummary(item) { let summary; if (item.type === 'buy_x_get_y') summary = this.labels.buy_get_summary.replace(':buy', item.buy_quantity).replace(':get', item.get_quantity); else if (item.type === 'free_shipping') summary = this.labels.free_shipping; else { const value = item.value_type === 'percentage' ? `${item.value}%` : item.value; summary = this.labels.value_summary.replace(':value', value); } const threshold = this.thresholdSummary(item); return threshold ? `${summary}${this.labels.threshold_separator}${threshold}` : summary; },
        dateRange(item) { return `${item.starts_at || this.labels.now} ${this.labels.to} ${item.ends_at || this.labels.long_term}`; },
        openCreate() { this.dialog.index = null; this.dialog.form = this.defaults(); this.dialog.show = true; },
        openEdit(item, index) { this.dialog.index = index; this.dialog.form = {...this.defaults(), ...item, product_ids: (item.product_ids || []).join(','), gift_product_ids: (item.gift_product_ids || []).join(''), starts_at: item.starts_at ? item.starts_at.replace('T', ' ').replace('.000000Z', '') : '', ends_at: item.ends_at ? item.ends_at.replace('T', ' ').replace('.000000Z', '') : ''}; this.dialog.show = true; },
        save() { this.$refs.discountForm.validate((valid) => { if (!valid) return; const isNew = !this.dialog.form.id; const method = isNew ? 'post' : 'put'; const url = isNew ? 'discounts' : `discounts/${this.dialog.form.id}`; $http[method](url, this.dialog.form).then((res) => { this.$message.success(res.message); if (isNew) this.discounts.unshift(res.data); else this.$set(this.discounts, this.dialog.index, res.data); this.dialog.show = false; }); }); },
        remove(item, index) { this.$confirm(this.labels.delete_confirm, '{{ __('common.text_hint') }}', {type: 'warning'}).then(() => $http.delete(`discounts/${item.id}`).then((res) => { this.$message.success(res.message); this.discounts.splice(index, 1); })).catch(() => {}); },
        resetForm() { if (this.$refs.discountForm) this.$refs.discountForm.resetFields(); this.dialog.form = {}; },
      },
    });
  </script>
@endpush
