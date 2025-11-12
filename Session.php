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

use BlitzPHP\Contracts\Database\ConnectionInterface;
use BlitzPHP\Contracts\Session\SessionInterface;
use BlitzPHP\Session\Cookie\Cookie;
use BlitzPHP\Session\Handlers\ArrayHandler;
use BlitzPHP\Session\Handlers\BaseHandler;
use BlitzPHP\Session\Handlers\Database;
use BlitzPHP\Session\Handlers\Database\MySQL;
use BlitzPHP\Session\Handlers\Database\Postgre;
use BlitzPHP\Session\Handlers\File;
use BlitzPHP\Session\Handlers\Memcached;
use BlitzPHP\Session\Handlers\Redis;
use BlitzPHP\Utilities\DateTime\Date;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use InvalidArgumentException;
use Psr\Log\LoggerAwareTrait;
use RuntimeException;

class Session implements SessionInterface
{
    use LoggerAwareTrait;

    /**
     * Un tableau mappant les schémas d'URL aux noms de classe de moteur de session complets.
     *
     * @var array<string, class-string>
     */
    protected static array $validHandlers = [
        'array'     => ArrayHandler::class,
        'file'      => File::class,
        'memcached' => Memcached::class,
        'redis'     => Redis::class,
        'database'  => Database::class,
        'postgre'   => Postgre::class,
        'mysql'     => MySQL::class,
    ];

    /**
     * La configuration de session par défaut est remplacée dans la plupart des adaptateurs. Ceux-ci sont
     * les clés communes à tous les adaptateurs. Si elle est remplacée, cette propriété n'est pas utilisée.
     *
     * - `cookie_name` @var string Nom du cookie à utiliser.
     * - `match_ip` @var bool Faire correspondre les adresses IP pour les cookies ?
     * - `save_path` @var array|string Le "chemin d'enregistrement" de la session varie entre
     * - `expiration` @var int Nombre de secondes jusqu'à la fin de la session.
     *
     * @var array<string, mixed>
     */
    protected array $config = [
        'save_path'           => [],
        'cookie_name'        => 'blitz_session',
        'match_ip'            => false,
        'expiration'         => 7200,
        'handler'            => 'file',
        'time_to_update'     => 300,
        'regenerate_destroy' => false,
    ];

    /**
     * Adapter a utiliser pour la session
     */
    private BaseHandler $adapter;

    /**
     * Instance de la connexion a la bd (uniquement pour les gestionnaires de session de type base de donnees)
     */
    private ?ConnectionInterface $db = null;

    /**
     * L'instance de cookie de session.
     */
    protected Cookie $cookie;

    /**
     * sid regex
     *
     * @var string
     */
    protected $sidRegexp;

    /**
     * Indique si la session a été démarrée
     */
    protected bool $started = false;

    /**
     * Constructeur
     */
    public function __construct(array $config, array $cookie, protected string $ipAddress)
    {
        $this->config = array_merge($this->config, $config);

		// Validation de la configuration
        $this->validateConfig();

        $this->config['cookie_name'] = $this->sanitizeCookieName($this->config['cookie_name']);

        $this->initializeCookie($cookie);
    }

    /**
     * Valide la configuration de la session
     *
     * @throws InvalidArgumentException
     */
    protected function validateConfig(): void
    {
        if ($this->config['expiration'] < 0) {
            throw new InvalidArgumentException('La durée d\'expiration de la session ne peut pas être négative');
        }

        if (empty($this->config['cookie_name'])) {
            throw new InvalidArgumentException('Le nom du cookie de session ne peut pas être vide');
        }
    }

    /**
     * Nettoie le nom du cookie
     */
    protected function sanitizeCookieName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '-', strtolower($name)));
    }

    /**
     * Initialise le cookie de session
     */
    protected function initializeCookie(array $cookie): void
    {
        $expires = $this->config['expiration'] === 0 ? 0 : Date::now()->getTimestamp() + $this->config['expiration'];

        $this->cookie = Cookie::create($this->config['cookie_name'], '', [
			'expires'  => $expires,
			'httponly' => true, // pour la sécurité
			'samesite' => $cookie['samesite'] ?? Cookie::SAMESITE_LAX,
		] + $cookie);
    }

    /**
     * Crée le gestionnaire de session approprié
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    protected function factory(): BaseHandler
    {
        if (isset($this->adapter)) {
            return $this->adapter;
        }

        $validHandlers = ($this->config['valid_handlers'] ?? []) + static::$validHandlers;

        if (empty($validHandlers) || !is_array($validHandlers)) {
            throw new InvalidArgumentException('La configuration de la session doit avoir un tableau de $valid_handlers.');
        }

        $handler = $this->config['handler'] ?? null;

        if (empty($handler)) {
            throw new InvalidArgumentException('La configuration de la session doit spécifier un gestionnaire.');
        }

        // Si le handler est une classe, on récupère sa clé
        if (in_array($handler, $validHandlers, true)) {
            $handler = array_search($handler, $validHandlers, true);
        }

        if (!array_key_exists($handler, $validHandlers)) {
            throw new InvalidArgumentException(sprintf(
                'Le gestionnaire de session "%s" n\'est pas valide.',
                $handler
            ));
        }

        $handlerClass = $validHandlers[$handler];

        if (!class_exists($handlerClass)) {
            throw new InvalidArgumentException(sprintf(
                'La classe du gestionnaire de session "%s" n\'existe pas.',
                $handlerClass
            ));
        }

        $adapter = new $handlerClass();

        if (!($adapter instanceof BaseHandler)) {
            throw new InvalidArgumentException(sprintf(
                'Le gestionnaire de session doit étendre %s.',
                BaseHandler::class
            ));
        }

		$initialized = $adapter->init([
			'cookie_domain' => $this->cookie->getDomain(),
			'cookie_path'   => $this->cookie->getPath(),
			'cookie_secure' => $this->cookie->isSecure(),
		] + $this->config, $this->ipAddress);

        if (! $initialized) {
            throw new RuntimeException(sprintf(
                'Le gestionnaire de session %s n\'est pas correctement configuré.',
                $handlerClass
            ));
        }

		if ($this->logger) {
			$adapter->setLogger($this->logger);
		}

        if ($adapter instanceof Database) {
            $adapter->setDatabase($this->db);
        }

        return $this->adapter = $adapter;
    }

    /**
     * Defini l'instance de la database a utiliser (pour les gestionnaire de session de type base de donnees)
     */
    public function setDatabase(?ConnectionInterface $db): static
    {
        $this->db = $db;

        return $this;
    }

    /**
     * Ajout d'un gestionnaire de session personnalisé
     */
    public function extend(array|string $name, ?string $handler = null): void
    {
        if (is_string($name)) {
            if (null === $handler) {
                throw new InvalidArgumentException(sprintf('Gestionnaire de session non specifié pour %s', $name));
            }

            $name = [$name => $handler];
        }

        foreach ($name as $key => $handler) {
            if (! is_a($handler, BaseHandler::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Le gestionnaire de session personnalisé %s doit utiliser %s comme classe de base.',
                    $handler,
                    BaseHandler::class
                ));
            }

            static::$validHandlers += [$key => $handler];
        }
    }

    /**
     * Initialisez le conteneur de session et démarre la session.
     */
    public function start(): ?self
    {
		 if ($this->started) {
			$this->logMessage('Session: La session a déjà été démarrée.', 'warning');

            return $this;
        }

        if (Helpers::isCli() && ! $this->onTest()) {
			$this->logMessage('Session: Initialisation en mode CLI annulée.', 'debug');

            return null;
        }

        if ((bool) ini_get('session.auto_start')) {
            $this->logMessage('Session: session.auto_start est activé dans php.ini. Abandon.', 'error');

            return null;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->logMessage('Session: Une session est déjà active.', 'warning');

            return null;
        }

        $this->configure();
        $this->setSaveHandler();
        $this->sanitizeSessionCookie();
        $this->startSession();

        $this->handleSessionRegeneration();
        $this->initVars();

		$this->started = true;
        $this->logMessage(
			sprintf("Session: Classe initialisée avec '%s'", Helpers::classBasename($this->factory())),
			'debug'
		);

        return $this;
    }

    /**
     * Nettoie le cookie de session si invalide
     */
    protected function sanitizeSessionCookie(): void
    {
        $cookieName = $this->config['cookie_name'];

        if (isset($_COOKIE[$cookieName]) &&
            (!is_string($_COOKIE[$cookieName]) || preg_match('#\A' . $this->sidRegexp . '\z#', $_COOKIE[$cookieName])) !== 1) {
            unset($_COOKIE[$cookieName]);
            $this->logMessage('Session: Cookie de session invalide détecté et supprimé.', 'warning');
        }
    }

    /**
     * Gère la régénération automatique de l'ID de session
     */
    protected function handleSessionRegeneration(): void
    {
        // Ne pas régénérer pour les requêtes AJAX
        if (Helpers::isAjaxRequest()) {
            return;
        }

        $regenerateTime = $this->config['time_to_update'] ?? 300;

        if ($regenerateTime > 0) {
            $now = Date::now()->getTimestamp();
            $lastRegenerate = $_SESSION['__blitz_last_regenerate'] ?? 0;

            if (($now - $lastRegenerate) >= $regenerateTime) {
                $this->regenerate((bool) $this->config['regenerate_destroy']);
            }
        }

        // Met à jour le cookie si nécessaire
        if (isset($_COOKIE[$this->config['cookie_name']]) && $_COOKIE[$this->config['cookie_name']] === session_id()) {
            $this->setCookie();
        }
    }

    /**
     * Detruit la session courante :
     *
	 * @deprecated 1.9 use destroy()
     */
    public function stop(): void
    {
        $this->destroy();
    }

    /**
     * {@inheritDoc}
     */
    public function regenerate(bool $destroy = false): void
    {
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION['__blitz_last_regenerate'] = Date::now()->getTimestamp();
			session_regenerate_id($destroy);
		}

        // $this->removeOldSessionCookie();
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(): bool
    {
        if ($this->onTest()) {
            return true;
        }

        return session_destroy();
    }

    /**
     * Écrit les données de la session et ferme la session en cours.
     */
    public function close(): void
    {
        if ($this->onTest()) {
            return;
        }

        session_write_close();
    }

    /**
     * {@inheritDoc}
     */
    public function set(array|string $key, mixed $value = null): void
    {
		$key = is_array($key) ? $key : [$key => $value];

		if (array_is_list($key)) {
            $key = array_fill_keys($key, null);
        }

        foreach ($key as $k => $v) {
            Arr::set($_SESSION, $k, $v);
        }
    }

    public function put(array|string $data, mixed $value = null): void
    {
        $this->set($data, $value);
    }

    /**
     * Obtenez toutes les données de la session.
     */
    public function all(): array
    {
        if (empty($_SESSION)) {
            return [];
        }

        return Arr::except($_SESSION, array_merge(['__blitz_vars'], $this->getFlashKeys(), $this->getTempKeys()));
    }

    /**
     * {@inheritDoc}
     */
    public function get(?string $key = null): mixed
    {
		if (empty($_SESSION)) {
            return $key === null ? [] : null;
        }

        if (! empty($key)) {
			if (null !== ($value = $_SESSION[$key] ?? null) || null !== ($value = Arr::getRecursive($_SESSION ?? [], $key))) {
				return $value;
			}

			return null;
        }

        return $this->all();
    }

    /**
     * {@inheritDoc}
     */
    public function has($key): bool
    {
		if (empty($_SESSION)) {
			return false;
		}

        $keys = is_array($key) ? $key : func_get_args();

		return Arr::has($_SESSION, $keys);
    }

    /**
     * Poussez la nouvelle valeur sur la valeur de session qui est un tableau.
     *
     * @param string $key  Identifiant de la propriété de session qui nous intéresse.
     * @param array<string, mixed> $data valeur à pousser vers la clé de session existante.
     */
    public function push(string $key, array $data): void
    {
        if ($this->has($key) && is_array($value = $this->get($key))) {
            $this->set($key, array_merge($value, $data));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function remove(array|string $key): void
    {
		$key = is_array($key) ? $key : [$key];

		foreach ($key as $k) {
			unset($_SESSION[$k]);
		}
    }

    /**
     * Méthode magique pour définir des variables dans la session en appelant simplement
     *  $session->foo = bar;
     */
    public function __set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Méthode magique pour obtenir des variables de session en appelant simplement
     * $foo = $session->foo ;
     */
    public function __get(string $key): mixed
    {
        // Remarque : Gardez cet ordre identique, juste au cas où quelqu'un voudrait utiliser 'session_id'
        // comme clé de données de session, pour quelque raison que ce soit
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        }

        if ($key === 'session_id') {
            return session_id();
        }

        return null;
    }

    /**
     * Méthode magique pour vérifier les variables de session.
     * Différent de has() en ce sens qu'il validera également 'session_id'.
     * Principalement utilisé par les fonctions PHP internes, les utilisateurs doivent s'en tenir à has()
     */
    public function __isset(string $key): bool
    {
        return isset($_SESSION[$key]) || $key === 'session_id';
    }

    /**
     * {@inheritDoc}
     */
    public function setFlashdata(array|string $data, array|bool|float|int|object|string|null $value = null): void
    {
        $this->set($data, $value);
        $this->markAsFlashdata(is_array($data) ? array_keys($data) : $data);
    }

    /**
     * {@inheritDoc}
     */
    public function getFlashdata(?string $key = null): mixed
    {
		$_SESSION['__blitz_vars'] ??= [];

        if (isset($key)) {
			if (! isset($_SESSION['__blitz_vars'][$key]) || is_int($_SESSION['__blitz_vars'][$key])) {
                return null;
            }

            return $_SESSION[$key] ?? null;
        }

        $flashdata = [];

        foreach ($_SESSION['__blitz_vars'] as $key => $value) {
			if (! is_int($value)) {
				$flashdata[$key] = $_SESSION[$key];
			}
		}

        return $flashdata;
    }

    /**
     * {@inheritDoc}
     */
    public function keepFlashdata(array|string $key): void
    {
        $this->markAsFlashdata($key);
    }

    /**
     * {@inheritDoc}
     */
    public function markAsFlashdata(array|string $key): bool
    {
		$keys = is_array($key) ? $key : [$key];

        foreach ($keys as $sessionKey) {
            if (! isset($_SESSION[$sessionKey])) {
                return false;
            }
        }

        $_SESSION['__blitz_vars'] ??= [];
        $_SESSION['__blitz_vars'] = [...$_SESSION['__blitz_vars'], ...array_fill_keys($keys, 'new')];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function unmarkFlashdata(array|string $key): void
    {
        if (! isset($_SESSION['__blitz_vars'])) {
            return;
        }

        if (! is_array($key)) {
            $key = [$key];
        }

        foreach ($key as $k) {
            if (isset($_SESSION['__blitz_vars'][$k]) && ! is_int($_SESSION['__blitz_vars'][$k])) {
                unset($_SESSION['__blitz_vars'][$k]);
            }
        }

        if ($_SESSION['__blitz_vars'] === []) {
            unset($_SESSION['__blitz_vars']);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getFlashKeys(): array
    {
        if (! isset($_SESSION['__blitz_vars'])) {
            return [];
        }

        $keys = [];

        foreach (array_keys($_SESSION['__blitz_vars']) as $key) {
            if (! is_int($_SESSION['__blitz_vars'][$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * {@inheritDoc}
     */
    public function setTempdata(array|string $data, mixed $value = null, int $ttl = 300): void
    {
        $this->set($data, $value);
        $this->markAsTempdata($data, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function getTempdata(?string $key = null)
    {
		$_SESSION['__blitz_vars'] ??= [];

        if (isset($key)) {
            if (! isset($_SESSION['__blitz_vars'][$key]) || ! is_int($_SESSION['__blitz_vars'][$key])) {
                return null;
            }

            return $_SESSION[$key] ?? null;
        }

        $tempdata = [];

        foreach ($_SESSION['__blitz_vars'] as $key => $value) {
            if (is_int($value)) {
                $tempdata[$key] = $_SESSION[$key];
            }
        }

        return $tempdata;
    }

    /**
     * {@inheritDoc}
     */
    public function removeTempdata(string $key): void
    {
        $this->unmarkTempdata($key);
        unset($_SESSION[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function markAsTempdata(array|string $key, int $ttl = 300): bool
    {
        $time = Date::now()->getTimestamp();
        $keys = is_array($key) ? $key : [$key];

		if (array_is_list($keys)) {
            $keys = array_fill_keys($keys, $ttl);
        }

        $tempdata = [];

        foreach ($keys as $sessionKey => $timeToLive) {
            if (! array_key_exists($sessionKey, $_SESSION)) {
                return false;
            }

            if (is_int($timeToLive)) {
                $timeToLive += $time;
            } else {
                $timeToLive = $time + $ttl;
            }

            $tempdata[$sessionKey] = $timeToLive;
        }

        $_SESSION['__blitz_vars'] ??= [];
        $_SESSION['__blitz_vars'] = [...$_SESSION['__blitz_vars'], ...$tempdata];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function unmarkTempdata(array|string $key): void
    {
        if (! isset($_SESSION['__blitz_vars'])) {
            return;
        }

        $key = is_array($key) ? $key : [$key];

        foreach ($key as $k) {
            if (isset($_SESSION['__blitz_vars'][$k]) && is_int($_SESSION['__blitz_vars'][$k])) {
                unset($_SESSION['__blitz_vars'][$k]);
            }
        }

        if ($_SESSION['__blitz_vars'] === []) {
            unset($_SESSION['__blitz_vars']);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getTempKeys(): array
    {
        if (! isset($_SESSION['__blitz_vars'])) {
            return [];
        }

        $keys = [];

        foreach (array_keys($_SESSION['__blitz_vars']) as $key) {
            if (is_int($_SESSION['__blitz_vars'][$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Définit le pilote comme gestionnaire de session en PHP.
     * Extrait pour faciliter les tests.
     */
    protected function setSaveHandler()
    {
        if ($this->onTest()) {
            return;
        }

        session_set_save_handler($this->factory(), true);
    }

    /**
     * Demarre la session
     * Extrait pour faciliter les tests.
     */
    protected function startSession()
    {
        if ($this->onTest()) {
            $_SESSION = [];

            return;
        }

        session_start(); // @codeCoverageIgnore
    }

    /**
     * Se charge de paramétrer le cookie côté client.
     *
     * @codeCoverageIgnore
     */
    protected function setCookie()
    {
        $expiration   = $this->config['expiration'] === 0 ? 0 : Date::now()->getTimestamp() + $this->config['expiration'];
        $this->cookie = $this->cookie->withValue(session_id())->withExpiry(Date::createFromTimestamp($expiration));
    }

    /**
     * Configuration.
     *
     * Gérer les liaisons d'entrée et les valeurs par défaut de configuration.
     */
    protected function configure()
    {
        if ($this->onTest()) {
            return;
        }

        ini_set('session.name', $this->config['cookie_name']);

        $sameSite = $this->cookie->getSameSite() ?: Cookie::SAMESITE_LAX;

        $params = [
            'lifetime' => $this->config['expiration'],
            'path'     => $this->cookie->getPath(),
            'domain'   => $this->cookie->getDomain(),
            'secure'   => $this->cookie->isSecure(),
            'httponly' => true, // HTTP uniquement ; Oui, c'est intentionnel et non configurable pour des raisons de sécurité.
            'samesite' => $sameSite,
        ];

        ini_set('session.cookie_samesite', $sameSite);
        session_set_cookie_params($params);

        if ($this->config['expiration'] > 0) {
            ini_set('session.gc_maxlifetime', (string) $this->config['expiration']);
        }

        if ($this->config['save_path'] !== '') {
            ini_set('session.save_path', $this->config['save_path']);
        }

        // La securite est le roi
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');

        $this->configureSidLength();
    }

    /**
     * Configurer la longueur de l'ID de session
     *
     * Pour vous faciliter la vie, nous imposons les paramètres par défaut de PHP. Parce que PHP9 les impose.
     * @see https://wiki.php.net/rfc/deprecations_php_8_4#sessionsid_length_and_sessionsid_bits_per_character
     */
    protected function configureSidLength()
    {
		 $bitsPerCharacter = (int) ini_get('session.sid_bits_per_character');
        $sidLength        = (int) ini_get('session.sid_length');

        // Nous forçons les valeurs par défaut de PHP.
        if (PHP_VERSION_ID < 90000) {
            if ($bitsPerCharacter !== 4) {
                ini_set('session.sid_bits_per_character', '4');
            }
            if ($sidLength !== 32) {
                ini_set('session.sid_length', '32');
            }
        }

        $this->sidRegexp = '[0-9a-f]{32}';
    }

    /**
     * Gérer les variables temporaires
     *
     * Efface les anciennes données "flash", marque la nouvelle pour la suppression et gère la suppression des données "temp".
     */
    protected function initVars(): void
    {
        if (! isset($_SESSION['__blitz_vars'])) {
            return;
        }

        $currentTime = Date::now()->getTimestamp();

        foreach ($_SESSION['__blitz_vars'] as $key => &$value) {
            if ($value === 'new') {
                $_SESSION['__blitz_vars'][$key] = 'old';
            }
            // NE le déplacez PAS au-dessus du contrôle de 'new' !
            elseif ($value === 'old' || $value < $currentTime) {
                unset($_SESSION[$key], $_SESSION['__blitz_vars'][$key]);
            }
        }

        if ($_SESSION['__blitz_vars'] === []) {
            unset($_SESSION['__blitz_vars']);
        }
    }

	public function logMessage(string $message, $level = 'error')
    {
        if ($this->logger) {
            $this->logger->log($level, $message);
        }
    }

    /**
     * Vérifie si on est en environnement de test
     */
    private function onTest(): bool
    {
        return (function_exists('on_test') && on_test()) ||
               (function_exists('is_cli') && is_cli() && !$this->isWebServerCli());
    }

    /**
     * Vérifie si c'est un serveur web en mode CLI (comme ReactPHP)
     */
    private function isWebServerCli(): bool
    {
        return isset($_SERVER['REQUEST_URI']) && PHP_SAPI === 'cli';
    }
}
