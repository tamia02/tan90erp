<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tan90_notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('channel', ['Email', 'In-App', 'Email + In-App', 'SMS', 'WhatsApp'])->default('Email');
            $table->string('subject');
            $table->string('recipient');
            $table->string('trigger_event');
            $table->enum('language', ['English', 'Hindi'])->default('English');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tan90_notification_templates');
    }
};
