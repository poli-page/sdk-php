<?php

declare(strict_types=1);

namespace PoliPage\Tests\Contract;

/**
 * Reading JSON decoded as objects.
 */
final class Json
{
    /**
     * The fields of a decoded JSON object, by name.
     *
     * @return array<string, mixed>
     */
    public static function fields(\stdClass $object): array
    {
        $fields = [];
        foreach (get_object_vars($object) as $name => $value) {
            $fields[self::name($name)] = $value;
        }

        return $fields;
    }

    /**
     * A JSON member name; PHP turns a numeric one into an int key.
     */
    private static function name(mixed $name): string
    {
        return is_int($name) ? (string) $name : (is_string($name) ? $name : throw new ContractException('A JSON member name is not a string'));
    }
}
