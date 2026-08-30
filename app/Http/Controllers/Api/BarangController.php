<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BarangController extends Controller
{
    // --- 1. MENGAMBIL DATA UNTUK DITAMPILKAN DI FLUTTER ---
    public function index()
    {
        $barang = DB::table('barang')->orderBy('id_barang', 'desc')->get();
        $now = now();
        
        $barang->transform(function ($item) use ($now) {
            $gambarArray = json_decode($item->gambar, true);
            
            // Sampul depan (Foto pertama)
            $item->url_foto = (!empty($gambarArray) && is_array($gambarArray)) 
                ? url('storage/barang/' . basename($gambarArray[0])) : null;
            
            // Semua foto untuk slider detail barang
            $item->semua_foto = [];
            if(!empty($gambarArray) && is_array($gambarArray)) {
                foreach($gambarArray as $g) {
                    $item->semua_foto[] = url('storage/barang/' . basename($g));
                }
            }

            // Ambil semua data Varian untuk barang ini
            $daftarVarian = DB::table('varian_barang')->where('id_barang', $item->id_barang)->get();
            $item->daftar_varian = $daftarVarian;
            
            // Hitung TOTAL STOK OTOMATIS (Penjumlahan stok dari seluruh varian)
            $item->stok = $daftarVarian->sum('stok');

            // Hitung total barang ini pernah disewa (mengecualikan yang ditolak)
            $item->jumlah_disewa = DB::table('pesanan')
                ->join('varian_barang', 'pesanan.id_varian', '=', 'varian_barang.id_varian')
                ->where('varian_barang.id_barang', $item->id_barang)
                ->where('pesanan.status_pesanan', '!=', 'ditolak')
                ->sum('pesanan.jumlah_pesan') ?? 0;

            // --- DISKON AKTIF ---
            $diskon = DB::table('diskon')
                ->where('id_barang', $item->id_barang)
                ->where('is_aktif', true)
                ->where('mulai', '<=', $now)
                ->where('akhir', '>=', $now)
                ->first();
            
            $item->diskon = $diskon;
            $item->persen_diskon = $diskon ? $diskon->persen : 0;

            // Harga setelah diskon (berdasarkan harga varian pertama / harga_sewa utama)
            if ($diskon && $item->harga_sewa) {
                $hargaAsli = (int) $item->harga_sewa;
                $item->harga_setelah_diskon = $hargaAsli - (int)($hargaAsli * $diskon->persen / 100);
            } else {
                $item->harga_setelah_diskon = $item->harga_sewa;
            }

            return $item;
        });

        return response()->json($barang);
    }

    // --- 2. MENYIMPAN BARANG BARU (BESERTA MULTI-FOTO & BANYAK VARIAN) ---
    public function store(Request $request)
    {
        $request->validate([
            'nama_barang' => 'required',
            'tipe_barang' => 'required',
            'foto.*'      => 'nullable|image|mimes:jpeg,png,jpg|max:5120', 
        ]);

        try {
            $idBarangBaru = 'BRG-' . strtoupper(Str::random(8));
            $paths = []; 

            // Simpan foto-foto fisik ke server
            if ($request->hasFile('foto')) {
                foreach ($request->file('foto') as $file) {
                    $paths[] = $file->store('barang', 'public');
                }
            }
            $namaFileDiDB = !empty($paths) ? json_encode($paths) : null;

            // Decode data varian yang dikirim dari form Flutter
            $varianArray = json_decode($request->varians, true) ?? [];

            // Ambil harga & kapasitas dari Varian Pertama sebagai data patokan di Induk
            $hargaUtama = count($varianArray) > 0 ? $varianArray[0]['harga'] : 0;
            $kapasitasUtama = count($varianArray) > 0 ? $varianArray[0]['kapasitas'] : '-';

            // Simpan ke Induk (Tabel 'barang')
            DB::table('barang')->insert([
                'id_barang'   => $idBarangBaru,
                'nama_barang' => $request->nama_barang,
                'tipe_barang' => $request->tipe_barang,
                'harga_sewa'  => $hargaUtama, 
                'kapasitas'   => $kapasitasUtama, 
                'deskripsi'   => $request->deskripsi,
                'gambar'      => $namaFileDiDB, 
            ]);

            // Simpan ke Anak (Tabel 'varian_barang')
            if (!empty($varianArray)) {
                foreach ($varianArray as $v) {
                    DB::table('varian_barang')->insert([
                        'id_barang'   => $idBarangBaru,
                        'nama_varian' => $v['kode'],
                        'harga_sewa'  => $v['harga'],
                        'kapasitas'   => $v['kapasitas'] ?: '-',
                        'stok'        => $v['stok']
                    ]);
                }
            }

            return response()->json(['status' => 'sukses', 'pesan' => 'Barang & Varian berhasil disimpan!'], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 3. FITUR ADMIN: TAMBAH VARIAN BARU SECARA SUSULAN ---
    public function tambahVarian(Request $request, $id_barang)
    {
        try {
            DB::table('varian_barang')->insert([
                'id_barang'   => $id_barang,
                'nama_varian' => $request->nama_varian,
                'harga_sewa'  => $request->harga_sewa,
                'kapasitas'   => $request->kapasitas ?: '-',
                'stok'        => $request->stok
            ]);
            
            return response()->json(['status' => 'sukses', 'pesan' => 'Varian baru berhasil ditambahkan!']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 4. FITUR CUSTOMER: PESAN BARANG (PENGURANGAN STOK DINAMIS) ---
    public function pesanBarang(Request $request, $id_barang)
    {
        // Tangkap ID Varian dan Jumlah Pesanan dari Flutter
        $id_varian = $request->id_varian;
        $jumlah_pesan = $request->jumlah_pesan ?? 1; // Jika tidak dikirim, default 1
        
        try {
            if ($id_varian) {
                $varian = DB::table('varian_barang')->where('id_varian', $id_varian)->first();
                
                // Cek apakah stoknya cukup untuk memenuhi jumlah pesanan
                if ($varian && $varian->stok >= $jumlah_pesan) {
                    
                    // Kurangi stok sebanyak jumlah yang dipesan
                    DB::table('varian_barang')
                        ->where('id_varian', $id_varian)
                        ->decrement('stok', $jumlah_pesan);
                        
                    return response()->json([
                        'status' => 'sukses', 
                        'pesan' => 'Berhasil dipesan sebanyak ' . $jumlah_pesan . ' unit!'
                    ]);
                } else {
                    return response()->json([
                        'status' => 'gagal', 
                        'pesan' => 'Stok tidak cukup! Sisa stok hanya: ' . ($varian ? $varian->stok : 0)
                    ], 400);
                }
            }

            return response()->json(['status' => 'gagal', 'pesan' => 'Pilih varian terlebih dahulu!'], 400);
            
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }
    // --- 5. FUNGSI UNTUK MENGAMBIL DAFTAR VARIAN (UNTUK POP-UP CHECKOUT FLUTTER) ---
    public function getVarian($id_barang)
    {
        try {
            $varian = DB::table('varian_barang') 
                        ->where('id_barang', $id_barang)
                        ->get();
                        
            return response()->json($varian);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 5B. FUNGSI UNTUK MENGAMBIL KATEGORI DINAMIS ---
    public function getKategori()
    {
        try {
            // Ambil semua tipe_barang yang unik dari tabel barang
            $kategori = DB::table('barang')
                        ->select('tipe_barang')
                        ->distinct()
                        ->whereNotNull('tipe_barang')
                        ->where('tipe_barang', '!=', '')
                        ->pluck('tipe_barang');
            
            return response()->json($kategori);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }
    // --- 6. FITUR ADMIN: HAPUS BARANG ---
    public function destroy($id_barang)
    {
        try {
            // 1. Hapus semua varian yang terkait dengan barang ini
            DB::table('varian_barang')->where('id_barang', $id_barang)->delete();
            
            // 2. Hapus barang induk
            $dihapus = DB::table('barang')->where('id_barang', $id_barang)->delete();
            
            if ($dihapus) {
                return response()->json(['status' => 'sukses', 'pesan' => 'Barang berhasil dihapus dari katalog!']);
            } else {
                return response()->json(['status' => 'gagal', 'pesan' => 'Barang tidak ditemukan.'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
        }
    }
    // --- 7. FITUR ADMIN: UPDATE VARIAN (Ganti Stok / Harga) ---
    public function updateVarian(Request $request, $id_varian)
    {
        try {
            DB::table('varian_barang')->where('id_varian', $id_varian)->update([
                'nama_varian' => $request->nama_varian,
                'harga_sewa'  => $request->harga_sewa,
                'kapasitas'   => $request->kapasitas ?: '-',
                'stok'        => $request->stok
            ]);
            return response()->json(['status' => 'sukses', 'pesan' => 'Varian berhasil diperbarui!']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 8. FITUR ADMIN: HAPUS VARIAN ---
    public function hapusVarian($id_varian)
    {
        try {
            DB::table('varian_barang')->where('id_varian', $id_varian)->delete();
            return response()->json(['status' => 'sukses', 'pesan' => 'Varian berhasil dihapus!']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }
}
