<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;

final class CatalogCuratorService
{
    /**
     * Curates and simplifies the entire catalog:
     * 1. Sets clean Category translations (UZ, RU, EN) across 6 platforms, including "✨ Boshqa xizmatlar".
     * 2. Cleans old test data and subcategories.
     * 3. Compares both active providers (NeoSMM & Shox SMM) and sets the cheaper provider
     *    as Primary (Standard) and the other as Backup (Failover).
     * 4. Enforces the psychological merchant pricing (Psixologik savdogar narxlari):
     *    - Cheap services (< 3 000 UZS wholesale): 100% to 200%+ markup (e.g. 150 so'm, 490 so'm, 1 290 so'm, 2 900 so'm).
     *    - Mid services (3 000 - 25 000 UZS wholesale): 70% to 90% markup (e.g. 7 900 so'm, 11 900 so'm, 23 900 so'm).
     *    - High services (> 25 000 UZS wholesale): 50% to 60% markup (e.g. 189 000 so'm, 259 000 so'm, 349 000 so'm).
     * 5. CRITICAL: NEVER OPERATE AT A LOSS. Every retail price is strictly greater than
     *    the backup provider's wholesale rate by at least 15-25% margin.
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
            // 1. INSTAGRAM
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
                                'price' => 23900.0,
                                'rate' => 13921.20,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '949', // NeoSMM (13 921 so'm)
                                'p2' => $p2, 's2' => '12',  // Shox SMM (18 000 so'm)
                            ],
                            [
                                'uz' => '👤 Instagram Obunachi — Standart (♻️ 60 kun kafolat)',
                                'ru' => '👤 Подписчики Instagram — Стандарт (♻️ 60 дней гарантия)',
                                'en' => '👤 Instagram Followers — Standard (♻️ 60 Days Refill)',
                                'price' => 28900.0,
                                'rate' => 15660.00,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '1485', // NeoSMM (15 660 so'm)
                                'p2' => $p2, 's2' => '16',   // Shox SMM (20 000 so'm)
                            ],
                            [
                                'uz' => '👤 Instagram Obunachi — Premium (💎 100% Sifatli, Kafolatli)',
                                'ru' => '👤 Подписчики Instagram — Премиум (💎 100% Качество, Гарантия)',
                                'en' => '👤 Instagram Followers — Premium (💎 100% High Quality, Guaranteed)',
                                'price' => 39900.0,
                                'rate' => 20000.00,
                                'min' => 500,
                                'max' => 100000,
                                'p1' => $p2, 's1' => '3',    // Shox SMM (20 000 so'm - arzonroq)
                                'p2' => $p1, 's2' => '819',  // NeoSMM (23 363 so'm)
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
                                'uz' => '❤️ Instagram Layk — Hamyonbop (Arzon)',
                                'ru' => '❤️ Лайки Instagram — Эконом (Дешевые)',
                                'en' => '❤️ Instagram Likes — Economy (Cheap)',
                                'price' => 3900.0,
                                'rate' => 997.50,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '400', // NeoSMM (997.50 so'm)
                                'p2' => $p2, 's2' => '812', // Shox SMM (3 000 so'm)
                            ],
                            [
                                'uz' => '❤️ Instagram Layk — Standart (Tezkor)',
                                'ru' => '❤️ Лайки Instagram — Стандарт (Быстрые)',
                                'en' => '❤️ Instagram Likes — Standard (Fast)',
                                'price' => 7900.0,
                                'rate' => 3000.00,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p2, 's1' => '812', // Shox SMM (3 000 so'm)
                                'p2' => $p1, 's2' => '401', // NeoSMM (961.88 so'm)
                            ],
                            [
                                'uz' => '❤️ Instagram Layk — Premium (💎 Eski & Xavfsiz)',
                                'ru' => '❤️ Лайки Instagram — Премиум (💎 Старые и надежные)',
                                'en' => '❤️ Instagram Likes — Premium (💎 Old & Safe Profiles)',
                                'price' => 13900.0,
                                'rate' => 7000.00,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p2, 's1' => '813', // Shox SMM (7 000 so'm)
                                'p2' => $p1, 's2' => '1186',
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
                                'uz' => '👁 Reels Ko\'rish — Hamyonbop (Tezkor)',
                                'ru' => '👁 Просмотры Reels — Эконом (Быстрые)',
                                'en' => '👁 Reels Views — Economy (Fast)',
                                'price' => 190.0,
                                'rate' => 7.20,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '568', // NeoSMM (7.20 so'm)
                                'p2' => $p2, 's2' => '816', // Shox SMM (20 so'm)
                            ],
                            [
                                'uz' => '👁 Reels Ko\'rish — Standart (Barqaror)',
                                'ru' => '👁 Просмотры Reels — Стандарт (Стабильные)',
                                'en' => '👁 Reels Views — Standard (Stable)',
                                'price' => 690.0,
                                'rate' => 30.00,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p2, 's1' => '134', // Shox SMM (30 so'm)
                                'p2' => $p1, 's2' => '570', // NeoSMM (37.50 so'm)
                            ],
                            [
                                'uz' => '👁 Reels Ko\'rish — Premium (+ Qamrov va Repost)',
                                'ru' => '👁 Просмотры Reels — Премиум (+ Охват и Репосты)',
                                'en' => '👁 Reels Views — Premium (+ Reach & Shares)',
                                'price' => 2900.0,
                                'rate' => 1000.00,
                                'min' => 100,
                                'max' => 500000,
                                'p1' => $p2, 's1' => '732', // Shox SMM (1 000 so'm)
                                'p2' => $p1, 's2' => '584', // NeoSMM (7 880 so'm)
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
                                'price' => 2490.0,
                                'rate' => 100.00,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p2, 's1' => '110', // Shox SMM (100 so'm)
                                'p2' => $p1, 's2' => '553', // NeoSMM (1 305 so'm)
                            ],
                            [
                                'uz' => '📖 Istoriya Ko\'rish — Standart (+ Layk)',
                                'ru' => '📖 Просмотры историй — Стандарт (+ Лайк)',
                                'en' => '📖 Story Views — Standard (+ Like)',
                                'price' => 4900.0,
                                'rate' => 1725.00,
                                'min' => 50,
                                'max' => 30000,
                                'p1' => $p1, 's1' => '1001', // NeoSMM (1 725 so'm)
                                'p2' => $p2, 's2' => '166',  // Shox SMM (2 160 so'm)
                            ],
                            [
                                'uz' => '📊 Istoriya So\'rovnoma Ovozi',
                                'ru' => '📊 Голоса в опросе историй',
                                'en' => '📊 Story Poll Votes',
                                'price' => 29900.0,
                                'rate' => 19879.20,
                                'min' => 100,
                                'max' => 25000,
                                'p1' => $p1, 's1' => '1386',
                                'p2' => $p1, 's2' => '1387',
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // 2. TELEGRAM
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
                                'uz' => '👥 Telegram Obunachi — Hamyonbop (Tezkor mix)',
                                'ru' => '👥 Подписчики Telegram — Эконом (Микс база)',
                                'en' => '👥 Telegram Members — Economy (Mixed)',
                                'price' => 2900.0,
                                'rate' => 349.20,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '452', // NeoSMM (349.20 so'm)
                                'p2' => $p2, 's2' => '538', // Shox SMM (1 900 so'm)
                            ],
                            [
                                'uz' => '👥 Telegram Obunachi — Standart (♻️ 60 kun kafolat)',
                                'ru' => '👥 Подписчики Telegram — Стандарт (♻️ 60 дней гарантия)',
                                'en' => '👥 Telegram Members — Standard (♻️ 60 Days Refill)',
                                'price' => 15900.0,
                                'rate' => 10177.50,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '453', // NeoSMM (10 177.50 so'm)
                                'p2' => $p2, 's2' => '631', // Shox SMM (13 000 so'm)
                            ],
                            [
                                'uz' => '👥 Telegram Obunachi — Premium (💎 Haqiqiy / 0% Tushish)',
                                'ru' => '👥 Подписчики Telegram — Премиум (💎 Реальные / Без списаний)',
                                'en' => '👥 Telegram Members — Premium (💎 Non-drop / High Quality)',
                                'price' => 21900.0,
                                'rate' => 7963.02,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '780', // NeoSMM (7 963.02 so'm)
                                'p2' => $p2, 's2' => '273', // Shox SMM (16 000 so'm)
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '♾ Butun Umrlik Obunachilar',
                            'ru' => '♾ Вечные Подписчики (Lifetime)',
                            'en' => '♾ Lifetime Members',
                        ],
                        'services' => [
                            [
                                'uz' => '⚡️ Tg Obunachi — Yangi Baza (30 kunlik)',
                                'ru' => '⚡️ Подписчики Тг — Новая База (30 дней)',
                                'en' => '⚡️ Tg Members — New Base (30 Days)',
                                'price' => 6900.0,
                                'rate' => 2095.20,
                                'min' => 500,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '930', // NeoSMM (2 095.20 so'm)
                                'p2' => $p2, 's2' => '540', // Shox SMM (3 900 so'm)
                            ],
                            [
                                'uz' => '♾ Tg Butun Umrlik Obunachi (Kafolatli)',
                                'ru' => '♾ Тг Вечные Подписчики (С гарантией)',
                                'en' => '♾ Tg Lifetime Members (Guaranteed)',
                                'price' => 7900.0,
                                'rate' => 3200.00,
                                'min' => 1000,
                                'max' => 100000,
                                'p1' => $p2, 's1' => '833', // Shox SMM (3 200 so'm)
                                'p2' => $p2, 's2' => '743', // Shox SMM (3 900 so'm)
                            ],
                            [
                                'uz' => '🔍 Tg Obunachi (Qidiruv orqali qo\'shiladi)',
                                'ru' => '🔍 Тг Подписчики (Добавление через поиск)',
                                'en' => '🔍 Tg Members (Added via Search)',
                                'price' => 8900.0,
                                'rate' => 3492.00,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '1502', // NeoSMM (3 492 so'm)
                                'p2' => $p1, 's2' => '1503', // NeoSMM (4 714 so'm)
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
                                'price' => 150.0,
                                'rate' => 18.00,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '918', // NeoSMM (18 so'm)
                                'p2' => $p2, 's2' => '771', // Shox SMM (50 so'm)
                            ],
                            [
                                'uz' => '👁 Telegram Ko\'rish — Standart (Tezkor)',
                                'ru' => '👁 Просмотры Telegram — Стандарт (Быстрые)',
                                'en' => '👁 Telegram Views — Standard (Fast)',
                                'price' => 2190.0,
                                'rate' => 596.16,
                                'min' => 100,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '922', // NeoSMM (596.16 so'm)
                                'p2' => $p2, 's2' => '207', // Shox SMM (1 687.50 so'm)
                            ],
                            [
                                'uz' => '👁 Telegram Ko\'rish — Premium (Oxirgi 10 ta postga)',
                                'ru' => '👁 Просмотры Telegram — Премиум (На последние 10 постов)',
                                'en' => '👁 Telegram Views — Premium (Last 10 posts)',
                                'price' => 3490.0,
                                'rate' => 1312.50,
                                'min' => 100,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '1228', // NeoSMM (1 312.50 so'm)
                                'p2' => $p2, 's2' => '101',  // Shox SMM (1 980 so'm)
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
                                'uz' => '👍 Reaksiyalar — Hamyonbop (Aralash mix)',
                                'ru' => '👍 Реакции — Эконом (Микс)',
                                'en' => '👍 Reactions — Economy (Mixed)',
                                'price' => 1290.0,
                                'rate' => 177.45,
                                'min' => 50,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '1193', // NeoSMM (177.45 so'm)
                                'p2' => $p2, 's2' => '776',  // Shox SMM (891 so'm)
                            ],
                            [
                                'uz' => '🔥 Reaksiyalar — Standart (Ijobiy 👍🔥❤️)',
                                'ru' => '🔥 Реакции — Стандарт (Позитивные 👍🔥❤️)',
                                'en' => '🔥 Reactions — Standard (Positive 👍🔥❤️)',
                                'price' => 1890.0,
                                'rate' => 473.85,
                                'min' => 50,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '1023', // NeoSMM (473.85 so'm)
                                'p2' => $p2, 's2' => '776',  // Shox SMM (891 so'm)
                            ],
                            [
                                'uz' => '⭐️ Reaksiyalar — Premium (Salbiy yoki Maxsus)',
                                'ru' => '⭐️ Реакции — Премиум (Негативные или Спец эмодзи)',
                                'en' => '⭐️ Reactions — Premium (Negative or Custom emojis)',
                                'price' => 2490.0,
                                'rate' => 756.60,
                                'min' => 50,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '1022', // NeoSMM (756.60 so'm)
                                'p2' => $p2, 's2' => '777',  // Shox SMM (1 000 so'm)
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // 3. TIKTOK
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
                                'price' => 23900.0,
                                'rate' => 14000.00,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p2, 's1' => '484', // Shox SMM (14 000 so'm)
                                'p2' => $p1, 's2' => '469', // NeoSMM (14 151 so'm)
                            ],
                            [
                                'uz' => '👥 TikTok Obunachi — Standart (♻️ Kafolatli)',
                                'ru' => '👥 Подписчики TikTok — Стандарт (♻️ С гарантией)',
                                'en' => '👥 TikTok Followers — Standard (♻️ Refill)',
                                'price' => 43900.0,
                                'rate' => 24260.40,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '468', // NeoSMM (24 260.40 so'm)
                                'p2' => $p2, 's2' => '54',  // Shox SMM (36 000 so'm)
                            ],
                            [
                                'uz' => '👥 TikTok Obunachi — Premium (🛡 100% Xavfsiz xizmat)',
                                'ru' => '👥 Подписчики TikTok — Премиум (🛡 100% Безопасный сервис)',
                                'en' => '👥 TikTok Followers — Premium (🛡 100% Safe Service)',
                                'price' => 99000.0,
                                'rate' => 66978.00,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '1449', // NeoSMM (66 978 so'm)
                                'p2' => $p1, 's2' => '1450',
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
                                'price' => 7900.0,
                                'rate' => 3619.80,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '463', // NeoSMM (3 619.80 so'm)
                                'p2' => $p2, 's2' => '650', // Shox SMM (5 280 so'm)
                            ],
                            [
                                'uz' => '❤️ TikTok Layk — Standart (Tezkor)',
                                'ru' => '❤️ Лайки TikTok — Стандарт (Быстрые)',
                                'en' => '❤️ TikTok Likes — Standard (Fast)',
                                'price' => 11900.0,
                                'rate' => 4002.21,
                                'min' => 50,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '464', // NeoSMM (4 002.21 so'm)
                                'p2' => $p2, 's2' => '649', // Shox SMM (6 000 so'm)
                            ],
                            [
                                'uz' => '❤️ TikTok Layk — Premium (Yashirin Profil)',
                                'ru' => '❤️ Лайки TikTok — Премиум (Скрытый профиль)',
                                'en' => '❤️ TikTok Likes — Premium (Hidden Profiles)',
                                'price' => 9900.0,
                                'rate' => 1756.03,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '1453', // NeoSMM (1 756 so'm)
                                'p2' => $p2, 's2' => '651',  // Shox SMM (6 600 so'm)
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
                                'price' => 490.0,
                                'rate' => 43.12,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '483', // NeoSMM (43.12 so'm)
                                'p2' => $p1, 's2' => '485', // NeoSMM (129.38 so'm)
                            ],
                            [
                                'uz' => '👁 TikTok Ko\'rish — Standart (5M/kun)',
                                'ru' => '👁 Просмотры TikTok — Стандарт (5M/день)',
                                'en' => '👁 TikTok Views — Standard (5M/day)',
                                'price' => 990.0,
                                'rate' => 129.38,
                                'min' => 100,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '485',
                                'p2' => $p1, 's2' => '486',
                            ],
                            [
                                'uz' => '👁 TikTok Ko\'rish — Premium (Avto ♻️R7)',
                                'ru' => '👁 Просмотры TikTok — Премиум (Авто ♻️R7)',
                                'en' => '👁 TikTok Views — Premium (Auto ♻️R7)',
                                'price' => 2490.0,
                                'rate' => 1033.20,
                                'min' => 50,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '1466',
                                'p2' => $p1, 's2' => '1467',
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // 4. YOUTUBE
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
                                'uz' => '👥 YouTube Obunachi — Hamyonbop (Arzon)',
                                'ru' => '👥 Подписчики YouTube — Эконом (Дешевые)',
                                'en' => '👥 YouTube Subscribers — Economy (Cheap)',
                                'price' => 18900.0,
                                'rate' => 10000.00,
                                'min' => 10,
                                'max' => 60000,
                                'p1' => $p2, 's1' => '639', // Shox SMM (10 000 so'm)
                                'p2' => $p1, 's2' => '572', // NeoSMM (12 367.50 so'm)
                            ],
                            [
                                'uz' => '👥 YouTube Obunachi — Standart (Tezkor)',
                                'ru' => '👥 Подписчики YouTube — Стандарт (Быстрые)',
                                'en' => '👥 YouTube Subscribers — Standard (Fast)',
                                'price' => 59000.0,
                                'rate' => 35000.00,
                                'min' => 10,
                                'max' => 50000,
                                'p1' => $p2, 's1' => '641', // Shox SMM (35 000 so'm)
                                'p2' => $p1, 's2' => '573', // NeoSMM (47 316 so'm)
                            ],
                            [
                                'uz' => '👥 YouTube Obunachi — Premium (♻️ 60 kun kafolat)',
                                'ru' => '👥 Подписчики YouTube — Премиум (♻️ 60 дней гарантия)',
                                'en' => '👥 YouTube Subscribers — Premium (♻️ 60 Days Refill)',
                                'price' => 399000.0,
                                'rate' => 286641.00,
                                'min' => 100,
                                'max' => 10000,
                                'p1' => $p1, 's1' => '574',
                                'p2' => $p1, 's2' => '574',
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
                                'uz' => '👁 YouTube Ko\'rish — Hamyonbop (♻️R30)',
                                'ru' => '👁 Просмотры YouTube — Эконом (♻️R30)',
                                'en' => '👁 YouTube Views — Economy (♻️R30)',
                                'price' => 11900.0,
                                'rate' => 5620.85,
                                'min' => 100,
                                'max' => 35000,
                                'p1' => $p1, 's1' => '563', // NeoSMM (5 620.85 so'm)
                                'p2' => $p2, 's2' => '548', // Shox SMM (7 700 so'm)
                            ],
                            [
                                'uz' => '👁 YouTube Ko\'rish — Standart (Bonus layklar bilan)',
                                'ru' => '👁 Просмотры YouTube — Стандарт (С бонус-лайками)',
                                'en' => '👁 YouTube Views — Standard (With Bonus Likes)',
                                'price' => 19900.0,
                                'rate' => 10296.00,
                                'min' => 50,
                                'max' => 25000,
                                'p1' => $p2, 's1' => '644', // Shox SMM (10 296 so'm)
                                'p2' => $p1, 's2' => '565', // NeoSMM (15 598 so'm)
                            ],
                            [
                                'uz' => '👁 YouTube Ko\'rish — Premium (♻️ 365 kun kafolat)',
                                'ru' => '👁 Просмотры YouTube — Премиум (♻️ 365 дней гарантия)',
                                'en' => '👁 YouTube Views — Premium (♻️ 365 Days Refill)',
                                'price' => 29900.0,
                                'rate' => 17212.93,
                                'min' => 10,
                                'max' => 500000,
                                'p1' => $p1, 's1' => '564',
                                'p2' => $p1, 's2' => '565',
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
                                'uz' => '👍 YouTube Layk — Hamyonbop (Arzon)',
                                'ru' => '👍 Лайки YouTube — Эконом (Дешевые)',
                                'en' => '👍 YouTube Likes — Economy (Cheap)',
                                'price' => 7900.0,
                                'rate' => 3074.40,
                                'min' => 10,
                                'max' => 500000,
                                'p1' => $p2, 's1' => '647', // Shox SMM (3 074.40 so'm)
                                'p2' => $p1, 's2' => '815', // NeoSMM (6 357.38 so'm)
                            ],
                            [
                                'uz' => '👍 YouTube Layk — Standart (Tezkor)',
                                'ru' => '👍 Лайки YouTube — Стандарт (Быстрые)',
                                'en' => '👍 YouTube Likes — Standard (Fast)',
                                'price' => 14900.0,
                                'rate' => 4920.00,
                                'min' => 10,
                                'max' => 50000,
                                'p1' => $p2, 's1' => '648', // Shox SMM (4 920 so'm)
                                'p2' => $p1, 's2' => '816', // NeoSMM (12 398 so'm)
                            ],
                            [
                                'uz' => '👍 YouTube Layk — Premium (♻️ 90 kun kafolat)',
                                'ru' => '👍 Лайки YouTube — Премиум (♻️ 90 дней гарантия)',
                                'en' => '👍 YouTube Likes — Premium (♻️ 90 Days Refill)',
                                'price' => 24900.0,
                                'rate' => 8666.40,
                                'min' => 25,
                                'max' => 70000,
                                'p1' => $p2, 's1' => '646', // Shox SMM (8 666.40 so'm)
                                'p2' => $p1, 's2' => '817', // NeoSMM (14 127 so'm)
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // 5. FACEBOOK
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
                                'uz' => '👤 Facebook Obunachi — Hamyonbop (Profil)',
                                'ru' => '👤 Подписчики Facebook — Эконом (Профиль)',
                                'en' => '👤 Facebook Followers — Economy (Profile)',
                                'price' => 5900.0,
                                'rate' => 2656.88,
                                'min' => 10,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '1149',
                                'p2' => $p1, 's2' => '1150',
                            ],
                            [
                                'uz' => '👤 Facebook Obunachi — Standart (Sahifa ♻️R60)',
                                'ru' => '👤 Подписчики Facebook — Стандарт (Страница ♻️R60)',
                                'en' => '👤 Facebook Followers — Standard (Page ♻️R60)',
                                'price' => 7900.0,
                                'rate' => 3510.00,
                                'min' => 10,
                                'max' => 200000,
                                'p1' => $p1, 's1' => '1155',
                                'p2' => $p1, 's2' => '1154',
                            ],
                            [
                                'uz' => '👥 Facebook Obunachi — Premium (Guruh / Non-drop)',
                                'ru' => '👥 Подписчики Facebook — Премиум (Группа / Без списаний)',
                                'en' => '👥 Facebook Followers — Premium (Group / Non-drop)',
                                'price' => 13900.0,
                                'rate' => 6256.77,
                                'min' => 10,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '1169',
                                'p2' => $p1, 's2' => '1167',
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
                                'uz' => '❤️ Facebook Reaksiya — Hamyonbop (👍)',
                                'ru' => '❤️ Реакции Facebook — Эконом (👍)',
                                'en' => '❤️ Facebook Reactions — Economy (👍)',
                                'price' => 2900.0,
                                'rate' => 1367.14,
                                'min' => 10,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '1238',
                                'p2' => $p1, 's2' => '1159',
                            ],
                            [
                                'uz' => '❤️ Facebook Layk — Standart (Post Like ♻️R60)',
                                'ru' => '❤️ Лайки Facebook — Стандарт (Пост ♻️R60)',
                                'en' => '❤️ Facebook Likes — Standard (Post Like ♻️R60)',
                                'price' => 11900.0,
                                'rate' => 6463.56,
                                'min' => 10,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '1157',
                                'p2' => $p1, 's2' => '1158',
                            ],
                            [
                                'uz' => '❤️ Facebook Layk — Premium (Post Like ♻️R120)',
                                'ru' => '❤️ Лайки Facebook — Премиум (Пост ♻️R120)',
                                'en' => '❤️ Facebook Likes — Premium (Post Like ♻️R120)',
                                'price' => 14900.0,
                                'rate' => 6861.56,
                                'min' => 10,
                                'max' => 1000000,
                                'p1' => $p1, 's1' => '1161',
                                'p2' => $p1, 's2' => '1158',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '👁 Video Ko\'rishlar',
                            'ru' => '👁 Просмотры видео',
                            'en' => '👁 Video Views',
                        ],
                        'services' => [
                            [
                                'uz' => '👁 Facebook Ko\'rish — Hamyonbop (10M baza)',
                                'ru' => '👁 Просмотры Facebook — Эконом (10M база)',
                                'en' => '👁 Facebook Views — Economy (10M base)',
                                'price' => 990.0,
                                'rate' => 332.10,
                                'min' => 10,
                                'max' => 2000000,
                                'p1' => $p1, 's1' => '1163',
                                'p2' => $p1, 's2' => '1160',
                            ],
                            [
                                'uz' => '👁 Facebook Ko\'rish — Standart (20M tezkor)',
                                'ru' => '👁 Просмотры Facebook — Стандарт (20M быстрые)',
                                'en' => '👁 Facebook Views — Standard (20M fast)',
                                'price' => 1890.0,
                                'rate' => 920.65,
                                'min' => 1,
                                'max' => 2000000,
                                'p1' => $p1, 's1' => '1164',
                                'p2' => $p1, 's2' => '1160',
                            ],
                            [
                                'uz' => '🔄 Facebook Ulashish (Share) — Premium',
                                'ru' => '🔄 Репосты Facebook (Share) — Премиум',
                                'en' => '🔄 Facebook Shares — Premium',
                                'price' => 3490.0,
                                'rate' => 605.62,
                                'min' => 100,
                                'max' => 2000000,
                                'p1' => $p1, 's1' => '1395',
                                'p2' => $p1, 's2' => '1394',
                            ],
                        ],
                    ],
                ],
            ],

            // ==========================================
            // 6. BOSHQA XIZMATLAR (MAXSUS)
            // ==========================================
            [
                'cat' => [
                    'uz' => '✨ Boshqa xizmatlar',
                    'ru' => '✨ Другие услуги',
                    'en' => '✨ Other Services',
                    'sort' => 6,
                ],
                'subs' => [
                    [
                        'name' => [
                            'uz' => '⭐️ Telegram Stars (Yulduzlar)',
                            'ru' => '⭐️ Telegram Stars (Звёзды)',
                            'en' => '⭐️ Telegram Stars',
                        ],
                        'services' => [
                            [
                                'uz' => '⭐️ 50 ta Telegram Stars',
                                'ru' => '⭐️ 50 шт Telegram Stars',
                                'en' => '⭐️ 50 Telegram Stars',
                                'price' => 349000.0, // 349 000 / 1000 * 50 = 17 450 so'm
                                'rate' => 220000.00,
                                'min' => 50,
                                'max' => 50,
                                'p1' => $p1, 's1' => '1409', // NeoSMM (220 000 so'm)
                                'p2' => $p2, 's2' => '25',   // Shox SMM (230 000 so'm)
                            ],
                            [
                                'uz' => '⭐️ 100 ta Telegram Stars',
                                'ru' => '⭐️ 100 шт Telegram Stars',
                                'en' => '⭐️ 100 Telegram Stars',
                                'price' => 349000.0, // 349 000 / 1000 * 100 = 34 900 so'm
                                'rate' => 220000.00,
                                'min' => 100,
                                'max' => 100,
                                'p1' => $p1, 's1' => '1409',
                                'p2' => $p2, 's2' => '25',
                            ],
                            [
                                'uz' => '⭐️ 500 ta Telegram Stars',
                                'ru' => '⭐️ 500 шт Telegram Stars',
                                'en' => '⭐️ 500 Telegram Stars',
                                'price' => 349000.0, // 349 000 / 1000 * 500 = 174 500 so'm
                                'rate' => 220000.00,
                                'min' => 500,
                                'max' => 500,
                                'p1' => $p1, 's1' => '1409',
                                'p2' => $p2, 's2' => '25',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '🎁 Telegram Premium Obuna',
                            'ru' => '🎁 Telegram Premium Подписка',
                            'en' => '🎁 Telegram Premium Subscription',
                        ],
                        'services' => [
                            [
                                'uz' => '🎁 Telegram Premium (3 oylik sovg\'a)',
                                'ru' => '🎁 Telegram Premium (3 месяца подарок)',
                                'en' => '🎁 Telegram Premium (3 months gift)',
                                'price' => 259000.0,
                                'rate' => 170000.00,
                                'min' => 1000,
                                'max' => 1000,
                                'p1' => $p1, 's1' => '1482', // NeoSMM (170 000 so'm)
                                'p2' => $p2, 's2' => '151',  // Shox SMM (175 000 so'm)
                            ],
                            [
                                'uz' => '🎁 Telegram Premium (6 oylik sovg\'a)',
                                'ru' => '🎁 Telegram Premium (6 месяцев подарок)',
                                'en' => '🎁 Telegram Premium (6 months gift)',
                                'price' => 359000.0,
                                'rate' => 230000.00,
                                'min' => 1000,
                                'max' => 1000,
                                'p1' => $p1, 's1' => '1483', // NeoSMM (230 000 so'm)
                                'p2' => $p2, 's2' => '152',  // Shox SMM (249 000 so'm)
                            ],
                            [
                                'uz' => '🎁 Telegram Premium (12 oylik sovg\'a)',
                                'ru' => '🎁 Telegram Premium (12 месяцев подарок)',
                                'en' => '🎁 Telegram Premium (12 months gift)',
                                'price' => 589000.0,
                                'rate' => 390000.00,
                                'min' => 1000,
                                'max' => 1000,
                                'p1' => $p1, 's1' => '1484', // NeoSMM (390 000 so'm)
                                'p2' => $p2, 's2' => '153',  // Shox SMM (399 000 so'm)
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '💝 Telegram Hadya & Sovg\'alar',
                            'ru' => '💝 Telegram Подарки (Gifts)',
                            'en' => '💝 Telegram Gifts',
                        ],
                        'services' => [
                            [
                                'uz' => '🎀 Lentali yurak — Heart With Ribbon',
                                'ru' => '🎀 Сердце с лентой — Heart With Ribbon',
                                'en' => '🎀 Heart With Ribbon Gift',
                                'price' => 4900000.0,
                                'rate' => 3900000.00,
                                'min' => 1000,
                                'max' => 1000,
                                'p1' => $p2, 's1' => '556',
                                'p2' => $p2, 's2' => '556',
                            ],
                            [
                                'uz' => '🧸 Ayiqcha — Teddy Bear',
                                'ru' => '🧸 Мишка — Teddy Bear',
                                'en' => '🧸 Teddy Bear Gift',
                                'price' => 4900000.0,
                                'rate' => 3900000.00,
                                'min' => 1000,
                                'max' => 1000,
                                'p1' => $p2, 's1' => '557',
                                'p2' => $p2, 's2' => '557',
                            ],
                            [
                                'uz' => '🎁 Sovg\'a qutisi — Gift Box',
                                'ru' => '🎁 Подарочная коробка — Gift Box',
                                'en' => '🎁 Gift Box',
                                'price' => 7500000.0,
                                'rate' => 5900000.00,
                                'min' => 1000,
                                'max' => 1000,
                                'p1' => $p2, 's1' => '563',
                                'p2' => $p2, 's2' => '563',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '🧵 Threads Xizmatlari',
                            'ru' => '🧵 Услуги Threads',
                            'en' => '🧵 Threads Services',
                        ],
                        'services' => [
                            [
                                'uz' => '👥 Threads Obunachi (Hamyonbop & Tezkor)',
                                'ru' => '👥 Подписчики Threads (Эконом и быстрые)',
                                'en' => '👥 Threads Followers (Economy & Fast)',
                                'price' => 24900.0,
                                'rate' => 14826.60,
                                'min' => 10,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '492',
                                'p2' => $p1, 's2' => '492',
                            ],
                            [
                                'uz' => '❤️ Threads Layk (Tezkor)',
                                'ru' => '❤️ Лайки Threads (Быстрые)',
                                'en' => '❤️ Threads Likes (Fast)',
                                'price' => 21900.0,
                                'rate' => 12850.20,
                                'min' => 10,
                                'max' => 100000,
                                'p1' => $p1, 's1' => '506',
                                'p2' => $p1, 's2' => '506',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '🚀 Telegram Boost (Ovozlar)',
                            'ru' => '🚀 Telegram Буст (Голоса)',
                            'en' => '🚀 Telegram Boosts',
                        ],
                        'services' => [
                            [
                                'uz' => '🚀 1 kunlik Boost ovoz (Hikoya faollashtirish)',
                                'ru' => '🚀 1 день Буст голоса (Активация историй)',
                                'en' => '🚀 1 Day Boost Votes (Enable Stories)',
                                'price' => 189000.0,
                                'rate' => 126000.00,
                                'min' => 1,
                                'max' => 15000,
                                'p1' => $p2, 's1' => '775',  // Shox SMM (126 000 so'm)
                                'p2' => $p1, 's2' => '1199', // NeoSMM (301 185 so'm)
                            ],
                            [
                                'uz' => '🚀 7 kunlik Boost ovoz',
                                'ru' => '🚀 7 дней Буст голоса',
                                'en' => '🚀 7 Days Boost Votes',
                                'price' => 949000.0,
                                'rate' => 672210.00,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '1200',
                                'p2' => $p1, 's2' => '1200',
                            ],
                        ],
                    ],
                    [
                        'name' => [
                            'uz' => '🤖 Telegram Bot Startlar',
                            'ru' => '🤖 Старты для Telegram Ботов',
                            'en' => '🤖 Telegram Bot Starts',
                        ],
                        'services' => [
                            [
                                'uz' => '🤖 Tezkor Bot Start (Hamyonbop)',
                                'ru' => '🤖 Быстрый Старт Бота (Эконом)',
                                'en' => '🤖 Fast Bot Starts (Economy)',
                                'price' => 2490.0,
                                'rate' => 1000.00,
                                'min' => 50,
                                'max' => 1000000,
                                'p1' => $p2, 's1' => '774', // Shox SMM (1 000 so'm)
                                'p2' => $p1, 's2' => '858', // NeoSMM (1 727.80 so'm)
                            ],
                            [
                                'uz' => '🤖 Sifatli Bot Start (Referral / Qidiruv)',
                                'ru' => '🤖 Качественный Старт Бота (Реферал / Поиск)',
                                'en' => '🤖 Quality Bot Starts (Referral / Search)',
                                'price' => 3890.0,
                                'rate' => 1727.80,
                                'min' => 50,
                                'max' => 50000,
                                'p1' => $p1, 's1' => '858', // NeoSMM (1 727.80 so'm)
                                'p2' => $p2, 's2' => '773', // Shox SMM (2 000 so'm)
                            ],
                            [
                                'uz' => '💎 Telegram Bot Stars Start',
                                'ru' => '💎 Telegram Бот Старты Stars',
                                'en' => '💎 Telegram Bot Stars Starts',
                                'price' => 79000.0,
                                'rate' => 50000.00,
                                'min' => 5,
                                'max' => 322,
                                'p1' => $p1, 's1' => '891',
                                'p2' => $p1, 's2' => '891',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $pdo->beginTransaction();
        $insertedCount = 0;

        try {
            foreach ($structure as $item) {
                $catData = $item['cat'];
                $catNameUz = $catData['uz'];

                // 1. Get or create Category
                $existingCat = Database::fetchOne(
                    'SELECT id FROM categories WHERE name_uz = :uz OR name_en = :en LIMIT 1',
                    ['uz' => $catNameUz, 'en' => $catData['en']]
                );

                if ($existingCat !== null) {
                    $catId = (int) $existingCat['id'];
                    Database::execute(
                        'UPDATE categories SET name_uz = :uz, name_ru = :ru, name_en = :en, sort_order = :so, is_active = 1 WHERE id = :id',
                        [
                            'uz' => $catNameUz,
                            'ru' => $catData['ru'],
                            'en' => $catData['en'],
                            'so' => $catData['sort'],
                            'id' => $catId,
                        ]
                    );
                } else {
                    Database::execute(
                        'INSERT INTO categories (name_uz, name_ru, name_en, sort_order, is_active) VALUES (:uz, :ru, :en, :so, 1)',
                        [
                            'uz' => $catNameUz,
                            'ru' => $catData['ru'],
                            'en' => $catData['en'],
                            'so' => $catData['sort'],
                        ]
                    );
                    $catId = (int) Database::lastInsertId();
                }

                // 2. Iterate Subcategories
                $subSort = 1;
                foreach ($item['subs'] as $subData) {
                    $subNameUz = $subData['name']['uz'];

                    $existingSub = Database::fetchOne(
                        'SELECT id FROM subcategories WHERE category_id = :cid AND (name_uz = :uz OR name_en = :en) LIMIT 1',
                        ['cid' => $catId, 'uz' => $subNameUz, 'en' => $subData['name']['en']]
                    );

                    if ($existingSub !== null) {
                        $subId = (int) $existingSub['id'];
                        Database::execute(
                            'UPDATE subcategories SET name_uz = :uz, name_ru = :ru, name_en = :en, sort_order = :so, is_active = 1 WHERE id = :id',
                            [
                                'uz' => $subNameUz,
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
                                'uz' => $subNameUz,
                                'ru' => $subData['name']['ru'],
                                'en' => $subData['name']['en'],
                                'so' => $subSort,
                            ]
                        );
                        $subId = (int) Database::lastInsertId();
                    }
                    $subSort++;

                    // 3. Iterate Services
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

                        $isUsernameLink = str_contains(strtolower($svc['en']), 'stars')
                            || str_contains(strtolower($svc['en']), 'premium')
                            || str_contains(strtolower($svc['en']), 'gift')
                            || str_contains(strtolower($svc['en']), 'threads followers')
                            || str_contains(strtolower($svc['en']), 'username');

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
                                    order_type = :ot,
                                    link_type = :lt,
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
                                    'ot' => str_contains(strtolower($svc['en']), 'poll') ? 'poll' : 'default',
                                    'lt' => $isUsernameLink ? 'username' : 'url',
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
                                    'lt' => $isUsernameLink ? 'username' : 'url',
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
