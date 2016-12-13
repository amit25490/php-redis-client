<?php
/**
 * This file is part of RedisClient.
 * git: https://github.com/cheprasov/php-redis-client
 *
 * (C) Alexander Cheprasov <cheprasov.84@ya.ru>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace RedisClient\Client;

use RedisClient\Cluster\ClusterMap;
use RedisClient\Command\Response\ResponseParser;
use RedisClient\Exception\AskResponseException;
use RedisClient\Exception\ErrorResponseException;
use RedisClient\Exception\MovedResponseException;
use RedisClient\Pipeline\Pipeline;
use RedisClient\Pipeline\PipelineInterface;
use RedisClient\Protocol\ProtocolFactory;
use RedisClient\Protocol\ProtocolInterface;
use RedisClient\RedisClient;

abstract class AbstractRedisClient {

    const VERSION = '1.6.0';

    const CONFIG_SERVER   = 'server';
    const CONFIG_TIMEOUT  = 'timeout';
    const CONFIG_DATABASE = 'database';
    const CONFIG_PASSWORD = 'password';
    const CONFIG_CLUSTER  = 'cluster';
    const CONFIG_VERSION  = 'version';

    /**
     * Default configuration
     * @var array
     */
    protected static $defaultConfig = [
        self::CONFIG_SERVER => '127.0.0.1:6379', // or tcp://127.0.0.1:6379 or 'unix:///tmp/redis.sock'
        self::CONFIG_TIMEOUT => 1, // in seconds
        self::CONFIG_DATABASE => 0, // default db
        self::CONFIG_CLUSTER => [
            'enabled' => false,
            'clusters' => [],
            'init_on_start' => false,
            'init_on_error' => false,
        ],
    ];

    /**
     * @var ProtocolInterface
     */
    protected $Protocol;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var ClusterMap
     */
    protected $ClusterMap;

    /**
     * @param array|null $config
     */
    public function __construct(array $config = null) {
        $this->setConfig($config);
    }

    /**
     * @param array|null $config
     */
    protected function setConfig(array $config = null) {
        $this->config = $config ? array_merge(static::$defaultConfig, $config) : static::$defaultConfig;
        if (!empty($this->config[self::CONFIG_CLUSTER]['enabled'])) {
            $ClusterMap = new ClusterMap(null, $this->config[self::CONFIG_TIMEOUT]);
            if (!empty($this->config[self::CONFIG_CLUSTER]['clusters'])) {
                $ClusterMap->setClusters($this->config[self::CONFIG_CLUSTER]['clusters']);
            }
            $this->ClusterMap = $ClusterMap;
        }
    }

    /**
     * @param string|null $param
     * @return mixed|null
     */
    protected function getConfig($param = null) {
        if (!$param) {
            return $this->config;
        }
        return isset($this->config[$param]) ? $this->config[$param] : null;
    }

    /**
     * @return ProtocolInterface
     */
    protected function getProtocol() {
        if (!$this->Protocol) {
            $this->Protocol = ProtocolFactory::createRedisProtocol(
                $this->getConfig(self::CONFIG_SERVER),
                $this->getConfig(self::CONFIG_TIMEOUT)
            );
            $this->onProtocolInit();
        }
        return $this->Protocol;
    }

    /**
     *
     */
    protected function onProtocolInit() {
        /** @var RedisClient $this */
        if ($password = $this->getConfig(self::CONFIG_PASSWORD)) {
            $this->auth($password);
        }
        if ($db = (int)$this->getConfig(self::CONFIG_DATABASE)) {
            $this->select($db);
        }
        if ($this->ClusterMap) {
            $conf = $this->getConfig(self::CONFIG_CLUSTER);
            if (!empty($conf['init_on_start'])) {
                $this->updateClusterSlots();
            }
        }
    }

    /**
     *
     */
    protected function updateClusterSlots() {
        /** @var RedisClient $this */
        $response = $this->clusterSlots();
        $clusters = ResponseParser::parseClusterSlots($response);
        $this->ClusterMap->setClusters($clusters);
    }

    /**
     * @inheritdoc
     */
    protected function returnCommand(array $command, $keys = null, array $params = null, $parserId = null) {
        return $this->executeCommand($command, $keys, $params, $parserId);
    }

    /**
     * @param array $command
     * @param null|string|string[] $command
     * @param array|null $params
     * @param int|null $parserId
     * @return mixed
     * @throws ErrorResponseException
     */
    protected function executeCommand(array $command, $keys, array $params = null, $parserId = null) {
        $Protocol = $this->getProtocol();
        $this->changeProtocolConnectionByKey($Protocol, $keys);
        $response = $this->executeProtocolCommand($Protocol, $command, $params);

        if ($response instanceof ErrorResponseException) {
            throw $response;
        }
        if (isset($parserId)) {
            return ResponseParser::parse($parserId, $response);
        }
        return $response;
    }

    /**
     * @param ProtocolInterface $Protocol
     * @param array $command
     * @param array|null $params
     * @return mixed
     * @throws ErrorResponseException
     */
    protected function executeProtocolCommand(ProtocolInterface $Protocol, array $command, array $params = null) {
        $response = $Protocol->send($this->getStructure($command, $params));

        if ($response instanceof ErrorResponseException && $this->ClusterMap) {
            if ($response instanceof MovedResponseException) {
                $conf = $this->getConfig(self::CONFIG_CLUSTER);
                if (!empty($conf['init_on_error'])) {
                    $this->updateClusterSlots();
                } else {
                    $this->ClusterMap->addCluster($response->getSlot(), $response->getServer());
                }
                $Connection = $this->ClusterMap->getConnectionByServer($response->getServer());
                $Protocol->setConnection($Connection);
                return $this->executeProtocolCommand($Protocol, $command, $params);
            }
            if ($response instanceof AskResponseException) {
                $TempRedisProtocol = ProtocolFactory::createRedisProtocol(
                    $response->getServer(),
                    $this->getConfig(self::CONFIG_TIMEOUT)
                );
                $TempRedisProtocol->send(['ASKING']);
                return $this->executeProtocolCommand($TempRedisProtocol, $command, $params);
            }
        }

        return $response;
    }

    /**
     * @param ProtocolInterface $Protocol
     * @param string|string[] $keys
     */
    protected function changeProtocolConnectionByKey(ProtocolInterface $Protocol, $keys) {
        if (isset($keys) && $this->ClusterMap) {
            $key = is_array($keys) ? $keys[0] : $keys;
            if ($Connection = $this->ClusterMap->getConnectionByKey($key)) {
                $Protocol->setConnection($Connection);
            }
        }
    }

    /**
     * @param PipelineInterface $Pipeline
     * @return mixed
     * @throws ErrorResponseException
     */
    protected function executePipeline(PipelineInterface $Pipeline) {
        $Protocol = $this->getProtocol();
        $this->changeProtocolConnectionByKey($Protocol, $Pipeline->getKeys());
        $responses = $this->getProtocol()->sendMulti($Pipeline->getStructure());
        if (is_object($responses)) {
            if ($responses instanceof ErrorResponseException) {
                throw $responses;
            }
        }
        return $Pipeline->parseResponse($responses);
    }

    /**
     * @inheritdoc
     */
    protected function subscribeCommand(array $subCommand, array $unsubCommand, array $params = null, $callback) {
        $Protocol = $this->getProtocol();
        $Protocol->subscribe($this->getStructure($subCommand, $params), $callback);
        return $this->executeProtocolCommand($Protocol, $unsubCommand, $params);
    }

    /**
     * @param string[] $command
     * @param array|null $params
     * @return string[]
     */
    protected function getStructure(array $command, array $params = null) {
        if (!isset($params)) {
            return $command;
        }
        foreach ($params as $param) {
            if (is_array($param)) {
                foreach($param as $p) {
                    $command[] = $p;
                }
            } else {
                $command[] = $param;
            }
        }
        return $command;
    }

    /**
     * @param null|Pipeline|\Closure $Pipeline
     * @return mixed|Pipeline
     * @throws \InvalidArgumentException
     */
    public function pipeline($Pipeline = null) {
        if (!$Pipeline) {
            return $this->createPipeline();
        }
        if ($Pipeline instanceof \Closure) {
            $Pipeline = $this->createPipeline($Pipeline);
        }
        if ($Pipeline instanceof PipelineInterface) {
            return $this->executePipeline($Pipeline);
        }
        throw new \InvalidArgumentException();
    }

    /**
     * @param \Closure|null $Pipeline
     * @return PipelineInterface
     */
    abstract protected function createPipeline(\Closure $Pipeline = null);

    /**
     * @param string[] $structure
     * @return mixed
     * @throws ErrorResponseException
     */
    public function executeRaw($structure) {
        $response = $this->getProtocol()->send($structure);
        if ($response instanceof ErrorResponseException) {
            throw $response;
        }
        return $response;
    }

    /**
     * Command will parsed by the client, before sent to server
     * @param string $command
     * @return mixed
     */
    public function executeRawString($command) {
        return $this->executeRaw($this->parseRawString($command));
    }

    /**
     * @param string $command
     * @return string[]
     */
    public function parseRawString($command) {
        $structure = [];
        $line = ''; $quotes = false;
        for ($i = 0, $length = strlen($command); $i <= $length; ++$i) {
            if ($i === $length) {
                if (isset($line[0])) {
                    $structure[] = $line;
                    $line = '';
                }
                break;
            }
            if ($command[$i] === '"' && $i && $command[$i - 1] !== '\\') {
                $quotes = !$quotes;
                if (!$quotes && !isset($line[0]) && $i + 1 === $length) {
                    $structure[] = $line;
                    $line = '';
                }
            } else if ($command[$i] === ' ' && !$quotes) {
                if (isset($command[$i + 1]) && trim($command[$i + 1])) {
                    if (count($structure) || isset($line[0])) {
                        $structure[] = $line;
                        $line = '';
                    }
                }
            } else {
                $line .= $command[$i];
            }
        }
        array_walk($structure, function(&$line) {
            $line = str_replace('\\"', '"', $line);
        });
        return $structure;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     * @throws \Exception
     */
    public function __call($name , array $arguments) {
        if ($method = $this->getMethodNameForReservedWord($name)) {
            return call_user_func_array([$this, $method], $arguments);
        }
        throw new \Exception('Call to undefined method '. static::class. '::'. $name);
    }

}
