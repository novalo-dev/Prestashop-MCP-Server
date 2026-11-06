# Security policy

This module is an OAuth 2.1 authorization server and an MCP server running
inside a shop. A flaw in it can expose a shop's orders and customers, so please
report vulnerabilities privately.

## How to report

Use GitHub's private vulnerability reporting: open the **Security** tab of this
repository and click **Report a vulnerability**. Only the maintainers see the
report.

Please do **not** open a public issue, pull request or discussion for a
vulnerability before it is fixed.

Include what you can of:

- the affected file or endpoint, and the PrestaShop and PHP versions;
- the steps to reproduce, or a proof of concept;
- what an attacker gains: which data, which account, which shop.

## What happens next

- An acknowledgement within **3 working days**.
- A first assessment within **10 working days**: confirmed or not, and how
  serious.
- A fix released as soon as it is ready, then a public advisory crediting you,
  unless you prefer to stay anonymous.

## In scope

- The OAuth flow: client registration, redirect URI validation, PKCE,
  authorization codes, access and refresh tokens, revocation.
- The authorization page (employee sign-in and consent) and the deposit page,
  and how they identify the employee.
- The MCP endpoint: authentication, scope and back office permission checks,
  the shop boundary on multistore installs, the sealed state used between the
  two halves of a call.
- File deposits: type checking, storage, access.
- Anything that leaks a token, a code or a secret, including into the logs.
- The contract other modules use (`SomcpContract`), where it could let a module
  or a request reach more than it declared.

## Out of scope

- Vulnerabilities in PrestaShop itself or in another module: report them to
  their maintainers.
- A shop served without HTTPS, or a server configuration that exposes files the
  module does not control.
- What an MCP client or an AI model does with data it was legitimately given.
- Attacks that need back office administrator access to begin with.

## Supported versions

Only the latest release is supported. Fixes go to the main branch and ship in
the next release.
