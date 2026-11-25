@php
/**
 * @bladestan-signature
 * @var string $title
 * @var \App\Models\User $user
 */
@endphp

@extends('layouts.base-layout')

@section('content')
<h1>{{ $title }}</h1>
<p>{{ $user->email }}</p>
@endsection
