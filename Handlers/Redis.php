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

use BlitzPHP\Session\SessionException;
use BlitzPHP\Utilities\DateTime\Date;
use Redis as BaseRedis;
use RedisException;

/**
 * Gestionnaire de session utilisant Redis pour la persistance
 */
class Redis extends BaseHandler
{
    private const DEFAULT_PORT     = 6379;
    private const DEFAULT_PROTOCOL = 'tcp';

    /**
     * phpRedis instance
     */
    protected ?BaseRedis $redis = null;

    /**
     * cle de verouillage
     */
    protected ?string $lockKey = null;

    /**
     * Drapeau pour les cles existantes
     */
    protected bool $keyExists = false;

    /**
     * Time (microseconds) to wait if lock cannot be acquired.
     */
    private int $lockRetryInterval = 100_000;

    /**
     * Maximum number of lock acquisition attempts.
     */
    private int $lockMaxRetries = 300;

    /**
     * {@inheritDoc}s
     *
     * @throws SessionException
     */
    public function init(array $config, string $ipAddress): bool
    {
        parent::init($config, $ipAddress);
		$config = (object) $config;

		$this->sessionExpiration = ($config->expiration === 0)
            ? (int) ini_get('session.gc_maxlifetime')
            : $config->expiration;

        // Ajouter un nom de cookie de session pour plusieurs cookies de session.
        $this->keyPrefix .= $this->cookieName . ':';

        $this->setSavePath();

        if ($this->matchIP === true) {
            $this->keyPrefix .= $this->ipAddress . ':';
        }

		$this->lockRetryInterval = $config->lock_wait ?? $this->lockRetryInterval;
        $this->lockMaxRetries    = $config->lock_attempts ?? $this->lockMaxRetries;

        return true;
    }

    protected function setSavePath(): void
    {
        if (empty($this->savePath)) {
            throw SessionException::emptySavepath();
        }

        $url   = parse_url($this->savePath);
        $query = [];

        if ($url === false) {
            // Domaine socket Unix comme `unix:///var/run/redis/redis.sock?persistent=1`.
            if (preg_match('#unix://(/[^:?]+)(\?.+)?#', $this->savePath, $matches)) {
                $host = $matches[1];
                $port = 0;

                if (isset($matches[2])) {
                    parse_str(ltrim($matches[2], '?'), $query);
                }
            } else {
                throw SessionException::invalidSavePathFormat($this->savePath);
            }
        } else {
            // Accepte aussi `/var/run/redis.sock` pour des raisons de compatibilité.
            if (isset($url['path']) && $url['path'][0] === '/') {
                $host = $url['path'];
                $port = 0;
            } else {
                // Connexions TCP.
                if (! isset($url['host'])) {
                    throw SessionException::invalidSavePathFormat($this->savePath);
                }

                $protocol = $url['scheme'] ?? self::DEFAULT_PROTOCOL;
                $host     = $protocol . '://' . $url['host'];
                $port     = $url['port'] ?? self::DEFAULT_PORT;
            }

            if (isset($url['query'])) {
                parse_str($url['query'], $query);
            }
        }

        $password = $query['auth'] ?? null;
        $database = isset($query['database']) ? (int) $query['database'] : 0;
        $timeout  = isset($query['timeout']) ? (float) $query['timeout'] : 0.0;
        $prefix   = $query['prefix'] ?? null;

        $this->savePath = [
            'host'     => $host,
            'port'     => $port,
            'password' => $password,
            'database' => $database,
            'timeout'  => $timeout,
        ];

        if ($prefix !== null) {
            $this->keyPrefix = $prefix;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function open(string $path, string $name): bool
    {
        if (empty($this->savePath)) {
            return false;
        }

        $redis = new BaseRedis();

        if (! $redis->connect($this->savePath['host'], $this->savePath['port'], $this->savePath['timeout'])) {
            $this->logMessage("Session\u{a0}: Impossible de se connecter à Redis avec les paramètres configurés.");
        } elseif (isset($this->savePath['password']) && ! $redis->auth($this->savePath['password'])) {
            $this->logMessage("Session\u{a0}: impossible de s'authentifier auprès de l'instance Redis.");
        } elseif (isset($this->savePath['database']) && ! $redis->select($this->savePath['database'])) {
            $this->logMessage("Session\u{a0}: impossible de sélectionner la base de données Redis avec index " . $this->savePath['database']);
        } else {
            $this->redis = $redis;

            return true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $id): false|string
    {
        if (isset($this->redis) && $this->lockSession($id)) {
            if (! isset($this->sessionID)) {
                $this->sessionID = $id;
            }

            $data = $this->redis->get($this->keyPrefix . $id);

            if (is_string($data)) {
                $this->keyExists = true;
            } else {
                $data = '';
            }

            $this->fingerprint = md5($data);

            return $data;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $id, string $data): bool
    {
        if (! isset($this->redis)) {
            return false;
        }

        if ($this->sessionID !== $id) {
            if (! $this->releaseLock() || ! $this->lockSession($id)) {
                return false;
            }

            $this->keyExists = false;
            $this->sessionID = $id;
        }

        if (isset($this->lockKey)) {
            $this->redis->expire($this->lockKey, 300);

            if ($this->fingerprint !== ($fingerprint = md5($data)) || $this->keyExists === false) {
                if ($this->redis->set($this->keyPrefix . $id, $data, $this->sessionExpiration)) {
                    $this->fingerprint = $fingerprint;
                    $this->keyExists   = true;

                    return true;
                }

                return false;
            }

            return $this->redis->expire($this->keyPrefix . $id, $this->sessionExpiration);
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function close(): bool
    {
        if (isset($this->redis)) {
            try {
                $pingReply = $this->redis->ping();

                if (($pingReply === true) || ($pingReply === '+PONG')) {
                    if (isset($this->lockKey) && ! $this->releaseLock()) {
                        return false;
                    }

                    if (! $this->redis->close()) {
                        return false;
                    }
                }
            } catch (RedisException $e) {
                $this->logMessage("Session\u{a0}: RedisException obtenu sur close()\u{a0}: " . $e->getMessage());
            }

            $this->redis = null;

            return true;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(string $id): bool
    {
        if (isset($this->redis, $this->lockKey)) {
            if (($result = $this->redis->del($this->keyPrefix . $id)) !== 1) {
                $this->logMessage("Session\u{a0}: Redis :: del() devrait renvoyer 1, a obtenu " . var_export($result, true) . ' instead.', 'debug');
            }

            return $this->destroyCookie();
        }

        return false;
    }

    /**
     * Acquires an emulated lock.
     */
    protected function lockSession(string $sessionID): bool
    {
        $lockKey = $this->keyPrefix . $sessionID . ':lock';

        // PHP 7 réutilise l'objet SessionHandler lors de la régénération,
        // nous devons donc vérifier ici si la clé de verrouillage correspond à l'ID de session correct.
        if ($this->lockKey === $lockKey) {
            return $this->redis->expire($this->lockKey, 300);
        }

        $attempt = 0;

        do {
            $result = $this->redis->set(
                $lockKey,
                (string) Date::now()->getTimestamp(),
                // NX -- Only set the key if it does not already exist.
                // EX seconds -- Set the specified expire time, in seconds.
                ['nx', 'ex' => 300],
            );

            if (! $result) {
                usleep($this->lockRetryInterval);

                continue;
            }

            $this->lockKey = $lockKey;
            break;
        } while (++$attempt < $this->lockMaxRetries);

        if ($attempt === 300) {
            $this->logMessage('Session: Impossible d\'obtenir le verrou pour ' . $this->keyPrefix . $sessionID . ' après 300 tentatives, abandonné.');

            return false;
        }

        $this->lock = true;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function releaseLock(): bool
    {
        if (isset($this->redis, $this->lockKey) && $this->lock) {
            if (! $this->redis->del($this->lockKey)) {
                $this->logMessage("Session\u{a0}: erreur lors de la tentative de libération du verrou pour " . $this->lockKey);

                return false;
            }

            $this->lockKey = null;
            $this->lock    = false;
        }

        return true;
    }
}
