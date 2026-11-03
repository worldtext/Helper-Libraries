<?php

declare(strict_types=1);

namespace WorldText\Model;

/**
 * Base for every typed response.
 *
 * The gateway returns numbers as JSON *strings* - `"credits":"2950"`,
 * `"msgid":"10001"`. That is deliberate and documented, so models keep them as
 * strings to round-trip faithfully, and expose numeric accessors (see
 * {@see Model::asDecimal()}) only where a value is genuinely arithmetic.
 * Parsing into floats on the way in would quietly lose precision on long
 * message ids.
 *
 * Each model builds from the raw decoded envelope, tolerating absent optional
 * fields.
 */
abstract class Model
{
    /**
     * @param array<string, mixed> $raw The decoded JSON envelope, kept verbatim.
     */
    public function __construct(public readonly array $raw)
    {
    }

    /**
     * The first of `$names` present in `$data` with a non-null value.
     *
     * The API is inconsistent about naming: group ids arrive as either `grpid`
     * or `groupid` depending on the route.
     *
     * @param array<string, mixed> $data
     */
    protected static function field(array $data, string ...$names): ?string
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $data) && $data[$name] !== null) {
                return self::text($data[$name]);
            }
        }

        return null;
    }

    /**
     * Coerce a JSON scalar to the string form the API uses. Numbers survive as
     * their original text where the encoder managed it; objects and lists
     * become null because no field of this API is one.
     */
    protected static function text(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => self::floatText($value),
            is_bool($value) => $value ? 'true' : 'false',
            default => null,
        };
    }

    /**
     * A JSON number as text, without locale-dependent formatting.
     */
    private static function floatText(float $value): string
    {
        $text = json_encode($value);

        return is_string($text) ? $text : (string) $value;
    }

    /**
     * The raw string a field held, or null when absent.
     */
    protected function get(string $name): ?string
    {
        return $this->field($this->raw, $name);
    }

    /**
     * `credits` as an exact decimal, or null when absent or not a number.
     *
     * Without bcmath (ext-bcmath is not guaranteed) this is a string->string
     * conversion: no float is involved, so "1.0" stays "1.0" and a long value
     * keeps every digit.
     */
    public function asDecimal(): ?string
    {
        return self::decimal($this->get('credits'));
    }

    /**
     * Normalise a numeric string to a plain decimal string, or null.
     *
     * Accepts a leading sign, digits either side of a decimal point, and an
     * exponent, as JSON numbers do.
     */
    public static function decimal(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $pattern = '/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/';
        if (preg_match($pattern, trim($value), $match) !== 1) {
            return null;
        }

        $sign = $match[1] === '-' ? '-' : '';
        $whole = $match[2] ?? '';
        $fraction = $match[3] ?? '';
        $exponent = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0;

        $digits = $whole . $fraction;
        if ($digits === '' || trim($digits, '0') === '') {
            return '0';
        }

        // Move the decimal point: it currently sits after `$whole`.
        $point = strlen($whole) + $exponent;

        if ($point <= 0) {
            $wholePart = '0';
            $fractionPart = str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $wholePart = $digits . str_repeat('0', $point - strlen($digits));
            $fractionPart = '';
        } else {
            $wholePart = substr($digits, 0, $point);
            $fractionPart = substr($digits, $point);
        }

        $wholePart = ltrim($wholePart, '0');
        if ($wholePart === '') {
            $wholePart = '0';
        }
        $fractionPart = rtrim($fractionPart, '0');

        $text = $wholePart . ($fractionPart === '' ? '' : '.' . $fractionPart);

        return $sign . $text;
    }

    /**
     * The model as a readable string, e.g. `Credits(credits='2950')`.
     */
    public function __toString(): string
    {
        $name = (new \ReflectionClass($this))->getShortName();
        $parts = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getName() === 'raw' || $property->isStatic()) {
                continue;
            }
            $value = $property->getValue($this);
            $parts[] = $property->getName() . '=' . self::describe($value);
        }

        return $name . '(' . implode(', ', $parts) . ')';
    }

    /**
     * Render one property value for {@see Model::__toString()}.
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_string($value) => "'" . $value . "'",
            is_array($value) => '[' . implode(', ', array_map(self::describe(...), $value)) . ']',
            default => (string) $value,
        };
    }
}