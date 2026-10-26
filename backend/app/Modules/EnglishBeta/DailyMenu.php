<?php

namespace App\Modules\EnglishBeta;

use Carbon\CarbonImmutable;

class DailyMenu
{
    private const MENUS = [
        ['fruit_salad', '水果沙拉', 'Fruit salad', ['apple', 'banana', 'orange', 'yogurt']],
        ['sandwich', '三明治', 'Sandwich', ['bread', 'cheese', 'tomato', 'lettuce']],
        ['breakfast', '早餐拼盘', 'Breakfast plate', ['egg', 'milk', 'bread', 'banana']],
        ['vegetable_soup', '蔬菜汤', 'Vegetable soup', ['carrot', 'potato', 'onion', 'corn']],
        ['pasta', '番茄意面', 'Tomato pasta', ['pasta', 'tomato', 'mushroom', 'cheese']],
        ['rice_bowl', '鸡肉饭', 'Chicken rice bowl', ['rice', 'chicken', 'broccoli', 'carrot']],
        ['picnic', '野餐盒', 'Picnic box', ['crackers', 'grapes', 'cheese', 'water']],
        ['smoothie', '草莓奶昔', 'Strawberry smoothie', ['strawberry', 'banana', 'milk', 'yogurt']],
        ['pizza', '披萨', 'Pizza', ['dough', 'tomato', 'cheese', 'pepper']],
        ['green_salad', '蔬菜沙拉', 'Green salad', ['lettuce', 'cucumber', 'tomato', 'corn']],
        ['taco', '鸡肉卷', 'Chicken wrap', ['tortilla', 'chicken', 'lettuce', 'avocado']],
        ['pancakes', '松饼', 'Pancakes', ['flour', 'egg', 'milk', 'honey']],
        ['stir_fry', '蔬菜炒饭', 'Vegetable fried rice', ['rice', 'tofu', 'broccoli', 'mushroom']],
        ['baked_potato', '烤土豆', 'Baked potato', ['potato', 'cheese', 'corn', 'butter']],
    ];

    private const RESTAURANT_MENUS = [
        ['breakfast', '早餐', 'Breakfast', ['pancakes', 'eggs', 'toast', 'juice']],
        ['pizza', '披萨晚餐', 'Pizza dinner', ['pizza', 'salad', 'lemonade', 'ice cream']],
        ['noodles', '面条午餐', 'Noodle lunch', ['noodles', 'dumplings', 'tea', 'fruit']],
        ['burger', '汉堡套餐', 'Burger meal', ['burger', 'fries', 'water', 'cookie']],
        ['soup', '热汤午餐', 'Soup lunch', ['soup', 'sandwich', 'milk', 'cake']],
        ['rice', '米饭晚餐', 'Rice dinner', ['rice', 'chicken', 'vegetables', 'juice']],
        ['taco', '卷饼午餐', 'Taco lunch', ['taco', 'corn', 'salad', 'lemonade']],
    ];

    private const DISHES = [
        'pancakes' => '松饼', 'eggs' => '鸡蛋', 'toast' => '吐司', 'juice' => '果汁',
        'pizza' => '披萨', 'salad' => '沙拉', 'lemonade' => '柠檬水', 'ice cream' => '冰淇淋',
        'noodles' => '面条', 'dumplings' => '饺子', 'tea' => '茶', 'fruit' => '水果',
        'burger' => '汉堡', 'fries' => '薯条', 'cookie' => '曲奇饼', 'soup' => '汤',
        'sandwich' => '三明治', 'cake' => '蛋糕', 'vegetables' => '蔬菜', 'taco' => '墨西哥卷饼',
    ];

    private const INGREDIENTS = [
        'apple' => ['苹果', '🍎'], 'banana' => ['香蕉', '🍌'], 'orange' => ['橙子', '🍊'],
        'yogurt' => ['酸奶', '🥣'], 'bread' => ['面包', '🍞'], 'cheese' => ['奶酪', '🧀'],
        'tomato' => ['番茄', '🍅'], 'lettuce' => ['生菜', '🥬'], 'egg' => ['鸡蛋', '🥚'],
        'milk' => ['牛奶', '🥛'], 'carrot' => ['胡萝卜', '🥕'], 'potato' => ['土豆', '🥔'],
        'onion' => ['洋葱', '🧅'], 'corn' => ['玉米', '🌽'], 'pasta' => ['意面', '🍝'],
        'mushroom' => ['蘑菇', '🍄'], 'rice' => ['米饭', '🍚'], 'chicken' => ['鸡肉', '🍗'],
        'broccoli' => ['西兰花', '🥦'], 'crackers' => ['饼干', '🍘'], 'grapes' => ['葡萄', '🍇'],
        'water' => ['水', '💧'], 'strawberry' => ['草莓', '🍓'], 'dough' => ['面团', '🫓'],
        'pepper' => ['彩椒', '🫑'], 'cucumber' => ['黄瓜', '🥒'], 'tortilla' => ['薄饼', '🫓'],
        'avocado' => ['牛油果', '🥑'], 'flour' => ['面粉', '🌾'], 'honey' => ['蜂蜜', '🍯'],
        'tofu' => ['豆腐', '⬜'], 'butter' => ['黄油', '🧈'],
    ];

    public function ingredient(string $word): ?array
    {
        $item = self::INGREDIENTS[$word] ?? null;
        return $item ? ['zh' => $item[0], 'icon' => $item[1]]
            : (isset(self::DISHES[$word]) ? ['zh' => self::DISHES[$word], 'icon' => '🍽️'] : null);
    }

    public function restaurantToday(string $timezone): string
    {
        $date = CarbonImmutable::now($timezone)->toDateString();
        $number = intdiv(CarbonImmutable::parse($date, 'UTC')->timestamp, 86400);
        return self::RESTAURANT_MENUS[($number % count(self::RESTAURANT_MENUS) + count(self::RESTAURANT_MENUS)) % count(self::RESTAURANT_MENUS)][0];
    }

    public function findRestaurant(?string $code): ?array
    {
        foreach (self::RESTAURANT_MENUS as [$key, $zh, $en, $words]) {
            if ($key !== $code) continue;
            return [
                'code' => $key, 'title_zh' => $zh, 'title_en' => $en, 'words' => $words,
                'ingredients' => array_map(fn ($word) => [
                    'word' => $word, 'zh' => $this->ingredient($word)['zh'], 'icon' => $this->ingredient($word)['icon'],
                ], $words),
            ];
        }
        return null;
    }

    public function today(string $timezone): string
    {
        // Local calendar date, independent of UTC rollover.
        $date = CarbonImmutable::now($timezone)->toDateString();
        return $this->todayForDate($date);
    }

    public function find(?string $code): ?array
    {
        foreach (self::MENUS as [$key, $zh, $en, $words]) {
            if ($key !== $code) continue;
            return [
                'code' => $key, 'title_zh' => $zh, 'title_en' => $en,
                'words' => $words,
                'ingredients' => array_map(fn ($word) => [
                    'word' => $word, 'zh' => self::INGREDIENTS[$word][0], 'icon' => self::INGREDIENTS[$word][1],
                ], $words),
            ];
        }

        return null;
    }

    public function choices(?string $exclude = null): array
    {
        return array_values(array_map(
            fn ($menu) => ['code' => $menu[0], 'title_zh' => $menu[1], 'title_en' => $menu[2]],
            array_filter(self::MENUS, fn ($menu) => $menu[0] !== $exclude),
        ));
    }

    public function tomorrowChoices(string $timezone, ?string $todayCode): array
    {
        $all = $this->choices($todayCode);
        $tomorrow = $this->todayForDate(CarbonImmutable::now($timezone)->addDay()->toDateString());
        usort($all, fn ($a, $b) => ($a['code'] === $tomorrow ? 0 : 1) <=> ($b['code'] === $tomorrow ? 0 : 1));

        return array_slice($all, 0, 3);
    }

    private function todayForDate(string $date): string
    {
        $number = intdiv(CarbonImmutable::parse($date, 'UTC')->timestamp, 86400);
        return self::MENUS[($number % count(self::MENUS) + count(self::MENUS)) % count(self::MENUS)][0];
    }
}
