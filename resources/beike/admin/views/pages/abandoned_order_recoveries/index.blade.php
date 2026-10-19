@extends('admin::layouts.master')

@section('title', __('admin/abandoned_order_recovery.page_title'))

@section('content')
  <div id="order-recovery-app" v-cloak>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
      <div>
        <h4 class="mb-1">{{ __('admin/abandoned_order_recovery.page_title') }}</h4>
        <p class="text-muted mb-0">{{ __('admin/abandoned_order_recovery.description') }}</p>
      </div>
      <div class="d-flex gap-2">
        <div class="card px-3 py-2"><small class="text-muted">{{ __('admin/abandoned_order_recovery.sent_total') }}</small><strong>{{ $stats['sent'] }}</strong></div>
        <div class="card px-3 py-2"><small class="text-muted">{{ __('admin/abandoned_order_recovery.restored_total') }}</small><strong>{{ $stats['restored'] }}</strong></div>
        <div class="card px-3 py-2"><small class="text-muted">{{ __('admin/abandoned_order_recovery.converted_total') }}</small><strong>{{ $stats['converted'] }}</strong></div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <div class="row g-4">
          <section class="col-xl-4" aria-labelledby="recovery-rule-title">
            <h5 id="recovery-rule-title" class="mb-3">{{ __('admin/abandoned_order_recovery.strategy') }}</h5>
            <el-form label-position="top" :model="settings">
              <div class="row g-2">
                <div class="col-md-6"><el-form-item label="{{ __('admin/abandoned_order_recovery.delay_hours') }}"><el-input-number v-model="settings.delay_hours" :min="1" :max="720"></el-input-number><span class="ms-2 text-muted">{{ __('admin/abandoned_order_recovery.hours') }}</span></el-form-item></div>
                <div class="col-md-6"><el-form-item label="{{ __('admin/abandoned_order_recovery.cooldown_hours') }}"><el-input-number v-model="settings.cooldown_hours" :min="1" :max="720"></el-input-number><span class="ms-2 text-muted">{{ __('admin/abandoned_order_recovery.hours') }}</span></el-form-item></div>
                <div class="col-md-6"><el-form-item label="{{ __('admin/abandoned_order_recovery.max_attempts') }}"><el-input-number v-model="settings.max_attempts" :min="1" :max="10"></el-input-number><span class="ms-2 text-muted">{{ __('admin/abandoned_order_recovery.times') }}</span></el-form-item></div>
                <div class="col-md-6"><el-form-item label="{{ __('admin/abandoned_order_recovery.link_days') }}"><el-input-number v-model="settings.link_days" :min="1" :max="90"></el-input-number><span class="ms-2 text-muted">{{ __('admin/abandoned_order_recovery.days') }}</span></el-form-item></div>
              </div>
            </el-form>
          </section>

          <section class="col-xl-8 border-start-xl" aria-labelledby="recovery-template-title">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 id="recovery-template-title" class="mb-0">{{ __('admin/abandoned_order_recovery.template') }}</h5>
              <button class="btn btn-outline-secondary btn-sm" type="button" @click="preview" :disabled="!previewOrderId || previewing">
                <span v-if="previewing" class="spinner-border spinner-border-sm me-1"></span>{{ __('admin/abandoned_order_recovery.preview') }}
              </button>
            </div>
            <el-form label-position="top" :model="settings">
              <el-form-item label="{{ __('admin/abandoned_order_recovery.subject') }}"><el-input v-model="settings.subject" maxlength="180" show-word-limit></el-input></el-form-item>
              <el-form-item label="{{ __('admin/abandoned_order_recovery.heading') }}"><el-input v-model="settings.heading" maxlength="180" show-word-limit></el-input></el-form-item>
              <el-form-item label="{{ __('admin/abandoned_order_recovery.message') }}"><el-input type="textarea" :rows="5" v-model="settings.message" maxlength="2000" show-word-limit></el-input></el-form-item>
              <div class="row g-2">
                <div class="col-md-5"><el-form-item label="{{ __('admin/abandoned_order_recovery.button_label') }}"><el-input v-model="settings.button_label" maxlength="64"></el-input></el-form-item></div>
                <div class="col-md-7"><el-form-item label="{{ __('admin/abandoned_order_recovery.discount_message') }}"><el-input v-model="settings.discount_message" maxlength="300"></el-input></el-form-item></div>
              </div>
              <p class="small text-muted">{{ __('admin/abandoned_order_recovery.placeholders') }}</p>
              @can('order_recoveries_update')
                <button class="btn btn-primary" type="button" @click="saveSettings" :disabled="saving">
                  <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ __('admin/abandoned_order_recovery.save_settings') }}
                </button>
              @endcan
            </el-form>
          </section>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
          <div><h5 class="mb-1">{{ __('admin/abandoned_order_recovery.candidate_orders') }}</h5><p class="small text-muted mb-0">{{ __('admin/abandoned_order_recovery.candidate_help') }}</p></div>
          <form method="get" action="{{ admin_route('order_recoveries.index') }}" class="d-flex gap-2">
            <input class="form-control" type="search" name="keyword" value="{{ request('keyword') }}" maxlength="100" placeholder="{{ __('admin/abandoned_order_recovery.search_placeholder') }}">
            <button class="btn btn-outline-primary text-nowrap" type="submit">{{ __('admin/abandoned_order_recovery.search') }}</button>
          </form>
        </div>

        <div class="bg-light rounded-3 p-3 mb-3">
          <div class="row align-items-end g-3">
            <div class="col-lg-3">
              <div class="form-check form-switch mb-2">
                <input id="include-discount" class="form-check-input" type="checkbox" v-model="includeDiscount">
                <label class="form-check-label" for="include-discount">{{ __('admin/abandoned_order_recovery.select_discount') }}</label>
              </div>
            </div>
            <div class="col-lg-5">
              <el-select v-model="discountId" class="w-100" :disabled="!includeDiscount" clearable placeholder="{{ __('admin/abandoned_order_recovery.no_discount') }}">
                @foreach ($discounts as $discount)
                  <el-option label="{{ $discount->name }} · {{ $discount->code }}" :value="{{ $discount->id }}"></el-option>
                @endforeach
              </el-select>
            </div>
            <div class="col-lg-4 text-lg-end">
              @can('order_recoveries_send')
                <button type="button" class="btn btn-primary" @click="sendOrders(selectedIds)" :disabled="sending || !selectedIds.length">
                  <span v-if="sending" class="spinner-border spinner-border-sm me-1"></span>{{ __('admin/abandoned_order_recovery.send_selected') }} (@{{ selectedIds.length }})
                </button>
              @endcan
            </div>
          </div>
          <div class="small text-muted mt-2">{{ __('admin/abandoned_order_recovery.discount_help') }}</div>
        </div>

        @if ($orders->count())
          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead><tr><th style="width:38px"></th><th>{{ __('admin/abandoned_order_recovery.order') }}</th><th>{{ __('admin/abandoned_order_recovery.customer') }}</th><th>{{ __('admin/abandoned_order_recovery.amount') }}</th><th>{{ __('admin/abandoned_order_recovery.abandoned_at') }}</th><th>{{ __('admin/abandoned_order_recovery.attempts') }}</th><th>{{ __('admin/abandoned_order_recovery.state') }}</th><th class="text-end">{{ __('common.action') }}</th></tr></thead>
              <tbody>
              @foreach ($orders as $order)
                @php
                  $state = $order->recovery_state;
                @endphp
                <tr>
                  <td><input class="form-check-input" type="checkbox" value="{{ $order->id }}" v-model="selectedIds" @disabled(!$state['eligible']) aria-label="{{ __('admin/abandoned_order_recovery.order') }} {{ $order->number }}"></td>
                  <td><a href="{{ admin_route('orders.show', [$order->id]) }}"><strong>{{ $order->number }}</strong></a></td>
                  <td><div>{{ $order->customer_name }}</div><small class="text-muted">{{ $order->email }}</small></td>
                  <td>{{ $order->total_format }}</td>
                  <td>{{ $order->created_at }}</td>
                  <td>{{ $state['attempts'] }} / {{ $settings['max_attempts'] }}</td>
                  <td>
                    <span class="badge {{ $state['eligible'] ? 'bg-success' : ($state['code'] === 'cooldown' ? 'bg-warning text-dark' : 'bg-secondary') }}">{{ $state['label'] }}</span>
                    @if ($state['available_at'])<div class="small text-muted mt-1">{{ __('admin/abandoned_order_recovery.next_available') }}：{{ $state['available_at'] }}</div>@endif
                  </td>
                  <td class="text-end">
                    @can('order_recoveries_send')
                      <button class="btn btn-outline-primary btn-sm" type="button" @click="sendOrders([{{ $order->id }}])" :disabled="sending || {{ $state['eligible'] ? 'false' : 'true' }}">{{ __('admin/abandoned_order_recovery.send_one') }}</button>
                    @endcan
                  </td>
                </tr>
              @endforeach
              </tbody>
            </table>
          </div>
          {{ $orders->links('admin::vendor/pagination/bootstrap-4') }}
        @else
          <div class="text-center text-muted py-5">{{ __('admin/abandoned_order_recovery.empty') }}</div>
        @endif
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h5 class="mb-3">{{ __('admin/abandoned_order_recovery.recent_history') }}</h5>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead><tr><th>{{ __('admin/abandoned_order_recovery.order') }}</th><th>{{ __('order.email') }}</th><th>{{ __('admin/abandoned_order_recovery.discount') }}</th><th>{{ __('admin/abandoned_order_recovery.delivery_status') }}</th><th>{{ __('admin/abandoned_order_recovery.sent_by') }}</th><th>{{ __('admin/abandoned_order_recovery.sent_at') }}</th><th>{{ __('admin/abandoned_order_recovery.converted') }}</th></tr></thead>
            <tbody>
            @forelse ($history as $item)
              <tr>
                <td>{{ $item->order?->number ?? '#' . $item->order_id }}</td>
                <td>{{ $item->recipient_email }}</td>
                <td>{{ $item->discount_code ?: '—' }}</td>
                <td>
                  @php
                    $statusClass = $item->status === 'sent' ? 'bg-success' : ($item->status === 'failed' ? 'bg-danger' : 'bg-warning text-dark');
                  @endphp
                  <span class="badge {{ $statusClass }}">{{ __('admin/abandoned_order_recovery.status_' . $item->status) }}</span>
                  @if ($item->failure_message)<div class="small text-danger mt-1" title="{{ $item->failure_message }}">{{ IlluminateSupportStr::limit($item->failure_message, 80) }}</div>@endif
                </td>
                <td>{{ $item->adminUser?->name ?: '—' }}</td>
                <td>{{ $item->sent_at ?: $item->created_at }}</td>
                <td>{{ $item->converted_at ? __('admin/abandoned_order_recovery.converted') : __('admin/abandoned_order_recovery.not_converted') }}</td>
              </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted py-4">—</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <el-dialog title="{{ __('admin/abandoned_order_recovery.preview') }}" :visible.sync="previewVisible" width="760px" top="4vh" @closed="clearPreview">
      <iframe v-if="previewUrl" :src="previewUrl" sandbox="" title="{{ __('admin/abandoned_order_recovery.preview') }}" style="width:100%;height:70vh;border:1px solid #e5e7eb;border-radius:6px;background:#fff"></iframe>
    </el-dialog>
  </div>
@endsection

@push('footer')
  <script>
    new Vue({
      el: '#order-recovery-app',
      data: {
        settings: @json($settings),
        selectedIds: [],
        includeDiscount: false,
        discountId: null,
        previewOrderId: @json($previewOrderId),
        saving: false,
        sending: false,
        previewing: false,
        previewVisible: false,
        previewHtml: '',
        previewUrl: '',
        urls: {
          settings: @json(admin_route('order_recoveries.settings')),
          preview: @json(admin_route('order_recoveries.preview')),
          send: @json(admin_route('order_recoveries.send')),
        },
      },
      watch: {
        selectedIds(ids) {
          if (ids.length) this.previewOrderId = Number(ids[0]);
        },
      },
      beforeDestroy() {
        this.revokePreviewUrl();
      },
      methods: {
        payloadDiscount() { return this.includeDiscount && this.discountId ? this.discountId : null; },
        saveSettings() {
          this.saving = true;
          $http.put(this.urls.settings, {settings: this.settings}).then((res) => {
            this.settings = res.data;
            this.$message.success(res.message);
          }).finally(() => { this.saving = false; });
        },
        preview() {
          if (!this.previewOrderId) return this.$message.warning(@json(__('admin/abandoned_order_recovery.preview_unavailable')));
          this.previewing = true;
          $http.post(this.urls.preview, {order_id: this.previewOrderId, discount_id: this.payloadDiscount(), settings: this.settings}).then((res) => {
            const html = String(res?.data?.html || '');
            if (!html) {
              this.$message.warning(@json(__('admin/abandoned_order_recovery.preview_unavailable')));
              return;
            }
            this.previewHtml = html;
            this.revokePreviewUrl();
            this.previewUrl = URL.createObjectURL(new Blob([html], {type: 'text/html;charset=utf-8'}));
            this.previewVisible = true;
          }).finally(() => { this.previewing = false; });
        },
        revokePreviewUrl() {
          if (!this.previewUrl) return;
          URL.revokeObjectURL(this.previewUrl);
          this.previewUrl = '';
        },
        clearPreview() {
          this.revokePreviewUrl();
          this.previewHtml = '';
        },
        sendOrders(ids) {
          ids = [...new Set(ids.map(Number))].filter(Boolean);
          if (!ids.length) return this.$message.warning(@json(__('admin/abandoned_order_recovery.select_order_first')));
          const message = @json(__('admin/abandoned_order_recovery.send_confirm', ['count' => '__COUNT__'])).replace('__COUNT__', ids.length);
          this.$confirm(message, @json(__('common.text_hint')), {type: 'warning'}).then(() => {
            this.sending = true;
            return $http.post(this.urls.send, {order_ids: ids, discount_id: this.payloadDiscount()}).then((res) => {
              const failures = (res.data.failed || []).map(item => `#${item.order_id}: ${item.message}`).join('\n');
              failures ? this.$alert(failures, res.message, {type: 'warning'}) : this.$message.success(res.message);
              window.setTimeout(() => location.reload(), failures ? 1800 : 700);
            }).finally(() => { this.sending = false; });
          }).catch(() => {});
        },
      },
    });
  </script>
@endpush
