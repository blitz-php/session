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

use ArrayIterator;
use BlitzPHP\Contracts\Session\CookieInterface;
use BlitzPHP\Utilities\Helpers;
use Countable;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use IteratorAggregate;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Traversable;

/**
 * Fournit une collection immuable d'objets cookies.
 * Ajout ou suppression à une collection renvoie une *nouvelle* collection que vous devez conserver.
 *
 * @template-implements IteratorAggregate<string, CookieInterface>
 * @phpstan-consistent-constructor
 *
 * @credit <a href="https://api.cakephp.org/4.3/class-Cake.Http.Cookie.CookieCollection.html">CakePHP - \Cake\Http\Cookie\CookieCollection</a>
 */

class CookieCollection implements IteratorAggregate, Countable
{
    /**
     * Cookies stockés dans la collection
     *
     * @var array<string, CookieInterface>
     */
    protected array $cookies = [];

    /**
     * Constructeur
     *
     * @param array<CookieInterface> $cookies Tableau de cookies
     */
    public function __construct(array $cookies = [])
    {
        $this->validateCookies($cookies);

        foreach ($cookies as $cookie) {
            $this->cookies[$cookie->getId()] = $cookie;
        }
    }

    /**
     * Crée une collection de cookies à partir d'un tableau d'en-têtes Set-Cookie
     *
     * @param list<string> $headers Tableau des valeurs d'en-tête Set-Cookie
     * @param array<string, mixed> $defaults Attributs par défaut pour les cookies
     */
    public static function createFromHeader(array $headers, array $defaults = []): static
    {
        $cookies = [];

        foreach ($headers as $header) {
            try {
                $cookies[] = Cookie::createFromHeaderString($header, $defaults);
            } catch (Exception $e) {
                // Log l'erreur mais continue avec les autres cookies valides
                /* Helpers::triggerWarning(
                    sprintf('CookieCollection: Impossible de parser l\'en-tête cookie - %s', $e->getMessage())
                ); */
            }
        }

        return new static($cookies);
    }

    /**
     * Crée une nouvelle collection à partir des cookies d'une requête serveur
     */
    public static function createFromServerRequest(ServerRequestInterface $request): static
    {
        $cookieParams = $request->getCookieParams();
        $cookies = [];

        foreach ($cookieParams as $name => $value) {
            $cookies[] = Cookie::create((string) $name, $value);
        }

        return new static($cookies);
    }

    /**
     * Retourne le nombre de cookies dans la collection
     */
    public function count(): int
    {
        return count($this->cookies);
    }

    /**
     * Vérifie si la collection est vide
     */
    public function isEmpty(): bool
    {
        return empty($this->cookies);
    }

    /**
     * Ajoute un cookie et retourne une nouvelle collection mise à jour
     *
     * Les cookies sont stockés par ID (nom;domaine;chemin). Si un cookie avec le même
     * ID existe déjà, il sera remplacé.
     */
    public function add(CookieInterface $cookie): static
    {
        $new                            = clone $this;
        $new->cookies[$cookie->getId()] = $cookie;

        return $new;
    }

    /**
     * Ajoute plusieurs cookies à la collection
     *
     * @param array<CookieInterface> $cookies Cookies à ajouter
     */
    public function addMany(array $cookies): static
    {
        $new = clone $this;

        foreach ($cookies as $cookie) {
            if (!$cookie instanceof CookieInterface) {
                throw new InvalidArgumentException(
                    'Tous les éléments doivent implémenter CookieInterface'
                );
            }
            $new->cookies[$cookie->getId()] = $cookie;
        }

        return $new;
    }

    /**
     * Récupère le premier cookie correspondant au nom spécifié
     *
     * @throws InvalidArgumentException Si le cookie n'est pas trouvé
     */
    public function get(string $name): CookieInterface
    {
        $cookie = $this->find($name);

        if ($cookie === null) {
            throw new InvalidArgumentException(
                sprintf('Cookie "%s" non trouvé dans la collection', $name)
            );
        }

        return $cookie;
    }

    /**
     * Recherche le premier cookie correspondant au nom spécifié
     */
    public function find(string $name): ?CookieInterface
    {
        $name = mb_strtolower($name);

        foreach ($this->cookies as $cookie) {
            if (mb_strtolower($cookie->getName()) === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Recherche tous les cookies correspondant au nom spécifié
     *
     * @return array<CookieInterface>
     */
    public function findAll(string $name): array
    {
        $name = mb_strtolower($name);
        $found = [];

        foreach ($this->cookies as $cookie) {
            if (mb_strtolower($cookie->getName()) === $name) {
                $found[] = $cookie;
            }
        }

        return $found;
    }

    /**
     * Vérifie si un cookie avec le nom spécifié existe dans la collection
     */
    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /**
     * Accesseur magique pour récupérer un cookie par son nom
     */
    public function __get(string $name): ?CookieInterface
    {
        return $this->find($name);
    }

    /**
     * Vérificateur magique pour l'existence d'un cookie
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /**
     * Crée une nouvelle collection sans les cookies correspondant au nom spécifié
     */
    public function remove(string $name): static
    {
        $new = clone $this;
        $name = mb_strtolower($name);

        foreach ($new->cookies as $id => $cookie) {
            if (mb_strtolower($cookie->getName()) === $name) {
                unset($new->cookies[$id]);
            }
        }

        return $new;
    }

    /**
     * Crée une nouvelle collection sans les cookies spécifiés
     *
     * @param array<string> $names Noms des cookies à supprimer
     */
    public function removeMany(array $names): static
    {
        $new = clone $this;

        foreach ($names as $name) {
            $name = mb_strtolower($name);
            foreach ($new->cookies as $id => $cookie) {
                if (mb_strtolower($cookie->getName()) === $name) {
                    unset($new->cookies[$id]);
                }
            }
        }

        return $new;
    }

    /**
     * Filtre la collection en gardant seulement les cookies valides pour un contexte donné
     *
     * @param string $scheme Protocole (http/https)
     * @param string $host Hôte de destination
     * @param string $path Chemin de destination
     */
    public function filter(string $scheme, string $host, string $path): static
    {
        $matchingCookies = $this->findMatchingCookies($scheme, $host, $path);
        $cookies = [];

        foreach ($matchingCookies as $name => $value) {
            $cookie = $this->find($name);
            if ($cookie !== null) {
                $cookies[] = $cookie;
            }
        }

        return new static($cookies);
    }

    /**
     * Vérifie que tous les éléments sont des instances valides de CookieInterface
     *
     * @param list<CookieInterface> $cookies Cookies à valider
     *
     * @throws InvalidArgumentException
     */
    protected function validateCookies(array $cookies): void
    {
        foreach ($cookies as $index => $cookie) {
            if (!$cookie instanceof CookieInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Attendu un tableau de CookieInterface, mais reçu "%s" à l\'index %d',
                        Helpers::typeName($cookie),
                        $index
                    )
                );
            }
        }
    }

    /**
     * Retourne un itérateur pour parcourir la collection
     *
     * @return Traversable<string, CookieInterface>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->cookies);
    }

    /**
     * Retourne tous les cookies sous forme de tableau
     *
     * @return array<string, CookieInterface>
     */
    public function toArray(): array
    {
        return $this->cookies;
    }

    /**
     * Retourne les noms de tous les cookies dans la collection
     *
     * @return array<string>
     */
    public function getNames(): array
    {
        return array_unique(
            array_map(
                fn (CookieInterface $cookie) => $cookie->getName(),
                $this->cookies
            )
        );
    }

    /**
     * Ajoute les cookies appropriés à une requête HTTP
     *
     * @param array<string, string> $extraCookies Cookies supplémentaires à ajouter
     */
    public function addToRequest(RequestInterface $request, array $extraCookies = []): RequestInterface
    {
        $uri     = $request->getUri();
        $cookies = $this->findMatchingCookies(
            $uri->getScheme(),
            $uri->getHost(),
            $uri->getPath() ?: '/'
        );

        $cookies = $extraCookies + $cookies;

        if (empty($cookies)) {
            return $request;
        }

        $cookiePairs = $this->buildCookieHeader($cookies);

        return $request->withHeader('Cookie', implode('; ', $cookiePairs));
    }

    /**
     * Construit les paires cookie=valeur pour l'en-tête HTTP
     *
     * @param array<string, string> $cookies Cookies à inclure
     *
     * @return array<string>
     */
    protected function buildCookieHeader(array $cookies): array
    {
        $cookiePairs = [];

        foreach ($cookies as $name => $value) {
            $cookie = sprintf('%s=%s', rawurlencode($name), rawurlencode($value));

            // Vérification de la longueur du cookie (RFC 6265)
            if (strlen($cookie) > 4096) {
                Helpers::triggerWarning(sprintf(
                    'Le cookie "%s" dépasse la longueur maximale recommandée de 4096 octets',
                    $name
                ));
            }

            $cookiePairs[] = $cookie;
        }

        return $cookiePairs;
    }

    /**
     * Trouve les cookies correspondant au schéma, hôte et chemin spécifiés
     *
     * @return array<string, string> Tableau associatif nom => valeur
     */
    protected function findMatchingCookies(string $scheme, string $host, string $path): array
    {
        $matches = [];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->cookies as $cookie) {
            if (!$this->isCookieMatching($cookie, $scheme, $host, $path, $now)) {
                continue;
            }

            $matches[$cookie->getName()] = $cookie->getScalarValue();
        }

        return $matches;
    }

    /**
     * Vérifie si un cookie correspond aux critères de la requête
     */
    protected function isCookieMatching(
        CookieInterface $cookie,
        string $scheme,
        string $host,
        string $path,
        DateTimeImmutable $now
    ): bool {
        // Vérification du protocole sécurisé
        if ($scheme === 'http' && $cookie->isSecure()) {
            return false;
        }

        // Vérification du chemin
        if (!$this->isPathMatching($cookie->getPath(), $path)) {
            return false;
        }

        // Vérification du domaine
        if (!$this->isDomainMatching($cookie->getDomain(), $host)) {
            return false;
        }

        // Vérification de l'expiration
        if ($cookie->isExpired($now)) {
            return false;
        }

        return true;
    }

    /**
     * Vérifie la correspondance du chemin
     */
    protected function isPathMatching(string $cookiePath, string $requestPath): bool
    {
        return str_starts_with($requestPath, $cookiePath);
    }

    /**
     * Vérifie la correspondance du domaine
     */
    protected function isDomainMatching(string $cookieDomain, string $requestHost): bool
    {
        // Domaine exact
        if ($cookieDomain === $requestHost) {
            return true;
        }

        // Domaine avec sous-domaines
        if (str_starts_with($cookieDomain, '.')) {
            $domain = ltrim($cookieDomain, '.');
            $pattern = '/' . preg_quote($domain, '/') . '$/';

            return (bool) preg_match($pattern, $requestHost);
        }

        return false;
    }

    /**
     * Crée une nouvelle collection incluant les cookies d'une réponse HTTP
     */
    public function addFromResponse(ResponseInterface $response, RequestInterface $request): static
    {
        $uri  = $request->getUri();
        $host = $uri->getHost();
        $path = $uri->getPath() ?: '/';

        $responseCookies = static::createFromHeader(
            $response->getHeader('Set-Cookie'),
            ['domain' => $host, 'path' => $path]
        );

        $new = clone $this;

        // Ajoute ou remplace les cookies de la réponse
        foreach ($responseCookies as $cookie) {
            $new->cookies[$cookie->getId()] = $cookie;
        }

        // Supprime les cookies expirés
        $new->removeExpiredCookies($host, $path);

        return $new;
    }

    /**
     * Supprime les cookies expirés de la collection
     */
    protected function removeExpiredCookies(string $host, string $path): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->cookies as $id => $cookie) {
            if (!$cookie->isExpired($now)) {
                continue;
            }

            // Vérifie que le cookie appartient au domaine/path actuel
            if ($this->isDomainMatching($cookie->getDomain(), $host) &&
                $this->isPathMatching($cookie->getPath(), $path)) {
                unset($this->cookies[$id]);
            }
        }
    }

    /**
     * Fusionne cette collection avec une autre
     */
    public function merge(self $other): static
    {
        $new = clone $this;

        foreach ($other->cookies as $id => $cookie) {
            $new->cookies[$id] = $cookie;
        }

        return $new;
    }

    /**
     * Crée une nouvelle collection avec seulement les cookies non expirés
     */
    public function withoutExpired(): static
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $validCookies = [];

        foreach ($this->cookies as $cookie) {
            if (!$cookie->isExpired($now)) {
                $validCookies[] = $cookie;
            }
        }

        return new static($validCookies);
    }

    /**
     * Vérifie si la collection contient des cookies expirés
     */
    public function hasExpired(): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->cookies as $cookie) {
            if ($cookie->isExpired($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retourne une représentation sous forme de chaîne de la collection
     */
    public function __toString(): string
    {
        $names = $this->getNames();

        return sprintf('CookieCollection(%d cookies: %s)', count($this), implode(', ', $names));
    }
}
