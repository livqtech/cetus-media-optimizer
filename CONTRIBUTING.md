# Contributing to Cetus Image Converter & AI Alt Text

Thank you for your interest in contributing!

## Reporting bugs

Open an issue on [GitHub](https://github.com/catalisidev/cetus-media-optimizer/issues) with:

- WordPress version and PHP version
- Active plugins and theme
- Steps to reproduce the bug
- Expected vs actual behaviour
- Any error messages from `wp-content/debug.log` (enable `WP_DEBUG` and `WP_DEBUG_LOG`)

## Suggesting features

Open a GitHub issue with the `enhancement` label. Describe the use case, not just the solution.

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Install dev dependencies: `composer install`
3. Make your changes. Follow WordPress coding standards.
4. Check your code: `composer phpcs`
5. Auto-fix where possible: `composer fix`
6. Open a pull request against `main` with a clear description of the change.

## Coding standards

- WordPress PHP Coding Standards (enforced via PHPCS — see `phpcs.xml.dist`)
- PHP 8.0+ syntax
- No direct database queries without `$wpdb->prepare()`
- All user-facing strings must be wrapped in `__()` / `esc_html_e()` with the `cetus-media-optimizer` text domain

## Translations

String files live in `languages/`. If you add new translatable strings, regenerate the `.pot` file with WP-CLI:

```bash
wp i18n make-pot . languages/cetus-media-optimizer.pot
```

## License

By contributing you agree that your code will be licensed under GPL-2.0-or-later.
