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

use BlitzPHP\Contracts\Session\CookieManagerInterface;
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Traits\Support\InteractsWithTime;

class CookieManager implements CookieManagerInterface
{
	use InteractsWithTime;
    use Macroable;

    /**
     * Le chemin par défaut (si spécifié).
     */
    protected string $path = '/';

    /**
     * Le domaine par défaut (si spécifié).
     */
    protected ?string $domain = null;

    /**
     * Le paramètre de sécurité par défaut (par défaut : false).
     */
    protected bool $secure = false;

	/**
     * Le paramètre de sécurité par défaut (par défaut : true).
     */
    protected bool $httponly = true;

    /**
     * L'option SameSite par défaut (si spécifiée).
     */
    protected ?string $samesite = null;

    /**
     * {@inheritDoc}
     */
    public function make(string $name, array|string $value, int $minutes = 0, array $options = []): Cookie
    {
        $time = ($minutes == 0) ? 0 : $this->availableAt($minutes * 60);

        return Cookie::create($name, $value, [
            'expires'  => $time,
            'path'     => $options['path'] ?: $this->path,
            'domain'   => $options['domain'] ?: $this->domain,
            'secure'   => $options['secure'] ?: $this->secure,
            'httponly' => $options['httponly'] ?: $this->httponly,
            'samesite' => $options['samesite'] ?: $this->samesite
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function forever(string $name, array|string $value, array $options = []): Cookie
    {
        return $this->make($name, $value, 2628000, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $name, array $options = []): Cookie
    {
        return $this->make($name, '', -2628000, ['secure'   => null, 'samesite' => null] + $options);
    }

	/**
	 * {@inheritDoc}
	 */
    public function get(string $name, array $options = []): ?Cookie
    {
        if (empty($value = $_COOKIE[$name])) {
            return null;
        }

        return $this->make($name, $value, 0, $options);
    }

	/**
	 * {@inheritDoc}
	 */
	public function has(string $name): bool
	{
		return ! empty($_COOKIE[$name]);
	}

    /**
     * Définissez le chemin et le domaine par défaut du gestionnaire.
     */
    public function setDefaultPathAndDomain(string $path, string $domain, bool $secure = false, bool $httponly = true, ?string $samesite = null): static
    {
        [$this->path, $this->domain, $this->secure, $this->httponly, $this->samesite] = [$path, $domain, $secure, $httponly, $samesite];

        return $this;
    }
}
