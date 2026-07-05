# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.5.x   | ✅ Yes    |
| < 1.5   | ❌ No     |

## Reporting a vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Report security issues privately by email to **support@catalisi.dev** with the subject line:

```
[SECURITY] Cetus Image Converter & AI Alt Text – <brief description>
```

Include:

- A description of the vulnerability and its potential impact
- Steps to reproduce (proof of concept if possible)
- WordPress version, PHP version, and plugin version affected

We will acknowledge your report within 48 hours and aim to release a fix within 14 days for critical issues.

## Scope

Issues in scope:

- SQL injection, XSS, CSRF, privilege escalation, arbitrary file read/write/delete
- Exposure of API keys or sensitive data
- Authentication or authorisation bypass

Out of scope:

- Vulnerabilities requiring administrator-level access to exploit (administrators are trusted in WordPress by design)
- Issues in WordPress core or third-party plugins
- Self-XSS
