# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Clueless is an AI-powered meeting assistant that provides real-time transcription, intelligent analysis, and action item extraction from conversations. It's built as a single-user desktop application using Electron/NativePHP with OpenAI's Realtime API for voice conversations.

## Tech Stack

- **Backend**: Laravel 12.0 (PHP 8.2+)
- **Frontend**: Vue 3.5.13 with TypeScript and Inertia.js
- **CSS**: Tailwind CSS 4.1.1
- **Build**: Vite 6
- **Desktop**: NativePHP/Electron
- **Testing**: Pest PHP
- **Database**: SQLite (dual database setup)
- **Real-time**: OpenAI Realtime API, WebSockets
- **AI Integration**: OpenAI Realtime API only

## Development Commands

### Running the Application

```bash
# Full development environment (Laravel + Vite + Queue + Logs)
composer dev

# Development with Server-Side Rendering
composer dev:ssr

# Desktop application mode (NativePHP)
composer native:dev

# Frontend only (Vite dev server)
npm run dev
```

### Build Commands

```bash
# Build frontend for production
npm run build

# Build with SSR support
npm run build:ssr
```

### Code Quality

```bash
# Format code with Prettier
npm run format

# Check formatting without fixing
npm run format:check

# Fix ESLint issues
npm run lint

# PHP code style (Laravel Pint)
php artisan pint
```

### Testing

```bash
# Run all tests
composer test
# or
php artisan test

# Run specific test file
php artisan test tests/Feature/DashboardTest.php

# Run tests with coverage (if configured)
php artisan test --coverage

# Run tests in parallel
php artisan test --parallel

# Run only unit tests
php artisan test --testsuite=Unit

# Run only feature tests
php artisan test --testsuite=Feature
```

## Project Architecture

### Directory Structure

- `/app/` - Laravel backend logic
  - `/Http/Controllers/` - Request handlers (Conversations, Realtime, Settings)
  - `/Models/` - Eloquent models (Conversation, Transcript, etc.)
  - `/Services/` - Business logic (ApiKeyService, TranscriptionService)
  - `/Providers/` - Service providers
- `/resources/js/` - Vue frontend application
  - `/components/` - Reusable Vue components
  - `/components/ui/` - UI component library (Reka UI based, shadcn/ui-inspired)
  - `/pages/` - Inertia.js page components
  - `/layouts/` - Layout components (App, Settings)
  - `/composables/` - Vue composables (useAppearance, useRealtime, etc.)
  - `/services/` - Frontend services (audioCaptureService, realtimeClient)
  - `/types/` - TypeScript type definitions
- `/routes/` - Application routing (web.php)
- `/database/` - Migrations, factories, seeders
- `/tests/` - Pest PHP test suites

### Key Patterns

1. **Inertia.js Integration**: Pages are Vue components loaded via Inertia.js, providing SPA-like experience without API endpoints
2. **Component Library**: UI components in `/resources/js/components/ui/` follow Reka UI patterns
3. **TypeScript**: Strict mode enabled, with path alias `@/` for `/resources/js/`
4. **No Authentication**: Single-user desktop app with no login required
5. **Theme Support**: Dark/light mode via `useAppearance` composable
6. **Real-time Features**: WebSocket connections for live transcription using OpenAI Realtime API
7. **Service Architecture**: Core business logic separated into service classes (ApiKeyService, TranscriptionService, etc.)

### Important Files

- `vite.config.ts` - Vite configuration with Laravel plugin
- `tsconfig.json` - TypeScript configuration
- `eslint.config.js` - ESLint rules (ignores UI components)
- `resources/js/app.ts` - Frontend entry point
- `resources/js/ssr.ts` - SSR entry point

## Common Development Tasks

### Creating New Pages

1. Create Vue component in `/resources/js/pages/`
2. Add route in `/routes/web.php` returning Inertia::render()
3. TypeScript types auto-imported from `@/types`

### Adding UI Components

UI components are in `/resources/js/components/ui/` and follow Reka UI patterns. Import and use them directly - they're excluded from ESLint checks.

### Database Operations

```bash
# Run migrations
php artisan migrate

# Create new migration
php artisan make:migration create_example_table

# Seed database
php artisan db:seed
```

### Environment Setup

1. Copy `.env.example` to `.env`
2. Generate app key: `php artisan key:generate`
3. Create SQLite database: `touch database/database.sqlite`
4. Run migrations: `php artisan migrate`
5. Configure OpenAI API key either:
   - In `.env` file: `OPENAI_API_KEY=sk-...`
   - Or via Settings > API Keys after launching the app

## Database Migrations

**IMPORTANT**: This application uses TWO databases:
1. Default SQLite database: `database/database.sqlite`
2. NativePHP SQLite database: `database/nativephp.sqlite`

When creating or running migrations, you MUST run them on BOTH databases:
```bash
# Run on default database
php artisan migrate

# Run on nativephp database (REQUIRED!)
php artisan migrate --database=nativephp

# For specific migrations:
php artisan migrate --database=nativephp --path=database/migrations/your_migration.php
```

Always check both databases when debugging database-related issues!

## Documentation Access

When you need to access documentation for any library or framework, use the Context7 MCP tool. This is especially important as we're in 2025 and web search results from 2024 may be outdated. Always prefer Context7 for the latest documentation.

Example usage:
- For PrismPHP docs: Use `mcp__context7__get-library-docs` with library ID `/prism-php/prism`
- For Laravel docs: Use Context7 to get the latest Laravel 12 documentation
- For any other library: First use `mcp__context7__resolve-library-id` to find the library

## Working with Real-time Features

The application uses a **direct frontend WebSocket architecture** for optimal performance:

### Architecture Overview
- **Frontend Audio Capture**: `/resources/js/services/audioCaptureService.ts`
- **Direct WebSocket Connections**: `/resources/js/pages/RealtimeAgent/Main.vue`
  - Native WebSocket implementation connecting directly to OpenAI Realtime API
  - Two separate connections: salesperson transcription + customer coach analysis
  - Uses `gpt-4o-mini-transcribe` model for cost-effective transcription
- **Backend Ephemeral Key Service**: `/app/Http/Controllers/RealtimeController.php`
  - Generates secure temporary authentication keys
  - No WebSocket relay - frontend connects directly to OpenAI

### Data Flow
```
Frontend → Backend (ephemeral key) → Frontend → OpenAI (direct WebSocket)
```

This architecture provides lower latency and better scalability compared to backend relay approaches.

## API Key Management

The application uses a simple API key management system:
- API keys are stored in Laravel's cache (not database)
- Configure via Settings > API Keys in the app
- Falls back to `.env` OPENAI_API_KEY if not configured
- Realtime Agent checks for API key on startup

## License & Usage

This project uses MIT License with Commons Clause:
- ✅ Free for personal and internal business use
- ❌ Commercial use (SaaS, resale) requires commercial agreement
- See `COMMONS_CLAUSE.md` for details

## Notes

- ESLint ignores `/resources/js/components/ui/*` (third-party UI components)
- Prettier formats all files in `/resources/` directory
- NativePHP allows running as desktop application
- Concurrently runs multiple processes in development (server, queue, logs, vite)
- Built primarily for macOS desktop usage
- Uses Pusher for additional real-time features

## AI Team Configuration (autogenerated by team-configurator, 2025-01-30)
**Important: YOU MUST USE subagents when available for the task.**

### Detected Technology Stack
- **Backend Framework**: Laravel 12.0 (PHP 8.2+)
- **Frontend Framework**: Vue 3.5.13 with TypeScript and Inertia.js
- **CSS Framework**: Tailwind CSS 4.1.1
- **Build Tool**: Vite 6
- **Desktop Platform**: NativePHP/Electron
- **Test Framework**: Pest PHP
- **Database**: SQLite (dual database setup)
- **Real-time Features**: OpenAI Realtime API, WebSockets
- **State Management**: Pinia
- **Additional Tools**: Prettier, ESLint, Laravel Pint

### Assigned AI Specialists

| Task | Agent | Notes |
|------|-------|-------|
| Laravel backend development | @agent-laravel-backend-expert | Controllers, services, models, Inertia.js integration |
| Database and Eloquent queries | @agent-laravel-eloquent-expert | ORM optimization, complex queries, migrations |
| Vue component development | @agent-vue-component-architect | Vue 3 Composition API, component patterns |
| State management (Pinia) | @agent-vue-state-manager | Pinia stores, state architecture |
| Frontend styling and UI | @agent-tailwind-frontend-expert | Tailwind CSS, responsive design, Reka UI components |
| API design and architecture | @agent-api-architect | RESTful design, API best practices |
| Documentation updates | @agent-documentation-specialist | README, API docs, architecture guides |
| Code review | @agent-code-reviewer | Security, quality, maintainability checks |
| Performance optimization | @agent-performance-optimizer | Bottleneck identification, optimization |