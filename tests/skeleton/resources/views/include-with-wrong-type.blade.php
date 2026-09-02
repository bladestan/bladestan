@php
/**
 * @bladestan-signature
 * @var \App\Models\User $user
 */
@endphp

<h1>Report</h1>

@include('signed-template', ['title' => 1, 'user' => $user])
