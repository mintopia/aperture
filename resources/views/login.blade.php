@extends('layout.public')

@section('content')
    <div class="card-body">
        <div class="row">
            <p>Welcome to P-LAN! Before you can use the Internet, we just need you to login.</p>
        </div>
        <div class="row mt-3">
            @foreach ($providers as $provider)
                <div class="col">
                    <a href="{{ route('login.redirect', ['provider' => $provider->code]) }}" class="btn w-100">
                        <i class="icon ti ti-brand-{{$provider->code}}"></i>
                        Login with {{ $provider->name }}
                    </a>
                </div>
            @endforeach
        </div>
    </div>
@endsection
