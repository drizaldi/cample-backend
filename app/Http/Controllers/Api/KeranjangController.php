<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KeranjangController extends Controller
{
    // 1. Tambah ke keranjang
    public function store(Request $request)
    {
        $idUser = $request->id_user ?? $request->id_pengguna;

        // Cek apakah barang + varian sudah ada di keranjang
        $existing = DB::table('keranjang')
            ->where('id_pengguna', $idUser)
            ->where('id_barang', $request->id_barang)
            ->where('nama_varian', $request->nama_varian)
            ->first();

        if ($existing) {
            // Update qty jika sudah ada
            DB::table('keranjang')
                ->where('id_keranjang', $existing->id_keranjang)
                ->update([
                    'qty' => $existing->qty + ($request->qty ?? 1),
                    'tanggal_mulai' => $request->tanggal_mulai ?? $existing->tanggal_mulai,
                    'tanggal_selesai' => $request->tanggal_selesai ?? $existing->tanggal_selesai
                ]);
        } else {
            // Insert baru
            DB::table('keranjang')->insert([
                'id_pengguna'  => $idUser,
                'id_barang'    => $request->id_barang,
                'nama_varian'  => $request->nama_varian,
                'qty'          => $request->qty ?? 1,
                'harga_satuan' => $request->harga_satuan ?? 0,
                'tanggal_mulai'=> $request->tanggal_mulai ?? null,
                'tanggal_selesai'=> $request->tanggal_selesai ?? null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        return response()->json(['status' => 'sukses', 'pesan' => 'Berhasil masuk keranjang']);
    }

    // 2. Tampilkan isi keranjang
    public function index($id_pengguna)
    {
        $data = DB::table('keranjang')
            ->join('barang', 'keranjang.id_barang', '=', 'barang.id_barang')
            ->leftJoin('varian_barang', function ($join) {
                $join->on('keranjang.id_barang', '=', 'varian_barang.id_barang')
                     ->on('keranjang.nama_varian', '=', 'varian_barang.nama_varian');
            })
            ->where('keranjang.id_pengguna', $id_pengguna)
            ->select(
                'keranjang.*',
                'barang.nama_barang',
                'barang.gambar',
                'varian_barang.harga_sewa',
                'varian_barang.stok',
                'varian_barang.id_varian'
            )
            ->orderBy('keranjang.created_at', 'desc')
            ->get();

        $data->transform(function ($item) {
            if ($item->gambar) {
                $gambarArray = json_decode($item->gambar, true);
                $item->url_foto = (!empty($gambarArray) && is_array($gambarArray))
                    ? url('storage/barang/' . basename($gambarArray[0])) : null;
            } else {
                $item->url_foto = null;
            }
            unset($item->gambar);
            return $item;
        });

        return response()->json($data);
    }

    // 3. Update qty
    public function update(Request $request, $id)
    {
        DB::table('keranjang')->where('id_keranjang', $id)->update([
            'qty' => $request->qty,
            'tanggal_mulai' => $request->tanggal_mulai ?? null,
            'tanggal_selesai' => $request->tanggal_selesai ?? null,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'sukses']);
    }

    // 4. Hapus dari keranjang
    public function destroy($id)
    {
        DB::table('keranjang')->where('id_keranjang', $id)->delete();
        return response()->json(['status' => 'sukses']);
    }
}
