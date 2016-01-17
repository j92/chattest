<?php
/**
 * Created by PhpStorm.
 * User: joost
 * Date: 13-01-16
 * Time: 14:32
 */

namespace Chat\Commands;

use Chat\DatabaseAdapter;
use React\Socket\Server as ServerSocket;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class ServerStartCommand extends Command
{
    protected $server_socket;
    protected $loop;
    protected $clients;
    protected $output;
    private $host = '127.0.0.1';
    private $port;
    private $database;

    public function __construct(DatabaseAdapter $database, $loop, ServerSocket $socket)
    {
        $this->database      = $database;
        $this->loop          = $loop;
        $this->server_socket = $socket;
        $this->clients       = new \SplObjectStorage();

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('server:start')
            ->setDescription('Start the server')
            ->addArgument(
                'port',
                InputArgument::REQUIRED,
                'On which port should the server listen?'
            )
        ;
    }

    /**
     * Start the chat server
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Store the OutputInterface
        $this->output = $output;
        $this->port   = (int) $input->getArgument('port');

        // Configure the server socket
        $this->configureServerSocket();

        // Tell the socket to start listening
        $this->startServerSocket();

        // Start the loop
        $this->loop->run();
    }

    /**
     * Configure the server socket
     * @throws \React\Socket\ConnectionException
     */
    private function configureServerSocket()
    {
        // Handle incoming packages
        $this->onIncoming($this->server_socket);

        $this->output->writeln("<info>Socket server listening on port {$this->port}.</info>");
        $this->output->writeln("<info>You can connect to it by running: telnet {$this->host} {$this->port}</info>");

        $this->logToFile('Server configured on ' . $this->host . ' and port ' . $this->port );
    }

    /**
     * Tell the server socket to start listening
     * @throws \React\Socket\ConnectionException
     */
    private function startServerSocket()
    {
        $this->logToFile('Server started listening on ' . $this->host . ' and port ' . $this->port );

        $this->server_socket->listen($this->port, $this->host);
    }

    /**
     * Handles the incoming packages
     *
     * @param ServerSocket $server
     */
    private function onIncoming(ServerSocket $server)
    {
        $server->on('connection', function ($client) {

            if (!$client->authenticated) {
                $this->authenticate($client);
            }

            // Handle incoming data from existing connections
            $client->on('data', function ($data) use ($client) {
                if ($client->authenticated) {
                    $this->processUserInput($data, $client);
                }
            });

            // Handling the end of connections
            $client->on('end', function () use ($client) {
                $this->clients->detach($client);
            });
        });
    }

    /*
     * Process the user input
     *
     */
    private function processUserInput($data, $client)
    {
        $data = trim($data);

        $command = $this->isCommand($data);

        if (!$command) {
            $this->sendMessageToClients($data, $client);
        }

        $this->processCommand($data, $client, $command);
    }


    private function authenticate($client)
    {
        $client->write('Please login by entering your unique nickname:'.PHP_EOL);
        $client->on('data', $func = function ($data) use (&$client, &$func)
        {
            $data = trim($data);

            if (isset($client->nickname)) {

                $client->password = $data;

                // authenticate this client
                $result = $this->database->fetch("
                      SELECT  password
                      FROM    users
                      WHERE   nickname    = :nickname
                      LIMIT 1
                 ", array('nickname' => $client->nickname)
                );

                if ($result == false){

                    if ($client->register == true) {
                        // Create the nickname and set the password
                        $result = $this->database->query("
                        INSERT INTO users (
                            nickname, password
                        )
                        VALUES
                        ( :nickname, :password_hash )
                        ",
                            array(
                                'nickname' => $client->nickname,
                                'password_hash' => password_hash($client->password, PASSWORD_DEFAULT)
                            )
                        );
                        var_dump($result);
                        if ($result == true) {
                            $client->authenticated = true;
                            $client->removeListener('data', $func);
                            $this->handleNewClient($client);
                        }
                    }
                } else {
                    if (password_verify($client->password, $result['password'])) {
                        $client->authenticated = true;
                        $client->removeListener('data', $func);
                        $this->handleNewClient($client);

                    } else {
                        $client->write('Invalid nickname password, try again: ' . PHP_EOL);
                    }
                }
            }
            else {
                // Validate nickname
                if (strlen($data) >= 3 && strlen($data) <= 15) {
                    $client->nickname = strtolower($data);
                    $result = $this->database->fetch("
                          SELECT  nickname
                          FROM    users
                          WHERE   nickname    = :nickname
                          LIMIT 1
                     ", array('nickname' => $client->nickname)
                    );

                    if ($result !== false){
                        $client->write('Password:' . PHP_EOL);
                    } else {
                        // new
                        $client->register = true;
                        $client->write('This is a new nickname, please enter a password to register: ' . PHP_EOL);
                    }

                }
            }
        });
    }

    /*
     * Check if user input is a system command
     */
    private function isCommand($data)
    {
        $commands = array('quit', 'nick');

        foreach ($commands as $command) {
            if (substr($data, 1, strlen($command)) === $command) {
                return $command;
            }
        }

        return false;
    }

    /**
     * Execute a given command for a specific client
     *
     * @param $data
     * @param $client
     * @param $command
     */
    private function processCommand($data, $client, $command)
    {
        if (empty($command)) {
            return;
        }

        // Log the command
        $this->logToFile('Processing command ' . strtoupper($command) . ' for client with IP ' . $client->getRemoteAddress() );

        switch ($command) {
            case 'quit':
                $this->quit($client, 'Bye!');
                break;
            case 'nick':
                $client->write('You want to change your nickname, good idea');
                break;
        }
    }

    /**
     * Sends a message to all clients except the sending client
     *
     * @param $msg
     * @param $current
     */
    private function sendMessageToClients($msg, $current)
    {
        foreach ($this->clients as $client) {

            // Dont write to the current client, as he is sending the message
            if ($current === $client) {
                continue;
            }

            $client->write($current->nickname . '> ' . $msg . PHP_EOL);
        }
    }

    private function handleNewClient($client)
    {
        // Add client to the list
        $this->clients->attach($client);

        $this->sendWelcomeMessage($client, 'Welcome to our chat '.$client->nickname.'. The current amount of connections is ' . count($this->clients) . PHP_EOL);

    }

    /**
     * Send a welcome message to a given connection
     *
     * @param $client
     */
    private function sendWelcomeMessage($client, $msg)
    {
        // Log the new connection
        $this->logToFile('New connection from client with IP ' . $client->getRemoteAddress() . '. Welcome message send');

        $client->write($msg);
    }

    /**
     * Handles the quit command, closes the connection
     *
     * @param $conn
     * @param $msg
     */
    private function quit($client, $msg)
    {
        // Log the closing connection
        $this->logToFile('Closing connection from client with IP ' . $client->getRemoteAddress() . '');

        $client->write($msg);
        $client->close();
    }

    private function logToFile($msg = '', $type = 'INFO')
    {
        // open file
        $fd = fopen('log.php', "a");

        // write string
        fwrite($fd, date("Y-m-d H:i:s") . " " . trim($type) . ": " . trim($msg) . "\n");

        // close file
        fclose($fd);
    }
}