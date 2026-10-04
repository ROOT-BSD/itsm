<?php

namespace App\Core;

/**
 * Тонка обгортка над вбудованим розширенням ext-ldap (не Composer-пакет —
 * те саме розширення, що й pdo_mysql чи mbstring, лише типово вимкнене за
 * замовчуванням). На відміну від IMAP/SMTP, протокол LDAP (ASN.1/BER) тут
 * свідомо НЕ реалізовано власноруч — ризик помилки в самостійній реалізації
 * такого бінарного протоколу набагато вищий, ніж для простих текстових
 * IMAP/SMTP, а ext-ldap для цього й призначене.
 *
 * Сумісна і з Active Directory (реальне розгортання), і з будь-яким іншим
 * LDAP-сервером (використовувалась під час розробки для перевірки).
 */
class LdapClient
{
    /** @var \LDAP\Connection|resource|null */
    private $conn = null;

    public function __construct(
        private string $host,
        private int $port = 389,
        private string $encryption = 'none', // none | starttls | ldaps
        private bool $verifyCert = true,
        private int $timeoutSeconds = 10
    ) {
    }

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        if (!$this->verifyCert) {
            // ext-ldap читає налаштування TLS з /etc/ldap/ldap.conf, а не з контексту виклику —
            // єдиний спосіб вимкнути перевірку сертифіката програмно, до ldap_connect().
            putenv('LDAPTLS_REQCERT=never');
        }

        $scheme = $this->encryption === 'ldaps' ? 'ldaps' : 'ldap';
        $uri = "{$scheme}://{$this->host}:{$this->port}";

        $conn = @ldap_connect($uri);
        if ($conn === false) {
            throw new LdapException("Не вдалося сформувати з'єднання з {$this->host}:{$this->port} — перевірте адресу й порт.");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $this->timeoutSeconds);

        if ($this->encryption === 'starttls') {
            if (!@ldap_start_tls($conn)) {
                throw new LdapException($this->explainError($conn, 'Не вдалося увімкнути STARTTLS'));
            }
        }

        $this->conn = $conn;
    }

    /** @throws LdapException якщо логін/пароль не підійшли або сервер недоступний */
    public function bind(string $dn, string $password): void
    {
        // LDAP-специфіка, про яку легко забути: порожній пароль за специфікацією означає
        // "анонімний bind" і сервер поверне УСПІХ навіть для невірного DN — тобто без цієї
        // перевірки порожній рядок пароля міг би пройти як «успішний вхід».
        if ($password === '') {
            throw new LdapException('Порожній пароль неприпустимий (анонімний bind).');
        }
        if (!@ldap_bind($this->conn, $dn, $password)) {
            throw new LdapException($this->explainError($this->conn, "Не вдалося увійти як «{$dn}»"));
        }
    }

    /**
     * @param string[] $attributes
     * @return array<int, array<string, mixed>> кожен запис — ['dn' => ..., 'атрибут' => 'значення' | ['значення', ...]]
     */
    public function search(string $baseDn, string $filter, array $attributes = []): array
    {
        $result = @ldap_search($this->conn, $baseDn, $filter, $attributes);
        if ($result === false) {
            throw new LdapException($this->explainError($this->conn, 'Помилка пошуку в каталозі'));
        }

        $entries = ldap_get_entries($this->conn, $result);
        $out = [];
        for ($i = 0; $i < $entries['count']; $i++) {
            $entry = $entries[$i];
            $row = ['dn' => $entry['dn']];
            foreach ($entry as $key => $value) {
                if (!is_string($key) || !is_array($value)) {
                    continue;
                }
                unset($value['count']);
                $row[strtolower($key)] = count($value) === 1 ? $value[0] : array_values($value);
            }
            $out[] = $row;
        }
        return $out;
    }

    public function unbind(): void
    {
        $this->close();
    }

    private function close(): void
    {
        if ($this->conn !== null) {
            @ldap_unbind($this->conn);
            $this->conn = null;
        }
    }

    /** @param \LDAP\Connection|resource $conn */
    private function explainError($conn, string $prefix): string
    {
        $code = ldap_errno($conn);
        $message = ldap_error($conn);

        $hint = match (true) {
            $code === 0x31 => ' Невірний логін або пароль.', // LDAP_INVALID_CREDENTIALS
            $code === 0x02 => ' Сервер не підтримує цю версію протоколу або налаштування TLS.',
            str_contains(strtolower($message), 'certificate') => ' Якщо сертифікат сервера самопідписаний — вкажіть AD_VERIFY_CERT=false у .env.',
            default => '',
        };

        return "{$prefix}: {$message} (код {$code}).{$hint}";
    }
}
