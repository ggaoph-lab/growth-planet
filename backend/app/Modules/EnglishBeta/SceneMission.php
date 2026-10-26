<?php

namespace App\Modules\EnglishBeta;

use Illuminate\Support\Facades\DB;

class SceneMission
{
    public function __construct(private readonly DailyMenu $menus) {}
    private const SCENES = [
        'supermarket' => [
            'title_zh' => '超市星球', 'title_en' => 'Supermarket Planet',
            'greeting' => 'Welcome to the supermarket! What would you like to buy?',
            'greeting_zh' => '欢迎来到超市！你想买什么？',
            'words' => ['apple', 'banana', 'milk', 'bread'],
            'missions' => [
                ['code' => 'find_apple', 'zh' => '听懂并找到苹果', 'en' => 'Find an apple'],
                ['code' => 'ask_for_milk', 'zh' => '用英语询问牛奶', 'en' => 'Ask for milk'],
                ['code' => 'checkout', 'zh' => '询问价格，完成结账', 'en' => 'Ask the price'],
            ],
        ],
        'restaurant' => [
            'title_zh' => '餐厅星球', 'title_en' => 'Restaurant Planet',
            'greeting' => 'Welcome to the restaurant! What would you like to order?',
            'greeting_zh' => '欢迎来到餐厅！你想点什么？',
            'words' => ['water', 'bread', 'milk', 'apple'],
            'missions' => [
                ['code' => 'find_water', 'zh' => '听懂并找到水', 'en' => 'Find water'],
                ['code' => 'order_food', 'zh' => '用英语点餐', 'en' => 'Order food'],
                ['code' => 'ask_bill', 'zh' => '询问账单', 'en' => 'Ask for the bill'],
            ],
        ],
        'bakery' => [
            'title_zh' => '面包店星球', 'title_en' => 'Bakery Planet',
            'greeting' => 'Welcome to the bakery! What would you like to try?',
            'greeting_zh' => '欢迎来到面包店！你想尝尝什么？',
            'words' => ['bread', 'cake', 'cookie', 'muffin'],
            'missions' => [
                ['code' => 'find_item', 'zh' => '听懂并找到点心', 'en' => 'Find a treat'],
                ['code' => 'ask_for_item', 'zh' => '用英语挑选点心', 'en' => 'Choose a treat'],
                ['code' => 'checkout', 'zh' => '询问价格', 'en' => 'Ask the price'],
            ],
        ],
        'toy_store' => [
            'title_zh' => '玩具店星球', 'title_en' => 'Toy Store Planet',
            'greeting' => 'Welcome to the toy store! What would you like to see?',
            'greeting_zh' => '欢迎来到玩具店！你想看看什么？',
            'words' => ['ball', 'puzzle', 'robot', 'kite'],
            'missions' => [
                ['code' => 'find_item', 'zh' => '听懂并找到玩具', 'en' => 'Find a toy'],
                ['code' => 'ask_for_item', 'zh' => '用英语挑选玩具', 'en' => 'Choose a toy'],
                ['code' => 'checkout', 'zh' => '询问价格', 'en' => 'Ask the price'],
            ],
        ],
    ];

    public function scenes(?string $menuCode = null, ?string $restaurantCode = null): array
    {
        return array_map(fn ($code) => $this->scene($code, $code === 'restaurant' ? $restaurantCode : ($code === 'supermarket' ? $menuCode : null)), array_keys(self::SCENES));
    }

    public function has(string $scene): bool
    {
        return isset(self::SCENES[$scene]);
    }

    public function scene(string $scene, ?string $menuCode = null): array
    {
        $data = ['code' => $scene] + self::SCENES[$scene];
        $menu = $scene === 'supermarket' ? $this->menus->find($menuCode)
            : ($scene === 'restaurant' ? $this->menus->findRestaurant($menuCode) : null);
        if ($menu) {
            $data['menu'] = $menu;
            $data['words'] = $menu['words'];
            if ($scene === 'restaurant') {
                $data['greeting'] = 'Today we have '.$menu['title_en'].'! What would you like to order?';
                $data['greeting_zh'] = '今天有'.$menu['title_zh'].'！你想点什么？';
                $data['missions'] = [
                    ['code' => 'choose_dish', 'zh' => '听懂今天的菜单', 'en' => 'Explore today’s menu'],
                    ['code' => 'order_food', 'zh' => '用英语点餐', 'en' => 'Order food'],
                    ['code' => 'ask_bill', 'zh' => '询问账单', 'en' => 'Ask for the bill'],
                ];
                return $data;
            }
            $data['greeting'] = 'Today we are making '.$menu['title_en'].'! What ingredients do we need?';
            $data['greeting_zh'] = '今天我们要做'.$menu['title_zh'].'！需要哪些食材？';
            $data['missions'] = [
                ['code' => 'collect_ingredients', 'zh' => '找齐今天的食材', 'en' => 'Collect the ingredients'],
                ['code' => 'ask_for_ingredient', 'zh' => '用英语询问一种食材', 'en' => 'Ask for an ingredient'],
                ['code' => 'checkout', 'zh' => '询问价格，完成结账', 'en' => 'Ask the price'],
            ];
        }

        return $data;
    }

    public function words(string $scene, ?string $menuCode = null): array
    {
        return $this->scene($scene, $menuCode)['words'];
    }

    public function firstTask(string $scene, ?string $menuCode = null): string
    {
        return $this->scene($scene, $menuCode)['missions'][0]['code'];
    }

    public function listeningTarget(int $childId, string $scene, int $sessionId, ?string $menuCode = null): ?string
    {
        $rows = DB::table('english_beta_vocabulary')->where('child_id', $childId)->get()->keyBy('word');
        $answered = DB::table('english_beta_word_evidence')
            ->where('session_id', $sessionId)->where('dimension', 'understood')->where('source', 'listen')
            ->pluck('word')->all();
        $remaining = array_values(array_diff($this->words($scene, $menuCode), $answered));
        usort($remaining, fn ($a, $b) => (int) ($rows[$a]->understood ?? false) <=> (int) ($rows[$b]->understood ?? false));

        return $remaining[0] ?? null;
    }

    public function listeningProgress(int $sessionId, string $scene, ?string $menuCode = null): array
    {
        $completed = DB::table('english_beta_word_evidence')
            ->where('session_id', $sessionId)->where('dimension', 'understood')->where('source', 'listen')
            ->whereIn('word', $this->words($scene, $menuCode))->distinct()->count('word');

        return ['completed' => $completed, 'total' => count($this->words($scene, $menuCode))];
    }

    public function practiceWord(int $childId, int $sessionId, string $scene, ?string $menuCode = null): string
    {
        $completed = $this->completed($sessionId);
        // Keep the on-screen tip aligned with the next speaking mission when one is open.
        if ($scene === 'supermarket' && $menuCode && $this->menus->find($menuCode)
            && ! in_array('ask_for_ingredient', $completed, true)) return $this->words($scene, $menuCode)[0];
        if ($scene === 'supermarket' && ! $menuCode && ! in_array('ask_for_milk', $completed, true)) return 'milk';
        if ($scene === 'restaurant' && $menuCode && ! in_array('order_food', $completed, true)) return $this->words($scene, $menuCode)[0];
        if ($scene === 'restaurant' && ! in_array('order_food', $completed, true)) {
            $rows = DB::table('english_beta_vocabulary')->where('child_id', $childId)->get()->keyBy('word');
            foreach ($this->words($scene, $menuCode) as $word) {
                $row = $rows[$word] ?? null;
                $score = (int) ($row->understood ?? false) + (int) ($row->spoken ?? false) + (int) ($row->used_in_sentence ?? false);
                if ($score === 0) return $word;
            }
        }
        $rows = DB::table('english_beta_vocabulary')->where('child_id', $childId)->get()->keyBy('word');
        $ranked = [];
        $words = $this->words($scene, $menuCode);
        foreach ($words as $index => $word) {
            $row = $rows[$word] ?? null;
            $score = (int) ($row->understood ?? false) + (int) ($row->spoken ?? false) + (int) ($row->used_in_sentence ?? false);
            $ranked[] = ['word' => $word, 'score' => $score, 'order' => ($index + $sessionId) % count($words)];
        }
        usort($ranked, fn ($a, $b) => [$a['score'], $a['order']] <=> [$b['score'], $b['order']]);

        return $ranked[0]['word'];
    }

    public function taskForUtterance(string $scene, string $text, ?string $menuCode = null): ?string
    {
        if ($scene === 'restaurant') {
            if (preg_match('/\b(?:bill|check please|how much)\b/i', $text)) return 'ask_bill';
            foreach ($this->words($scene, $menuCode) as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $text)
                    && preg_match('/\b(?:i|want|like|may|can|please)\b/i', $text)) return 'order_food';
            }
            return null;
        }
        if (in_array($scene, ['bakery', 'toy_store'], true)) {
            if (preg_match('/\b(?:how much|price|pay|checkout)\b/i', $text)) return 'checkout';
            foreach ($this->words($scene) as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $text)
                    && preg_match('/\b(?:i|want|like|may|can|please)\b/i', $text)) return 'ask_for_item';
            }
            return null;
        }
        if (preg_match('/\b(?:how much|price|pay|checkout)\b/i', $text)) return 'checkout';
        if ($menuCode && $this->menus->find($menuCode)) {
            foreach ($this->words($scene, $menuCode) as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $text)
                    && preg_match('/\b(?:i|want|like|may|can|please)\b/i', $text)) return 'ask_for_ingredient';
            }
            return null;
        }
        if (preg_match('/\bmilk\b/i', $text)
            && preg_match('/\b(?:i|want|like|may|can|please)\b/i', $text)) return 'ask_for_milk';
        return null;
    }

    public function complete(int $sessionId, string $code): void
    {
        DB::table('english_beta_tasks')->insertOrIgnore([
            'session_id' => $sessionId, 'code' => $code, 'completed' => true,
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function completed(int $sessionId): array
    {
        return DB::table('english_beta_tasks')->where('session_id', $sessionId)
            ->where('completed', true)->pluck('code')->all();
    }

    public function evidence(int $familyId, int $childId, int $sessionId, string $scene, string $word, string $dimension, string $source): void
    {
        DB::table('english_beta_word_evidence')->insert([
            'family_id' => $familyId, 'child_id' => $childId, 'session_id' => $sessionId,
            'scene' => $scene, 'word' => $word, 'dimension' => $dimension,
            'source' => $source, 'created_at' => now(),
        ]);
    }
}
