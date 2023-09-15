<?php

/**
 * This file is part of Blitz PHP framework.
 *
 * (c) 2022 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Session;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use Closure;

class Store extends Session
{
	/**
     * {@inheritDoc}
     */
    public function start()
    {
		$status = parent::start();

		if (! $this->has('_token')) {
            $this->regenerateToken();
        }

		return $status;
    }

	/**
     * {@inheritDoc}
     */
    public function regenerate(bool $destroy = false): void
    {
		$this->regenerateToken();
		parent::regenerate($destroy);
    }


    /**
     * Get a subset of the session data.
     */
    public function only(array $keys): array
    {
        return Arr::only($this->all(), $keys);
    }

	/**
     * Checks if a key exists.
     *
     * @param  string|array  $key
     */
    public function exists($key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

		foreach ($keys as $key) {
			if (!isset($_SESSION[$key])) {
				return false;
			}
		}

		return true;
    }

    /**
     * Determine if the given key is missing from the session data.
     *
     * @param  string|array  $key
     */
    public function missing($key): bool
    {
        return ! $this->exists($key);
    }

	/**
     * {@inheritDoc}
     */
    public function get(?string $key = null, mixed $default = null): mixed
	{
		if (null !== $value = parent::get($key)) {
			return $value;
		}

		return $default;
	}

	/**
     * Get the value of a given key and then forget it.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
		$value = $this->get($key, $default);
		$this->remove($key);

		return $value;
    }

    /**
     * Determine if the session contains old input.
     */
    public function hasOldInput(?string $key = null): bool
    {
        $old = $this->getOldInput($key);

        return is_null($key) ? count($old) > 0 : ! is_null($old);
    }

    /**
     * {@inheritDoc}
     */
    public function getOldInput($key = null, $default = null)
    {
		if (!null !== $value = parent::getOldInput($key)) {
			return $value;
		}

		return $default;
    }

	/**
     * Replace the given session attributes entirely.
     */
    public function replace(array $attributes): void
    {
        $this->put($attributes);
    }

	/**
     * Get an item from the session, or store the default value.
     */
    public function remember(string $key, Closure $callback): mixed
    {
        if (! is_null($value = $this->get($key))) {
            return $value;
        }

        return Helpers::tap($callback(), function ($value) use ($key) {
            $this->put($key, $value);
        });
    }

	/**
	 * {@inheritDoc}
     */
    public function push(string $key, mixed $value): void
    {
        $array = $this->get($key, []);

        $array[] = $value;

        $this->put($key, $array);
    }

	/**
     * Increment the value of an item in the session.
     */
    public function increment(string $key, int $amount = 1): mixed
    {
        $this->put($key, $value = $this->get($key, 0) + $amount);

        return $value;
    }

    /**
     * Decrement the value of an item in the session.
	 *
     * @return int
     */
    public function decrement(string $key, int $amount = 1)
    {
        return $this->increment($key, $amount * -1);
    }

    /**
     * Flash a key / value pair to the session.
     *
     * @return void
     */
    public function flash(string|array $key, mixed $value = true): void
    {
        $this->setFlashdata($key, $value);
    }

    public function flashErrors(array|string $errors, string $key = 'default'): void
    {
        if (is_string($errors)) {
            $errors = [$key => $errors];
        }

        $_errors = $this->getFlashdata('errors') ?? [];
        
        $this->flash(
            'errors',
            array_merge($_errors, $errors)
        );
    }

	/**
     * Get the CSRF token value.
     *
     * @return string
     */
    public function token(): ?string
    {
        return $this->get('_token');
    }

    /**
     * Regenerate the CSRF token value.
     *
     * @return void
     */
    public function regenerateToken()
    {
        $this->put('_token', Text::random(40));
    }

    /**
     * Get the previous URL from the session.
     */
    public function previousUrl(): ?string
    {
        return $this->get('_previous.url');
    }

    /**
     * Set the "previous" URL in the session.
     */
    public function setPreviousUrl(string $url): void
    {
        $this->put('_previous.url', $url);
    }

    /**
     * Specify that the user has confirmed their password.
     */
    public function passwordConfirmed(): void
    {
        $this->put('auth.password_confirmed_at', time());
    }
}
