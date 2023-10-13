@extends('layout.admin')

@section('header')
    <div class="container-xl">
        <div class="page-header d-print-none">
            <div class="row align-items-center">
                <div class="col">
                    <div class="page-pretitle">
                        User
                    </div>
                    <h2 class="page-title">
                        {{ $user->nickname }}
                    </h2>
                </div>
                <div class="col-auto ms-auto">
                    <div class="btn-list">
                        @if ($user->blocked)
                            <button class="btn btn-outline-success d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#blockModal">
                                <i class="icon ti ti-world"></i>
                                Unblock Internet
                            </button>
                        @else
                            <button class="btn btn-outline-danger d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#blockModal">
                                <i class="icon ti ti-world-off"></i>
                                Block Internet
                            </button>
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
                            <div class="datagrid-title">Nickname</div>
                            <div class="datagrid-content">{{ $user->nickname }}</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Email</div>
                            <div class="datagrid-content">{{ $user->email ?? '-' }}</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Internet Blocked</div>
                            <div class="datagrid-content {{ $user->blocked ? 'text-danger' : 'text-success' }}">
                                {{ $user->blocked ? 'Yes' : 'No' }}
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">IP</div>
                            <div class="datagrid-content">
                                @if ($ips->count() > 0)
                                    <a href="{{ route('admin.ips.show', ['ip' => $ips[0]->ip->id]) }}">
                                        {{ $ips[0]->ip->address }}
                                    </a>
                                @else
                                    None
                                @endif
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Roles</div>
                            <div class="datagrid-content">
                                @foreach ($roles as $role)
                                    <span class="status status-primary">{{ $role->name }}</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Downloaded</div>
                            <div class="datagrid-content">{{ Helper::humanSize($downloaded) }}</div>
                        </div>
                        <div class="datagrid-item">
                            <div class="datagrid-title">Uploaded</div>
                            <div class="datagrid-content">{{ Helper::humanSize($uploaded) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex">
        <h3>IP Addresses</h3>
    </div>

    <div class="row row-cards row-deck mb-5">
        <div class="col">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-striped">
                        <thead>
                        <tr>
                            <th>Address</th>
                            <th>Last Login</th>
                            <th>Internet Access</th>
                            <th>Rate Limited</th>
                            <th>Downloaded</th>
                            <th>Uploaded</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($ips as $ip)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.ips.show', ['ip' => $ip->ip->id]) }}">
                                        {{ $ip->ip->address }}
                                    </a>
                                </td>
                                <td>{{ $ip->last_seen_at->format('H:i:s d-M-Y') }}</td>
                                <td class="text-{{ $ip->ip->allowed ? 'success' : 'danger' }}">{{ $ip->ip->allowed ? 'Yes' : 'No' }}</td>
                                <td class="text-{{ $ip->ip->limited ? 'danger' : 'success' }}">{{ $ip->ip->limited ? 'Yes' : 'No' }}</td>
                                <td>{{ Helper::humanSize($ip->ip->received) }}</td>
                                <td>{{ Helper::humanSize($ip->ip->sent) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex">
        <h3>Authentication</h3>
    </div>

    <div class="row row-cards row-deck mb-5">
        <div class="col">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-vcenter card-table table-striped">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($auths as $auth)
                                <tr>
                                    <td>{{ $auth->provider->name }}</td>
                                    <td>{{ $auth->external_id }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('footer')
    <div class="modal" id="blockModal" tabindex="-1">
        <div class="modal-dialog modal-sm" role="document">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-status bg-{{ $user->blocked ? 'success' : 'danger' }}"></div>
                <div class="modal-body text-center py-4">
                    <i class="icon icon-lg ti ti-world text-{{ $user->blocked ? 'success' : 'danger' }}"></i>
                    <h3>Are you sure?</h3>
                    <div class="text-secondary">Do you want to {{ $user->blocked ? 'unblock' : 'block' }} internet access for <strong>{{ $user->nickname }}</strong>?</div>
                </div>
                <div class="modal-footer">
                    <div class="w-100">
                        <div class="row">
                            <div class="col"><a href="#" class="btn w-100" data-bs-dismiss="modal">
                                    Cancel
                                </a></div>
                            <div class="col">
                                <form action="{{ route('admin.users.block', ['user' => $user]) }}" method="post">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="block" value="{{ (int)!$user->blocked }}" />
                                    <input type="submit" class="btn btn-{{ $user->blocked ? 'success' : 'danger' }} w-100" data-bs-dismiss="modal" value="{{ $user->blocked ? 'Unblock' : 'Block' }} Internet">
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
