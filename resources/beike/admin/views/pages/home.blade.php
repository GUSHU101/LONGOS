@extends('admin::layouts.master')

@section('title', '')

@section('body-class', 'admin-home')

@section('content')
  <div class="admin-commerce-home">
    @hook('admin.home.index.content.header')

    @include('admin::pages.dashboard.charts_data')

    @hook('admin.home.index.content.footer')
  </div>
@endsection
