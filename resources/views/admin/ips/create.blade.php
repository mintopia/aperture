@extends('layout.admin')

@section('header')
    <div class="container-xl">
        <div class="page-header d-print-none">
            <div class="row align-items-center">
                <div class="col">
                    <div class="page-pretitle">
                        IP Addresses
                    </div>
                    <h2 class="page-title">
                        Add New
                    </h2>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('content')
    <div class="row row-cards">
        <div class="col-md-12">
            <form class="card" action="{{ route('admin.ips.store') }}" method="post">
                <div class="card-body">
                    @include('admin.ips.forms._form')
                </div>
                <div class="card-footer text-end">
                    <div class="d-flex">
                        <a href="{{ route('admin.ips.index') }}" class="btn btn-link">Cancel</a>
                        <button type="submit" class="btn btn-primary ms-auto">Submit</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
