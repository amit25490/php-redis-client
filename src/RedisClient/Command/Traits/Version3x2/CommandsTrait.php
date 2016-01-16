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
namespace RedisClient\Command\Traits\Version3x2;

use RedisClient\Command\Traits\AbstractCommandsTrait;
use RedisClient\Command\Traits\Version3x0\ClusterCommandsTrait;
use RedisClient\Command\Traits\Version2x6\ConnectionCommandsTrait;
use RedisClient\Command\Traits\Version2x8\HyperLogLogCommandsTrait;
use RedisClient\Command\Traits\Version3x0\KeysCommandsTrait;
use RedisClient\Command\Traits\Version2x6\ListsCommandsTrait;
use RedisClient\Command\Traits\Version2x6\ScriptingCommandsTrait;
use RedisClient\Command\Traits\Version2x9\ServerCommandsTrait;
use RedisClient\Command\Traits\Version3x0\SortedSetsCommandsTrait;
use RedisClient\Command\Traits\Version2x8\StringsCommandsTrait;
use RedisClient\Command\Traits\Version2x6\TransactionsCommandsTrait;

trait CommandsTrait {

    use AbstractCommandsTrait;

    use ClusterCommandsTrait;
    use ConnectionCommandsTrait;
    use HashesCommandsTrait;
    use HyperLogLogCommandsTrait;
    use KeysCommandsTrait;
    use ListsCommandsTrait;
    use ScriptingCommandsTrait;
    use ServerCommandsTrait;
    use SetsCommandsTrait;
    use SortedSetsCommandsTrait;
    use StringsCommandsTrait;
    use TransactionsCommandsTrait;

    /**
     * @return string
     */
    public function getVersion() {
        return '3.2';
    }

}
