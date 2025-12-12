import { Head, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type User = {
    id: number;
    name: string;
    email: string;
    role: string;
    created_at: string;
};

type RoleOption = {
    id: number;
    name: string;
    slug: string;
};

type PageProps = {
    users: User[];
    roles: RoleOption[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Users', href: '/admin/users' },
];

export default function Users({ users, roles }: PageProps) {
    const { props } = usePage();
    const flash = (props as any).flash || {};
    const [open, setOpen] = useState(false);
    const [roleState, setRoleState] = useState<Record<number, string>>(
        () =>
            users.reduce((acc, user) => {
                acc[user.id] = user.role;
                return acc;
            }, {} as Record<number, string>),
    );

    const createForm = useForm({
        name: '',
        email: '',
        password: '',
        role: roles[0]?.slug ?? 'client',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User Management" />
        <div className="space-y-6 p-6">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">User Management</h1>
                    <p className="text-sm text-muted-foreground">
                        Create users, change roles, and delete accounts. Super admins only.
                    </p>
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
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <button className="inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground shadow-sm hover:opacity-90">
                            Add user
                        </button>
                    </DialogTrigger>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Add user</DialogTitle>
                        </DialogHeader>
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post('/admin/users', createForm.data, {
                                    onSuccess: () => {
                                        setOpen(false);
                                        createForm.reset();
                                    },
                                });
                            }}
                        >
                            <div className="space-y-1">
                                <label className="text-sm font-medium">Name</label>
                                <input
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    required
                                />
                                {createForm.errors.name && (
                                    <p className="text-xs text-rose-600">{createForm.errors.name}</p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <label className="text-sm font-medium">Email</label>
                                <input
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    value={createForm.data.email}
                                    onChange={(e) => createForm.setData('email', e.target.value)}
                                    required
                                    type="email"
                                />
                                {createForm.errors.email && (
                                    <p className="text-xs text-rose-600">{createForm.errors.email}</p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <label className="text-sm font-medium">Password</label>
                                <input
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    value={createForm.data.password}
                                    onChange={(e) => createForm.setData('password', e.target.value)}
                                    required
                                    type="password"
                                />
                                {createForm.errors.password && (
                                    <p className="text-xs text-rose-600">{createForm.errors.password}</p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <label className="text-sm font-medium">Role</label>
                                <select
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    value={createForm.data.role}
                                    onChange={(e) => createForm.setData('role', e.target.value)}
                                >
                                    {roles.map((role) => (
                                        <option key={role.slug} value={role.slug}>
                                            {role.slug}
                                        </option>
                                    ))}
                                </select>
                                {createForm.errors.role && (
                                    <p className="text-xs text-rose-600">{createForm.errors.role}</p>
                                )}
                            </div>
                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                                    disabled={createForm.processing}
                                >
                                    {createForm.processing ? 'Creating...' : 'Create user'}
                                </button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            <div className="rounded-lg border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                <h2 className="mb-3 text-lg font-semibold">Users</h2>
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
                                {users.map((user) => (
                                    <tr key={user.id}>
                                        <td className="px-4 py-2">{user.name}</td>
                                        <td className="px-4 py-2">{user.email}</td>
                                        <td className="px-4 py-2">
                                            <form
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    router.patch(`/admin/users/${user.id}/role`, {
                                                        role: roleState[user.id] ?? user.role,
                                                    });
                                                }}
                                                className="flex items-center gap-2"
                                            >
                                                <select
                                                    className="rounded-md border px-2 py-1 text-sm"
                                                    value={roleState[user.id] ?? user.role}
                                                    onChange={(e) =>
                                                        setRoleState((prev) => ({
                                                            ...prev,
                                                            [user.id]: e.target.value,
                                                        }))
                                                    }
                                                >
                                                    {roles.map((role) => (
                                                        <option key={role.slug} value={role.slug}>
                                                            {role.slug}
                                                        </option>
                                                    ))}
                                                </select>
                                                <button
                                                    type="submit"
                                                    className="rounded-md bg-primary px-2 py-1 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                                                >
                                                    Save
                                                </button>
                                            </form>
                                        </td>
                                        <td className="px-4 py-2">
                                            <form
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    if (
                                                        !confirm(
                                                            `Delete user ${user.name}? This cannot be undone.`,
                                                        )
                                                    ) {
                                                        return;
                                                    }
                                                    router.delete(`/admin/users/${user.id}`);
                                                }}
                                            >
                                                <button
                                                    type="submit"
                                                    className="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-200 disabled:opacity-60"
                                                >
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                ))}
                                {users.length === 0 && (
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
        </div>
        </AppLayout>
    );
}

