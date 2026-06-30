# Contributing

Contributions are welcome. Please read `AGENTS.md` first — it covers coding standards, security checklist, versioning, and the phase map for this rebuild.

## Process

1. Open an issue before large changes when possible.
2. Keep commits small and focused. Explain non-obvious motivation in the commit body.
3. Open a pull request. Note if it is a draft.
4. Ensure PHPCS passes where applicable and add tests for new behavior when the test harness is available.
5. Update `CHANGELOG.md` and bump the plugin version for user-facing changes (see `AGENTS.md`).

## Standards

- WordPress PHP Coding Standards
- PHP 7.4+ (match `plugin.php` header)
- Every new PHP file needs file-level and class-level docblocks with `@since`
- Never modify existing `@since` tags on symbols that already shipped — add new methods with the version that introduces them

## Tests

```sh
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer exec phpunit
```

Fixtures live in `tests/fixtures/`. Legacy v3 tests are under `.legacy/tests/` and are not run by the default suite.

## License

By contributing, you agree that your contributions will be licensed under GPLv2 or later.
