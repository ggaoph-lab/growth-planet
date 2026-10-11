<?php

namespace App\Modules\Movie\Services;

/**
 * Parent-only starter shelf used when TMDB is not configured.
 * A movie that belongs to a franchise carries its series, so adding one part can import the rest.
 */
class BuiltinMovieSuggestions
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function cards(): array
    {
        $cards = [];
        foreach (self::seriesList() as $series) {
            foreach ($series['movies'] as $movie) {
                $cards[] = self::present($movie, $series['key'], $series['name']);
            }
        }
        foreach (self::singles() as $movie) {
            $cards[] = self::present($movie, null, null);
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function overviewFor(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $card = self::find($key);
        $overview = $card['overview'] ?? null;

        return is_string($overview) && trim($overview) !== '' ? $overview : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::seriesList() as $series) {
            foreach ($series['movies'] as $movie) {
                if ($movie['key'] === $key) {
                    return $movie + ['series_key' => $series['key'], 'series_name' => $series['name']];
                }
            }
        }
        foreach (self::singles() as $movie) {
            if ($movie['key'] === $key) {
                return $movie + ['series_key' => null, 'series_name' => null];
            }
        }

        return null;
    }

    /**
     * Every part that should be imported together with this movie, in story order.
     *
     * @return list<array<string, mixed>>
     */
    public static function importSet(string $key): array
    {
        $card = self::find($key);
        if ($card === null) {
            return [];
        }
        if ($card['series_key'] === null) {
            return [$card];
        }

        $set = [];
        foreach (self::seriesList() as $series) {
            if ($series['key'] !== $card['series_key']) {
                continue;
            }
            foreach ($series['movies'] as $movie) {
                $set[] = $movie + ['series_key' => $series['key'], 'series_name' => $series['name']];
            }
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $movie
     * @return array<string, mixed>
     */
    private static function present(array $movie, ?string $seriesKey, ?string $seriesName): array
    {
        return [
            'key' => $movie['key'],
            'source' => 'builtin',
            'external_id' => null,
            'title_zh' => $movie['title_zh'],
            'original_title' => $movie['original_title'],
            'release_year' => $movie['release_year'],
            'runtime_minutes' => $movie['runtime_minutes'],
            'overview' => $movie['overview'],
            'series_key' => $seriesKey,
            'series_name' => $seriesName,
            'poster_url' => '/api/v1/parent/movie-posters/'.$movie['key'],
            'belongs_to_collection' => $seriesKey === null ? null : [
                'external_id' => $seriesKey,
                'name' => $seriesName,
            ],
        ];
    }

    /**
     * Chinese intro (~50–100 chars) plus English intro, shown together in the detail dialog.
     *
     * @return array{key:string, title_zh:string, original_title:string, release_year:int, runtime_minutes:int, overview:string, wiki:string, position:int}
     */
    private static function movie(
        string $key,
        string $titleZh,
        string $original,
        int $year,
        int $runtime,
        string $overviewZh,
        string $overviewEn,
        string $wiki,
        int $position,
    ): array {
        return [
            'key' => $key,
            'title_zh' => $titleZh,
            'original_title' => $original,
            'release_year' => $year,
            'runtime_minutes' => $runtime,
            'overview' => $overviewZh."\n\n".$overviewEn,
            'wiki' => $wiki,
            'position' => $position,
        ];
    }

    /**
     * @return list<array{key:string, name:string, movies:list<array<string, mixed>>}>
     */
    private static function seriesList(): array
    {
        return [
            [
                'key' => 'toy-story',
                'name' => '玩具总动员',
                'movies' => [
                    self::movie(
                        'toy-story-1',
                        '玩具总动员',
                        'Toy Story',
                        1995,
                        81,
                        '牛仔胡迪和航天员巴斯是安迪最爱的玩具。他们先互相较劲，再一起合作，在房间外的冒险里学会友谊，也明白陪伴孩子的意义。',
                        'Woody the cowboy and Buzz Lightyear are Andy\'s favorite toys. After rivalry turns into teamwork, their outdoor adventure teaches them about friendship and what it means to be there for a child.',
                        'Toy_Story',
                        1,
                    ),
                    self::movie(
                        'toy-story-2',
                        '玩具总动员2',
                        'Toy Story 2',
                        1999,
                        92,
                        '胡迪被玩具收藏家偷走，巴斯带着伙伴去营救。大家在商店与陌生玩具之间想办法回家，也思考玩具被珍爱和被遗忘的心情。',
                        'Woody is stolen by a toy collector, and Buzz leads a rescue. Among shop shelves and unfamiliar toys, they fight to get home and reflect on being loved—or left behind.',
                        'Toy_Story_2',
                        2,
                    ),
                    self::movie(
                        'toy-story-3',
                        '玩具总动员3',
                        'Toy Story 3',
                        2010,
                        103,
                        '安迪即将离家上大学，玩具们担心再没人陪伴。他们被送到日托中心后，遭遇新规矩与危险，靠友谊和勇气寻找安全的新家，也明白陪伴的意义。',
                        'Andy is leaving for college, and his toys fear being forgotten. After ending up at a daycare, they face new rules and danger, rely on friendship and courage to find a safer home, and learn what companionship means.',
                        'Toy_Story_3',
                        3,
                    ),
                    self::movie(
                        'toy-story-4',
                        '玩具总动员4',
                        'Toy Story 4',
                        2019,
                        100,
                        '牧羊女再次出现，胡迪要在忠诚与自我之间做选择。旅途中他遇见新朋友叉叉，一起经历迷路、寻找与告别，也学会放手成长。',
                        'Bo Peep returns, and Woody must choose between loyalty and his own path. On the road with new friend Forky, he faces getting lost, searching, and saying goodbye—and learns how to grow by letting go.',
                        'Toy_Story_4',
                        4,
                    ),
                ],
            ],
            [
                'key' => 'frozen',
                'name' => '冰雪奇缘',
                'movies' => [
                    self::movie(
                        'frozen-1',
                        '冰雪奇缘',
                        'Frozen',
                        2013,
                        102,
                        '艾莎的冰雪魔法失控后，王国被冰封。安娜一路寻找姐姐，结识新朋友，用真心融化恐惧与隔阂，证明爱比恐惧更强大，姐妹也能彼此守护。',
                        'When Elsa\'s ice magic spirals out of control, the kingdom freezes over. Anna sets out to find her sister, makes new friends, and proves that love is stronger than fear.',
                        'Frozen_(2013_film)',
                        1,
                    ),
                    self::movie(
                        'frozen-2',
                        '冰雪奇缘2',
                        'Frozen II',
                        2019,
                        103,
                        '神秘声音召唤艾莎前往魔法森林。姐妹俩揭开家族过去，面对自然元素的考验，一起守护阿伦戴尔，也更懂彼此的勇气与牵挂。',
                        'A mysterious voice calls Elsa into an enchanted forest. The sisters uncover their family\'s past, face elemental trials, protect Arendelle, and grow closer than ever.',
                        'Frozen_II',
                        2,
                    ),
                ],
            ],
            [
                'key' => 'kung-fu-panda',
                'name' => '功夫熊猫',
                'movies' => [
                    self::movie(
                        'kung-fu-panda-1',
                        '功夫熊猫',
                        'Kung Fu Panda',
                        2008,
                        92,
                        '爱吃面条的熊猫阿宝意外被选为神龙大侠。他跟着师父苦练功夫，克服自卑，和朋友一起保护和平谷，证明普通人也能变英雄。',
                        'Noodle-loving panda Po is unexpectedly chosen as the Dragon Warrior. Training with his master, he overcomes self-doubt, joins friends to protect the Valley of Peace, and proves everyday folks can be heroes.',
                        'Kung_Fu_Panda_(film)',
                        1,
                    ),
                    self::movie(
                        'kung-fu-panda-2',
                        '功夫熊猫2',
                        'Kung Fu Panda 2',
                        2011,
                        90,
                        '阿宝面对身世谜团，同时要阻止沈王爷的阴谋。他学会内心平静，和功夫伙伴并肩作战，守护山谷与家人，也找回真正的自己。',
                        'Po confronts the mystery of his past while stopping Lord Shen\'s plot. Finding inner peace, he fights beside his kung fu friends to protect the valley and his family.',
                        'Kung_Fu_Panda_2',
                        2,
                    ),
                    self::movie(
                        'kung-fu-panda-3',
                        '功夫熊猫3',
                        'Kung Fu Panda 3',
                        2016,
                        95,
                        '阿宝找到熊猫村庄，和亲生父亲重逢。他要教大家功夫，并对抗企图夺走气的天煞，在家人与朋友支持下成长为真正的导师。',
                        'Po finds a panda village and reunites with his birth father. He teaches kung fu, faces Kai who steals chi, and becomes a true mentor with family and friends behind him.',
                        'Kung_Fu_Panda_3',
                        3,
                    ),
                ],
            ],
            [
                'key' => 'dragon',
                'name' => '驯龙高手',
                'movies' => [
                    self::movie(
                        'dragon-1',
                        '驯龙高手',
                        'How to Train Your Dragon',
                        2010,
                        98,
                        '维京少年小嗝嗝不擅长打仗，却救下一只受伤的夜煞。他慢慢和龙成为朋友，改变村子对龙的看法，证明真正的勇气也可以很温柔。',
                        'Viking teen Hiccup is no fighter, but he rescues an injured Night Fury. Becoming friends with the dragon, he changes his village\'s view of dragons and shows that courage can be gentle.',
                        'How_to_Train_Your_Dragon_(film)',
                        1,
                    ),
                    self::movie(
                        'dragon-2',
                        '驯龙高手2',
                        'How to Train Your Dragon 2',
                        2014,
                        102,
                        '小嗝嗝长大后探索广阔新世界，遇见失散已久的母亲。面对想控制所有龙的敌人，他要守护龙与家园，勇敢承担起领袖的责任。',
                        'Grown-up Hiccup explores new lands and reunites with his long-lost mother. Facing an enemy who wants to control every dragon, he protects dragons and home and steps into leadership.',
                        'How_to_Train_Your_Dragon_2',
                        2,
                    ),
                ],
            ],
            [
                'key' => 'moana',
                'name' => '海洋奇缘',
                'movies' => [
                    self::movie(
                        'moana-1',
                        '海洋奇缘',
                        'Moana',
                        2016,
                        107,
                        '莫阿娜听从海洋召唤出海，半路遇上半神毛伊。他们穿越风暴与怪物，把生命之心送回岛上女神，拯救家园并找到自己的航线。',
                        'Moana answers the ocean\'s call and teams up with demigod Maui. Through storms and monsters, they return the heart of Te Fiti, save her home, and help Moana find her own path.',
                        'Moana_(2016_film)',
                        1,
                    ),
                    self::movie(
                        'moana-2',
                        '海洋奇缘2',
                        'Moana 2',
                        2024,
                        100,
                        '莫阿娜再次勇敢出航，想重新连接分散的岛民。她带领新伙伴穿越未知海域，面对重重挑战，继续守护海洋与族人之间的联系。',
                        'Moana sets sail again to reconnect distant islanders. Leading new companions across unknown seas, she faces fresh challenges and keeps the bond between people and the ocean alive.',
                        'Moana_2',
                        2,
                    ),
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function singles(): array
    {
        return [
            self::movie(
                'zootopia',
                '疯狂动物城',
                'Zootopia',
                2016,
                108,
                '兔子警官朱迪来到动物城，和狐狸尼克搭档破案。他们揭开城市偏见背后的阴谋，证明不同动物也能互相信任、一起守护公平。',
                'Rabbit officer Judy Hopps arrives in Zootopia and pairs with fox Nick to solve a case. They uncover a plot fueled by prejudice and prove different animals can trust each other and protect fairness.',
                'Zootopia',
                1,
            ),
            self::movie(
                'coco',
                '寻梦环游记',
                'Coco',
                2017,
                105,
                '热爱音乐的米格意外误入亡灵世界，一路寻找真正的家族故事。他学会铭记先人，也明白家与梦想可以一起被珍惜和传承。',
                'Music-loving Miguel wanders into the Land of the Dead and seeks his family\'s true story. He learns to remember his ancestors and sees that family and dreams can both be cherished.',
                'Coco_(2017_film)',
                1,
            ),
            self::movie(
                'inside-out',
                '头脑特工队',
                'Inside Out',
                2015,
                95,
                '乐乐、忧忧等情绪小人住在莱莉的脑海中。搬家和新学校打乱一切，他们学会接纳悲伤，帮女孩面对成长里那些复杂的心情。',
                'Joy, Sadness, and the other emotions live in Riley\'s mind. A move and a new school scramble everything, and they learn to accept sadness so Riley can handle growing-up feelings.',
                'Inside_Out_(2015_film)',
                1,
            ),
            self::movie(
                'big-hero-6',
                '超能陆战队',
                'Big Hero 6',
                2014,
                102,
                '小宏在失去哥哥后，与护理机器人大白成为伙伴。他们和朋友组成超能小队，阻止危险计划，也慢慢走出难过、学会互相扶持。',
                'After losing his brother, Hiro teams up with care robot Baymax. With friends they form a super team, stop a dangerous plan, and slowly heal while learning to support one another.',
                'Big_Hero_6_(film)',
                1,
            ),
            self::movie(
                'lion-king',
                '狮子王',
                'The Lion King',
                1994,
                88,
                '小狮子辛巴在失去父亲后远走他乡。长大后他在朋友鼓励下回家，勇敢直面过去，夺回荣耀石，也真正明白责任与勇气的意义。',
                'Young lion Simba flees after losing his father. Grown up and urged by friends, he returns home, faces the past, reclaims Pride Rock, and learns responsibility and courage.',
                'The_Lion_King_(1994_film)',
                1,
            ),
            self::movie(
                'paddington',
                '帕丁顿熊',
                'Paddington',
                2014,
                95,
                '来自秘鲁的帕丁顿熊带着果酱来到伦敦，被布朗一家收留。他用礼貌与善意化解误会，也让这座陌生的大城市多了一份温暖。',
                'Paddington Bear arrives in London from Peru with marmalade and is taken in by the Browns. His manners and kindness clear up misunderstandings and bring warmth to the big city.',
                'Paddington_(film)',
                1,
            ),
            self::movie(
                'totoro',
                '龙猫',
                'My Neighbor Totoro',
                1988,
                86,
                '小月和小梅搬到宁静乡下，遇见森林精灵龙猫。在等待妈妈康复的日子里，她们感受大自然的奇妙，也学会彼此照顾、心怀盼望。',
                'Satsuki and Mei move to the countryside and meet forest spirit Totoro. While waiting for their mother to recover, they discover nature\'s wonders and learn to care for each other with hope.',
                'My_Neighbor_Totoro',
                1,
            ),
        ];
    }
}
