<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi', function (Blueprint $table) {
            $table->string('id_transaksi', 50)->primary();
            $table->unsignedBigInteger('id_user')->nullable();
            $table->string('nama_pemesan')->nullable();
            $table->double('total_harga');
            $table->double('total_dp');
            $table->double('sisa_tagihan');
            $table->string('bukti_tf_dp')->nullable();
            $table->string('metode_pembayaran')->nullable();
            $table->text('snap_url')->nullable();
            $table->string('snap_token')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('status_transaksi')->default('menunggu_dp');
            $table->dateTime('tanggal_dikembalikan')->nullable();
            $table->string('foto_ktp')->nullable();
            $table->double('nominal_pelunasan')->nullable();
            $table->string('metode_pelunasan')->nullable();
            $table->timestamp('tanggal_pelunasan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi');
    }
};
