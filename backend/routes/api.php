<?php

use App\Modules\Core\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('health', function (Clock $clock) {
    try {
        DB::select('SELECT 1');
        $database = 'ok';
    } catch (Throwable) {
        $database = 'unavailable';
    }

    return response()->json([
        'status' => $database === 'ok' ? 'ok' : 'degraded',
        'database' => $database,
        'app_timezone' => config('app.timezone'),
        'time_utc' => $clock->now()->toIso8601String(),
    ], $database === 'ok' ? 200 : 503);
});

require base_path('app/Modules/Identity/routes.php');
require base_path('app/Modules/Ledger/routes.php');
require base_path('app/Modules/Approval/routes.php');
require base_path('app/Modules/Task/routes.php');
require base_path('app/Modules/Habit/routes.php');
require base_path('app/Modules/Shop/routes.php');
require base_path('app/Modules/Item/routes.php');
require base_path('app/Modules/Schedule/routes.php');
require base_path('app/Modules/Exploration/routes.php');
require base_path('app/Modules/Food/routes.php');
require base_path('app/Modules/Movie/routes.php');
require base_path('app/Modules/English/routes.php');
require base_path('app/Modules/Report/routes.php');
