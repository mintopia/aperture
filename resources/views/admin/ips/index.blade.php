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
            </div>
        </div>
    </div>
@endsection
@section('content')
    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="table-responsive">
                    <table class="table-responsive">
                        <table class="table table-vcenter card-table table-striped">
                            <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>User</th>
                                <th>Last Seen</th>
                                <th>Internet Access</th>
                                <th>Rate Limited</th>
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