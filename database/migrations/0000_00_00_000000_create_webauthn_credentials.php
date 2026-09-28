<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Laragear\WebAuthn\Models\WebAuthnCredential;

return new class extends Migration
{
    public function up(): void
    {
        $this->credentials()->up();
    }

    public function down(): void
    {
        $this->credentials()->down();
    }

    private function credentials(): Migration
    {
        return WebAuthnCredential::migration();
    }
};
