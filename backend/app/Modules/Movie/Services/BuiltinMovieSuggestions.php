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
     * @return array{key:string, title_zh:string, original_title:string, release_year:int, runtime_minutes:int, overview:string, wiki:string, position:int}
     */
    private static function movie(
        string $key,
        string $titleZh,
        string $original,
        int $year,
        int $runtime,
        string $overview,
        string $wiki,
        int $position,
    ): array {
        return [
            'key' => $key,
            'title_zh' => $titleZh,
            'original_title' => $original,
            'release_year' => $year,
            'runtime_minutes' => $runtime,
            'overview' => $overview,
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
                    self::movie('toy-story-1', '玩具总动员', 'Toy Story', 1995, 81, '玩具们在主人不在时有自己的冒险。', 'Toy_Story', 1),
                    self::movie('toy-story-2', '玩具总动员2', 'Toy Story 2', 1999, 92, '玩具们被带到一家商店，想办法回家。', 'Toy_Story_2', 2),
                    self::movie('toy-story-3', '玩具总动员3', 'Toy Story 3', 2010, 103, '主人长大后，玩具们一起面对新的去处。', 'Toy_Story_3', 3),
                    self::movie('toy-story-4', '玩具总动员4', 'Toy Story 4', 2019, 100, '牧羊女回来了，胡迪要做一个选择。', 'Toy_Story_4', 4),
                ],
            ],
            [
                'key' => 'frozen',
                'name' => '冰雪奇缘',
                'movies' => [
                    self::movie('frozen-1', '冰雪奇缘', 'Frozen', 2013, 102, '两位公主一起面对冰雪魔法。', 'Frozen_(2013_film)', 1),
                    self::movie('frozen-2', '冰雪奇缘2', 'Frozen II', 2019, 103, '姐妹俩走进魔法森林，寻找过去的答案。', 'Frozen_II', 2),
                ],
            ],
            [
                'key' => 'kung-fu-panda',
                'name' => '功夫熊猫',
                'movies' => [
                    self::movie('kung-fu-panda-1', '功夫熊猫', 'Kung Fu Panda', 2008, 92, '爱吃的熊猫学功夫，保护山谷。', 'Kung_Fu_Panda_(film)', 1),
                    self::movie('kung-fu-panda-2', '功夫熊猫2', 'Kung Fu Panda 2', 2011, 90, '阿宝面对自己的身世，继续保护朋友。', 'Kung_Fu_Panda_2', 2),
                    self::movie('kung-fu-panda-3', '功夫熊猫3', 'Kung Fu Panda 3', 2016, 95, '阿宝找到熊猫家族，一起对抗新的对手。', 'Kung_Fu_Panda_3', 3),
                ],
            ],
            [
                'key' => 'dragon',
                'name' => '驯龙高手',
                'movies' => [
                    self::movie('dragon-1', '驯龙高手', 'How to Train Your Dragon', 2010, 98, '男孩和受伤的龙成为朋友。', 'How_to_Train_Your_Dragon_(film)', 1),
                    self::movie('dragon-2', '驯龙高手2', 'How to Train Your Dragon 2', 2014, 102, '小嗝嗝长大后，要守护龙和村子。', 'How_to_Train_Your_Dragon_2', 2),
                ],
            ],
            [
                'key' => 'moana',
                'name' => '海洋奇缘',
                'movies' => [
                    self::movie('moana-1', '海洋奇缘', 'Moana', 2016, 107, '少女出海，把心送回岛上的女神。', 'Moana_(2016_film)', 1),
                    self::movie('moana-2', '海洋奇缘2', 'Moana 2', 2024, 100, '莫阿娜再次出航，连接分散的岛民。', 'Moana_2', 2),
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
            self::movie('zootopia', '疯狂动物城', 'Zootopia', 2016, 108, '兔子警官和狐狸搭档在动物城里查案。', 'Zootopia', 1),
            self::movie('coco', '寻梦环游记', 'Coco', 2017, 105, '男孩在亡灵世界寻找家人的故事。', 'Coco_(2017_film)', 1),
            self::movie('inside-out', '头脑特工队', 'Inside Out', 2015, 95, '情绪小人们帮女孩面对搬家和新学校。', 'Inside_Out_(2015_film)', 1),
            self::movie('big-hero-6', '超能陆战队', 'Big Hero 6', 2014, 102, '机器人大白陪伴男孩走出失去亲人的难过。', 'Big_Hero_6_(film)', 1),
            self::movie('lion-king', '狮子王', 'The Lion King', 1994, 88, '小狮子辛巴长大后回到荣耀石。', 'The_Lion_King_(1994_film)', 1),
            self::movie('paddington', '帕丁顿熊', 'Paddington', 2014, 95, '一只礼貌的小熊在伦敦找到了家。', 'Paddington_(film)', 1),
            self::movie('totoro', '龙猫', 'My Neighbor Totoro', 1988, 86, '两姐妹在乡下遇见森林里的龙猫。', 'My_Neighbor_Totoro', 1),
        ];
    }
}
