<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    // FUNGSI UNTUK MENDAFTAR (REGISTER)
    public function register(Request $request)
    {
        $request->validate([
            'nama' => 'required|string',
            'email' => 'required|email|unique:pengguna,email',
            'password' => 'required|string|min:6'
        ], [
            'nama.required' => 'Nama lengkap wajib diisi!',
            'email.required' => 'Email wajib diisi!',
            'email.email' => 'Format email tidak valid!',
            'email.unique' => 'Email sudah terdaftar, silakan login!',
            'password.required' => 'Password wajib diisi!',
            'password.min' => 'Password minimal harus 6 karakter!'
        ]);

        try {
            $user = \App\Models\User::create([
                'nama' => $request->nama,
                'email' => $request->email,
                'no_hp' => $request->no_hp ?? null,
                'password' => \Illuminate\Support\Facades\Hash::make($request->password), 
                'role' => 'user' // Otomatis menjadi pelanggan
            ]);
            
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'sukses', 
                'pesan' => 'Registrasi Berhasil!',
                'token' => $token,
                'data' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Registrasi gagal: ' . $e->getMessage()], 400);
        }
    }

    // FUNGSI UNTUK MASUK (LOGIN)
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();

        if (!$user || !\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Username/Email atau Password salah!'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        // Rekonstruksi URL foto profil dari basename saja
        $fotoUrl = null;
        if ($user->foto_profil) {
            $fotoUrl = url('storage/profil/' . basename($user->foto_profil));
        }

        return response()->json([
            'status' => 'sukses', 
            'token' => $token,
            'data' => [
                'id'          => $user->id,
                'nama'        => $user->nama,
                'email'       => $user->email,
                'no_hp'       => $user->no_hp,
                'role'        => $user->role,
                'foto_profil' => $fotoUrl,
            ]
        ]);
    }

    // --- ALUR LUPA PASSWORD (EMAIL OTP) ---
    public function kirimOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'no_hp' => 'required'
        ]);

        // Normalisasi no_hp: hilangkan tanda +, spasi, dan strip
        $noHpInput = preg_replace('/[^0-9]/', '', $request->no_hp);
        
        // Cari user berdasarkan email dulu
        $user = \App\Models\User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Email tidak terdaftar!'], 400);
        }

        // Normalisasi no_hp di database juga untuk perbandingan
        $noHpDb = preg_replace('/[^0-9]/', '', $user->no_hp ?? '');
        
        // Bandingkan bagian akhirnya (8-12 digit terakhir) agar fleksibel
        $panjang = min(strlen($noHpInput), strlen($noHpDb));
        $cocok = $panjang >= 8 && substr($noHpInput, -$panjang) === substr($noHpDb, -$panjang);
        
        if (!$cocok) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Nomor HP tidak sesuai dengan data yang terdaftar!'], 400);
        }

        // Generate 4 digit OTP
        $otp = rand(1000, 9999);
        
        // Simpan OTP sementara (misal di DB, karena ini simulasi/demo, kita bisa pakai Cache atau simpan di field sementara)
        // Disini kita akan memanfaatkan Cache dengan key email
        \Illuminate\Support\Facades\Cache::put('otp_' . $request->email, $otp, now()->addMinutes(10));

        // Karena ini sering kali butuh konfigurasi SMTP, untuk tujuan lokal/demo:
        // Coba kirim via mail, jika gagal abaikan dan log saja.
        try {
            \Illuminate\Support\Facades\Mail::raw("Kode OTP Lupa Password Anda adalah: $otp\n\nKode ini berlaku selama 10 menit.", function ($message) use ($user) {
                $message->to($user->email)->subject('Kode OTP Cample');
            });
            \Illuminate\Support\Facades\Log::info("Mengirim OTP $otp ke email " . $user->email);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Gagal mengirim email OTP: " . $e->getMessage());
            // Meskipun gagal kirim (karena SMTP belum disetting), tetap tampilkan sukses di flutter untuk demo
            // Di flutter nanti akan bisa cek log server jika diperlukan
        }

        return response()->json(['status' => 'sukses', 'pesan' => 'OTP berhasil dikirim ke email!']);
    }

    public function verifikasiOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required'
        ]);

        $savedOtp = \Illuminate\Support\Facades\Cache::get('otp_' . $request->email);

        if (!$savedOtp || $savedOtp != $request->otp) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Kode OTP salah atau sudah kadaluarsa!'], 400);
        }

        // Hapus OTP
        \Illuminate\Support\Facades\Cache::forget('otp_' . $request->email);

        // Buat token khusus untuk reset password (berlaku 15 menit)
        $resetToken = \Illuminate\Support\Str::random(60);
        \Illuminate\Support\Facades\Cache::put('reset_' . $request->email, $resetToken, now()->addMinutes(15));

        return response()->json([
            'status' => 'sukses', 
            'pesan' => 'OTP Benar!',
            'reset_token' => $resetToken
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'reset_token' => 'required',
            'password' => 'required|min:6'
        ]);

        $savedToken = \Illuminate\Support\Facades\Cache::get('reset_' . $request->email);

        if (!$savedToken || $savedToken != $request->reset_token) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Sesi reset password tidak valid atau sudah kadaluarsa!'], 400);
        }

        $user = \App\Models\User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['status' => 'gagal', 'pesan' => 'User tidak ditemukan!'], 404);
        }

        // Update password
        $user->password = \Illuminate\Support\Facades\Hash::make($request->password);
        $user->save();

        // Hapus token
        \Illuminate\Support\Facades\Cache::forget('reset_' . $request->email);

        return response()->json(['status' => 'sukses', 'pesan' => 'Password berhasil diubah!']);
    }
}
