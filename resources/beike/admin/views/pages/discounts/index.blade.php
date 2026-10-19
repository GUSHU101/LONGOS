@extends('admin::layouts.master')

@section('title', __('admin/common.discounts_index'))

@section('content')
  <div id="discount-app" class="card h-min-600" v-cloak>
    <div class="card-body">
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
        <div>
          <h5 class="mb-1">折扣</h5>
          <div class="text-muted small">创建自动优惠或客户可输入的折扣码。商品 ID 留空时规则适用于全店商品。</div>
        </div>
        <button type="button" class="btn btn-primary" @click="openCreate">添加折扣</button>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
          <tr>
            <th>ID</th>
            <th>名称</th>
            <th>方式</th>
            <th>折扣类型</th>
            <th>规则</th>
            <th>状态</th>
            <th>有效期</th>
            <th class="text-end">{{ __('common.action') }}</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="(discount, index) in discounts" :key="discount.id">
            <td>@{{ discount.id }}</td>
            <td><strong>@{{ discount.name }}</strong><div class="small text-muted" v-if="discount.code">代码：@{{ discount.code }}</div></td>
            <td><span :class="discount.mode === 'automatic' ? 'badge bg-primary' : 'badge bg-dark'">@{{ modeLabel(discount.mode) }}</span></td>
            <td>@{{ typeLabel(discount.type) }}</td>
            <td>@{{ ruleSummary(discount) }}</td>
            <td><span :class="discount.status ? 'text-success' : 'text-muted'">@{{ discount.status ? '启用' : '停用' }}</span></td>
            <td class="small text-muted">@{{ dateRange(discount) }}</td>
            <td class="text-end text-nowrap">
              <button type="button" class="btn btn-outline-primary btn-sm" @click="openEdit(discount, index)">编辑</button>
              <button type="button" class="btn btn-outline-danger btn-sm ms-1" @click="remove(discount, index)">删除</button>
            </td>
          </tr>
          <tr v-if="!discounts.length"><td colspan="8" class="text-center py-5 text-muted">尚未创建折扣活动</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <el-dialog :title="dialog.form.id ? '编辑折扣' : '添加折扣'" :visible.sync="dialog.show" width="760px" :close-on-click-modal="false" @close="resetForm">
      <el-form ref="discountForm" :model="dialog.form" :rules="rules" label-width="132px">
        <el-form-item label="活动名称" prop="name"><el-input v-model="dialog.form.name" placeholder="例如：夏季全场九折"></el-input></el-form-item>
        <el-form-item label="折扣方式" prop="mode">
          <el-radio-group v-model="dialog.form.mode"><el-radio label="automatic">自动折扣</el-radio><el-radio label="code">折扣码</el-radio></el-radio-group>
        </el-form-item>
        <el-form-item label="折扣码" prop="code" v-if="dialog.form.mode === 'code'"><el-input v-model="dialog.form.code" placeholder="例如：WELCOME10" @input="dialog.form.code = dialog.form.code.toUpperCase()"></el-input></el-form-item>
        <el-form-item label="折扣类型" prop="type">
          <el-select v-model="dialog.form.type" class="w-100">
            <el-option label="产品金额减免" value="product_amount"></el-option>
            <el-option label="买 X 送 Y" value="buy_x_get_y"></el-option>
            <el-option label="订单金额减免" value="order_amount"></el-option>
            <el-option label="免运费" value="free_shipping"></el-option>
          </el-select>
          <div class="form-text">@{{ typeDescription(dialog.form.type) }}</div>
        </el-form-item>
        <template v-if="isValueDiscount">
          <el-form-item label="优惠值" prop="value">
            <div class="d-flex gap-2"><el-select v-model="dialog.form.value_type" style="width: 150px"><el-option label="百分比" value="percentage"></el-option><el-option label="固定金额" value="fixed"></el-option></el-select><el-input v-model.number="dialog.form.value" type="number" min="0"><template slot="append">@{{ dialog.form.value_type === 'percentage' ? '%' : 'USD' }}</template></el-input></div>
          </el-form-item>
        </template>
        <template v-if="dialog.form.type === 'buy_x_get_y'">
          <el-form-item label="购买 / 赠送"><div class="d-flex gap-2"><el-input v-model.number="dialog.form.buy_quantity" type="number" min="1"><template slot="prepend">买</template></el-input><el-input v-model.number="dialog.form.get_quantity" type="number" min="1"><template slot="prepend">送</template></el-input></div></el-form-item>
          <el-form-item label="赠品商品 ID"><el-input v-model="dialog.form.gift_product_ids" placeholder="多个商品 ID 请用英文逗号分隔；留空时赠送同范围商品中单价最低的商品"></el-input></el-form-item>
        </template>
        <el-form-item label="最低订单金额"><el-input v-model.number="dialog.form.minimum_amount" type="number" min="0" placeholder="0"><template slot="append">USD</template></el-input><div class="form-text">折扣规则始终以店铺基准货币 USD 计算；达到该金额后才能使用，0 表示无门槛。</div></el-form-item>
        <el-form-item label="适用商品 ID" v-if="['product_amount', 'buy_x_get_y'].includes(dialog.form.type)"><el-input v-model="dialog.form.product_ids" placeholder="多个商品 ID 请用英文逗号分隔；留空为全店商品"></el-input></el-form-item>
        <el-form-item label="开始时间"><el-date-picker v-model="dialog.form.starts_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" placeholder="不限制" class="w-100"></el-date-picker></el-form-item>
        <el-form-item label="结束时间"><el-date-picker v-model="dialog.form.ends_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" placeholder="不限制" class="w-100"></el-date-picker></el-form-item>
        <el-form-item label="状态"><el-switch v-model="dialog.form.status" active-text="启用" inactive-text="停用"></el-switch></el-form-item>
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
        dialog: {show: false, index: null, form: {}},
        rules: {name: [{required: true, message: '请填写活动名称', trigger: 'blur'}], mode: [{required: true}], type: [{required: true}]},
      },
      computed: {isValueDiscount() { return ['product_amount', 'order_amount'].includes(this.dialog.form.type); }},
      methods: {
        defaults() { return {id: null, name: '', mode: 'automatic', code: '', type: 'product_amount', value_type: 'percentage', value: 10, minimum_amount: 0, buy_quantity: 1, get_quantity: 1, product_ids: '', gift_product_ids: '', starts_at: '', ends_at: '', status: true}; },
        modeLabel(mode) { return mode === 'automatic' ? '自动折扣' : '折扣码'; },
        typeLabel(type) { return {product_amount: '产品金额减免', buy_x_get_y: '买 X 送 Y', order_amount: '订单金额减免', free_shipping: '免运费'}[type] || type; },
        typeDescription(type) { return {product_amount: '为特定产品或产品系列提供金额或百分比减免。', buy_x_get_y: '达到购买数量后，赠送指定商品或范围内价格最低的商品。', order_amount: '按订单商品总额提供金额或百分比减免。', free_shipping: '达到门槛后免除当前选择的配送费用。'}[type] || ''; },
        ruleSummary(item) { if (item.type === 'buy_x_get_y') return `买 ${item.buy_quantity} 送 ${item.get_quantity}`; if (item.type === 'free_shipping') return item.minimum_amount > 0 ? `满 ${item.minimum_amount} 免运费` : '免运费'; const value = item.value_type === 'percentage' ? `${item.value}%` : item.value; return item.minimum_amount > 0 ? `优惠 ${value}，满 ${item.minimum_amount} 可用` : `优惠 ${value}`; },
        dateRange(item) { return `${item.starts_at || '立即'} 至 ${item.ends_at || '长期'}`; },
        openCreate() { this.dialog.index = null; this.dialog.form = this.defaults(); this.dialog.show = true; },
        openEdit(item, index) { this.dialog.index = index; this.dialog.form = {...this.defaults(), ...item, product_ids: (item.product_ids || []).join(','), gift_product_ids: (item.gift_product_ids || []).join(''), starts_at: item.starts_at ? item.starts_at.replace('T', ' ').replace('.000000Z', '') : '', ends_at: item.ends_at ? item.ends_at.replace('T', ' ').replace('.000000Z', '') : ''}; this.dialog.show = true; },
        save() { this.$refs.discountForm.validate((valid) => { if (!valid) return; const isNew = !this.dialog.form.id; const method = isNew ? 'post' : 'put'; const url = isNew ? 'discounts' : `discounts/${this.dialog.form.id}`; $http[method](url, this.dialog.form).then((res) => { this.$message.success(res.message); if (isNew) this.discounts.unshift(res.data); else this.$set(this.discounts, this.dialog.index, res.data); this.dialog.show = false; }); }); },
        remove(item, index) { this.$confirm('删除后该折扣将不能再被使用，是否继续？', '{{ __('common.text_hint') }}', {type: 'warning'}).then(() => $http.delete(`discounts/${item.id}`).then((res) => { this.$message.success(res.message); this.discounts.splice(index, 1); })).catch(() => {}); },
        resetForm() { if (this.$refs.discountForm) this.$refs.discountForm.resetFields(); this.dialog.form = {}; },
      },
    });
  </script>
@endpush
