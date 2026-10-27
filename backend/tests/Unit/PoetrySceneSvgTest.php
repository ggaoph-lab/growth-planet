<?php

namespace Tests\Unit;

use App\Modules\Poetry\Image\PoetrySceneSvg;
use PHPUnit\Framework\TestCase;

class PoetrySceneSvgTest extends TestCase
{
    public function test_detects_grade1_scenes_and_ignores_style_suffix(): void
    {
        $svg = new PoetrySceneSvg;
        $cases = [
            'moon' => '古诗《静夜思》床前明月光，疑是地上霜。中国传统水墨画风格，儿童绘本风',
            'goose' => '古诗《咏鹅》鹅，鹅，鹅，曲项向天歌。中国传统水墨画风格',
            'spring' => '古诗《春晓》春眠不觉晓，处处闻啼鸟。',
            'lotus' => '古诗《小池》小荷才露尖尖角，早有蜻蜓立上头。',
            'snow' => '古诗《江雪》孤舟蓑笠翁，独钓寒江雪。',
            'tower' => '古诗《登鹳雀楼》黄河入海流。',
            'bean' => '古诗《相思》红豆生南国。',
            'farm' => '古诗《悯农（其一）》锄禾日当午。',
            'bridge' => '古诗《枫桥夜泊》江枫渔火对愁眠。',
            'kite' => '古诗《村居》忙趁东风放纸鸢。',
            'boat' => '古诗《赠汪伦》桃花潭水深千尺。',
            'landscape' => '古诗《画》远看山有色，近听水无声。中国传统水墨画风格',
        ];

        foreach ($cases as $scene => $prompt) {
            $this->assertSame($scene, $svg->detectScene($prompt), $prompt);
            $url = $svg->render($prompt)['url'];
            $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        }

        $moon = $svg->render($cases['moon'])['url'];
        $goose = $svg->render($cases['goose'])['url'];
        $this->assertNotSame($moon, $goose);
    }
}
