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
     * Obtenez un sous-ensemble des données de session.
     */
    public function only(array $keys): array
    {
        return Arr::only($this->all(), $keys);
    }

    /**
     * Vérifie si une clé existe.
     */
    public function exists(array|string $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $key) {
            if (! isset($_SESSION[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Déterminez si la clé donnée est manquante dans les données de session.
     */
    public function missing(array|string $key): bool
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
     * Obtenez la valeur d'une clé donnée, puis effacez-la.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * Déterminez si la session contient d’anciennes entrées.
     */
    public function hasOldInput(?string $key = null): bool
    {
        $old = $this->getOldInput($key);

        return null === $key ? count($old) > 0 : null !== $old;
    }

    /**
     * {@inheritDoc}
     */
    public function getOldInput($key = null, $default = null)
    {
        if (! null !== $value = parent::getOldInput($key)) {
            return $value;
        }

        return $default;
    }

    /**
     * Remplacez entièrement les attributs de session donnés.
     */
    public function replace(array $attributes): void
    {
        $this->put($attributes);
    }

    /**
     * Récupérez un élément de la session ou stockez la valeur par défaut.
     */
    public function remember(string $key, Closure $callback): mixed
    {
        if (null !== ($value = $this->get($key))) {
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
     * Incrémente la valeur d'un élément dans la session.
     */
    public function increment(string $key, int $amount = 1): mixed
    {
        $this->put($key, $value = $this->get($key, 0) + $amount);

        return $value;
    }

    /**
     * Décrémenter la valeur d'un élément dans la session.
     *
     * @return int
     */
    public function decrement(string $key, int $amount = 1)
    {
        return $this->increment($key, $amount * -1);
    }

    /**
     * Flashez une paire clé/valeur dans la session.
     */
    public function flash(array|string $key, mixed $value = true): void
    {
        $this->setFlashdata($key, $value);
    }

    public function flashErrors(array|string $errors, string $key = 'default'): array
    {
        if (is_string($errors)) {
            $errors = [$key => $errors];
        }

        $_errors = $this->getFlashdata('errors') ?? [];

        $flashed = array_merge($_errors, $errors);

        $this->flash('errors', $flashed);

        return $flashed;
    }

    /**
     * Obtenez la valeur du jeton CSRF.
     */
    public function token(): ?string
    {
        return $this->get('_token');
    }

    /**
     * Régénérez la valeur du jeton CSRF.
     */
    public function regenerateToken(): void
    {
        $this->put('_token', Text::random(40));
    }

    /**
     * Obtenez l'URL précédente de la session.
     */
    public function previousUrl(): ?string
    {
        return $this->get('_previous.url');
    }

    /**
     * Définissez l'URL "précédente" dans la session.
     */
    public function setPreviousUrl(string $url): void
    {
        $this->put('_previous.url', $url);
    }

    /**
     * Précisez que l'utilisateur a confirmé son mot de passe.
     */
    public function passwordConfirmed(): void
    {
        $this->put('auth.password_confirmed_at', time());
    }
}
