<?php

namespace HSKN\Support;

defined('ABSPATH') || exit;

final class Ids
{
    public const WORD = '/^(H[1-3]-\d{3}|T\d{2}-\d{3})$/';
    public const SENTENCE = '/^\d{2}-(S\d{2,3}|D\d+-\d+|E\d{3,4})$/';
    public const TOPIC = '/^\d{2}$/';
    public const TRACK = '/^(topic-\d{2}|words-\d{2}-\d{2}|vocab-hsk[23]-\d{2})$/';

    public static function isWord(string $id): bool
    {
        return (bool) preg_match(self::WORD, $id);
    }

    public static function isSentence(string $id): bool
    {
        return (bool) preg_match(self::SENTENCE, $id);
    }

    public static function isTopic(string $id): bool
    {
        return (bool) preg_match(self::TOPIC, $id);
    }

    public static function isTrack(string $id): bool
    {
        return (bool) preg_match(self::TRACK, $id);
    }
}
