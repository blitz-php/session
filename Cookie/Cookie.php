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

use BlitzPHP\Contracts\Session\CookieInterface;
use BlitzPHP\Utilities\Iterable\Arr;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Objet cookie pour créer un cookie et le transformer en valeur d'en-tête
 *
 * Un cookie HTTP est une petite donnée envoyée depuis un site Web et stockée
 * sur l'ordinateur de l'utilisateur par le navigateur Web pendant la navigation.
 *
 * Les objets cookies sont immuables - vous devez réaffecter les variables lors de la modification.
 *
 * ```
 * $cookie = $cookie->withValue('0');
 * ```
 *
 * @see https://tools.ietf.org/html/draft-ietf-httpbis-rfc6265bis-03
 * @see https://en.wikipedia.org/wiki/HTTP_cookie
 * @see \BlitzPHP\Session\Cookie\CookieCollection for working with collections of cookies.
 * @see \BlitzPHP\Http\Response::getCookieCollection() for working with response cookies.
 *
 * @credit <a href="https://api.cakephp.org/4.3/class-Cake.Http.Cookie.Cookie.html">CakePHP - \Cake\Http\Cookie\Cookie</a>
 */
class Cookie implements CookieInterface
{
    /**
     * Nom du cookie
     */
    protected string $name = '';

    /**
     * Valeur du cookie (peut être un tableau pour les données complexes)
     */
    protected array|string $value = '';

    /**
     * Indique si une valeur JSON a été développée dans un tableau.
     */
    protected bool $isExpanded = false;

    /**
     * Date d'expiration
     */
    protected ?DateTimeInterface $expiresAt = null;

    /**
     * Chemin
     */
    protected string $path = '/';

    /**
     * Domaine
     */
    protected string $domain = '';

    /**
     * Securisé ?
     */
    protected bool $secure = false;

    /**
     * Uniquement via HTTP ?
     */
    protected bool $httpOnly = false;

    /**
     * Politique SameSite
     */
    protected ?string $sameSite = null;

    /**
     * Attributs par défaut pour un cookie.
     *
     * @var array<string, mixed>
     *
     * @see \BlitzPHP\Session\Cookie\Cookie::setDefaults()
     */
    protected static $defaults = [
        'expires'  => null,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => false,
        'samesite' => null,
    ];

    /**
     * Constructeur
     *
     * @param string $name Nom du cookie
     * @param array|string|float|int|bool $value Valeur du cookie
     * @param DateTimeInterface|null $expiresAt Date d'expiration
     * @param string|null $path Chemin
     * @param string|null $domain Domaine
     * @param bool|null $secure Sécurisé
     * @param bool|null $httpOnly HTTP seulement
     * @param string|null $sameSite Politique SameSite
     */
    public function __construct(
        string $name,
        $value = '',
        ?DateTimeInterface $expiresAt = null,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
        ?string $sameSite = null
    ) {
        $this->validateName($name);
        $this->name = $name;

        $this->setValue($value);

        // Utilisation des valeurs par défaut si non spécifiées
        $this->path     = $path ?? static::$defaults['path'];
        $this->domain   = $domain ?? static::$defaults['domain'];
        $this->secure   = $secure ?? static::$defaults['secure'];
        $this->httpOnly = $httpOnly ?? static::$defaults['httponly'];

        // Validation SameSite
        if ($sameSite === null) {
            $this->sameSite = static::$defaults['samesite'];
        } else {
            $this->validateSameSiteValue($sameSite);
            $this->sameSite = $sameSite;
        }

        // Gestion du fuseau horaire pour l'expiration
        $this->expiresAt = $this->normalizeExpiration($expiresAt);
    }

    /**
     * Normalise la date d'expiration avec le fuseau horaire GMT
     */
    protected function normalizeExpiration(?DateTimeInterface $expiresAt): ?DateTimeInterface
    {
        if ($expiresAt === null) {
            return static::$defaults['expires'];
        }

        /** @var \DateTime $expiresAt Clonage pour éviter les effets de bord */
        $expiresAt = clone $expiresAt;

        return $expiresAt->setTimezone(new DateTimeZone('GMT'));
    }

	/**
     * Définit les options par défaut pour les cookies
     *
     * Les options valides sont :
     *
     * - `expires` : peut être un horodatage UNIX ou une chaîne compatible `strtotime()` ou une instance `DateTimeInterface` ou `null`.
     * - `path` : une chaîne de chemin. Par défaut `'/'`.
     * - `domain` : chaîne de nom de domaine. La valeur par défaut est ''''.
     * - `httponly` : booléen. La valeur par défaut est "false".
     * - `secure` : booléen. La valeur par défaut est "false".
     * - `samesite` : peut être l'un des éléments suivants : `CookieInterface::SAMESITE_LAX`, `CookieInterface::SAMESITE_STRICT`,
     *              `CookieInterface::SAMESITE_NONE` ou `null`. La valeur par défaut est `null`.
     */
    public static function setDefaults(array $options): void
    {
        if (isset($options['expires'])) {
            $options['expires'] = static::createDateTimeInstance($options['expires']);
        }
        if (isset($options['samesite'])) {
            static::validateSameSiteValue($options['samesite']);
        }

        static::$defaults = $options + static::$defaults;
    }

    /**
     * Méthode de fabrication pour créer des instances de Cookie
     *
     * @param string $name Nom du cookie
     * @param array|string|float|int|bool $value Valeur du cookie
     * @param array<string, mixed> $options Options du cookie
     */
    public static function create(string $name, $value, array $options = []): static
    {
		$options            += static::$defaults;
		$options['expires']  = static::createDateTimeInstance($options['expires']);

        return new static(
            $name,
            $value ?: '',
            $options['expires'],
            $options['path'],
            $options['domain'],
            $options['secure'],
            $options['httponly'],
            $options['samesite']
        );
    }

    /**
     * Crée une instance DateTimeInterface à partir de différentes représentations
	 */
    protected static function createDateTimeInstance(DateTimeInterface|string|int|null $expires): ?DateTimeInterface
    {
        if ($expires === null) {
            return null;
        }

        if ($expires instanceof DateTimeInterface) {
            return $expires->setTimezone(new DateTimeZone('GMT'));
        }

        // Conversion des chaînes de date
        if (! is_numeric($expires)) {
            $timestamp = strtotime($expires);
            $expires = $timestamp !== false ? $timestamp : null;
        }

        if ($expires !== null) {
            return new DateTimeImmutable('@' . $expires);
        }

        return null;
    }

    /**
     * Crée un cookie à partir d'une chaîne d'en-tête "Set-Cookie"
     *
	 * @param array<string, mixed> $defaults Attributs par defaut.
     */
    public static function createFromHeaderString(string $cookie, array $defaults = []): static
    {
        $parts = static::parseHeaderString($cookie);
        $nameValue = explode('=', (string)array_shift($parts), 2);

        $name = urldecode($nameValue[0] ?? '');
        $value = urldecode($nameValue[1] ?? '');

        $data = ['name'  => $name, 'value' => $value] + $defaults;

        // Traitement des attributs
        foreach ($parts as $part) {
            $attribute = static::parseCookieAttribute($part);
            $data = static::processCookieAttribute($data, $attribute);
        }

        return static::create($data['name'], $data['value'], $data);
    }

    /**
     * Parse la chaîne d'en-tête du cookie
     */
    protected static function parseHeaderString(string $cookie): array
    {
        if (str_contains($cookie, '";"')) {
            $cookie = str_replace('";"', '{__cookie_replace__}', $cookie);
            $parts  = str_replace('{__cookie_replace__}', '";"', explode(';', $cookie));
        } else {
            $parts = preg_split('/\;[ \t]*/', $cookie) ?: [];
        }

        return array_filter($parts);
    }

    /**
     * Parse un attribut de cookie
     */
    protected static function parseCookieAttribute(string $part): array
    {
        if (str_contains($part, '=')) {
            [$key, $value] = explode('=', $part, 2);
        } else {
            $key   = $part;
            $value = true;
        }

        return [
            'key'   => strtolower(trim($key)),
            'value' => trim($value)
        ];
    }

    /**
     * Traite un attribut de cookie
     */
    protected static function processCookieAttribute(array $data, array $attribute): array
    {
        switch ($attribute['key']) {
            case 'max-age':
                $data['expires'] = time() + (int)$attribute['value'];
                break;

            case 'samesite':
                if (in_array($attribute['value'], CookieInterface::SAMESITE_VALUES, true)) {
                    $data['samesite'] = $attribute['value'];
                }
                break;

            case 'expires':
            case 'path':
            case 'domain':
            case 'secure':
            case 'httponly':
                $data[$attribute['key']] = $attribute['value'];
                break;
        }

        return $data;
    }

    /**
     * Convertit le cookie en chaîne d'en-tête
     */
    public function toHeaderValue(): string
    {
        $headerValue = [
            sprintf('%s=%s', $this->name, rawurlencode($this->getScalarValue()))
        ];

        // Ajout des attributs conditionnels
        $attributes = [
            'expires'  => $this->expiresAt ? sprintf('expires=%s', $this->getFormattedExpires()) : null,
            'path'     => $this->path !== '' ? sprintf('path=%s', $this->path) : null,
            'domain'   => $this->domain !== '' ? sprintf('domain=%s', $this->domain) : null,
            'samesite' => $this->sameSite ? sprintf('samesite=%s', $this->sameSite) : null,
            'secure'   => $this->secure ? 'secure' : null,
            'httponly' => $this->httpOnly ? 'httponly' : null,
        ];

        $headerValue = array_merge($headerValue, array_filter($attributes));

        return implode('; ', $headerValue);
    }

    /**
     * {@inheritDoc}
     */
    public function withName(string $name): static
    {
        $this->validateName($name);
        $new       = clone $this;
        $new->name = $name;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getId(): string
    {
        return implode(';', [$this->name, $this->domain, $this->path]);
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Valide le nom du cookie
     *
     * @throws InvalidArgumentException
     *
     * @see https://tools.ietf.org/html/rfc2616#section-2.2 Rules for naming cookies.
     */
    protected function validateName(string $name): void
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Le nom du cookie ne peut pas être vide.');
        }

        if (preg_match("/[=,;\t\r\n\013\014]/", $name)) {
            throw new InvalidArgumentException(
                sprintf('Le nom du cookie "%s" contient des caractères invalides.', $name)
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getValue(): array|string
    {
        return $this->value;
    }

    /**
     * {@inheritDoc}
     */
    public function getScalarValue(): string
    {
        if ($this->isExpanded) {
            assert(is_array($this->value), '$value est un tableau');

            return $this->flattenValue($this->value);
        }

		assert(is_string($this->value), '$value est une chaine');

        return $this->value;
    }

    /**
     * {@inheritDoc}
     */
    public function withValue($value): static
    {
        $new = clone $this;
        $new->setValue($value);

        return $new;
    }

    /**
     * Définit la valeur du cookie.
     *
     * @param array|string|float|int|bool $value The value to store.
     */
    protected function setValue($value): void
    {
        $this->isExpanded = is_array($value);
        $this->value      = is_array($value) ? $value : (string)$value;
    }

    /**
     * {@inheritDoc}
     */
    public function withPath(string $path): static
    {
        $new       = clone $this;
        $new->path = $path;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * {@inheritDoc}
     */
    public function withDomain(string $domain): static
    {
        $new         = clone $this;
        $new->domain = $domain;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * {@inheritDoc}
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * {@inheritDoc}
     */
    public function withSecure(bool $secure): static
    {
        $new         = clone $this;
        $new->secure = $secure;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function withHttpOnly(bool $httpOnly): static
    {
        $new           = clone $this;
        $new->httpOnly = $httpOnly;

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    /**
     * {@inheritDoc}
     */
    public function withExpiry(DateTimeInterface $dateTime): static
    {
        if ($dateTime instanceof DateTime) {
            $dateTime = clone $dateTime;
        }

        $new            = clone $this;
        $new->expiresAt = $dateTime->setTimezone(new DateTimeZone('GMT'));

        return $new;
    }

    /**
     * Crée un nouveau cookie avec un nouveau délai d'expiration.
     */
    public function withExpires(mixed $expires): static
    {
        if (! $expires instanceof DateTimeInterface) {
            $expires = static::createDateTimeInstance($expires);
        }

        return $this->withExpiry($expires);
    }

    /**
     * {@inheritDoc}
     */
    public function getExpiry(): ?DateTimeInterface
    {
        return $this->expiresAt;
    }

    /**
     * {@inheritDoc}
     */
    public function getExpiresTimestamp(): ?int
    {
        if (! $this->expiresAt) {
            return null;
        }

        return (int) $this->expiresAt->format('U');
    }

    /**
     * {@inheritDoc}
     */
    public function getFormattedExpires(): string
    {
        if (! $this->expiresAt) {
            return '';
        }

        return $this->expiresAt->format(static::EXPIRES_FORMAT);
    }

    /**
     * {@inheritDoc}
     */
    public function isExpired(?DateTimeInterface $time = null): bool
    {
        $time = $time ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($time instanceof DateTime) {
            $time = clone $time;
        }

        if (!$this->expiresAt) {
            return false;
        }

        return $this->expiresAt < $time;
    }

    /**
     * {@inheritDoc}
     */
    public function withNeverExpire(): static
    {
        $new            = clone $this;
        $new->expiresAt = new DateTimeImmutable('2038-01-01');

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function withExpired(): static
    {
        $new            = clone $this;
        $new->expiresAt = new DateTimeImmutable('@1');

        return $new;
    }

    /**
     * {@inheritDoc}
     */
    public function getSameSite(): ?string
    {
        return $this->sameSite;
    }

    /**
     * {@inheritDoc}
     */
    public function withSameSite(?string $sameSite): static
    {
        if ($sameSite !== null) {
            $this->validateSameSiteValue($sameSite);
        }

        $new           = clone $this;
        $new->sameSite = $sameSite;

        return $new;
    }

    /**
     * Vérifiez que la valeur transmise pour SameSite est valide.
     *
     * @throws InvalidArgumentException
     */
    protected static function validateSameSiteValue(string $sameSite)
    {
        if (! in_array($sameSite, CookieInterface::SAMESITE_VALUES, true)) {
            throw new InvalidArgumentException(
                'Samesite value must be either of: ' . implode(', ', CookieInterface::SAMESITE_VALUES)
            );
        }
    }

    /**
     * Vérifie si une valeur existe dans les données du cookie.
     */
    public function check(string $path): bool
    {
        return Arr::check($this->getExpandedValue(), $path);
    }

    /**
     * Créer un nouveau cookie avec des données mises à jour.
     */
    public function withAddedValue(string $path, mixed $value): static
    {
        $new = clone $this;
        if ($new->isExpanded === false) {
            assert(is_string($new->value), '$value is not a string');
            $new->value = $new->expandValue($new->value);
        }

        assert(is_array($new->value), '$value is not an array');
        $new->value = Arr::insert($new->value, $path, $value);

        return $new;
    }

    /**
     * Créer un nouveau cookie sans chemin spécifique
     */
    public function withoutAddedValue(string $path): static
    {
        $new = clone $this;
        if ($new->isExpanded === false) {
            assert(is_string($new->value), '$value is not a array');
            $new->value = $new->expandValue($new->value);
        }

        assert(is_array($new->value), '$value is not a string');
        $new->value = Arr::remove($new->value, $path);

        return $new;
    }

    /**
     * Lire les données du cookie
     *
     * Cette méthode étendra les données complexes sérialisées,
     * à la première utilisation.
     */
    public function read(?string $path = null): mixed
    {
        $value = $this->getExpandedValue();

        if ($path === null) {
            return $value;
        }

        return Arr::get($value, $path);
    }

    /**
     * Vérifie si la valeur du cookie a été étendue
     */
    public function isExpanded(): bool
    {
        return $this->isExpanded;
    }

    /**
     * {@inheritDoc}
     */
    public function getOptions(): array
    {
        $options = [
            'expires'  => (int) $this->getExpiresTimestamp(),
            'path'     => $this->path,
            'domain'   => $this->domain,
            'secure'   => $this->secure,
            'httponly' => $this->httpOnly,
        ];

        if ($this->sameSite !== null) {
            $options['samesite'] = $this->sameSite;
        }

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'name'  => $this->name,
            'value' => $this->getScalarValue(),
        ] + $this->getOptions();
    }

    /**
     * Aplatit un tableau en chaîne JSON
     *
     * @throws JsonException
     */
    protected function flattenValue(array $array): string
    {
        return json_encode($array, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Récupère la valeur développée (étend le JSON si nécessaire)
     */
    protected function getExpandedValue(): array
    {
        if (!$this->isExpanded && is_string($this->value)) {
            $this->value = $this->expandValue($this->value);
        }

        return $this->value;
    }

    /**
     * Développe une chaîne en tableau
     */
    protected function expandValue(string $string)
    {
        $this->isExpanded = true;

        // Tentative de décodage JSON
        if ($this->isJson($string)) {
            $decoded = json_decode($string, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Fallback pour l'ancien format
        return $this->expandLegacyFormat($string);
    }

    /**
     * Vérifie si une chaîne est potentiellement du JSON
     */
    protected function isJson(string $string): bool
    {
        $firstChar = substr($string, 0, 1);
        return $firstChar === '{' || $firstChar === '[';
    }

    /**
     * Développe l'ancien format de sérialisation
     */
    protected function expandLegacyFormat(string $string)
    {
        $array = [];
        $pairs = explode(',', $string);

        foreach ($pairs as $pair) {
            $parts = explode('|', $pair);
            if (count($parts) === 1) {
                return $parts[0]; // Valeur simple
            }
            $array[$parts[0]] = $parts[1];
        }

        return $array;
    }
}
