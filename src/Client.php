<?php
/**
 * Created by PhpStorm.
 * User: joost
 * Date: 17-01-16
 * Time: 18:09
 */

namespace Chat;

use React\EventLoop\LoopInterface;
use React\Socket\Connection;

class Client extends Connection
{
    protected $isAuthenticated = false;
    protected $register = false;
    protected $password;
    protected $addedToClients = false;
    protected $nickname;
    protected $database;
    public $connection;

    public function __construct($connection, LoopInterface $loop,  DatabaseAdapter $database)
    {
        $this->connection = $connection;
        $this->database = $database;

        parent::__construct($connection->stream, $loop);
    }

    /**
     * Authenticate the current client
     */
    public function authenticate()
    {
        $connection = $this->connection;
        $this->write('Please login by entering your unique nickname:'.PHP_EOL);
        $connection->on('data', $func = function ($data) use (&$connection, &$func)
        {
            $data = trim($data);

            // If the client has a nickname
            if ( !empty($this->getNickname()) ) {

                // Set its password
                $this->setPassword($data);

                // Get the password from the database
                $result = $this->database->fetch("
                      SELECT  password
                      FROM    users
                      WHERE   nickname    = :nickname
                      LIMIT 1
                 ", array('nickname' => $this->nickname)
                );

                // If no record was found
                if ($result == false){

                    // Check if the client has to be registered
                    if ($this->getRegister() == true) {

                        // Create the user and set the password
                        $result = $this->database->query("
                            INSERT INTO users (
                                nickname, password
                            )
                            VALUES
                            ( :nickname, :password_hash )
                        ",
                            array(
                                'nickname' => $this->nickname,
                                'password_hash' => password_hash($this->password, PASSWORD_DEFAULT)
                            )
                        );

                        // If the insertion was successful
                        if ($result == true) {
                            $this->setIsAuthenticated(true);
                            $connection->removeListener('data', $func);
                        }
                    }
                } else {
                    // a record is found, verify the password
                    if (password_verify($this->getPassword(), $result['password'])) {
                        $this->setIsAuthenticated(true);
                        $connection->removeListener('data', $func);
                    } else {
                        $this->write('Invalid nickname password, try again: ' . PHP_EOL);
                    }
                }
            }
            else {

                if ($this->validateNickname($data)) {
                    $this->setNickname($data);
                    $result = $this->database->fetch("
                      SELECT  nickname
                      FROM    users
                      WHERE   nickname    = :nickname
                      LIMIT 1
                    ", array('nickname' => $this->nickname)
                    );

                    // Nickname exists, ask for the password
                    if (count($result) == 1){
                        $this->write('Password:' . PHP_EOL);
                    } else {
                        // New nickname, ask to register it
                        $this->setRegister(true);
                        $this->write('This is a new nickname, please enter a password to register: ' . PHP_EOL);
                    }
                }
            }
        });
    }

    public function write($data)
    {
        return $this->connection->write($data);
    }

    /**
     * @return mixed
     */
    public function getIsAuthenticated()
    {
        return $this->isAuthenticated;
    }

    /**
     * @param mixed $authenticated
     */
    public function setIsAuthenticated($isAuthenticated)
    {
        $this->isAuthenticated = $isAuthenticated;
    }

    /**
     * @return mixed
     */
    public function getRegister()
    {
        return $this->register;
    }

    /**
     * @param mixed $register
     */
    public function setRegister($register)
    {
        $this->register = $register;
    }

    /**
     * @return mixed
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param mixed $password
     */
    public function setPassword($password)
    {
        $this->password = $password;
    }

    /**
     * @return mixed
     */
    public function getNickname()
    {
        return $this->nickname;
    }

    /**
     * @param mixed $nickname
     */
    public function setNickname($nickname)
    {
        $this->nickname = $nickname;
    }

    /**
     * Validate a certain nickname
     *
     * @param $nickname
     * @return bool
     */
    private function validateNickname($nickname)
    {
        return strlen($nickname) >= 3 && strlen($nickname) <= 15;
    }

    /**
     * @return boolean
     */
    public function isAddedToClients()
    {
        return $this->addedToClients;
    }

    /**
     * @param boolean $addedToClients
     */
    public function setAddedToClients($addedToClients)
    {
        $this->addedToClients = $addedToClients;
    }
}