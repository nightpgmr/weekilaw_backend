<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'role',
        'password',
        'auth_provider',
        'provider_id',
        'otp_code',
        'otp_expires_at',
        'phone_verified_at',
        'email_verified_at',
        'wallet_balance',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'wallet_balance' => 'decimal:2',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function addToWallet(float $amount, string $description = null, array $metadata = []): WalletTransaction
    {
        return \DB::transaction(function () use ($amount, $description, $metadata) {
            $balanceBefore = $this->wallet_balance;
            $balanceAfter = $balanceBefore + $amount;

            $this->update(['wallet_balance' => $balanceAfter]);

            return WalletTransaction::create([
                'user_id' => $this->id,
                'type' => 'deposit',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'status' => 'completed',
                'description' => $description,
                'metadata' => $metadata,
                'completed_at' => now(),
            ]);
        });
    }

    public function deductFromWallet(float $amount, string $description = null, array $metadata = []): ?WalletTransaction
    {
        if ($this->wallet_balance < $amount) {
            return null; // Insufficient balance
        }

        return \DB::transaction(function () use ($amount, $description, $metadata) {
            $balanceBefore = $this->wallet_balance;
            $balanceAfter = $balanceBefore - $amount;

            $this->update(['wallet_balance' => $balanceAfter]);

            return WalletTransaction::create([
                'user_id' => $this->id,
                'type' => 'charge',
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'status' => 'completed',
                'description' => $description,
                'metadata' => $metadata,
                'completed_at' => now(),
            ]);
        });
    }
}
