<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->integer('id_chat')->autoIncrement()->primary();
            $table->string('id_user', 50)->nullable();
            $table->string('pengirim', 50)->default('Sistem');
            $table->text('pesan')->nullable();
            $table->string('tipe_pesan', 50)->default('teks');
            $table->string('id_pesanan', 50)->nullable();
            $table->dateTime('tanggal')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
