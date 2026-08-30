<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::capture());

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$users = DB::table('pengguna')->get();
foreach ($users as $u) {
    if (!password_get_info($u->password)['algoName'] || password_get_info($u->password)['algoName'] === 'unknown') {
        DB::table('pengguna')->where('id', $u->id)->update([
            'password' => Hash::make($u->password)
        ]);
        echo "Hashed password for user: {$u->email}\n";
    }
}
echo "Done.\n";
