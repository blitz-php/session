<?php

/**
 * This file is part of Blitz PHP framework.
 *
 * (c) 2022 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Session\Handlers;

use Psr\Log\LoggerAwareTrait;
use SessionHandlerInterface;

abstract class BaseHandler implements SessionHandlerInterface
{
    use LoggerAwareTrait;

    /**
     * L'empreinte Data.
     */
    protected string $fingerprint = '';

    /**
     * Verrouiller l'espace réservé.
	 *
	 * @var bool|string
     */
    protected $lock = false;

    /**
     * L'ID de la session courante
     */
    protected ?string $sessionID = null;

    /**
     * L'adresse IP de l'utilisateur.
     */
    protected string $ipAddress;

	/**
     * Prefixe de la cle de session (memcached, redis, database).
     */
    protected string $keyPrefix = 'blitz_session:';

    /**
     * Domaine des cookies.
     */
    protected string $cookieDomain = '';

    /**
     * Chemin des Cookies.
     */
    protected string $cookiePath = '/';

    /**
     * Cookie sécurisé ?
     */
    protected bool $cookieSecure = false;

    /**
     * Nom du cookie à utiliser.
     */
    protected string $cookieName = '';

	/**
	 * Nombre de secondes jusqu'à la fin de la session.
	 */
	protected int $sessionExpiration = 7200;

    /**
     * Faire correspondre les adresses IP pour les cookies ?
     */
    protected bool $matchIP = false;

    /**
     * Le "chemin d'enregistrement" de la session
     *
     * @var array|string
     */
    protected $savePath;

    /**
     * Initialiser le moteur de session
     *
     * Appelé automatiquement par le frontal de session. Fusionner la configuration d'exécution avec les valeurs par défaut
     * Avant utilisation.
     *
     * @param array<string, mixed> $config Tableau associatif de paramètres pour le moteur
     *
     * @return bool Vrai si le moteur a été initialisé avec succès, faux sinon
     */
    public function init(array $config, string $ipAddress): bool
    {
		$config = (object) $config;

		$this->ipAddress = $ipAddress;

		$this->cookieDomain = $config->cookie_domain;
		$this->cookiePath   = $config->cookie_path;
		$this->cookieSecure = $config->cookie_secure;

		$this->cookieName        = $config->cookie_name;
		$this->matchIP           = $config->match_ip;
		$this->savePath          = $config->save_path;
		$this->sessionExpiration = $config->expiration;

        return true;
    }

    /**
     * Méthode interne pour forcer la suppression d'un cookie par le client lorsque session_destroy() est appelée.
     */
    protected function destroyCookie(): bool
    {
        return setcookie($this->cookieName, '', [
            'expires'  => 1,
            'path'     => $this->cookiePath,
            'domain'   => $this->cookieDomain,
            'secure'   => $this->cookieSecure,
            'httponly' => true,
        ]);
    }

    /**
     * Une méthode factice permettant aux pilotes sans fonctionnalité de verrouillage
     * (bases de données autres que PostgreSQL et MySQL) d'agir comme s'ils acquéraient un verrou.
     */
    protected function lockSession(string $sessionID): bool
    {
        $this->lock = true;

        return true;
    }

    /**
     * Libère le verrou, le cas échéant.
     */
    protected function releaseLock(): bool
    {
        $this->lock = false;

        return true;
    }

    /**
     * Les pilotes autres que celui des "fichiers" n'utilisent pas (n'ont pas besoin d'utiliser)
     * le paramètre INI session.save_path, mais cela conduit à des messages d'erreur déroutants
     * émis par PHP lorsque open() ou write() échoue, car le message contient session.save_path ...
     *
     * Pour contourner le problème, les pilotes appellent cette méthode
     * afin que l'INI soit défini juste à temps pour que le message d'erreur soit correctement généré.
     */
    protected function fail(): bool
    {
        ini_set('session.save_path', $this->savePath);

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function gc(int $max_lifetime): false|int
    {
        return 1;
    }

    public function logMessage(string $message, $level = 'error')
    {
        if ($this->logger) {
            $this->logger->log($level, $message);
        }
    }
}
