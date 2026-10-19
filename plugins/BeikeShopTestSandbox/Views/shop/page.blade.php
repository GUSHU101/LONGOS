@extends('layout.master')

@section('minimal-checkout', '1')

@section('content')
    @include('BeikeShopTestSandbox::shop.test_checkout', ['title' => $title])
@endsection
