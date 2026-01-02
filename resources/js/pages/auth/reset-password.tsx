import { Head } from '@inertiajs/react';

import AuthLayout from '@/layouts/auth-layout';

interface ResetPasswordProps {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    return (
        <AuthLayout
            title="Reset password"
            description="Please enter your new password below"
        >
            <Head title="Reset password" />

            <div className="text-center text-sm text-muted-foreground">
                Password reset is handled through phone authentication.
                <br />
                This feature is currently disabled.
            </div>
        </AuthLayout>
    );
}
