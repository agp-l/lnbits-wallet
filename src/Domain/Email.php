<?php
declare(strict_types=1);
namespace LiteWallet\Domain;

use InvalidArgumentException;

final class Email
{
    public static function normalize(string $email): string
    {
        $email = trim($email);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[^\x21-\x7e]/', $email)) {
            throw new InvalidArgumentException('Zadejte platnou e-mailovou adresu.');
        }
        [$local, $domain] = explode('@', $email, 2);
        return $local . '@' . strtolower($domain);
    }
}
