<?php

// Local ladder used when no AI engine is selected, or the selected engine fails.
// The child's known/missed list is the source of truth across vendor changes.
return ['bands' => [
    1 => [
        ['text' => 'bird', 'meaning' => '鸟', 'emoji' => '🐦', 'options' => ['鸟', '鱼', '树']],
        ['text' => 'fish', 'meaning' => '鱼', 'emoji' => '🐟', 'options' => ['鱼', '鸟', '太阳']],
        ['text' => 'tree', 'meaning' => '树', 'emoji' => '🌳', 'options' => ['树', '花', '车']],
        ['text' => 'sun', 'meaning' => '太阳', 'emoji' => '☀️', 'options' => ['月亮', '太阳', '雨']],
        ['text' => 'mom', 'meaning' => '妈妈', 'emoji' => '👩', 'options' => ['妈妈', '爸爸', '朋友']],
        ['text' => 'dad', 'meaning' => '爸爸', 'emoji' => '👨', 'options' => ['妈妈', '爸爸', '老师']],
        ['text' => 'big', 'meaning' => '大的', 'emoji' => '🐘', 'options' => ['大的', '小的', '红的']],
        ['text' => 'small', 'meaning' => '小的', 'emoji' => '🐭', 'options' => ['大的', '小的', '热的']],
        ['text' => 'run', 'meaning' => '跑', 'emoji' => '🏃', 'options' => ['跑', '坐', '吃']],
        ['text' => 'egg', 'meaning' => '鸡蛋', 'emoji' => '🥚', 'options' => ['鸡蛋', '面包', '水']],
        ['text' => 'bus', 'meaning' => '公共汽车', 'emoji' => '🚌', 'options' => ['公共汽车', '飞机', '船']],
        ['text' => 'star', 'meaning' => '星星', 'emoji' => '⭐', 'options' => ['星星', '云', '花']],
    ],
    2 => [
        ['text' => 'school', 'meaning' => '学校', 'emoji' => '🏫', 'options' => ['学校', '公园', '商店']],
        ['text' => 'friend', 'meaning' => '朋友', 'emoji' => '🤝', 'options' => ['朋友', '老师', '弟弟']],
        ['text' => 'rain', 'meaning' => '雨', 'emoji' => '🌧️', 'options' => ['雪', '雨', '风']],
        ['text' => 'bread', 'meaning' => '面包', 'emoji' => '🍞', 'options' => ['米饭', '面包', '蛋糕']],
        ['text' => 'open', 'meaning' => '打开', 'emoji' => '🚪', 'options' => ['打开', '关上', '坐下']],
        ['text' => 'help', 'meaning' => '帮助', 'emoji' => '🛟', 'options' => ['帮助', '等待', '画画']],
        ['text' => 'morning', 'meaning' => '早上', 'emoji' => '🌅', 'options' => ['早上', '晚上', '中午']],
        ['text' => 'window', 'meaning' => '窗户', 'emoji' => '🪟', 'options' => ['门', '窗户', '桌子']],
    ],
    3 => [
        ['text' => 'garden', 'meaning' => '花园', 'emoji' => '🌷', 'options' => ['花园', '厨房', '教室']],
        ['text' => 'hungry', 'meaning' => '饥饿的', 'emoji' => '🍽️', 'options' => ['饥饿的', '口渴的', '困倦的']],
        ['text' => 'sister', 'meaning' => '姐妹', 'emoji' => '👧', 'options' => ['兄弟', '姐妹', '同学']],
        ['text' => 'yellow', 'meaning' => '黄色', 'emoji' => '🟡', 'options' => ['黄色', '紫色', '黑色']],
        ['text' => 'cloud', 'meaning' => '云', 'emoji' => '☁️', 'options' => ['云', '河', '山']],
        ['text' => 'chair', 'meaning' => '椅子', 'emoji' => '🪑', 'options' => ['椅子', '床', '灯']],
    ],
    4 => [
        ['text' => 'breakfast', 'meaning' => '早餐', 'emoji' => '🥣', 'options' => ['早餐', '午餐', '晚餐']],
        ['text' => 'library', 'meaning' => '图书馆', 'emoji' => '📚', 'options' => ['图书馆', '医院', '车站']],
        ['text' => 'quiet', 'meaning' => '安静的', 'emoji' => '🤫', 'options' => ['安静的', '吵闹的', '勇敢的']],
        ['text' => 'weather', 'meaning' => '天气', 'emoji' => '🌤️', 'options' => ['天气', '季节', '方向']],
    ],
    5 => [
        ['text' => 'adventure', 'meaning' => '冒险', 'emoji' => '🧭', 'options' => ['冒险', '秘密', '礼物']],
        ['text' => 'discover', 'meaning' => '发现', 'emoji' => '🔍', 'options' => ['发现', '忘记', '许诺']],
        ['text' => 'gentle', 'meaning' => '温柔的', 'emoji' => '🕊️', 'options' => ['温柔的', '急忙的', '粗糙的']],
        ['text' => 'journey', 'meaning' => '旅程', 'emoji' => '🧳', 'options' => ['旅程', '地图', '门票']],
    ],
]];
