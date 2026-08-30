<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DiskonController extends Controller
{
    // --- 1. AMBIL SEMUA DISKON (Admin) ---
    public function index()
    {
        $now = Carbon::now();
        // Urutkan: yang masih aktif di atas, lalu berdasarkan created_at descending
        $diskon = DB::table('diskon')
            ->orderByRaw('CASE WHEN akhir >= ? THEN 1 ELSE 0 END DESC', [$now])
            ->orderBy('created_at', 'desc')
            ->get();

        // Ambil data barang untuk mapping nama_barang
        $barang = DB::table('barang')->pluck('nama_barang', 'id_barang');

        foreach ($diskon as $d) {
            $d->nama_barang = $barang[$d->id_barang] ?? 'Barang Dihapus';
        }

        return response()->json($diskon);
    }

    // --- 2. AMBIL DISKON AKTIF UNTUK BARANG TERTENTU ---
    public function diskonAktifBarang($id_barang)
    {
        $now = Carbon::now();
        $diskon = DB::table('diskon')
            ->where('id_barang', $id_barang)
            ->where('is_aktif', true)
            ->where('mulai', '<=', $now)
            ->where('akhir', '>=', $now)
            ->first();

        return response()->json($diskon);
    }

    // --- 3. TAMBAH DISKON BARU ---
    public function store(Request $request)
    {
        $request->validate([
            'id_barang' => 'required|string',
            'persen'    => 'required|integer|min:1|max:100',
            'mulai'     => 'required|date',
            'akhir'     => 'required|date|after:mulai',
        ], [
            'id_barang.required' => 'Pilih barang terlebih dahulu!',
            'persen.required'    => 'Persentase diskon wajib diisi!',
            'persen.min'         => 'Diskon minimal 1%!',
            'persen.max'         => 'Diskon maksimal 100%!',
            'mulai.required'     => 'Waktu mulai wajib diisi!',
            'akhir.required'     => 'Waktu berakhir wajib diisi!',
            'akhir.after'        => 'Waktu berakhir harus setelah waktu mulai!',
        ]);

        $now = Carbon::now();

        // Cek apakah ada diskon yang masih BERLAKU untuk barang ini
        // Jika ada yang belum expired, kita update itu (agar tidak double diskon aktif)
        // Jika sudah expired, kita buat baris baru untuk menjaga histori
        $existing = DB::table('diskon')
            ->where('id_barang', $request->id_barang)
            ->where('akhir', '>=', $now)
            ->first();

        if ($existing) {
            // Update yang masih aktif
            DB::table('diskon')->where('id', $existing->id)->update([
                'persen'     => $request->persen,
                'mulai'      => $request->mulai,
                'akhir'      => $request->akhir,
                'updated_at' => now(),
            ]);
            return response()->json([
                'status' => 'sukses',
                'pesan'  => 'Diskon berhasil diperbarui!',
                'data'   => DB::table('diskon')->find($existing->id),
            ]);
        }

        $id = DB::table('diskon')->insertGetId([
            'id_barang'  => $request->id_barang,
            'persen'     => $request->persen,
            'mulai'      => $request->mulai,
            'akhir'      => $request->akhir,
            'is_aktif'   => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'sukses',
            'pesan'  => 'Diskon berhasil ditambahkan!',
            'data'   => DB::table('diskon')->find($id),
        ]);
    }

    // --- 4. HAPUS / NONAKTIFKAN DISKON ---
    public function destroy($id)
    {
        DB::table('diskon')->where('id', $id)->delete();

        return response()->json([
            'status' => 'sukses',
            'pesan'  => 'Diskon berhasil dihapus!',
        ]);
    }
}
