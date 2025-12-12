import { useEffect, useState } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

type User = {
    id: number;
    name: string;
    email: string;
    role: string;
    created_at: string;
};

type Role = {
    id: number;
    name: string;
    slug: string;
    permissions: { id: number; slug: string }[];
};

type Permission = {
    id: number;
    name: string;
    slug: string;
};

type PageProps = {
    tab: 'users' | 'roles' | 'permissions' | string;
    users: User[];
    roles: Role[];
    permissions: Permission[];
};

export default function AdminHub({ tab, users, roles, permissions }: PageProps) {
    const safeUsers = Array.isArray(users) ? users : [];
    const safeRoles = Array.isArray(roles) ? roles : [];
    const safePermissions = Array.isArray(permissions) ? permissions : [];

    const { props } = usePage();
    const flash = (props as any).flash || {};
    const [activeTab, setActiveTab] = useState<'users' | 'roles' | 'permissions'>(
        tab === 'roles' || tab === 'permissions' ? tab : 'users',
    );
    const [modalOpen, setModalOpen] = useState(false);
    const [editContext, setEditContext] = useState<
        | { type: 'users'; id: number }
        | { type: 'roles'; id: number }
        | { type: 'permissions'; id: number }
        | null
    >(null);

    const userCreateForm = useForm({
        name: '',
        email: '',
        password: '',
        role: safeRoles[0]?.slug ?? 'client',
    });

    const roleCreateForm = useForm({
        name: '',
        slug: '',
    });

    const permissionCreateForm = useForm({
        name: '',
        slug: '',
    });

    const [userRolesState, setUserRolesState] = useState<Record<number, string>>(() =>
        Object.fromEntries(safeUsers.map((u) => [u.id, u.role])),
    );
    const [rolePermsState, setRolePermsState] = useState<Record<number, string[]>>(() =>
        Object.fromEntries(safeRoles.map((r) => [r.id, r.permissions.map((p) => p.slug)])),
    );

    useEffect(() => {
        setUserRolesState(Object.fromEntries(safeUsers.map((u) => [u.id, u.role])));
    }, [safeUsers]);

    useEffect(() => {
        setRolePermsState(Object.fromEntries(safeRoles.map((r) => [r.id, r.permissions.map((p) => p.slug)])));
    }, [safeRoles]);

    const topButtonLabel =
        editContext?.type === 'users'
            ? 'Edit user'
            : editContext?.type === 'roles'
              ? 'Edit role'
              : editContext?.type === 'permissions'
                ? 'Edit permission'
                : activeTab === 'users'
                  ? 'Add user'
                  : activeTab === 'roles'
                    ? 'Add role'
                    : 'Add permission';

    const pageTitle = activeTab === 'roles' ? 'Roles' : activeTab === 'permissions' ? 'Permissions' : 'Users';

    const handleModalSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editContext?.type === 'users') {
            userCreateForm.patch(`/admin/users/${editContext.id}`, {
                onSuccess: () => {
                    setModalOpen(false);
                    setEditContext(null);
                    userCreateForm.reset('password');
                },
            });
        } else if (editContext?.type === 'roles') {
            roleCreateForm.patch(`/admin/roles/${editContext.id}`, {
                onSuccess: () => {
                    setModalOpen(false);
                    setEditContext(null);
                    roleCreateForm.reset();
                },
            });
        } else if (editContext?.type === 'permissions') {
            permissionCreateForm.patch(`/admin/permissions/${editContext.id}`, {
                onSuccess: () => {
                    setModalOpen(false);
                    setEditContext(null);
                    permissionCreateForm.reset();
                },
            });
        } else if (activeTab === 'users') {
            userCreateForm.post('/admin/users', {
                onSuccess: () => {
                    setModalOpen(false);
                    userCreateForm.reset();
                },
            });
        } else if (activeTab === 'roles') {
            roleCreateForm.post('/admin/roles', {
                onSuccess: () => {
                    setModalOpen(false);
                    roleCreateForm.reset();
                },
            });
        } else {
            permissionCreateForm.post('/admin/permissions', {
                onSuccess: () => {
                    setModalOpen(false);
                    permissionCreateForm.reset();
                },
            });
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Admin Hub', href: '/admin/hub' }]}>
            <Head title="Admin Hub" />

            <div className="flex items-start justify-between gap-4 px-6 pt-6 pb-4 mb-2">
                <div>
                    <h1 className="text-2xl font-semibold">{pageTitle}</h1>
                    {flash.success && (
                        <div className="mt-2 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                            {flash.success}
                        </div>
                    )}
                    {flash.error && (
                        <div className="mt-2 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {flash.error}
                        </div>
                    )}
                </div>

            <div className="flex items-center justify-end gap-3">
                <button
                    type="button"
                    className="inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground shadow-sm hover:opacity-90"
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setEditContext(null);
                        userCreateForm.reset();
                        roleCreateForm.reset();
                        permissionCreateForm.reset();
                        setModalOpen(true);
                    }}
                >
                    {topButtonLabel}
                </button>
            </div>

            {modalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-lg bg-card p-4 shadow-lg">
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-lg font-semibold">{topButtonLabel}</h2>
                            <button
                                type="button"
                                className="rounded-md px-2 py-1 text-sm hover:bg-muted"
                                onClick={() => setModalOpen(false)}
                            >
                                Close
                            </button>
                        </div>
                        <form className="space-y-3" onSubmit={handleModalSubmit}>
                            {activeTab === 'users' && (
                                <>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Name</label>
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={userCreateForm.data.name}
                                            onChange={(e) => userCreateForm.setData('name', e.target.value)}
                                            required
                                        />
                                        {userCreateForm.errors.name && (
                                            <p className="text-xs text-rose-600">{userCreateForm.errors.name}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Email</label>
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={userCreateForm.data.email}
                                            onChange={(e) => userCreateForm.setData('email', e.target.value)}
                                            required
                                            type="email"
                                        />
                                        {userCreateForm.errors.email && (
                                            <p className="text-xs text-rose-600">{userCreateForm.errors.email}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Password</label>
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={userCreateForm.data.password}
                                            onChange={(e) => userCreateForm.setData('password', e.target.value)}
                                            type="password"
                                        />
                                        {userCreateForm.errors.password && (
                                            <p className="text-xs text-rose-600">{userCreateForm.errors.password}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Role</label>
                                        <select
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={userCreateForm.data.role}
                                            onChange={(e) => userCreateForm.setData('role', e.target.value)}
                                        >
                                            {roles.map((role) => (
                                                <option key={role.slug} value={role.slug}>
                                                    {role.slug}
                                                </option>
                                            ))}
                                        </select>
                                        {userCreateForm.errors.role && (
                                            <p className="text-xs text-rose-600">{userCreateForm.errors.role}</p>
                                        )}
                                    </div>
                                </>
                            )}

                            {activeTab === 'roles' && (
                                <>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Name</label>
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={roleCreateForm.data.name}
                                            onChange={(e) => roleCreateForm.setData('name', e.target.value)}
                                            required
                                        />
                                        {roleCreateForm.errors.name && (
                                            <p className="text-xs text-rose-600">{roleCreateForm.errors.name}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Slug</label>
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={roleCreateForm.data.slug}
                                            onChange={(e) => roleCreateForm.setData('slug', e.target.value)}
                                            required
                                        />
                                        {roleCreateForm.errors.slug && (
                                            <p className="text-xs text-rose-600">{roleCreateForm.errors.slug}</p>
                                        )}
                                    </div>
                                </>
                            )}

                            {activeTab === 'permissions' && (
                                <>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Name</label>
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={permissionCreateForm.data.name}
                                            onChange={(e) => permissionCreateForm.setData('name', e.target.value)}
                                            required
                                        />
                                        {permissionCreateForm.errors.name && (
                                            <p className="text-xs text-rose-600">{permissionCreateForm.errors.name}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-sm font-medium">Slug</label>
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-sm"
                                            value={permissionCreateForm.data.slug}
                                            onChange={(e) => permissionCreateForm.setData('slug', e.target.value)}
                                            required
                                        />
                                        {permissionCreateForm.errors.slug && (
                                            <p className="text-xs text-rose-600">{permissionCreateForm.errors.slug}</p>
                                        )}
                                    </div>
                                </>
                            )}

                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                                    disabled={
                                        userCreateForm.processing ||
                                        roleCreateForm.processing ||
                                        permissionCreateForm.processing
                                    }
                                >
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>

            <div className="space-y-4 px-6 pb-6">
            {activeTab === 'users' && (
                <div className="rounded-lg border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                    <div className="overflow-hidden rounded-md border border-border/60">
                        <table className="min-w-full divide-y divide-border/60 text-sm">
                            <thead className="bg-muted/40">
                                <tr>
                                    <th className="px-4 py-2 text-left font-medium">Name</th>
                                    <th className="px-4 py-2 text-left font-medium">Email</th>
                                    <th className="px-4 py-2 text-left font-medium">Role</th>
                                    <th className="px-4 py-2 text-left font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/60">
                                {safeUsers.map((user) => (
                                    <tr key={user.id}>
                                        <td className="px-4 py-2">{user.name}</td>
                                        <td className="px-4 py-2">{user.email}</td>
                                        <td className="px-4 py-2">
                                            <div className="flex items-center gap-2">
                                                <select
                                                    className="rounded-md border px-2 py-1 text-sm"
                                                    value={userRolesState[user.id] ?? user.role}
                                                    onChange={(e) =>
                                                        setUserRolesState((prev) => ({
                                                            ...prev,
                                                            [user.id]: e.target.value,
                                                        }))
                                                    }
                                                >
                                                    {safeRoles.map((role) => (
                                                        <option key={role.slug} value={role.slug}>
                                                            {role.slug}
                                                        </option>
                                                    ))}
                                                </select>
                                                <button
                                                    type="button"
                                                    className="rounded-md bg-primary px-2 py-1 text-xs font-medium text-primary-foreground hover:opacity-90"
                                                    onClick={() =>
                                                        router.patch(`/admin/users/${user.id}/role`, {
                                                            role: userRolesState[user.id] ?? user.role,
                                                        })
                                                    }
                                                >
                                                    Save
                                                </button>
                                            </div>
                                        </td>
                                        <td className="px-4 py-2">
                                            <button
                                                type="button"
                                                className="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-200"
                                                onClick={() => {
                                                    if (!confirm(`Delete user ${user.name}? This cannot be undone.`)) {
                                                        return;
                                                    }
                                                    router.delete(`/admin/users/${user.id}`);
                                                }}
                                            >
                                                Delete
                                            </button>
                                            <button
                                                type="button"
                                                className="ml-2 rounded-md bg-muted px-2 py-1 text-xs font-medium hover:bg-muted/80"
                                                onClick={() => {
                                                    setActiveTab('users');
                                                    setEditContext({ type: 'users', id: user.id });
                                                    userCreateForm.setData({
                                                        name: user.name,
                                                        email: user.email,
                                                        role: user.role,
                                                        password: '',
                                                    });
                                                    setModalOpen(true);
                                                }}
                                            >
                                                Edit
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {safeUsers.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-3 text-sm text-muted-foreground" colSpan={4}>
                                            No users found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {activeTab === 'roles' && (
                <div className="space-y-3">
                    {safeRoles.map((role) => (
                        <div
                            key={role.id}
                            className="rounded-lg border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">{role.name}</h2>
                                    <p className="text-sm text-muted-foreground">{role.slug}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    {role.slug !== 'super_admin' && (
                                        <button
                                            type="button"
                                            className="rounded-md bg-muted px-2 py-1 text-xs font-medium hover:bg-muted/80"
                                            onClick={() => {
                                                setActiveTab('roles');
                                                setEditContext({ type: 'roles', id: role.id });
                                                roleCreateForm.setData({
                                                    name: role.name,
                                                    slug: role.slug,
                                                });
                                                setModalOpen(true);
                                            }}
                                        >
                                            Edit
                                        </button>
                                    )}
                                    {role.slug !== 'super_admin' && (
                                        <button
                                            type="button"
                                            className="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-200"
                                            onClick={() => {
                                                if (!confirm(`Delete role ${role.name}?`)) return;
                                                router.delete(`/admin/roles/${role.id}`);
                                            }}
                                        >
                                            Delete
                                        </button>
                                    )}
                                </div>
                            </div>

                            <div className="mt-3 space-y-2">
                                <p className="text-sm font-medium">Permissions</p>
                                <form
                                    className="space-y-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        router.post(`/admin/roles/${role.id}/permissions`, {
                                            permissions: rolePermsState[role.id] ?? [],
                                        });
                                    }}
                                >
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {safePermissions.map((perm) => {
                                            const current = rolePermsState[role.id] ?? [];
                                            const checked = current.includes(perm.slug);
                                            return (
                                                <label
                                                    key={perm.id}
                                                    className="flex items-center gap-2 rounded border border-border/60 px-2 py-1 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        onChange={(e) => {
                                                            setRolePermsState((prev) => {
                                                                const existing = prev[role.id] ?? [];
                                                                return {
                                                                    ...prev,
                                                                    [role.id]: e.target.checked
                                                                        ? [...existing, perm.slug]
                                                                        : existing.filter((p) => p !== perm.slug),
                                                                };
                                                            });
                                                        }}
                                                    />
                                                    <span>{perm.slug}</span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                    <button
                                        type="submit"
                                        className="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                                    >
                                        Save permissions
                                    </button>
                                </form>
                            </div>
                        </div>
                    ))}
                    {safeRoles.length === 0 && (
                        <div className="rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                            No roles yet.
                        </div>
                    )}
                </div>
            )}

            {activeTab === 'permissions' && (
                <div className="space-y-2">
                    {safePermissions.map((perm) => (
                        <div
                            key={perm.id}
                            className="flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-card px-4 py-3 shadow-sm dark:border-sidebar-border"
                        >
                            <div>
                                <p className="text-sm font-semibold">{perm.name}</p>
                                <p className="text-xs text-muted-foreground">{perm.slug}</p>
                            </div>
                            <button
                                type="button"
                                className="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-200"
                                onClick={() => {
                                    if (!confirm(`Delete permission ${perm.name}?`)) return;
                                    router.delete(`/admin/permissions/${perm.id}`);
                                }}
                            >
                                Delete
                            </button>
                            <button
                                type="button"
                                className="ml-2 rounded-md bg-muted px-2 py-1 text-xs font-medium hover:bg-muted/80"
                                onClick={() => {
                                    setActiveTab('permissions');
                                    setEditContext({ type: 'permissions', id: perm.id });
                                    permissionCreateForm.setData({
                                        name: perm.name,
                                        slug: perm.slug,
                                    });
                                    setModalOpen(true);
                                }}
                            >
                                Edit
                            </button>
                        </div>
                    ))}
                    {safePermissions.length === 0 && (
                        <div className="rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                            No permissions yet.
                        </div>
                    )}
                </div>
            )}
            </div>
        </AppLayout>
    );
}

