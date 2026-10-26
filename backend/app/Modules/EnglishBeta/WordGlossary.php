<?php

namespace App\Modules\EnglishBeta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WordGlossary
{
    private const WORDS = [
        'yesterday' => ['昨天', '时间副词'], 'tomorrow' => ['明天', '时间副词'],
        'today' => ['今天', '时间副词'], 'bought' => ['买了；buy 的过去式', '动词'],
        'buy' => ['买', '动词'], 'eat' => ['吃', '动词'], 'ate' => ['吃了；eat 的过去式', '动词'],
        'ingredients' => ['食材；ingredient 的复数', '名词'], 'ingredient' => ['食材', '名词'],
        'supermarket' => ['超市', '名词'], 'restaurant' => ['餐厅', '名词'],
        'welcome' => ['欢迎', '动词'], 'please' => ['请；劳驾', '礼貌用语'],
        'would' => ['想要；用于礼貌表达', '情态动词'], 'like' => ['喜欢；想要', '动词'],
        'much' => ['多少', '副词'], 'how' => ['怎样；多少', '疑问词'],
        'what' => ['什么', '疑问词'], 'where' => ['哪里', '疑问词'],
        'make' => ['制作', '动词'], 'made' => ['制作了；make 的过去式', '动词'],
        'meal' => ['一餐', '名词'], 'food' => ['食物', '名词'],
        'price' => ['价格', '名词'], 'bill' => ['账单', '名词'],
        'anything' => ['任何东西', '代词'], 'something' => ['某样东西', '代词'],
        'else' => ['另外；其他', '副词'], 'new' => ['新的', '形容词'],
        'delicious' => ['美味的', '形容词'], 'great' => ['很棒的', '形容词'],
        'find' => ['找到', '动词'], 'found' => ['找到了；find 的过去式', '动词'],
        'ask' => ['询问', '动词'], 'ready' => ['准备好的', '形容词'],
        'muffin' => ['玛芬蛋糕', '名词'], 'ball' => ['球', '名词'],
        'puzzle' => ['拼图', '名词'], 'robot' => ['机器人', '名词'],
        'kite' => ['风筝', '名词'],
        'hello' => ['你好', '感叹词'], 'hi' => ['你好', '感叹词'],
        'nice' => ['好的；不错的', '形容词'], 'good' => ['好的', '形容词'],
        'thank' => ['感谢', '动词'], 'thanks' => ['谢谢', '感叹词'],
        'you' => ['你；你们', '代词'], 'we' => ['我们', '代词'],
        'i' => ['我', '代词'], 'it' => ['它；这件东西', '代词'],
        'the' => ['这个；特指前面提到的事物', '冠词'],
        'a' => ['一个', '冠词'], 'an' => ['一个', '冠词'],
        'can' => ['能够；可以', '情态动词'], 'may' => ['可以', '情态动词'],
        'is' => ['是', '动词'], 'are' => ['是', '动词'],
        'was' => ['是；is 的过去式', '动词'], 'were' => ['是；are 的过去式', '动词'],
        'have' => ['有；吃', '动词'], 'had' => ['有过；吃过', '动词'],
        'will' => ['将要', '情态动词'], 'do' => ['做', '动词'],
        'did' => ['做了；do 的过去式', '动词'], 'need' => ['需要', '动词'],
        'for' => ['为了；给', '介词'], 'with' => ['和；用', '介词'],
        'to' => ['到；向；用于不定式', '介词'], 'in' => ['在……里面', '介词'],
        'now' => ['现在', '副词'], 'try' => ['尝试', '动词'],
        'order' => ['点餐', '动词'], 'choice' => ['选择', '名词'],
        'dollars' => ['美元；dollar 的复数', '名词'], 'dollar' => ['美元', '名词'],
        'everything' => ['所有东西', '代词'], 'different' => ['不同的', '形容词'],
        'sounds' => ['听起来；sound 的第三人称单数', '动词'],
        // Common fairy-tale reading words (shared with 童话星球 word bubbles).
        'once' => ['从前；有一次', '副词'], 'little' => ['小的', '形容词'],
        'girl' => ['女孩', '名词'], 'boy' => ['男孩', '名词'],
        'grandma' => ['奶奶；外婆', '名词'], 'grandmother' => ['奶奶；外婆', '名词'],
        'forest' => ['森林', '名词'], 'woods' => ['树林', '名词'],
        'house' => ['房子', '名词'], 'wolf' => ['狼', '名词'],
        'red' => ['红色的', '形容词'], 'riding' => ['骑马；骑行', '动词'],
        'hood' => ['兜帽；风帽', '名词'], 'went' => ['去了；go 的过去式', '动词'],
        'see' => ['看见', '动词'], 'saw' => ['看见了；see 的过去式', '动词'],
        'there' => ['那里；有', '副词'], 'she' => ['她', '代词'],
        'he' => ['他', '代词'], 'they' => ['他们；她们', '代词'],
        'and' => ['和；并且', '连词'], 'but' => ['但是', '连词'],
        'said' => ['说；say 的过去式', '动词'], 'came' => ['来了；come 的过去式', '动词'],
        'big' => ['大的', '形容词'], 'small' => ['小的', '形容词'],
        'mother' => ['妈妈', '名词'], 'father' => ['爸爸', '名词'],
        'door' => ['门', '名词'], 'path' => ['小路', '名词'],
        'night' => ['夜晚', '名词'], 'day' => ['白天；一天', '名词'],
        'king' => ['国王', '名词'], 'queen' => ['女王；王后', '名词'],
        'princess' => ['公主', '名词'], 'prince' => ['王子', '名词'],
        'magic' => ['魔法；有魔力的', '名词'], 'story' => ['故事', '名词'],
    ];

    public function lookup(string $word, string $context, ?string $key, string $model, DailyMenu $menus): array
    {
        $word = strtolower(trim($word));
        $ingredient = $menus->ingredient($word);
        if ($ingredient) return ['word' => $word, 'meaning_zh' => $ingredient['zh'], 'pos_zh' => '名词'];
        if (isset(self::WORDS[$word])) return ['word' => $word, 'meaning_zh' => self::WORDS[$word][0], 'pos_zh' => self::WORDS[$word][1]];
        if (! $key) return ['word' => $word, 'meaning_zh' => null, 'pos_zh' => null];

        $cacheKey = 'english-beta-glossary:'.hash('sha256', $word.'|'.$context);
        if ($cached = Cache::get($cacheKey)) return $cached;
        try {
            $response = Http::withToken($key)->timeout(6)->post('https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', [
                'model' => preg_match('/^qwen[a-zA-Z0-9._-]{0,64}$/', $model) ? $model : 'qwen-flash',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a concise English-to-Chinese dictionary for children. Explain only the requested word in the given sentence. Return JSON only: {"meaning_zh":"short Chinese meaning in context","pos_zh":"Chinese part of speech"}. No advice, links, or extra text.'],
                    ['role' => 'user', 'content' => "Word: {$word}\nSentence: {$context}"],
                ],
                'max_tokens' => 80,
            ]);
            if ($response->successful()) {
                $raw = (string) $response->json('choices.0.message.content');
                $data = json_decode($raw, true);
                if (is_array($data) && is_string($data['meaning_zh'] ?? null) && is_string($data['pos_zh'] ?? null)
                    && mb_strlen($data['meaning_zh']) <= 40 && mb_strlen($data['pos_zh']) <= 12
                    && ! preg_match('/https?:|[<>]/i', $data['meaning_zh'].$data['pos_zh'])) {
                    $result = ['word' => $word, 'meaning_zh' => $data['meaning_zh'], 'pos_zh' => $data['pos_zh']];
                    Cache::put($cacheKey, $result, now()->addDays(30));
                    return $result;
                }
            }
        } catch (\Throwable) {
            // Word pronunciation remains available when AI is unavailable.
        }
        return ['word' => $word, 'meaning_zh' => null, 'pos_zh' => null];
    }
}
