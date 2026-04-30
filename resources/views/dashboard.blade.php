@extends('layouts.dashboard', ['page' => $page ?? 'overview'])

@section('content')
    @php
        $page = $page ?? 'overview';
        $allowed = ['overview', 'positions', 'scanner', 'history', 'failed', 'risk', 'settings'];
        $partial = in_array($page, $allowed, true) ? "pages.$page" : 'pages.overview';
    @endphp
    @include($partial)
@endsection
