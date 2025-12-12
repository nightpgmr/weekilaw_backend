<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/permissions', [
            'permissions' => Permission::orderBy('slug')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:permissions,slug'],
        ]);

        Permission::create($validated);

        return redirect()->back()->with('success', 'Permission created.');
    }

    public function update(Request $request, Permission $permission): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:permissions,slug,'.$permission->id],
        ]);

        $permission->update($validated);

        return redirect()->back()->with('success', 'Permission updated.');
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        $permission->delete();

        return redirect()->back()->with('success', 'Permission deleted.');
    }
}

