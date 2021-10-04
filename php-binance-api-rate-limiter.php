<?php

/*
 * ============================================================
 * @package php-binance-api
 * @link https://github.com/jaggedsoft/php-binance-api
 * ============================================================
 * @copyright 2017-2021
 * @author Jon Eyrick
 * @license MIT License
 * ============================================================
 * A curl HTTP REST wrapper for the binance currency exchange
 */
namespace Binance;

// PHP version check
if (version_compare(phpversion(), '7.0', '<=')) {
    fwrite(STDERR, "Hi, PHP " . phpversion() . " support will be removed very soon as part of continued development.\n");
    fwrite(STDERR, "Please consider upgrading.\n");
}

/**
 * Wrapper/Decorator for the binance api, providing rate limiting
 *
 * Eg. Usage:
 * require 'vendor/autoload.php';
 * $api = new Binance\\API();
 * $api = new Binance\\RateLimiter($api);
 */

class RateLimiter
{
    private $api = null;
    private $weights = null;
    private $ordersfunctions = null;
    private $requestWeightLimit = 10;
    private $requestWeightInterval = 60;
    private $exchangeOrdersRateLimit = 10;
    private $exchangeOrdersRateInterval = 10;
    private $exchangeOrdersDailyLimit = 10;
    private $exchangeOrdersDailyInterval = 10;
    private $requestsQueue = array();
    private $ordersQueue = array();
    private $ordersDayQueue = array();

    /**
     * RateLimiter constructor
     *
     * @param API $api
     * @param array $limits
     */
    public function __construct(API $api, array $limits = null)
    {
        $this->api = $api;

        $this->weights = array(
            'account' => 10,
            'addToTransfered' => 0,
            'aggTrades' => 1,
            'balances' => 1,
            'bookPrices' => 1,
            'buy' => 1,
            'buyTest' => 1,
            'cancel' => 1,
            'candlesticks' => 1,
            'chart' => 0,
            'cumulative' => 0,
            'depositAddress' => 1,
            'depositHistory' => 1,
            'assetDetail' => 1,
            'depth' => 1,
            'depthCache' => 1,
            'displayDepth' => 1,
            'exchangeInfo' => 1,
            'first' => 0,
            'getProxyUriString' => 0,
            'getRequestCount' => 0,
            'getTransfered' => 0,
            'highstock' => 1,
            'history' => 5,
            'keepAlive' => 0,
            'kline' => 1,
            'last' => 0,
            'marketBuy' => 1,
            'marketBuyTest' => 1,
            'marketSell' => 1,
            'marketSellTest' => 1,
            'miniTicker' => 1,
            'openOrders' => 2,
            'order' => 1,
            'orders' => 10,
            'orderStatus' => 1,
            'prevDay' => 2,
            'prices' => 2,
            'report' => 0,
            'sell' => 1,
            'sellTest' => 1,
            'setProxy' => 0,
            'sortDepth' => 1,
            'terminate' => 0,
            'ticker' => 1,
            'time' => 1,
            'trades' => 5,
            'userData' => 1,
            'useServerTime' => 1,
            'withdraw' => 1,
            'withdrawFee' => 1,
            'withdrawHistory' => 1,
            'fiatHistory' => 1,
            'fiatPaymentsHistory' => 1,
            'commissionFee' => 1,
        );

        $this->ordersfunctions = array(
            'buy',
            'buyTest',
            'cancel',
            'history',
            'marketBuy',
            'marketBuyTest',
            'marketSell',
            'marketSellTest',
            'openOrders',
            'order',
            'orders',
            'orderStatus',
            'sell',
            'sellTest',
            'trades',
        );

        $this->init($limits);
    }

    /**
     * @param array $limits
     *
     * @return void
     */
    private function init(array $limits = null): void
    {
        if (empty($config)) {
            $limits = $this->api->exchangeInfo()['rateLimits'];
        }

        if (is_array($limits) === false) {
            print "Problem getting exchange limits\n";
            return;
        }

        foreach ($limits as $exchangeLimit) {
            switch ($exchangeLimit['rateLimitType']) {
                case "REQUEST_WEIGHT":
                    $this->requestWeightLimit = round($exchangeLimit['limit'] * 0.95);
                    $this->requestWeightInterval = $this->getInterval($exchangeLimit);
                    break;
                case "ORDERS":
                    if ($exchangeLimit['interval'] === "SECOND") {
                        $this->exchangeOrdersRateLimit = round($exchangeLimit['limit'] * 0.9);
                        $this->exchangeOrdersRateInterval = $this->getInterval($exchangeLimit);
                    }
                    if ($exchangeLimit['interval'] === "DAY") {
                        $this->exchangeOrdersDailyLimit = round($exchangeLimit['limit'] * 0.98);
                        $this->exchangeOrdersDailyInterval = $this->getInterval($exchangeLimit);
                    }
                    break;
            }
        }
    }

    /**
     * magic get for private and protected members
     *
     * @param $file string the name of the property to return
     * @return null
     */
    public function __get(string $member)
    {
        return $this->api->$member;
    }

    /**
     * magic set for private and protected members
     *
     * @param $member string the name of the member property
     * @param $value the value of the member property
     */
    public function __set(string $member, $value)
    {
        $this->api->$member = $value;
    }

    private function requestsPerInterval()
    {
        // requests per minute restrictions
        if (count($this->requestsQueue) === 0) {
            return;
        }

        while (count($this->requestsQueue) > $this->requestWeightLimit) {
            $oldest = isset($this->requestsQueue[0]) ? $this->requestsQueue[0] : time();
            while ($oldest < time() - $this->requestWeightInterval) {
                array_shift($this->requestsQueue);
                $oldest = isset($this->requestsQueue[0]) ? $this->requestsQueue[0] : time();
            }
            print "Rate limiting in effect for requests " . PHP_EOL;
            sleep(1);
        }
    }

    private function ordersPerSecond()
    {
        // orders per second restrictions
        if (count($this->ordersQueue) === 0) {
            return;
        }

        while (count($this->ordersQueue) > $this->exchangeOrdersRateLimit) {
            $oldest = isset($this->ordersQueue[0]) ? $this->ordersQueue[0] : time();
            while ($oldest < time() - $this->exchangeOrdersRateInterval) {
                array_shift($this->ordersQueue);
                $oldest = isset($this->ordersQueue[0]) ? $this->ordersQueue[0] : time();
            }
            print "Rate limiting in effect for orders " . PHP_EOL;
            sleep(1);
        }
    }

    private function ordersPerDay()
    {
        // orders per day restrictions
        if (count($this->ordersDayQueue) === 0) {
            return;
        }

        $yesterday = time() - $this->exchangeOrdersDailyInterval;
        while (count($this->ordersDayQueue) > round($this->exchangeOrdersDailyLimit * 0.8)) {
            $oldest = isset($this->ordersDayQueue[0]) ? $this->ordersDayQueue[0] : time();
            while ($oldest < $yesterday) {
                array_shift($this->ordersDayQueue);
                $oldest = isset($this->ordersDayQueue[0]) ? $this->ordersDayQueue[0] : time();
            }
            print "Rate limiting in effect for daily order limits " . PHP_EOL;

            $remainingRequests = round($this->exchangeOrdersDailyLimit * 0.2);
            $remainingSeconds = $this->ordersDayQueue[0] - $yesterday;

            $sleepTime = ($remainingSeconds > $remainingRequests) ? round($remainingSeconds / $remainingRequests) : 1;
            sleep($sleepTime);
        }
    }

    /**
     * @param array $limit
     *
     * @return int
     */
    private function getInterval(array $limit): int
    {
        $intervals = [
            'SECOND' => 1,
            'MINUTE' => 60,
            'DAY' => 60 * 60 * 24,
        ];

        $default = 60;

        return ($intervals[$limit['interval']] ?? $default) * $limit['intervalNum'];
    }

    /**
     * magic call to redirect call to the API, capturing and monitoring the weight limit
     *
     * @param $name the function to call
     * @param $arguments the paramters to the function
     */
    public function __call(string $name, array $arguments)
    {
        $weight = $this->weights[$name] ?? false;

        if ($weight && $weight > 0) {
            $this->requestsPerInterval();
            if (in_array($name, $this->ordersfunctions) === true) {
                $this->ordersPerSecond();
                $this->ordersPerDay();
            }

            $c_time = time();

            for ($w = 0; $w < $weight; $w++) {
                $this->requestsQueue[] = $c_time;
            }

            if (in_array($name, $this->ordersfunctions) === true) {
                for ($w = 0; $w < $weight; $w++) {
                    $this->ordersQueue[] = $c_time;
                    $this->ordersDayQueue[] = $c_time;
                }
            }
        }

        return call_user_func_array(array(&$this->api, $name), $arguments);
    }
}
