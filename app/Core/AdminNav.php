<?php

namespace App\Core;

/**
 * Адмін-панель складається з двох окремих розділів зі своїми адресами та вкладками:
 *
 *   «Керування»            (/admin/manage)   — проєкти, задачі й тікети: усе, що стосується самої роботи;
 *   «Налаштування системи» (/admin/settings) — користувачі й доступ, інтеграції, контроль.
 *
 * Тут єдине місце, де записано, яка адмін-сторінка до якого розділу належить і які картки показує кожен
 * розділ: від цього залежать і вкладки, і виділення пункту меню, і посилання «назад» на підсторінках.
 * Нова адмін-сторінка потрапляє в розділ додаванням її адреси до prefixes відповідного розділу (і картки в groups).
 */
class AdminNav
{
    public const SECTIONS = [
        'manage' => [
            'title' => 'Керування',
            'heading' => 'Керування проєктами, задачами й тікетами',
            'lead' => 'Усе, що стосується самої роботи: проєкти, задачі всіх проєктів, черги й якість обслуговування тікетів.',
            'url' => '/admin/manage',
            // Адреса належить розділу, якщо збігається з префіксом або починається з «префікс/» (тож /admin/ad не «захоплює» /admin/audit).
            'prefixes' => ['/admin/manage', '/admin/projects', '/admin/board', '/admin/gantt', '/admin/time', '/admin/queues', '/admin/csat'],
        ],
        'settings' => [
            'title' => 'Налаштування системи',
            'heading' => 'Налаштування системи',
            'lead' => 'Облікові записи та доступ, підключення до Active Directory і пошти, контроль дій у системі.',
            'url' => '/admin/settings',
            'prefixes' => ['/admin/settings', '/admin/users', '/admin/security', '/admin/ad', '/admin/email', '/admin/audit', '/admin/api', '/admin/webhooks'],
        ],
    ];

    /** Розділ за шляхом запиту ('/admin/users/5/edit' → 'settings'); null — це не адмін-сторінка розділів. */
    public static function sectionForPath(string $path): ?string
    {
        $path = rtrim($path, '/') ?: '/';
        foreach (self::SECTIONS as $key => $section) {
            foreach ($section['prefixes'] as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** Розділ поточного запиту. */
    public static function currentSection(): ?string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return is_string($path) ? self::sectionForPath($path) : null;
    }

    /**
     * Картки розділу, згруповані за змістом.
     *
     * @return array<int, array{title: string, cards: array<int, array{title: string, text: string, url: string, button: string, style: string}>}>
     */
    public static function groups(string $section): array
    {
        $card = static fn(string $title, string $text, string $url, string $button, string $style = 'btn-outline-secondary'): array
            => ['title' => $title, 'text' => $text, 'url' => $url, 'button' => $button, 'style' => $style];

        if ($section === 'manage') {
            return [
                ['title' => 'Проєкти', 'cards' => [
                    $card('Проєкти', 'Перегляд усіх проєктів, зміна видимості й статусу, незворотне видалення проєкту разом з усіма задачами.', '/admin/projects', 'Керувати проєктами', 'btn-outline-danger'),
                ]],
                ['title' => 'Задачі (усі проєкти одразу)', 'cards' => [
                    $card('📋 Канбан — усі проєкти', 'Загальна канбан-дошка з задачами всіх проєктів одразу, згрупована по статусах.', '/admin/board', 'Відкрити дошку'),
                    $card('📊 Гант — усі проєкти', 'Загальна діаграма Ганта з термінами задач усіх проєктів на одній шкалі.', '/admin/gantt', 'Відкрити діаграму'),
                    $card('⏱️ Облік часу — усі проєкти', 'Загальна сума годин, розбивка по проєктах і учасниках, повний список записів по всій системі.', '/admin/time', 'Відкрити звіт'),
                ]],
                ['title' => 'Тікети', 'cards' => [
                    $card('Черги тікетів', 'Створення черг для розподілу звернень, SLA-нормативи й автопризначення оператора.', '/admin/queues', 'Керувати чергами', 'btn-outline-primary'),
                    $card('⭐ CSAT — якість обслуговування', 'Середня оцінка заявників, розбивка по чергах і операторах, останні оцінки.', '/admin/csat', 'Переглянути звіт'),
                ]],
            ];
        }

        return [
            ['title' => 'Користувачі й доступ', 'cards' => [
                $card('Користувачі', 'Локальні й AD-облікові записи: паролі, ролі, активація/деактивація, зняття блокування.', '/admin/users', 'Керувати користувачами', 'btn-outline-primary'),
                $card('🔒 Безпека входу', 'Кількість невдалих спроб пароля до блокування облікового запису та тривалість блокування.', '/admin/security', 'Налаштувати'),
                $card('🗂️ Active Directory', 'Підключення до AD, синхронізація користувачів, відповідність груп ролям.', '/admin/ad', 'Налаштування'),
            ]],
            ['title' => 'Інтеграції', 'cards' => [
                $card('🔌 REST API', 'Токени доступу для програм і скриптів: вмикання API, ліміт запитів, створення й відкликання токенів. Права токена — ті самі, що й у його власника.', '/admin/api', 'Керувати API'),
                $card('🪝 Вебхуки', 'Надсилання подій (новий тікет, зміна статусу, коментар…) на зовнішні адреси з підписом HMAC, повтори й журнал доставок.', '/admin/webhooks', 'Керувати вебхуками'),
                $card('📧 Пошта → тікети', 'Автоматичне створення тікетів із листів у поштовій скриньці підтримки: підключення, черга, журнал обробки; автовідповіді й сповіщення.', '/admin/email', 'Відкрити'),
            ]],
            ['title' => 'Контроль', 'cards' => [
                $card('📜 Журнал аудиту', 'Хто і що змінював у проєктах, задачах, тікетах і вікі — з фільтрами за типом, автором і датою.', '/admin/audit', 'Переглянути журнал'),
            ]],
        ];
    }
}
