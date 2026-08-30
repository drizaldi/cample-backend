<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('varian_barang', function (Blueprint $table) {
            $table->integer('id_varian')->autoIncrement()->primary();
            $table->string('id_barang', 50);
            $table->string('nama_varian', 100);
            $table->integer('harga_sewa');
            $table->string('kapasitas', 50);
            $table->integer('stok');

            $table->foreign('id_barang')->references('id_barang')->on('barang')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('varian_barang');
    }
};
