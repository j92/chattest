<?php
/**
 * Created by PhpStorm.
 * User: joost
 * Date: 15-01-16
 * Time: 10:11
 */

namespace Chat;

use PDO;

class DatabaseAdapter
{

    protected $connection;

    function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function fetchAll($sql, $parameters)
    {
        $sth = $this->connection->prepare($sql);
        $sth->execute($parameters);
        return $sth->fetchAll();
    }

    public function query($sql, $parameters)
    {
        return $this->connection->prepare($sql)->execute($parameters);
    }

    public function fetch($sql, $parameters)
    {
        $sth = $this->connection->prepare($sql);
        $sth->execute($parameters);
        return $sth->fetch();
    }
}