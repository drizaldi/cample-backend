<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    // --- 1. MENGAMBIL DAFTAR INBOX UNTUK ADMIN ---
    public function getRooms()
    {
        try {
            $userIds = DB::table('chat_messages')
                        ->select('id_user')
                        ->whereNotNull('id_user') 
                        ->distinct()
                        ->pluck('id_user');

            $rooms = [];

            foreach ($userIds as $id_user) {
                $lastMessage = DB::table('chat_messages')
                                ->where('id_user', $id_user)
                                ->orderBy('tanggal', 'desc') 
                                ->first();

                // Disesuaikan persis dengan gambar tabel `pengguna` Anda
                $user = DB::table('pengguna')->where('id', $id_user)->first();

                $nama = $user ? $user->nama : 'Pelanggan ' . $id_user;
                $foto = ($user && $user->foto_profil) ? url('storage/profil/' . basename($user->foto_profil)) : '';

                $rooms[] = [
                    'id_user' => $id_user,
                    'nama_user' => $nama,
                    'foto_profil' => $foto,
                    'pesan_terakhir' => $lastMessage ? $lastMessage->pesan : '...',
                    'waktu_terakhir' => $lastMessage ? $lastMessage->tanggal : null 
                ];
            }

            usort($rooms, function($a, $b) {
                $timeA = strtotime($a['waktu_terakhir'] ?? '1970-01-01');
                $timeB = strtotime($b['waktu_terakhir'] ?? '1970-01-01');
                return $timeB <=> $timeA;
            });

            return response()->json($rooms);

        } catch (\Exception $e) {
            // Ini akan mengirimkan baris dan teks error aslinya ke Flutter
            return response()->json(['status' => 'error', 'pesan' => 'Baris '.$e->getLine().': '.$e->getMessage()], 500);
        }
    }

    // --- 2. MENGAMBIL RIWAYAT OBROLAN ---
    public function getPesan($id_user)
    {
        try {
            $pesan = DB::table('chat_messages')
                        ->where('id_user', $id_user)
                        ->orderBy('tanggal', 'asc') 
                        ->get();

            return response()->json($pesan);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'pesan' => $e->getMessage()], 500);
        }
    }

    // --- 3. MENGIRIM PESAN BARU ---
    public function kirimPesan(Request $request)
    {
        try {
            DB::table('chat_messages')->insert([
                'id_user'    => $request->id_user,
                'pengirim'   => $request->pengirim,
                'pesan'      => $request->pesan,
                'tipe_pesan' => 'teks',
            ]);

            return response()->json(['status' => 'sukses']);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'pesan' => $e->getMessage()], 500);
        }
    }
    // --- 4. SISTEM CHATBOT: OTOMATIS KIRIM NOTIFIKASI SEWA KE USER ---
    public static function kirimPesanBot($id_user, $pesanTeks)
    {
        try {
            DB::table('chat_messages')->insert([
                'id_user'    => $id_user,
                'pengirim'   => 'admin', // Dikirim atas nama admin agar muncul di chat user
                'pesan'      => $pesanTeks,
                'tipe_pesan' => 'teks',
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error("Gagal mengirim pesan chatbot: " . $e->getMessage());
            return false;
        }
    }
}
