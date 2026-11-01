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
 * SomcpPrompt - a guided task the merchant starts from their assistant.
 *
 * This replaced the Skills extension. Skills were correct and read by nobody:
 * no assistant tested goes looking for them. Putting their text in the
 * server's instructions would have reached the model, at the price of every
 * conversation carrying it whether the merchant needed it or not. A prompt
 * costs nothing until the merchant picks it from the assistant's menu, and
 * then it carries the whole guide.
 *
 * What it gives up is initiative: a prompt never starts on its own.
 *
 * A prompt is one markdown file and a few fields. It is filtered exactly like
 * the tools it talks about, for the reason skills were: a guide to changing
 * order statuses is a promise the shop will refuse to keep for an account
 * that may not change one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SomcpPrompt
{
    /** @var int Largest prompt file served. A guide longer than this is not read, it is skimmed. */
    public const MAX_BYTES = 65536;

    /** @var int Longest value accepted for one argument. */
    public const MAX_ARGUMENT_LENGTH = 2000;

    /** @var string */
    private $name;

    /** @var string */
    private $title;

    /** @var string */
    private $description;

    /** @var string absolute path of the markdown file */
    private $file;

    /** @var array<int, string> */
    private $requiredScopes;

    /** @var array<int, string> names of the tools the prompt talks about */
    private $tools;

    /** @var array<int, array{controller:string, action:string}> */
    private $requiredPermissions;

    /** @var array<int, array{name:string, description:string, required:bool}> */
    private $arguments;

    /** @var string name of the icon the built-in chat draws beside the title, empty for its default one */
    private $icon;

    /**
     * @param string $name
     * @param string $title shown in the assistant's menu
     * @param string $description one line, what the prompt is for
     * @param string $file absolute path of the markdown file
     * @param array<int, string> $requiredScopes
     * @param array<int, string> $tools
     * @param array{permissions?: array<int, array{controller:string, action:string}>} $acts
     * @param array<int, array{name:string, description:string, required:bool}> $arguments
     * @param string $icon name of an icon of the built-in chat, empty for its default one
     */
    public function __construct(
        string $name,
        string $title,
        string $description,
        string $file,
        array $requiredScopes,
        array $tools = [],
        array $acts = [],
        array $arguments = [],
        string $icon = '',
    ) {
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
        $this->file = $file;
        $this->requiredScopes = $requiredScopes;
        $this->tools = $tools;
        $this->requiredPermissions = is_array($acts['permissions'] ?? null) ? $acts['permissions'] : [];
        $this->arguments = $arguments;
        // A name, never markup: the chat looks it up in its own set and falls
        // back to its default icon for one it does not know.
        $this->icon = preg_match('/^[a-z]{1,20}$/', $icon) === 1 ? $icon : '';
    }

    /**
     * The same prompt under another name, as the registry files a third
     * party's.
     *
     * @param string $name
     *
     * @return SomcpPrompt
     */
    public function renamed(string $name): SomcpPrompt
    {
        $copy = clone $this;
        $copy->name = $name;

        return $copy;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
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
     * @return array<int, string>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<int, array{name:string, description:string, required:bool}>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return string
     */
    public function getIcon(): string
    {
        return $this->icon;
    }

    /**
     * The back office page the prompt is about, read from the permission it
     * requires: the built-in chat suggests first the prompts of the page the
     * employee has open.
     *
     * @return string empty when the prompt requires no permission
     */
    public function getController(): string
    {
        return (string) ($this->requiredPermissions[0]['controller'] ?? '');
    }

    /**
     * Whether the file can be served.
     *
     * Checked when the prompt is registered, so a prompt nobody could open is
     * refused in the log instead of listed and broken.
     *
     * @return bool
     */
    public function isReadable(): bool
    {
        return is_file($this->file) && is_readable($this->file) && (int) filesize($this->file) <= self::MAX_BYTES;
    }

    /**
     * The prompt as prompts/list describes it.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $described = [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
        ];

        // Left out when empty rather than sent as []: a client building a form
        // from it has nothing to build, and some draw an empty one.
        if ($this->arguments !== []) {
            $described['arguments'] = $this->arguments;
        }

        return $described;
    }

    /**
     * The required argument the caller left out, if any.
     *
     * @param array<string, mixed> $given
     *
     * @return string empty when nothing is missing
     */
    public function missingArgument(array $given): string
    {
        foreach ($this->arguments as $argument) {
            if ($argument['required'] && trim(self::scalar($given[$argument['name']] ?? '')) === '') {
                return $argument['name'];
            }
        }

        return '';
    }

    /**
     * The prompt as prompts/get returns it.
     *
     * One user message: the guide, then what the merchant filled in. The
     * values are set apart under a heading instead of spliced into the text,
     * because they are typed by a person and the guide is written by a module
     * author - mixing the two lets a value rewrite the instructions around it.
     * Only declared arguments are kept; anything else in the request is noise
     * at best.
     *
     * @param array<string, mixed> $given
     *
     * @return array<string, mixed>|null null when the file cannot be read
     */
    public function render(array $given): ?array
    {
        if (!$this->isReadable()) {
            return null;
        }

        $text = @file_get_contents($this->file);
        if (!is_string($text)) {
            return null;
        }

        $text = self::withoutFrontMatter($text);

        $filled = [];
        foreach ($this->arguments as $argument) {
            $value = trim(self::scalar($given[$argument['name']] ?? ''));
            if ($value !== '') {
                $filled[] = '- ' . $argument['name'] . ': '
                    . Tools::substr(str_replace(["\r", "\n"], ' ', $value), 0, self::MAX_ARGUMENT_LENGTH);
            }
        }

        if ($filled !== []) {
            $text = rtrim($text) . "\n\n## This request\n\n" . implode("\n", $filled) . "\n";
        }

        return [
            'description' => $this->description,
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]],
            ],
        ];
    }

    /**
     * Whether a token may be handed this prompt.
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
     * The file without a leading front matter block.
     *
     * A module moving from skills keeps its SKILL.md, whose front matter means
     * nothing to a prompt and reads as noise at the top of the message.
     *
     * @param string $text
     *
     * @return string
     */
    private static function withoutFrontMatter(string $text): string
    {
        if (strncmp($text, "---\n", 4) !== 0) {
            return $text;
        }

        $end = strpos($text, "\n---", 3);
        if ($end === false) {
            return $text;
        }

        // Byte offsets from strpos, so a byte-wise cut: a multibyte character
        // in the front matter would shift a character-wise one.
        return ltrim(substr($text, $end + 4), "\r\n");
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function scalar($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
