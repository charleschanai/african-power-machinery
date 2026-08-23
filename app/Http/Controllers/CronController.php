<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class CronController extends Controller
{
    public function tick(Request $request): JsonResponse
    {
        $expected = (string) config('services.cron.secret');
        $given = (string) $request->query('key', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            abort(403);
        }

        $results = ['process_scheduled' => trim(Artisan::output('sms:process-scheduled'))];

        $now = Carbon::now();

        if ($now->isSunday() && (int) $now->format('G') === 8) {
            $stamp = 'cron:weekly:'.$now->format('Ymd');

            if (! Cache::has($stamp)) {
                Cache::put($stamp, true, now()->addHours(6));

                $results['weekly_reminder'] = trim(Artisan::output('sms:weekly-reminder'));
            }
        }

        return response()->json(['ok' => true, 'at' => $now->toIso8601String()] + $results);
    }
}
