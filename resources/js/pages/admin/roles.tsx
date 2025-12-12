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
    roles: Role[];
    permissions: Permission[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Roles', href: '/admin/roles' },
];

export default function Roles({ roles, permissions }: PageProps) {
    const { props } = usePage();
    const flash = (props as any).flash || {};
    const [open, setOpen] = useState(false);
    const [rolePerms, setRolePerms] = useState<Record<number, string[]>>(
        () =>
            roles.reduce((acc, role) => {
                acc[role.id] = role.permissions.map((p) => p.slug);
                return acc;
            }, {} as Record<number, string[]>),
    );

    const createForm = useForm({
        name: '',
        slug: '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles" />
        <div className="space-y-4 p-6">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Roles</h1>
                    <p className="text-sm text-muted-foreground">
                        Manage roles and their permissions (super admins only).
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
                            Add role
                        </button>
                    </DialogTrigger>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Add role</DialogTitle>
                        </DialogHeader>
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post('/admin/roles', createForm.data, {
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
                                <label className="text-sm font-medium">Slug</label>
                                <input
                                    className="w-full rounded-md border px-3 py-2 text-sm"
                                    value={createForm.data.slug}
                                    onChange={(e) => createForm.setData('slug', e.target.value)}
                                    required
                                />
                                {createForm.errors.slug && (
                                    <p className="text-xs text-rose-600">{createForm.errors.slug}</p>
                                )}
                            </div>
                            <div className="flex justify-end">
                                <button
                                    type="submit"
                                    className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                                    disabled={createForm.processing}
                                >
                                    {createForm.processing ? 'Creating...' : 'Create role'}
                                </button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            <div className="space-y-3">
                {roles.map((role) => (
                    <div
                        key={role.id}
                        className="rounded-lg border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold">{role.name}</h2>
                                <p className="text-sm text-muted-foreground">{role.slug}</p>
                            </div>
                            {role.slug !== 'super_admin' && (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        if (!confirm(`Delete role ${role.name}?`)) return;
                                        router.delete(`/admin/roles/${role.id}`);
                                    }}
                                >
                                    <button
                                        type="submit"
                                        className="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-200 disabled:opacity-60"
                                    >
                                        Delete
                                    </button>
                                </form>
                            )}
                        </div>

                        <div className="mt-3 space-y-2">
                            <p className="text-sm font-medium">Permissions</p>
                            <form
                                className="space-y-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(`/admin/roles/${role.id}/permissions`, {
                                        permissions: rolePerms[role.id] ?? [],
                                    });
                                }}
                            >
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {permissions.map((perm) => (
                                        <label
                                            key={perm.id}
                                            className="flex items-center gap-2 rounded border border-border/60 px-2 py-1 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={(rolePerms[role.id] ?? []).includes(perm.slug)}
                                                onChange={(e) => {
                                                    const current = rolePerms[role.id] ?? [];
                                                    if (e.target.checked) {
                                                        setRolePerms((prev) => ({
                                                            ...prev,
                                                            [role.id]: Array.from(new Set([...current, perm.slug])),
                                                        }));
                                                    } else {
                                                        setRolePerms((prev) => ({
                                                            ...prev,
                                                            [role.id]: current.filter((p) => p !== perm.slug),
                                                        }));
                                                    }
                                                }}
                                            />
                                            <span>{perm.slug}</span>
                                        </label>
                                    ))}
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
                {roles.length === 0 && (
                    <div className="rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                        No roles yet.
                    </div>
                )}
            </div>
        </div>
        </AppLayout>
    );
}

