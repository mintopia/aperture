@extends('layout.admin')

@section('header')
    <div class="container-xl">
        <div class="page-header d-print-none">
            <div class="row align-items-center">
                <div class="col">
                    <div class="page-pretitle">
                        IP Address
                    </div>
                    <h2 class="page-title">
                        {{ $ip->address }}
                    </h2>
                </div>
                <div class="col-auto ms-auto">
                    <div class="btn-list">
                        @if ($ip->allowed)
                            <button class="btn btn-outline-danger d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#enableModal">
                                <i class="icon ti ti-world-off"></i>
                                Disable Internet
                            </button>
                        @else
                            <button class="btn btn-outline-success d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#enableModal">
                                <i class="icon ti ti-world"></i>
                                Enable Internet
                            </button>
                        @endif
                        @if ($ip->limited)
                            <button class="btn btn-outline-success d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#ratelimitModal">
                                <i class="icon ti ti-brand-speedtest"></i>
                                Remove Rate Limit
                            </button>
                        @else
                            <button class="btn btn-outline-danger d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#ratelimitModal">
                                <i class="icon ti ti-brand-speedtest"></i>
                                Apply Rate Limit
                            </button>
                        @endif
                        @if ($port)
                            @if ($shutdown)
                                <button class="btn btn-outline-success d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#portModal">
                                    <i class="icon ti ti-plug-connected"></i>
                                    Enable Port
                                </button>
                            @else
                                <button class="btn btn-outline-danger d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#portModal">
                                    <i class="icon ti ti-plug-connected-x"></i>
                                    Disable Port
                                </button>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('content')
    <div class="row row-cards row-deck mb-5">
        <div class="col">
            <div class="card">
                <div class="card-body">
                    <div class="datagrid">
                        <div class="datagrid-item">
                            <div class="datagrid-title">Address</div>
                            <div class="datagrid-content">{{ $ip->address }}</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">MAC Address</div>
                            <div class="datagrid-content">{{ $ip->mac ?? 'Unknown' }}</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Internet Access</div>
                            <div class="datagrid-content {{ $ip->allowed ? 'text-success' : 'text-danger' }}">
                                {{ $ip->allowed ? 'Yes' : 'No' }}
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Rate Limited</div>
                            <div class="datagrid-content {{ $ip->limited ? 'text-danger' : 'text-success' }}">
                                {{ $ip->limited ? 'Yes' : 'No' }}
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">User</div>
                            <div class="datagrid-content">
                                @if ($users->count() > 0)
                                    <a href="{{ route('admin.users.show', ['user' => $users[0]->user->id]) }}">{{ $users[0]->user->nickname }}</a>
                                @else
                                    -
                                @endif
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Notes</div>
                            <div class="datagrid-content">{{ $ip->comment ?? '-' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex">
        <h3>Users</h3>
    </div>

    <div class="row row-cards row-deck mb-5">
        <div class="col">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-striped">
                        <thead>
                            <tr>
                                <th>Nickname</th>
                                <th>Last Login</th>
                                <th>Blocked</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $user)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.users.show', ['user' => $user->user->id]) }}">
                                            {{ $user->user->nickname }}
                                        </a>
                                    </td>
                                    <td>{{ $user->last_seen_at->format('H:i:s d-M-Y') }}</td>
                                    <td class="{{ $user->user->blocked ? 'text-danger' : 'text-success' }}">
                                        {{ $user->user->blocked ? 'Yes' : 'No' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @if ($port)

        <div class="d-flex">
            <h3>Network Port</h3>
        </div>

        <div class="row row-cards row-deck mb-5">
            <div class="col">
                <div class="card">
                    <div class="card-body">
                        <div class="datagrid">
                            <div class="datagrid-item">
                                <div class="datagrid-title">Switch</div>
                                <div class="datagrid-content">{{ $port->switch }}</div>
                            </div>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Interface</div>
                                <div class="datagrid-content">{{ $port->interface }}</div>
                            </div>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Port Status</div>
                                <div class="datagrid-content {{ $port->status === 'up' ? 'text-success' : 'text-danger' }}">
                                    {{ $port->status === 'up' ? 'Up' : 'Down' }}
                                </div>
                            </div>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Admin Status</div>
                                <div class="datagrid-content {{ $shutdown ? 'text-danger' : 'text-success' }}">
                                    {{ $shutdown ? 'Down' : 'Up' }}
                                </div>
                            </div>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Port Speed</div>
                                <div class="datagrid-content">
                                    {{ $port->speed / 1000000 }}M
                                </div>
                            </div>
                            <div class="datagrid-item">
                                <div class="datagrid-title">Updated At</div>
                                <div class="datagrid-content">{{ $ip->portUpdatedAt->format('H:i:s d-M-Y') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row row-cards row-deck mb-5">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body p-0 position-relative">
                        <pre class="p-1 h-100"><code>{{ $status }}</code></pre>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body p-0 position-relative">
                        <pre class="p-1 h-100"><code>{{ $config }}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
@section('footer')
    <div class="modal" id="enableModal" tabindex="-1">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-status bg-{{ $ip->allowed ? 'danger' : 'success' }}"></div>
                <div class="modal-body text-center py-4">
                    <i class="icon icon-lg ti ti-world text-{{ $ip->allowed ? 'danger' : 'success' }}"></i>
                    <h3>Are you sure?</h3>
                    <div class="text-secondary">Do you want to {{ $ip->allowed ? 'disable' : 'enable' }} internet for <strong>{{ $ip->address }}</strong>?</div>
                </div>
                <div class="modal-footer">
                    <div class="w-100">
                        <div class="row">
                            <div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">
                                    Cancel
                                </a></div>
                            <div class="col">
                                <form action="{{ route('admin.ips.internet', ['ip' => $ip]) }}" method="post">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="allow" value="{{ (int)!$ip->allowed }}" />
                                    <input type="submit" class="btn btn-{{ $ip->allowed ? 'danger' : 'success' }} w-100" data-bs-dismiss="modal" value="{{ $ip->allowed ? 'Disable' : 'Enable' }} Internet">
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="ratelimitModal" tabindex="-1">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-status bg-{{ $ip->limited ? 'success' : 'danger' }}"></div>
                <div class="modal-body text-center py-4">
                    <i class="icon icon-lg ti ti-brand-speedtest text-{{ $ip->limited ? 'success' : 'danger' }}"></i>
                    <h3>Are you sure?</h3>
                    <div class="text-secondary">Do you want to {{ $ip->limited ? 'remove the rate limit for' : 'apply a rate limit to' }} <strong>{{ $ip->address }}</strong>?</div>
                </div>
                <div class="modal-footer">
                    <div class="w-100">
                        <div class="row">
                            <div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">
                                    Cancel
                                </a></div>
                            <div class="col">
                                <form action="{{ route('admin.ips.limit', ['ip' => $ip]) }}" method="post">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="limit" value="{{ (int)!$ip->limited }}" />
                                    <input type="submit" class="btn btn-{{ $ip->limited ? 'success' : 'danger' }} w-100" data-bs-dismiss="modal" value="{{ $ip->limited ? 'Remove' : 'Apply' }} Rate Limit">
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($port)
        <div class="modal" id="portModal" tabindex="-1">
            <div class="modal-dialog modal-sm" role="document">
                <div class="modal-content">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <div class="modal-status bg-{{ $shutdown ? 'success' : 'danger' }}"></div>
                    <div class="modal-body text-center py-4">
                        <i class="icon icon-lg ti {{ $shutdown ? 'ti-plug-connected text-success' : 'ti-plug-connected-x text-danger' }}"></i>
                        <h3>Are you sure?</h3>
                        <div class="text-secondary">Do you want to {{ $shutdown ? 'enable' : 'disable' }} <strong>{{ $port->interface }}</strong> ?</div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">
                                        Cancel
                                    </a></div>
                                <div class="col">
                                    <form action="{{ route('admin.ips.port', ['ip' => $ip]) }}" method="post">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="shutdown" value="{{ (int)!$shutdown }}" />
                                        <input type="submit" class="btn btn-{{ $shutdown ? 'success' : 'danger' }} w-100" data-bs-dismiss="modal" value="{{ $shutdown ? 'Enable' : 'Disable' }} {{ $port->interface }}">
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection
