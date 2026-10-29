<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * The placeholders of a contract case (README of poli-page/api-contract):
 * `{{mock}}` is the mock server's origin, `{{httpDate+N}}` an HTTP-date N
 * seconds after the replacement. Any other placeholder is an error.
 *
 * Used by the test and by mock-server.php, which replaces the placeholders
 * of an answer when it sends it.
 */
final class Placeholders
{
    /**
     * @param mixed $value a JSON value, decoded as arrays or as objects
     *
     * @return mixed the same shape, every string substituted
     */
    public static function substitute(mixed $value, string $mock): mixed
    {
        if (is_string($value)) {
            return self::substituteString($value, $mock);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::substitute($item, $mock), $value);
        }
        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $out->{$key} = self::substitute($item, $mock);
            }

            return $out;
        }

        return $value;
    }

    public static function substituteString(string $value, string $mock): string
    {
        $result = preg_replace_callback(
            '/\{\{([^}]*)\}\}/',
            static function (array $match) use ($mock): string {
                $name = $match[1];
                if ($name === 'mock') {
                    return $mock;
                }
                if (preg_match('/^httpDate\+(\d+)$/', $name, $date) === 1) {
                    // An HTTP-date has whole seconds: round to the nearest one.
                    $at = (int) round(microtime(true) + (int) $date[1]);

                    return gmdate('D, d M Y H:i:s', $at) . ' GMT';
                }

                throw new ContractException(sprintf('Unknown contract placeholder {{%s}}', $name));
            },
            $value,
        );
        if ($result === null) {
            throw new ContractException('Cannot replace the placeholders of ' . $value);
        }

        return $result;
    }
}
