<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

final class CatalogCuratorService
{
    /**
     * Curates and simplifies the entire catalog:
     * 1. Removes the old test subcategory #1 and uncurated test data.
     * 2. Sets clean Category translations (UZ, RU, EN).
     * 3. Creates clean Subcategories (UZ, RU, EN).
     * 4. Deactivates all messy raw auto-sync services.
     * 5. Populates exactly 3 clear tiers (Economy, Standard, Premium) per service type,
     *    plus unique special services, all mapped to active providers and backup failovers.
     */
    public static function curate(): array
    {
        $pdo = Database::connection();

        // 1. Remove initial test subcategory #1 ("OBUNACHILAR") if it exists
        try {
            Database::execute('DELETE FROM subcategories WHERE id = 1 AND (SELECT COUNT(*) FROM services WHERE subcategory_id = 1) = 0');
        } catch (\Throwable) {
            // Ignore if constrained
        }

        // 2. Deactivate all existing messy services so only curated ones are active
        Database::execute('UPDATE services SET is_active = 0');
        Database::execute('UPDATE subcategories SET is_active = 0');
        Database::execute('UPDATE categories SET is_active = 0');

        // Check providers
        $neoProvider = Database::fetchOne('SELECT id FROM providers WHERE api_url LIKE :u LIMIT 1', ['u' => '%neosmm%']);
        $shoxProvider = Database::fetchOne('SELECT id FROM providers WHERE api_url LIKE :u LIMIT 1', ['u' => '%smmsb%']);

        $p1 = $neoProvider ? (int) $neoProvider['id'] : 1;
        $p2 = $shoxProvider ? (int) $shoxProvider['id'] : 40144;

        // Structure of curated services
        $structure = [
            // ==========================================
            // INSTAGRAM
            // ==========================================
            [
                'cat' => [
                    'uz' => '📸 Instagram',
                    'ru' => '📸 Инстаграм',
                    'en' => '📸 Instagram',
                    'sort' => 1,
                ],
                'subs' => [
                    [
                        'name' => [
                            'uz' => '👥 Obunachilar',
                            'ru' => '👥 Подписчики',
                            'en' => '👥 Followers',
                        ],
                        'services' => [
                            [
                                'uz' => '👤 Instagram Obunachi — Hamyonbop (Tezkor)',
                                'ru' => '👤 Подписчики Instagram — Эконом (Быстрые)',
                                'en' => '👤 Instagram Followers — Economy (Fast)',
                                'price' => 18000.0,
                                'rate' => 12850.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '1432',
                                'p2' => $p2, 's2' => '3',
                            ],
                            [
                                'uz' => '👤 Instagram Obunachi — Standart (♻️ 30 kun kafolat)',
                                'ru' => '👤 Подписчики Instagram — Стандарт (♻️ 30 дней гарантия)',
                                'en' => '👤 Instagram Followers — Standard (♻️ 30 Days Refill)',
                                'price' => 32000.0,
                                'rate' => 22850.0,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '1433',
                                'p2' => $p2, 's2' => '7',
                            ],
                            [
                                'uz' => '👤 Instagram Obunachi — Premium (💎 100% Sifatli, Kafolatli)',
                                'ru' => '👤 Подписчики Instagram — Премиум (💎 100% Качество, Гарантия)',
                                'en' => '👤 Instagram Followers — Premium (💎 100% High Quality, Guaranteed)',
                                'price' => 55000.0,
                                'rate' => 39280.0,
                                'min' => 100,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '1435',
                                'p2' => $p2, 's2' => '12',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '❤️ Layklar',
                            'ru' => '❤️ Лайки',
                            'en' => '❤️ Likes',
                        ],
                        'services' => [
                            [
                                'uz' => '❤️ Instagram Layk — Hamyonbop',
                                'ru' => '❤️ Лайки Instagram — Эконом',
                                'en' => '❤️ Instagram Likes — Economy',
                                'price' => 4500.0,
                                'rate' => 3200.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '475',
                                'p2' => $p2, 's2' => '21',
                            ],
                            [
                                'uz' => '❤️ Instagram Layk — Standart (Tezkor)',
                                'ru' => '❤️ Лайки Instagram — Стандарт (Быстрые)',
                                'en' => '❤️ Instagram Likes — Standard (Fast)',
                                'price' => 8500.0,
                                'rate' => 6070.0,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '476',
                                'p2' => $p2, 's2' => '22',
                            ],
                            [
                                'uz' => '❤️ Instagram Layk — Premium (💎 Real profillar)',
                                'ru' => '❤️ Лайки Instagram — Премиум (💎 Реальные пользователи)',
                                'en' => '❤️ Instagram Likes — Premium (💎 Real Profiles)',
                                'price' => 16000.0,
                                'rate' => 11420.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '477',
                                'p2' => $p2, 's2' => '23',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '👁 Reels & Video Ko\'rishlar',
                            'ru' => '👁 Просмотры Reels и видео',
                            'en' => '👁 Reels & Video Views',
                        ],
                        'services' => [
                            [
                                'uz' => '👁 Reels Ko\'rish — Hamyonbop',
                                'ru' => '👁 Просмотры Reels — Эконом',
                                'en' => '👁 Reels Views — Economy',
                                'price' => 1200.0,
                                'rate' => 850.0,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '480',
                                'p2' => $p2, 's2' => '31',
                            ],
                            [
                                'uz' => '👁 Reels Ko\'rish — Standart (Tezkor)',
                                'ru' => '👁 Просмотры Reels — Стандарт (Быстрые)',
                                'en' => '👁 Reels Views — Standard (Fast)',
                                'price' => 2800.0,
                                'rate' => 2000.0,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '481',
                                'p2' => $p2, 's2' => '32',
                            ],
                            [
                                'uz' => '👁 Reels Ko\'rish — Premium (+ Qamrov va Ulashishlar)',
                                'ru' => '👁 Просмотры Reels — Премиум (+ Охват и Репосты)',
                                'en' => '👁 Reels Views — Premium (+ Reach & Shares)',
                                'price' => 6500.0,
                                'rate' => 4640.0,
                                'min' => 100,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '482',
                                'p2' => $p2, 's2' => '33',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '📖 Istoriya (Stories)',
                            'ru' => '📖 Истории (Stories)',
                            'en' => '📖 Stories',
                        ],
                        'services' => [
                            [
                                'uz' => '📖 Istoriya Ko\'rish — Hamyonbop',
                                'ru' => '📖 Просмотры историй — Эконом',
                                'en' => '📖 Story Views — Economy',
                                'price' => 3000.0,
                                'rate' => 2140.0,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '485',
                                'p2' => $p2, 's2' => '35',
                            ],
                            [
                                'uz' => '📖 Istoriya Ko\'rish — Standart (+ Layk)',
                                'ru' => '📖 Просмотры историй — Стандарт (+ Лайк)',
                                'en' => '📖 Story Views — Standard (+ Like)',
                                'price' => 6000.0,
                                'rate' => 4280.0,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '486',
                                'p2' => $p2, 's2' => '36',
                            ],
                            [
                                'uz' => '📊 Istoriya So\'rovnoma Ovozi',
                                'ru' => '📊 Голоса в опросе историй',
                                'en' => '📊 Story Poll Votes',
                                'price' => 12000.0,
                                'rate' => 8570.0,
                                'min' => 50,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '487',
                                'p2' => $p2, 's2' => '37',
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // TELEGRAM
            // ==========================================
            [
                'cat' => [
                    'uz' => '✈️ Telegram',
                    'ru' => '✈️ Телеграм',
                    'en' => '✈️ Telegram',
                    'sort' => 2,
                ],
                'subs' => [
                    [
                        'name' => [
                            'uz' => '👥 Obunachilar (Kanal & Guruh)',
                            'ru' => '👥 Подписчики (Канал и группа)',
                            'en' => '👥 Members (Channel & Group)',
                        ],
                        'services' => [
                            [
                                'uz' => '👥 Telegram Obunachi — Hamyonbop (Arzon mix)',
                                'ru' => '👥 Подписчики Telegram — Эконом (Микс база)',
                                'en' => '👥 Telegram Members — Economy (Mixed)',
                                'price' => 5500.0,
                                'rate' => 3920.0,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '452',
                                'p2' => $p2, 's2' => '56',
                            ],
                            [
                                'uz' => '👥 Telegram Obunachi — Standart (♻️ 60 kun kafolat)',
                                'ru' => '👥 Подписчики Telegram — Стандарт (♻️ 60 дней гарантия)',
                                'en' => '👥 Telegram Members — Standard (♻️ 60 Days Refill)',
                                'price' => 14500.0,
                                'rate' => 10350.0,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '453',
                                'p2' => $p2, 's2' => '56',
                            ],
                            [
                                'uz' => '👥 Telegram Obunachi — Premium (💎 O\'zbek / 0% Tushish)',
                                'ru' => '👥 Подписчики Telegram — Премиум (💎 Узбекские / Без списаний)',
                                'en' => '👥 Telegram Members — Premium (💎 Real Uzbek / Non-drop)',
                                'price' => 26000.0,
                                'rate' => 18570.0,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '454',
                                'p2' => $p2, 's2' => '56',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '👁 Ko\'rishlar (Post Views)',
                            'ru' => '👁 Просмотры постов',
                            'en' => '👁 Post Views',
                        ],
                        'services' => [
                            [
                                'uz' => '👁 Telegram Ko\'rish — Hamyonbop (1 ta post)',
                                'ru' => '👁 Просмотры Telegram — Эконом (1 пост)',
                                'en' => '👁 Telegram Views — Economy (1 post)',
                                'price' => 450.0,
                                'rate' => 320.0,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '460',
                                'p2' => $p2, 's2' => '71',
                            ],
                            [
                                'uz' => '👁 Telegram Ko\'rish — Standart (Tezkor)',
                                'ru' => '👁 Просмотры Telegram — Стандарт (Быстрые)',
                                'en' => '👁 Telegram Views — Standard (Fast)',
                                'price' => 1400.0,
                                'rate' => 1000.0,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '461',
                                'p2' => $p2, 's2' => '72',
                            ],
                            [
                                'uz' => '👁 Telegram Ko\'rish — Premium (Oxirgi 10 ta postga)',
                                'ru' => '👁 Просмотры Telegram — Премиум (На последние 10 постов)',
                                'en' => '👁 Telegram Views — Premium (Last 10 posts)',
                                'price' => 4500.0,
                                'rate' => 3210.0,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '462',
                                'p2' => $p2, 's2' => '73',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '👍 Reaksiyalar',
                            'ru' => '👍 Реакции',
                            'en' => '👍 Reactions',
                        ],
                        'services' => [
                            [
                                'uz' => '👍 Reaksiyalar — Hamyonbop (Aralash)',
                                'ru' => '👍 Реакции — Эконом (Микс)',
                                'en' => '👍 Reactions — Economy (Mixed)',
                                'price' => 1800.0,
                                'rate' => 1280.0,
                                'min' => 50,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '465',
                                'p2' => $p2, 's2' => '86',
                            ],
                            [
                                'uz' => '🔥 Reaksiyalar — Standart (Ijobiy 👍🔥❤️)',
                                'ru' => '🔥 Реакции — Стандарт (Позитивные 👍🔥❤️)',
                                'en' => '🔥 Reactions — Standard (Positive 👍🔥❤️)',
                                'price' => 3800.0,
                                'rate' => 2710.0,
                                'min' => 50,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '466',
                                'p2' => $p2, 's2' => '86',
                            ],
                            [
                                'uz' => '⭐️ Reaksiyalar — Premium (Telegram Stars / Maxsus emoji)',
                                'ru' => '⭐️ Реакции — Премиум (Telegram Stars / Премиум эмодзи)',
                                'en' => '⭐️ Reactions — Premium (Telegram Stars / Premium emojis)',
                                'price' => 14000.0,
                                'rate' => 10000.0,
                                'min' => 20,
                                'max' => 5000,
                                'p1' => $p1, 's1' => '467',
                                'p2' => $p2, 's2' => '86',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '⭐️ Stars & Maxsus xizmatlar',
                            'ru' => '⭐️ Stars и спец-услуги',
                            'en' => '⭐️ Stars & Special Services',
                        ],
                        'services' => [
                            [
                                'uz' => '⭐️ Telegram Stars (Yulduzlar)',
                                'ru' => '⭐️ Telegram Stars (Звёзды)',
                                'en' => '⭐️ Telegram Stars',
                                'price' => 308000.0,
                                'rate' => 220000.0,
                                'min' => 50,
                                'max' => 1000,
                                'p1' => $p1, 's1' => '1409',
                                'p2' => $p2, 's2' => '56',
                            ],
                            [
                                'uz' => '🎁 Telegram Premium Obuna (3 oylik sovg\'a)',
                                'ru' => '🎁 Telegram Premium Подписка (3 месяца)',
                                'en' => '🎁 Telegram Premium Subscription (3 months)',
                                'price' => 165000.0,
                                'rate' => 117850.0,
                                'min' => 1,
                                'max' => 10,
                                'p1' => $p1, 's1' => '1410',
                                'p2' => $p2, 's2' => '56',
                            ],
                            [
                                'uz' => '🤖 Telegram Bot uchun Start (Tezkor)',
                                'ru' => '🤖 Старты для Telegram бота (Быстрые)',
                                'en' => '🤖 Telegram Bot Starts (Fast)',
                                'price' => 28000.0,
                                'rate' => 20000.0,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '470',
                                'p2' => $p2, 's2' => '83',
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // TIKTOK
            // ==========================================
            [
                'cat' => [
                    'uz' => '🎵 TikTok',
                    'ru' => '🎵 ТикТок',
                    'en' => '🎵 TikTok',
                    'sort' => 3,
                ],
                'subs' => [
                    [
                        'name' => [
                            'uz' => '👥 Obunachilar',
                            'ru' => '👥 Подписчики',
                            'en' => '👥 Followers',
                        ],
                        'services' => [
                            [
                                'uz' => '👥 TikTok Obunachi — Hamyonbop',
                                'ru' => '👥 Подписчики TikTok — Эконом',
                                'en' => '👥 TikTok Followers — Economy',
                                'price' => 22000.0,
                                'rate' => 15700.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '501',
                                'p2' => $p2, 's2' => '100',
                            ],
                            [
                                'uz' => '👥 TikTok Obunachi — Standart (♻️ Kafolatli)',
                                'ru' => '👥 Подписчики TikTok — Стандарт (♻️ С гарантией)',
                                'en' => '👥 TikTok Followers — Standard (♻️ Refill)',
                                'price' => 38000.0,
                                'rate' => 27140.0,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '502',
                                'p2' => $p2, 's2' => '100',
                            ],
                            [
                                'uz' => '👥 TikTok Obunachi — Premium (💎 100% Xavfsiz, Sifatli)',
                                'ru' => '👥 Подписчики TikTok — Премиум (💎 100% Безопасные, Качество)',
                                'en' => '👥 TikTok Followers — Premium (💎 100% Safe, HQ)',
                                'price' => 65000.0,
                                'rate' => 46420.0,
                                'min' => 50,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '503',
                                'p2' => $p2, 's2' => '100',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '❤️ Layklar',
                            'ru' => '❤️ Лайки',
                            'en' => '❤️ Likes',
                        ],
                        'services' => [
                            [
                                'uz' => '❤️ TikTok Layk — Hamyonbop',
                                'ru' => '❤️ Лайки TikTok — Эконом',
                                'en' => '❤️ TikTok Likes — Economy',
                                'price' => 8000.0,
                                'rate' => 5700.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '505',
                                'p2' => $p2, 's2' => '101',
                            ],
                            [
                                'uz' => '❤️ TikTok Layk — Standart (Tezkor)',
                                'ru' => '❤️ Лайки TikTok — Стандарт (Быстрые)',
                                'en' => '❤️ TikTok Likes — Standard (Fast)',
                                'price' => 16000.0,
                                'rate' => 11420.0,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '506',
                                'p2' => $p2, 's2' => '101',
                            ],
                            [
                                'uz' => '❤️ TikTok Layk — Premium (Kafolatli)',
                                'ru' => '❤️ Лайки TikTok — Премиум (С гарантией)',
                                'en' => '❤️ TikTok Likes — Premium (Refill)',
                                'price' => 28000.0,
                                'rate' => 20000.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '507',
                                'p2' => $p2, 's2' => '101',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '👁 Ko\'rishlar',
                            'ru' => '👁 Просмотры',
                            'en' => '👁 Views',
                        ],
                        'services' => [
                            [
                                'uz' => '👁 TikTok Ko\'rish — Hamyonbop',
                                'ru' => '👁 Просмотры TikTok — Эконом',
                                'en' => '👁 TikTok Views — Economy',
                                'price' => 800.0,
                                'rate' => 570.0,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '510',
                                'p2' => $p2, 's2' => '101',
                            ],
                            [
                                'uz' => '👁 TikTok Ko\'rish — Standart (Tezkor)',
                                'ru' => '👁 Просмотры TikTok — Стандарт (Быстрые)',
                                'en' => '👁 TikTok Views — Standard (Fast)',
                                'price' => 2000.0,
                                'rate' => 1420.0,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '511',
                                'p2' => $p2, 's2' => '101',
                            ],
                            [
                                'uz' => '👁 TikTok Ko\'rish — Premium (Tavsiyalarga / FYP)',
                                'ru' => '👁 Просмотры TikTok — Премиум (В рекомендации)',
                                'en' => '👁 TikTok Views — Premium (FYP Boost)',
                                'price' => 5500.0,
                                'rate' => 3920.0,
                                'min' => 100,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '512',
                                'p2' => $p2, 's2' => '101',
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // YOUTUBE
            // ==========================================
            [
                'cat' => [
                    'uz' => '▶️ YouTube',
                    'ru' => '▶️ Ютуб',
                    'en' => '▶️ YouTube',
                    'sort' => 4,
                ],
                'subs' => [
                    [
                        'name' => [
                            'uz' => '👥 Obunachilar',
                            'ru' => '👥 Подписчики',
                            'en' => '👥 Subscribers',
                        ],
                        'services' => [
                            [
                                'uz' => '👥 YouTube Obunachi — Hamyonbop',
                                'ru' => '👥 Подписчики YouTube — Эконом',
                                'en' => '👥 YouTube Subscribers — Economy',
                                'price' => 45000.0,
                                'rate' => 32140.0,
                                'min' => 50,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '520',
                                'p2' => $p2, 's2' => '102',
                            ],
                            [
                                'uz' => '👥 YouTube Obunachi — Standart (Kafolatli)',
                                'ru' => '👥 Подписчики YouTube — Стандарт (С гарантией)',
                                'en' => '👥 YouTube Subscribers — Standard (Guaranteed)',
                                'price' => 85000.0,
                                'rate' => 60700.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '521',
                                'p2' => $p2, 's2' => '102',
                            ],
                            [
                                'uz' => '👥 YouTube Obunachi — Premium (Monetizatsiya uchun)',
                                'ru' => '👥 Подписчики YouTube — Премиум (Для монетизации)',
                                'en' => '👥 YouTube Subscribers — Premium (For Monetization)',
                                'price' => 160000.0,
                                'rate' => 114280.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '522',
                                'p2' => $p2, 's2' => '102',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '👁 Ko\'rishlar',
                            'ru' => '👁 Просмотры',
                            'en' => '👁 Views',
                        ],
                        'services' => [
                            [
                                'uz' => '👁 YouTube Ko\'rish — Hamyonbop',
                                'ru' => '👁 Просмотры YouTube — Эконом',
                                'en' => '👁 YouTube Views — Economy',
                                'price' => 12000.0,
                                'rate' => 8570.0,
                                'min' => 100,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '525',
                                'p2' => $p2, 's2' => '103',
                            ],
                            [
                                'uz' => '👁 YouTube Ko\'rish — Standart (Tezkor)',
                                'ru' => '👁 Просмотры YouTube — Стандарт (Быстрые)',
                                'en' => '👁 YouTube Views — Standard (Fast)',
                                'price' => 22000.0,
                                'rate' => 15710.0,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '526',
                                'p2' => $p2, 's2' => '103',
                            ],
                            [
                                'uz' => '👁 YouTube Ko\'rish — Premium (Yuqori retention)',
                                'ru' => '👁 Просмотры YouTube — Премиум (Высокое удержание)',
                                'en' => '👁 YouTube Views — Premium (High Retention)',
                                'price' => 38000.0,
                                'rate' => 27140.0,
                                'min' => 100,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '527',
                                'p2' => $p2, 's2' => '103',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '👍 Layklar',
                            'ru' => '👍 Лайки',
                            'en' => '👍 Likes',
                        ],
                        'services' => [
                            [
                                'uz' => '👍 YouTube Layk — Hamyonbop',
                                'ru' => '👍 Лайки YouTube — Эконом',
                                'en' => '👍 YouTube Likes — Economy',
                                'price' => 10000.0,
                                'rate' => 7140.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '530',
                                'p2' => $p2, 's2' => '104',
                            ],
                            [
                                'uz' => '👍 YouTube Layk — Standart (Tezkor)',
                                'ru' => '👍 Лайки YouTube — Стандарт (Быстрые)',
                                'en' => '👍 YouTube Likes — Standard (Fast)',
                                'price' => 18000.0,
                                'rate' => 12850.0,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '531',
                                'p2' => $p2, 's2' => '104',
                            ],
                            [
                                'uz' => '👍 YouTube Layk — Premium (Kafolatli)',
                                'ru' => '👍 Лайки YouTube — Премиум (С гарантией)',
                                'en' => '👍 YouTube Likes — Premium (Guaranteed)',
                                'price' => 32000.0,
                                'rate' => 22850.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '532',
                                'p2' => $p2, 's2' => '104',
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // FACEBOOK
            // ==========================================
            [
                'cat' => [
                    'uz' => '🌐 Facebook',
                    'ru' => '🌐 Фейсбук',
                    'en' => '🌐 Facebook',
                    'sort' => 5,
                ],
                'subs' => [
                    [
                        'name' => [
                            'uz' => '👥 Obunachilar (Profil & Sahifa)',
                            'ru' => '👥 Подписчики (Профиль и страница)',
                            'en' => '👥 Followers (Profile & Page)',
                        ],
                        'services' => [
                            [
                                'uz' => '👥 Facebook Obunachi — Hamyonbop',
                                'ru' => '👥 Подписчики Facebook — Эконом',
                                'en' => '👥 Facebook Followers — Economy',
                                'price' => 25000.0,
                                'rate' => 17850.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '540',
                                'p2' => $p2, 's2' => '48',
                            ],
                            [
                                'uz' => '👥 Facebook Obunachi — Standart (Kafolatli)',
                                'ru' => '👥 Подписчики Facebook — Стандарт (С гарантией)',
                                'en' => '👥 Facebook Followers — Standard (Guaranteed)',
                                'price' => 45000.0,
                                'rate' => 32140.0,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '541',
                                'p2' => $p2, 's2' => '49',
                            ],
                            [
                                'uz' => '👥 Facebook Obunachi — Premium (Guruh / Sahifa)',
                                'ru' => '👥 Подписчики Facebook — Премиум (Группа / Страница)',
                                'en' => '👥 Facebook Followers — Premium (Group / Page)',
                                'price' => 75000.0,
                                'rate' => 53570.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '542',
                                'p2' => $p2, 's2' => '53',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '❤️ Layklar & Reaksiyalar',
                            'ru' => '❤️ Лайки и реакции',
                            'en' => '❤️ Likes & Reactions',
                        ],
                        'services' => [
                            [
                                'uz' => '❤️ Facebook Layk — Hamyonbop',
                                'ru' => '❤️ Лайки Facebook — Эконом',
                                'en' => '❤️ Facebook Likes — Economy',
                                'price' => 12000.0,
                                'rate' => 8570.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '545',
                                'p2' => $p2, 's2' => '50',
                            ],
                            [
                                'uz' => '🔥 Facebook Reaksiya — Standart (👍❤️🔥)',
                                'ru' => '🔥 Реакции Facebook — Стандарт (👍❤️🔥)',
                                'en' => '🔥 Facebook Reactions — Standard (👍❤️🔥)',
                                'price' => 20000.0,
                                'rate' => 14280.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '546',
                                'p2' => $p2, 's2' => '51',
                            ],
                            [
                                'uz' => '❤️ Facebook Layk — Premium (Kafolatli)',
                                'ru' => '❤️ Лайки Facebook — Премиум (С гарантией)',
                                'en' => '❤️ Facebook Likes — Premium (Guaranteed)',
                                'price' => 35000.0,
                                'rate' => 25000.0,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '547',
                                'p2' => $p2, 's2' => '50',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $insertedCount = 0;
        $pdo->beginTransaction();

        try {
            foreach ($structure as $catData) {
                // Upsert category
                $cat = Database::fetchOne(
                    'SELECT id FROM categories WHERE name_uz = :uz OR name_uz LIKE :uz_like LIMIT 1',
                    [
                        'uz' => $catData['cat']['uz'],
                        'uz_like' => '%' . trim(preg_replace('/[^a-zA-Z0-9]/', '', $catData['cat']['en'])) . '%',
                    ]
                );

                if ($cat !== null) {
                    $catId = (int) $cat['id'];
                    Database::execute(
                        'UPDATE categories SET name_uz = :uz, name_ru = :ru, name_en = :en, sort_order = :so, is_active = 1 WHERE id = :id',
                        [
                            'uz' => $catData['cat']['uz'],
                            'ru' => $catData['cat']['ru'],
                            'en' => $catData['cat']['en'],
                            'so' => $catData['cat']['sort'],
                            'id' => $catId,
                        ]
                    );
                } else {
                    Database::execute(
                        'INSERT INTO categories (name_uz, name_ru, name_en, sort_order, is_active) VALUES (:uz, :ru, :en, :so, 1)',
                        [
                            'uz' => $catData['cat']['uz'],
                            'ru' => $catData['cat']['ru'],
                            'en' => $catData['cat']['en'],
                            'so' => $catData['cat']['sort'],
                        ]
                    );
                    $catId = (int) Database::lastInsertId();
                }

                $subSort = 1;
                foreach ($catData['subs'] as $subData) {
                    $sub = Database::fetchOne(
                        'SELECT id FROM subcategories WHERE category_id = :cid AND (name_uz = :uz OR name_en = :en) LIMIT 1',
                        [
                            'cid' => $catId,
                            'uz' => $subData['name']['uz'],
                            'en' => $subData['name']['en'],
                        ]
                    );

                    if ($sub !== null) {
                        $subId = (int) $sub['id'];
                        Database::execute(
                            'UPDATE subcategories SET name_uz = :uz, name_ru = :ru, name_en = :en, sort_order = :so, is_active = 1 WHERE id = :id',
                            [
                                'uz' => $subData['name']['uz'],
                                'ru' => $subData['name']['ru'],
                                'en' => $subData['name']['en'],
                                'so' => $subSort,
                                'id' => $subId,
                            ]
                        );
                    } else {
                        Database::execute(
                            'INSERT INTO subcategories (category_id, name_uz, name_ru, name_en, sort_order, is_active) VALUES (:cid, :uz, :ru, :en, :so, 1)',
                            [
                                'cid' => $catId,
                                'uz' => $subData['name']['uz'],
                                'ru' => $subData['name']['ru'],
                                'en' => $subData['name']['en'],
                                'so' => $subSort,
                            ]
                        );
                        $subId = (int) Database::lastInsertId();
                    }
                    $subSort++;

                    $svcSort = 1;
                    foreach ($subData['services'] as $svc) {
                        $existingSvc = Database::fetchOne(
                            'SELECT id FROM services WHERE subcategory_id = :sid AND (name_uz = :uz OR name_en = :en) LIMIT 1',
                            [
                                'sid' => $subId,
                                'uz' => $svc['uz'],
                                'en' => $svc['en'],
                            ]
                        );

                        if ($existingSvc !== null) {
                            Database::execute(
                                'UPDATE services SET
                                    name_uz = :uz,
                                    name_ru = :ru,
                                    name_en = :en,
                                    provider_id = :p1,
                                    provider_service_id = :s1,
                                    backup_provider_id = :p2,
                                    backup_service_id = :s2,
                                    rate_per_1000 = :rate,
                                    price_per_1000 = :price,
                                    min_quantity = :min,
                                    max_quantity = :max,
                                    sort_order = :so,
                                    is_active = 1,
                                    updated_at = NOW()
                                 WHERE id = :id',
                                [
                                    'uz' => $svc['uz'],
                                    'ru' => $svc['ru'],
                                    'en' => $svc['en'],
                                    'p1' => $svc['p1'],
                                    's1' => $svc['s1'],
                                    'p2' => $svc['p2'],
                                    's2' => $svc['s2'],
                                    'rate' => $svc['rate'],
                                    'price' => $svc['price'],
                                    'min' => $svc['min'],
                                    'max' => $svc['max'],
                                    'so' => $svcSort,
                                    'id' => $existingSvc['id'],
                                ]
                            );
                        } else {
                            Database::execute(
                                'INSERT INTO services (
                                    subcategory_id, provider_id, provider_service_id,
                                    backup_provider_id, backup_service_id,
                                    name_uz, name_ru, name_en,
                                    order_type, link_type,
                                    rate_per_1000, price_per_1000,
                                    min_quantity, max_quantity,
                                    sort_order, auto_sync_price, is_active
                                ) VALUES (
                                    :sid, :p1, :s1,
                                    :p2, :s2,
                                    :uz, :ru, :en,
                                    :ot, :lt,
                                    :rate, :price,
                                    :min, :max,
                                    :so, 0, 1
                                )',
                                [
                                    'sid' => $subId,
                                    'p1' => $svc['p1'],
                                    's1' => $svc['s1'],
                                    'p2' => $svc['p2'],
                                    's2' => $svc['s2'],
                                    'uz' => $svc['uz'],
                                    'ru' => $svc['ru'],
                                    'en' => $svc['en'],
                                    'ot' => str_contains(strtolower($svc['en']), 'poll') ? 'poll' : 'default',
                                    'lt' => str_contains(strtolower($svc['en']), 'username') ? 'username' : 'url',
                                    'rate' => $svc['rate'],
                                    'price' => $svc['price'],
                                    'min' => $svc['min'],
                                    'max' => $svc['max'],
                                    'so' => $svcSort,
                                ]
                            );
                        }
                        $svcSort++;
                        $insertedCount++;
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'status' => 'ok',
            'curated_services_count' => $insertedCount,
        ];
    }
}
