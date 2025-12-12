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

type Permission = {
    id: number;
    name: string;
    slug: string;
};

type PageProps = {
    permissions: Permission[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Permissions', href: '/admin/permissions' },
];

export default function Permissions({ permissions }: PageProps) {
    const { props } = usePage();
    const flash = (props as any).flash || {};
    const [open, setOpen] = useState(false);

    const createForm = useForm({
        name: '',
        slug: '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Permissions" />
        <div className="space-y-4 p-6">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Permissions</h1>
                    <p className="text-sm text-muted-foreground">Manage permissions (super admins only).</p>
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
                            Add permission
                        </button>
                    </DialogTrigger>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Add permission</DialogTitle>
                        </DialogHeader>
                        <form
                            className="space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.post('/admin/permissions', createForm.data, {
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
                                    {createForm.processing ? 'Creating...' : 'Create permission'}
                                </button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>

            <div className="space-y-2">
                {permissions.map((perm) => (
                    <div
                        key={perm.id}
                        className="flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-card px-4 py-3 shadow-sm dark:border-sidebar-border"
                    >
                        <div>
                            <p className="text-sm font-semibold">{perm.name}</p>
                            <p className="text-xs text-muted-foreground">{perm.slug}</p>
                        </div>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!confirm(`Delete permission ${perm.name}?`)) return;
                                router.delete(`/admin/permissions/${perm.id}`);
                            }}
                        >
                            <button
                                type="submit"
                                className="rounded-md bg-rose-100 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-200 disabled:opacity-60"
                            >
                                Delete
                            </button>
                        </form>
                    </div>
                ))}
                {permissions.length === 0 && (
                    <div className="rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                        No permissions yet.
                    </div>
                )}
            </div>
        </div>
        </AppLayout>
    );
}

