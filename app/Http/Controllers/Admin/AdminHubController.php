<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminHubController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->query('tab', 'users');

        return Inertia::render('admin/hub', [
            'tab' => $tab,
            'users' => User::select('id', 'name', 'email', 'role', 'created_at')
                ->orderByDesc('created_at')
                ->get(),
            'roles' => Role::with('permissions:id,slug')
                ->orderBy('slug')
                ->get(['id', 'name', 'slug']),
            'permissions' => Permission::orderBy('slug')->get(['id', 'name', 'slug']),
        ]);
    }
}


