<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengguna', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->primary();
            $table->string('nama', 100)->nullable();
            $table->string('email', 100)->nullable()->unique();
            $table->string('no_hp', 20)->nullable();
            $table->string('password', 255)->nullable();
            $table->enum('role', ['admin', 'user'])->default('user');
            $table->text('foto_profil')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengguna');
    }
};
