<?php

namespace App\Modules\Poetry\Image;

/**
 * Deterministic watercolor-ish SVG scenes keyed off poem wording.
 * Used by MockImageProvider (offline / no API key) so each poem looks distinct.
 */
final class PoetrySceneSvg
{
    /**
     * @return array{url: string, scene: string}
     */
    public function render(string $prompt): array
    {
        $scene = $this->detectScene($prompt);
        $svg = $this->svgFor($scene, $prompt);
        $url = 'data:image/svg+xml;base64,'.base64_encode($svg);

        return ['url' => $url, 'scene' => $scene];
    }

    public function detectScene(string $text): string
    {
        // Ignore style-suffix noise (e.g. 「水墨画」) so every poem does not
        // collapse into the landscape scene.
        $t = preg_replace('/中国传统.*$/u', '', $text) ?? $text;
        $t = preg_replace('/，?儿童绘本.*$/u', '', $t) ?? $t;

        // Specific titles / unique phrases first; generic tokens last.
        $rules = [
            'goose' => ['《咏鹅》', '鹅，鹅，鹅', '白毛浮绿水', '红掌拨清波'],
            'lotus' => ['《小池》', '小荷才露', '蜻蜓立上头', '泉眼无声'],
            'spring' => ['《春晓》', '春眠不觉晓', '处处闻啼鸟', '花落知多少'],
            'snow' => ['《江雪》', '独钓寒江雪', '蓑笠翁', '千山鸟飞绝'],
            'tower' => ['《登鹳雀楼》', '黄河入海流', '欲穷千里目', '白日依山尽'],
            'bean' => ['《相思》', '红豆生南国', '愿君多采撷'],
            'farm' => ['悯农', '锄禾日当午', '盘中餐', '粒粒皆辛苦'],
            'bridge' => ['《枫桥夜泊》', '江枫渔火', '寒山寺', '夜半钟声'],
            'kite' => ['《村居》', '忙趁东风放纸鸢', '拂堤杨柳', '儿童散学'],
            'boat' => ['《赠汪伦》', '桃花潭水', '汪伦送我', '岸上踏歌'],
            'landscape' => ['《画》', '远看山有色', '近听水无声', '人来鸟不惊'],
            'moon' => ['《静夜思》', '床前明月光', '疑是地上霜', '低头思故乡', '举头望明月'],
        ];

        foreach ($rules as $scene => $needles) {
            foreach ($needles as $needle) {
                if (mb_strpos($t, $needle) !== false) {
                    return $scene;
                }
            }
        }

        // Stable variety for unknown / 诗泉 poems.
        $idx = hexdec(substr(hash('sha256', $t), 0, 2)) % 5;

        return ['moon', 'spring', 'landscape', 'boat', 'lotus'][$idx];
    }

    private function svgFor(string $scene, string $prompt): string
    {
        $title = $this->extractTitle($prompt);
        $palette = $this->palette($scene);
        $art = match ($scene) {
            'goose' => $this->artGoose($palette),
            'lotus' => $this->artLotus($palette),
            'moon' => $this->artMoon($palette),
            'spring' => $this->artSpring($palette),
            'snow' => $this->artSnow($palette),
            'tower' => $this->artTower($palette),
            'bean' => $this->artBean($palette),
            'farm' => $this->artFarm($palette),
            'bridge' => $this->artBridge($palette),
            'kite' => $this->artKite($palette),
            'boat' => $this->artBoat($palette),
            default => $this->artLandscape($palette),
        };

        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 520" role="img" aria-label="{$safeTitle}">
  <defs>
    <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="{$palette['sky1']}"/>
      <stop offset="100%" stop-color="{$palette['sky2']}"/>
    </linearGradient>
    <linearGradient id="ground" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="{$palette['ground1']}"/>
      <stop offset="100%" stop-color="{$palette['ground2']}"/>
    </linearGradient>
    <filter id="soft" x="-10%" y="-10%" width="120%" height="120%">
      <feGaussianBlur stdDeviation="0.6"/>
    </filter>
  </defs>
  <rect width="800" height="520" fill="url(#sky)"/>
  {$art}
  <rect x="24" y="24" width="752" height="472" rx="28" fill="none" stroke="{$palette['frame']}" stroke-width="3" opacity="0.35"/>
</svg>
SVG;
    }

    private function extractTitle(string $prompt): string
    {
        if (preg_match('/《([^》]+)》/u', $prompt, $m)) {
            return $m[1];
        }
        if (preg_match('/古诗[《\s]*([^\s，,》]+)/u', $prompt, $m)) {
            return $m[1];
        }

        return '诗词';
    }

    /** @return array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string} */
    private function palette(string $scene): array
    {
        return match ($scene) {
            'goose' => [
                'sky1' => '#d9f0ff', 'sky2' => '#9ad0ef', 'ground1' => '#6ec1a0', 'ground2' => '#3f8f78',
                'accent' => '#e85d4c', 'ink' => '#2a3a45', 'frame' => '#ffffff', 'water' => '#5eb3d4', 'light' => '#fff6c8',
            ],
            'lotus' => [
                'sky1' => '#e7f7d8', 'sky2' => '#b7dfb0', 'ground1' => '#6fbf8a', 'ground2' => '#3f8a62',
                'accent' => '#f2a0c0', 'ink' => '#2f4638', 'frame' => '#ffffff', 'water' => '#6eb8c9', 'light' => '#ffe9a8',
            ],
            'moon' => [
                'sky1' => '#1d2a5c', 'sky2' => '#0f1738', 'ground1' => '#24304f', 'ground2' => '#141c33',
                'accent' => '#f4e3a1', 'ink' => '#e8eefc', 'frame' => '#c9d4f5', 'water' => '#2d3f6e', 'light' => '#fff4c2',
            ],
            'spring' => [
                'sky1' => '#ffe9c8', 'sky2' => '#f7c48a', 'ground1' => '#8fce6e', 'ground2' => '#5ea34a',
                'accent' => '#ff8fab', 'ink' => '#3a4030', 'frame' => '#ffffff', 'water' => '#7ec8e3', 'light' => '#fff1b8',
            ],
            'snow' => [
                'sky1' => '#dfe9f5', 'sky2' => '#b7c7dc', 'ground1' => '#edf3fa', 'ground2' => '#c9d6e8',
                'accent' => '#6b7c93', 'ink' => '#2e3a4a', 'frame' => '#ffffff', 'water' => '#9eb4cd', 'light' => '#ffffff',
            ],
            'tower' => [
                'sky1' => '#ffd7a8', 'sky2' => '#f08a5a', 'ground1' => '#c9783c', 'ground2' => '#8d4a28',
                'accent' => '#ffe08a', 'ink' => '#3a2718', 'frame' => '#ffffff', 'water' => '#e6b25c', 'light' => '#fff0b0',
            ],
            'bean' => [
                'sky1' => '#ffe4ec', 'sky2' => '#f5b7c9', 'ground1' => '#8fbf78', 'ground2' => '#5d9150',
                'accent' => '#d62839', 'ink' => '#3a2a30', 'frame' => '#ffffff', 'water' => '#7eb9a0', 'light' => '#ffe9a0',
            ],
            'farm' => [
                'sky1' => '#fff0c2', 'sky2' => '#f0c56e', 'ground1' => '#c2a15a', 'ground2' => '#8a6b36',
                'accent' => '#6b8f3a', 'ink' => '#3a3018', 'frame' => '#ffffff', 'water' => '#8eb8d0', 'light' => '#ffe7a0',
            ],
            'bridge' => [
                'sky1' => '#2a3358', 'sky2' => '#151a30', 'ground1' => '#3a2c3a', 'ground2' => '#1c1420',
                'accent' => '#ff7a45', 'ink' => '#f0e6d8', 'frame' => '#d8c8b0', 'water' => '#2f4a62', 'light' => '#ffd39a',
            ],
            'kite' => [
                'sky1' => '#cfefff', 'sky2' => '#9ad4f5', 'ground1' => '#9ad46e', 'ground2' => '#5fa348',
                'accent' => '#ff6b6b', 'ink' => '#2f4030', 'frame' => '#ffffff', 'water' => '#7ec8e3', 'light' => '#fff4b0',
            ],
            'boat' => [
                'sky1' => '#ffd6c8', 'sky2' => '#f0a090', 'ground1' => '#7ebf8a', 'ground2' => '#4f8f62',
                'accent' => '#ff8f9f', 'ink' => '#3a2a28', 'frame' => '#ffffff', 'water' => '#5aa7c4', 'light' => '#ffe6b0',
            ],
            default => [
                'sky1' => '#e8f4ff', 'sky2' => '#b9d4f0', 'ground1' => '#8fbf8a', 'ground2' => '#5a8f68',
                'accent' => '#c9a227', 'ink' => '#2f3a40', 'frame' => '#ffffff', 'water' => '#6eafc9', 'light' => '#fff2b8',
            ],
        };
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artMoon(array $p): string
    {
        return <<<SVG
  <circle cx="620" cy="120" r="58" fill="{$p['light']}" opacity="0.95"/>
  <circle cx="640" cy="108" r="58" fill="url(#sky)" opacity="0.55"/>
  <ellipse cx="400" cy="430" rx="340" ry="48" fill="{$p['ground1']}" opacity="0.55"/>
  <rect x="120" y="300" width="220" height="140" rx="8" fill="{$p['ink']}" opacity="0.22"/>
  <rect x="145" y="325" width="70" height="50" rx="4" fill="{$p['light']}" opacity="0.25"/>
  <rect x="235" y="325" width="70" height="50" rx="4" fill="{$p['light']}" opacity="0.18"/>
  <path d="M80 360 Q200 300 320 360" fill="none" stroke="{$p['accent']}" stroke-width="3" opacity="0.35"/>
  <circle cx="180" cy="250" r="3" fill="{$p['light']}"/><circle cx="260" cy="210" r="2" fill="{$p['light']}"/>
  <circle cx="420" cy="180" r="2.5" fill="{$p['light']}"/><circle cx="500" cy="230" r="2" fill="{$p['light']}"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artSpring(array $p): string
    {
        return <<<SVG
  <circle cx="640" cy="110" r="46" fill="{$p['light']}"/>
  <ellipse cx="400" cy="460" rx="360" ry="70" fill="url(#ground)"/>
  <path d="M120 360 C160 280, 220 260, 260 340" fill="none" stroke="{$p['ink']}" stroke-width="8" stroke-linecap="round" opacity="0.55"/>
  <circle cx="150" cy="270" r="22" fill="{$p['accent']}" opacity="0.75"/>
  <circle cx="190" cy="250" r="18" fill="{$p['accent']}" opacity="0.65"/>
  <circle cx="230" cy="275" r="20" fill="{$p['accent']}" opacity="0.7"/>
  <path d="M480 390 C520 300, 600 290, 650 380" fill="none" stroke="{$p['ink']}" stroke-width="7" opacity="0.45"/>
  <ellipse cx="560" cy="300" rx="40" ry="18" fill="#7ecf6a" opacity="0.85" transform="rotate(-20 560 300)"/>
  <path d="M300 180 q20 -30 40 0 q-20 10 -40 0" fill="{$p['ink']}" opacity="0.55"/>
  <path d="M360 160 q18 -28 36 0 q-18 10 -36 0" fill="{$p['ink']}" opacity="0.45"/>
  <circle cx="420" cy="420" r="8" fill="{$p['accent']}" opacity="0.8"/>
  <circle cx="450" cy="435" r="6" fill="{$p['accent']}" opacity="0.7"/>
  <circle cx="390" cy="438" r="7" fill="{$p['accent']}" opacity="0.75"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artGoose(array $p): string
    {
        return <<<SVG
  <ellipse cx="400" cy="390" rx="320" ry="90" fill="{$p['water']}" opacity="0.85"/>
  <path d="M0 390 Q200 350 400 390 T800 390 L800 520 L0 520 Z" fill="{$p['water']}" opacity="0.55"/>
  <ellipse cx="360" cy="350" rx="70" ry="34" fill="#f7f7f2"/>
  <path d="M420 340 q40 -50 8 -78" fill="none" stroke="#f7f7f2" stroke-width="22" stroke-linecap="round"/>
  <circle cx="430" cy="250" r="22" fill="#f7f7f2"/>
  <circle cx="438" cy="246" r="3" fill="{$p['ink']}"/>
  <path d="M448 252 q18 4 22 12" fill="{$p['accent']}"/>
  <ellipse cx="320" cy="372" rx="22" ry="10" fill="{$p['accent']}"/>
  <path d="M180 400 q80 20 160 0" fill="none" stroke="#ffffff" stroke-width="3" opacity="0.45"/>
  <path d="M420 410 q90 18 180 0" fill="none" stroke="#ffffff" stroke-width="3" opacity="0.35"/>
  <circle cx="620" cy="120" r="40" fill="{$p['light']}" opacity="0.9"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artLotus(array $p): string
    {
        return <<<SVG
  <ellipse cx="400" cy="400" rx="340" ry="95" fill="{$p['water']}" opacity="0.8"/>
  <ellipse cx="280" cy="390" rx="70" ry="24" fill="#4f9a62" opacity="0.9"/>
  <ellipse cx="470" cy="410" rx="80" ry="26" fill="#4f9a62" opacity="0.85"/>
  <path d="M400 410 L410 250" stroke="#3f7a52" stroke-width="6" stroke-linecap="round"/>
  <ellipse cx="430" cy="230" rx="34" ry="18" fill="{$p['accent']}" transform="rotate(-18 430 230)"/>
  <ellipse cx="400" cy="245" rx="28" ry="14" fill="#ffc1d6" transform="rotate(12 400 245)"/>
  <circle cx="418" cy="236" r="8" fill="#ffd86b"/>
  <path d="M470 250 q30 -40 55 -10" fill="none" stroke="{$p['ink']}" stroke-width="3" opacity="0.55"/>
  <ellipse cx="530" cy="230" rx="18" ry="8" fill="#5aa86a" transform="rotate(25 530 230)"/>
  <circle cx="515" cy="238" r="4" fill="{$p['ink']}" opacity="0.7"/>
  <circle cx="650" cy="110" r="36" fill="{$p['light']}"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artSnow(array $p): string
    {
        return <<<SVG
  <path d="M0 280 L180 160 L320 250 L480 120 L640 220 L800 150 L800 520 L0 520 Z" fill="{$p['ground1']}" opacity="0.95"/>
  <path d="M0 360 Q220 320 420 360 T800 350 L800 520 L0 520 Z" fill="{$p['water']}" opacity="0.65"/>
  <ellipse cx="430" cy="390" rx="70" ry="14" fill="{$p['ink']}" opacity="0.35"/>
  <path d="M390 390 q40 -10 80 0" fill="none" stroke="{$p['ink']}" stroke-width="5"/>
  <circle cx="430" cy="360" r="14" fill="{$p['accent']}"/>
  <path d="M418 370 l-18 30 m28 -30 l18 30" stroke="{$p['ink']}" stroke-width="4" stroke-linecap="round"/>
  <path d="M360 392 L500 392" stroke="{$p['ink']}" stroke-width="3" opacity="0.5"/>
  <circle cx="160" cy="120" r="3" fill="{$p['light']}"/><circle cx="240" cy="90" r="2" fill="{$p['light']}"/>
  <circle cx="520" cy="100" r="2.5" fill="{$p['light']}"/><circle cx="600" cy="140" r="3" fill="{$p['light']}"/>
  <circle cx="700" cy="80" r="2" fill="{$p['light']}"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artTower(array $p): string
    {
        return <<<SVG
  <circle cx="620" cy="150" r="55" fill="{$p['light']}" opacity="0.9"/>
  <path d="M0 300 L220 180 L400 260 L800 200 L800 520 L0 520 Z" fill="{$p['ground1']}"/>
  <path d="M0 380 Q300 340 800 390 L800 520 L0 520 Z" fill="{$p['water']}" opacity="0.75"/>
  <rect x="180" y="220" width="90" height="150" fill="{$p['ink']}" opacity="0.55"/>
  <polygon points="170,220 225,170 280,220" fill="{$p['accent']}" opacity="0.85"/>
  <rect x="195" y="250" width="20" height="24" fill="{$p['light']}" opacity="0.45"/>
  <rect x="235" y="250" width="20" height="24" fill="{$p['light']}" opacity="0.35"/>
  <rect x="195" y="300" width="20" height="24" fill="{$p['light']}" opacity="0.4"/>
  <rect x="235" y="300" width="20" height="24" fill="{$p['light']}" opacity="0.3"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artBean(array $p): string
    {
        return <<<SVG
  <ellipse cx="400" cy="460" rx="360" ry="70" fill="url(#ground)"/>
  <path d="M260 420 C300 260, 360 220, 420 360" fill="none" stroke="{$p['ink']}" stroke-width="8" stroke-linecap="round" opacity="0.5"/>
  <ellipse cx="340" cy="280" rx="26" ry="34" fill="{$p['accent']}" transform="rotate(-18 340 280)"/>
  <ellipse cx="390" cy="250" rx="24" ry="32" fill="{$p['accent']}" transform="rotate(12 390 250)"/>
  <ellipse cx="430" cy="300" rx="22" ry="30" fill="#b91d2e" transform="rotate(-8 430 300)"/>
  <path d="M360 240 q20 -40 45 -10" fill="none" stroke="#4f8a48" stroke-width="5"/>
  <circle cx="620" cy="120" r="40" fill="{$p['light']}"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artFarm(array $p): string
    {
        return <<<SVG
  <circle cx="620" cy="110" r="48" fill="{$p['light']}"/>
  <path d="M0 300 Q200 250 400 300 T800 290 L800 520 L0 520 Z" fill="url(#ground)"/>
  <path d="M80 340 q40 -50 20 -90" stroke="{$p['accent']}" stroke-width="5" fill="none"/>
  <path d="M140 340 q40 -55 18 -95" stroke="{$p['accent']}" stroke-width="5" fill="none"/>
  <path d="M200 345 q40 -50 16 -88" stroke="{$p['accent']}" stroke-width="5" fill="none"/>
  <path d="M260 350 q38 -48 14 -86" stroke="{$p['accent']}" stroke-width="5" fill="none"/>
  <circle cx="430" cy="330" r="16" fill="{$p['ink']}" opacity="0.55"/>
  <path d="M430 345 v55" stroke="{$p['ink']}" stroke-width="8" stroke-linecap="round" opacity="0.5"/>
  <path d="M400 400 h70" stroke="{$p['ink']}" stroke-width="6" stroke-linecap="round" opacity="0.45"/>
  <ellipse cx="560" cy="420" rx="50" ry="18" fill="#f3e2b0" opacity="0.85"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artBridge(array $p): string
    {
        return <<<SVG
  <circle cx="160" cy="120" r="28" fill="{$p['light']}" opacity="0.75"/>
  <path d="M0 360 Q250 300 500 360 T800 350 L800 520 L0 520 Z" fill="{$p['water']}" opacity="0.7"/>
  <path d="M120 340 Q260 250 420 340" fill="none" stroke="{$p['ink']}" stroke-width="10" opacity="0.55"/>
  <rect x="500" y="250" width="70" height="120" fill="{$p['ink']}" opacity="0.4"/>
  <polygon points="490,250 535,200 580,250" fill="{$p['accent']}" opacity="0.55"/>
  <ellipse cx="620" cy="390" rx="40" ry="12" fill="{$p['accent']}" opacity="0.85"/>
  <path d="M600 390 q20 -25 40 0" fill="{$p['ink']}" opacity="0.45"/>
  <circle cx="280" cy="160" r="2" fill="{$p['light']}"/><circle cx="340" cy="130" r="2" fill="{$p['light']}"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artKite(array $p): string
    {
        return <<<SVG
  <circle cx="650" cy="100" r="42" fill="{$p['light']}"/>
  <ellipse cx="400" cy="460" rx="360" ry="70" fill="url(#ground)"/>
  <path d="M80 400 C140 280, 220 260, 260 380" fill="none" stroke="#6f9a48" stroke-width="10" opacity="0.7"/>
  <path d="M200 300 q40 -20 70 10" fill="#8fbf5a" opacity="0.8"/>
  <path d="M480 220 L560 160 L620 230 L540 280 Z" fill="{$p['accent']}" opacity="0.9"/>
  <path d="M560 160 L540 280" stroke="{$p['ink']}" stroke-width="2" opacity="0.5"/>
  <path d="M480 220 L620 230" stroke="{$p['ink']}" stroke-width="2" opacity="0.5"/>
  <path d="M540 280 C500 340, 470 380, 450 420" fill="none" stroke="{$p['ink']}" stroke-width="2" opacity="0.45"/>
  <circle cx="430" cy="430" r="12" fill="{$p['ink']}" opacity="0.45"/>
  <path d="M430 442 v40" stroke="{$p['ink']}" stroke-width="6" opacity="0.4"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artBoat(array $p): string
    {
        return <<<SVG
  <circle cx="620" cy="110" r="44" fill="{$p['light']}"/>
  <path d="M0 340 Q220 300 420 340 T800 330 L800 520 L0 520 Z" fill="{$p['water']}" opacity="0.8"/>
  <ellipse cx="220" cy="300" rx="50" ry="70" fill="{$p['accent']}" opacity="0.75"/>
  <ellipse cx="280" cy="280" rx="40" ry="55" fill="#ffb0b8" opacity="0.7"/>
  <path d="M420 360 q80 20 160 0 l-20 30 q-60 12 -120 0 z" fill="{$p['ink']}" opacity="0.45"/>
  <path d="M500 360 v-50" stroke="{$p['ink']}" stroke-width="4" opacity="0.5"/>
  <path d="M120 380 C180 330, 240 360, 280 390" fill="none" stroke="{$p['ink']}" stroke-width="3" opacity="0.35"/>
SVG;
    }

    /** @param  array{sky1:string,sky2:string,ground1:string,ground2:string,accent:string,ink:string,frame:string,water:string,light:string}  $p */
    private function artLandscape(array $p): string
    {
        return <<<SVG
  <circle cx="640" cy="110" r="40" fill="{$p['light']}"/>
  <path d="M0 320 L160 180 L300 280 L460 140 L620 250 L800 170 L800 520 L0 520 Z" fill="{$p['ground1']}" opacity="0.9"/>
  <path d="M0 400 Q250 350 500 400 T800 390 L800 520 L0 520 Z" fill="{$p['water']}" opacity="0.7"/>
  <path d="M120 420 q40 -80 10 -130" fill="none" stroke="#5f9a58" stroke-width="8" opacity="0.7"/>
  <circle cx="140" cy="280" r="28" fill="#7fbf68" opacity="0.85"/>
  <path d="M560 300 q30 -60 60 -10" fill="none" stroke="{$p['ink']}" stroke-width="3" opacity="0.4"/>
  <circle cx="620" cy="280" r="6" fill="{$p['ink']}" opacity="0.45"/>
SVG;
    }
}
