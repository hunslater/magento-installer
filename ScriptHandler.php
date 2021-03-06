<?php
/**
 * @author Manuele Menozzi <mmenozzi@webgriffe.com>
 */

namespace Webgriffe\MagentoInstaller;


use Composer\IO\IOInterface;
use Composer\Script\Event;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class ScriptHandler
{
    const DATABASE_CHARACTER_SET = 'utf8';
    const DATABASE_COLLATE = 'utf8_general_ci';

    private static $mysqlPdoWrapper;

    public static function installMagento(Event $event)
    {
        $options = $event->getComposer()->getPackage()->getExtra();
        $parametersFile = $options['install'];

        if (!file_exists($parametersFile)) {
            throw new FileNotFoundException($parametersFile);
        }

        $yml = Yaml::parse($parametersFile);
        $parameters = self::getInstallParameters($yml['parameters']);

        self::$mysqlPdoWrapper = new PdoWrapper();
        $dsn = sprintf('mysql:host=%s', $parameters['db_host']);
        self::$mysqlPdoWrapper->init($dsn, $parameters['db_user'], $parameters['db_pass']);
        $query = sprintf("SHOW DATABASES LIKE '%s';", $parameters['db_name']);
        $pdoStatement = self::$mysqlPdoWrapper->query($query);

        $io = $event->getIO();

        if ($pdoStatement->rowCount() > 0) {
            $io->write(sprintf('Database \'%s\' already exists, installation skipped.', $parameters['db_name']));
            return;
        }

        if (!self::askConfirmation($io, $parameters)) {
            return;
        }

        self::createMysqlDatabase($parameters);

        $command = static::getInstallCommand($parameters);
        self::executeCommand($command);
    }

    protected static function executeCommand($command)
    {
        $process = new Process(null);
        $process->setCommandLine($command);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) { echo $buffer; });
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('An error occurred while executing \'%s\'.', $command));
        }
    }

    private static function getInstallCommand(array $parameters)
    {
        $arguments = array();
        foreach ($parameters as $key => $value) {
            $arguments[] = sprintf('--%s "%s"', $key, $value);
        }

        $arguments = implode(' ', $arguments);
        return sprintf('php -f install.php -- %s', $arguments);
    }

    private static function getInstallParameters(array $parameters)
    {
        return array_merge(
            array(
                'license_agreement_accepted' => '1',
                'skip_url_validation' => '1',
                'use_rewrites' => '1',
                'use_secure' => '0',
                'use_secure_admin' => '0'
            ),
            $parameters,
            array(
                'secure_base_url' => $parameters['url']
            )
        );
    }

    /**
     * @param $parameters
     */
    private static function createMysqlDatabase(array $parameters)
    {
        $createDatabaseQuery = sprintf(
            'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s;',
            $parameters['db_name'],
            self::DATABASE_CHARACTER_SET,
            self::DATABASE_COLLATE
        );
        self::$mysqlPdoWrapper->query($createDatabaseQuery);
    }

    /**
     * @param IOInterface $io
     * @param $parameters
     * @return bool
     */
    private static function askConfirmation(IOInterface $io, $parameters)
    {
        $confirmation = $io->askConfirmation(
            sprintf(
                'Do you want to create MySQL database \'%s\' and install Magento on it [Y,n]?',
                $parameters['db_name']
            ),
            true
        );
        return $confirmation;
    }
}