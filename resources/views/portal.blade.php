@extends('layout.public')

@section('content')

    <div class="alert alert-important alert-info d-none" role="alert" id="dns-warning">
        <div class="d-flex">
            <div>
                <i class="icon alert-icon icon-lg ti ti-network-off"></i>
            </div>
            <div>
                <h4 class="alert-heading">Your DNS is not configured properly</h4>
                <div class="alert-description">
                    You're using custom DNS servers. This means you will have slower game downloads.
                    <br /><br />
                    Please update your network settings to use the DNS servers that are automatically assigned
                    and disable any internet security software that may be hijacking DNS.
                </div>
            </div>
        </div>
    </div>
    <div class="card-body">


        <div class="row">
            <p class="mb-5">Hi <strong>{{ Auth::user()->nickname }}</strong>, thanks for logging in!</p>



            @if (Auth::user()->blocked)

                <div class="alert alert-important alert-danger" role="alert">
                    <div class="d-flex">
                        <div>
                            <i class="icon alert-icon ti ti-ban"></i>
                        </div>
                        <div class="text-center flex-grow-1">
                            Internet access has been denied
                        </div>
                    </div>
                </div>

                <p>
                    Please speak to a member of the team for further assistance.
                </p>
            @else
                <div id="status-waiting" class="{{ $ip->allowed ? 'd-none' : '' }}">
                    <p class="text-center text-secondary">Please Wait</p>
                    <div class="progress progress-sm">
                        <div class="progress-bar progress-bar-indeterminate"></div>
                    </div>
                </div>

                <div id="status-ok" class="{{ $ip->allowed ? '' : 'd-none' }}">
                    <div class="alert alert-important alert-success" role="alert">
                        <div class="d-flex">
                            <div>
                                <i class="icon alert-icon ti ti-check"></i>
                            </div>
                            <div class="text-center flex-grow-1">
                                Internet access has been granted!
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
@section('footer')
    @if (true || !Auth::user()->blocked && !$ip->allowed)
        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const statusOK = document.getElementById('status-ok');
            const statusWaiting = document.getElementById('status-waiting');

            let checks = 0;

            function checkStatus() {
                checks++;
                let timeout = 2000;
                if (checks > 20) {
                    timeout = 30000;
                } else if (checks > 4) {
                    timeout = 10000;
                }

                fetch('/status')
                    .then(response => {
                        if (!response.ok) {
                            console.log('Error from API');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.allowed === true) {
                            // If status is true, hide 'status-waiting' and show 'status-ok'
                            statusWaiting.classList.add('d-none');
                            statusOK.classList.remove('d-none');
                        } else {
                            setTimeout(checkStatus, timeout);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching status:', error);
                    });
            }

            fetch('https://' + crypto.randomUUID() + '.lancache.test.entropylan.party', {
                timeout: 2000,
            }).then(response => {
                if (response.ok) {
                    return response.json();
                }
            }).then(data => {
                if (data.server !== 'event') {
                    document.getElementById('dns-warning').classList.remove('d-none');
                }
            });

            @if($ipv6DetectionEnabled && $ipv6DetectionEndpoint)
            var ipv6Endpoint = '{{ $ipv6DetectionEndpoint }}'.replace('{random}', crypto.randomUUID());
            fetch(ipv6Endpoint, {
                timeout: 2000,
            }).then(response => {
                if (response.ok) {
                    return response.json();
                }
            }).then(data => {
                if (data) {
                    fetch("/ipv6", {
                        method: "POST",
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            'ipv6': data.ip,
                        }),
                    }).then(response => {
                        setTimeout(checkStatus, 2000);
                    });
                } else {
                    setTimeout(checkStatus, 2000);
                }
            }).catch(error => {
                setTimeout(checkStatus, 2000);
            });
            @else
            setTimeout(checkStatus, 2000);
            @endif

        });
        </script>
    @endif
@endsection
