# Contributing to Clueless

First off, thank you for considering contributing to Clueless! It's people like you that make Clueless such a great tool for meeting transcription and analysis.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [How Can I Contribute?](#how-can-i-contribute)
- [Development Setup](#development-setup)
- [Pull Request Process](#pull-request-process)
- [Coding Standards](#coding-standards)
- [Community](#community)

## Code of Conduct

This project and everyone participating in it is governed by the [Clueless Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

## Getting Started

- Make sure you have a [GitHub account](https://github.com/signup/free)
- Fork the repository on GitHub
- Read the [README](README.md) for build instructions
- Check out the [open issues](https://github.com/vijaythecoder/clueless/issues)

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues as you might find that you don't need to create one. When you are creating a bug report, please include as many details as possible using our bug report template.

**Great Bug Reports** tend to have:
- A quick summary and/or background
- Steps to reproduce
- What you expected would happen
- What actually happens
- Notes (possibly including why you think this might be happening)

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:
- A clear and descriptive title
- A detailed description of the proposed enhancement
- An explanation of why this enhancement would be useful
- Examples of how it would work

### Your First Code Contribution

Unsure where to begin contributing? You can start by looking through these issues:
- `good first issue` - issues which should only require a few lines of code
- `help wanted` - issues which need extra attention

### Pull Requests

1. Fork the repo and create your branch from `main`
2. If you've added code that should be tested, add tests
3. If you've changed APIs, update the documentation
4. Ensure the test suite passes
5. Make sure your code follows the existing style
6. Issue that pull request!

## Development Setup

1. **Clone your fork:**
   ```bash
   git clone https://github.com/YOUR_USERNAME/clueless.git
   cd clueless
   ```

2. **Install dependencies:**
   ```bash
   composer install
   npm install
   ```

3. **Set up your environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Set up databases:**
   ```bash
   touch database/database.sqlite
   touch database/nativephp.sqlite
   php artisan migrate
   php artisan migrate --database=nativephp
   ```

5. **Add your API keys to `.env`:**
   ```
   OPENAI_API_KEY=your-openai-api-key-here
   NATIVEPHP_APP_ID=com.yourcompany.yourapp
   ```

6. **Run the development server:**
   ```bash
   composer dev
   ```

## 🔐 Code Signing & Security

### For Contributors (Development)

**You don't need Apple Developer certificates for contributing!** The project automatically creates unsigned builds for development, which are perfect for testing your changes.

If you see warnings like this, it's completely normal:
```
⚠️  No code signing certificate found - creating unsigned build
💡 To create signed builds, install an Apple Developer certificate in Keychain
```

### Security Guidelines

**❌ NEVER commit these:**
- `.env` file with real credentials
- Certificate files (`.cer`, `.p12`)
- Apple Developer credentials
- Real API keys

**✅ SAFE to commit:**
- `.env.example` (template only)
- Source code
- Build scripts (auto-detect certificates)
- Documentation

### Security Hardening

This project follows security best practices:

**Minimal Entitlements**: The `entitlements.plist` file uses the principle of least privilege, requesting only:
- `com.apple.security.device.audio-input` - For audio input access
- `com.apple.security.device.microphone` - For microphone access
- `com.apple.security.device.screen-capture` - Required for ScreenCaptureKit system audio capture

**Removed Dangerous Entitlements**: We explicitly avoid these high-risk permissions:
- `com.apple.security.cs.allow-jit` - Unnecessary JIT compilation
- `com.apple.security.cs.allow-unsigned-executable-memory` - Memory injection risk
- `com.apple.security.cs.disable-executable-page-protection` - Memory corruption risk  
- `com.apple.security.cs.disable-library-validation` - Library injection risk

### Before Committing

Always verify no sensitive data:
```bash
git status
git diff --cached
grep -r "your-actual-api-key" . --exclude-dir=vendor
```

## Pull Request Process

1. **Before submitting:**
   - Run `npm run format` to format your code
   - Run `npm run lint` to check for linting errors
   - Run `php artisan pint` for PHP code style
   - Run `composer test` to ensure all tests pass

2. **PR Title:**
   - Use conventional commit format: `feat:`, `fix:`, `docs:`, `style:`, `refactor:`, `test:`, `chore:`
   - Example: `feat: add export functionality for transcripts`

3. **PR Description:**
   - Reference the issue being fixed: `Fixes #123`
   - Describe what changed and why
   - Include screenshots for UI changes
   - List any breaking changes

4. **Review Process:**
   - PRs require at least one review before merging
   - Address all review comments
   - Keep PRs focused - one feature/fix per PR

## Coding Standards

### PHP (Laravel)

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards
- Use Laravel conventions and best practices
- Write descriptive variable and method names
- Add PHPDoc blocks for methods and complex logic
- Keep controllers thin - move business logic to services

### TypeScript/Vue

- Use TypeScript for all new Vue components
- Follow the [Vue 3 Style Guide](https://vuejs.org/style-guide/)
- Use Composition API for new components
- Define prop types and emit types
- Keep components small and focused

### Git Commit Messages

- Use the present tense ("Add feature" not "Added feature")
- Use the imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit the first line to 72 characters or less
- Reference issues and pull requests liberally after the first line

### Testing

- Write tests for new features
- Maintain or improve code coverage
- Use Pest PHP for backend tests
- Test both happy paths and edge cases

## Documentation

- Update the README.md with details of changes to the interface
- Update the .env.example file with any new environment variables
- Document new features in the wiki
- Add JSDoc/PHPDoc comments for public APIs

## Community

- Join our [GitHub Discussions](https://github.com/vijaythecoder/clueless/discussions)
- Ask questions in [Issues](https://github.com/vijaythecoder/clueless/issues)

## Recognition

Contributors who have made significant contributions will be recognized in our:
- README.md contributors section
- Release notes
- Project website

## Questions?

Don't hesitate to ask questions in:
- [GitHub Discussions](https://github.com/vijaythecoder/clueless/discussions)
- [Issue comments](https://github.com/vijaythecoder/clueless/issues)

Thank you for contributing to Clueless! 🎉