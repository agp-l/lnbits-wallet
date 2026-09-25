<?php
declare(strict_types=1);
namespace LiteWallet\Support;

use RuntimeException;

final class Config
{
    private array $values;

    public function __construct(string $path)
    {
        if (!is_file($path)) { throw new RuntimeException('Chybí config.php; postup je v README.md.'); }
        $values = require $path;
        if (!is_array($values)) { throw new RuntimeException('Neplatný config.php.'); }
        $this->values = $values;
        foreach (['lnbits_url', 'database_path', 'app_url', 'app_key', 'mail'] as $key) {
            if (empty($values[$key])) { throw new RuntimeException('V config.php chybí ' . $key . '.'); }
        }
        if (!preg_match('/^[a-f0-9]{64}$/iD', (string) $values['app_key'])) {
            throw new RuntimeException('app_key musí být 32 náhodných bytů v hexadecimálním tvaru.');
        }
        $url = (string) $values['app_url'];
        if (!preg_match('~^https://[^/?#]+(?:/[^?#]*)?/?$~D', $url)
            && !preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::[0-9]+)?(?:/[^?#]*)?/?$~D', $url)) {
            throw new RuntimeException('app_url musí být veřejná HTTPS adresa (nebo lokální HTTP).');
        }
    }

    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function url(): string { return rtrim((string) $this->values['app_url'], '/') . '/'; }
}
