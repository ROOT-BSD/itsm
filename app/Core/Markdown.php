<?php

namespace App\Core;

/**
 * Невеликий безпечний рендерер Markdown (підмножина GFM) для сторінок вікі. Без залежностей.
 *
 * Підтримує: заголовки (# … ######), абзаци, **жирний**, *курсив*, ~~закреслений~~, `код` у рядку,
 * блоки коду (``` і ~~~), цитати (>), горизонтальні лінії, маркіровані й нумеровані списки з довільною
 * вкладеністю (у пунктах можуть бути абзаци, вкладені списки та блоки коду), таблиці (GFM, з
 * вирівнюванням), посилання [текст](url), автопосилання <https://…>, посилання між сторінками вікі
 * [[slug]] / [[slug|текст]], жорсткі переноси (два пробіли чи \ в кінці рядка), екранування (\*).
 *
 * Чого свідомо НЕМАЄ: сирого HTML (будь-яка розмітка в тексті екранується й показується буквально),
 * зображень (![](…) показується як текст), setext-заголовків (підкреслення ===/---).
 *
 * Безпека — головна вимога, бо результат виводиться на сторінку без подальшого екранування:
 *  - увесь текст проходить htmlspecialchars; HTML утворюють лише самі конструкції цього класу;
 *  - URL посилань — за білим списком (http, https, mailto, /відносні, #якорі, wiki:slug); усе інше
 *    (javascript:, data:, vbscript: тощо) не стає посиланням, лишається текстом;
 *  - символи з приватної області Unicode, якими позначаються проміжні вставки, вилучаються з вхідного
 *    тексту, тож їх неможливо підробити;
 *  - глибина вкладеності обмежена; збій регулярного виразу (ліміт PCRE) дає екранований текст, а не помилку.
 */
class Markdown
{
    private const MAX_DEPTH = 12;
    private const TOKEN_OPEN = "\u{E000}";
    private const TOKEN_CLOSE = "\u{E001}";

    /** @var array<int, string> проміжні вставки вже готового безпечного HTML (код, посилання) */
    private array $tokens = [];
    /** @var array<string, true> */
    private array $usedIds = [];
    /** @var array<int, array{level: int, id: string, text: string}> */
    private array $headings = [];
    /** @var array<string, true> існуючі slug-и сторінок вікі (для [[посилань]]) */
    private array $existingSlugs;

    /** @param string[] $existingSlugs */
    public function __construct(array $existingSlugs = [])
    {
        $this->existingSlugs = array_fill_keys($existingSlugs, true);
    }

    /** Заголовки останнього відрендереного документа — для змісту. @return array<int, array{level: int, id: string, text: string}> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function toHtml(string $markdown): string
    {
        $this->tokens = [];
        $this->usedIds = [];
        $this->headings = [];

        $text = mb_scrub($markdown, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        // Символи, якими ми позначаємо вставки, у вхідному тексті заборонені — інакше їх можна було б підробити.
        $text = preg_replace('/[\x{E000}-\x{F8FF}]/u', "\u{FFFD}", $text) ?? $text;
        $text = str_replace("\t", '    ', $text);

        return $this->blocks(explode("\n", $text), 0, false);
    }

    /** Текст без розмітки — для фрагментів у результатах пошуку. */
    public static function plainText(string $markdown): string
    {
        $t = preg_replace('/```[^\n]*\n(.*?)```/su', '$1', $markdown) ?? $markdown;
        $t = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $t) ?? $t;
        $t = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', static fn(array $m): string => $m[2] ?? $m[1], $t) ?? $t;
        $t = preg_replace('/^[ ]{0,3}(#{1,6}|>+|[-*+]|\d+[.)])[ ]+/mu', '', $t) ?? $t;
        $t = preg_replace('/^\s*\|?[\s:|-]+\|?\s*$/mu', '', $t) ?? $t;
        $t = str_replace(['|', '**', '__', '~~', '`', '*'], ' ', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    // ------------------------------------------------------------------ блоки

    /**
     * @param string[] $lines
     * @param bool $tight true — абзаци безпосередньо в цьому контейнері виводяться без <p> (щільні списки)
     */
    private function blocks(array $lines, int $depth, bool $tight): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '<p>' . $this->esc(implode("\n", $lines)) . "</p>\n";
        }

        $out = '';
        $n = count($lines);
        $i = 0;

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Блок коду з огорожею
            if (preg_match('/^( {0,3})(`{3,}|~{3,})[ ]*([^\s`]*)[^`]*$/u', $line, $m)) {
                $fence = $m[2];
                $indent = strlen($m[1]);
                $lang = preg_match('/^[A-Za-z0-9_+#.-]{1,20}$/', $m[3]) ? $m[3] : '';
                $closeRe = '/^ {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ ]*$/';
                $code = [];
                $i++;
                while ($i < $n) {
                    if (preg_match($closeRe, $lines[$i])) {
                        $i++;
                        break;
                    }
                    $code[] = preg_replace('/^ {0,' . $indent . '}/', '', $lines[$i]);
                    $i++;
                }
                $out .= '<pre class="wiki-code"><code' . ($lang !== '' ? ' class="language-' . $lang . '"' : '') . '>'
                    . $this->esc(implode("\n", $code)) . "</code></pre>\n";
                continue;
            }

            // Заголовок
            if (preg_match('/^ {0,3}(#{1,6})[ ]+(.+?)[ ]*$/u', $line, $m)) {
                $text = trim((string) preg_replace('/[ ]+#+[ ]*$/u', '', $m[2]));
                if ($text !== '') {
                    $out .= $this->heading(strlen($m[1]), $text);
                    $i++;
                    continue;
                }
            }

            // Горизонтальна лінія (перевіряється ДО списків: «* * *» і «- - -» схожі на пункти)
            if (preg_match('/^ {0,3}([-*_])(?:[ ]*\1){2,}[ ]*$/', $line)) {
                $out .= "<hr>\n";
                $i++;
                continue;
            }

            // Цитата
            if (preg_match('/^ {0,3}>/', $line)) {
                $quote = [];
                while ($i < $n && preg_match('/^ {0,3}>[ ]?(.*)$/', $lines[$i], $q)) {
                    $quote[] = $q[1];
                    $i++;
                }
                $out .= '<blockquote class="wiki-quote">' . "\n" . $this->blocks($quote, $depth + 1, false) . "</blockquote>\n";
                continue;
            }

            // Таблиця
            if (str_contains($line, '|') && $i + 1 < $n && self::isTableSeparator($lines[$i + 1])) {
                $table = $this->table($lines, $i);
                if ($table !== null) {
                    $out .= $table;
                    continue;
                }
            }

            // Список
            if (self::listMarker($line) !== null) {
                $out .= $this->parseList($lines, $i, $depth);
                continue;
            }

            // Абзац: збираємо рядки до порожнього або до початку іншого блоку
            $para = [$line];
            $i++;
            while ($i < $n && trim($lines[$i]) !== '' && !$this->startsBlock($lines, $i)) {
                $para[] = $lines[$i];
                $i++;
            }
            $html = $this->inline(implode("\n", array_map(static fn(string $l): string => ltrim($l, ' '), $para)));
            $out .= $tight ? $html . "\n" : '<p>' . $html . "</p>\n";
        }

        return $out;
    }

    /** Чи починає рядок $i блок, що може перервати абзац. */
    private function startsBlock(array $lines, int $i): bool
    {
        $line = $lines[$i];
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line)
            || preg_match('/^ {0,3}#{1,6}[ ]+\S/', $line)
            || preg_match('/^ {0,3}([-*_])(?:[ ]*\1){2,}[ ]*$/', $line)
            || preg_match('/^ {0,3}>/', $line)) {
            return true;
        }
        $marker = self::listMarker($line);
        if ($marker !== null && $marker['content'] !== '') {
            // нумерований список може перервати абзац лише з «1.» (інакше «2024. рік» ставав би списком)
            return !$marker['ordered'] || $marker['number'] === 1;
        }
        return str_contains($line, '|') && $i + 1 < count($lines) && self::isTableSeparator($lines[$i + 1]);
    }

    /**
     * Розбирає рядок як початок пункту списку.
     *
     * @return array{indent: int, ordered: bool, number: int, marker: string, content: string, offset: int}|null
     *         offset — на скільки пробілів має бути зміщений вміст, щоб належати цьому пункту
     */
    private static function listMarker(string $line): ?array
    {
        if (!preg_match('/^( {0,3})([-*+]|\d{1,9}[.)])( *)(.*)$/u', $line, $m)) {
            return null;
        }
        $spaces = strlen($m[3]);
        $content = $m[4];
        if ($content !== '' && $spaces === 0) {
            return null; // «*текст*», «-5», «2024.рік»: після маркера обов'язковий пробіл
        }
        // 1–4 пробіли після маркера входять у відступ вмісту; 5 і більше — це код усередині пункту
        $gap = ($content === '' || $spaces > 4) ? 1 : $spaces;
        if ($spaces > 4) {
            $content = str_repeat(' ', $spaces - 1) . $content;
        }
        $ordered = ctype_digit($m[2][0]);
        return [
            'indent' => strlen($m[1]),
            'ordered' => $ordered,
            'number' => $ordered ? (int) $m[2] : 0,
            'marker' => $ordered ? substr($m[2], -1) : $m[2],
            'content' => $content,
            'offset' => strlen($m[1]) + strlen($m[2]) + $gap,
        ];
    }

    private function parseList(array $lines, int &$i, int $depth): string
    {
        $n = count($lines);
        $first = self::listMarker($lines[$i]);
        $ordered = $first['ordered'];
        $startNumber = $first['number'];
        $marker = $first['marker'];
        $items = [];
        $loose = false;

        while ($i < $n) {
            $m = self::listMarker($lines[$i]);
            // Інший тип/маркер (ordered↔bullet, «-»↔«*», «.»↔«)») починає НОВИЙ список; надто глибокий відступ — не пункт.
            if ($m === null || $m['ordered'] !== $ordered || $m['marker'] !== $marker || $m['indent'] > $first['indent'] + 3) {
                break;
            }
            $offset = $m['offset'];
            $itemLines = [$m['content']];
            $i++;
            $blankRun = 0;
            $inFence = (bool) preg_match('/^ {0,3}(`{3,}|~{3,})/', $m['content']);

            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    $blankRun++;
                    $i++;
                    continue;
                }
                $indent = strlen($l) - strlen(ltrim($l, ' '));
                if ($indent >= $offset) {
                    for ($k = 0; $k < $blankRun; $k++) {
                        $itemLines[] = '';
                    }
                    $blankRun = 0;
                    $inner = substr($l, $offset);
                    if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $inner)) {
                        $inFence = !$inFence;
                    }
                    $itemLines[] = $inner;
                    $i++;
                    continue;
                }
                // Менший відступ: «ледача» (lazy) тяглість абзацу — лише без порожнього рядка, не в коді й не новий блок.
                if ($blankRun === 0 && !$inFence && !$this->startsBlock($lines, $i) && self::listMarker($l) === null) {
                    $itemLines[] = ltrim($l, ' ');
                    $i++;
                    continue;
                }
                break;
            }

            // Порожній рядок МІЖ пунктами одного списку робить його «вільним» (абзаци в <p>).
            if ($blankRun > 0 && $i < $n) {
                $next = self::listMarker($lines[$i]);
                if ($next !== null && $next['ordered'] === $ordered && $next['marker'] === $marker && $next['indent'] <= $first['indent'] + 3) {
                    $loose = true;
                }
            }
            // …або порожній рядок усередині пункту між двома блоками (поза кодом).
            if (!$loose) {
                $fence = false;
                $sawBlank = false;
                foreach ($itemLines as $idx => $il) {
                    if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $il)) {
                        $fence = !$fence;
                    }
                    if (!$fence && trim($il) === '' && $idx > 0 && $idx < count($itemLines) - 1) {
                        $sawBlank = true;
                    }
                }
                if ($sawBlank) {
                    $loose = true;
                }
            }
            $items[] = $itemLines;
        }

        $tag = $ordered ? 'ol' : 'ul';
        $startAttr = ($ordered && $startNumber !== 1) ? ' start="' . $startNumber . '"' : '';
        $html = "<{$tag}{$startAttr}>\n";
        foreach ($items as $itemLines) {
            $html .= '<li>' . trim($this->blocks($itemLines, $depth + 1, !$loose), "\n") . "</li>\n";
        }
        return $html . "</{$tag}>\n";
    }

    private static function isTableSeparator(string $line): bool
    {
        return (bool) preg_match('/^[ ]{0,3}\|?[ ]*:?-{1,}:?[ ]*(\|[ ]*:?-{1,}:?[ ]*)*\|?[ ]*$/', $line)
            && str_contains($line, '-')
            && (str_contains($line, '|') || str_contains($line, ':'));
    }

    /** @return string[] клітинки рядка таблиці (без крайніх «|»; «\|» усередині клітинки — буквальна «|») */
    private static function splitRow(string $row): array
    {
        $row = trim($row);
        if (str_starts_with($row, '|')) {
            $row = substr($row, 1);
        }
        if (str_ends_with($row, '|') && !str_ends_with($row, '\\|')) {
            $row = substr($row, 0, -1);
        }
        $cells = preg_split('/(?<!\\\\)\|/', $row) ?: [];
        return array_map(static fn(string $c): string => trim(str_replace('\\|', '|', $c)), $cells);
    }

    private function table(array $lines, int &$i): ?string
    {
        $head = self::splitRow($lines[$i]);
        $sep = self::splitRow($lines[$i + 1]);
        if (count($head) !== count($sep)) {
            return null; // не таблиця — звичайний абзац
        }
        $align = array_map(static function (string $c): string {
            $left = str_starts_with($c, ':');
            $right = str_ends_with($c, ':');
            return $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : ''));
        }, $sep);

        $cols = count($head);
        $cell = function (string $tag, string $content, string $a): string {
            return "<{$tag}" . ($a !== '' ? ' class="text-' . ($a === 'center' ? 'center' : ($a === 'right' ? 'end' : 'start')) . '"' : '') . '>'
                . $this->inline($content) . "</{$tag}>";
        };

        $html = '<div class="table-responsive"><table class="table table-bordered table-sm wiki-table">' . "\n<thead><tr>";
        foreach ($head as $k => $c) {
            $html .= $cell('th', $c, $align[$k]);
        }
        $html .= "</tr></thead>\n<tbody>\n";

        $i += 2;
        $n = count($lines);
        while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
            $cells = self::splitRow($lines[$i]);
            $html .= '<tr>';
            for ($k = 0; $k < $cols; $k++) {
                $html .= $cell('td', $cells[$k] ?? '', $align[$k]);
            }
            $html .= "</tr>\n";
            $i++;
        }
        return $html . "</tbody></table></div>\n";
    }

    private function heading(int $level, string $text): string
    {
        $plain = trim(self::stripInline($text));
        $id = $this->headingId($plain);
        $this->headings[] = ['level' => $level, 'id' => $id, 'text' => $plain];
        return "<h{$level} id=\"{$id}\">" . $this->inline($text) . "</h{$level}>\n";
    }

    /**
     * Текст заголовка без рядкової розмітки (для змісту й якорів). Окрема від plainText(): та призначена для
     * фрагментів пошуку й відрізає початок рядка, схожий на маркер списку — «1. Вхід» втратив би номер розділу.
     */
    private static function stripInline(string $text): string
    {
        $t = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $t = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', static fn(array $m): string => $m[2] ?? $m[1], $t) ?? $t;
        $t = preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $t) ?? $t;
        $t = str_replace(['**', '__', '~~', '`', '*'], '', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    private function headingId(string $plain): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($plain)), '-');
        $base = $base !== '' ? mb_substr($base, 0, 80) : 'section';
        $id = $base;
        for ($n = 2; isset($this->usedIds[$id]); $n++) {
            $id = $base . '-' . $n;
        }
        $this->usedIds[$id] = true;
        return $id;
    }

    // ------------------------------------------------------------------ рядкові конструкції

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function token(string $html): string
    {
        $this->tokens[] = $html;
        return self::TOKEN_OPEN . (count($this->tokens) - 1) . self::TOKEN_CLOSE;
    }

    /** Дозволені посилання; null — небезпечне/невідоме (стане звичайним текстом). */
    public static function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7F<>"\\\\]/', $url)) {
            return null;
        }
        if (preg_match('~^https?://[^/?#\s]+~i', $url) || preg_match('/^mailto:[^\s@]+@[^\s@]+$/i', $url)) {
            return $url;
        }
        if (preg_match('/^wiki:([a-z0-9][a-z0-9-]*)$/', $url, $m)) {
            return '/wiki/' . $m[1];
        }
        if (preg_match('~^#[\p{L}\p{N}_%.-]+$~u', $url) || preg_match('~^/(?!/)[^\s]*$~', $url)) {
            return $url;
        }
        return null;
    }

    private function linkHtml(string $url, string $innerHtml, ?string $title = null): string
    {
        $external = preg_match('~^https?://~i', $url) === 1;
        return '<a href="' . $this->esc($url) . '"'
            . ($title !== null && $title !== '' ? ' title="' . $this->esc($title) . '"' : '')
            . ($external ? ' target="_blank" rel="noopener noreferrer"' : '')
            . '>' . $innerHtml . '</a>';
    }

    private function inline(string $text, bool $allowLinks = true): string
    {
        // 1. Код у рядку — найвищий пріоритет: усередині нього жодна розмітка не діє.
        $text = preg_replace_callback('/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/us', function (array $m): string {
            $code = $m[2];
            if (strlen($code) > 1 && $code[0] === ' ' && substr($code, -1) === ' ' && trim($code) !== '') {
                $code = substr($code, 1, -1);
            }
            return $this->token('<code>' . $this->esc($code) . '</code>');
        }, $text) ?? $this->esc($text);

        // 2. Екранування \* \_ \[ …
        $text = preg_replace_callback('/\\\\([!-\/:-@\[-`{-~])/', fn(array $m): string => $this->token($this->esc($m[1])), $text) ?? $text;

        // 3. Посилання між сторінками вікі: [[slug]] і [[slug|текст]]
        $text = preg_replace_callback('/\[\[([a-z0-9][a-z0-9-]{0,79})(?:\|([^\]\n]{1,200}))?\]\]/u', function (array $m): string {
            $slug = $m[1];
            $label = $this->esc(trim($m[2] ?? '') !== '' ? trim($m[2]) : $slug);
            return $this->token(isset($this->existingSlugs[$slug])
                ? '<a href="/wiki/' . $slug . '">' . $label . '</a>'
                : '<a href="/wiki/' . $slug . '" class="wiki-link-missing" title="Такої сторінки ще немає">' . $label . '</a>');
        }, $text) ?? $text;

        // 4. Зображення поки не підтримуються — показуємо опис текстом
        $text = preg_replace_callback('/!\[([^\]\n]*)\]\([^)\n]*\)/u', fn(array $m): string => $this->token('<span class="text-muted">[зображення: ' . $this->esc($m[1]) . ']</span>'), $text) ?? $text;

        // 5. Посилання [текст](url "заголовок")
        if ($allowLinks) {
            $text = preg_replace_callback('/\[((?:[^\[\]\n]|\n)+?)\]\(\s*([^)\s]*)(?:\s+"([^"\n]*)")?\s*\)/u', function (array $m): string {
                $inner = $this->inline($m[1], false);
                $url = self::safeUrl($m[2]);
                return $this->token($url === null ? $inner : $this->linkHtml($url, $inner, $m[3] ?? null));
            }, $text) ?? $text;

            // 6. Автопосилання <https://…>
            $text = preg_replace_callback('/<(https?:\/\/[^\s<>]+)>/i', function (array $m): string {
                $url = self::safeUrl($m[1]);
                return $this->token($url === null ? $this->esc($m[0]) : $this->linkHtml($url, $this->esc($m[1])));
            }, $text) ?? $text;
        }

        // 7. Жорсткі переноси: два пробіли або «\» перед кінцем рядка
        $text = preg_replace_callback('/(?: {2,}|\\\\)\n/', fn(): string => $this->token("<br>\n"), $text) ?? $text;

        // 8. Усе, що лишилось, — звичайний текст: екрануємо, потім застосовуємо виділення
        $text = $this->esc($text);
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/us', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])__(?=\S)(.+?)(?<=\S)__(?![\w_])/us', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![*\w])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\w])/us', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![\w_])/us', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/us', '<del>$1</del>', $text) ?? $text;

        // 9. Повертаємо вставки (вони самі вже безпечний HTML і не містять інших вставок)
        return preg_replace_callback('/' . self::TOKEN_OPEN . '(\d+)' . self::TOKEN_CLOSE . '/u', fn(array $m): string => $this->tokens[(int) $m[1]] ?? '', $text) ?? $text;
    }
}
