<?php

declare(strict_types=1);

namespace FpDbTest;

use Closure;
use InvalidArgumentException;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    /**
     * @var array<string, Closure>
     */
    private array $replacementClosures;

    private string $markersRegExpPattern = '(?<=[(=\s])\?.*?(?=[)\s]|$)';

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;

        $this->initReplacementClosures();
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $args = array_reverse($args); // array_pop has lower complexity than array_shift

        return preg_replace_callback("/$this->markersRegExpPattern|\{.+?}/", function ($matches) use (&$args) {

            if (preg_match('/^\{(.+)}$/', $matches[0], $blockMatches)) {
                $result = $this->replaceConditionBlock($blockMatches[1], $args);
            } else {
                $result = $this->replaceMarker($matches[0], $this->grabNextArg($args));
            }

            return $result;
        }, $query);
    }

    /**
     * @return SkipException
     */
    public function skip()
    {
        return new SkipException();
    }

    private function replaceConditionBlock(string $block, array &$args): string
    {
        try {
            return preg_replace_callback("/$this->markersRegExpPattern/", function ($matches) use (&$args) {

                return $this->replaceMarker($matches[0], $this->grabNextArg($args));
            }, $block);
        } catch (SkipException) {
            return '';
        }
    }

    /**
     * @throws UnknownMarkerException
     */
    private function replaceMarker(string $marker, mixed $arg): string
    {
        $marker = strtolower($marker);

        return isset($this->replacementClosures[$marker]) ? $this->replacementClosures[$marker]($arg) : throw new UnknownMarkerException();
    }

    /**
     * @throws NoMoreArgsException
     * @throws SkipException
     */
    private function grabNextArg(array &$args): mixed
    {
        if ([] === $args) {
            throw new NoMoreArgsException();
        }

        $arg = array_pop($args);
        if ($arg instanceof SkipException) {
            throw $arg;
        }

        return $arg;
    }

    private function initReplacementClosures(): void
    {
        $this->replacementClosures = [
            '?d' => function (string|int|bool|null $arg): string {
                if (is_numeric($arg) || is_bool($arg)) {
                    $result = strval($arg + 0);

                    if (str_contains($result, '.')) {
                        throw new InvalidArgumentException('Float typed arg is not acceptable for `?d` strategy');
                    }
                } elseif (null === $arg) {
                    $result = $this->replaceNull();
                } else {
                    throw new InvalidArgumentException("Could not convert string arg '$arg' to int for `?d` strategy");
                }

                return $result;
            },
            '?f' => function (string|float|bool|null $arg): string {
                if (is_numeric($arg) || is_bool($arg)) {
                    $result = sprintf('%f', $arg);
                } elseif (null === $arg) {
                    $result = $this->replaceNull();
                } else {
                    throw new InvalidArgumentException("Could not convert string arg '$arg' to float for `?f` strategy");
                }

                return $result;
            },
            '?a' => function (array $arg): string {
                $result = [];
                if (array_is_list($arg)) {
                    $result = array_map(fn($v) => $this->replaceMarker('?', $v), $arg);
                } else {
                    foreach ($arg as $columnName => $value) {
                        $result[] = "{$this->quoteColumnName($columnName)} = " . $this->replaceMarker('?', $value);
                    }
                }

                return implode(', ', $result);
            },
            '?#' => function (array|string $arg): string {
                return implode(', ', array_map([$this, 'quoteColumnName'], (array) $arg));
            },
            '?' => function (string|int|float|bool|null $arg): string {
                if (is_string($arg)) {
                    $result = $this->escapeString($arg);
                } elseif (is_int($arg)) {
                    $result = $this->replaceMarker('?d', $arg);
                } elseif (is_float($arg)) {
                    $result = $this->replaceMarker('?f', $arg);
                } elseif (is_bool($arg)) {
                    $result = (string) $arg;
                } else {
                    $result = $this->replaceNull();
                }

                return $result;
            },
        ];
    }

    private function replaceNull(): string
    {
        return 'NULL';
    }

    private function escapeString(string $value): string
    {
        return "'{$this->mysqli->real_escape_string($value)}'";
    }

    private function quoteColumnName(string $columnName): string
    {
        return "`$columnName`";
    }
}
