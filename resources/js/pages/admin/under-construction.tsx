import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Hammer } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard().url },
    { title: 'Under Construction', href: '/admin/under-construction' },
];

type PageProps = {
    enabled: boolean;
};

export default function UnderConstruction({ enabled }: PageProps) {
    const { props } = usePage();
    const flash = (props as any).flash || {};
    const [busy, setBusy] = useState(false);

    const toggle = (nextEnabled: boolean) => {
        setBusy(true);
        router.post(
            '/admin/under-construction/toggle',
            { enabled: nextEnabled },
            { onFinish: () => setBusy(false) },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Under Construction" />
            <div className="p-6">
                <div className="rounded-lg border border-dashed border-border bg-muted/30 p-6 text-center">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Hammer size={24} />
                    </div>
                    <h1 className="text-2xl font-semibold">مدیریت حالت "در حال ساخت"</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        این تنظیمات برای کنترل نمایش صفحه "در حال ساخت" در وب‌سایت اصلی استفاده می‌شود.
                    </p>
                    <div className="mt-4 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium">
                        <span
                            className={`inline-flex h-2 w-2 rounded-full ${enabled ? 'bg-emerald-500' : 'bg-rose-500'}`}
                        />
                        <span>وضعیت: {enabled ? 'فعال' : 'غیرفعال'}</span>
                    </div>
                    <p className="mt-2 text-xs text-muted-foreground">
                        با تغییر این تنظیمات، صفحه "در حال ساخت" در وب‌سایت اصلی نمایش داده یا پنهان می‌شود.
                    </p>
                    <div className="mt-4 flex justify-center gap-3">
                        <button
                            type="button"
                            className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60"
                            onClick={() => toggle(!enabled)}
                            disabled={busy}
                        >
                            {busy ? 'در حال ذخیره...' : enabled ? 'غیرفعال کردن صفحه' : 'فعال کردن صفحه'}
                        </button>
                    </div>
                    {flash.success && (
                        <div className="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                            {flash.success}
                        </div>
                    )}
                    {flash.error && (
                        <div className="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
                            {flash.error}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}


