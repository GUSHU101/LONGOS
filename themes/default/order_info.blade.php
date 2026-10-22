@extends('layout.master')

@section('body-class', 'page-account-order-info')

@section('content')
  <div class="container">
    <div class="order-detail-shell w-max-1000 mx-auto py-4 py-lg-5">
      @include('shared.order_info')
    </div>
  </div>
@endsection
