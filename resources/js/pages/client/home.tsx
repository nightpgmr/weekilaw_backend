import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function ClientHome() {
    return (
        <AppLayout>
            <Head title="Client Area" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl border border-sidebar-border/70 p-6 dark:border-sidebar-border">
                <h1 className="text-2xl font-semibold">Client Area</h1>
                <p className="text-sm text-muted-foreground">
                    You are signed in as a client. Replace this placeholder with your client-facing dashboard or pages.
                </p>
            </div>
        </AppLayout>
    );
}

