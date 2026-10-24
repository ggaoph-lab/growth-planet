<?php

namespace App\Modules\Task\Services;

/**
 * Qwen-TTS voices for parent settings (DashScope qwen3-tts-flash list).
 *
 * @see https://help.aliyun.com/zh/model-studio/qwen-tts-voice-list
 */
class QwenTtsVoiceCatalog
{
    /**
     * @return list<array{id: string, name_zh: string, name_en: string, desc_zh: string, desc_en: string, tags: list<string>}>
     */
    public function forLang(string $lang): array
    {
        $english = str_starts_with(strtolower(str_replace('_', '-', trim($lang))), 'en');

        return $english ? $this->english() : $this->chinese();
    }

    /**
     * @return array{zh: list<array<string, mixed>>, en: list<array<string, mixed>>}
     */
    public function all(): array
    {
        return [
            'zh' => $this->chinese(),
            'en' => $this->english(),
        ];
    }

    public function isKnown(string $voice): bool
    {
        $id = trim($voice);
        if ($id === '') {
            return false;
        }

        foreach ($this->allVoices() as $row) {
            if ($row['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mandarin-first picks for kid Chinese read-aloud (kids/child near top).
     *
     * @return list<array{id: string, name_zh: string, name_en: string, desc_zh: string, desc_en: string, tags: list<string>}>
     */
    private function chinese(): array
    {
        return $this->pick([
            'Cherry', 'Serena', 'Ethan', 'Bella', 'Bunny', 'Mia', 'Pip', 'Mochi',
            'Nini', 'Stella', 'Chelsie', 'Momo', 'Vivian', 'Maia', 'Moon', 'Kai',
            'Seren', 'Elias', 'Neil', 'Ryan', 'Katerina', 'Jennifer', 'Aiden',
            'Nofish', 'Bellona', 'Vincent', 'Arthur', 'Eldric Sage',
            'Sunny', 'Dylan', 'Jada', 'Kiki', 'Rocky', 'Peter',
        ]);
    }

    /**
     * English picks: US-tagged first, then kid-like / bilingual.
     *
     * @return list<array{id: string, name_zh: string, name_en: string, desc_zh: string, desc_en: string, tags: list<string>}>
     */
    private function english(): array
    {
        return $this->pick([
            'Jennifer', 'Aiden', 'Bella', 'Bunny', 'Pip', 'Mia', 'Mochi', 'Nini',
            'Cherry', 'Serena', 'Ethan', 'Stella', 'Chelsie', 'Momo', 'Vivian',
            'Maia', 'Ryan', 'Neil', 'Moon', 'Kai', 'Seren', 'Elias', 'Katerina',
            'Andre', 'Emilien', 'Lenn', 'Sohee', 'Ono Anna',
            'Bodega', 'Sonrisa', 'Alek', 'Dolce',
        ]);
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string, name_zh: string, name_en: string, desc_zh: string, desc_en: string, tags: list<string>}>
     */
    private function pick(array $ids): array
    {
        $byId = [];
        foreach ($this->allVoices() as $row) {
            $byId[$row['id']] = $row;
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * @return list<array{id: string, name_zh: string, name_en: string, desc_zh: string, desc_en: string, tags: list<string>}>
     */
    private function allVoices(): array
    {
        return [
            $this->voice('Cherry', '芊悦', 'Cherry', '阳光积极、亲切自然', 'Bright and friendly', ['female', 'kids']),
            $this->voice('Serena', '苏瑶', 'Serena', '温柔小姐姐', 'Gentle and soft', ['female', 'kids']),
            $this->voice('Ethan', '晨煦', 'Ethan', '阳光温暖、有朝气', 'Warm and energetic', ['male']),
            $this->voice('Chelsie', '千雪', 'Chelsie', '软萌二次元女声', 'Soft anime-like', ['female']),
            $this->voice('Momo', '茉兔', 'Momo', '撒娇搞怪、逗你开心', 'Playful and teasing', ['female', 'kids']),
            $this->voice('Vivian', '十三', 'Vivian', '拽拽的、可爱小暴躁', 'Cute and sassy', ['female']),
            $this->voice('Moon', '月白', 'Moon', '率性帅气', 'Cool and dashing', ['male']),
            $this->voice('Maia', '四月', 'Maia', '知性与温柔', 'Thoughtful and gentle', ['female']),
            $this->voice('Kai', '凯', 'Kai', '舒缓放松男声', 'Soothing male', ['male']),
            $this->voice('Nofish', '不吃鱼', 'Nofish', '不会翘舌音的设计师', 'Designer voice, no retroflex', ['male']),
            $this->voice('Bella', '萌宝', 'Bella', '可爱小萝莉音', 'Cute childlike tone', ['female', 'kids', 'child']),
            $this->voice('Jennifer', '詹妮弗', 'Jennifer', '品牌级电影质感美语女声（推荐英语跟读）', 'Cinematic US English female — best for EN practice', ['female', 'us']),
            $this->voice('Ryan', '甜茶', 'Ryan', '节奏拉满、戏感强', 'Dramatic and rhythmic', ['male']),
            $this->voice('Katerina', '卡捷琳娜', 'Katerina', '御姐音色、韵律丰富', 'Mature and rhythmic', ['female']),
            $this->voice('Aiden', '艾登', 'Aiden', '美语大男孩', 'US English young man', ['male', 'us']),
            $this->voice('Eldric Sage', '沧明子', 'Eldric Sage', '沉稳睿智的老者', 'Wise elder', ['male']),
            $this->voice('Mia', '乖小妹', 'Mia', '温顺乖巧', 'Sweet and obedient', ['female', 'kids', 'child']),
            $this->voice('Mochi', '沙小弥', 'Mochi', '聪明伶俐的小大人', 'Bright little grown-up', ['male', 'kids', 'child']),
            $this->voice('Bellona', '燕铮莺', 'Bellona', '洪亮清晰、人物鲜活', 'Loud, clear, vivid', ['female']),
            $this->voice('Vincent', '田叔', 'Vincent', '沙哑烟嗓、江湖感', 'Husky storyteller', ['male']),
            $this->voice('Bunny', '萌小姬', 'Bunny', '萌属性爆棚的小萝莉', 'Very cute childlike', ['female', 'kids', 'child']),
            $this->voice('Neil', '阿闻', 'Neil', '新闻主播式清晰男声', 'Clear news-anchor style', ['male']),
            $this->voice('Elias', '墨讲师', 'Elias', '讲解清晰、适合知识内容', 'Clear teaching voice', ['female']),
            $this->voice('Arthur', '徐大爷', 'Arthur', '质朴长辈讲故事', 'Folksy storytelling elder', ['male']),
            $this->voice('Nini', '邻家妹妹', 'Nini', '软糯甜妹音', 'Soft and sticky-sweet', ['female', 'kids']),
            $this->voice('Seren', '小婉', 'Seren', '温和舒缓、助眠感', 'Calm and sleepy-soft', ['female']),
            $this->voice('Pip', '顽屁小孩', 'Pip', '调皮捣蛋、充满童真', 'Playful kid energy', ['male', 'kids', 'child']),
            $this->voice('Stella', '少女阿月', 'Stella', '甜系少女音', 'Sweet teen girl', ['female']),
            $this->voice('Bodega', '博德加', 'Bodega', '热情西班牙大叔（西语感）', 'Warm Spanish uncle vibe', ['male']),
            $this->voice('Sonrisa', '索尼莎', 'Sonrisa', '热情开朗的拉美大姐', 'Cheerful Latin American', ['female']),
            $this->voice('Alek', '阿列克', 'Alek', '偏俄语感男声', 'Russian-tinged male', ['male']),
            $this->voice('Dolce', '多尔切', 'Dolce', '慵懒意大利大叔', 'Laid-back Italian male', ['male']),
            $this->voice('Sohee', '素熙', 'Sohee', '温柔开朗韩国欧尼', 'Warm Korean unnie', ['female']),
            $this->voice('Ono Anna', '小野杏', 'Ono Anna', '鬼灵精怪青梅竹马', 'Playful Japanese childhood friend', ['female']),
            $this->voice('Lenn', '莱恩', 'Lenn', '偏德语感青年男声', 'German-tinged young male', ['male']),
            $this->voice('Emilien', '埃米尔安', 'Emilien', '浪漫法国大哥哥', 'Romantic French big-brother', ['male']),
            $this->voice('Andre', '安德雷', 'Andre', '磁性沉稳男声', 'Magnetic and steady', ['male']),
            $this->voice('Radio Gol', '拉迪奥·戈尔', 'Radio Gol', '足球解说诗人', 'Football commentator poet', ['male']),
            $this->voice('Jada', '上海-阿珍', 'Jada', '风风火火的沪上阿姐（上海话）', 'Spirited Shanghai auntie', ['female', 'dialect']),
            $this->voice('Dylan', '北京-晓东', 'Dylan', '北京胡同少年（北京话）', 'Beijing hutong youth', ['male', 'dialect']),
            $this->voice('Li', '南京-老李', 'Li', '耐心的瑜伽老师（南京话）', 'Patient Nanjing yoga teacher', ['male', 'dialect']),
            $this->voice('Marcus', '陕西-秦川', 'Marcus', '面宽话短的老陕（陕西话）', 'Plain-spoken Shaanxi', ['male', 'dialect']),
            $this->voice('Roy', '闽南-阿杰', 'Roy', '诙谐市井（闽南语）', 'Witty Minnan vibe', ['male', 'dialect']),
            $this->voice('Peter', '天津-李彼得', 'Peter', '天津相声捧哏（天津话）', 'Tianjin crosstalk sidekick', ['male', 'dialect']),
            $this->voice('Sunny', '四川-晴儿', 'Sunny', '甜到心里的川妹子（四川话）', 'Sweet Sichuan girl', ['female', 'dialect']),
            $this->voice('Eric', '四川-程川', 'Eric', '跳脱市井的成都男声（四川话）', 'Lively Chengdu male', ['male', 'dialect']),
            $this->voice('Rocky', '粤语-阿强', 'Rocky', '幽默风趣（粤语）', 'Humorous Cantonese', ['male', 'dialect']),
            $this->voice('Kiki', '粤语-阿清', 'Kiki', '甜美港妹闺蜜（粤语）', 'Sweet Cantonese girlfriend', ['female', 'dialect']),
        ];
    }

    /**
     * @param  list<string>  $tags
     * @return array{id: string, name_zh: string, name_en: string, desc_zh: string, desc_en: string, tags: list<string>}
     */
    private function voice(string $id, string $nameZh, string $nameEn, string $descZh, string $descEn, array $tags): array
    {
        return [
            'id' => $id,
            'name_zh' => $nameZh,
            'name_en' => $nameEn,
            'desc_zh' => $descZh,
            'desc_en' => $descEn,
            'tags' => $tags,
        ];
    }
}
