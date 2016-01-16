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
namespace RedisClient;
use RedisClient\Client\Version\RedisClient3x2 as RedisClientLastVersion;
use RedisClient\Pipeline\Pipeline;
use RedisClient\Pipeline\PipelineInterface;


/**
 * Class RedisClient
 * @package RedisClient
 */
class RedisClient extends RedisClientLastVersion {

    /**
     * @param \Closure|null $Pipeline
     * @return PipelineInterface
     */
    protected function createPipeline(\Closure $Pipeline = null) {
        return new Pipeline($Pipeline);
    }

}
