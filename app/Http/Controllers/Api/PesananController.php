<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PesananController extends Controller
{
    // --- 1. MENGAMBIL DATA PESANAN (VERSI SUPER AMAN) ---
    public function index()
    {
        // Kita cabut leftJoin('users') yang bikin error.
        // Kita ambil data dari pesanan, varian, dan barang yang sudah PASTI ADA.
        $pesanan = DB::table('pesanan')
            ->leftJoin('varian_barang', 'pesanan.id_varian', '=', 'varian_barang.id_varian')
            ->leftJoin('barang', 'varian_barang.id_barang', '=', 'barang.id_barang')
            ->leftJoin('pengguna', 'pesanan.id_user', '=', 'pengguna.id')
            ->select(
                'pesanan.*', 
                'varian_barang.nama_varian', 
                'barang.nama_barang', 
                'barang.gambar',
                'pengguna.foto_profil as foto_profil_user'
            )
            ->orderBy('pesanan.tanggal_pesan', 'desc')
            ->get();

        $pesanan->transform(function ($item) {
            if ($item->gambar) {
                $gambarArray = json_decode($item->gambar, true);
                $item->url_foto = (!empty($gambarArray) && is_array($gambarArray)) 
                    ? url('storage/barang/' . basename($gambarArray[0])) : null;
            } else {
                $item->url_foto = null;
            }
            
            $item->url_bukti_tf = !empty($item->bukti_tf_dp) ? url('storage/pembayaran/' . $item->bukti_tf_dp) : null;
            $item->url_foto_ktp = !empty($item->foto_ktp) ? url('storage/ktp/' . $item->foto_ktp) : null;
            
            // Rekonstruksi foto_profil_user
            if ($item->foto_profil_user && !str_starts_with($item->foto_profil_user, 'http')) {
                $item->foto_profil_user = url('storage/profil/' . $item->foto_profil_user);
            }
            
            return $item;
        });

        return response()->json($pesanan);
    }

    // --- 2. PROSES SIMPAN PESANAN (CHECKOUT) WITH MIDTRANS SNAP ---
    public function store(Request $request)
    {
        try {
            // 1. Ambil data varian barang beserta data induk barangnya
            $varian = DB::table('varian_barang')->where('id_varian', $request->id_varian)->first();
            
            if ($varian && $varian->stok >= $request->jumlah_pesan) {
                // Cek diskon aktif untuk barang ini
                $now = now();
                $diskonAktif = DB::table('diskon')
                    ->where('id_barang', $varian->id_barang)
                    ->where('is_aktif', true)
                    ->where('mulai', '<=', $now)
                    ->where('akhir', '>=', $now)
                    ->first();

                $hargaSatuan = $varian->harga_sewa;
                $persenDiskon = 0;
                $hargaSatuanSetelahDiskon = $hargaSatuan;

                if ($diskonAktif) {
                    $persenDiskon = $diskonAktif->persen;
                    $hargaSatuanSetelahDiskon = $hargaSatuan - (int)($hargaSatuan * $persenDiskon / 100);
                }

                $totalHarga = $hargaSatuanSetelahDiskon * $request->jumlah_pesan * $request->lama_sewa;
                $dp = (int) round($totalHarga / 2);
                $idPesananBaru = 'ORD-' . strtoupper(Str::random(6));
                $idTransaksiBaru = 'TRC-' . strtoupper(Str::random(6));
                $expiredAt = now()->addMinutes(30);

                // --- GENERATE MIDTRANS SNAP TOKEN ---
                \Midtrans\Config::$serverKey    = config('services.midtrans.server_key');
                \Midtrans\Config::$isProduction = config('services.midtrans.is_production');
                \Midtrans\Config::$isSanitized  = config('services.midtrans.is_sanitized');
                \Midtrans\Config::$is3ds        = config('services.midtrans.is_3ds');

                $barangIndukTemp = DB::table('barang')->where('id_barang', $varian->id_barang)->first();
                $namaBarangTemp  = $barangIndukTemp ? $barangIndukTemp->nama_barang : 'Sewa Alat Camping';

                $midtransParams = [
                    'transaction_details' => [
                        'order_id'     => $idTransaksiBaru,
                        'gross_amount' => $dp, // Hanya bayar DP (50%)
                    ],
                    'item_details' => [
                        [
                            'id'       => $varian->id_varian,
                            'price'    => $dp,
                            'quantity' => 1,
                            'name'     => 'DP Sewa: ' . $namaBarangTemp . ' (' . $varian->nama_varian . ')',
                        ],
                    ],
                    'customer_details' => [
                        'first_name' => $request->nama_pemesan ?? 'Pelanggan',
                        'email'      => $request->email_pemesan ?? 'pelanggan@cample.id',
                    ],
                    'expiry' => [
                        'start_time' => now()->format('Y-m-d H:i:s O'),
                        'unit'       => 'minute',
                        'duration'   => 30,
                    ],
                ];

                $snapToken = \Midtrans\Snap::getSnapToken($midtransParams);
                $snapUrl   = 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . $snapToken;
                // --- AKHIR MIDTRANS ---

                // Simpan transaksi master
                DB::table('transaksi')->insert([
                    'id_transaksi'      => $idTransaksiBaru,
                    'id_user'           => $request->id_user ?? null,
                    'nama_pemesan'      => $request->nama_pemesan ?? 'User Tanpa Nama',
                    'total_harga'       => $totalHarga,
                    'total_dp'          => $dp,
                    'sisa_tagihan'      => $totalHarga - $dp,
                    'metode_pembayaran' => 'Midtrans',
                    'snap_url'          => $snapUrl,
                    'snap_token'        => $snapToken,
                    'expired_at'        => $expiredAt,
                    'status_transaksi'  => 'menunggu_dp',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                // Simpan transaksi detail ke tabel pesanan
                DB::table('pesanan')->insert([
                    'id_pesanan'        => $idPesananBaru,
                    'id_transaksi'      => $idTransaksiBaru,
                    'id_user'           => $request->id_user ?? null,
                    'nama_pemesan'      => $request->nama_pemesan ?? 'User Tanpa Nama',
                    'id_varian'         => $request->id_varian,
                    'jumlah_pesan'      => $request->jumlah_pesan,
                    'lama_sewa'         => $request->lama_sewa,
                    'tanggal_mulai'     => $request->tanggal_mulai,
                    'tanggal_selesai'   => $request->tanggal_selesai,
                    'total_harga'       => $totalHarga,
                    'dp_dibayar'        => $dp,
                    'sisa_tagihan'      => $totalHarga - $dp,
                    'metode_pembayaran' => 'Midtrans',
                    'status_pesanan'    => 'menunggu_dp',
                    'diskon_persen'     => $persenDiskon,
                    'harga_satuan_asli' => $hargaSatuan,
                    'tanggal_pesan'     => now()
                ]);

                // --- LOGIKA CHATBOT OTOMATIS ---
                $barangInduk = DB::table('barang')->where('id_barang', $varian->id_barang)->first();
                $namaBarang  = $barangInduk ? $barangInduk->nama_barang : 'Alat Camping';
                
                $urlFoto = '';
                if ($barangInduk && $barangInduk->gambar) {
                    $gambarArray = json_decode($barangInduk->gambar, true);
                    if (!empty($gambarArray) && is_array($gambarArray)) {
                        $urlFoto = url('storage/barang/' . basename($gambarArray[0]));
                    }
                }

                $pesanBot = "🤖 *NOTIFIKASI SISTEM CAMPLE*\n\n"
                          . "Halo kak! Terima kasih sudah menyewa di Cample 🥰🙏.\n"
                          . "Pesanan Anda telah kami terima dan menunggu pembayaran DP.\n\n"
                          . "📦 *Detail Barang yang Disewa:*\n"
                          . "• Nama Alat: " . $namaBarang . " (" . $varian->nama_varian . ")\n"
                          . "• Jumlah: " . $request->jumlah_pesan . " unit\n"
                          . "• Lama Sewa: " . $request->lama_sewa . " Hari\n"
                          . "• Total Harga: *Rp " . number_format($totalHarga, 0, ',', '.') . "*\n"
                          . "• DP yang harus dibayar: *Rp " . number_format($dp, 0, ',', '.') . "*\n\n"
                          . "Silakan selesaikan pembayaran DP dalam 30 menit.\n"
                          . "Jika ada pertanyaan, silakan langsung balas chat ini ya! 😊";

                if ($request->id_user) {
                    \App\Http\Controllers\Api\ChatController::kirimPesanBot($request->id_user, $pesanBot);
                }

                DB::table('chat_messages')->insert([
                    'id_user'    => $request->id_user,
                    'pengirim'   => 'Sistem',
                    'pesan'      => 'Kartu Pesanan',
                    'tipe_pesan' => 'kartu_pesanan',
                    'id_pesanan' => $idTransaksiBaru,
                    'tanggal'    => now()->addSecond()
                ]);

                return response()->json([
                    'status'      => 'sukses',
                    'pesan'       => 'Pesanan berhasil dibuat!',
                    'id_transaksi' => $idTransaksiBaru,
                    'snap_url'    => $snapUrl,
                    'snap_token'  => $snapToken,
                    'expired_at'  => $expiredAt->toIso8601String(),
                    'total_dp'    => $dp,
                    'nama_barang' => $namaBarang . ' (' . $varian->nama_varian . ')',
                ]);
            }
            
            return response()->json(['status' => 'gagal', 'pesan' => 'Stok tidak cukup!'], 400);
            
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 2.5 UPLOAD BUKTI TF DP ---
    public function uploadBuktiTfDp(Request $request, $id_pesanan)
    {
        try {
            $request->validate([
                'bukti_tf_dp' => 'required|image|mimes:jpeg,png,jpg|max:5120'
            ]);

            $pesanan = DB::table('pesanan')->where('id_pesanan', $id_pesanan)->first();
            if (!$pesanan) {
                return response()->json(['status' => 'gagal', 'pesan' => 'Pesanan tidak ditemukan'], 404);
            }

            if ($request->hasFile('bukti_tf_dp')) {
                $file = $request->file('bukti_tf_dp');
                $filename = time() . '_tf_dp_' . $file->getClientOriginalName();
                // Simpan di public disk (storage/app/public/pembayaran)
                $file->storeAs('pembayaran', $filename, 'public');

                DB::table('pesanan')->where('id_pesanan', $id_pesanan)->update([
                    'bukti_tf_dp' => $filename,
                    'status_pesanan' => 'menunggu_konfirmasi' // Langsung berubah agar admin bisa ACC
                ]);

                return response()->json(['status' => 'sukses', 'pesan' => 'Bukti transfer berhasil diunggah!']);
            }

            return response()->json(['status' => 'gagal', 'pesan' => 'Tidak ada file yang diunggah'], 400);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 3. MENGAMBIL RIWAYAT CHAT ---
    public function getChats()
    {
        $chats = DB::table('chat_messages')
            ->leftJoin('pesanan', 'chat_messages.id_pesanan', '=', 'pesanan.id_pesanan')
            ->leftJoin('varian_barang', 'pesanan.id_varian', '=', 'varian_barang.id_varian')
            ->leftJoin('barang', 'varian_barang.id_barang', '=', 'barang.id_barang')
            ->select(
                'chat_messages.*', 
                'pesanan.jumlah_pesan', 'pesanan.lama_sewa', 'pesanan.dp_dibayar', 'pesanan.status_pesanan',
                'varian_barang.nama_varian', 
                'barang.nama_barang', 'barang.gambar'
            )
            ->orderBy('chat_messages.tanggal', 'asc')
            ->get();

        $chats->transform(function ($item) {
            if ($item->gambar) {
                $gambarArray = json_decode($item->gambar, true);
                $item->url_foto = (!empty($gambarArray) && is_array($gambarArray)) 
                    ? url('storage/barang/' . basename($gambarArray[0])) : null;
            }
            return $item;
        });

        return response()->json($chats);
    }

    // --- 4. UPDATE STATUS (ADMIN) ---
    public function updateStatus(Request $request, $id)
    {
        $statusBaru = $request->status; 
        
        $transaksi = DB::table('transaksi')->where('id_transaksi', $id)->first();
        if ($transaksi) {
            $pesanans = DB::table('pesanan')->where('id_transaksi', $id)->get();
            
            foreach ($pesanans as $pesanan) {
                // Saat admin konfirmasi DP -> stok BERKURANG (status: akan_diambil)
                if ($statusBaru == 'akan_diambil') {
                    DB::table('varian_barang')->where('id_varian', $pesanan->id_varian)->decrement('stok', $pesanan->jumlah_pesan);
                }
                
                // Saat barang selesai dikembalikan -> stok DIPULIHKAN (status: selesai)
                if ($statusBaru == 'selesai') {
                    DB::table('varian_barang')->where('id_varian', $pesanan->id_varian)->increment('stok', $pesanan->jumlah_pesan);
                }

                // Jika ditolak, kembalikan stok juga (jika sebelumnya sudah dikurangi)
                if ($statusBaru == 'ditolak' && $transaksi->status_transaksi == 'akan_diambil') {
                    DB::table('varian_barang')->where('id_varian', $pesanan->id_varian)->increment('stok', $pesanan->jumlah_pesan);
                }
            }
            
            DB::table('transaksi')->where('id_transaksi', $id)->update(['status_transaksi' => $statusBaru]);
            DB::table('pesanan')->where('id_transaksi', $id)->update(['status_pesanan' => $statusBaru]);
            
            return response()->json(['status' => 'sukses', 'pesan' => 'Status transaksi berhasil diubah!']);
        }

        // --- FALLBACK UNTUK PESANAN LAMA YANG BELUM ADA TRANSAKSI ---
        $pesanan = DB::table('pesanan')->where('id_pesanan', $id)->first();
        if (!$pesanan) {
            return response()->json(['status' => 'gagal', 'pesan' => 'Pesanan tidak ditemukan'], 404);
        }

        if ($statusBaru == 'akan_diambil') {
            DB::table('varian_barang')->where('id_varian', $pesanan->id_varian)->decrement('stok', $pesanan->jumlah_pesan);
        }
        if ($statusBaru == 'selesai') {
            DB::table('varian_barang')->where('id_varian', $pesanan->id_varian)->increment('stok', $pesanan->jumlah_pesan);
        }
        if ($statusBaru == 'ditolak' && $pesanan->status_pesanan == 'akan_diambil') {
            DB::table('varian_barang')->where('id_varian', $pesanan->id_varian)->increment('stok', $pesanan->jumlah_pesan);
        }
        
        DB::table('pesanan')->where('id_pesanan', $id)->update(['status_pesanan' => $statusBaru]);
        return response()->json(['status' => 'sukses', 'pesan' => 'Status berhasil diubah!']);
    }

    // --- 5. UPLOAD PELUNASAN (Admin: nominal + foto KTP) ---
    public function uploadPelunasan(Request $request, $id)
    {
        try {
            $request->validate([
                'foto_ktp'          => 'required|image|mimes:jpeg,png,jpg|max:5120',
                'nominal_pelunasan' => 'required|numeric',
                'metode_pelunasan'  => 'nullable|string',
            ]);

            if (!$request->hasFile('foto_ktp')) {
                return response()->json(['status' => 'gagal', 'pesan' => 'Foto KTP tidak ditemukan'], 400);
            }

            $file = $request->file('foto_ktp');
            $filename = time() . '_ktp_' . $file->getClientOriginalName();
            $file->storeAs('ktp', $filename, 'public');

            // 1. Coba Cari Transaksi (Baru)
            $transaksi = DB::table('transaksi')->where('id_transaksi', $id)->first();
            if ($transaksi) {
                DB::table('transaksi')->where('id_transaksi', $id)->update([
                    'foto_ktp'          => $filename,
                    'nominal_pelunasan' => $request->nominal_pelunasan,
                    'metode_pelunasan'  => $request->metode_pelunasan ?? 'Transfer',
                    'tanggal_pelunasan' => now(),
                    'status_transaksi'  => 'disewa',
                ]);
                
                DB::table('pesanan')->where('id_transaksi', $id)->update([
                    'foto_ktp'          => $filename,
                    'nominal_pelunasan' => $request->nominal_pelunasan,
                    'metode_pelunasan'  => $request->metode_pelunasan ?? 'Transfer',
                    'tanggal_pelunasan' => now(),
                    'status_pesanan'    => 'disewa',
                ]);

                return response()->json(['status' => 'sukses', 'pesan' => 'Pelunasan Transaksi berhasil dicatat!']);
            }

            // 2. Coba Cari Pesanan (Lama)
            $pesanan = DB::table('pesanan')->where('id_pesanan', $id)->first();
            if ($pesanan) {
                DB::table('pesanan')->where('id_pesanan', $id)->update([
                    'foto_ktp'          => $filename,
                    'nominal_pelunasan' => $request->nominal_pelunasan,
                    'metode_pelunasan'  => $request->metode_pelunasan ?? 'Transfer',
                    'tanggal_pelunasan' => now(),
                    'status_pesanan'    => 'disewa',
                ]);
                return response()->json(['status' => 'sukses', 'pesan' => 'Pelunasan Pesanan berhasil dicatat!']);
            }

            return response()->json(['status' => 'gagal', 'pesan' => 'Pesanan/Transaksi tidak ditemukan'], 404);

        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }
    
    // --- 6. BULK CHECKOUT (MASTER-DETAIL) ---
    public function bulkStore(Request $request)
    {
        try {
            DB::beginTransaction();

            $items = $request->items; // Array of items
            if (!$items || !is_array($items) || count($items) == 0) {
                return response()->json(['status' => 'gagal', 'pesan' => 'Tidak ada barang yang dipilih'], 400);
            }

            $idTransaksiBaru = 'TRC-' . strtoupper(Str::random(6));
            $totalHargaTransaksi = 0;
            $namaPemesan = $request->nama_pemesan ?? 'User Tanpa Nama';

            $pesananTerbuat = [];
            $pesanBotDetails = "";

            foreach ($items as $item) {
                $varian = DB::table('varian_barang')->where('id_varian', $item['id_varian'])->first();
                if (!$varian || $varian->stok < $item['jumlah_pesan']) {
                    DB::rollBack();
                    return response()->json(['status' => 'gagal', 'pesan' => 'Stok tidak cukup untuk varian ID: ' . $item['id_varian']], 400);
                }

                $now = now();
                $diskonAktif = DB::table('diskon')
                    ->where('id_barang', $varian->id_barang)
                    ->where('is_aktif', true)
                    ->where('mulai', '<=', $now)
                    ->where('akhir', '>=', $now)
                    ->first();

                $hargaSatuan = $varian->harga_sewa;
                $persenDiskon = $diskonAktif ? $diskonAktif->persen : 0;
                $hargaSatuanSetelahDiskon = $hargaSatuan - (int)($hargaSatuan * $persenDiskon / 100);

                $totalHargaPesanan = $hargaSatuanSetelahDiskon * $item['jumlah_pesan'] * $item['lama_sewa'];
                $dpPesanan = $totalHargaPesanan / 2;
                
                $totalHargaTransaksi += $totalHargaPesanan;
                $idPesananBaru = 'ORD-' . strtoupper(Str::random(6));

                DB::table('pesanan')->insert([
                    'id_pesanan'        => $idPesananBaru,
                    'id_transaksi'      => $idTransaksiBaru,
                    'id_user'           => $request->id_user ?? null,
                    'nama_pemesan'      => $namaPemesan,
                    'id_varian'         => $item['id_varian'],
                    'jumlah_pesan'      => $item['jumlah_pesan'],
                    'lama_sewa'         => $item['lama_sewa'],
                    'tanggal_mulai'     => $item['tanggal_mulai'] ?? null,
                    'tanggal_selesai'   => $item['tanggal_selesai'] ?? null,
                    'total_harga'       => $totalHargaPesanan,
                    'dp_dibayar'        => $dpPesanan,
                    'sisa_tagihan'      => $totalHargaPesanan - $dpPesanan,
                    'metode_pembayaran' => 'Midtrans',
                    'status_pesanan'    => 'menunggu_dp',
                    'diskon_persen'     => $persenDiskon,
                    'harga_satuan_asli' => $hargaSatuan,
                    'tanggal_pesan'     => now()
                ]);

                $barangInduk = DB::table('barang')->where('id_barang', $varian->id_barang)->first();
                $namaBarang = $barangInduk ? $barangInduk->nama_barang : 'Alat Camping';
                $pesanBotDetails .= "• " . $namaBarang . " (" . $varian->nama_varian . ") - " . $item['jumlah_pesan'] . " unit, " . $item['lama_sewa'] . " hr\n";
            }

            $totalDpTransaksi = (int) round($totalHargaTransaksi / 2);
            $expiredAt        = now()->addMinutes(30);

            // --- GENERATE MIDTRANS SNAP TOKEN (BULK) ---
            \Midtrans\Config::$serverKey    = config('services.midtrans.server_key');
            \Midtrans\Config::$isProduction = config('services.midtrans.is_production');
            \Midtrans\Config::$isSanitized  = config('services.midtrans.is_sanitized');
            \Midtrans\Config::$is3ds        = config('services.midtrans.is_3ds');

            $midtransParams = [
                'transaction_details' => [
                    'order_id'     => $idTransaksiBaru,
                    'gross_amount' => $totalDpTransaksi,
                ],
                'item_details' => [
                    [
                        'id'       => $idTransaksiBaru,
                        'price'    => $totalDpTransaksi,
                        'quantity' => 1,
                        'name'     => 'DP Sewa Bulk (' . count($items) . ' barang)',
                    ],
                ],
                'customer_details' => [
                    'first_name' => $namaPemesan,
                    'email'      => $request->email_pemesan ?? 'pelanggan@cample.id',
                ],
                'expiry' => [
                    'start_time' => now()->format('Y-m-d H:i:s O'),
                    'unit'       => 'minute',
                    'duration'   => 30,
                ],
            ];

            $snapToken = \Midtrans\Snap::getSnapToken($midtransParams);
            $snapUrl   = 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . $snapToken;
            // --- AKHIR MIDTRANS BULK ---

            // Buat Transaksi Master
            DB::table('transaksi')->insert([
                'id_transaksi'      => $idTransaksiBaru,
                'id_user'           => $request->id_user ?? null,
                'nama_pemesan'      => $namaPemesan,
                'total_harga'       => $totalHargaTransaksi,
                'total_dp'          => $totalDpTransaksi,
                'sisa_tagihan'      => $totalHargaTransaksi - $totalDpTransaksi,
                'metode_pembayaran' => 'Midtrans',
                'snap_url'          => $snapUrl,
                'snap_token'        => $snapToken,
                'expired_at'        => $expiredAt,
                'status_transaksi'  => 'menunggu_dp',
                'created_at'        => now(),
                'updated_at'        => now()
            ]);

            // Kirim Chatbot
            $pesanBot = "🤖 *NOTIFIKASI SISTEM CAMPLE*\n\n"
                . "Halo kak! Terima kasih sudah menyewa di Cample 🥰🙏.\n"
                . "Checkout untuk " . count($items) . " barang berhasil!\n"
                . "DP yang harus dibayar: *Rp " . number_format($totalDpTransaksi, 0, ',', '.') . "*.\n\n"
                . "📦 *Detail Barang yang Disewa:*\n"
                . $pesanBotDetails . "\n"
                . "• Total Harga: *Rp " . number_format($totalHargaTransaksi, 0, ',', '.') . "*\n\n"
                . "Silakan selesaikan pembayaran DP dalam 30 menit. 😊";

            if ($request->id_user) {
                \App\Http\Controllers\Api\ChatController::kirimPesanBot($request->id_user, $pesanBot);
            }

            DB::table('chat_messages')->insert([
                'id_user'    => $request->id_user,
                'pengirim'   => 'Sistem',
                'pesan'      => 'Kartu Pesanan',
                'tipe_pesan' => 'kartu_pesanan',
                'id_pesanan' => $idTransaksiBaru,
                'tanggal'    => now()->addSecond()
            ]);

            DB::commit();
            return response()->json([
                'status'       => 'sukses',
                'pesan'        => 'Pesanan bulk berhasil dibuat!',
                'id_transaksi' => $idTransaksiBaru,
                'snap_url'     => $snapUrl,
                'snap_token'   => $snapToken,
                'expired_at'   => $expiredAt->toIso8601String(),
                'total_dp'     => $totalDpTransaksi,
                'nama_barang'  => 'Sewa Bulk (' . count($items) . ' barang)',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 7. MENGAMBIL DAFTAR TRANSAKSI (MASTER) ---
    public function getTransaksi()
    {
        $transaksi = DB::table('transaksi')
            ->leftJoin('pengguna', 'transaksi.id_user', '=', 'pengguna.id')
            ->select('transaksi.*', 'pengguna.foto_profil')
            ->orderBy('transaksi.created_at', 'desc')
            ->get();

        $transaksi->transform(function ($item) {
            $item->url_bukti_tf_dp = !empty($item->bukti_tf_dp) ? url('storage/pembayaran/' . $item->bukti_tf_dp) : null;
            $item->url_foto_ktp = !empty($item->foto_ktp) ? url('storage/ktp/' . $item->foto_ktp) : null;
            
            if ($item->foto_profil && !str_starts_with($item->foto_profil, 'http')) {
                $item->foto_profil = url('storage/profil/' . $item->foto_profil);
            }
            
            // Ambil detail pesanan
            $item->detail_pesanan = DB::table('pesanan')
                ->leftJoin('varian_barang', 'pesanan.id_varian', '=', 'varian_barang.id_varian')
                ->leftJoin('barang', 'varian_barang.id_barang', '=', 'barang.id_barang')
                ->where('pesanan.id_transaksi', $item->id_transaksi)
                ->select('pesanan.*', 'varian_barang.nama_varian', 'barang.nama_barang', 'barang.gambar')
                ->orderBy('pesanan.tanggal_pesan', 'asc')
                ->get();
            
            foreach ($item->detail_pesanan as $detail) {
                 if ($detail->gambar) {
                     $gambarArray = json_decode($detail->gambar, true);
                     $detail->url_foto = (!empty($gambarArray) && is_array($gambarArray)) 
                         ? url('storage/barang/' . basename($gambarArray[0])) : null;
                 }
                 if (!empty($detail->foto_pengembalian)) {
                     $detail->url_foto_pengembalian = url('storage/pengembalian/' . $detail->foto_pengembalian);
                 } else {
                     $detail->url_foto_pengembalian = null;
                 }
            }
            
            // Hitung total denda untuk transaksi (diambil dari semua detail pesanan)
            $item->denda_keterlambatan = $item->detail_pesanan->sum('denda_keterlambatan');
            $item->denda_kerusakan = $item->detail_pesanan->sum('denda_kerusakan');
            
            return $item;
        });

        // --- AMBIL PESANAN LAMA (ORPHAN) YANG TIDAK PUNYA TRANSAKSI ---
        // Pesanan yang dibuat sebelum sistem transaksi diimplementasi
        // id_transaksi pada pesanan lama berisi id_pesanan itu sendiri atau kosong/null
        $idTransaksiYangAda = DB::table('transaksi')->pluck('id_transaksi')->toArray();
        
        $pesananLama = DB::table('pesanan')
            ->leftJoin('varian_barang', 'pesanan.id_varian', '=', 'varian_barang.id_varian')
            ->leftJoin('barang', 'varian_barang.id_barang', '=', 'barang.id_barang')
            ->leftJoin('pengguna', 'pesanan.id_user', '=', 'pengguna.id')
            ->where(function ($q) use ($idTransaksiYangAda) {
                // Pesanan yang id_transaksinya tidak ada di tabel transaksi
                $q->whereNull('pesanan.id_transaksi')
                  ->orWhereNotIn('pesanan.id_transaksi', $idTransaksiYangAda);
            })
            ->select(
                'pesanan.*',
                'varian_barang.nama_varian',
                'barang.nama_barang',
                'barang.gambar',
                'pengguna.foto_profil'
            )
            ->orderBy('pesanan.tanggal_pesan', 'desc')
            ->get();

        // Ubah setiap pesanan lama menjadi format "virtual transaksi"
        foreach ($pesananLama as $p) {
            $gambarArray = $p->gambar ? json_decode($p->gambar, true) : [];
            $urlFoto = (!empty($gambarArray) && is_array($gambarArray))
                ? url('storage/barang/' . basename($gambarArray[0])) : null;

            $detailItem = (object)[
                'id_pesanan'       => $p->id_pesanan,
                'id_transaksi'     => $p->id_transaksi ?? $p->id_pesanan,
                'id_varian'        => $p->id_varian,
                'id_user'          => $p->id_user,
                'nama_pemesan'     => $p->nama_pemesan,
                'nama_barang'      => $p->nama_barang,
                'nama_varian'      => $p->nama_varian,
                'jumlah_pesan'     => $p->jumlah_pesan,
                'lama_sewa'        => $p->lama_sewa,
                'tanggal_mulai'    => $p->tanggal_mulai ?? null,
                'tanggal_selesai'  => $p->tanggal_selesai ?? null,
                'total_harga'      => $p->total_harga,
                'dp_dibayar'       => $p->dp_dibayar,
                'sisa_tagihan'     => $p->sisa_tagihan,
                'harga_satuan_asli'=> $p->harga_satuan_asli ?? 0,
                'diskon_persen'    => $p->diskon_persen ?? 0,
                'status_pesanan'   => $p->status_pesanan,
                'bukti_tf_dp'      => $p->bukti_tf_dp,
                'foto_ktp'         => $p->foto_ktp,
                'tanggal_pesan'    => $p->tanggal_pesan,
                'gambar'           => $p->gambar,
                'url_foto'         => $urlFoto,
            ];

            $virtualTransaksi = (object)[
                'id_transaksi'      => $p->id_transaksi ?? $p->id_pesanan,
                'id_user'           => $p->id_user,
                'nama_pemesan'      => $p->nama_pemesan ?? 'User',
                'total_harga'       => $p->total_harga,
                'total_dp'          => $p->dp_dibayar,
                'sisa_tagihan'      => $p->sisa_tagihan,
                'denda_keterlambatan' => $p->denda_keterlambatan ?? 0,
                'denda_kerusakan'   => $p->denda_kerusakan ?? 0,
                'metode_pembayaran' => $p->metode_pembayaran ?? '-',
                'status_transaksi'  => $p->status_pesanan,
                'bukti_tf_dp'       => $p->bukti_tf_dp,
                'foto_ktp'          => $p->foto_ktp,
                'created_at'        => $p->tanggal_pesan,
                'updated_at'        => $p->tanggal_pesan,
                'foto_profil'       => $p->foto_profil ?? null,
                'url_bukti_tf_dp'   => !empty($p->bukti_tf_dp) ? url('storage/pembayaran/' . $p->bukti_tf_dp) : null,
                'url_foto_ktp'      => !empty($p->foto_ktp) ? url('storage/ktp/' . $p->foto_ktp) : null,
                'detail_pesanan'    => collect([$detailItem]),
            ];

            $transaksi->push($virtualTransaksi);
        }

        // Urutkan ulang berdasarkan created_at
        $transaksi = $transaksi->sortByDesc('created_at')->values();

        return response()->json($transaksi);
    }

    // --- 8. UPLOAD BUKTI TF DP TRANSAKSI ---
    public function uploadBuktiTfDpTransaksi(Request $request, $id_transaksi)
    {
        try {
            $request->validate([
                'bukti_tf_dp' => 'required|image|mimes:jpeg,png,jpg|max:5120'
            ]);

            $transaksi = DB::table('transaksi')->where('id_transaksi', $id_transaksi)->first();
            if (!$transaksi) {
                return response()->json(['status' => 'gagal', 'pesan' => 'Transaksi tidak ditemukan'], 404);
            }

            if ($request->hasFile('bukti_tf_dp')) {
                $file = $request->file('bukti_tf_dp');
                $filename = time() . '_tf_dp_trc_' . $file->getClientOriginalName();
                $file->storeAs('pembayaran', $filename, 'public');

                DB::table('transaksi')->where('id_transaksi', $id_transaksi)->update([
                    'bukti_tf_dp' => $filename,
                    'status_transaksi' => 'menunggu_konfirmasi',
                    'updated_at' => now()
                ]);

                // Update status pesanan di dalamnya juga
                DB::table('pesanan')->where('id_transaksi', $id_transaksi)->update([
                    'status_pesanan' => 'menunggu_konfirmasi'
                ]);

                return response()->json(['status' => 'sukses', 'pesan' => 'Bukti transfer berhasil diunggah!']);
            }

            return response()->json(['status' => 'gagal', 'pesan' => 'Tidak ada file yang diunggah'], 400);
        } catch (\Exception $e) {
            return response()->json(['status' => 'gagal', 'pesan' => $e->getMessage()], 500);
        }
    }
    // --- 9. MENGAMBIL DAFTAR TRANSAKSI USER TERTENTU ---
    public function getTransaksiUser($id_user)
    {
        $transaksi = DB::table('transaksi')
            ->where('id_user', $id_user)
            ->orderBy('created_at', 'desc')
            ->get();

        $transaksi->transform(function ($item) {
            $item->url_bukti_tf_dp = !empty($item->bukti_tf_dp) ? url('storage/pembayaran/' . $item->bukti_tf_dp) : null;
            $item->url_foto_ktp = !empty($item->foto_ktp) ? url('storage/ktp/' . $item->foto_ktp) : null;
            
            // Ambil detail pesanan
            $item->detail_pesanan = DB::table('pesanan')
                ->leftJoin('varian_barang', 'pesanan.id_varian', '=', 'varian_barang.id_varian')
                ->leftJoin('barang', 'varian_barang.id_barang', '=', 'barang.id_barang')
                ->where('pesanan.id_transaksi', $item->id_transaksi)
                ->select('pesanan.*', 'varian_barang.nama_varian', 'barang.nama_barang', 'barang.gambar')
                ->orderBy('pesanan.tanggal_pesan', 'asc')
                ->get();
            
            foreach ($item->detail_pesanan as $detail) {
                 if ($detail->gambar) {
                     $gambarArray = json_decode($detail->gambar, true);
                     $detail->url_foto = (!empty($gambarArray) && is_array($gambarArray)) 
                         ? url('storage/barang/' . basename($gambarArray[0])) : null;
                 }
                 if (!empty($detail->foto_pengembalian)) {
                     $detail->url_foto_pengembalian = url('storage/pengembalian/' . $detail->foto_pengembalian);
                 } else {
                     $detail->url_foto_pengembalian = null;
                 }
            }
            
            return $item;
        });

        return response()->json($transaksi);
    }

    // --- 10. KONFIRMASI PENGEMBALIAN BARANG (DENGAN DENDA) ---
    public function konfirmasiPengembalian(Request $request, $id_transaksi)
    {
        // Parameter request:
        // items: array of object { id_pesanan, kondisi, denda_kerusakan, keterangan, foto (file) }
        // Untuk form-data, bisa dikirim sebagai items[0][id_pesanan], items[0][kondisi], dst.
        
        try {
            DB::beginTransaction();
            
            $now = Carbon::now();
            $itemsData = [];
            
            // Mengurai input karena dikirim via form-data (yang bisa jadi array atau string JSON tergantung frontend)
            $items = $request->input('items', []);
            
            if (empty($items)) {
                // Mungkin dikirim format lain, kita asumsikan update semua pesanan di transaksi jika kosong
                $pesananList = DB::table('pesanan')->where('id_transaksi', $id_transaksi)->get();
                foreach ($pesananList as $pes) {
                    $items[] = [
                        'id_pesanan' => $pes->id_pesanan,
                        'kondisi' => 'Normal',
                        'denda_kerusakan' => 0,
                        'keterangan' => ''
                    ];
                }
            }

            foreach ($items as $index => $itemData) {
                // Pastikan $itemData adalah array (jika form-data mungkin formatnya beda)
                if (is_string($itemData)) {
                    $itemData = json_decode($itemData, true);
                }
                
                $id_pesanan = $itemData['id_pesanan'];
                $kondisi = $itemData['kondisi'] ?? 'Normal';
                $denda_kerusakan = (int)($itemData['denda_kerusakan'] ?? 0);
                $keterangan = $itemData['keterangan'] ?? null;
                
                // Ambil data pesanan untuk menghitung telat
                $pesanan = DB::table('pesanan')->where('id_pesanan', $id_pesanan)->first();
                if (!$pesanan) continue;
                
                // Menghitung denda keterlambatan berdasarkan perbandingan tanggal kalender.
                // Selisih dihitung per hari (mengabaikan komponen waktu).
                $denda_keterlambatan = 0;
                if ($pesanan->tanggal_selesai) {
                    $tglSelesaiHariSaja = Carbon::parse($pesanan->tanggal_selesai)->startOfDay();
                    $sekarangHariSaja   = Carbon::now()->startOfDay();
                    if ($sekarangHariSaja->greaterThan($tglSelesaiHariSaja)) {
                        $hariTerlambat = $tglSelesaiHariSaja->diffInDays($sekarangHariSaja);
                        if ($hariTerlambat > 0) {
                            $hargaPerHari = (int)($pesanan->harga_satuan_asli ?? ($pesanan->total_harga / $pesanan->jumlah_pesan / $pesanan->lama_sewa));
                            $denda_keterlambatan = $hariTerlambat * $hargaPerHari * $pesanan->jumlah_pesan;
                        }
                    }
                }
                
                // Handle upload foto per item jika ada
                $fotoName = null;
                $fileKey = "items.{$index}.foto"; // Format jika dikirim via multipart form-data array
                if ($request->hasFile($fileKey)) {
                    $file = $request->file($fileKey);
                    $fotoName = time() . '_' . $id_pesanan . '.' . $file->getClientOriginalExtension();
                    $file->storeAs('pengembalian', $fotoName, 'public');
                }

                // Update pesanan
                $updateData = [
                    'status_pesanan' => 'selesai',
                    'denda_keterlambatan' => $denda_keterlambatan,
                    'denda_kerusakan' => $denda_kerusakan,
                    'kondisi_pengembalian' => $kondisi,
                    'keterangan_kondisi' => $keterangan,
                ];
                if ($fotoName) {
                    $updateData['foto_pengembalian'] = $fotoName;
                }

                DB::table('pesanan')
                    ->where('id_pesanan', $id_pesanan)
                    ->update($updateData);
            }

            // Update status transaksi global
            DB::table('transaksi')
                ->where('id_transaksi', $id_transaksi)
                ->update([
                    'status_transaksi' => 'selesai',
                    'tanggal_dikembalikan' => $now->toDateTimeString()
                ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Pengembalian barang berhasil dikonfirmasi'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengkonfirmasi pengembalian: ' . $e->getMessage()
            ], 500);
        }
    }

    // --- SIMULASI BAYAR (DEV ONLY) ---
    public function simulasiBayar($id_transaksi)
    {
        try {
            DB::beginTransaction();

            // Update status transaksi
            DB::table('transaksi')
                ->where('id_transaksi', $id_transaksi)
                ->update([
                    'status_transaksi' => 'menunggu_konfirmasi'
                ]);

            // Update status semua pesanan terkait
            DB::table('pesanan')
                ->where('id_transaksi', $id_transaksi)
                ->update([
                    'status_pesanan' => 'menunggu_konfirmasi'
                ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Simulasi pembayaran berhasil'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal simulasi: ' . $e->getMessage()
            ], 500);
        }
    }
}
