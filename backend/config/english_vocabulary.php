<?php

// Local ladder used when no AI engine is selected, or the selected engine fails.
// Mix words and short sentences so probe slots are not word-only.
// The child's known/missed list is the source of truth across vendor changes.
return ['bands' => [
    1 => [
        ['text' => 'bird', 'meaning' => '鸟', 'emoji' => '🐦', 'ipa' => '/bɜːrd/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['鸟', '鱼', '树']],
        ['text' => 'fish', 'meaning' => '鱼', 'emoji' => '🐟', 'ipa' => '/fɪʃ/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['鱼', '鸟', '太阳']],
        ['text' => 'I see a bird.', 'meaning' => '我看见一只鸟。', 'emoji' => '👀', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['我看见一只鸟。', '我想喝水。', '这是一棵树。']],
        ['text' => 'tree', 'meaning' => '树', 'emoji' => '🌳', 'ipa' => '/triː/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['树', '花', '车']],
        ['text' => 'sun', 'meaning' => '太阳', 'emoji' => '☀️', 'ipa' => '/sʌn/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['月亮', '太阳', '雨']],
        ['text' => 'The sun is big.', 'meaning' => '太阳很大。', 'emoji' => '🌞', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['太阳很大。', '我喜欢鱼。', '这是妈妈。']],
        ['text' => 'mom', 'meaning' => '妈妈', 'emoji' => '👩', 'ipa' => '/mɑːm/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['妈妈', '爸爸', '朋友']],
        ['text' => 'dad', 'meaning' => '爸爸', 'emoji' => '👨', 'ipa' => '/dæd/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['妈妈', '爸爸', '老师']],
        ['text' => 'This is my dad.', 'meaning' => '这是我的爸爸。', 'emoji' => '👨‍👧', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['这是我的爸爸。', '我想跑步。', '鸡蛋很好吃。']],
        ['text' => 'big', 'meaning' => '大的', 'emoji' => '🐘', 'ipa' => '/bɪɡ/', 'pos' => 'adj.', 'kind' => 'word', 'options' => ['大的', '小的', '红的']],
        ['text' => 'small', 'meaning' => '小的', 'emoji' => '🐭', 'ipa' => '/smɔːl/', 'pos' => 'adj.', 'kind' => 'word', 'options' => ['大的', '小的', '热的']],
        ['text' => 'run', 'meaning' => '跑', 'emoji' => '🏃', 'ipa' => '/rʌn/', 'pos' => 'v.', 'kind' => 'word', 'options' => ['跑', '坐', '吃']],
        ['text' => 'egg', 'meaning' => '鸡蛋', 'emoji' => '🥚', 'ipa' => '/eɡ/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['鸡蛋', '面包', '水']],
        ['text' => 'bus', 'meaning' => '公共汽车', 'emoji' => '🚌', 'ipa' => '/bʌs/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['公共汽车', '飞机', '船']],
        ['text' => 'star', 'meaning' => '星星', 'emoji' => '⭐', 'ipa' => '/stɑːr/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['星星', '云', '花']],
        ['text' => 'I can run.', 'meaning' => '我会跑。', 'emoji' => '🏃', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['我会跑。', '我想坐。', '这是一颗星星。']],
    ],
    2 => [
        ['text' => 'school', 'meaning' => '学校', 'emoji' => '🏫', 'ipa' => '/skuːl/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['学校', '公园', '商店']],
        ['text' => 'friend', 'meaning' => '朋友', 'emoji' => '🤝', 'ipa' => '/frend/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['朋友', '老师', '弟弟']],
        ['text' => 'I like school.', 'meaning' => '我喜欢学校。', 'emoji' => '🎒', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['我喜欢学校。', '下雨了。', '请打开门。']],
        ['text' => 'rain', 'meaning' => '雨', 'emoji' => '🌧️', 'ipa' => '/reɪn/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['雪', '雨', '风']],
        ['text' => 'bread', 'meaning' => '面包', 'emoji' => '🍞', 'ipa' => '/bred/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['米饭', '面包', '蛋糕']],
        ['text' => 'open', 'meaning' => '打开', 'emoji' => '🚪', 'ipa' => '/ˈoʊpən/', 'pos' => 'v.', 'kind' => 'word', 'options' => ['打开', '关上', '坐下']],
        ['text' => 'help', 'meaning' => '帮助', 'emoji' => '🛟', 'ipa' => '/help/', 'pos' => 'v.', 'kind' => 'word', 'options' => ['帮助', '等待', '画画']],
        ['text' => 'morning', 'meaning' => '早上', 'emoji' => '🌅', 'ipa' => '/ˈmɔːrnɪŋ/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['早上', '晚上', '中午']],
        ['text' => 'window', 'meaning' => '窗户', 'emoji' => '🪟', 'ipa' => '/ˈwɪndoʊ/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['门', '窗户', '桌子']],
        ['text' => 'Good morning!', 'meaning' => '早上好！', 'emoji' => '🌅', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['早上好！', '再见！', '请帮我。']],
    ],
    3 => [
        ['text' => 'garden', 'meaning' => '花园', 'emoji' => '🌷', 'ipa' => '/ˈɡɑːrdn/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['花园', '厨房', '教室']],
        ['text' => 'hungry', 'meaning' => '饥饿的', 'emoji' => '🍽️', 'ipa' => '/ˈhʌŋɡri/', 'pos' => 'adj.', 'kind' => 'word', 'options' => ['饥饿的', '口渴的', '困倦的']],
        ['text' => 'I am hungry.', 'meaning' => '我饿了。', 'emoji' => '🍽️', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['我饿了。', '这是一把椅子。', '云在天上。']],
        ['text' => 'sister', 'meaning' => '姐妹', 'emoji' => '👧', 'ipa' => '/ˈsɪstər/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['兄弟', '姐妹', '同学']],
        ['text' => 'yellow', 'meaning' => '黄色', 'emoji' => '🟡', 'ipa' => '/ˈjeloʊ/', 'pos' => 'adj.', 'kind' => 'word', 'options' => ['黄色', '紫色', '黑色']],
        ['text' => 'cloud', 'meaning' => '云', 'emoji' => '☁️', 'ipa' => '/klaʊd/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['云', '河', '山']],
        ['text' => 'chair', 'meaning' => '椅子', 'emoji' => '🪑', 'ipa' => '/tʃer/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['椅子', '床', '灯']],
    ],
    4 => [
        ['text' => 'breakfast', 'meaning' => '早餐', 'emoji' => '🥣', 'ipa' => '/ˈbrekfəst/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['早餐', '午餐', '晚餐']],
        ['text' => 'library', 'meaning' => '图书馆', 'emoji' => '📚', 'ipa' => '/ˈlaɪbreri/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['图书馆', '医院', '车站']],
        ['text' => 'Be quiet, please.', 'meaning' => '请安静。', 'emoji' => '🤫', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['请安静。', '今天天气很好。', '我们去图书馆。']],
        ['text' => 'quiet', 'meaning' => '安静的', 'emoji' => '🤫', 'ipa' => '/ˈkwaɪət/', 'pos' => 'adj.', 'kind' => 'word', 'options' => ['安静的', '吵闹的', '勇敢的']],
        ['text' => 'weather', 'meaning' => '天气', 'emoji' => '🌤️', 'ipa' => '/ˈweðər/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['天气', '季节', '方向']],
    ],
    5 => [
        ['text' => 'adventure', 'meaning' => '冒险', 'emoji' => '🧭', 'ipa' => '/ədˈventʃər/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['冒险', '秘密', '礼物']],
        ['text' => 'discover', 'meaning' => '发现', 'emoji' => '🔍', 'ipa' => '/dɪˈskʌvər/', 'pos' => 'v.', 'kind' => 'word', 'options' => ['发现', '忘记', '许诺']],
        ['text' => 'What a journey!', 'meaning' => '多棒的旅程啊！', 'emoji' => '🧳', 'ipa' => '', 'pos' => '', 'kind' => 'sentence', 'options' => ['多棒的旅程啊！', '请温柔一点。', '我发现了秘密。']],
        ['text' => 'gentle', 'meaning' => '温柔的', 'emoji' => '🕊️', 'ipa' => '/ˈdʒentl/', 'pos' => 'adj.', 'kind' => 'word', 'options' => ['温柔的', '急忙的', '粗糙的']],
        ['text' => 'journey', 'meaning' => '旅程', 'emoji' => '🧳', 'ipa' => '/ˈdʒɜːrni/', 'pos' => 'n.', 'kind' => 'word', 'options' => ['旅程', '地图', '门票']],
    ],
]];
