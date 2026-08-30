<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profil_toko', function (Blueprint $table) {
            $table->integer('id')->autoIncrement()->primary();
            $table->string('nama_toko', 100)->default('Cample Store Official');
            $table->text('alamat')->nullable();
            $table->string('kontak', 50)->default('0812-3456-7890');
            $table->string('foto_profil', 255)->nullable();
            $table->string('promo_judul', 150)->default('Promo Sewa Alat Camping');
            $table->string('promo_sub', 255)->default('Diskon hingga 20% minggu ini!');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profil_toko');
    }
};
