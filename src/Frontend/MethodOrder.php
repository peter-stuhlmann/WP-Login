<?php

declare(strict_types=1);

namespace Portier\Frontend;

/**
 * Order of the login methods below the form (passkey button, social providers), as sorted by
 * the admin (Figma "Loginarten": drag & drop or arrow keys).
 */
final class MethodOrder
{
    /**
     * Puts the methods in the configured order. Methods missing from the order (e.g. a provider
     * added later) keep their default position after the sorted ones; ids in the order that are
     * not available are skipped.
     *
     * @param array<string, array<string, string>> $methods Id => method, in default order.
     * @param list<string>                         $order   Configured ids.
     * @return list<array<string, string>>
     */
    public static function apply(array $methods, array $order): array
    {
        $sorted = [];
        foreach ($order as $id) {
            if (isset($methods[$id])) {
                $sorted[] = $methods[$id];
                unset($methods[$id]);
            }
        }
        return array_merge($sorted, array_values($methods));
    }
}
