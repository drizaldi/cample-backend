<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesanan', function (Blueprint $table) {
            $table->string('id_pesanan', 50)->primary();
            $table->string('id_transaksi', 50)->nullable();
            $table->integer('id_user')->nullable();
            $table->integer('id_varian');
            $table->string('nama_pemesan', 100)->nullable()->default('Pelanggan');
            $table->integer('jumlah_pesan');
            $table->integer('lama_sewa');
            $table->date('tanggal_mulai')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->integer('total_harga');
            $table->integer('dp_dibayar');
            $table->integer('sisa_tagihan');
            $table->string('metode_pembayaran', 50);
            $table->string('status_pesanan', 50)->nullable()->default('Menunggu Diambil');
            $table->integer('denda_keterlambatan')->nullable()->default(0);
            $table->integer('denda_kerusakan')->nullable()->default(0);
            $table->string('kondisi_pengembalian', 255)->nullable()->default('Normal');
            $table->text('keterangan_kondisi')->nullable();
            $table->string('foto_pengembalian', 255)->nullable();
            $table->double('denda')->default(0);
            $table->text('alasan_denda')->nullable();
            $table->string('foto_kerusakan', 255)->nullable();
            $table->string('bukti_tf_dp', 255)->nullable();
            $table->string('foto_ktp', 255)->nullable();
            $table->bigInteger('nominal_pelunasan')->nullable();
            $table->string('metode_pelunasan', 255)->nullable();
            $table->timestamp('tanggal_pelunasan')->nullable();
            $table->integer('diskon_persen')->default(0);
            $table->integer('harga_satuan_asli')->default(0);
            $table->dateTime('tanggal_pesan')->nullable()->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesanan');
    }
};
