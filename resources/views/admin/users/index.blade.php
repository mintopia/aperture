@extends('layout.admin')
@section('header')
    <div class="container-xl">
        <div class="page-header d-print-none">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">
                        Users
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
                                    <th>Nickname</th>
                                    <th>IP</th>
                                    <th>Blocked</th>
                                    <th>Roles</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($users as $user)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.users.show', ['user' => $user->id]) }}">
                                            {{ $user->nickname }}
                                        </a>
                                    </td>
                                    <td>
                                        @if ($user->ips->count() > 0)
                                            <a href="{{ route('admin.ips.show', ['ip' => $user->ips[0]->ip->id]) }}">
                                                {{ $user->ips[0]->ip->address }}
                                            </a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="text-{{ $user->blocked ? 'danger' : 'success' }}">{{ $user->blocked ? 'Yes' : 'No' }}</td>
                                    <td>
                                        @foreach($user->roles as $role)
                                            <span class="status status-primary">{{ $role->name }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </table>
                </div>
                @include('partials._pagination', [
                    'page' => $users
                ])
            </div>
        </div>
@endsection
