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
 * SomcpTool - one callable exposed to an assistant.
 *
 * A tool knows the scopes it needs and carries its own handler. It knows
 * nothing about HTTP, JSON-RPC or OAuth, which is what lets another module
 * publish one without depending on any of that.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpTool
{
    /** @var string */
    private $name;

    /** @var string */
    private $title;

    /** @var string */
    private $description;

    /** @var array<string, mixed> */
    private $inputSchema;

    /** @var array<int, string> */
    private $requiredScopes;

    /** @var array<int, array{controller:string, action:string}> */
    private $requiredPermissions;

    /** @var array<string, bool> */
    private $annotations;

    /**
     * @var array<string, mixed> Metadata a particular client understands.
     *
     * The protocol keeps `_meta` as the place to put what it has not defined
     * itself: official extensions use the io.modelcontextprotocol/ prefix, and
     * anyone else their own vendor one. A client that does not know a key
     * ignores it, which is what makes this safe to carry - a key meant for one
     * assistant costs the others nothing.
     *
     * The module puts nothing here on its own. It carries what a tool declares,
     * so a third-party module can reach a client feature without somcp having
     * to learn about it first.
     */
    private $meta;

    /** @var string Why the declared schema cannot be served, empty when it can. */
    private $schemaProblem;

    /** @var callable */
    private $handler;

    /**
     * @param string $name machine name, what tools/call targets
     * @param string $title short human label
     * @param string $description what the tool does, read by the assistant
     * @param array<string, mixed> $inputSchema JSON Schema of the arguments
     * @param array<int, string> $requiredScopes scopes a token must hold
     * @param callable $handler function(array $arguments, array $context): array
     * @param array{permissions?: array<int, array{controller:string, action:string}>, annotations?: array<string, bool>} $acts
     *                                                                                                                          what the tool does, built with acts() or actsOnNothing(). Left
     *                                                                                                                          empty, the tool asks for no back office permission and is open to
     *                                                                                                                          any account that could connect an assistant at all - which has to
     *                                                                                                                          be a deliberate choice, and is one for a tool reading no shop data.
     * @param array<string, mixed> $meta metadata for a particular client, keyed as the
     *                                   protocol requires: a vendor prefix ending in a
     *                                   slash, e.g. 'openai/fileParams'. Clients that do
     *                                   not know a key ignore it. Left empty, nothing is
     *                                   sent - which is the case for every tool here.
     */
    public function __construct(
        string $name,
        string $title,
        string $description,
        array $inputSchema,
        array $requiredScopes,
        callable $handler,
        array $acts = [],
        array $meta = [],
    ) {
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
        $this->schemaProblem = self::schemaProblem($inputSchema);
        $this->inputSchema = self::repairedSchema($inputSchema);
        $this->requiredScopes = $requiredScopes;
        $this->handler = $handler;
        $this->requiredPermissions = is_array($acts['permissions'] ?? null) ? $acts['permissions'] : [];
        $this->annotations = is_array($acts['annotations'] ?? null) ? $acts['annotations'] : [];
        $this->meta = self::acceptableMeta($meta);
    }

    /**
     * The metadata keys a tool is allowed to publish.
     *
     * Two rules of the protocol, applied here because this is where a
     * third-party module's data enters the answer the shop sends:
     *
     * - a key is an optional prefix - dot-separated labels then a slash - and
     *   a name that starts and ends alphanumeric;
     * - any prefix whose second label is `modelcontextprotocol` or `mcp` is
     *   reserved for the protocol itself.
     *
     * A module that publishes under the reserved prefix would be writing what
     * a client reads as protocol metadata, from inside a tool description.
     * Refused, and said so in the log rather than dropped in silence.
     *
     * `openai/fileParams` passes: one label, not reserved.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function acceptableMeta(array $meta): array
    {
        $accepted = [];
        foreach ($meta as $key => $value) {
            $key = (string) $key;
            $slash = strrpos($key, '/');
            $prefix = $slash === false ? '' : Tools::substr($key, 0, $slash);
            $name = $slash === false ? $key : Tools::substr($key, $slash + 1);

            $labels = $prefix === '' ? [] : explode('.', $prefix);
            $wellFormed = $name !== ''
                && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9._-]*[a-zA-Z0-9])?$/', $name) === 1;

            foreach ($labels as $label) {
                if (preg_match('/^[a-zA-Z]([a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $label) !== 1) {
                    $wellFormed = false;
                }
            }

            $reserved = in_array($labels[1] ?? '', ['modelcontextprotocol', 'mcp'], true);

            if (!$wellFormed || $reserved) {
                SomcpLogger::log(
                    'tool_meta_refused',
                    SomcpLogger::OUTCOME_FAILURE,
                    Tools::substr($key, 0, 120) . ($reserved ? ' uses a reserved prefix' : ' is not a valid key')
                );

                continue;
            }

            $accepted[$key] = $value;
        }

        return $accepted;
    }

    /**
     * Why a declared schema cannot be served at all.
     *
     * Only what cannot be repaired. A schema that merely leans on PHP habits -
     * a missing `type`, an empty array where an object belongs - is fixed by
     * repairedSchema() below rather than refused.
     *
     * @param array<string, mixed> $schema
     *
     * @return string empty when the schema is usable
     */
    private static function schemaProblem(array $schema): string
    {
        $type = $schema['type'] ?? 'object';
        if ($type !== 'object') {
            // tools/call sends a named-argument object. A schema describing
            // anything else has no way of matching what arrives.
            return 'the root type is ' . (is_string($type) ? $type : gettype($type)) . ', not object';
        }

        $properties = $schema['properties'] ?? [];
        if (!is_array($properties) && !($properties instanceof stdClass)) {
            return 'properties is a ' . gettype($properties);
        }

        // A non-empty list - keys 0, 1, 2 - is a sequence of fields with no
        // names. Nothing sensible can be recovered from it.
        if (is_array($properties) && $properties !== [] && array_is_list($properties)) {
            return 'properties is a list, so the arguments have no names';
        }

        return '';
    }

    /**
     * The same schema, in a shape a client will accept.
     *
     * One thing is fixed here, and it is the one that keeps happening: an empty
     * PHP array becomes `[]` in JSON, where the specification wants `{}`. A
     * client refuses the whole tool list over it, so one module declaring a
     * parameterless tool the natural PHP way takes down every other module's
     * tools with it. That is too much damage for a mistake this easy to make.
     *
     * A missing `type` is filled in for the same reason: tools/call only ever
     * sends an object, so there is no other value it could have had.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function repairedSchema(array $schema): array
    {
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }

        if (!isset($schema['properties'])) {
            $schema['properties'] = new stdClass();
        }

        if (isset($schema['required']) && (!is_array($schema['required']) || !array_is_list($schema['required']))) {
            // Required is a list of names. Anything else would be sent as an
            // object and refused, and there is nothing to recover from it.
            unset($schema['required']);
        }

        return self::repairedNode($schema);
    }

    /**
     * Empty arrays turned into empty objects, wherever a schema expects one.
     *
     * Applied to the root and to every field below it. Nothing else is touched:
     * a field's own `type` is its author's business, and only the root is known
     * to describe an object.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function repairedNode(array $node): array
    {
        foreach (['properties', 'patternProperties', '$defs', 'definitions', 'items'] as $key) {
            if (isset($node[$key]) && $node[$key] === []) {
                $node[$key] = new stdClass();
            }
        }

        foreach (['properties', 'patternProperties', '$defs', 'definitions'] as $key) {
            if (is_array($node[$key] ?? null)) {
                foreach ($node[$key] as $name => $field) {
                    if (is_array($field)) {
                        $node[$key][$name] = self::repairedNode($field);
                    }
                }
            }
        }

        if (is_array($node['items'] ?? null)) {
            $node['items'] = self::repairedNode($node['items']);
        }

        return $node;
    }

    /**
     * Why this tool cannot be published, empty when it can.
     *
     * @return string
     */
    public function getSchemaProblem(): string
    {
        return $this->schemaProblem;
    }

    /**
     * What a tool does, stated once.
     *
     * The four actions are PrestaShop's own - the ones a merchant sets in Team,
     * Permissions - and the module derives both things it needs from them: the
     * permission the employee must hold, and what an assistant should be told
     * about the call. Declared separately, the two could contradict each other:
     * a tool asking for the right to delete while calling itself read-only is a
     * mistake nothing would catch.
     *
     * Only reading is marked repeatable. Everything that writes asks for a
     * confirmation and is never retried on its own - including creating, which
     * destroys nothing. Marking it destructive is deliberate: that flag is the
     * only lever a client offers to ask the merchant first, and a confirmation
     * too many costs a click, while a call nobody wanted can cost a mail sent
     * to a customer. Do not "fix" it.
     *
     * @param string $action one of SomcpPermission's VIEW, ADD, EDIT or DELETE
     * @param string $controller back office controller, e.g. AdminProducts
     *
     * @return array{permissions:array<int, array{controller:string, action:string}>, annotations:array<string, bool>}
     */
    public static function acts(string $action, string $controller): array
    {
        $reads = $action === SomcpPermission::VIEW;

        return [
            'permissions' => [SomcpPermission::on($controller, $action)],
            'annotations' => [
                'readOnlyHint' => $reads,
                'destructiveHint' => !$reads,
                // Repeating a read changes nothing. Repeating anything else can
                // create a duplicate, or send a second mail to a customer for a
                // call whose answer was merely lost on the way back.
                'idempotentHint' => $reads,
                // The shop is a closed world: nothing here reaches beyond it.
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * A tool that touches no shop data at all, and asks for no permission.
     *
     * @return array{permissions:array<int, array{controller:string, action:string}>, annotations:array<string, bool>}
     */
    public static function actsOnNothing(): array
    {
        return [
            'permissions' => [],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The same tool under another name.
     *
     * A copy rather than a setter: the registry renames a third-party tool as
     * it files it, and the module that built the object keeps the one it built.
     * Nothing else about the tool moves.
     *
     * @param string $name
     *
     * @return SomcpTool
     */
    public function renamed(string $name): SomcpTool
    {
        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    /**
     * @return array<int, string>
     */
    public function getRequiredScopes(): array
    {
        return $this->requiredScopes;
    }

    /**
     * @return array<int, array{controller:string, action:string}>
     */
    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }

    /**
     * Whether the employee behind a token may use this tool at all.
     *
     * Both halves have to hold: the permissions say what the employee can do
     * in the back office, the scopes say what the merchant let this particular
     * assistant do on their behalf. A read-only assistant belonging to an
     * administrator is a thing the merchant is allowed to want.
     *
     * @param string $grantedScopes
     * @param int $idEmployee
     *
     * @return bool
     */
    public function isAvailableTo(string $grantedScopes, int $idEmployee): bool
    {
        return SomcpScope::covers($grantedScopes, $this->requiredScopes)
            && SomcpPermission::employeeHolds($idEmployee, $this->requiredPermissions);
    }

    /**
     * The shape sent in a tools/list answer.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $described = [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];

        if ($this->annotations !== []) {
            $described['annotations'] = $this->annotations;
        }

        if ($this->meta !== []) {
            $described['_meta'] = $this->meta;
        }

        return $described;
    }

    /**
     * Runs the tool.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context token row, employee and shop
     *
     * @return array<string, mixed> a CallToolResult
     */
    public function call(array $arguments, array $context): array
    {
        return call_user_func($this->handler, $arguments, $context);
    }

    /** @var string Marks a result that is a question rather than an answer. */
    public const INPUT_REQUIRED = '_somcpInputRequired';

    /**
     * A tool answering that it needs something before it can finish.
     *
     * The handler returns this instead of a result; the endpoint turns it into
     * the `input_required` shape, seals the payload and hands both to the
     * client. The client fetches what was asked, calls the tool again with the
     * same arguments, and the payload comes back in the context under
     * `resumed` - so a handler reads its own state and never has to know that
     * any of this travelled through the caller.
     *
     * A tool may only ask for what the client declared it can do. The endpoint
     * checks that, and answers the merchant plainly when it cannot be asked.
     *
     * @param array<string, array<string, mixed>> $inputRequests keyed by a name of the tool's
     *                                                           choosing, each built with SomcpElicitation
     * @param array<string, mixed> $state what the tool wants back on the second call
     *
     * @return array<string, mixed>
     */
    public static function needsInput(array $inputRequests, array $state = []): array
    {
        return [
            self::INPUT_REQUIRED => true,
            'inputRequests' => $inputRequests,
            'state' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return bool
     */
    public static function isInputRequired(array $result): bool
    {
        return ($result[self::INPUT_REQUIRED] ?? false) === true;
    }

    /**
     * Wraps data as JSON inside the text content a tool result must have.
     *
     * Not structuredContent: that one requires the tool to declare an
     * outputSchema and to match it exactly, which is a contract worth taking on
     * deliberately rather than by default. JSON in a text block asks nothing of
     * the client and still saves the model from reading prose to find a number.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function json(array $data): array
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return self::text($encoded === false ? '{}' : $encoded);
    }

    /**
     * Wraps plain text into the content shape a tool result must have.
     *
     * @param string $text
     * @param bool $isError
     *
     * @return array<string, mixed>
     */
    public static function text(string $text, bool $isError = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    /**
     * A calendar day given as YYYY-MM-DD, or null when the value is not one.
     *
     * Shared by the tools that take a period, so a date accepted by one is
     * accepted by the others and the assistant can pass the same bounds on.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    public static function day($value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return null;
        }

        return $value;
    }

    /**
     * The active languages of a shop, keyed by id_lang, with their ISO code.
     *
     * The one list every tool checks a language against: an installed
     * language that is disabled, or attached to another shop, is not one the
     * merchant writes in, so a text read or written in it would be lost.
     *
     * @param int $idShop
     *
     * @return array<int, string>
     */
    public static function activeLanguages(int $idShop): array
    {
        $languages = [];
        foreach (Language::getLanguages(true, $idShop) as $language) {
            $languages[(int) $language['id_lang']] = (string) $language['iso_code'];
        }

        return $languages;
    }

    /**
     * The language a read tool reads its texts in, as [id_lang, iso], or null
     * when the one asked for is not an active language of the shop.
     *
     * Left out, it is the shop default rather than the context language: on an
     * MCP call the context language follows the client's Accept-Language
     * header, so the same call would answer in a different language from one
     * client to the next. An unknown code is refused rather than replaced, or
     * texts read in one language would be handed back as another.
     *
     * @param array<string, mixed> $arguments
     * @param int $idShop
     *
     * @return array{0: int, 1: string}|null
     */
    public static function readLanguage(array $arguments, int $idShop): ?array
    {
        $languages = self::activeLanguages($idShop);
        if (!array_key_exists('language', $arguments) || $arguments['language'] === null || $arguments['language'] === '') {
            $idDefault = (int) Configuration::get('PS_LANG_DEFAULT', null, null, $idShop);
            if (isset($languages[$idDefault])) {
                return [$idDefault, $languages[$idDefault]];
            }
            // A default language left disabled still answers in a language
            // the shop has, rather than failing a call that asked for none.
            foreach ($languages as $idLang => $iso) {
                return [$idLang, $iso];
            }

            return null;
        }
        if (!is_string($arguments['language'])) {
            return null;
        }

        $asked = Tools::strtolower(trim($arguments['language']));
        foreach ($languages as $idLang => $iso) {
            if (Tools::strtolower($iso) === $asked) {
                return [$idLang, $iso];
            }
        }

        return null;
    }

    /**
     * The answer to a language readLanguage() refused, naming the ones the
     * shop has so the next call can pick one.
     *
     * @param int $idShop
     *
     * @return array<string, mixed>
     */
    public static function languageRefused(int $idShop): array
    {
        return self::text(
            'This language is not one of the shop\'s, which has '
                . implode(', ', self::activeLanguages($idShop))
                . '. Give the ISO code of one of them, or leave language out for the shop default.',
            true
        );
    }

    /**
     * The name of a carrier as the back office shows it.
     *
     * The carrier the installer creates is stored with the name 0, and the
     * back office shows it under the shop name. Shared by every tool that
     * names a carrier, so the same carrier never wears two names.
     *
     * @param string $name as stored
     *
     * @return string
     */
    public static function carrierName(string $name): string
    {
        return $name === '0' ? (string) Configuration::get('PS_SHOP_NAME') : $name;
    }
}
