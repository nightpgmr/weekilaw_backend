<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/roles', [
            'roles' => Role::with('permissions:id,slug')
                ->orderBy('slug')
                ->get(['id', 'name', 'slug']),
            'permissions' => Permission::orderBy('slug')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:roles,slug'],
        ]);

        Role::create($validated);

        return redirect()->back()->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:roles,slug,'.$role->id],
        ]);

        if ($role->slug === 'super_admin' && $validated['slug'] !== 'super_admin') {
            return redirect()->back()->with('error', 'Cannot rename super_admin role.');
        }

        $role->update($validated);

        return redirect()->back()->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->slug === 'super_admin') {
            return redirect()->back()->with('error', 'Cannot delete super_admin role.');
        }

        $role->delete();

        return redirect()->back()->with('success', 'Role deleted.');
    }

    public function syncPermissions(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['exists:permissions,slug'],
        ]);

        $ids = Permission::whereIn('slug', $validated['permissions'] ?? [])->pluck('id')->all();

        $role->permissions()->sync($ids);

        return redirect()->back()->with('success', 'Permissions updated.');
    }
}

