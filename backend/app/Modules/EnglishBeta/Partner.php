<?php

namespace App\Modules\EnglishBeta;

use Illuminate\Support\Facades\Http;

class Partner
{
    public function suggestReply(
        string $scene,
        array $history,
        array $completed,
        string $practiceWord,
        string $provider,
        string $key,
        string $chatModel,
        ?array $menu = null,
    ): array {
        if ($menu) {
            if ($scene === 'restaurant') {
                if (! in_array('order_food', $completed, true)) return $this->line('I would like '.$this->phrase($practiceWord).', please.', '我想点'.$practiceWord.'，谢谢。') + ['provider' => 'mock'];
                return $this->line('How much is the bill?', '账单多少钱？') + ['provider' => 'mock'];
            }
            if (! in_array('ask_for_ingredient', $completed, true)) {
                return $this->line('I would like '.$this->phrase($practiceWord).', please.', '我想要'.$practiceWord.'，谢谢。') + ['provider' => 'mock'];
            }
            if (! in_array('checkout', $completed, true)) {
                return $this->line('How much is it?', '这个多少钱？') + ['provider' => 'mock'];
            }
            return $this->line('I would like something new tomorrow.', '明天我想吃点新东西。') + ['provider' => 'mock'];
        }
        if (in_array($scene, ['bakery', 'toy_store'], true)) {
            if (! in_array('ask_for_item', $completed, true)) return $this->line('I would like '.$this->phrase($practiceWord).', please.', '我想要'.$practiceWord.'，谢谢。') + ['provider' => 'mock'];
            return $this->line('How much is it?', '多少钱？') + ['provider' => 'mock'];
        }
        if ($provider === 'qwen' && $key !== '') {
            try {
                $messages = [[
                    'role' => 'system',
                    'content' => 'Help a child answer the English partner in a '.$scene.'. Suggest exactly one short sentence that the child can say next. Answer the latest partner question naturally; if useful, guide this goal: '.$this->goalHint($scene, $completed, null, $practiceWord).' Reply JSON only: {"en":"child reply","zh":"中文意思"}. Never ask for personal details, contact, location, or links. No unsafe topics.',
                ]];
                if ($history === []) {
                    $messages[] = ['role' => 'assistant', 'content' => $scene === 'restaurant'
                        ? 'Welcome to the restaurant! What would you like to order?'
                        : 'Welcome to the supermarket! What would you like to buy?'];
                }
                foreach (array_slice($history, -6) as $turn) {
                    $messages[] = ['role' => $turn->speaker === 'child' ? 'user' : 'assistant', 'content' => $turn->text];
                }
                $messages[] = ['role' => 'user', 'content' => 'What can I say next?'];
                $response = Http::withToken($key)->timeout(8)->post('https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', [
                    'model' => $this->safeModel($chatModel, 'qwen-flash'),
                    'messages' => $messages, 'max_tokens' => 80,
                ]);
                if ($response->successful()) {
                    $parsed = $this->parseBilingual(trim((string) $response->json('choices.0.message.content')));
                    if ($parsed !== null && mb_strlen($parsed['text']) <= 120
                        && ! preg_match('/\\d{7,}/', $parsed['text'].' '.$parsed['text_zh'])) {
                        return $parsed + ['provider' => 'qwen'];
                    }
                }
            } catch (\Throwable) {
                // Explicit help remains available when the model is unavailable.
            }
        }

        $lastPartner = '';
        foreach (array_reverse($history) as $turn) {
            if ($turn->speaker === 'partner') {
                $lastPartner = strtolower($turn->text);
                break;
            }
        }
        if (str_contains($lastPartner, 'anything else')) {
            return $this->line('No, thank you.', '不用了，谢谢。') + ['provider' => 'mock'];
        }
        if ($scene === 'restaurant') {
            if (! in_array('order_food', $completed, true)) {
                return $this->line('I would like '.$this->phrase($practiceWord).', please.', '我想点'.$practiceWord.'，谢谢。') + ['provider' => 'mock'];
            }
            if (! in_array('ask_bill', $completed, true)) {
                return $this->line('How much is the bill?', '账单多少钱？') + ['provider' => 'mock'];
            }
        } else {
            if (! in_array('ask_for_milk', $completed, true)) {
                return $this->line('I would like milk, please.', '我想要牛奶，谢谢。') + ['provider' => 'mock'];
            }
            if (! in_array('checkout', $completed, true)) {
                return $this->line('How much is it?', '这个多少钱？') + ['provider' => 'mock'];
            }
        }

        return $this->line('Thank you!', '谢谢！') + ['provider' => 'mock'];
    }

    public function reply(
        string $scene,
        string $provider,
        string $input,
        array $history,
        string $key,
        array $guide = [],
        string $chatModel = 'qwen-flash',
        string $tokenMode = 'frugal',
    ): array {
        $practiceWord = (string) ($guide['practice_word'] ?? ($scene === 'restaurant' ? 'water' : 'apple'));
        $completed = array_values(array_filter((array) ($guide['completed_tasks'] ?? [])));
        $pendingTask = $guide['pending_task'] ?? null;
        $pendingTask = is_string($pendingTask) ? $pendingTask : null;
        $menu = is_array($guide['menu'] ?? null) ? $guide['menu'] : null;
        $openingLine = is_string($guide['opening_line'] ?? null) ? $guide['opening_line'] : '';
        $tomorrowChoices = (array) ($guide['tomorrow_choices'] ?? []);
        $tomorrowChoice = is_array($guide['tomorrow_choice'] ?? null) ? $guide['tomorrow_choice'] : null;
        $selectedTomorrow = is_array($guide['selected_tomorrow'] ?? null) ? $guide['selected_tomorrow'] : $tomorrowChoice;
        $model = $this->safeModel($chatModel, 'qwen-flash');
        $frugal = $tokenMode !== 'full';

        // Scripted mission turns never need a chat model — this is the main token saver.
        if (! $menu && $frugal && $this->canScriptReply($scene, $input, $history, $practiceWord, $completed, $pendingTask)) {
            return $this->mockReply($scene, $input, $history, $practiceWord, $completed, $pendingTask, $menu, $tomorrowChoices, $tomorrowChoice) + ['provider' => 'mock'];
        }
        if ($tomorrowChoice) {
            return $this->mockReply($scene, $input, $history, $practiceWord, $completed, $pendingTask, $menu, $tomorrowChoices, $tomorrowChoice) + ['provider' => 'mock'];
        }

        if ($provider === 'qwen' && $key !== '') {
            try {
                $place = match ($scene) { 'restaurant' => 'restaurant', 'bakery' => 'bakery', 'toy_store' => 'toy store', default => 'supermarket' };
                $goal = $menu
                    ? ($scene === 'restaurant'
                        ? 'Today’s restaurant theme is '.$menu['title_en'].'; the menu offers '.implode(', ', $menu['words']).'. The child explored the menu. Respond to their order, then invite them to ask for the bill. Never claim a dish they did not order.'
                        : 'Your opening line was: '.$openingLine.' Today the child is shopping for '.$menu['title_en'].' with these ingredients: '.implode(', ', $menu['words']).'. The child already collected all ingredients. '.
                        ($selectedTomorrow ? 'The child already chose '.$selectedTomorrow['title_en'].' for tomorrow. Do not ask them to choose again. Answer the latest question, including the price if asked, then continue today’s shopping.'
                            : (in_array('checkout', $completed, true) || $pendingTask === 'checkout'
                                ? 'Answer the price question if just asked, then ask what the child would like to eat tomorrow. Offer only '.implode(', ', array_column($tomorrowChoices, 'title_en')).'. Use "Tomorrow we will...".'
                                : ($pendingTask === 'ask_for_ingredient' || in_array('ask_for_ingredient', $completed, true)
                                    ? 'Respond to the ingredient request, then naturally ask about the price.'
                                    : 'Respond naturally to the child and invite a request for '.$practiceWord.'. Refer to yesterday with a simple past-tense sentence.'))))
                    : $this->goalHint($scene, $completed, $pendingTask, $practiceWord);
                $system = $menu
                    ? 'You are a warm, natural American English speaking '.$place.' partner for a child. Use idiomatic US English, simple short sentences, and react to what the child actually said. Continue the conversation; never restart it. Use past tense when discussing yesterday and future tense when discussing tomorrow. '.$goal.' Reply JSON only: {"en":"one or two short English sentences","zh":"faithful 简短中文翻译"}. Do not ask for personal information, contact, location or links; no unsafe topics.'
                    : ($frugal
                    ? 'Child English partner in a '.$place.'. Answer the latest question first; then guide the next goal. Do not repeat a welcome or answered question. Reply JSON only: {"en":"one short English sentence","zh":"简短中文翻译"}. '.$goal.' No personal data, links, or unsafe topics.'
                    : 'You are a kind English practice partner for a child in a '.$place.'. Answer the latest question first and continue from recent turns. Reply JSON only: {"en":"one short age-appropriate English sentence","zh":"简短中文翻译"}. Stay in the '.$place.' scenario. '.$goal.' Never repeat the welcome or ask the child to repeat a sentence they already said correctly. Never ask for personal details, contact, location, or links. Do not discuss unsafe topics.');
                $messages = [['role' => 'system', 'content' => $system]];
                $window = $menu ? 6 : ($frugal ? 2 : 6);
                foreach (array_slice($history, -$window) as $turn) {
                    $messages[] = ['role' => $turn->speaker === 'child' ? 'user' : 'assistant', 'content' => $turn->text];
                }
                $messages[] = ['role' => 'user', 'content' => $input];
                $response = Http::withToken($key)->timeout(8)->post('https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $frugal ? 80 : 120,
                ]);
                if ($response->successful()) {
                    $parsed = $this->parseBilingual(trim((string) $response->json('choices.0.message.content')));
                    if ($parsed !== null) {
                        return $parsed + ['provider' => 'qwen'];
                    }
                }
            } catch (\Throwable) {
                // Network and upstream failures fall back to a local safe prompt.
            }
        }
        // Doubao is an explicit extension point; until configured it uses the same local fallback.
        return $this->mockReply($scene, $input, $history, $practiceWord, $completed, $pendingTask, $menu, $tomorrowChoices, $tomorrowChoice) + ['provider' => 'mock'];
    }

    private function canScriptReply(string $scene, string $input, array $history, string $practiceWord, array $completed, ?string $pendingTask): bool
    {
        if ($pendingTask !== null) {
            return true;
        }
        if (preg_match('/\b(apple|banana|milk|bread|water|how much|price|pay|checkout|bill|check please)\b/i', $input)) {
            return true;
        }
        $after = $completed;
        if ($this->nextMissionCoach($scene, $after, $practiceWord) !== null) {
            return true;
        }

        return $history === [];
    }

    private function goalHint(string $scene, array $completed, ?string $pendingTask, string $practiceWord): string
    {
        $after = $pendingTask ? array_values(array_unique([...$completed, $pendingTask])) : $completed;
        if ($prompt = $this->nextMissionCoach($scene, $after, $practiceWord)) {
            return 'Guide the child toward this next practice goal: '.$prompt['en'];
        }

        return 'Invite the child to use the word "'.$practiceWord.'" in a short shopping sentence.';
    }

    /**
     * @return array{text: string, text_zh: string}
     */
    private function mockReply(string $scene, string $input, array $history, string $practiceWord, array $completed, ?string $pendingTask, ?array $menu = null, array $tomorrowChoices = [], ?array $tomorrowChoice = null): array
    {
        $after = $pendingTask ? array_values(array_unique([...$completed, $pendingTask])) : $completed;

        if ($menu) {
            if ($scene === 'restaurant') {
                if ($pendingTask === 'ask_bill' || in_array('ask_bill', $after, true)) return $this->line('The bill is five dollars. Thanks for dining with us!', '账单是五美元。谢谢你来吃饭！');
                if ($pendingTask === 'order_food' || in_array('order_food', $after, true)) return $this->line('Great choice! Would you like anything else, or may I bring the bill?', '选得好！还想点别的吗，还是要账单？');
                return $this->line('Today we have '.$menu['title_en'].'. Would you like '.$this->phrase($practiceWord).'?', '今天有'.$menu['title_zh'].'。你想点'.$practiceWord.'吗？');
            }
            if ($tomorrowChoice) {
                return $this->line('Great! Tomorrow we will make '.$tomorrowChoice['title_en'].'.', '好呀！明天我们做'.$tomorrowChoice['title_zh'].'。');
            }
            if ($pendingTask === 'checkout') {
                return $this->line('It is two dollars. We have everything for '.$menu['title_en'].'! What would you like to eat tomorrow?', '两美元。今天的'.$menu['title_zh'].'食材买齐啦！明天你想吃什么？');
            }
            if (in_array('checkout', $after, true)) {
                $names = implode(' or ', array_map(fn ($choice) => $choice['title_en'], $tomorrowChoices));
                return $this->line('We bought everything for '.$menu['title_en'].'! What would you like to eat tomorrow: '.$names.'?', '今天的'.$menu['title_zh'].'食材买齐啦！明天你想吃什么？请选择下面的一种。');
            }
            if ($pendingTask === 'ask_for_ingredient') {
                return $this->line('Nice! Yesterday we tried something different. Now ask: How much is it?', '说得好！昨天我们尝了别的食物。现在问：How much is it?');
            }
            if (preg_match('/\b(?:yesterday|ate|bought|was|were)\b/i', $input)) {
                return $this->line('That sounds good! Today we will make '.$menu['title_en'].'. Can you ask for '.$this->phrase($practiceWord).'?', '听起来不错！今天我们要做'.$menu['title_zh'].'。你能用英语要'.$practiceWord.'吗？');
            }
            return $this->line('Good! Can you say: I would like '.$this->phrase($practiceWord).', please?', '很好！你能说：I would like '.$this->phrase($practiceWord).', please?');
        }

        if (preg_match('/\b(?:how much|price|cost|bill|check please)\b/i', $input)) {
            if ($scene === 'restaurant') {
                return $this->line('The bill is five dollars. Great job!', '账单是五美元。做得很好！');
            }
            $item = $this->lastItem($history) ?? 'item';
            return $this->line('The '.$item.' is two dollars. Great job!', '这件商品是两美元。做得很好！');
        }
        if ($pendingTask === 'ask_for_item') return $this->line('Great choice! Now ask: How much is it?', '选得好！现在问：How much is it?');
        if (in_array($pendingTask, ['ask_for_milk', 'order_food'], true)) {
            return $scene === 'restaurant'
                ? $this->line('Nice order! Now ask: How much is the bill?', '点餐很棒！接下来问：How much is the bill?')
                : $this->line('Nice! Now ask: How much is it?', '很好！接下来问：How much is it?');
        }
        if (in_array($pendingTask, ['checkout', 'ask_bill'], true)) {
            return $this->line('Great job! You finished this scene.', '太棒了！你完成了这个场景。');
        }

        $hasSentence = (bool) preg_match('/\b(?:i|want|like|buy|have|may|can|please|order)\b/i', $input);
        if (preg_match('/\b(apple|banana|milk|bread|water)\b/i', $input, $match)) {
            $word = strtolower($match[1]);
            if ($hasSentence) {
                if ($coach = $this->nextMissionCoach($scene, $after, $practiceWord)) {
                    return $this->line('Great! '.$coach['en'], '太棒了！'.$coach['zh']);
                }

                return $this->line(
                    'Great speaking! Try another sentence with '.$this->phrase($practiceWord).'.',
                    '说得很好！再用 '.$this->phrase($practiceWord).' 说一句试试。',
                );
            }

            return $this->line(
                'Good try! Can you say: I would like '.$this->phrase($word).', please?',
                '不错！你能说：I would like '.$this->phrase($word).', please?',
            );
        }

        if ($coach = $this->nextMissionCoach($scene, $after, $practiceWord)) {
            return $this->line($coach['en'], $coach['zh']);
        }

        if ($history === []) {
            return $scene === 'restaurant'
                ? $this->line('Welcome to the restaurant! What would you like to order?', '欢迎来到餐厅！你想点什么？')
                : $this->line('Welcome to the supermarket! What would you like to buy?', '欢迎来到超市！你想买什么？');
        }

        if ($scene === 'restaurant' && in_array('order_food', $completed, true) && in_array('ask_bill', $completed, true)
            || in_array($scene, ['bakery', 'toy_store'], true) && in_array('ask_for_item', $completed, true) && in_array('checkout', $completed, true)
            || $scene !== 'restaurant' && in_array('ask_for_milk', $completed, true) && in_array('checkout', $completed, true)) {
            return $this->line('Great job! You can finish this visit or try another item.', '太棒了！可以结束本次探索，或再试一件商品。');
        }

        return $this->line(
            'Can you say: I would like '.$this->phrase($practiceWord).', please?',
            '你能说：I would like '.$this->phrase($practiceWord).', please?',
        );
    }

    private function lastItem(array $history): ?string
    {
        foreach (array_reverse($history) as $turn) {
            if ($turn->speaker === 'child' && preg_match('/\b(apple|banana|milk|bread|water)\b/i', $turn->text, $match)) {
                return strtolower($match[1]);
            }
        }

        return null;
    }

    /**
     * @return array{en: string, zh: string}|null
     */
    private function nextMissionCoach(string $scene, array $completed, string $practiceWord): ?array
    {
        if ($scene === 'restaurant') {
            if (! in_array('order_food', $completed, true)) {
                return [
                    'en' => 'What would you like to order? Try: I would like '.$this->phrase($practiceWord).', please.',
                    'zh' => '你想点什么？试试说：I would like '.$this->phrase($practiceWord).', please.',
                ];
            }
            if (! in_array('ask_bill', $completed, true)) {
                return [
                    'en' => 'Can you ask for the bill? Say: How much is the bill?',
                    'zh' => '你能问一下账单吗？说：How much is the bill?',
                ];
            }

            return null;
        }
        if (in_array($scene, ['bakery', 'toy_store'], true)) {
            if (! in_array('ask_for_item', $completed, true)) return [
                'en' => 'What would you like? Try: I would like '.$this->phrase($practiceWord).', please.',
                'zh' => '你想要什么？试试说：I would like '.$this->phrase($practiceWord).', please.',
            ];
            if (! in_array('checkout', $completed, true)) return [
                'en' => 'Can you ask the price? Say: How much is it?', 'zh' => '你能问价格吗？说：How much is it?',
            ];
            return null;
        }
        if (! in_array('ask_for_milk', $completed, true)) {
            return [
                'en' => 'Can you ask for milk? Say: I would like milk, please.',
                'zh' => '你能要牛奶吗？说：I would like milk, please.',
            ];
        }
        if (! in_array('checkout', $completed, true)) {
            return [
                'en' => 'Can you ask the price? Say: How much is it?',
                'zh' => '你能问一下价格吗？说：How much is it?',
            ];
        }

        return null;
    }

    /**
     * @return array{text: string, text_zh: string}
     */
    private function line(string $en, string $zh): array
    {
        return ['text' => $en, 'text_zh' => $zh];
    }

    /**
     * @return array{text: string, text_zh: string}|null
     */
    private function parseBilingual(string $raw): ?array
    {
        if ($raw === '' || mb_strlen($raw) > 480) {
            return null;
        }
        if (preg_match('~https?://|@|(?:phone|address|email|secret|password)~i', $raw)) {
            return null;
        }
        if (preg_match('/\{.*\}/s', $raw, $match)) {
            $json = json_decode($match[0], true);
            if (is_array($json)) {
                $en = trim((string) ($json['en'] ?? $json['text'] ?? ''));
                $zh = trim((string) ($json['zh'] ?? $json['text_zh'] ?? ''));
                if ($en !== '' && mb_strlen($en) <= 240) {
                    return [
                        'text' => $en,
                        'text_zh' => $zh !== '' && mb_strlen($zh) <= 240 ? $zh : $en,
                    ];
                }
            }
        }
        // Plain English fallback (older prompts / truncated JSON).
        if (mb_strlen($raw) <= 240 && ! str_contains($raw, '{')) {
            return ['text' => $raw, 'text_zh' => $raw];
        }

        return null;
    }

    private function phrase(string $word): string
    {
        return match (strtolower($word)) {
            'apple', 'orange', 'egg', 'onion', 'avocado' => 'an '.strtolower($word),
            'banana', 'tomato', 'carrot', 'potato', 'mushroom', 'cucumber', 'tortilla', 'pepper', 'strawberry',
            'cake', 'cookie', 'muffin', 'ball', 'puzzle', 'robot', 'kite', 'burger', 'sandwich', 'taco', 'pizza' => 'a '.strtolower($word),
            'grapes', 'crackers' => 'some '.strtolower($word),
            'milk', 'bread', 'water', 'rice', 'cheese', 'lettuce', 'corn', 'pasta', 'chicken', 'broccoli',
            'yogurt', 'dough', 'flour', 'honey', 'tofu', 'butter', 'soup', 'juice', 'lemonade', 'tea', 'fruit', 'toast', 'ice cream' => 'some '.strtolower($word),
            default => strtolower($word),
        };
    }

    private function safeModel(string $model, string $fallback): string
    {
        $model = trim($model);

        return $model !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $model) ? $model : $fallback;
    }
}
