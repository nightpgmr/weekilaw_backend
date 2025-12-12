<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users', [
            'users' => User::select('id', 'name', 'email', 'role', 'created_at')
                ->orderByDesc('created_at')
                ->get(),
            'roles' => Role::orderBy('slug')->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'exists:roles,slug'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        if ($role = Role::where('slug', $validated['role'])->first()) {
            $user->roles()->sync([$role->id]);
        }

        return redirect()->back()->with('success', 'User created.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'exists:roles,slug'],
        ]);

        if ($request->user()->id === $user->id && $validated['role'] !== 'super_admin') {
            return redirect()->back()->with('error', 'Cannot change your own role below super_admin.');
        }

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
        ];

        if (! empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
        }

        $user->update($updateData);

        if ($role = Role::where('slug', $validated['role'])->first()) {
            $user->roles()->sync([$role->id]);
        }

        return redirect()->back()->with('success', 'User updated.');
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'exists:roles,slug'],
        ]);

        // prevent demoting or changing self accidentally
        if ($request->user()->id === $user->id && $validated['role'] !== 'super_admin') {
            return redirect()->back()->with('error', 'Cannot change your own role below super_admin.');
        }

        $user->update(['role' => $validated['role']]);
        if ($role = Role::where('slug', $validated['role'])->first()) {
            $user->roles()->sync([$role->id]);
        }

        return redirect()->back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->id === $user->id) {
            return redirect()->back()->with('error', 'You cannot delete yourself.');
        }

        $user->delete();

        return redirect()->back()->with('success', 'User deleted.');
    }
}

