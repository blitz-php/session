<?php

/**
 * This file is part of Blitz PHP framework.
 *
 * (c) 2022 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Session\Cookie;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Cookie\CookieValuePrefix</a>
 */
class CookieValuePrefix
{
    /**
     * Créez un nouveau préfixe de valeur de cookie pour le nom de cookie donné.
     */
    public static function create(string $cookieName, string $key): string
    {
        return hash_hmac('sha1', $cookieName.'v2', $key).'|';
    }

    /**
     * Supprimez le préfixe de valeur du cookie.
     */
    public static function remove(string $cookieValue): string
    {
        return substr($cookieValue, 41);
    }

    /**
     * Validez qu'une valeur de cookie contient un préfixe valide.
	 * Si tel est le cas, renvoyez la valeur du cookie avec le préfixe supprimé. Sinon, renvoie null.
     */
    public static function validate(string $cookieName, string $cookieValue, string $key): ?string
    {
        $hasValidPrefix = str_starts_with($cookieValue, static::create($cookieName, $key));

        return $hasValidPrefix ? static::remove($cookieValue) : null;
    }
}
