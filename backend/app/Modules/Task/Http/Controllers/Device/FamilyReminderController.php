<?php

namespace App\Modules\Task\Http\Controllers\Device;

use App\Modules\Identity\Services\DeviceTokens;
use App\Modules\Task\Services\QwenTtsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FamilyReminderController
{
    public function __construct(private readonly DeviceTokens $tokens, private readonly QwenTtsService $tts) {}

    public function index(Request $request): JsonResponse
    {
        $familyId = $this->familyId($request);
        $rows = DB::table('family_reminders')->where('family_id', $familyId)->where('enabled', true)
            ->orderBy('id')->get()->map(fn ($row) => [
                'id' => (int) $row->id, 'title' => $row->title,
                'weekdays' => json_decode($row->weekdays, true), 'times' => json_decode($row->times, true),
                'updated_at' => $row->updated_at,
            ])->all();
        return response()->json(['data' => $rows]);
    }

    public function audio(Request $request, int $id): JsonResponse
    {
        $familyId = $this->familyId($request);
        $row = DB::table('family_reminders')->where('family_id', $familyId)->where('enabled', true)->where('id', $id)->first();
        abort_if($row === null, 404);
        $result = $this->tts->synthesize('提醒时间到了，'.$row->title.'。', $familyId, 'zh-CN');
        $naturalVoices = ['qwen', 'cosyvoice', 'edge_tts', 'gemini_tts', 'elevenlabs'];
        if ($result === null || ! in_array($result['provider'], $naturalVoices, true)) {
            return response()->json(['data' => ['available' => false, 'hint' => 'natural_chinese_voice_unavailable']], 503);
        }
        return response()->json(['data' => ['available' => true, 'audio_base64' => $result['audio_base64'],
            'mime' => $result['mime'], 'provider' => $result['provider']]]);
    }

    private function familyId(Request $request): int
    {
        $device = $this->tokens->fromRequest($request);
        abort_if($device === null, 401);
        return (int) $device->family_id;
    }
}
