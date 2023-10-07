<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\IpAddressAction;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = (object) [
            'perPage' => $request->input('perPage', 20),
            'nickname' => $request->input('nickname', ''),
            'ip' => $request->input('ip', ''),
        ];
        $query = User::query()->with('ips.ip')->with('roles');

        if ($filters->nickname) {
            $query = $query->where('nickname', $filters->nickname);
        }
        if ($filters->ip) {
            $query = $query->whereHas('ips.ip', function($query) use ($filters) {
                return $query->where('address', $filters->ip);
            });
        }

        $users = $query->paginate($filters->perPage)->appends((array) $filters);
        return view('admin.users.index', [
            'users' => $users,
            'filters' => $filters,
        ]);
    }

    public function show(User $user)
    {
        $ips = $user->ips()->with('ip')->get();
        $roles = $user->roles()->get();
        $auths = $user->authentications()->with('provider')->get();
        return view('admin.users.show', [
            'user' => $user,
            'roles' => $roles,
            'ips' => $ips,
            'auths' => $auths,
        ]);
    }

    public function block(Request $request, User $user)
    {
        $user->blocked = (bool)$request->input('block');
        $user->save();
        if ($user->blocked) {
            $message = 'The user will be blocked from accessing the Internet from new IPs';
        } else {
            $message = 'The user will be unblocked from accessing the Internet from new IPs';
        }
        return response()->redirectToRoute('admin.users.show', ['user' => $user->id])->with('successMessage', $message);
    }
}
