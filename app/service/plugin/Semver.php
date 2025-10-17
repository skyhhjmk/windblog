<?php

namespace app\service\plugin;

/**
 * Minimal semver/constraint matcher supporting:
 * - =, ==, >, >=, <, <=
 * - ^, ~
 * - x or * wildcards (e.g. 1.*, 1.2.x)
 * - range with || (OR) and , (AND)
 */
class Semver
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*' || strtolower($constraint) === 'any') {
            return true;
        }
        // Split by OR
        foreach (preg_split('/\s*\|\|\s*/', $constraint) as $orPart) {
            if ($orPart === '') {
                continue;
            }
            $ok = true;
            foreach (preg_split('/\s*,\s*/', $orPart) as $andPart) {
                if ($andPart === '') {
                    continue;
                }
                if (!self::matchAnd($version, $andPart)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function matchAnd(string $version, string $cond): bool
    {
        $cond = trim($cond);
        // Wildcards like 1.*, 1.2.x
        if (preg_match('/^\d+(?:\.\d+)?(?:\.\d+)?[.*xX]$/', $cond)) {
            $prefix = rtrim(str_ireplace(['.x', '.*'], '', $cond), '.');

            return str_starts_with($version, $prefix . '.') || $version === $prefix;
        }
        // ^ caret
        if (str_starts_with($cond, '^')) {
            $base = self::normalize(substr($cond, 1));
            [$b1, $b2, $b3] = self::split($base);
            [$v1, $v2, $v3] = self::split($version);
            if ($b1 > 0) {
                return self::cmpTrip([$v1, $v2, $v3], [$b1, $b2, $b3]) >= 0 && $v1 === $b1;
            }
            if ($b2 > 0) {
                return self::cmpTrip([$v1, $v2, $v3], [$b1, $b2, $b3]) >= 0 && $v2 === $b2;
            }

            return self::cmpTrip([$v1, $v2, $v3], [$b1, $b2, $b3]) >= 0;
        }
        // ~ tilde
        if (str_starts_with($cond, '~')) {
            $base = self::normalize(substr($cond, 1));
            [$b1, $b2, $b3] = self::split($base);
            [$v1, $v2, $v3] = self::split($version);
            // Same minor if specified, else same major
            if ($b2 > 0) {
                return self::cmpTrip([$v1, $v2, $v3], [$b1, $b2, $b3]) >= 0 && $v1 === $b1 && $v2 === $b2;
            }

            return self::cmpTrip([$v1, $v2, $v3], [$b1, $b2, $b3]) >= 0 && $v1 === $b1;
        }
        // Operators
        if (preg_match('/^(>=|<=|>|<|==|=)\s*(.+)$/', $cond, $m)) {
            $op = $m[1];
            $rhs = self::normalize($m[2]);
            $cmp = version_compare($version, $rhs);

            return match ($op) {
                '>' => $cmp > 0,
                '>=' => $cmp >= 0,
                '<' => $cmp < 0,
                '<=' => $cmp <= 0,
                '=', '==' => $cmp === 0,
                default => false,
            };
        }
        // Bare version equals or minimal
        if (preg_match('/^\d+(?:\.\d+){0,2}$/', $cond)) {
            $rhs = self::normalize($cond);

            return version_compare($version, $rhs) >= 0;
        }

        return false;
    }

    private static function normalize(string $v): string
    {
        $v = trim($v);
        $v = ltrim($v, 'vV');
        $parts = explode('.', $v);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    private static function split(string $v): array
    {
        $v = self::normalize($v);
        $parts = array_map('intval', explode('.', $v));

        return [$parts[0], $parts[1], $parts[2]];
    }

    private static function cmpTrip(array $a, array $b): int
    {
        return $a <=> $b;
    }
}
