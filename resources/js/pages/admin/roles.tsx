import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
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

export default function Roles({ roles, permissions }: PageProps) {
    const { props } = usePage();
    const flash = (props as any).flash || {};
    const [open, setOpen] = useState(false);

    const createForm = useForm({
        name: '',
        slug: '',
    });

    const permissionForms = useMemo(
        () =>
            roles.reduce((acc, role) => {
                acc[role.id] = useForm({
                    permissions: role.permissions.map((p) => p.slug),
                });
                return acc;
            }, {} as Record<number, ReturnType<typeof useForm>>),
        [roles],
    );

    const deleteForms = useMemo(
        () =>
            roles.reduce((acc, role) => {
                acc[role.id] = useForm({});
                return acc;
            }, {} as Record<number, ReturnType<typeof useForm>>),
        [roles],
    );

    return (
        <div className="space-y-4 p-6">
            <Head title="Roles" />
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
                                createForm.post(route('admin.roles.store'), {
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
                                        deleteForms[role.id].delete(route('admin.roles.destroy', role.id));
                                    }}
                                >
                                    <button
                                        type="submit"
                                        className="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-200 disabled:opacity-60"
                                        disabled={deleteForms[role.id].processing}
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
                                    permissionForms[role.id].post(route('admin.roles.permissions', role.id));
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
                                                checked={permissionForms[role.id].data.permissions.includes(
                                                    perm.slug,
                                                )}
                                                onChange={(e) => {
                                                    const current = permissionForms[role.id].data.permissions;
                                                    if (e.target.checked) {
                                                        permissionForms[role.id].setData('permissions', [
                                                            ...current,
                                                            perm.slug,
                                                        ]);
                                                    } else {
                                                        permissionForms[role.id].setData(
                                                            'permissions',
                                                            current.filter((p: string) => p !== perm.slug),
                                                        );
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
                                    disabled={permissionForms[role.id].processing}
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
    );
}

