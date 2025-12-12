<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\AdminHubController;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::get('/home', function () {
    $user = request()->user();

    if (! $user) {
        return redirect()->route('home');
    }

    return $user->isAdmin()
        ? redirect()->route('dashboard')
        : redirect()->route('client.home');
})->middleware('auth')->name('redirect.home');

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::get('dashboard', function () {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");

        $userCounts = [
            'total' => User::count(),
            'admins' => User::where('role', 'admin')->count(),
            'super_admins' => User::where('role', 'super_admin')->count(),
            'clients' => User::where('role', 'client')->count(),
        ];

        $stats = [
            'counts' => [
                'users' => $userCounts['total'],
                'admins' => $userCounts['admins'],
                'super_admins' => $userCounts['super_admins'],
                'clients' => $userCounts['clients'],
                'roles' => Role::count(),
                'permissions' => Permission::count(),
            ],
            'db' => [
                'connection' => $connection,
                'database' => $database,
                'host' => $host,
            ],
        ];

        return Inertia::render('dashboard', [
            'stats' => $stats,
            'recentUsers' => User::latest()
                ->limit(5)
                ->get(['id', 'name', 'email', 'role', 'created_at']),
            'recentRoles' => Role::withCount('permissions')
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'slug', 'created_at']),
            'recentPermissions' => Permission::latest()
                ->limit(5)
                ->get(['id', 'name', 'slug', 'created_at']),
        ]);
    })->name('dashboard');
});

Route::middleware(['auth', 'client'])->group(function () {
    Route::get('client', function () {
        return Inertia::render('client/home');
    })->name('client.home');
});

Route::middleware(['auth', 'verified', 'superadmin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('users', [UserManagementController::class, 'store'])->name('users.store');
        Route::patch('users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::patch('users/{user}/role', [UserManagementController::class, 'updateRole'])->name('users.role');
        Route::delete('users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

        Route::get('roles', [RoleController::class, 'index'])->name('roles');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->name('roles.permissions');

        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions');
        Route::post('permissions', [PermissionController::class, 'store'])->name('permissions.store');
        Route::patch('permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update');
        Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy');

        Route::get('hub', [AdminHubController::class, 'index'])->name('hub');
});

require __DIR__.'/settings.php';
