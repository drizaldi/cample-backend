<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BarangController;

use App\Http\Controllers\Api\DiskonController;


// =========================================================
// ROUTE PUBLIK — Bisa diakses tanpa login
// =========================================================

// --- ROUTE DEBUG SEMENTARA (HAPUS SETELAH SELESAI TESTING) ---
Route::get('/debug-otp/{email}', function($email) {
    $user = \Illuminate\Support\Facades\DB::table('pengguna')->where('email', $email)->first();
    if($user) {
        return response()->json([
            'email' => $user->email,
            'otp' => $user->otp,
            'waktu_kedaluwarsa' => $user->waktu_kedaluwarsa_otp
        ]);
    }
    return response()->json(['pesan' => 'User tidak ditemukan'], 404);
});

// --- ROUTE DEBUG TOKEN ---
Route::get('/debug-token', function(\Illuminate\Http\Request $request) {
    return response()->json([
        'status' => 'sukses',
        'token' => $request->bearerToken(),
        'user' => $request->user()
    ]);
})->middleware('auth:sanctum');

Route::get('/debug-admins', function() {
    return response()->json(\Illuminate\Support\Facades\DB::table('pengguna')->where('role', 'admin')->get());
});

Route::get('/debug-diskon', function() {
    return response()->json(\Illuminate\Support\Facades\DB::table('diskon')->get());
});

Route::get('/debug-add-columns', function() {
    try {
        \Illuminate\Support\Facades\Schema::table('pesanan', function(\Illuminate\Database\Schema\Blueprint $table) {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('pesanan', 'diskon_persen')) {
                $table->integer('diskon_persen')->default(0)->after('status_pesanan');
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('pesanan', 'harga_satuan_asli')) {
                $table->integer('harga_satuan_asli')->default(0)->after('diskon_persen');
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('pesanan', 'bukti_tf_dp')) {
                $table->string('bukti_tf_dp')->nullable()->after('status_pesanan');
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('pesanan', 'foto_ktp')) {
                $table->string('foto_ktp')->nullable()->after('bukti_tf_dp');
            }
        });
        return response()->json(['status' => 'sukses', 'pesan' => 'Kolom berhasil ditambahkan']);
    } catch (\Exception $e) {
        return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
    }
});

Route::get('/debug-migrate-transaksi', function() {
    try {
        $pesananLama = \Illuminate\Support\Facades\DB::table('pesanan')->whereNull('id_transaksi')->get();
        $count = 0;
        foreach ($pesananLama as $pesanan) {
            $idTransaksi = 'TRC-OLD-' . strtoupper(\Illuminate\Support\Str::random(4));
            
            \Illuminate\Support\Facades\DB::table('transaksi')->insert([
                'id_transaksi'      => $idTransaksi,
                'id_user'           => $pesanan->id_user,
                'nama_pemesan'      => $pesanan->nama_pemesan,
                'total_harga'       => $pesanan->total_harga,
                'total_dp'          => $pesanan->dp_dibayar,
                'sisa_tagihan'      => $pesanan->sisa_tagihan,
                'metode_pembayaran' => $pesanan->metode_pembayaran,
                'status_transaksi'  => $pesanan->status_pesanan,
                'bukti_tf_dp'       => $pesanan->bukti_tf_dp,
                'foto_ktp'          => $pesanan->foto_ktp,
                'created_at'        => $pesanan->tanggal_pesan ?? now(),
                'updated_at'        => now(),
            ]);

            \Illuminate\Support\Facades\DB::table('pesanan')
                ->where('id_pesanan', $pesanan->id_pesanan)
                ->update(['id_transaksi' => $idTransaksi]);
                
            $count++;
        }
        return response()->json(['status' => 'sukses', 'pesan' => "$count pesanan lama berhasil dimigrasi ke transaksi"]);
    } catch (\Exception $e) {
        return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
    }
});

// --- JALUR LOGIN & REGISTER (throttle: maks 5 request/menit) ---
Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login'])->middleware('throttle:5,1');

// --- JALUR MIDTRANS WEBHOOK CALLBACK (Publik, dipanggil server Midtrans) ---
// Tidak memerlukan auth, tetapi diverifikasi menggunakan signature_key
Route::post('/midtrans/callback', [App\Http\Controllers\Api\MidtransController::class, 'handleCallback']);

// --- JALUR LUPA PASSWORD ---
Route::post('/lupa-password/kirim-otp', [App\Http\Controllers\Api\AuthController::class, 'kirimOtp']);
Route::post('/lupa-password/verifikasi-otp', [App\Http\Controllers\Api\AuthController::class, 'verifikasiOtp']);
Route::post('/lupa-password/reset', [App\Http\Controllers\Api\AuthController::class, 'resetPassword']);

// --- JALUR KATALOG BARANG (publik: siapa saja boleh lihat) ---
Route::get('/barang', [BarangController::class, 'index']);
Route::get('/kategori', [BarangController::class, 'getKategori']);

// --- JALUR PROFIL TOKO (publik: info toko boleh dilihat umum) ---
Route::get('/profil', [App\Http\Controllers\Api\ProfilController::class, 'index']);
Route::get('/admin/info', [App\Http\Controllers\Api\UserController::class, 'infoToko']);


// =========================================================
// ROUTE TERPROTEKSI — Wajib login (auth:sanctum)
// =========================================================
Route::middleware(['auth:sanctum', 'update.last.seen'])->group(function () {

    // --- JALUR PROFIL USER (PELANGGAN) ---
    // 1. Ambil statistik & data user
    Route::get('/user/stats', [App\Http\Controllers\Api\UserController::class, 'getStats']);
    // 2. Update foto profil & data diri (Nama/Email)
    Route::post('/user/update-profil', [App\Http\Controllers\Api\UserController::class, 'updateProfil']);
    // 3. Ganti password user
    Route::post('/user/ganti-password', [App\Http\Controllers\Api\UserController::class, 'gantiPassword']);

    // --- JALUR PESANAN & TRANSAKSI ---
    Route::post('/pesanan/bulk', [App\Http\Controllers\Api\PesananController::class, 'bulkStore']);
    Route::post('/pesanan', [App\Http\Controllers\Api\PesananController::class, 'store']);
    Route::get('/pesanan', [App\Http\Controllers\Api\PesananController::class, 'index']);
    
    // Transaksi routes (Master)
    Route::get('/transaksi', [App\Http\Controllers\Api\PesananController::class, 'getTransaksi']);
    Route::get('/transaksi/user/{id_user}', [App\Http\Controllers\Api\PesananController::class, 'getTransaksiUser']);
    Route::post('/transaksi/{id_transaksi}/upload-dp', [App\Http\Controllers\Api\PesananController::class, 'uploadBuktiTfDpTransaksi']);
    Route::post('/transaksi/{id_transaksi}/simulasi-bayar', [App\Http\Controllers\Api\PesananController::class, 'simulasiBayar']);
    Route::post('/transaksi/{id_transaksi}/pengembalian', [App\Http\Controllers\Api\PesananController::class, 'konfirmasiPengembalian']);
    Route::post('/pesanan/{id_pesanan}/upload-dp', [App\Http\Controllers\Api\PesananController::class, 'uploadBuktiTfDp']);
    Route::post('/pesanan/{id_pesanan}/pelunasan', [App\Http\Controllers\Api\PesananController::class, 'uploadPelunasan']);
    Route::post('/pesanan/{id_pesanan}/status', [App\Http\Controllers\Api\PesananController::class, 'updateStatus']);
    Route::put('/pesanan/{id_pesanan}', [App\Http\Controllers\Api\PesananController::class, 'updateStatus']);

    // --- JALUR KERANJANG ---
    Route::post('/keranjang', [App\Http\Controllers\Api\KeranjangController::class, 'store']);
    Route::get('/keranjang/{id_user}', [App\Http\Controllers\Api\KeranjangController::class, 'index']);
    Route::put('/keranjang/{id}', [App\Http\Controllers\Api\KeranjangController::class, 'update']);
    Route::delete('/keranjang/{id}', [App\Http\Controllers\Api\KeranjangController::class, 'destroy']);

    // --- JALUR CHAT REALTIME ---
    Route::get('/chat/rooms', [App\Http\Controllers\Api\ChatController::class, 'getRooms']);
    Route::get('/chat/pesan/{id_user}', [App\Http\Controllers\Api\ChatController::class, 'getPesan']);
    Route::post('/chat/kirim', [App\Http\Controllers\Api\ChatController::class, 'kirimPesan']);
    Route::get('/chat', [App\Http\Controllers\Api\PesananController::class, 'getChats']);

    // --- JALUR ADMIN: Kelola Barang (hanya admin yang boleh) ---
    Route::post('/barang', [BarangController::class, 'store']);
    Route::delete('/barang/{id_barang}', [BarangController::class, 'destroy']);

    // --- JALUR ADMIN: Kelola Varian Barang ---
    Route::post('/barang/{id_barang}/varian', [BarangController::class, 'tambahVarian']);
    Route::get('/barang/{id_barang}/varian', [BarangController::class, 'getVarian']);
    Route::put('/barang/varian/{id_varian}', [BarangController::class, 'updateVarian']);
    Route::delete('/barang/varian/{id_varian}', [BarangController::class, 'hapusVarian']);

    // --- JALUR PESAN BARANG (harus login) ---
    Route::post('/barang/{id_barang}/pesan', [BarangController::class, 'pesanBarang']);

    // --- JALUR ADMIN: Profil Toko & Status User ---
    Route::post('/profil', [App\Http\Controllers\Api\ProfilController::class, 'update']);
    Route::post('/profil/promo', [App\Http\Controllers\Api\ProfilController::class, 'updatePromo']);
    Route::get('/admin/users', [App\Http\Controllers\Api\UserController::class, 'getUsers']);


    // --- JALUR ADMIN: Manajemen Diskon ---
    Route::get('/admin/diskon', [DiskonController::class, 'index']);
    Route::post('/admin/diskon/baru', [DiskonController::class, 'store']); // BYPASS ROUTE
    Route::post('/admin/diskon', [DiskonController::class, 'store']);
    Route::delete('/admin/diskon/{id}', [DiskonController::class, 'destroy']);

    // --- JALUR PUBLIK DISKON (user lihat diskon aktif barang tertentu) ---
    Route::get('/diskon/{id_barang}', [DiskonController::class, 'diskonAktifBarang']);
});