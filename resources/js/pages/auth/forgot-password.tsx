// Components
import { login } from '@/routes';
import { Head } from '@inertiajs/react';

import TextLink from '@/components/text-link';
import AuthLayout from '@/layouts/auth-layout';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <AuthLayout
            title="Forgot password"
            description="Enter your email to receive a password reset link"
        >
            <Head title="Forgot password" />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <div className="text-center text-sm text-muted-foreground">
                    Password reset is handled through phone authentication.
                    <br />
                    Please use the login page to reset your password.
                </div>

                <div className="space-x-1 text-center text-sm text-muted-foreground">
                    <span>Return to</span>
                    <TextLink href={login()}>log in</TextLink>
                </div>
            </div>
        </AuthLayout>
    );
}
