<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to obtain
 * it through the world-wide-web, please send an email to
 * contact@novalo.fr so we can send you a copy immediately.
 *
 * @author    Novalo <contact@novalo.fr>
 * @copyright 2026 Novalo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License v3.0 (AFL-3.0)
 */
declare(strict_types=1);

namespace Novalo\Somcp\Security;

use RateLimit\ConfigurableRateLimiter;
use RateLimit\Exception\LimitExceeded;
use RateLimit\Rate;
use RateLimit\RateLimiter;
use RateLimit\SilentRateLimiter;
use RateLimit\Status;

// The validation checklist asks every PHP file of a module to refuse to run
// outside PrestaShop. It sits after the imports rather than at the very top
// because a namespace and its use statements have to come first, and this
// class is reached through Composer's autoloader, not by being included.
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * A rate limiter of nikolaposa/rate-limit that keeps its counters in the
 * shop's database.
 *
 * The library brings the rules - a quota over a window, the status of an
 * identifier, the exception when it is over - and ships storages for APCu,
 * Redis and Memcached. A shared hosting has none of them, and PrestaShop
 * itself only promises a database, so this is the one piece written here: a
 * fixed window that opens with the first hit and closes after the interval.
 *
 * Identifiers are hashed before they are stored: the table holds no address
 * and no email in clear.
 */
final class DbRateLimiter extends ConfigurableRateLimiter implements RateLimiter, SilentRateLimiter
{
    /** @var string */
    private $table;

    /** @var string */
    private $prefix;

    /** @var int */
    private $idShop;

    /**
     * @param Rate $rate
     * @param string $prefix what is counted, kept apart from the other counters
     */
    public function __construct(Rate $rate, string $prefix)
    {
        parent::__construct($rate);
        $this->table = _DB_PREFIX_ . 'somcp_rate_limit';
        $this->prefix = $prefix;
        // Each shop of a multistore counts for itself: a block on one storefront
        // does not lock the merchant out of the others.
        $this->idShop = (int) \Context::getContext()->shop->id;
    }

    /**
     * Counts one hit, or refuses it when the quota is already used up.
     *
     * @param string $identifier
     *
     * @throws LimitExceeded
     */
    public function limit(string $identifier): void
    {
        if ($this->current($identifier) >= $this->rate->getOperations()) {
            throw LimitExceeded::for($identifier, $this->rate);
        }
        $this->hit($identifier);
    }

    /**
     * Counts one hit and says where the identifier stands.
     *
     * @param string $identifier
     *
     * @return Status
     */
    public function limitSilently(string $identifier): Status
    {
        $this->hit($identifier);

        return $this->status($identifier);
    }

    /**
     * Where the identifier stands, without counting anything.
     *
     * @param string $identifier
     *
     * @return Status
     */
    public function status(string $identifier): Status
    {
        $row = $this->row($identifier);

        return Status::from(
            $identifier,
            $row === null ? 0 : (int) $row['hits'],
            $this->rate->getOperations(),
            $row === null ? time() : (int) strtotime((string) $row['window_end'])
        );
    }

    /**
     * Forgets an identifier.
     *
     * @param string $identifier
     */
    public function reset(string $identifier): void
    {
        \Db::getInstance()->delete('somcp_rate_limit', "`bucket` = '" . pSQL($this->bucket($identifier)) . "'");
    }

    /**
     * @param string $identifier
     *
     * @return int
     */
    private function current(string $identifier): int
    {
        $row = $this->row($identifier);

        return $row === null ? 0 : (int) $row['hits'];
    }

    /**
     * @param string $identifier
     */
    private function hit(string $identifier): void
    {
        $db = \Db::getInstance();
        $now = date('Y-m-d H:i:s');
        // Windows that have closed are dropped as the table is used, so it
        // never grows beyond the addresses seen within the longest interval.
        $db->delete('somcp_rate_limit', "`window_end` < '" . pSQL($now) . "'");

        $end = date('Y-m-d H:i:s', time() + $this->rate->getInterval());
        // One statement, so two requests arriving together cannot both read
        // zero and both be let through.
        $db->execute('INSERT INTO `' . bqSQL($this->table) . '` (`bucket`, `id_shop`, `hits`, `window_end`)'
            . " VALUES ('" . pSQL($this->bucket($identifier)) . "', " . $this->idShop . ", 1, '" . pSQL($end) . "')"
            . ' ON DUPLICATE KEY UPDATE `hits` = `hits` + 1');
    }

    /**
     * @param string $identifier
     *
     * @return array<string, mixed>|null
     */
    private function row(string $identifier): ?array
    {
        $row = \Db::getInstance()->getRow('SELECT `hits`, `window_end` FROM `' . bqSQL($this->table) . '`'
            . " WHERE `bucket` = '" . pSQL($this->bucket($identifier)) . "'"
            . " AND `window_end` >= '" . pSQL(date('Y-m-d H:i:s')) . "'");

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param string $identifier
     *
     * @return string
     */
    private function bucket(string $identifier): string
    {
        return hash('sha256', $this->idShop . ':' . $this->prefix . ':' . $this->rate->getInterval() . ':' . $identifier);
    }
}
