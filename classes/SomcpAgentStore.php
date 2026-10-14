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

/*
 * SomcpAgentStore - the conversations of the back office assistant.
 *
 * Every read is filtered on the employee and the shop, never only on the
 * conversation id the browser sends: an id is a number anyone can change in a
 * request, and a conversation is as private as the employee's own inbox.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpAgentStore
{
    /** @var int Conversations listed in the side column. */
    private const LIST_LIMIT = 50;

    /**
     * @param int $idEmployee
     * @param int $idShop
     *
     * @return array<int, array{id_conversation:int, title:string, provider:string, model:string, date_upd:string}>
     */
    public static function conversations(int $idEmployee, int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_conversation`, `title`, `provider`, `model`, `date_upd`
            FROM `' . _DB_PREFIX_ . 'somcp_agent_conversation`
            WHERE `id_employee` = ' . (int) $idEmployee . ' AND `id_shop` = ' . (int) $idShop . '
            ORDER BY `date_upd` DESC, `id_conversation` DESC
            LIMIT ' . self::LIST_LIMIT
        );

        $list = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $list[] = [
                'id_conversation' => (int) $row['id_conversation'],
                'title' => (string) $row['title'],
                'provider' => (string) $row['provider'],
                'model' => (string) $row['model'],
                'date_upd' => (string) $row['date_upd'],
            ];
        }

        return $list;
    }

    /**
     * One conversation, when it belongs to this employee on this shop.
     *
     * @param int $idConversation
     * @param int $idEmployee
     * @param int $idShop
     *
     * @return array{id_conversation:int, title:string, provider:string, model:string}|null
     */
    public static function conversation(int $idConversation, int $idEmployee, int $idShop): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `id_conversation`, `title`, `provider`, `model`
            FROM `' . _DB_PREFIX_ . 'somcp_agent_conversation`
            WHERE `id_conversation` = ' . (int) $idConversation . '
                AND `id_employee` = ' . (int) $idEmployee . '
                AND `id_shop` = ' . (int) $idShop
        );

        if (!is_array($row) || $row === []) {
            return null;
        }

        return [
            'id_conversation' => (int) $row['id_conversation'],
            'title' => (string) $row['title'],
            'provider' => (string) $row['provider'],
            'model' => (string) $row['model'],
        ];
    }

    /**
     * @param int $idEmployee
     * @param int $idShop
     * @param string $title
     * @param string $provider
     * @param string $model
     *
     * @return int the new conversation, 0 when it could not be written
     */
    public static function start(int $idEmployee, int $idShop, string $title, string $provider, string $model): int
    {
        $written = Db::getInstance()->insert('somcp_agent_conversation', [
            'id_employee' => (int) $idEmployee,
            'id_shop' => (int) $idShop,
            'title' => pSQL(Tools::substr($title, 0, 255)),
            'provider' => pSQL($provider),
            'model' => pSQL(Tools::substr($model, 0, 100)),
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return $written ? (int) Db::getInstance()->Insert_ID() : 0;
    }

    /**
     * Records which model answers from now on, and moves the conversation up.
     *
     * @param int $idConversation
     * @param string $provider
     * @param string $model
     */
    public static function touch(int $idConversation, string $provider, string $model): void
    {
        Db::getInstance()->update('somcp_agent_conversation', [
            'provider' => pSQL($provider),
            'model' => pSQL(Tools::substr($model, 0, 100)),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_conversation` = ' . (int) $idConversation);
    }

    /**
     * @param int $idConversation already checked to belong to the employee
     */
    public static function delete(int $idConversation): void
    {
        Db::getInstance()->delete('somcp_agent_message', '`id_conversation` = ' . (int) $idConversation);
        Db::getInstance()->delete('somcp_agent_conversation', '`id_conversation` = ' . (int) $idConversation);
    }

    /**
     * @param int $idConversation
     * @param string $role user, assistant or tool
     * @param array<string, mixed> $content
     * @param string $status done or pending
     *
     * @return int
     */
    public static function add(int $idConversation, string $role, array $content, string $status = 'done'): int
    {
        $json = (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $written = Db::getInstance()->insert('somcp_agent_message', [
            'id_conversation' => (int) $idConversation,
            'role' => pSQL($role),
            // pSQL with html allowed: the JSON carries the merchant's own text,
            // which may hold markup they meant to discuss, and is never printed
            // unescaped.
            'content' => pSQL($json, true),
            'status' => pSQL($status),
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return $written ? (int) Db::getInstance()->Insert_ID() : 0;
    }

    /**
     * @param int $idMessage
     * @param string $status
     */
    public static function mark(int $idMessage, string $status): void
    {
        Db::getInstance()->update('somcp_agent_message', ['status' => pSQL($status)], '`id_message` = ' . (int) $idMessage);
    }

    /**
     * Every turn of a conversation, oldest first.
     *
     * @param int $idConversation already checked to belong to the employee
     *
     * @return array<int, array{id_message:int, role:string, content:array<string, mixed>, status:string, date_add:string}>
     */
    public static function turns(int $idConversation): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_message`, `role`, `content`, `status`, `date_add`
            FROM `' . _DB_PREFIX_ . 'somcp_agent_message`
            WHERE `id_conversation` = ' . (int) $idConversation . '
            ORDER BY `id_message` ASC'
        );

        $turns = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $content = json_decode((string) $row['content'], true);
            $turns[] = [
                'id_message' => (int) $row['id_message'],
                'role' => (string) $row['role'],
                'content' => is_array($content) ? $content : [],
                'status' => (string) $row['status'],
                'date_add' => (string) $row['date_add'],
            ];
        }

        return $turns;
    }
}
