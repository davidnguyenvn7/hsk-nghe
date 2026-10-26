<?php

namespace HSKN\Audio;

defined('ABSPATH') || exit;

/**
 * Đọc frame MPEG audio Layer III để ghép nhiều clip thành một file mà không cần ffmpeg.
 *
 * Bỏ ID3v2 ở đầu, ID3v1 ở cuối và frame Xing/Info/VBRI (frame này khai báo tổng thời lượng của
 * clip gốc, giữ lại thì trình duyệt sẽ hiểu sai độ dài file ghép). Các clip phải cùng sample rate.
 */
final class Mp3
{
    private const BR_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
    private const BR_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
    private const SR = [3 => [44100, 48000, 32000], 2 => [22050, 24000, 16000], 0 => [11025, 12000, 8000]];

    /**
     * @return array{bytes:string, count:int, sample_rate:int, spf:int, ms:float, header:?string}
     */
    public static function read(string $path): array
    {
        $d = @file_get_contents($path);
        if ($d === false) {
            throw new \RuntimeException("Không đọc được $path");
        }
        return self::parse($d);
    }

    public static function parse(string $d): array
    {
        $len = strlen($d);
        $pos = 0;
        if (substr($d, 0, 3) === 'ID3' && $len > 10) {
            $size = ((ord($d[6]) & 0x7f) << 21) | ((ord($d[7]) & 0x7f) << 14) | ((ord($d[8]) & 0x7f) << 7) | (ord($d[9]) & 0x7f);
            $pos = 10 + $size + ((ord($d[5]) & 0x10) ? 10 : 0);
        }
        $end = $len;
        if ($len >= 128 && substr($d, -128, 3) === 'TAG') {
            $end -= 128;
        }

        $out = [];
        $count = 0;
        $sr = 0;
        $spf = 0;
        $header = null;
        while ($pos + 4 <= $end) {
            $h = unpack('N', substr($d, $pos, 4))[1];
            if (($h & 0xFFE00000) !== 0xFFE00000) {
                $pos++;
                continue;
            }
            $ver = ($h >> 19) & 3;       // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
            $layer = ($h >> 17) & 3;     // 1 = Layer III
            $crc = (($h >> 16) & 1) === 0;
            $brIdx = ($h >> 12) & 15;
            $srIdx = ($h >> 10) & 3;
            $pad = ($h >> 9) & 1;
            $mono = (($h >> 6) & 3) === 3;
            if ($ver === 1 || $layer !== 1 || $brIdx === 0 || $brIdx === 15 || $srIdx === 3) {
                $pos++;
                continue;
            }
            $br = ($ver === 3 ? self::BR_V1 : self::BR_V2)[$brIdx] * 1000;
            $rate = self::SR[$ver][$srIdx];
            $samples = $ver === 3 ? 1152 : 576;
            $flen = intdiv(intdiv($samples, 8) * $br, $rate) + $pad;
            if ($flen < 8 || $pos + $flen > $end) {
                break;
            }
            $frame = substr($d, $pos, $flen);
            $pos += $flen;

            $side = $ver === 3 ? ($mono ? 17 : 32) : ($mono ? 9 : 17);
            $off = 4 + ($crc ? 2 : 0);
            if ($count === 0) {
                $tag = substr($frame, $off + $side, 4);
                if ($tag === 'Xing' || $tag === 'Info' || substr($frame, 36, 4) === 'VBRI') {
                    continue;
                }
            }
            $header ??= substr($frame, 0, 4);
            $out[] = $frame;
            $count++;
            $sr = $rate;
            $spf = $samples;
        }

        return [
            'bytes'        => implode('', $out),
            'count'        => $count,
            'sample_rate'  => $sr,
            'spf'          => $spf,
            'ms'           => $sr ? $count * $spf * 1000 / $sr : 0.0,
            'header'       => $header,
        ];
    }

    /**
     * Frame im lặng cùng định dạng với $header: side info toàn 0 (main_data_begin = 0, part2_3_length = 0)
     * nên bộ giải mã cho ra mẫu 0, và frame tự chứa nên lặp lại bao nhiêu lần cũng được.
     *
     * @return array{frame:string, ms:float}
     */
    public static function silentFrame(string $header): array
    {
        $h = unpack('N', $header)[1];
        $h |= 1 << 16;           // không CRC
        $h &= ~(1 << 9);         // không padding
        $ver = ($h >> 19) & 3;
        $br = ($ver === 3 ? self::BR_V1 : self::BR_V2)[($h >> 12) & 15] * 1000;
        $rate = self::SR[$ver][($h >> 10) & 3];
        $samples = $ver === 3 ? 1152 : 576;
        $flen = intdiv(intdiv($samples, 8) * $br, $rate);
        return ['frame' => pack('N', $h) . str_repeat("\0", $flen - 4), 'ms' => $samples * 1000 / $rate];
    }

    public static function durationMs(string $path): int
    {
        try {
            return (int) round(self::read($path)['ms']);
        } catch (\RuntimeException) {
            return 0;
        }
    }
}
