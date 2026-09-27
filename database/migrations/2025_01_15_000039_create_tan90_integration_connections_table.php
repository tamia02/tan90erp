<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_integration_connections', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', ['REST API', 'SOAP', 'OAuth 2.0', 'SMTP', 'SFTP', 'Browser SDK'])->default('REST API');
            $table->string('base_url')->nullable();
            $table->string('auth')->nullable();
            $table->enum('environment', ['Demo', 'Staging', 'Production', 'Future'])->default('Staging');
            $table->enum('health', ['healthy', 'pending', 'failed', 'disabled'])->default('pending');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_integration_connections');
    }
};
