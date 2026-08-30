<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProfilController extends Controller
{
    // Mengambil data profil saat halaman Flutter dibuka
    public function index()
    {
        $profil = DB::table('profil_toko')->first();
        
        if ($profil && $profil->foto_profil) {
            $profil->url_foto = url('storage/profil/' . basename($profil->foto_profil));
        } else {
            if($profil) $profil->url_foto = null;
        }

        return response()->json($profil);
    }

    // Menyimpan perubahan nama, alamat, kontak, dan foto
    public function update(Request $request)
    {
        try {
            $profil = DB::table('profil_toko')->first();
            $updateData = [
                'nama_toko' => $request->nama_toko,
                'alamat' => $request->alamat,
                'kontak' => $request->kontak,
            ];

            // Jika ada foto baru yang diupload
            if ($request->hasFile('foto')) {
                // Hapus foto lama jika ada
                if ($profil && $profil->foto_profil) {
                    Storage::disk('public')->delete($profil->foto_profil);
                }
                // Simpan foto baru
                $path = $request->file('foto')->store('profil', 'public');
                $updateData['foto_profil'] = $path;
            }

            DB::table('profil_toko')->where('id', 1)->update($updateData);

            return response()->json(['status' => 'sukses', 'pesan' => 'Profil berhasil disimpan!']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }
    // --- FUNGSI UPDATE TEKS PROMO ---
    public function updatePromo(Request $request)
    {
        // Mengubah teks promo untuk toko dengan ID 1 (karena profil toko hanya ada 1 baris data)
        DB::table('profil_toko')->where('id', 1)->update([
            'promo_judul' => $request->promo_judul,
            'promo_sub' => $request->promo_sub
        ]);
        
        return response()->json(['status' => 'sukses']);
    }
}
