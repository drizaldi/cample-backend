<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barang', function (Blueprint $table) {
            $table->string('id_barang', 50)->primary();
            $table->string('nama_barang', 150);
            $table->string('tipe_barang', 100)->nullable();
            $table->string('kode_barang', 50)->nullable();
            $table->string('kapasitas', 50)->nullable();
            $table->integer('harga_sewa');
            $table->enum('status_ketersediaan', ['Tersedia', 'Dibooking', 'Disewa'])->default('Tersedia');
            $table->string('gambar', 255)->nullable();
            $table->text('deskripsi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barang');
    }
};
