# Clueless

![License: MIT + Commons Clause](https://img.shields.io/badge/License-MIT%20%2B%20Commons%20Clause-orange)

AI-powered meeting assistant for real-time transcription, conversation analysis, and actionable insights.

![Clueless Demo](https://raw.githubusercontent.com/vijaythecoder/clueless/main/public/images/clueless.gif)

## Download

### 🖥️ Ready to use? Download the desktop app!

[![Download for macOS](https://img.shields.io/badge/Download%20for%20macOS-DMG-blue?logo=apple&logoColor=white)](https://github.com/vijaythecoder/clueless/releases)

**Requirements for running the app:**
- macOS 10.15 or later
- OpenAI API key (add it in the app settings)

No development tools needed - just download, install, and start transcribing!

## Features

- **Real-time Transcription**: Capture and transcribe conversations as they happen
- **Intelligent Analysis**: AI-powered analysis of meeting content to surface key points and themes
- **Action Item Extraction**: Automatically identify and extract action items from conversations
- **Conversation Storage**: Store and organize all your meetings and conversations for future reference
- **Insights Generation**: Get AI-generated summaries and insights from your meetings
- **Context Preservation**: Maintain conversation context across multiple sessions
- **Desktop Integration**: Native desktop app for seamless meeting assistance

## Community

Join our growing community of contributors and users!

[![Discord](https://img.shields.io/discord/1392929316559519864?color=7289DA&label=Discord&logo=discord&logoColor=white)](https://discord.gg/PhPMPrxcKw)

- 💬 [Discord Community](https://discord.gg/PhPMPrxcKw) - Chat with other users and contributors
- 🐛 [Issue Tracker](https://github.com/vijaythecoder/clueless/issues) - Report bugs or request features
- 💡 [Discussions](https://github.com/vijaythecoder/clueless/discussions) - Share ideas and get help

## Development Setup

> **Just want to use the app?** Check the [Download](#download) section above!

For developers who want to contribute or run from source:

### Developer Requirements

- PHP 8.2 or higher
- Node.js 18 or higher  
- Composer
- npm or yarn
- OpenAI API key

### Installation from Source

```bash
# Clone the repository
git clone https://github.com/vijaythecoder/clueless.git
cd clueless

# Install dependencies
composer install
npm install

# Run the desktop app
composer native:dev
```

That's it! The app will handle database setup automatically.

> **Need detailed setup?** Check out our [Contributing Guide](CONTRIBUTING.md) for complete installation instructions, environment configuration, and development commands.

## Tech Stack

- **Backend**: Laravel 12.0 (PHP 8.2+)
- **Frontend**: Vue 3.5.13 with TypeScript and Inertia.js
- **Desktop**: NativePHP/Electron
- **AI**: OpenAI Realtime API for transcription and analysis
- **Styling**: Tailwind CSS 4.1.1
- **Build**: Vite 6
- **Database**: SQLite for local conversation storage

## Usage

1. **Start a Meeting**: Launch the app and begin a new conversation session
2. **Real-time Transcription**: The app will automatically transcribe all audio in real-time
3. **Review Insights**: During or after the meeting, review AI-generated insights and summaries
4. **Extract Action Items**: View automatically identified action items and tasks
5. **Store Conversations**: All meetings are stored locally for future reference and analysis


## Contributing

Contributions are welcome! Please check out our [Contributing Guide](CONTRIBUTING.md) for:
- Development setup
- Code standards
- Pull request process
- Testing guidelines

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=vijaythecoder/clueless&type=Date)](https://www.star-history.com/#vijaythecoder/clueless&Date)

## License

This project is licensed under the [MIT License](LICENSE) with the [Commons Clause](COMMONS_CLAUSE.md).

**Personal and internal use = free. SaaS or resale? Contact us.**

### What this means:
- ✅ **Use it** for personal projects or within your company
- ✅ **Modify it** to fit your needs
- ✅ **Self-host it** on your own infrastructure
- ❌ **Cannot sell** as a hosted service or SaaS product
- ❌ **Cannot resell** 

For any questions, please reach out via [Discord](https://discord.gg/PhPMPrxcKw).
