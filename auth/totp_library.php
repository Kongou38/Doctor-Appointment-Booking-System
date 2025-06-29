<?php
function base32Encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }

    $base32 = '';
    for ($i = 0; $i < strlen($binary); $i += 5) {
        $chunk = substr($binary, $i, 5);
        $chunk = str_pad($chunk, 5, '0');
        $index = bindec($chunk);
        $base32 .= $alphabet[$index];
    }
    return $base32;
}

function generateTotpSecret($length = 16) {
    $randomBytes = random_bytes($length);
    return base32Encode($randomBytes);
}

function verifyTotpCode($secret, $code, $window = 1) {
    $secret = base32Decode($secret);
    $timeSlice = floor(time() / 30);

    for ($i = -$window; $i <= $window; $i++) {
        $calculated = calculateTotp($secret, $timeSlice + $i);
        if ($calculated === $code) return true;
    }
    return false;
}

function calculateTotp($key, $timeSlice) {
    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0xf;
    $truncatedHash = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad($truncatedHash % 1000000, 6, '0', STR_PAD_LEFT);
}

function base32Decode($b32) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper($b32);
    $binary = '';
    foreach (str_split($b32) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $data = '';
    for ($i = 0; $i < strlen($binary); $i += 8) {
        $byte = substr($binary, $i, 8);
        if (strlen($byte) < 8) continue;
        $data .= chr(bindec($byte));
    }
    return $data;
}
