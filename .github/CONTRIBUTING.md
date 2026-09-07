# Contributing to Turbo Search

Thank you for your interest in contributing to **Turbo Search**! We welcome contributions of all types: bug reports, bug fixes, feature requests, documentation enhancements, translations, and optimizations.

---

## Code of Conduct

We are committed to providing a welcoming, inclusive, and harassment-free environment for everyone. Please be respectful and constructive in discussions, code reviews, and issue threads.

---

## Getting Started

1. **Fork the repository** on GitHub: [https://github.com/wpamitkumar/turbo-search](https://github.com/wpamitkumar/turbo-search)
2. **Clone your fork** into your WordPress plugins directory:
   ```bash
   git clone https://github.com/wpamitkumar/turbo-search.git wp-content/plugins/turbo-search
   cd wp-content/plugins/turbo-search
   ```
3. **Install developer dependencies**:
   ```bash
   composer install
   ```
4. **Create a topic branch**:
   ```bash
   git checkout -b feature/your-feature-name
   # or
   git checkout -b fix/issue-description
   ```

---

## Coding Guidelines & Standards

- **WordPress Coding Standards (WPCS)**: All PHP code must adhere strictly to WordPress Coding Standards. A configured `phpcs.xml.dist` ruleset is provided.
- **PHP Compatibility**: Code must remain compatible with **PHP 7.4 through PHP 8.3+**.
- **WordPress Compatibility**: Code must support **WordPress 6.0+**.
- **Self-Contained & Secure**:
  - Sanitize all inputs (`sanitize_text_field()`, `absint()`, etc.).
  - Escape all outputs (`esc_html()`, `esc_attr()`, `esc_url()`, etc.).
  - Verify nonces and capabilities on all AJAX handlers and REST endpoints.
  - No external CDN asset links (all JavaScript, CSS, and fonts must be bundled locally).

---

## Local Verification & Testing

Before submitting your Pull Request, verify your changes locally:

1. **PHP Syntax Check**:
   ```bash
   find . -name "*.php" -not -path "./vendor/*" -not -path "./build/*" -not -path "./scratch/*" -exec php -l {} \;
   ```
2. **PHP CodeSniffer**:
   ```bash
   composer lint
   # To automatically fix style violations:
   composer format
   ```
3. **Verify Build Package**:
   ```bash
   mkdir -p build/turbo-search
   rsync -av --exclude-from='.distignore' ./ build/turbo-search/
   cd build && zip -r turbo-search.zip turbo-search/ && cd ..
   ```

---

## Submitting Pull Requests

1. Commit your changes with clear, descriptive commit messages.
2. Push your topic branch to your fork.
3. Open a Pull Request targeting the `main` branch of [https://github.com/wpamitkumar/turbo-search](https://github.com/wpamitkumar/turbo-search).
4. Fill out the Pull Request template completely.
5. Ensure all automated GitHub Actions CI checks pass.

---

## Need Help?

Check the [Contributor FAQ](https://github.com/wpamitkumar/turbo-search#readme) in the README or open a discussion in [GitHub Issues](https://github.com/wpamitkumar/turbo-search/issues).

