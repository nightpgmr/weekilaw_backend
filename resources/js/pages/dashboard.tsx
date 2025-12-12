import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Database, Key, ShieldCheck, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

type DashboardProps = {
    stats: {
        counts: {
            users: number;
            admins: number;
            super_admins: number;
            clients: number;
            roles: number;
            permissions: number;
        };
        db: {
            connection: string | null;
            database: string | null;
            host: string | null;
        };
    };
    recentUsers: { id: number; name: string; email: string; role: string; created_at: string }[];
    recentRoles: { id: number; name: string; slug: string; permissions_count: number; created_at: string }[];
    recentPermissions: { id: number; name: string; slug: string; created_at: string }[];
};

const formatDate = (value: string) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

export default function Dashboard() {
    const { props } = usePage<DashboardProps>();
    const { stats, recentUsers, recentRoles, recentPermissions } = props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">Database</p>
                                <p className="text-xl font-semibold">{stats.db.database ?? '-'}</p>
                                <p className="text-xs text-muted-foreground">
                                    {stats.db.connection} • {stats.db.host ?? 'localhost'}
                                </p>
                            </div>
                            <div className="rounded-full bg-primary/10 p-2 text-primary">
                                <Database size={20} />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">Users</p>
                                <p className="text-xl font-semibold">{stats.counts.users}</p>
                                <p className="text-xs text-muted-foreground">
                                    Admins {stats.counts.admins} • Super {stats.counts.super_admins} • Clients{' '}
                                    {stats.counts.clients}
                                </p>
                            </div>
                            <div className="rounded-full bg-primary/10 p-2 text-primary">
                                <Users size={20} />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">Roles</p>
                                <p className="text-xl font-semibold">{stats.counts.roles}</p>
                                <p className="text-xs text-muted-foreground">Access management</p>
                            </div>
                            <div className="rounded-full bg-primary/10 p-2 text-primary">
                                <ShieldCheck size={20} />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-muted-foreground">Permissions</p>
                                <p className="text-xl font-semibold">{stats.counts.permissions}</p>
                                <p className="text-xs text-muted-foreground">System access levels</p>
                            </div>
                            <div className="rounded-full bg-primary/10 p-2 text-primary">
                                <Key size={20} />
                            </div>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">Latest Users</h2>
                                <p className="text-sm text-muted-foreground">Last 5 users</p>
                            </div>
                        </div>
                        <div className="mt-4 divide-y divide-border/60">
                            {recentUsers.map((user) => (
                                <div key={user.id} className="flex items-center justify-between py-3">
                                    <div>
                                        <p className="font-medium">{user.name}</p>
                                        <p className="text-xs text-muted-foreground">{user.email}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-primary">{user.role}</p>
                                        <p className="text-xs text-muted-foreground">{formatDate(user.created_at)}</p>
                                    </div>
                                </div>
                            ))}
                            {recentUsers.length === 0 && (
                                <p className="py-3 text-sm text-muted-foreground">No users yet.</p>
                            )}
                        </div>
                    </div>

                    <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">Roles & Permissions</h2>
                                <p className="text-sm text-muted-foreground">Overview of roles and permissions</p>
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            <div className="space-y-3">
                                <p className="text-sm font-semibold">Roles</p>
                                <div className="space-y-2 rounded-lg border border-border/60 p-3">
                                    {recentRoles.map((role) => (
                                        <div key={role.id} className="flex items-center justify-between">
                                            <div>
                                                <p className="font-medium">{role.name}</p>
                                                <p className="text-xs text-muted-foreground">{role.slug}</p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-xs text-muted-foreground">
                                                    {role.permissions_count} permissions
                                                </p>
                                                <p className="text-xs text-muted-foreground">{formatDate(role.created_at)}</p>
                                            </div>
                                        </div>
                                    ))}
                                    {recentRoles.length === 0 && (
                                        <p className="text-sm text-muted-foreground">No roles yet.</p>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-3">
                                <p className="text-sm font-semibold">Permissions</p>
                                <div className="space-y-2 rounded-lg border border-border/60 p-3">
                                    {recentPermissions.map((perm) => (
                                        <div key={perm.id} className="flex items-center justify-between">
                                            <div>
                                                <p className="font-medium">{perm.name}</p>
                                                <p className="text-xs text-muted-foreground">{perm.slug}</p>
                                            </div>
                                            <p className="text-xs text-muted-foreground">{formatDate(perm.created_at)}</p>
                                        </div>
                                    ))}
                                    {recentPermissions.length === 0 && (
                                        <p className="text-sm text-muted-foreground">No permissions yet.</p>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
