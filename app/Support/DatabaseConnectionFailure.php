<?php

namespace App\Support;

use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

final class DatabaseConnectionFailure
{
    /**
     * True when the throwable is a database connectivity / bootstrap failure
     * (unreachable host, auth failure, connect timeout) rather than a query bug.
     */
    public static function matches(Throwable $e): bool
    {
        if (self::messageLooksLikeConnectionFailure($e->getMessage())) {
            return true;
        }

        if ($e instanceof QueryException || $e instanceof PDOException) {
            return app(LostConnectionDetector::class)->causedByLostConnection($e)
                || self::sqlStateIsConnectionFailure($e);
        }

        if ($e instanceof FatalError && Str::contains($e->getMessage(), [
            'Maximum execution time',
            'Allowed memory size',
        ]) && Str::contains($e->getFile(), ['Connectors'.DIRECTORY_SEPARATOR.'Connector.php', 'Connectors/Connector.php', 'Database'.DIRECTORY_SEPARATOR.'Connection.php', 'Database/Connection.php'])) {
            return true;
        }

        $previous = $e->getPrevious();

        return $previous instanceof Throwable && self::matches($previous);
    }

    private static function sqlStateIsConnectionFailure(Throwable $e): bool
    {
        $sqlState = null;

        if ($e instanceof PDOException && isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
            $sqlState = $e->errorInfo[0];
        } elseif ($e instanceof QueryException) {
            $sqlState = $e->errorInfo[0] ?? null;
        }

        return is_string($sqlState) && Str::startsWith($sqlState, '08');
    }

    private static function messageLooksLikeConnectionFailure(string $message): bool
    {
        return Str::contains($message, [
            'SQLSTATE[08006]',
            'SQLSTATE[08001]',
            'SQLSTATE[HY000] [7]',
            'connection to server at',
            'could not connect to server',
            'Connection timed out',
            'timeout expired',
            'Connection refused',
            'could not translate host name',
            'Name or service not known',
            'No such file or directory',
            'password authentication failed',
            'fe_sendauth: no password supplied',
            'remaining connection slots are reserved',
            'too many connections',
            'the database system is starting up',
            'the database system is shutting down',
        ]);
    }
}
