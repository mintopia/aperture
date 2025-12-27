@extends('layout.public')

@section('content')
    <div class="card-body">
        <div class="row">
            <p>Scan the QR code below on your phone to login with {{ $provider->name }}</p>
            <img src="{{ (new chillerlan\QRCode\QRCode())->render($deviceCode->fullUri) }}" alt="QR Code" />
            <p>
                You can also visit <a href="{{ config('aperture.borealis.endpoint') }}/auth">{{ config('aperture.borealis.endpoint') }}/auth</a>
                and enter <strong>{{ $deviceCode->userCode }}</strong>.
            </p>
            <p class="text-muted small">
                Alternatively, you can try and login with <a href="{{ route('login.redirect', ['provider' => $provider->code]) }}">{{ $provider->name }}</a>
                directly in your browser.
            </p>
        </div>
    </div>
@endsection
@push('scripts-footer')
    <script>
        let borealis = {
            interval: {{ $deviceCode->interval }} * 1000,
            status: '{{ $deviceCode->status->name }}',
            urls: {
                check: '{{ route('login.check') }}',
                success: '{{ route('home') }}',
                fail: '{{ route('login', ['fail' => 1]) }}',
            },

            timeout: null,

            init: function() {
                borealis.timeout = setTimeout(function() {
                    borealis.check();
                }, borealis.interval)
            },

            check: function() {
                fetch(borealis.urls.check).then(response => response.json()).then(response => {
                    borealis.process(response.data);
                });
            },

            process: function(data) {
                console.log(data);
                if (data.status === 'dcsPending') {
                    borealis.timeout = setTimeout(function() {
                        borealis.check();
                    }, borealis.interval);
                } else if (data.status === 'dcsFailed') {
                    window.location = borealis.urls.fail;
                } else if (data.status === 'dcsSuccessful') {
                    window.location = borealis.urls.success;
                }
            }
        }
        document.addEventListener("DOMContentLoaded", function () {
            borealis.init();
        });

    </script>
@endpush
