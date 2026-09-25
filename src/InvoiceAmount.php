<?php
declare(strict_types=1);

/** Read the fixed amount from BOLT11's human-readable prefix in millisatoshis. */
function bolt11_amount_msat(string $invoice): int
{
    $separator = strrpos(strtolower($invoice), '1');
    $prefix = $separator === false ? '' : substr(strtolower($invoice), 0, $separator);
    if (!preg_match('/^ln(?:bcrt|bc|tb)([1-9][0-9]*)([munp]?)$/D', $prefix, $match)) {
        throw new InvalidArgumentException('Faktura musí obsahovat pevnou částku.');
    }
    $digits = $match[1];
    if (strlen($digits) > 15) { throw new InvalidArgumentException('Částka je příliš vysoká.'); }
    $n = (int) $digits;
    $multiplier = ['' => 100000000000, 'm' => 100000000, 'u' => 100000, 'n' => 100, 'p' => 0];
    $suffix = $match[2];
    if ($suffix === 'p') {
        if ($n % 10 !== 0) { throw new InvalidArgumentException('Částka je nižší než jeden millisatoshi.'); }
        return intdiv($n, 10);
    }
    $factor = $multiplier[$suffix];
    if ($n > intdiv(PHP_INT_MAX, $factor)) { throw new InvalidArgumentException('Částka je příliš vysoká.'); }
    return $n * $factor;
}
