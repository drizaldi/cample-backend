<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    /**
     * Setiap kali user/admin yang sudah login melakukan request ke API,
     * middleware ini otomatis memperbarui kolom `last_seen` mereka.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            DB::table('pengguna')
                ->where('id', $request->user()->id)
                ->update(['last_seen' => now()]);
        }

        return $next($request);
    }
}
