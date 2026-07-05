# Cetus Image Converter & AI Alt Text

**Next-Gen WebP & AVIF Conversion with AI-Powered Alt Text**

Automated image optimization and smart SEO accessibility for WordPress. Convert your entire media library to AVIF or WebP, auto-generate Alt Text via Google Gemini or OpenAI, detect orphan files, and track savings — all from a single native admin panel.

---

## Features

- **AVIF / WebP conversion** — Creates optimized copies alongside originals (JPG/PNG/GIF are never deleted). Uses Imagick when available, falls back to GD.
- **Smart format selection** — Automatic mode picks AVIF when the server supports it, WebP otherwise. Force a specific format if needed.
- **WebP → AVIF re-conversion** — Already have WebP images? Convert them to AVIF without re-uploading.
- **Auto-convert on upload** — Optionally convert every new image the moment it lands in the library.
- **AI-powered Alt Text** — Send images to Google Gemini or OpenAI (BYOK) and save descriptions natively as `_wp_attachment_image_alt`. Automatic fallback between providers on quota errors.
- **Bulk processor** — Asynchronous AJAX batches with real-time progress bar, speed (img/s), ETA, Pause and Stop controls. Safe for shared hosting.
- **WP-Cron background processing** — Process the library unattended without keeping the browser open.
- **Orphan file scanner** — Finds images on disk not registered in the WordPress database.
- **Conversion log** — Scrollable table of the last conversions with format, space saved and status.
- **Per-image exclusion** — Skip individual images from both auto-convert and bulk batch via the attachment edit screen.
- **Library statistics** — Total images, unconverted count, disk usage by format, cumulative savings.

---

## Requirements

| Feature | Requirement |
|---|---|
| WebP output | Imagick with WebP **or** GD with WebP |
| AVIF output | Imagick with libavif / libheif |
| AI Alt Text | Google Gemini or OpenAI API key (BYOK) |
| WordPress | 6.2 or later |
| PHP | 8.0 or later |

The built-in **Step 1 – Server Diagnosis** panel shows green/red indicators for every capability on your specific server.

---

## Installation

1. Upload the `cetus-media-optimizer` folder to `/wp-content/plugins/`.
2. Activate through **Plugins** in the WordPress admin.
3. Go to **Cetus Media** in the admin menu.
4. Follow the three-step panel:
   - **Step 1** — Review server diagnostics.
   - **Step 2** — Choose output format and optionally add AI API keys.
   - **Step 3** — Click *Start Optimisation* to process the library.

---

## Privacy & Security

- All AJAX handlers require a valid WordPress nonce and `current_user_can('manage_options')`.
- Original images are **never deleted** — conversion only adds companion files.
- API keys are stored in the WordPress options table and are never exposed in front-end HTML or JavaScript.
- AI requests go directly from your WordPress installation to Google or OpenAI — no data passes through Catalisi servers.
- Telemetry is opt-in only and currently a stub — no data is sent.

---

## Development

```bash
# Install dev dependencies
composer install

# Run PHP_CodeSniffer
composer lint

# Fix auto-fixable issues
composer fix
```

A CI pipeline (GitHub Actions) runs PHPCS and PHP syntax checks on every push across PHP 8.0–8.3. See `.github/workflows/` for details.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full history.

---

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

## Support

- **WordPress.org forum:** https://wordpress.org/support/plugin/cetus-media-optimizer/
- **GitHub Issues:** https://github.com/catalisidev/cetus-media-optimizer/issues
- **Email:** support@catalisi.dev

---

Developed by [Catalisi](https://catalisi.dev)
