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

/**
 * Gestionnaire de session utilisant les fichiers pour la persistance
 */
class File extends BaseHandler
{

    /**
     * Dossier dans lequel nous devons stocker les fichiers de session
     *
     * @var string
     */
    protected $savePath;

    /**
     * Le descripteur de fichier
     *
     * @var resource|null
     */
    protected $fileHandle;

    /**
     * Nom de fichier
     */
    protected string $filePath = '';

    /**
     * S'il s'agit d'un nouveau fichier.
     */
    protected bool $fileNew = false;

    /**
     * Regex de l'ID de session
     */
    protected string $sessionIDRegex = '';

    /**
     * {@inheritDoc}
     */
    public function init(array $config, string $ipAddress): bool
    {
        parent::init($config, $ipAddress);

        if (! empty($this->savePath)) {
            $this->savePath = rtrim($this->savePath, '/\\');
            ini_set('session.save_path', $this->savePath);
        } else {
            $sessionPath = rtrim(ini_get('session.save_path'), '/\\');

            if ($sessionPath === '') {
                $sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blitz-php' . DIRECTORY_SEPARATOR . 'session';
            }

            $this->savePath = $sessionPath;
        }

        $this->configureSessionIDRegex();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function open(string $path, string $name): bool
    {
        if (! is_dir($path) && ! mkdir($path, 0o700, true)) {
            throw SessionException::invalidSavePath($this->savePath);
        }

        if (! is_writable($path)) {
            throw SessionException::writeProtectedSavePath($this->savePath);
        }

        $this->savePath = $path;

        // nous utiliserons le nom de la session comme préfixe pour éviter les collisions
        $this->filePath = $this->savePath . '/' . $name . ($this->matchIP ? md5($this->ipAddress) : '');

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $id): false|string
    {
        // Cela peut sembler bizarre, mais PHP 5.6 a introduit session_reset(), qui relit les données de session
        if ($this->fileHandle === null) {
            $this->fileNew = ! is_file($this->filePath . $id);

            if (($this->fileHandle = fopen($this->filePath . $id, 'c+b')) === false) {
                $this->logMessage("Session : Impossible d'ouvrir le fichier '" . $this->filePath . $id . "'.");

                return false;
            }

            if (flock($this->fileHandle, LOCK_EX) === false) {
                $this->logMessage("Session\u{a0}: impossible d'obtenir le verrou pour le fichier '" . $this->filePath . $id . "'.");
                fclose($this->fileHandle);
                $this->fileHandle = null;

                return false;
            }

            if (! isset($this->sessionID)) {
                $this->sessionID = $id;
            }

            if ($this->fileNew) {
                chmod($this->filePath . $id, 0o600);
                $this->fingerprint = md5('');

                return '';
            }
        } else {
            rewind($this->fileHandle);
        }

        $data   = '';
        $buffer = 0;
        clearstatcache();

        for ($read = 0, $length = filesize($this->filePath . $id); $read < $length; $read += strlen($buffer)) {
            if (($buffer = fread($this->fileHandle, $length - $read)) === false) {
                break;
            }

            $data .= $buffer;
        }

        $this->fingerprint = md5($data);

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function write(string $id, string $data): bool
    {
        // Si les deux identifiants ne correspondent pas, nous avons un appel session_regenerate_id()
        if ($id !== $this->sessionID) {
            $this->sessionID = $id;
        }

        if (! is_resource($this->fileHandle)) {
            return false;
        }

        if ($this->fingerprint === md5($data)) {
            return ($this->fileNew) ? true : touch($this->filePath . $id);
        }

        if (! $this->fileNew) {
            ftruncate($this->fileHandle, 0);
            rewind($this->fileHandle);
        }

        if (($length = strlen($data)) > 0) {
			$result  = null;
			$written = 0;

            for (; $written < $length; $written += $result) {
                if (($result = fwrite($this->fileHandle, substr($data, $written))) === false) {
                    break;
                }
            }

            if (! is_int($result)) {
                $this->fingerprint = md5(substr($data, 0, $written));
                $this->logMessage("Session\u{a0}: impossible d'écrire des données.");

                return false;
            }
        }

        $this->fingerprint = md5($data);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function close(): bool
    {
        if (is_resource($this->fileHandle)) {
            flock($this->fileHandle, LOCK_UN);
            fclose($this->fileHandle);

            $this->fileHandle = null;
            $this->fileNew    = false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function destroy(string $id): bool
    {
        if ($this->close()) {
            return is_file($this->filePath . $id)
                ? (unlink($this->filePath . $id) && $this->destroyCookie())
                : true;
        }

        if ($this->filePath !== null) {
            clearstatcache();

            return is_file($this->filePath . $id)
                ? (unlink($this->filePath . $id) && $this->destroyCookie())
                : true;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function gc(int $max_lifetime): false|int
    {
        if (! is_dir($this->savePath) || ($directory = opendir($this->savePath)) === false) {
            $this->logMessage("Session\u{a0}: le récupérateur de place n'a pas pu répertorier les fichiers dans le répertoire '" . $this->savePath . "'.", 'debug');

            return false;
        }

        $ts = Date::now()->getTimestamp() - $max_lifetime;

        $pattern = $this->matchIP === true ? '[0-9a-f]{32}' : '';

        $pattern = sprintf(
            '#\A%s' . $pattern . $this->sessionIDRegex . '\z#',
            preg_quote($this->cookieName, '#')
        );

        $collected = 0;

        while (($file = readdir($directory)) !== false) {
            // Si le nom du fichier ne correspond pas à ce modèle, ce n'est pas un fichier de session ou ce n'est pas le nôtre
            if (preg_match($pattern, $file) !== 1
                || ! is_file($this->savePath . DIRECTORY_SEPARATOR . $file)
                || ($mtime = filemtime($this->savePath . DIRECTORY_SEPARATOR . $file)) === false
                || $mtime > $ts
            ) {
                continue;
            }

            unlink($this->savePath . DIRECTORY_SEPARATOR . $file);
            $collected++;
        }

        closedir($directory);

        return $collected;
    }

    /**
     * Configurer l'expression régulière de l'ID de session.
	 *
	 * Pour vous faciliter la vie, nous imposons les paramètres par défaut de PHP. Parce que PHP9 les impose.
     * @see https://wiki.php.net/rfc/deprecations_php_8_4#sessionsid_length_and_sessionsid_bits_per_character
     */
    protected function configureSessionIDRegex()
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

        $this->sessionIDRegex = '[0-9a-f]{32}';
    }
}
