<?php
/**
 * Created by PhpStorm.
 * User: joost
 * Date: 13-01-16
 * Time: 14:32
 */

namespace Chat\Commands;

use Chat\DatabaseAdapter;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Socket\Server as ServerSocket;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Chat\Client;


class ServerStartCommand extends Command
{
    protected $server_socket;
    protected $loop;
    protected $clients;
    protected $output;
    private $host = '127.0.0.1';
    private $port;
    private $database;

    public function __construct(DatabaseAdapter $database, LoopInterface $loop, ServerSocket $socket)
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
        // Set up the listeners for handling incoming packages
        $this->onIncoming($this->server_socket, $this->loop);

        $this->output->writeln("<info>Socket server listening on port {$this->port}.</info>");
        $this->output->writeln("<info>You can connect to it by running: telnet {$this->host} {$this->port}</info>");

        $this->logToFile('Server configured on ' . $this->host . ' and port ' . $this->port);
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
     * Set's up the listeners for handling incoming packages
     *
     * @param ServerSocket $server
     */
    private function onIncoming(ServerSocket $server, LoopInterface $loop)
    {
        $server->on('connection', function ($connection) use ($loop){

            $client = new Client($connection, $loop, $this->database);

            if (!$client->getIsAuthenticated()) {
                $client->authenticate();
            }

            // Handle incoming data from existing connections
            $connection->on('data', function ($data) use ($client) {
                if ($client->getIsAuthenticated()) {
                    if ($client->isAddedToClients()){
                        $this->processUserInput($data, $client);
                    } else {
                        $this->handleNewClient($client);
                    }
                }
            });

            // Handling the end of connections
            $connection->on('end', function () use ($client) {
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

        $this->processCommand($client, $command);
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
     * @param $client
     * @param $command
     */
    private function processCommand($client, $command)
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

            $client->write($current->getNickname() . '> ' . $msg . PHP_EOL);
        }
    }

    private function handleNewClient($client)
    {
        // Add client to the list
        $this->clients->attach($client);

        $client->setAddedToClients(true);

        $this->sendWelcomeMessage($client, 'Welcome to our chat '.$client->getNickname.'. The current amount of connections is ' . count($this->clients) . PHP_EOL);
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