<?php
namespace MinS3\Api;

use MinS3\Api\Parser\ParserException;

/**
 * 解析结果中的时间值。
 *
 * 继承 \DateTime，同时可以像字符串一样使用（转为 ISO 8601），
 * 因此 `echo $result['LastModified']` 与 `$result['LastModified']->format(...)`
 * 都能工作。
 */
class DateTimeResult extends \DateTime implements \JsonSerializable
{
    private const ISO8601_NANOSECOND_REGEX = '/^(.*\.\d{6})(\d{1,3})(Z|[+-]\d{2}:\d{2})?$/';

    /**
     * 由 Unix 时间戳创建。
     */
    public static function fromEpoch(mixed $unixTimestamp): self
    {
        if (!is_numeric($unixTimestamp)) {
            throw new ParserException('传给 DateTimeResult::fromEpoch 的时间戳无效');
        }

        // 本地化环境下小数点可能是逗号，格式串必须跟着变
        $decimalSeparator = localeconv()['decimal_point'] ?? '.';
        $dateTime = \DateTime::createFromFormat(
            'U' . $decimalSeparator . 'u',
            sprintf('%0.6f', $unixTimestamp),
            new \DateTimeZone('UTC')
        );

        if ($dateTime === false) {
            throw new ParserException('传给 DateTimeResult::fromEpoch 的时间戳无效');
        }

        return new self($dateTime->format('Y-m-d H:i:s.u'), new \DateTimeZone('UTC'));
    }

    public static function fromISO8601(mixed $iso8601Timestamp): self
    {
        if (is_numeric($iso8601Timestamp) || !is_string($iso8601Timestamp)) {
            throw new ParserException('传给 DateTimeResult::fromISO8601 的时间戳无效');
        }

        // 8.0.10 之前不支持纳秒精度，降到微秒
        if (PHP_VERSION_ID < 80010
            && preg_match(self::ISO8601_NANOSECOND_REGEX, $iso8601Timestamp, $matches)
        ) {
            $iso8601Timestamp = $matches[1] . ($matches[3] ?? '');
        }

        return new self($iso8601Timestamp);
    }

    /**
     * 由格式未知的时间值创建。
     *
     * @param string|null $expectedFormat 'iso8601' 或 'unixTimestamp'，
     *                                    仅作为首选，解析失败会回退到另一种
     */
    public static function fromTimestamp(mixed $timestamp, ?string $expectedFormat = null): self
    {
        if (empty($timestamp)) {
            return self::fromEpoch(0);
        }

        if (!is_string($timestamp) && !is_numeric($timestamp)) {
            throw new ParserException('传给 DateTimeResult::fromTimestamp 的时间戳无效');
        }

        try {
            if ($expectedFormat === 'iso8601') {
                try {
                    return self::fromISO8601($timestamp);
                } catch (\Exception $e) {
                    return self::fromEpoch($timestamp);
                }
            }

            if ($expectedFormat === 'unixTimestamp') {
                try {
                    return self::fromEpoch($timestamp);
                } catch (\Exception $e) {
                    return self::fromISO8601($timestamp);
                }
            }

            if (self::isValidEpoch($timestamp)) {
                return self::fromEpoch($timestamp);
            }

            return self::fromISO8601($timestamp);
        } catch (\Exception $e) {
            throw new ParserException('传给 DateTimeResult::fromTimestamp 的时间戳无效');
        }
    }

    public function __toString(): string
    {
        return $this->format('c');
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    /**
     * 是否为纯数字（可当作 Unix 时间戳解析）。
     */
    private static function isValidEpoch(mixed $input): bool
    {
        if (is_string($input)) {
            return (bool) preg_match('/^-?[0-9]+\.?[0-9]*$/', $input);
        }

        return is_numeric($input);
    }
}
