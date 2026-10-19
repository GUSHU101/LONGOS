@extends('admin::layouts.master')

@php
  $settings = $plugin->getSetting() ?: [];
  $rawRules = old('shipping_rules', $settings['shipping_rules'] ?? '');
  $rules = is_array($rawRules) ? $rawRules : json_decode((string) $rawRules, true);
  if (!is_array($rules)) $rules = [];
  if (!$rules) {
    $legacy = json_decode((string) ($settings['zone_rules'] ?? '[]'), true);
    $rules = is_array($legacy) ? $legacy : [];
  }
  if (!$rules) {
    // Match the runtime fallback so a newly installed shipping plugin is
    // immediately understandable and usable without creating a blank rule.
    $rules = [[
      'code' => 'standard',
      'name' => '标准配送',
      'description' => '配送费用将在结账时计算',
      'enabled' => true,
      'type' => 'fixed',
      'value' => 0,
      'handling_fee' => 0,
      'weight_initial' => 0,
      'weight_step' => 1,
      'weight_rate' => 0,
      'quantity_initial' => 0,
      'quantity_step' => 1,
      'quantity_rate' => 0,
      'countries' => '',
      'zones' => '',
      'postcodes' => '',
      'min_order' => 0,
      'max_order' => 0,
      'min_weight' => 0,
      'max_weight' => 0,
      'min_quantity' => 0,
      'max_quantity' => 0,
      'free_shipping_threshold' => 0,
    ]];
  }
  $status = (int) old('status', $settings['status'] ?? 0);
@endphp

@section('title', $plugin->getLocaleName())

@section('content')
  <div class="card shipping-rule-editor" id="flat-shipping-editor" v-cloak>
    <div class="card-body p-3 p-lg-4">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <h5 class="mb-1">多方案运费</h5>
          <p class="text-muted mb-0">按地区、订单金额、重量、件数和邮编组合创建多个配送方案；客户在结账时选择符合条件的方案。</p>
        </div>
        <span class="badge text-bg-primary px-3 py-2">{{ $plugin->getLocaleName() }}</span>
      </div>

      <form action="{{ admin_route('plugins.update', [$plugin->code]) }}" method="POST" @submit="beforeSubmit">
        @csrf
        @method('put')
        <input type="hidden" name="shipping_rules" :value="serializedRules">
        <input type="hidden" name="status" value="0">

        <div class="form-check form-switch border rounded-3 px-5 py-3 mb-4 bg-light">
          <input class="form-check-input" type="checkbox" role="switch" name="status" value="1" id="flat-shipping-status" v-model="status">
          <label class="form-check-label fw-semibold" for="flat-shipping-status">启用多方案运费</label>
          <div class="small text-muted">关闭后该配送插件不会在结账页显示。</div>
        </div>

        <div class="rule-card card mb-3" v-for="(rule, index) in rules" :key="rule.uid">
          <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 bg-white">
            <div class="d-flex align-items-center gap-2"><span class="rule-number">@{{ index + 1 }}</span><strong>@{{ rule.name || '未命名配送方案' }}</strong><span class="badge text-bg-light border">@{{ typeLabel(rule.type) }}</span></div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-outline-secondary" @click="move(index, -1)" :disabled="index === 0"><i class="bi bi-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-outline-secondary" @click="move(index, 1)" :disabled="index === rules.length - 1"><i class="bi bi-arrow-down"></i></button>
              <button type="button" class="btn btn-sm btn-outline-primary" @click="duplicate(index)">复制</button>
              <button type="button" class="btn btn-sm btn-outline-danger" @click="remove(index)">删除</button>
            </div>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4"><label class="form-label">方案名称 <span class="text-danger">*</span></label><input class="form-control" v-model.trim="rule.name" maxlength="80" placeholder="例如：标准配送"></div>
              <div class="col-md-3"><label class="form-label">方案代码</label><input class="form-control" v-model="rule.code" @input="normalizeCode(rule)" maxlength="48" placeholder="standard"><div class="form-text">仅英文、数字、-、_。</div></div>
              <div class="col-md-3"><label class="form-label">配送时效</label><input class="form-control" v-model.trim="rule.description" maxlength="120" placeholder="例如：3–7 个工作日"></div>
              <div class="col-md-2"><label class="form-label">状态</label><select class="form-select" v-model="rule.enabled"><option :value="true">启用</option><option :value="false">停用</option></select></div>

              <div class="col-md-3"><label class="form-label">计费方式</label><select class="form-select" v-model="rule.type"><option value="fixed">固定金额</option><option value="percent">订单金额百分比</option><option value="weight">重量阶梯</option><option value="quantity">件数阶梯</option><option value="free">免运费</option></select></div>
              <div class="col-md-3" v-if="rule.type !== 'free'"><label class="form-label">基础运费</label><div class="input-group"><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.value"><span class="input-group-text">@{{ rule.type === 'percent' ? '%' : '金额' }}</span></div></div>
              <div class="col-md-3" v-if="rule.type !== 'free'"><label class="form-label">处理费</label><div class="input-group"><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.handling_fee"><span class="input-group-text">金额</span></div></div>
              <div class="col-md-3" v-if="rule.type === 'free'"><label class="form-label">说明</label><div class="form-control bg-light">符合条件时运费为 0</div></div>

              <template v-if="rule.type === 'weight'">
                <div class="col-md-4"><label class="form-label">首重</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.weight_initial"></div>
                <div class="col-md-4"><label class="form-label">续重单位</label><input class="form-control" type="number" min="0.01" step="0.01" v-model.number="rule.weight_step"></div>
                <div class="col-md-4"><label class="form-label">每续重单位加价</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.weight_rate"></div>
              </template>
              <template v-if="rule.type === 'quantity'">
                <div class="col-md-4"><label class="form-label">基础运费覆盖件数</label><input class="form-control" type="number" min="0" step="1" v-model.number="rule.quantity_initial"></div>
                <div class="col-md-4"><label class="form-label">续件单位</label><input class="form-control" type="number" min="1" step="1" v-model.number="rule.quantity_step"></div>
                <div class="col-md-4"><label class="form-label">每续件单位加价</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.quantity_rate"></div>
              </template>

              <div class="col-12"><hr class="my-1"><h6 class="mb-0">适用条件 <small class="text-muted fw-normal">所有填写的条件必须同时满足；留空或填 0 表示不限制。</small></h6></div>
              <div class="col-md-6"><label class="form-label">国家代码</label><input class="form-control" v-model="rule.countries" placeholder="US, CA, GB"><div class="form-text">ISO 两位国家代码，用英文逗号分隔。</div></div>
              <div class="col-md-6"><label class="form-label">省/州代码</label><input class="form-control" v-model="rule.zones" placeholder="CA, NY"><div class="form-text">省/州编码，用英文逗号分隔。</div></div>
              <div class="col-md-6"><label class="form-label">邮编</label><input class="form-control" v-model="rule.postcodes" placeholder="90210, 10001"><div class="form-text">精确匹配的邮编，用英文逗号分隔。</div></div>
              <div class="col-md-3"><label class="form-label">最低订单金额</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.min_order"></div>
              <div class="col-md-3"><label class="form-label">最高订单金额</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.max_order"></div>
              <div class="col-md-3"><label class="form-label">最低重量</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.min_weight"></div>
              <div class="col-md-3"><label class="form-label">最高重量</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.max_weight"></div>
              <div class="col-md-3"><label class="form-label">最少件数</label><input class="form-control" type="number" min="0" step="1" v-model.number="rule.min_quantity"></div>
              <div class="col-md-3"><label class="form-label">最多件数</label><input class="form-control" type="number" min="0" step="1" v-model.number="rule.max_quantity"></div>
              <div class="col-md-3"><label class="form-label">免邮门槛</label><input class="form-control" type="number" min="0" step="0.01" v-model.number="rule.free_shipping_threshold"></div>
            </div>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
          <button type="button" class="btn btn-outline-primary" @click="addRule"><i class="bi bi-plus-lg me-1"></i>添加配送方案</button>
          <button type="submit" class="btn btn-primary px-4">保存运费方案</button>
        </div>
      </form>
    </div>
  </div>
@endsection

@push('footer')
  <style>
    .shipping-rule-editor .rule-card { border: 1px solid #dfe5ee; border-radius: 16px; overflow: hidden; }
    .shipping-rule-editor .rule-card .card-header { border-bottom: 1px solid #edf0f4; padding: .85rem 1rem; }
    .shipping-rule-editor .rule-number { display: inline-grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; color: #155eef; background: #edf4ff; font-size: .8rem; font-weight: 800; }
    .shipping-rule-editor .empty-state { background: #fafcff; border-style: dashed !important; }
  </style>
  <script>
    new Vue({
      el: '#flat-shipping-editor',
      data: {status: @json((bool) $status), rules: @json(array_values($rules))},
      computed: {serializedRules() { return JSON.stringify(this.rules.map((rule, index) => { const {uid, ...stored} = rule; return {...stored, sort_order: index}; })); }},
      created() { this.rules = this.rules.map((rule, index) => this.normalizeRule(rule, index)); },
      methods: {
        defaults(index = 0) { return {uid: `rule-${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`, code: `method_${index + 1}`, name: '', description: '', enabled: true, type: 'fixed', value: 0, handling_fee: 0, weight_initial: 0, weight_step: 1, weight_rate: 0, quantity_initial: 0, quantity_step: 1, quantity_rate: 0, countries: '', zones: '', postcodes: '', min_order: 0, max_order: 0, min_weight: 0, max_weight: 0, min_quantity: 0, max_quantity: 0, free_shipping_threshold: 0}; },
        normalizeRule(rule, index) { const next = {...this.defaults(index), ...rule}; next.uid = next.uid || `rule-${Date.now()}-${index}`; next.enabled = !(next.enabled === false || next.enabled === 0 || next.enabled === '0'); ['countries', 'zones', 'postcodes'].forEach((key) => { if (Array.isArray(next[key])) next[key] = next[key].join(','); }); return next; },
        typeLabel(type) { return {fixed: '固定金额', percent: '订单比例', weight: '重量阶梯', quantity: '件数阶梯', free: '免运费'}[type] || '固定金额'; },
        normalizeCode(rule) { rule.code = String(rule.code || '').toLowerCase().replace(/[^a-z0-9_-]+/g, '_').replace(/^_+|_+$/g, ''); },
        addRule() { this.rules.push(this.defaults(this.rules.length)); },
        duplicate(index) { const copy = this.normalizeRule(JSON.parse(JSON.stringify(this.rules[index])), this.rules.length); copy.uid = `rule-${Date.now()}-copy`; copy.code = `${copy.code || 'method'}_copy`; copy.name = `${copy.name || '配送方案'}（副本）`; this.rules.splice(index + 1, 0, copy); },
        remove(index) { this.rules.splice(index, 1); },
        move(index, direction) { const target = index + direction; if (target < 0 || target >= this.rules.length) return; const item = this.rules.splice(index, 1)[0]; this.rules.splice(target, 0, item); },
        beforeSubmit(event) {
          let message = '';
          if (this.rules.length > 100) message = '配送方案不能超过 100 个。';
          const codes = new Set();
          for (const rule of this.rules) {
            if (!String(rule.name || '').trim()) { message = '请填写每个配送方案的名称。'; break; }
            if (!/^[a-z0-9_-]{1,80}$/.test(String(rule.code || ''))) { message = '请填写有效且唯一的方案代码。'; break; }
            if (codes.has(rule.code)) { message = '方案代码不能重复。'; break; }
            codes.add(rule.code);
            const invalidRange = [['min_order', 'max_order'], ['min_weight', 'max_weight'], ['min_quantity', 'max_quantity']]
              .some(([minimum, maximum]) => Number(rule[maximum] || 0) > 0 && Number(rule[maximum]) < Number(rule[minimum] || 0));
            if (invalidRange) { message = '最高限制不能小于最低限制。'; break; }
            if ((rule.type === 'weight' && Number(rule.weight_step) <= 0) || (rule.type === 'quantity' && Number(rule.quantity_step) <= 0)) { message = '阶梯计费的续重/续件单位必须大于 0。'; break; }
          }
          if (message) { event.preventDefault(); this.$message.error(message); }
        },
      },
    });
  </script>
@endpush
