@extends('layout.admin')
@section('header')
    <div class="container-xl">
        <div class="page-header d-print-none">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        IP Addresses
                    </h2>
                </div>

                <div class="col-auto ms-auto d-print-none">
                    <div class="btn-list">
                        <a href="{{ route('admin.ips.create') }}" class="btn btn-primary d-none d-sm-inline-block">
                            <i class="icon ti ti-plus"></i>
                            Add New
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('content')
    <div class="row">

        <div class="col-md-12 col-lg-3">
            <form action="{{ route('admin.ips.index') }}" method="get" class="card">
                <div class="card-header">
                    <h3 class="card-title">Search</h3>
                </div>
                <div class="card-body">
                    @include('admin.ips.forms._search')
                </div>
                <div class="card-footer text-right">
                    <button class="btn btn-primary ml-auto" type="submit">Search</button>
                </div>
            </form>
        </div>

        <div class="col-lg-9 col-md-12">
            <div class="card">
                <div class="table-responsive">
                    <table class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                            <tr>
                                <th>
                                    @include('partials._sortheader', [
                                        'title' => 'IP Address',
                                        'field' => 'address',
                                    ])
                                </th>
                                <th>User</th>
                                <th>
                                    @include('partials._sortheader', [
                                        'title' => 'Last Seen',
                                        'field' => 'last_seen_at',
                                    ])
                                </th>
                                <th>
                                    @include('partials._sortheader', [
                                        'title' => 'Down',
                                        'field' => 'received',
                                    ])
                                </th>
                                <th>
                                    @include('partials._sortheader', [
                                        'title' => 'Up',
                                        'field' => 'sent',
                                    ])
                                </th>
                                <th>
                                    @include('partials._sortheader', [
                                        'title' => 'Internet',
                                        'field' => 'allowed',
                                    ])
                                </th>
                                <th>
                                    @include('partials._sortheader', [
                                        'title' => 'Limited',
                                        'field' => 'limited',
                                    ])
                                </th>
                                <th>Comment</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($ips as $ip)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.ips.show', ['ip' => $ip->id]) }}">
                                            {{ $ip->address }}
                                        </a>
                                    </td>
                                    <td>
                                        @if ($ip->users->count() > 0)
                                            <a href="{{ route('admin.users.show', ['user' => $ip->users[0]->user->id]) }}">
                                                {{ $ip->users[0]->user->nickname }}
                                            </a>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($ip->users->count() > 0)
                                            {{ $ip->users[0]->last_seen_at->format('H:i:s d M Y') }}
                                        @endif
                                    </td>
                                    <td>{{ Helper::humanSize($ip->received) }}</td>
                                    <td>{{ Helper::humanSize($ip->sent) }}</td>
                                    <td class="text-{{ $ip->allowed ? 'success' : 'danger' }}">{{ $ip->allowed ? 'Yes' : 'No' }}</td>
                                    <td class="text-{{ $ip->limited ? 'danger' : 'success' }}">{{ $ip->limited ? 'Yes' : 'No' }}</td>
                                    <td>{{ $ip->comment }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </table>
                </div>
                @include('partials._pagination', [
                    'page' => $ips
                ])
            </div>
        </div>
@endsection
