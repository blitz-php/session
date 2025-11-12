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
use InvalidArgumentException;

class Store extends Session
{
    /**
     * {@inheritDoc}
     */
    public function start(): ?self
    {
        $status = parent::start();

        if (null !== $status && ! $this->has('_token')) {
            $this->regenerateToken();
        }

        return $status === null ? null : $this;
    }

    /**
     * {@inheritDoc}
     */
    public function regenerate(bool $destroy = false): void
    {
		if ($this->started) {
			parent::regenerate($destroy);
		}

		$this->regenerateToken();
    }

    /**
     * Récupère un sous-ensemble des données de session.
     */
    public function only(array $keys): array
    {
        return Arr::only($this->all(), $keys);
    }

    /**
     * Récupère toutes les données de session sauf celles spécifiées.
     */
    public function except(array $keys): array
    {
        return Arr::except($this->all(), $keys);
    }

    /**
     * Vérifie si une clé existe dans la session (inclut la vérification de null).
     */
    public function exists(array|string $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $k) {
            if (! array_key_exists($k, $_SESSION)) {
                return false;
            }
        }

        return true;
    }

	/**
	 * Récupère les données flash ou une valeur par défaut.
	 */
	public function flashed(?string $key = null, mixed $default = null): mixed
	{
		if (null !== $data = $this->getFlashdata($key)) {
			return $data;
		}

		return Helpers::value($default);
	}

    /**
     * Vide toutes les données de session sauf certaines clés.
     */
    public function flush(array $except = []): void
    {
        $allData = $this->all();

        foreach ($allData as $key => $value) {
            if (! in_array($key, $except)) {
                $this->remove($key);
            }
        }
    }

    /**
     * Récupère une ancienne valeur d'entrée.
     */
    public function getOldInput(?string $key = null, mixed $default = null): mixed
    {
        $oldInput = $this->flashed('_old_input', []);

        if (null === $key) {
            return $oldInput;
        }

        return Arr::get($oldInput, $key, $default);
    }

    /**
     * Détermine si au moins une des clés existe et n'est pas nulle.
     */
    public function hasAny(string|array $key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();

        foreach ($keys as $key) {
            if ($this->exists($key) && null !== $this->get($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Détermine si la session contient des anciennes entrées.
     */
    public function hasOldInput(?string $key = null): bool
    {
        $old = $this->getOldInput($key);

        return null === $key ? ! empty($old) : null !== $old;
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
        $value = parent::get($key);

        if ($key === null) {
            return $value;
        }

        return $value ?? Helpers::value($default);
    }

    /**
     * Récupère la valeur puis la supprime.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        return Helpers::tap($this->get($key, $default), function () use ($key) {
            $this->remove($key);
        });
    }

    /**
     * Récupère et supprime un élément flash.
     */
    public function pullFlash(string $key, mixed $default = null): mixed
    {
        return Helpers::tap($this->flashed($key, $default), function () use ($key) {
            $this->remove($key);
        });
    }

    /**
     * Remplacez entièrement les attributs de session donnés.
     */
    public function replace(array $attributes): void
    {
		$this->flush();
		$this->regenerateToken();

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
     * Ajoute une valeur à un tableau dans la session.
     */
    public function push(string $key, mixed $value): void
    {
        $array = $this->get($key, []);

        if (! is_array($array)) {
            throw new InvalidArgumentException("La valeur de la clé '{$key}' n'est pas un tableau.");
        }

        $array[] = $value;

        $this->put($key, $array);
    }

    /**
     * Incrémente la valeur d'un élément dans la session.
     */
    public function increment(string $key, int $amount = 1): mixed
    {
        $value = $this->get($key, 0);

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("La valeur de la clé '{$key}' n'est pas numérique.");
        }

        $newValue = $value + $amount;
        $this->put($key, $newValue);

        return $newValue;
    }

    /**
     * Décrémente la valeur d'un élément dans la session.
     */
    public function decrement(string $key, int $amount = 1): mixed
    {
        return $this->increment($key, $amount * -1);
    }

    /**
     * Flashe une paire clé/valeur dans la session.
     */
    public function flash(array|string $key, mixed $value = null): void
    {
        $this->setFlashdata($key, $value);
    }

    /**
     * Flashe un tableau d’entrées dans la session.
     */
    public function flashInput(array $value): void
    {
        $this->flash('_old_input', $value);
    }

	/**
     * Flashe des erreurs dans la session.
     */
    public function flashErrors(array|string $errors, string $key = 'default'): array
    {
        if (is_string($errors)) {
            $errors = [$key => $errors];
        }

        $_errors = $this->flashed('errors', []);

        $flashed = array_merge($_errors, $errors);

        $this->flash('errors', $flashed);

        return $flashed;
    }

    /**
     * Remettre à jour toutes les données de la mémoire flash de la session.
     */
    public function reflash(): void
    {
        $this->keep($this->getFlashKeys());
    }

    /**
     * Reflasher un sous-ensemble des données actuelles de la mémoire flash.
     */
    public function keep(array|string $keys): void
    {
        $this->keepFlashdata($keys);
    }

    /**
     * Définit de nouvelles données dans la session et les marque comme données temporaires avec une durée de vie définie.
     */
    public function temp(array|string $key, mixed $value = null, int $expire = 300): void
    {
        $this->setTempdata($key, $value, $expire);
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
     * Determine if the previous URI is available.
     */
    public function hasPreviousUri(): bool
    {
        return null !== $this->previousUrl();
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
