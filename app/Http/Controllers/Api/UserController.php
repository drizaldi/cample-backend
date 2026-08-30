<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // --- 1. AMBIL STATISTIK USER (TOTAL SEWA & KERANJANG) ---
    public function getStats(Request $request)
    {
        $id = $request->user()->id;
        $user = DB::table('pengguna')->where('id', $id)->first();

        if (!$user) {
            return response()->json(['pesan' => 'User tidak ditemukan'], 404);
        }

        // Menghitung total sewa berdasarkan ID User atau Nama (untuk data lama), kecuali yang ditolak
        $totalSewa = DB::table('pesanan')
            ->where(function($query) use ($id, $user) {
                $query->where('id_user', $id)
                      ->orWhere('nama_pemesan', $user->nama);
            })
            ->where('status_pesanan', '!=', 'ditolak')
            ->count();
            
        // Menghitung jumlah barang di keranjang (hanya barang yang masih ada)
        $totalKeranjang = DB::table('keranjang')
            ->join('barang', 'keranjang.id_barang', '=', 'barang.id_barang')
            ->where('keranjang.id_pengguna', $id)
            ->count();

        // FIX: Selalu rekonstruksi URL dari nama file saja, agar tidak rusak saat IP/domain berubah
        if ($user->foto_profil) {
            $user->foto_profil = url('storage/profil/' . basename($user->foto_profil));
        }

        return response()->json([
            'total_sewa' => $totalSewa,
            'total_keranjang' => $totalKeranjang,
            'user' => [
                'id' => $user->id,
                'nama' => $user->nama,
                'email' => $user->email,
                'no_hp' => $user->no_hp,
                'role' => $user->role,
                'foto_profil' => $user->foto_profil,
            ]
        ]);
    }

    // --- 2. UPDATE PROFIL & UPLOAD FOTO PERMANEN ---
    public function updateProfil(Request $request)
    {
        $id = $request->user()->id;
        $data = [
            'nama' => $request->nama,
            'email' => $request->email,
        ];

        if ($request->hasFile('foto_profil')) {
            $file = $request->file('foto_profil');
            $filename = time() . '_' . $file->getClientOriginalName();
            
            // Simpan secara fisik ke: storage/app/public/profil
            $file->move(storage_path('app/public/profil'), $filename);
            
            // FIX: Simpan HANYA nama file ke database (bukan URL penuh)
            // URL dibentuk secara dinamis di getStats/query agar tidak rusak saat ganti ngrok
            $data['foto_profil'] = $filename;
        }

        DB::table('pengguna')->where('id', $id)->update($data);
        
        $userBaru = DB::table('pengguna')->where('id', $id)->first();
        
        // Bentuk URL foto profil secara dinamis sebelum dikirim ke Flutter
        if ($userBaru && $userBaru->foto_profil) {
            // Cek apakah sudah berupa URL penuh (data lama) atau hanya nama file (data baru)
            if (!str_starts_with($userBaru->foto_profil, 'http')) {
                $userBaru->foto_profil = url('storage/profil/' . $userBaru->foto_profil);
            }
        }
        
        return response()->json([
            'status' => 'sukses', 
            'pesan' => 'Profil berhasil diperbarui', 
            'user' => $userBaru
        ]);
    }

    // --- 3. GANTI PASSWORD ---
    public function gantiPassword(Request $request)
    {
        $request->validate([
            'password_lama' => 'required',
            'password_baru' => 'required|min:6'
        ], [
            'password_lama.required' => 'Password lama wajib diisi!',
            'password_baru.required' => 'Password baru wajib diisi!',
            'password_baru.min' => 'Password baru minimal 6 karakter!'
        ]);

        $user = $request->user();

        // Cek apakah password lama cocok
        if (!Hash::check($request->password_lama, $user->password)) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Password lama salah!'], 400);
        }

        DB::table('pengguna')->where('id', $user->id)->update([
            'password' => Hash::make($request->password_baru)
        ]);
        
        return response()->json(['status' => 'sukses', 'pesan' => 'Password berhasil diubah!']);
    }

    // --- 4. INFO ADMIN TOKO ---
    public function infoToko()
    {
        // Ambil admin yang paling terakhir aktif jika ada lebih dari satu admin
        $admin = DB::table('pengguna')->where('role', 'admin')->orderByDesc('last_seen')->first();

        if (!$admin) {
            return response()->json(['pesan' => 'Admin tidak ditemukan'], 404);
        }

        // Hitung label_online berdasarkan last_seen
        $labelOnline = 'Offline';
        if ($admin->last_seen) {
            $lastSeen = \Carbon\Carbon::parse($admin->last_seen);
            $diffMenit = intval($lastSeen->diffInMinutes(now()));
            $diffJam   = intval($lastSeen->diffInHours(now()));
            $diffHari  = intval($lastSeen->diffInDays(now()));

            if ($diffMenit < 2) {
                $labelOnline = 'Online';
            } elseif ($diffMenit < 60) {
                $labelOnline = $diffMenit . ' menit lalu';
            } elseif ($diffJam < 24) {
                $labelOnline = $diffJam . ' jam lalu';
            } else {
                $labelOnline = $diffHari . ' hari lalu';
            }
        }

        // Ambil profil_toko untuk mendapatkan foto toko terbaru
        $profilToko = DB::table('profil_toko')->first();
        $fotoAdmin = $profilToko ? $profilToko->foto_profil : $admin->foto_profil;

        return response()->json([
            'id'           => $admin->id,
            'nama'         => $admin->nama,
            'email'        => $admin->email,
            'foto_profil'  => ($fotoAdmin && !str_starts_with($fotoAdmin, 'http')) ? url('storage/profil/' . basename($fotoAdmin)) : $fotoAdmin,
            'last_seen'    => $admin->last_seen,
            'label_online' => $labelOnline,
        ]);
    }

    // --- 5. DAFTAR SEMUA USER UNTUK ADMIN (Untuk Status Online di Chat) ---
    public function getUsers()
    {
        $users = DB::table('pengguna')->where('role', 'user')->get();
        
        $result = [];
        foreach ($users as $u) {
            $labelOnline = 'Offline';
            if ($u->last_seen) {
                $lastSeen = \Carbon\Carbon::parse($u->last_seen);
                $diffMenit = intval($lastSeen->diffInMinutes(now()));
                $diffJam   = intval($lastSeen->diffInHours(now()));
                $diffHari  = intval($lastSeen->diffInDays(now()));

                if ($diffMenit < 2) {
                    $labelOnline = 'Online';
                } elseif ($diffMenit < 60) {
                    $labelOnline = $diffMenit . ' menit lalu';
                } elseif ($diffJam < 24) {
                    $labelOnline = $diffJam . ' jam lalu';
                } else {
                    $labelOnline = $diffHari . ' hari lalu';
                }
            }

            $result[] = [
                'id' => $u->id,
                'nama' => $u->nama,
                'label_online' => $labelOnline,
                'last_seen' => $u->last_seen,
                'foto_profil' => ($u->foto_profil && !str_starts_with($u->foto_profil, 'http')) ? url('storage/profil/' . basename($u->foto_profil)) : $u->foto_profil
            ];
        }

        return response()->json($result);
    }
}
