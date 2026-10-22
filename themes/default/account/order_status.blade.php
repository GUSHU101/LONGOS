@hookwrapper('account.order.status.tab')
<nav class="order-status-nav" aria-label="{{ __('shop/account/order.filter_label') }}">
<ul class="nav nav-tabs order-status-wrap" role="list">
  <li class="nav-item" role="presentation">
    <a class="nav-link {{ !request('status') ? 'active' : '' }}" href="{{ shop_route('account.order.index') }}" @if (!request('status')) aria-current="page" @endif>{{ __('order.order_all') }}</a>
  </li>
  <li class="nav-item" role="presentation">
    <a class="nav-link {{ request('status') == 'unpaid' ? 'active' : '' }}" href="{{ shop_route('account.order.index', ['status' => 'unpaid']) }}" @if (request('status') == 'unpaid') aria-current="page" @endif>{{ __('order.unpaid') }}</a>
  </li>
  <li class="nav-item" role="presentation">
    <a class="nav-link {{ request('status') == 'paid' ? 'active' : '' }}" href="{{ shop_route('account.order.index', ['status' => 'paid']) }}" @if (request('status') == 'paid') aria-current="page" @endif>{{ __('shop/account.pending_send') }}</a>
  </li>
  <li class="nav-item" role="presentation">
    <a class="nav-link {{ request('status') == 'shipped' ? 'active' : '' }}" href="{{ shop_route('account.order.index', ['status' => 'shipped']) }}" @if (request('status') == 'shipped') aria-current="page" @endif>{{ __('shop/account.pending_receipt') }}</a>
  </li>
  <li class="nav-item" role="presentation">
    <a class="nav-link {{ request('status') == 'completed' ? 'active' : '' }}" href="{{ shop_route('account.order.index', ['status' => 'completed']) }}" @if (request('status') == 'completed') aria-current="page" @endif>{{ __('order.completed') }}</a>
  </li>
</ul>
</nav>
@endhookwrapper
