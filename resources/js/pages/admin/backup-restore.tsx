import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Database, Download, Upload } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Backup & Restore', href: '/admin/backup-restore' },
];

export default function BackupRestore() {
    const { props } = usePage();
    const flash = (props as any).flash || {};

    const backupForm = useForm({});
    const [restoreFileName, setRestoreFileName] = useState<string>('');
    const restoreForm = useForm<{ file: File | null }>({
        file: null,
    });

    const handleBackup = () => {
        backupForm.reset();
        window.location.href = '/admin/backup/download';
    };

    const handleRestore = () => {
        restoreForm.post('/admin/backup/restore', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setRestoreFileName('');
                restoreForm.reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Backup & Restore" />
            <div className="space-y-4 p-6">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">Backup & Restore</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage database backups and restores. (UI only — hook your endpoints when ready.)
                        </p>
                    </div>
                    <div className="rounded-full bg-primary/10 p-3 text-primary">
                        <Database size={24} />
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-lg border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <h2 className="text-lg font-semibold">Create backup</h2>
                        <p className="text-sm text-muted-foreground">
                            Trigger a database backup. Replace placeholder logic with your DB backup script.
                        </p>
                        <button
                            type="button"
                            className="mt-3 inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground shadow-sm hover:opacity-90 disabled:opacity-60"
                            onClick={handleBackup}
                            disabled={backupForm.processing}
                        >
                            <Download size={16} />
                            {backupForm.processing ? 'Working...' : 'Run backup'}
                        </button>
                    </div>

                    <div className="rounded-lg border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <h2 className="text-lg font-semibold">Restore backup</h2>
                        <p className="text-sm text-muted-foreground">
                            Upload a backup file to restore. Replace placeholder restore logic with your implementation.
                        </p>
                        <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <label className="flex w-full cursor-pointer flex-col gap-1 rounded-md border border-dashed border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground hover:bg-muted/50">
                                <span>{restoreFileName || 'Choose backup file'}</span>
                                <input
                                    type="file"
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        restoreForm.setData('file', file ?? null);
                                        setRestoreFileName(file ? file.name : '');
                                    }}
                                />
                            </label>
                            <button
                                type="button"
                                className="inline-flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-sm font-medium hover:bg-muted/80 disabled:opacity-60"
                                onClick={handleRestore}
                                disabled={restoreForm.processing || !restoreForm.data.file}
                            >
                                <Upload size={16} />
                                {restoreForm.processing ? 'Restoring...' : 'Upload & restore'}
                            </button>
                        </div>
                        {restoreForm.errors.file && (
                            <p className="mt-2 text-xs text-rose-600">{restoreForm.errors.file}</p>
                        )}
                    </div>
                </div>

                {flash.success && (
                    <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        {flash.error}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}


