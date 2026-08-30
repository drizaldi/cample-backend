<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MidtransController extends Controller
{
    /**
     * Menangani notifikasi Webhook dari server Midtrans.
     * Endpoint: POST /api/midtrans/callback
     * 
     * Keamanan:
     * - Verifikasi signature_key sebelum memproses apapun.
     * - Endpoint ini tidak memerlukan auth:sanctum karena dipanggil oleh server Midtrans.
     */
    public function handleCallback(Request $request)
    {
        try {
            // 1. Ambil payload dari Midtrans
            $payload = $request->all();

            // Wajib ada order_id dan transaction_status
            if (empty($payload['order_id']) || empty($payload['transaction_status'])) {
                Log::warning('[Midtrans Callback] Payload tidak lengkap', $payload);
                return response()->json(['message' => 'Invalid payload'], 400);
            }

            $orderId           = $payload['order_id'];          // = id_transaksi kita
            $transactionStatus = $payload['transaction_status'];
            $fraudStatus       = $payload['fraud_status'] ?? 'accept';
            $grossAmount       = $payload['gross_amount']       ?? 0;
            $signatureKey      = $payload['signature_key']      ?? '';
            $statusCode        = $payload['status_code']        ?? '200';

            // 2. Verifikasi signature_key untuk keamanan
            // Formula: SHA512(order_id + status_code + gross_amount + server_key)
            $serverKey = config('services.midtrans.server_key');
            $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

            if ($signatureKey !== $expectedSignature) {
                Log::error('[Midtrans Callback] Signature tidak valid untuk order: ' . $orderId);
                return response()->json(['message' => 'Invalid signature'], 403);
            }

            // 3. Cari transaksi berdasarkan order_id
            $transaksi = DB::table('transaksi')->where('id_transaksi', $orderId)->first();

            if (!$transaksi) {
                Log::warning('[Midtrans Callback] Transaksi tidak ditemukan: ' . $orderId);
                return response()->json(['message' => 'Transaction not found'], 404);
            }

            // 4. Cegah pemrosesan berulang jika status sudah final
            $statusFinal = ['menunggu_konfirmasi', 'akan_diambil', 'disewa', 'selesai', 'dibatalkan'];
            if (in_array($transaksi->status_transaksi, $statusFinal)) {
                Log::info('[Midtrans Callback] Status sudah final, diabaikan: ' . $orderId);
                return response()->json(['message' => 'Already processed'], 200);
            }

            // 5. Proses berdasarkan transaction_status dari Midtrans
            if ($transactionStatus === 'capture' && $fraudStatus === 'accept') {
                // Kartu kredit — capture sukses
                $this->handleDpBerhasil($orderId, $transaksi);

            } elseif ($transactionStatus === 'settlement') {
                // Transfer bank, QRIS, GoPay, dll — sudah settle
                $this->handleDpBerhasil($orderId, $transaksi);

            } elseif ($transactionStatus === 'pending') {
                // Masih menunggu pembayaran — tidak perlu update
                Log::info('[Midtrans Callback] Status pending untuk: ' . $orderId);

            } elseif (in_array($transactionStatus, ['deny', 'cancel', 'expire', 'failure'])) {
                // Gagal / expire — batalkan pesanan dan kembalikan stok
                $this->handleDpGagal($orderId, $transaksi, $transactionStatus);
            }

            return response()->json(['message' => 'OK'], 200);

        } catch (\Exception $e) {
            Log::error('[Midtrans Callback] Exception: ' . $e->getMessage());
            return response()->json(['message' => 'Server error'], 500);
        }
    }

    /**
     * Menangani kondisi DP berhasil dibayar.
     * Update status transaksi dan seluruh pesanan menjadi 'dp_dibayar' (menunggu konfirmasi admin).
     */
    private function handleDpBerhasil(string $orderId, object $transaksi): void
    {
        // Setelah DP berhasil dibayar via Midtrans, langsung masuk 'menunggu_konfirmasi'
        // agar admin bisa melihat pesanan di tab "Akan Disewa" dan memutuskan terima/tolak
        DB::table('transaksi')->where('id_transaksi', $orderId)->update([
            'status_transaksi' => 'menunggu_konfirmasi',
            'updated_at'       => now(),
        ]);

        DB::table('pesanan')->where('id_transaksi', $orderId)->update([
            'status_pesanan' => 'menunggu_konfirmasi',
        ]);

        Log::info('[Midtrans Callback] DP berhasil, status -> menunggu_konfirmasi untuk: ' . $orderId);
    }

    /**
     * Menangani kondisi DP gagal/expire/dibatalkan.
     * Update status menjadi 'dibatalkan' dan kembalikan stok ke varian barang.
     */
    private function handleDpGagal(string $orderId, object $transaksi, string $alasan): void
    {
        DB::table('transaksi')->where('id_transaksi', $orderId)->update([
            'status_transaksi' => 'dibatalkan',
            'updated_at'       => now(),
        ]);

        // Ambil semua item pesanan untuk mengembalikan stok
        $pesanans = DB::table('pesanan')->where('id_transaksi', $orderId)->get();
        foreach ($pesanans as $pesanan) {
            DB::table('pesanan')->where('id_pesanan', $pesanan->id_pesanan)->update([
                'status_pesanan' => 'dibatalkan',
            ]);
            // Kembalikan stok (hanya jika stok sudah pernah dikurangi)
            // Pada flow ini, stok belum dikurangi saat menunggu DP
            // Stok baru dikurangi saat admin konfirmasi (akan_diambil)
            // Jadi tidak perlu increment stok di sini
        }

        Log::info("[Midtrans Callback] Transaksi dibatalkan ($alasan) untuk: " . $orderId);
    }
}
