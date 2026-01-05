<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('temp_otps', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15);
            $table->string('otp'); // hashed OTP
            $table->timestamp('expires_at');
            $table->string('type', 20); // 'register' or 'login'
            $table->timestamps();

            $table->index(['phone', 'type']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temp_otps');
    }
};
