# Scheduling component: agent guide

A framework-independent scheduler component with adapters (Laravel, crunz) and its own mutex.
Design and every decision behind it: the Forgejo wiki pages "Emorfiq scheduler design journal" and
"Emorfiq scheduler interfaces draft". Rules below are adopted from Jan's vlm project where they apply.

## Working rules (HARD)

- Wait for plan approval: after proposing a plan, implement nothing until Jan approves it.
- Never commit or push unless Jan explicitly asks. Never force push.
- Commits end with `Co-Authored-By: Claude <noreply@anthropic.com>`, without the model name.
- Never rewrite, weaken or delete a test to make code pass. Fix the code; the test is the contract.
- Never change a public method signature (parameter types, return type, order, name) without approval.
- When in doubt, ask.

## Principles

- SOLID, small specific interfaces, depend on abstractions.
- Value objects for immutable domain data; domain-specific exceptions for rule violations.
- KISS and YAGNI: no feature "just in case". **No unused code**: no method, enum case, option or
  class without a caller. Add it the day something needs it.
- Reuse before creating; no copy-paste logic.

## Layout

pds/skeleton at the package root (`src/`, `tests/`, `config/`, `docs/`). Inside `src/`: the main
interfaces and their default implementations at the root (`SchedulerInterface`, `Scheduler`); role
folders for framework roles (`Exception/`, later `Event/`, singular as Symfony names them); domain
folders for our own concepts (`Task/`, `Lock/`, `Run/`); third-party integrations under
`Bridge/<Vendor>/`. No kind folders for our own concepts (`ValueObject/`, `Enum/`). Interfaces end in
`Interface`, exceptions in `Exception`.

## Exceptions

Every exception extends `abstract SchedulerException extends \Exception`, so all are checked.
Every function that can throw documents it with `@throws`, constructors and property hooks
included. PHPStan enforces it (`exceptions.check` in `phpstan.neon`); a stale `@throws` fails too.

## Code style

- PHP 8.5. Value objects validate in `set` hooks on `public private(set)` properties declared in the
  class body and assigned in the constructor; the hook and the constructor both carry `@throws`.
  Not promoted: PHPStan does not see a promoted hook throw from the constructor. Derived values are
  virtual `get` hooks (`LockPolicy::$ttlSeconds`). Classes without validation stay `readonly` with
  promoted properties.
- Every PHP file starts with `<?php declare(strict_types=1);` on one line.
- php-cs-fixer `@Symfony`, with `blank_line_after_opening_tag` and `linebreak_after_opening_tag` off
  and `single_line_empty_body` on (an empty body is `{}` on the same line)
  so the line above survives: `composer cs-fix`.
- A `use` statement for every class, including global classes and single calls; alias on a name
  clash (`use Cron\CronExpression as CronExpressionParser;`). Enforced by the fixer
  (`global_namespace_import`, `fully_qualified_strict_types`).
- Enums for states and reasons.
- Method names that make comments unnecessary. No comments that describe what the code does; a
  comment only for a trap the code cannot show.
- Every method that implements an interface or overrides a parent carries `{@inheritDoc}` (the
  fixer keeps it: `no_superfluous_phpdoc_tags.remove_inheritdoc` is off).
- PHPDoc only where the declaration cannot carry the type (array shapes such as
  `array<string, mixed>`, `list<...>`, `positive-int`).
- No `__toString()` or `equals()` on value objects unless something calls them; read `->value`.

## Checks

- `.githooks/pre-commit` runs the fixer in check mode and PHPStan; a commit that fails either is
  refused. `composer install` points git at it (`core.hooksPath`). Never bypass the hook.
- `composer phpstan`: PHPStan 2.x, level max over `src`. Must be clean (1.x does not understand hooks).
- `composer cs-fix` fixes, `composer cs-check` only reports; the hook runs the check.
- `composer phpmd`: vlm's ruleset (`phpmd.xml`), advisory. PHPMD 2.15 cannot parse PHP 8.4
  asymmetric visibility (`private(set)`) and skips those files with "Unexpected token";.

## Logging levels

ERROR for failures needing attention, WARNING for unexpected but handled, INFO for significant
events, DEBUG never in production. Log context carries the task id.
