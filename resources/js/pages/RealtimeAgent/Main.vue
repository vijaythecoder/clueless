<template>
    <div
        class="bg-dot-pattern scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent flex min-h-screen flex-col overflow-y-auto bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100"
        :class="{
            'screen-protection-active': isProtectionEnabled,
            'screen-protection-filter': isProtectionEnabled,
            'overlay-mode-active': isOverlayMode,
        }"
    >
        <!-- Screen Protection Overlay -->
        <div v-if="isProtectionEnabled" class="screen-protection-overlay" aria-hidden="true"></div>

        <!-- Professional Navigation Title Bar -->
        <div
            class="title-bar flex h-10 flex-shrink-0 items-center border-b border-gray-200 bg-gray-50 px-3 md:px-6 dark:border-gray-700 dark:bg-gray-900"
            style="-webkit-app-region: drag"
        >
            <!-- Left: App Title (with space for macOS controls) -->
            <div class="flex-1 pl-16 md:pl-20">
                <span class="text-xs font-semibold text-gray-800 md:text-sm dark:text-gray-200">Clueless</span>
            </div>

            <!-- Mobile Menu Button (visible on small screens) -->
            <button
                @click="showMobileMenu = !showMobileMenu"
                class="p-1.5 text-gray-600 transition-colors hover:text-gray-900 md:hidden dark:text-gray-400 dark:hover:text-gray-100"
                style="-webkit-app-region: no-drag"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path v-if="!showMobileMenu" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <!-- Right Side: All Controls (hidden on mobile, visible on desktop) -->
            <div class="hidden items-center gap-4 md:flex lg:gap-6" style="-webkit-app-region: no-drag">
                <!-- Coach Selector -->
                <div class="relative">
                    <button
                        @click="showCoachDropdown = !showCoachDropdown"
                        :disabled="isActive"
                        class="flex items-center gap-1.5 text-xs text-gray-600 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        :class="{ 'cursor-not-allowed opacity-50': isActive }"
                    >
                        <span>Coach:</span>
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ selectedTemplate?.name || 'Select' }}</span>
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <!-- Coach Dropdown Menu -->
                    <div
                        v-if="showCoachDropdown"
                        class="absolute top-full right-0 left-0 z-50 mt-2 flex max-h-96 w-full flex-col rounded-lg bg-white shadow-xl md:right-0 md:left-auto md:w-80 dark:bg-gray-800"
                    >
                        <!-- Search Input -->
                        <div class="border-b border-gray-200 p-3 dark:border-gray-700">
                            <input
                                v-model="templateSearchQuery"
                                type="text"
                                placeholder="Search templates..."
                                class="w-full rounded border border-gray-200 bg-white px-3 py-1.5 text-sm transition-all focus:border-transparent focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                                @click.stop
                            />
                        </div>

                        <!-- Template List -->
                        <div
                            class="scrollbar-thin scrollbar-thumb-gray-300 scrollbar-track-transparent hover:scrollbar-thumb-gray-400 flex-1 overflow-y-auto"
                        >
                            <div v-if="filteredTemplates.length === 0" class="p-3 text-center text-xs text-gray-600 dark:text-gray-400">
                                No templates found
                            </div>
                            <div
                                v-for="template in filteredTemplates"
                                :key="template.id"
                                @click="
                                    selectTemplateFromDropdown(template);
                                    showCoachDropdown = false;
                                    templateSearchQuery = '';
                                "
                                class="cursor-pointer px-3 py-1.5 transition-colors hover:bg-gray-50/50 dark:hover:bg-gray-700/50"
                                :class="{ 'border-l-2 border-blue-500 bg-blue-50/50': selectedTemplate?.id === template.id }"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="text-sm">{{ getIconEmoji(template.icon) }}</span>
                                    <p class="text-xs text-gray-900 dark:text-gray-100">{{ template.name }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Connection Status -->
                <div class="flex items-center gap-1.5">
                    <div
                        :class="[
                            'h-2 w-2 rounded-full',
                            connectionStatus === 'connected'
                                ? 'bg-green-500'
                                : connectionStatus === 'connecting'
                                  ? 'animate-pulse bg-yellow-500'
                                  : 'bg-red-500',
                        ]"
                    ></div>
                    <span class="text-xs text-gray-600 dark:text-gray-400">
                        {{ connectionStatus }}
                    </span>
                </div>

                <!-- Divider -->
                <div class="h-4 w-px bg-gray-300 dark:bg-gray-600"></div>

                <!-- Screen Protection Toggle -->
                <button
                    @click="toggleProtection"
                    class="flex items-center gap-1.5 text-xs transition-colors"
                    :class="[
                        isProtectionSupported
                            ? isProtectionEnabled
                                ? 'text-green-600 hover:text-green-700 dark:text-green-400 dark:hover:text-green-300'
                                : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100'
                            : 'text-orange-600 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300',
                    ]"
                    :title="
                        !isProtectionSupported
                            ? 'Screen protection not available'
                            : isProtectionEnabled
                              ? 'Screen protection is ON'
                              : 'Screen protection is OFF'
                    "
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            v-if="isProtectionEnabled"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                        />
                        <path
                            v-else
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                        />
                    </svg>
                    <span class="font-medium">{{ !isProtectionSupported ? 'N/A' : isProtectionEnabled ? 'Protected' : 'Protect' }}</span>
                </button>

                <!-- Overlay Mode Toggle -->
                <button
                    v-if="isOverlaySupported"
                    @click="toggleOverlayMode"
                    class="flex items-center gap-1.5 text-xs transition-colors"
                    :class="[
                        isOverlayMode
                            ? 'text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300'
                            : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100',
                    ]"
                    :title="isOverlayMode ? 'Exit overlay mode' : 'Enter overlay mode'"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            v-if="!isOverlayMode"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"
                        />
                        <path
                            v-else
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                        />
                    </svg>
                    <span class="font-medium">{{ isOverlayMode ? 'Normal' : 'Overlay' }}</span>
                </button>

                <!-- Actions -->
                <button
                    @click="handleDashboardClick"
                    :disabled="isActive"
                    class="text-xs font-medium text-gray-600 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                    :class="{ 'cursor-not-allowed opacity-50': isActive }"
                >
                    Dashboard
                </button>
                <button
                    @click="toggleSession"
                    class="rounded-md px-4 py-1.5 text-xs font-medium transition-colors"
                    :class="[isActive ? 'bg-red-500 text-white hover:bg-red-600' : 'bg-blue-500 text-white hover:bg-blue-600']"
                >
                    {{ isActive ? 'End Call' : 'Start Call' }}
                </button>
            </div>

            <!-- Mobile-visible Start/End Call button -->
            <button
                @click="toggleSession"
                class="ml-2 rounded-md px-3 py-1 text-xs font-medium transition-colors md:hidden"
                style="-webkit-app-region: no-drag"
                :class="[isActive ? 'bg-red-500 text-white hover:bg-red-600' : 'bg-blue-500 text-white hover:bg-blue-600']"
            >
                {{ isActive ? 'End' : 'Start' }}
            </button>
        </div>

        <!-- Mobile Menu Dropdown -->
        <div
            v-if="showMobileMenu"
            class="animate-fadeIn fixed top-10 right-0 left-0 z-[100] max-h-[80vh] overflow-y-auto border-b border-gray-200 bg-white shadow-lg md:hidden dark:border-gray-700 dark:bg-gray-900"
            @click.stop
        >
            <div class="space-y-3 px-4 py-3">
                <!-- Coach Selector in Mobile Menu -->
                <div class="border-b border-gray-100 pb-3 dark:border-gray-800">
                    <button
                        @click.stop="showCoachDropdown = !showCoachDropdown"
                        :disabled="isActive"
                        class="flex w-full items-center justify-between text-xs text-gray-600 dark:text-gray-400"
                        :class="{ 'cursor-not-allowed opacity-50': isActive }"
                        type="button"
                    >
                        <span
                            >Coach: <span class="font-medium text-gray-800 dark:text-gray-200">{{ selectedTemplate?.name || 'Select' }}</span></span
                        >
                        <svg
                            class="h-3 w-3 transition-transform"
                            :class="{ 'rotate-180': showCoachDropdown }"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <!-- Coach Dropdown inside Mobile Menu -->
                    <div v-if="showCoachDropdown" class="mt-2 rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                        <!-- Search Input -->
                        <input
                            v-model="templateSearchQuery"
                            type="text"
                            placeholder="Search templates..."
                            class="mb-2 w-full rounded border border-gray-200 bg-white px-2 py-1 text-xs focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                            @click.stop
                        />
                        <div class="max-h-36 overflow-y-auto">
                            <div v-if="filteredTemplates.length === 0" class="p-2 text-center text-xs text-gray-600 dark:text-gray-400">
                                No templates found
                            </div>
                            <button
                                v-for="template in filteredTemplates"
                                :key="template.id"
                                @click.stop="
                                    selectTemplateFromDropdown(template);
                                    showCoachDropdown = false;
                                    showMobileMenu = false;
                                    templateSearchQuery = '';
                                "
                                class="w-full cursor-pointer rounded px-2 py-1.5 text-left transition-colors hover:bg-white dark:hover:bg-gray-700"
                                :class="{ 'bg-blue-100': selectedTemplate?.id === template.id }"
                            >
                                <div class="pointer-events-none flex items-center gap-2">
                                    <span class="text-xs">{{ getIconEmoji(template.icon) }}</span>
                                    <p class="text-xs text-gray-900 dark:text-gray-100">{{ template.name }}</p>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Connection Status -->
                <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-600 dark:text-gray-400">Connection</span>
                    <div class="flex items-center gap-1.5">
                        <div
                            :class="[
                                'h-2 w-2 rounded-full',
                                connectionStatus === 'connected'
                                    ? 'bg-green-500'
                                    : connectionStatus === 'connecting'
                                      ? 'animate-pulse bg-yellow-500'
                                      : 'bg-red-500',
                            ]"
                        ></div>
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ connectionStatus }}</span>
                    </div>
                </div>

                <!-- Screen Protection -->
                <button
                    @click="
                        toggleProtection();
                        showMobileMenu = false;
                    "
                    class="flex w-full items-center justify-between text-xs"
                    :class="[
                        isProtectionSupported
                            ? isProtectionEnabled
                                ? 'text-green-600 dark:text-green-400'
                                : 'text-gray-600 dark:text-gray-400'
                            : 'text-orange-600 dark:text-orange-400',
                    ]"
                >
                    <span>Screen Protection</span>
                    <span class="font-medium">{{ !isProtectionSupported ? 'N/A' : isProtectionEnabled ? 'ON' : 'OFF' }}</span>
                </button>

                <!-- Overlay Mode -->
                <button
                    v-if="isOverlaySupported"
                    @click="
                        toggleOverlayMode();
                        showMobileMenu = false;
                    "
                    class="flex w-full items-center justify-between text-xs"
                    :class="[isOverlayMode ? 'text-blue-600 dark:text-blue-400' : 'text-gray-600 dark:text-gray-400']"
                >
                    <span>Overlay Mode</span>
                    <span class="font-medium">{{ isOverlayMode ? 'ON' : 'OFF' }}</span>
                </button>

                <!-- Dashboard Link -->
                <button
                    @click="
                        handleDashboardClick();
                        showMobileMenu = false;
                    "
                    :disabled="isActive"
                    class="w-full border-t border-gray-100 pt-3 text-left text-xs text-gray-600 dark:border-gray-800 dark:text-gray-400"
                    :class="{ 'cursor-not-allowed opacity-50': isActive }"
                >
                    Go to Dashboard →
                </button>
            </div>
        </div>

        <!-- Main Container with scrollable layout -->
        <div class="flex flex-1 flex-col p-4 pb-6">
            <!-- Main Content Area with Responsive Columns -->
            <div class="grid min-h-[calc(100vh-5rem)] grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <!-- Column 1: Live Transcription -->
                <div class="col-span-1">
                    <div class="flex h-[600px] flex-col rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                        <!-- Transcription Header -->
                        <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Live Transcription</h3>
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center gap-1.5">
                                        <div class="h-2 w-2 rounded-full bg-green-500"></div>
                                        <span class="text-xs text-gray-600 dark:text-gray-400">You</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <div class="h-2 w-2 rounded-full bg-green-500"></div>
                                        <span class="text-xs text-gray-600 dark:text-gray-400">Customer</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Transcription Content - Reversed order, newest first -->
                        <div
                            ref="transcriptContainer"
                            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent flex flex-1 flex-col-reverse overflow-y-auto p-4 pb-8"
                        >
                            <div v-if="transcriptGroups.length === 0" class="py-12 text-center">
                                <p class="text-sm text-gray-600 dark:text-gray-400">Waiting for conversation to begin...</p>
                            </div>

                            <div
                                v-for="group in [...transcriptGroups].reverse()"
                                :key="group.id"
                                class="mb-3"
                                :class="[
                                    'group relative',
                                    group.role === 'salesperson' ? 'pr-12 pl-0' : '',
                                    group.role === 'customer' ? 'pr-0 pl-12' : '',
                                    group.role === 'system' ? 'pr-6 pl-6' : '',
                                ]"
                            >
                                <div
                                    :class="[
                                        'animate-fadeIn mb-2 rounded-lg p-3',
                                        group.role === 'salesperson' ? 'bg-blue-50 text-right dark:bg-blue-900/20' : '',
                                        group.role === 'customer' ? 'bg-gray-50 text-left dark:bg-gray-800' : '',
                                        group.role === 'system' ? 'bg-yellow-50 text-center text-sm dark:bg-yellow-900/20' : '',
                                    ]"
                                >
                                    <div class="flex-1">
                                        <p v-for="(msg, idx) in group.messages" :key="idx" :class="{ 'mt-1': idx > 0 }">
                                            {{ msg.text }}
                                        </p>
                                    </div>
                                    <span class="mt-2 block text-xs text-gray-600 dark:text-gray-400">
                                        {{ formatTime(group.startTime) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Column 2: Real-time Intelligence -->
                <div class="col-span-1 flex flex-col gap-3">
                    <!-- Customer Intelligence Card -->
                    <div
                        class="flex-none rounded-lg border border-gray-200 bg-white p-4 transition-all duration-300 dark:border-gray-700 dark:bg-gray-800"
                        :class="{ 'animate-fadeIn': intelligenceUpdating }"
                    >
                        <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Customer Intelligence
                            <span v-if="intelligenceUpdating" class="animate-pulse text-xs text-gray-900 dark:text-gray-100">Updating...</span>
                        </h3>

                        <!-- Intent & Stage -->
                        <div class="mb-3 grid grid-cols-2 gap-3">
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                <p class="text-xs text-gray-600 dark:text-gray-400">Intent</p>
                                <p class="text-sm font-medium text-gray-900 capitalize dark:text-gray-100">{{ customerIntelligence.intent }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900">
                                <p class="text-xs text-gray-600 dark:text-gray-400">Stage</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ customerIntelligence.buyingStage }}</p>
                            </div>
                        </div>

                        <!-- Engagement Level -->
                        <div class="omega-metric-card">
                            <div class="mb-2 flex items-center justify-between">
                                <p class="omega-metric-label">Engagement Level</p>
                                <p class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ customerIntelligence.engagementLevel }}%</p>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-full bg-blue-500 transition-all duration-300 ease-out"
                                    :style="{ width: `${customerIntelligence.engagementLevel}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>

                    <!-- Key Insights Card -->
                    <div
                        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                        style="min-height: 200px; max-height: 300px"
                    >
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Key Insights</h3>

                        <div v-if="insights.length === 0" class="py-4 text-center">
                            <p class="text-xs text-gray-600 dark:text-gray-400">Listening for insights...</p>
                        </div>

                        <div
                            v-else
                            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent flex-1 space-y-2 overflow-y-auto"
                        >
                            <div v-for="insight in insights.slice(0, 5)" :key="insight.id" class="animate-fadeIn flex items-start gap-2">
                                <div class="flex items-start gap-2">
                                    <span
                                        :class="[
                                            'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                                            insight.type === 'pain_point'
                                                ? 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400'
                                                : insight.type === 'objection'
                                                  ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400'
                                                  : insight.type === 'positive_signal'
                                                    ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400'
                                                    : insight.type === 'concern'
                                                      ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400'
                                                      : 'bg-blue-100 text-blue-800 dark:bg-blue-900/20 dark:text-blue-400',
                                        ]"
                                    >
                                        {{ insight.type.replace('_', ' ') }}
                                    </span>
                                    <p class="flex-1 text-xs text-gray-900 dark:text-gray-100">{{ insight.text }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Discussion Topics Card -->
                    <div
                        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                        style="min-height: 120px"
                    >
                        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Discussion Topics</h3>

                        <div v-if="topics.length === 0" class="py-2 text-center">
                            <p class="text-xs text-gray-600 dark:text-gray-400">Topics will appear as discussed...</p>
                        </div>

                        <div
                            v-else
                            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent flex flex-1 flex-wrap gap-2 overflow-y-auto"
                        >
                            <div
                                v-for="topic in topics"
                                :key="topic.id"
                                :class="[
                                    'animate-fadeIn inline-flex items-center rounded-full px-2 py-1 text-xs font-medium',
                                    topic.sentiment === 'positive'
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400'
                                        : topic.sentiment === 'negative'
                                          ? 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400'
                                          : topic.sentiment === 'mixed'
                                            ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400'
                                            : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-400',
                                ]"
                            >
                                <span>{{ topic.name }}</span>
                                <span class="text-gray-600 dark:text-gray-400">{{ topic.mentions }}x</span>
                            </div>
                        </div>
                    </div>

                    <!-- Talking Points Card -->
                    <div
                        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                        v-if="selectedTemplate?.talking_points?.length > 0"
                        style="min-height: 150px"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Talking Points</h3>
                            <div class="flex items-center gap-2">
                                <div class="text-xs text-gray-600 dark:text-gray-400">
                                    {{ coveredPoints.length }}/{{ selectedTemplate.talking_points.length }} covered
                                </div>
                                <div class="omega-progress h-1.5 w-16">
                                    <div class="omega-progress-bar h-full" :style="{ width: `${talkingPointsProgress}%` }"></div>
                                </div>
                            </div>
                        </div>

                        <div
                            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent max-h-48 space-y-2 overflow-y-auto"
                        >
                            <div v-for="(point, index) in selectedTemplate.talking_points" :key="index" class="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    :id="`point-${index}`"
                                    v-model="coveredPoints"
                                    :value="index"
                                    class="mt-0.5 cursor-pointer rounded border-gray-200 dark:border-gray-700"
                                />
                                <label
                                    :for="`point-${index}`"
                                    class="flex-1 cursor-pointer text-xs text-gray-900 dark:text-gray-100"
                                    :class="{ 'text-gray-600 line-through dark:text-gray-400': coveredPoints.includes(index) }"
                                >
                                    {{ point }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Column 3: Contextual & Actions -->
                <div class="col-span-1 flex flex-col gap-3 md:col-span-2 xl:col-span-1">
                    <!-- Contextual Information Card -->
                    <div style="min-height: 150px">
                        <ContextualInformation
                            :prompt="selectedTemplate?.prompt || ''"
                            :conversation-context="conversationContext"
                            :last-customer-message="lastCustomerMessage"
                        />
                    </div>

                    <!-- Commitments Made Card -->
                    <div
                        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                        style="min-height: 200px"
                    >
                        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Commitments Made</h3>

                        <div v-if="commitments.length === 0" class="text-xs text-gray-600 dark:text-gray-400">No commitments captured yet...</div>

                        <div v-else class="omega-scrollbar max-h-48 space-y-1.5 overflow-y-auto">
                            <div v-for="commitment in commitments" :key="commitment.id" class="flex items-start gap-2 py-2 text-xs">
                                <div class="flex items-start gap-2">
                                    <span
                                        :class="[
                                            'mt-1 h-2 w-2 flex-shrink-0 rounded-full',
                                            commitment.speaker === 'salesperson' ? 'bg-green-500' : 'bg-gray-400',
                                        ]"
                                    ></span>
                                    <div class="flex-1">
                                        <span
                                            :class="[
                                                'font-medium',
                                                commitment.speaker === 'salesperson'
                                                    ? 'text-gray-900 dark:text-gray-100'
                                                    : 'text-gray-900 dark:text-gray-100',
                                            ]"
                                        >
                                            {{ commitment.speaker === 'salesperson' ? 'You:' : 'Customer:' }}
                                        </span>
                                        <span class="ml-1 text-gray-600 dark:text-gray-400">{{ commitment.text }}</span>
                                        <span v-if="commitment.deadline" class="ml-1 text-gray-600 dark:text-gray-400">
                                            ({{ commitment.deadline }})
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Post-Call Actions Card -->
                    <div
                        class="flex flex-col rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                        style="min-height: 200px"
                    >
                        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Post-Call Actions</h3>

                        <div v-if="actionItems.length === 0" class="text-xs text-gray-600 dark:text-gray-400">Action items will appear here...</div>

                        <div v-else class="omega-scrollbar max-h-48 space-y-1.5 overflow-y-auto">
                            <div v-for="item in actionItems" :key="item.id" class="flex items-start gap-2 py-2">
                                <div class="flex items-start gap-2">
                                    <input type="checkbox" v-model="item.completed" class="omega-border mt-0.5 rounded" />
                                    <label class="flex-1 text-xs text-gray-900 dark:text-gray-100">
                                        {{ item.text }}
                                        <span v-if="item.deadline" class="text-gray-600 dark:text-gray-400"> - Due: {{ item.deadline }} </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
// Unused imports commented out - these are dynamically imported later
// import { SystemAudioCapture, isSystemAudioAvailable } from '@/services/audioCapture'
import { useOverlayMode } from '@/composables/useOverlayMode';
import { useScreenProtection } from '@/composables/useScreenProtection';
import { useVariables } from '@/composables/useVariables';
import { audioHealthMonitor, type AudioHealthStatus } from '@/services/audioHealthCheck';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useSettingsStore } from '@/stores/settings';
// import type { TemplateResolution } from '@/types/variable'
import ContextualInformation from '@/components/ContextualInformation.vue';

interface Script {
    id: string;
    text: string;
    displayText?: string;
    context: string;
    priority: 'high' | 'normal' | 'low';
    used: boolean;
    timestamp: Date;
    isStreaming?: boolean;
    rawArgs?: string;
}

interface Topic {
    id: string;
    name: string;
    firstMentioned: number;
    lastMentioned: number;
    mentions: number;
    sentiment: 'positive' | 'negative' | 'neutral' | 'mixed';
}

interface Commitment {
    id: string;
    speaker: 'salesperson' | 'customer';
    text: string;
    context: string;
    timestamp: number;
    type: 'promise' | 'next_step' | 'deliverable';
    deadline?: string;
    completed?: boolean;
}

interface Insight {
    id: string;
    type: 'pain_point' | 'objection' | 'positive_signal' | 'concern' | 'question';
    text: string;
    importance: 'high' | 'medium' | 'low';
    timestamp: number;
    addressed: boolean;
}

interface ActionItem {
    id: string;
    text: string;
    owner: 'salesperson' | 'customer' | 'both';
    type: 'follow_up' | 'send_info' | 'schedule' | 'internal';
    deadline?: string;
    completed: boolean;
    relatedCommitment?: string;
}

interface CustomerIntelligence {
    intent: 'research' | 'evaluation' | 'decision' | 'implementation' | 'unknown';
    sentiment: 'positive' | 'negative' | 'neutral';
    engagementLevel: number; // 0-100
    buyingStage: string;
}

interface TranscriptGroup {
    id: string;
    role: 'salesperson' | 'customer' | 'system';
    messages: Array<{ text: string; timestamp: number }>;
    startTime: number;
    endTime: number;
    systemCategory?: 'info' | 'warning' | 'success' | 'error';
}

interface Template {
    id: string;
    name: string;
    description: string;
    prompt: string;
    icon: string;
    category: string;
    is_system: boolean;
    usage_count: number;
    variables?: Record<string, string>;
    talking_points?: string[];
    additional_info?: Record<string, any>;
}

// State
const connectionStatus = ref<'disconnected' | 'connecting' | 'connected'>('disconnected');
const isActive = ref(false);
const isEndingCall = ref(false); // Track if we're intentionally ending the call
const audioLevel = ref(0);
const systemAudioLevel = ref(0);
// eslint-disable-next-line @typescript-eslint/no-unused-vars
const scripts = ref<Script[]>([]); // Keep for now, may phase out - used in WebSocket handlers
const transcript = ref<{ role: string; text: string; timestamp: number }[]>([]);
const transcriptGroups = ref<TranscriptGroup[]>([]);
const microphoneStatus = ref<'inactive' | 'active' | 'error'>('inactive');
const systemAudioStatus = ref<'inactive' | 'active' | 'error' | 'unsupported'>('inactive');
const lastAudioSource = ref<'salesperson' | 'customer' | null>(null);
const audioSourceBuffer = ref<Array<{ source: 'salesperson' | 'customer'; timestamp: number }>>([]);
const AUDIO_BUFFER_SIZE = 50; // Keep last 50 audio chunks for tracking
// const showDebugInfo = ref(false)
// Note: streaming text and function calls are now handled by individual agents
const systemAudioCapture = ref<any>(null); // System audio capture instance
// const isRestartingAudio = ref(false) // Used by commented out restartSystemAudio
// const audioRestartAttempts = ref(0) // Used by commented out restartSystemAudio
// const maxAudioRestartAttempts = 3 // Used by commented out restartSystemAudio
const audioHealthStatus = ref<AudioHealthStatus | null>(null);
// const showDiagnostics = ref(false)

// New intelligence data
const topics = ref<Topic[]>([]);
const commitments = ref<Commitment[]>([]);
const insights = ref<Insight[]>([]);
const actionItems = ref<ActionItem[]>([]);
const customerIntelligence = ref<CustomerIntelligence>({
    intent: 'unknown',
    sentiment: 'neutral',
    engagementLevel: 50,
    buyingStage: 'Discovery',
});
const intelligenceUpdating = ref(false);

// Template management
const templates = ref<Template[]>([]);
const selectedTemplate = ref<Template | null>(null);

// Variables management
const { getVariablesAsRecord, loadVariables } = useVariables();

// Talking points tracking
const coveredPoints = ref<number[]>([]);
const talkingPointsProgress = computed(() => {
    if (!selectedTemplate.value?.talking_points?.length) return 0;
    return Math.round((coveredPoints.value.length / selectedTemplate.value.talking_points.length) * 100);
});

// Quick reference tracking
// const quickReferenceExpanded = ref(true)
const highlightedReference = ref<string | null>(null);

// Contextual information tracking
const lastCustomerMessage = ref<string>('');
const conversationContext = computed(() => {
    // Get last 3 messages from transcript for context
    const recentMessages = transcript.value.slice(-3);
    return recentMessages.map((msg) => msg.text).join(' ');
});

// Refs
const transcriptContainer = ref<HTMLDivElement>();

// Optimized architecture - Customer audio drives coaching directly
let wsSalesperson: WebSocket | null = null; // Microphone audio - simple transcription
let wsCustomerCoach: WebSocket | null = null; // System audio + AI coaching combined
const salespersonContext = ref(''); // Track what salesperson is saying

let micStream: MediaStream | null = null;
// System audio is handled by Swift helper, not MediaStream
let audioContext: AudioContext | null = null;
let micProcessor: ScriptProcessorNode | null = null;
// System audio processor not needed - handled by Swift helper

// Conversation state for coordinator
const conversationHistory = ref<Array<{ speaker: 'salesperson' | 'customer'; text: string; timestamp: number }>>([]);
// Note: pending coaching requests handled by individual WebSocket connections
const lastConversationTime = ref(Date.now());
// const silenceCheckInterval = ref<NodeJS.Timeout | null>(null) // Not currently used

// Response management
let activeResponseId: string | null = null;
let customerSpeechTimeout: NodeJS.Timeout | null = null;
let customerSpeechBuffer: string[] = [];
const SPEECH_DEBOUNCE_MS = 500; // Reduced from 1000ms to capture transitions better
let isAnalyzing = false; // Track if we're currently analyzing

// Function call accumulator for streaming
const functionCallAccumulator = new Map<string, string>();

// Track last speaker to detect transitions
let lastSpeaker: 'salesperson' | 'customer' | null = null;
let lastSpeechTime = 0;

// Conversation session tracking
const currentSessionId = ref<number | null>(null);
const isSavingData = ref(false);
const transcriptQueue: Array<{ speaker: string; text: string; timestamp: number; groupId?: string; systemCategory?: string }> = [];
const insightQueue: Array<{ type: string; data: any; timestamp: number }> = [];
let saveInterval: NodeJS.Timeout | null = null;


// Coach dropdown
const showCoachDropdown = ref(false);
const showMobileMenu = ref(false);
const templateSearchQuery = ref('');

// Screen protection
const { isProtectionEnabled, isProtectionSupported, toggleProtection, enableForCall } = useScreenProtection();

// Overlay mode
const { isOverlayMode, isSupported: isOverlaySupported, toggleOverlayMode } = useOverlayMode();

// Computed
const hasApiKey = ref(false); // Will be checked on mount
// const isDev = computed(() => import.meta.env.DEV)

// All available templates (no category filtering)
const allTemplates = computed(() => {
    return templates.value;
});

// Filtered templates based on search
const filteredTemplates = computed(() => {
    if (!templateSearchQuery.value) return allTemplates.value;

    const query = templateSearchQuery.value.toLowerCase();
    return allTemplates.value.filter((t) => t.name.toLowerCase().includes(query) || t.description?.toLowerCase().includes(query));
});

// New computed properties for intelligence dashboard
// const recentInsights = computed(() => insights.value.slice(0, 5))
// const activeCommitments = computed(() => commitments.value.filter(c => !c.completed))
// const pendingActions = computed(() => actionItems.value.filter(a => !a.completed))

// Dynamic card visibility
// const showTalkingPoints = computed(() => selectedTemplate.value?.talking_points?.length > 0)
// const showInsights = computed(() => insights.value.length > 0)
// const showTopics = computed(() => topics.value.length > 0)
// const showCommitments = computed(() => commitments.value.length > 0)
// const showActionItems = computed(() => actionItems.value.length > 0)

// Call duration
const callStartTime = ref<Date | null>(null);
const callDurationSeconds = ref(0);
let durationInterval: NodeJS.Timeout | null = null;

// const callDuration = computed(() => {
//   const minutes = Math.floor(callDurationSeconds.value / 60)
//   const seconds = callDurationSeconds.value % 60
//   return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
// })

// Helper function to format time
const formatTime = (timestamp: number) => {
    const date = new Date(timestamp);
    return date.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });
};

// Helper to add message to transcript groups
const addToTranscript = (role: 'salesperson' | 'customer' | 'system', text: string, systemCategory?: 'info' | 'warning' | 'success' | 'error') => {
    const timestamp = Date.now();
    const MESSAGE_GROUP_TIMEOUT = 5000; // 5 seconds to group messages

    // Add to raw transcript for reference
    transcript.value.push({ role, text, timestamp });

    // Update last customer message for contextual information
    if (role === 'customer') {
        lastCustomerMessage.value = text;
    }

    // Queue for database saving
    if (currentSessionId.value) {
        transcriptQueue.push({
            speaker: role,
            text,
            timestamp,
            groupId: undefined, // Will be set based on grouping logic
            systemCategory,
        });
    }

    // Check if we should append to last group
    const lastGroup = transcriptGroups.value[transcriptGroups.value.length - 1];

    if (
        lastGroup &&
        lastGroup.role === role &&
        timestamp - lastGroup.endTime < MESSAGE_GROUP_TIMEOUT &&
        // Don't group system messages with different categories
        (role !== 'system' || lastGroup.systemCategory === systemCategory)
    ) {
        // Append to existing group
        lastGroup.messages.push({ text, timestamp });
        lastGroup.endTime = timestamp;
    } else {
        // Create new group
        const newGroup: TranscriptGroup = {
            id: `group-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
            role,
            messages: [{ text, timestamp }],
            startTime: timestamp,
            endTime: timestamp,
            systemCategory,
        };
        transcriptGroups.value.push(newGroup);
    }

    // Trigger scroll
    scrollTranscriptToTop();
};

// Auto-scroll transcript to bottom (for flex-col-reverse layout)
const scrollTranscriptToTop = () => {
    nextTick(() => {
        if (transcriptContainer.value) {
            // For flex-col-reverse, scrolling to max height shows the newest messages
            transcriptContainer.value.scrollTop = transcriptContainer.value.scrollHeight;
        }
    });
};

// Watch for transcript changes and auto-scroll to top
watch(
    () => transcriptGroups.value.length,
    () => {
        scrollTranscriptToTop();
    },
);

// Watch for topics that might relate to reference info
watch(topics, (newTopics) => {
    if (!selectedTemplate.value?.additional_info) return;

    // Simple keyword matching to highlight relevant reference sections
    const latestTopic = newTopics[newTopics.length - 1];
    if (!latestTopic) return;

    const topicLower = latestTopic.name.toLowerCase();
    const referenceKeys = Object.keys(selectedTemplate.value.additional_info);

    // Find matching reference section
    const matchingKey = referenceKeys.find((key) => {
        const keyLower = key.toLowerCase();
        return topicLower.includes(keyLower) || keyLower.includes(topicLower);
    });

    if (matchingKey) {
        highlightedReference.value = matchingKey;
        setTimeout(() => {
            highlightedReference.value = null;
        }, 3000);
    }
});

// Methods
// const markScriptUsed = (scriptId: string) => {
//   const scriptIndex = scripts.value.findIndex(s => s.id === scriptId)
//   if (scriptIndex !== -1) {
//     // Mark as used
//     scripts.value[scriptIndex].used = true
//
//     // Add to transcript for confirmation
//     addToTranscript('system', `✅ Used script: "${scripts.value[scriptIndex].text}"`, 'success')
//
//     // Remove the used script after a short delay for visual feedback
//     setTimeout(() => {
//       scripts.value = scripts.value.filter(s => s.id !== scriptId)
//     }, 200)
//   }
// }

const handleDashboardClick = async () => {
    if (isActive.value) {
        const confirmEnd = confirm('You have an active call. Are you sure you want to end the call and view call history?');
        if (confirmEnd) {
            await stopSession();
            router.visit('/conversations');
        }
    } else {
        router.visit('/conversations');
    }
};

const toggleSession = async () => {
    if (isActive.value) {
        await stopSession();
    } else {
        await startSession();
    }
};


const startSession = async () => {
    try {
        connectionStatus.value = 'connecting';
        callStartTime.value = new Date();
        callDurationSeconds.value = 0;

        // Auto-enable screen protection during calls
        enableForCall();

        // Start duration timer
        durationInterval = setInterval(() => {
            if (isActive.value) {
                callDurationSeconds.value++;
            }
        }, 1000);

        // Start conversation session in database
        await startConversationSession();

        // First setup audio capture (mic + system)
        await setupAudioCapture();

        // Get ephemeral key from backend
        let ephemeralKey;
        try {
            const response = await axios.post('/api/realtime/ephemeral-key');
            if (response.data.status === 'error') {
                throw new Error(response.data.message);
            }
            ephemeralKey = response.data.ephemeralKey;
        } catch (error) {
            console.error('Failed to get ephemeral key:', error);
            alert('Failed to connect: ' + (error.response?.data?.message || 'API key not configured'));
            connectionStatus.value = 'disconnected';
            return;
        }

        // Create TWO WebSocket connections for optimized architecture
        const wsUrl = `wss://api.openai.com/v1/realtime?model=gpt-4o-mini-realtime-preview-2024-12-17`;

        // 1. Salesperson Transcriber - Simple transcription only
        wsSalesperson = new WebSocket(wsUrl, ['realtime', `openai-insecure-api-key.${ephemeralKey}`, 'openai-beta.realtime-v1']);

        // 2. Customer Coach Agent - System audio + Real-time coaching
        wsCustomerCoach = new WebSocket(wsUrl, ['realtime', `openai-insecure-api-key.${ephemeralKey}`, 'openai-beta.realtime-v1']);

        // Set up Salesperson Agent (Microphone only)
        wsSalesperson.onopen = () => {

            // Add small delay to ensure WebSocket is ready
            setTimeout(() => {
                if (wsSalesperson && wsSalesperson.readyState === WebSocket.OPEN) {
                    // Configure for fast transcription only
                    const salespersonConfig = {
                        type: 'session.update',
                        session: {
                            modalities: ['text'],
                            instructions: 'Transcribe audio only. No analysis or responses.',
                            input_audio_format: 'pcm16',
                            output_audio_format: 'pcm16',
                            input_audio_transcription: {
                                model: 'gpt-4o-mini-transcribe', // Lower latency, cost-effective model
                                language: 'en', // English language for consistent transcription
                            },
                            turn_detection: {
                                type: 'server_vad',
                                threshold: 0.5, // More aggressive
                                prefix_padding_ms: 100, // Faster response
                                silence_duration_ms: 200, // Quicker detection
                            },
                        },
                    };

                    wsSalesperson.send(JSON.stringify(salespersonConfig));
                }
            }, 100);
        };

        // Set up Customer Coach Agent (System audio + AI coaching)
        wsCustomerCoach.onopen = () => {
            connectionStatus.value = 'connected';

            // Get coach instructions from template
            let coachInstructions = '';
            if (selectedTemplate.value) {
                // Get global variables
                const globalVariables = getVariablesAsRecord();

                // Get template-specific variables
                const templateVariables = selectedTemplate.value.variables || {};

                // Merge variables (template variables override global ones)
                const mergedVariables = { ...globalVariables, ...templateVariables };

                // Replace variables in the prompt
                coachInstructions = selectedTemplate.value.prompt;
                Object.entries(mergedVariables).forEach(([key, value]) => {
                    coachInstructions = coachInstructions.replace(new RegExp(`\\{${key}\\}`, 'g'), String(value));
                });
            } else {
                coachInstructions = `You are an intelligent sales assistant analyzing conversations in real-time.

Your job is to:
1. Track discussion topics with track_discussion_topic
2. Detect commitments from both parties with detect_commitment  
3. Analyze customer intent/stage with analyze_customer_intent
4. Capture key insights with highlight_insight
5. Create post-call action items with create_action_item
6. IMPORTANT: Use detect_information_need when customer asks about or needs specific information

For detect_information_need, watch for:
- Questions about pricing, features, integration, support, implementation
- Comparisons with competitors
- Technical specifications or requirements
- Timeline or deployment questions
- Security, compliance, or data privacy concerns
- ROI or value proposition inquiries

When detecting information needs:
- Set urgency to 'high' for direct questions ("What's the price?", "How does X work?")
- Set urgency to 'medium' for exploratory discussions
- Include the full customer question/statement in the context field
- Be specific about the topic (e.g., "pricing" not just "information")

Focus on:
- What topics are being discussed (features, pricing, timeline, competitors)
- What each party commits to ("I'll send...", "We'll provide...", "Let me check...")
- Customer's buying stage (research, evaluation, decision, implementation)
- Pain points, objections, positive signals, concerns
- Action items for follow-up

Be thorough but not intrusive. Extract actionable intelligence to help guide the sales rep.`;
            }

            // Configure for transcription + coaching
            const customerCoachConfig = {
                type: 'session.update',
                session: {
                    modalities: ['text'],
                    instructions: coachInstructions,
                    input_audio_format: 'pcm16',
                    output_audio_format: 'pcm16',
                    input_audio_transcription: {
                        model: 'gpt-4o-mini-transcribe', // Lower latency, cost-effective model
                        language: 'en', // English language for consistent transcription
                    },
                    tools: [
                        {
                            type: 'function',
                            name: 'track_discussion_topic',
                            description: 'Track or update a discussion topic',
                            parameters: {
                                type: 'object',
                                properties: {
                                    name: { type: 'string', description: 'Topic name (e.g., "Pricing", "Integration", "Timeline")' },
                                    sentiment: { type: 'string', enum: ['positive', 'negative', 'neutral', 'mixed'] },
                                    context: { type: 'string', description: 'Brief context about the discussion' },
                                },
                                required: ['name', 'sentiment'],
                            },
                        },
                        {
                            type: 'function',
                            name: 'detect_commitment',
                            description: 'Log a commitment made by either party',
                            parameters: {
                                type: 'object',
                                properties: {
                                    speaker: { type: 'string', enum: ['salesperson', 'customer'] },
                                    text: { type: 'string', description: 'What was committed' },
                                    type: { type: 'string', enum: ['promise', 'next_step', 'deliverable'] },
                                    deadline: { type: 'string', description: 'When it should be done (optional)' },
                                    context: { type: 'string', description: 'Additional context' },
                                },
                                required: ['speaker', 'text', 'type'],
                            },
                        },
                        {
                            type: 'function',
                            name: 'analyze_customer_intent',
                            description: 'Update customer intelligence analysis',
                            parameters: {
                                type: 'object',
                                properties: {
                                    intent: { type: 'string', enum: ['research', 'evaluation', 'decision', 'implementation', 'unknown'] },
                                    buyingStage: { type: 'string', description: 'Current stage in buying process' },
                                    sentiment: { type: 'string', enum: ['positive', 'negative', 'neutral'] },
                                    engagementLevel: { type: 'number', minimum: 0, maximum: 100 },
                                },
                                required: ['intent', 'sentiment', 'engagementLevel'],
                            },
                        },
                        {
                            type: 'function',
                            name: 'highlight_insight',
                            description: 'Capture a key insight from the conversation',
                            parameters: {
                                type: 'object',
                                properties: {
                                    type: { type: 'string', enum: ['pain_point', 'objection', 'positive_signal', 'concern', 'question'] },
                                    text: { type: 'string', description: 'The insight text' },
                                    importance: { type: 'string', enum: ['high', 'medium', 'low'] },
                                },
                                required: ['type', 'text', 'importance'],
                            },
                        },
                        {
                            type: 'function',
                            name: 'create_action_item',
                            description: 'Create a post-call action item',
                            parameters: {
                                type: 'object',
                                properties: {
                                    text: { type: 'string', description: 'What needs to be done' },
                                    owner: { type: 'string', enum: ['salesperson', 'customer', 'both'] },
                                    type: { type: 'string', enum: ['follow_up', 'send_info', 'schedule', 'internal'] },
                                    deadline: { type: 'string', description: 'When it should be done' },
                                    relatedCommitment: { type: 'string', description: 'ID of related commitment if any' },
                                },
                                required: ['text', 'owner', 'type'],
                            },
                        },
                        {
                            type: 'function',
                            name: 'detect_information_need',
                            description: 'Detect when customer is asking about or discussing topics that require specific information',
                            parameters: {
                                type: 'object',
                                properties: {
                                    topic: {
                                        type: 'string',
                                        description: 'The main topic being discussed (e.g., "pricing", "features", "integration")',
                                    },
                                    context: { type: 'string', description: 'The specific context or question from the customer' },
                                    urgency: {
                                        type: 'string',
                                        enum: ['high', 'medium', 'low'],
                                        description: 'How urgently the information is needed',
                                    },
                                },
                                required: ['topic', 'context'],
                            },
                        },
                    ],
                    turn_detection: {
                        type: 'server_vad',
                        threshold: 0.6, // Very aggressive
                        prefix_padding_ms: 50, // Minimal padding
                        silence_duration_ms: 150, // Fast detection
                    },
                },
            };

            // Send immediately
            wsCustomerCoach.send(JSON.stringify(customerCoachConfig));
        };

        // Set up message handlers for both agents

        // Add error handlers
        wsSalesperson.onerror = (error) => {
            console.error('❌ Salesperson WebSocket error:', error);
            addToTranscript('system', '⚠️ Salesperson connection error. Reconnecting...', 'error');
        };

        wsSalesperson.onclose = () => {
            if (isActive.value) {
                addToTranscript('system', '⚠️ Salesperson connection lost. Please restart the session.', 'error');
            }
        };

        wsCustomerCoach.onerror = (error) => {
            console.error('❌ Customer Coach WebSocket error:', error);
            addToTranscript('system', '⚠️ Customer Coach connection error. Reconnecting...', 'error');
        };

        wsCustomerCoach.onclose = () => {
            if (isActive.value) {
                addToTranscript('system', '⚠️ Customer Coach connection lost. Please restart the session.', 'error');
                connectionStatus.value = 'disconnected';
            }
        };

        // Salesperson transcriber message handler
        wsSalesperson.onmessage = (event) => {
            const data = JSON.parse(event.data);

            switch (data.type) {
                case 'session.created':
                    break;

                case 'conversation.item.input_audio_transcription.completed':
                    if (data.transcript) {

                        // Track speaker transitions
                        const now = Date.now();
                        lastSpeaker = 'salesperson';
                        lastSpeechTime = now;

                        // Update last conversation time
                        lastConversationTime.value = now;
                        salespersonContext.value = data.transcript;

                        // Update UI transcript
                        addToTranscript('salesperson', data.transcript);

                        // Send context update to customer coach
                        if (wsCustomerCoach && wsCustomerCoach.readyState === WebSocket.OPEN) {
                            // Only send significant updates (not just "um" or "uh")
                            if (data.transcript.length > 5 && !data.transcript.match(/^(um|uh|ah|hmm)$/i)) {
                                wsCustomerCoach.send(
                                    JSON.stringify({
                                        type: 'conversation.item.create',
                                        item: {
                                            type: 'message',
                                            role: 'user',
                                            content: [
                                                {
                                                    type: 'input_text',
                                                    text: `CONTEXT UPDATE: Salesperson just said: "${data.transcript}". Adjust future suggestions based on this.`,
                                                },
                                            ],
                                        },
                                    }),
                                );
                            }
                        }
                    }
                    break;
            }
        };

        // Customer Coach agent message handler (transcribes + coaches)
        wsCustomerCoach.onmessage = (event) => {
            const data = JSON.parse(event.data);

            // Enhanced logging for debugging
            if (data.type.includes('function')) {
            } else if (data.type.includes('transcription')) {
            } else if (data.type === 'response.function_call_arguments.delta') {
            } else {
            }

            switch (data.type) {
                case 'session.created':
                    isActive.value = true;
                    break;

                case 'response.created':
                    activeResponseId = data.response?.id || null;
                    // Reset analyzing flag when response is created
                    isAnalyzing = false;
                    break;

                case 'conversation.item.input_audio_transcription.completed':
                    if (data.transcript) {

                        // Update UI immediately
                        addToTranscript('customer', data.transcript);

                        // Update conversation tracking
                        lastConversationTime.value = Date.now();
                        conversationHistory.value.push({
                            speaker: 'customer',
                            text: data.transcript,
                            timestamp: Date.now(),
                        });

                        // Add to speech buffer for debounced analysis
                        customerSpeechBuffer.push(data.transcript);

                        // Track speaker transitions
                        const now = Date.now();
                        const timeSinceLastSpeech = now - lastSpeechTime;
                        const speakerChanged = lastSpeaker && lastSpeaker !== 'customer';

                        // Update tracking
                        lastSpeaker = 'customer';
                        lastSpeechTime = now;

                        // Detect important speech patterns that should trigger immediate analysis
                        const shouldAnalyzeImmediately =
                            (customerSpeechBuffer.length > 5 || // Enough content accumulated
                                data.transcript.includes('?') || // Question asked
                                data.transcript.includes('.') || // Complete statement
                                (speakerChanged && timeSinceLastSpeech < 2000)) && // Quick speaker transition
                            !isAnalyzing;

                        // Cancel any pending analysis
                        if (customerSpeechTimeout) {
                            clearTimeout(customerSpeechTimeout);
                        }

                        if (shouldAnalyzeImmediately) {
                            // Analyze immediately for important content
                            analyzeCustomerSpeech();
                        } else {
                            // Otherwise debounce
                            customerSpeechTimeout = setTimeout(() => {
                                analyzeCustomerSpeech();
                            }, SPEECH_DEBOUNCE_MS);
                        }
                    }
                    break;

                case 'response.function_call_arguments.done':

                    // Get accumulated arguments if available
                    const accumulatedArgs = functionCallAccumulator.get(data.call_id || '') || data.arguments;

                    if (data.name && accumulatedArgs) {
                        try {
                            const args = JSON.parse(accumulatedArgs);
                            handleFunctionCall(data.name, args);

                            // Clear accumulator
                            if (data.call_id) {
                                functionCallAccumulator.delete(data.call_id);
                            }
                        } catch (e) {
                            console.error('❌ Failed to parse function arguments:', e);
                            console.error('Accumulated args:', accumulatedArgs);
                        }
                    }
                    break;

                case 'response.function_call_arguments.delta':
                    // Accumulate streaming arguments
                    if (data.call_id && data.arguments) {
                        const existing = functionCallAccumulator.get(data.call_id) || '';
                        functionCallAccumulator.set(data.call_id, existing + data.arguments);
                    }
                    break;

                case 'error':
                    console.error('❌ Customer Coach error:', data.error);
                    activeResponseId = null;
                    isAnalyzing = false;

                    // Check if it's the "already has active response" error
                    if (data.error?.message?.includes('already has active response')) {
                    }
                    break;

                case 'response.done':
                    activeResponseId = null;
                    isAnalyzing = false;
                    break;

                case 'response.cancelled':
                    activeResponseId = null;
                    isAnalyzing = false;
                    break;
            }
        };

        // Old coach agent handler removed - now integrated into wsCustomerCoach
        /*
    wsCoach.onmessage = (event) => {
      const data = JSON.parse(event.data)
      
      if (data.type.includes('function')) {
      } else {
      }
      
      switch (data.type) {
        case 'session.created':
          isActive.value = true
          break
          
        case 'session.updated':
          break
          
        case 'response.function_call_arguments.done':
          if (data.name && data.arguments) {
            try {
              const args = JSON.parse(data.arguments)
              handleFunctionCall(data.name, args)
              // Reset flag to allow new responses
              coachResponsePending = false
            } catch (e) {
              console.error('❌ Failed to parse function arguments:', e)
            }
          }
          break
          
        case 'response.function_call_arguments.delta':
          if (data.name === 'show_teleprompter_script' && data.arguments) {
            // Extract streaming text progressively
            const streamingId = data.call_id || `streaming-${Date.now()}`
            
            // Find or create the streaming script
            let scriptIndex = scripts.value.findIndex(s => s.id === streamingId)
            let currentScript = scriptIndex !== -1 ? scripts.value[scriptIndex] : null
            
            // Parse the delta arguments
            try {
              // Get accumulated arguments
              const accumulatedArgs = currentScript ? (currentScript.rawArgs || '') + data.arguments : data.arguments
              
              // Try to extract text progressively
              const textMatch = accumulatedArgs.match(/"text"\s*:\s*"([^"]*)/);
              
              if (textMatch) {
                // Get the partial text (may be incomplete)
                const partialText = textMatch[1]
                
                const streamingScript = {
                  id: streamingId,
                  text: partialText,
                  displayText: partialText,
                  context: '',
                  priority: 'normal' as 'high' | 'normal' | 'low',
                  used: false,
                  timestamp: new Date(),
                  isStreaming: true,
                  rawArgs: accumulatedArgs
                }
                
                if (scriptIndex !== -1) {
                  // Update existing - only update text to show progressive streaming
                  scripts.value[scriptIndex].displayText = partialText
                  scripts.value[scriptIndex].text = partialText
                  scripts.value[scriptIndex].rawArgs = accumulatedArgs
                } else {
                  // Add new at top
                  scripts.value.unshift(streamingScript)
                  // Keep only 3 scripts visible
                  if (scripts.value.length > 3) {
                    scripts.value = scripts.value.slice(0, 3)
                  }
                }
                
                // Force update without animations
                scripts.value = [...scripts.value]
              }
            } catch (e) {
              // Silent fail during streaming
            }
          }
          break
          
        case 'conversation.item.created':
          if (data.item.type === 'message' && data.item.role === 'assistant') {
            const content = data.item.content?.[0]
            if (content?.type === 'text' && content.text) {
              transcript.value.push({
                role: 'coach',
                text: `[Coach AI] ${content.text}`
              })
            }
          }
          break
          
        case 'error':
          console.error('❌ Coach error:', data.error)
          // Reset flag on error to allow retry
          coachResponsePending = false
          break
          
        case 'response.done':
          // Reset flag when response is done
          coachResponsePending = false
          break
      }
    }
    */

        // Error handlers for all agents
        wsSalesperson.onerror = (error) => {
            console.error('❌ Salesperson WebSocket error:', error);
        };

        wsCustomerCoach.onerror = (error) => {
            console.error('❌ Customer Coach WebSocket error:', error);
            connectionStatus.value = 'disconnected';
        };

        // Close handlers
        wsSalesperson.onclose = () => {
        };

        wsCustomerCoach.onclose = () => {
            connectionStatus.value = 'disconnected';
            isActive.value = false;
        };

        // Old sendToCoach function removed - now handled directly by wsCustomerCoach
    } catch (error) {
        console.error('❌ Failed to start session:', error);
        connectionStatus.value = 'disconnected';
        isActive.value = false;
        alert(`Failed to connect: ${error instanceof Error ? error.message : 'Unknown error'}`);
    }
};

// Analyze accumulated customer speech
const analyzeCustomerSpeech = async () => {
    if (!wsCustomerCoach || wsCustomerCoach.readyState !== WebSocket.OPEN) {
        return;
    }

    if (customerSpeechBuffer.length === 0 || isAnalyzing) {
        return;
    }

    // Set analyzing flag to prevent concurrent analyses
    isAnalyzing = true;

    // Cancel any active response first - but only if we have a valid ID
    if (activeResponseId && activeResponseId !== 'pending') {
        try {
            wsCustomerCoach.send(
                JSON.stringify({
                    type: 'response.cancel',
                    response_id: activeResponseId,
                }),
            );
        } catch (error) {
            console.error('Error cancelling response:', error);
        }
        activeResponseId = null;
    }

    // Copy buffer content before clearing to avoid losing new text
    const combinedSpeech = customerSpeechBuffer.join(' ');
    customerSpeechBuffer = []; // Clear buffer


    // Create focused instruction for better results
    const instruction = `Analyze this customer statement: "${combinedSpeech}"

IMPORTANT: Use the provided function tools to capture insights:

1. Use track_discussion_topic for ANY topic mentioned (product features, pricing, timeline, competitors, etc.)
2. Use detect_commitment when customer or salesperson promises something specific
3. Use analyze_customer_intent to assess their buying stage and engagement (call this ONCE per analysis)
4. Use highlight_insight for important points (pain points, objections, positive signals)
5. Use create_action_item for follow-up tasks mentioned

Be thorough - capture ALL topics discussed, not just the main one. Each distinct topic should be tracked separately.`;

    // Create new response - ID will be assigned by the server

    wsCustomerCoach.send(
        JSON.stringify({
            type: 'response.create',
            response: {
                modalities: ['text'],
                instructions: instruction,
            },
        }),
    );
};

const handleFunctionCall = (name: string, args: any) => {

    switch (name) {
        case 'track_discussion_topic':

            // Normalize topic name for comparison
            const normalizedName = args.name.trim().toLowerCase();

            // Find existing topic or create new
            const existingTopic = topics.value.find((t) => t.name.toLowerCase() === normalizedName);

            if (existingTopic) {
                // Update existing topic
                existingTopic.mentions++;
                existingTopic.lastMentioned = Date.now();
                if (args.sentiment && args.sentiment !== existingTopic.sentiment) {
                    existingTopic.sentiment = 'mixed';
                }
            } else {
                // Add new topic
                const newTopic = {
                    id: `topic-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
                    name: args.name,
                    firstMentioned: Date.now(),
                    lastMentioned: Date.now(),
                    mentions: 1,
                    sentiment: args.sentiment || 'neutral',
                };
                topics.value.push(newTopic);

                // Queue insight for saving
                insightQueue.push({
                    type: 'topic',
                    data: newTopic,
                    timestamp: Date.now(),
                });
            }

            // Sort topics by mentions
            topics.value.sort((a, b) => b.mentions - a.mentions);

            // Also add to transcript for visibility
            if (!existingTopic) {
                addToTranscript('system', `📌 New topic: ${args.name}`, 'info');
            }
            break;

        case 'detect_commitment':

            const newCommitment = {
                id: `commit-${Date.now()}`,
                speaker: args.speaker,
                text: args.text,
                context: args.context || '',
                timestamp: Date.now(),
                type: args.type,
                deadline: args.deadline,
                completed: false,
            };

            commitments.value.push(newCommitment);

            // Queue insight for saving
            insightQueue.push({
                type: 'commitment',
                data: newCommitment,
                timestamp: Date.now(),
            });

            // Add to transcript for visibility
            addToTranscript('system', `🤝 ${args.speaker === 'salesperson' ? 'You committed' : 'Customer committed'}: ${args.text}`, 'info');
            break;

        case 'analyze_customer_intent':

            // Trigger visual feedback
            intelligenceUpdating.value = true;

            // Update customer intelligence
            customerIntelligence.value = {
                intent: args.intent,
                sentiment: args.sentiment,
                engagementLevel: args.engagementLevel,
                buyingStage: args.buyingStage || customerIntelligence.value.buyingStage,
            };


            // Add system message for significant changes
            if (args.intent !== 'unknown' && args.intent !== customerIntelligence.value.intent) {
                addToTranscript('system', `📊 Customer intent changed to: ${args.intent}`, 'info');
            }

            // Clear visual feedback after animation
            setTimeout(() => {
                intelligenceUpdating.value = false;
            }, 1000);
            break;

        case 'highlight_insight':

            const newInsight = {
                id: `insight-${Date.now()}`,
                type: args.type,
                text: args.text,
                importance: args.importance,
                timestamp: Date.now(),
                addressed: false,
            };

            insights.value.unshift(newInsight);

            // Queue insight for saving
            insightQueue.push({
                type: 'key_insight',
                data: newInsight,
                timestamp: Date.now(),
            });

            // Keep only recent insights
            if (insights.value.length > 10) {
                insights.value = insights.value.slice(0, 10);
            }

            // Log current insights to verify update

            // Add to transcript for visibility
            addToTranscript('system', `💡 ${args.type.replace('_', ' ')}: ${args.text}`, 'info');
            break;

        case 'create_action_item':

            const newActionItem = {
                id: `action-${Date.now()}`,
                text: args.text,
                owner: args.owner,
                type: args.type,
                deadline: args.deadline,
                completed: false,
                relatedCommitment: args.relatedCommitment,
            };

            actionItems.value.push(newActionItem);

            // Queue insight for saving
            insightQueue.push({
                type: 'action_item',
                data: newActionItem,
                timestamp: Date.now(),
            });
            break;

        case 'identify_speaker':
            // Update the last speaker transcript with identified role
            const lastSpeakerIndex = transcript.value.findLastIndex((t) => t.role === 'speaker');
            if (lastSpeakerIndex !== -1) {
                // Update the role based on AI's identification
                transcript.value[lastSpeakerIndex].role = args.speaker;

                // Log identification for debugging
            }
            break;

        case 'detect_information_need':

            // Update the conversation context for the contextual information card
            if (args.context) {
                lastCustomerMessage.value = args.context;
            }

            // Log for debugging
            if (args.urgency === 'high') {
                addToTranscript('system', `⚡ Customer needs information about: ${args.topic}`, 'info');
            }
            break;
    }
};

const setupAudioCapture = async () => {
    try {

        // First check if we can enumerate devices
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const audioInputs = devices.filter((d) => d.kind === 'audioinput');

            // If no devices or all have empty labels, we need permission
            if (audioInputs.length === 0 || audioInputs.every((d) => !d.label)) {
            }
        } catch (enumError) {
            console.error('Error enumerating devices:', enumError);
        }

        // Setup microphone capture for salesperson's voice
        micStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                sampleRate: 24000,
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
        microphoneStatus.value = 'active';

        // Try to setup system audio capture using Swift helper
        try {

            // Dynamic import to avoid issues in browser
            const { SystemAudioCapture, isSystemAudioAvailable } = await import('@/services/audioCapture');

            // Check if system audio capture is available
            const isAvailable = await isSystemAudioAvailable();

            if (isAvailable) {

                systemAudioCapture.value = new SystemAudioCapture();

                // Check permission first
                await systemAudioCapture.value.checkPermission();

                // Handle audio data from system
                systemAudioCapture.value.on('audio', (pcm16: Int16Array) => {
                    if (isActive.value) {
                        // Update system audio level
                        const sum = Array.from(pcm16).reduce((acc, val) => acc + Math.abs(val), 0);
                        systemAudioLevel.value = Math.min(100, (sum / pcm16.length) * 0.5);

                        // Log every 20th packet for debugging
                        if (Math.random() < 0.05) {
                            // Removed debug logging
                        }

                        // CRITICAL: Send as customer audio - this is from phone/zoom
                        sendAudioWithMetadata(pcm16, 'customer');
                    }
                });

                // Handle status updates
                systemAudioCapture.value.on('status', (state: string) => {

                    // Map status states to our UI states
                    switch (state) {
                        case 'capturing':
                            systemAudioStatus.value = 'active';
                            break;
                        case 'stopped':
                            systemAudioStatus.value = 'inactive';
                            break;
                        case 'retrying':
                            systemAudioStatus.value = 'error';
                            addToTranscript('system', '🔄 System audio is retrying capture...', 'warning');
                            break;
                        case 'restarting':
                            addToTranscript('system', '🔄 System audio is restarting...', 'warning');
                            break;
                        default:
                            systemAudioStatus.value = 'inactive';
                    }
                });

                // Handle errors
                systemAudioCapture.value.on('error', (error: Error) => {
                    console.error('❌ System audio error:', error);
                    systemAudioStatus.value = 'error';

                    // Check if it's a permission error
                    if (error.message.includes('Screen recording permission')) {
                        addToTranscript('system', '🔒 Screen Recording Permission Required', 'error');
                        addToTranscript('system', '1. System Preferences should open automatically', 'info');
                        addToTranscript('system', '2. Enable Screen Recording for this app', 'info');
                        addToTranscript('system', '3. Restart the session after granting permission', 'info');
                    } else {
                        addToTranscript('system', `⚠️ System audio error: ${error.message}`, 'error');
                    }
                });

                // Handle process exit
                systemAudioCapture.value.on('exit', (code: number) => {
                    console.error('❌ System audio process exited with code:', code);
                    systemAudioStatus.value = 'error';
                    // Only show error if we're not intentionally ending the call
                    if (!isEndingCall.value) {
                        addToTranscript('system', `⚠️ System audio capture process exited (code: ${code})`, 'error');
                    } else {
                        addToTranscript('system', '📞 Call ended', 'info');
                    }
                });

                // Start capture
                await systemAudioCapture.value.start();

                // Attach to health monitor
                audioHealthMonitor.attach(systemAudioCapture.value);

            } else {
                throw new Error('System audio capture not available');
            }
        } catch (error) {
            console.warn('⚠️ System audio capture not available:', error);
            systemAudioStatus.value = 'unsupported';

            // Fallback to mixed audio mode
            addToTranscript('system', '⚠️ System audio capture unavailable. Using mixed audio mode.', 'warning');
            addToTranscript('system', '• Use headphones to reduce echo', 'info');
            addToTranscript('system', '• AI will identify speakers based on conversation context', 'info');
        }

        // Create audio context with 24kHz sample rate (required by OpenAI)
        audioContext = new AudioContext({ sampleRate: 24000 });

        // Setup microphone processor
        const micSource = audioContext.createMediaStreamSource(micStream);
        micProcessor = audioContext.createScriptProcessor(4096, 1, 1);

        micProcessor.onaudioprocess = (event) => {
            if (isActive.value) {
                const inputData = event.inputBuffer.getChannelData(0);

                // Update audio level for visualization
                const sum = inputData.reduce((acc, val) => acc + Math.abs(val), 0);
                audioLevel.value = Math.min(100, (sum / inputData.length) * 500);

                // Convert and send audio
                const pcm16 = convertToPCM16(inputData);

                // If we have system audio, this is definitely salesperson
                // If not, it's mixed audio (both speakers in one stream)
                const audioSource = systemAudioStatus.value === 'active' ? 'salesperson' : 'mixed';

                // Log occasionally for debugging
                if (Math.random() < 0.02) {
                    // Removed debug logging
                }

                sendAudioWithMetadata(pcm16, audioSource);
            }
        };

        micSource.connect(micProcessor);
        micProcessor.connect(audioContext.destination);

        // Note: System audio is handled by the Swift helper, not through Web Audio API

    } catch (error) {
        console.error('❌ Failed to setup audio:', error);
        microphoneStatus.value = 'error';
        throw error;
    }
};

// Helper function to convert Float32Array to Int16Array
const convertToPCM16 = (float32Array: Float32Array): Int16Array => {
    const pcm16 = new Int16Array(float32Array.length);
    for (let i = 0; i < float32Array.length; i++) {
        const clamped = Math.max(-1, Math.min(1, float32Array[i]));
        pcm16[i] = Math.floor(clamped * 32767);
    }
    return pcm16;
};

// Helper function to send audio with metadata
const sendAudioWithMetadata = (pcm16: Int16Array, source: 'salesperson' | 'customer' | 'mixed') => {
    try {
        // Convert to base64 in chunks to avoid string length limitations
        const uint8Array = new Uint8Array(pcm16.buffer);
        let base64Audio = '';
        const chunkSize = 8192; // Process in 8KB chunks

        for (let i = 0; i < uint8Array.length; i += chunkSize) {
            const chunk = uint8Array.slice(i, Math.min(i + chunkSize, uint8Array.length));
            base64Audio += btoa(String.fromCharCode(...chunk));
        }

        // Route audio to the correct agent based on source
        if (source === 'salesperson' && wsSalesperson && wsSalesperson.readyState === WebSocket.OPEN) {
            // Send microphone audio to salesperson agent
            wsSalesperson.send(
                JSON.stringify({
                    type: 'input_audio_buffer.append',
                    audio: base64Audio,
                }),
            );

            if (Math.random() < 0.02) {
                // Log 2% of the time
            }
        } else if (source === 'customer' && wsCustomerCoach && wsCustomerCoach.readyState === WebSocket.OPEN) {
            // Send system audio to customer coach agent (direct to AI)
            wsCustomerCoach.send(
                JSON.stringify({
                    type: 'input_audio_buffer.append',
                    audio: base64Audio,
                }),
            );

            if (Math.random() < 0.02) {
                // Log 2% of the time
            }
        } else if (source === 'mixed') {
            // Mixed audio mode - send to both agents (not ideal but fallback)
            console.warn('⚠️ Mixed audio mode detected - speaker identification may be inaccurate');

            if (wsSalesperson && wsSalesperson.readyState === WebSocket.OPEN) {
                wsSalesperson.send(
                    JSON.stringify({
                        type: 'input_audio_buffer.append',
                        audio: base64Audio,
                    }),
                );
            }

            if (wsCustomerCoach && wsCustomerCoach.readyState === WebSocket.OPEN) {
                wsCustomerCoach.send(
                    JSON.stringify({
                        type: 'input_audio_buffer.append',
                        audio: base64Audio,
                    }),
                );
            }
        }

        // Track audio source with timestamp
        if (source !== 'mixed') {
            const timestamp = Date.now();
            lastAudioSource.value = source;

            // Add to buffer for tracking
            audioSourceBuffer.value.push({ source, timestamp });

            // Keep buffer size limited
            if (audioSourceBuffer.value.length > AUDIO_BUFFER_SIZE) {
                audioSourceBuffer.value = audioSourceBuffer.value.slice(-AUDIO_BUFFER_SIZE);
            }
        }
    } catch (e) {
        console.error('❌ Failed to send audio:', e);
    }
};

// Commented out - function not currently used
// const restartSystemAudio = async () => {
//   if (isRestartingAudio.value || !systemAudioCapture.value) return
//
//   isRestartingAudio.value = true
//   audioRestartAttempts.value++
//
//   try {
//     addToTranscript('system', '🔄 Restarting system audio capture...', 'warning')
//
//     // Restart the audio capture
//     await systemAudioCapture.value.restart()
//
//     // Reset attempt counter on success
//     audioRestartAttempts.value = 0
//
//     addToTranscript('system', '✅ System audio capture restarted successfully', 'success')
//   } catch (error) {
//     console.error('Failed to restart system audio:', error)
//
//     if (audioRestartAttempts.value >= maxAudioRestartAttempts) {
//       addToTranscript('system', `❌ Failed to restart audio after ${maxAudioRestartAttempts} attempts. Please restart the session.`, 'error')
//       systemAudioStatus.value = 'error'
//     } else {
//       addToTranscript('system', `⚠️ Audio restart failed (attempt ${audioRestartAttempts.value}/${maxAudioRestartAttempts}). Retrying...`, 'warning')
//       // Try again after a delay
//       setTimeout(() => restartSystemAudio(), 2000)
//     }
//   } finally {
//     isRestartingAudio.value = false
//   }
// }

const stopSession = async () => {
    isEndingCall.value = true; // Mark that we're intentionally ending

    // Add call ended message
    addToTranscript('system', '📞 Call ended', 'success');

    // Save any remaining data before stopping
    if (currentSessionId.value) {
        await saveQueuedData(true); // Force save
        await endConversationSession();
    }

    // Clear any pending timeouts
    if (customerSpeechTimeout) {
        clearTimeout(customerSpeechTimeout);
        customerSpeechTimeout = null;
    }

    // Clear save interval
    if (saveInterval) {
        clearInterval(saveInterval);
        saveInterval = null;
    }

    // Clear buffers and state
    customerSpeechBuffer = [];
    activeResponseId = null;
    functionCallAccumulator.clear();

    // Stop duration timer
    if (durationInterval) {
        clearInterval(durationInterval);
        durationInterval = null;
    }

    // Stop audio processors
    if (micProcessor) {
        micProcessor.disconnect();
        micProcessor = null;
    }
    // System processor cleanup not needed - handled by Swift helper
    if (audioContext) {
        await audioContext.close();
        audioContext = null;
    }

    // Stop media streams
    if (micStream) {
        micStream.getTracks().forEach((track) => track.stop());
        micStream = null;
    }

    // Stop system audio capture
    if (systemAudioCapture.value) {
        await systemAudioCapture.value.stop();
        systemAudioCapture.value = null;
    }

    microphoneStatus.value = 'inactive';
    systemAudioStatus.value = 'inactive';

    // Close all WebSocket connections
    if (wsSalesperson) {
        wsSalesperson.close();
        wsSalesperson = null;
    }
    if (wsCustomerCoach) {
        wsCustomerCoach.close();
        wsCustomerCoach = null;
    }

    isActive.value = false;
    connectionStatus.value = 'disconnected';


    // Reset the ending flag after a delay
    setTimeout(() => {
        isEndingCall.value = false;
    }, 1000);
};

// Audio level animation
const animateAudioLevel = () => {
    // Decay audio levels
    if (audioLevel.value > 0) {
        audioLevel.value = Math.max(0, audioLevel.value - 2);
    }
    if (systemAudioLevel.value > 0) {
        systemAudioLevel.value = Math.max(0, systemAudioLevel.value - 2);
    }
};

// Helper function to format uptime
// const formatUptime = (seconds: number): string => {
//   if (seconds < 60) return `${seconds}s`
//   const minutes = Math.floor(seconds / 60)
//   const remainingSeconds = seconds % 60
//   if (minutes < 60) return `${minutes}m ${remainingSeconds}s`
//   const hours = Math.floor(minutes / 60)
//   const remainingMinutes = minutes % 60
//   return `${hours}h ${remainingMinutes}m`
// }

// Conversation Session Management
const startConversationSession = async () => {
    try {
        const response = await axios.post('/conversations', {
            template_used: selectedTemplate.value?.name || null,
            customer_name: null,
            customer_company: null,
        });

        currentSessionId.value = response.data.session_id;

        // Start periodic saving
        saveInterval = setInterval(() => {
            saveQueuedData();
        }, 5000); // Save every 5 seconds
    } catch (error) {
        console.error('❌ Failed to start conversation session:', error);
    }
};

const endConversationSession = async () => {
    if (!currentSessionId.value) return;

    try {
        // Save final state
        await axios.post(`/conversations/${currentSessionId.value}/end`, {
            duration_seconds: callDurationSeconds.value,
            final_intent: customerIntelligence.value.intent,
            final_buying_stage: customerIntelligence.value.buyingStage,
            final_engagement_level: customerIntelligence.value.engagementLevel,
            final_sentiment: customerIntelligence.value.sentiment,
            ai_summary: null, // Could generate summary here
        });

        currentSessionId.value = null;
    } catch (error) {
        console.error('❌ Failed to end conversation session:', error);
    }
};

const saveQueuedData = async (force: boolean = false) => {
    if (!currentSessionId.value || isSavingData.value) return;

    // Only save if we have data or forced
    if (!force && transcriptQueue.length === 0 && insightQueue.length === 0) return;

    isSavingData.value = true;

    try {
        // Save transcripts
        if (transcriptQueue.length > 0) {
            const transcriptsToSave = [...transcriptQueue];
            transcriptQueue.length = 0; // Clear queue

            await axios.post(`/conversations/${currentSessionId.value}/transcripts`, {
                transcripts: transcriptsToSave.map((t) => ({
                    speaker: t.speaker,
                    text: t.text,
                    spoken_at: t.timestamp,
                    group_id: t.groupId,
                    system_category: t.systemCategory,
                })),
            });

        }

        // Save insights
        if (insightQueue.length > 0) {
            const insightsToSave = [...insightQueue];
            insightQueue.length = 0; // Clear queue

            await axios.post(`/conversations/${currentSessionId.value}/insights`, {
                insights: insightsToSave.map((i) => ({
                    insight_type: i.type,
                    data: i.data,
                    captured_at: i.timestamp,
                })),
            });

        }
    } catch (error) {
        console.error('❌ Failed to save conversation data:', error);
        // Re-add failed items back to queue
        // In production, implement better retry logic
    } finally {
        isSavingData.value = false;
    }
};

// Template Management Methods
const fetchTemplates = async () => {
    try {
        const response = await axios.get('/templates');
        templates.value = response.data.templates || [];

        // Load persisted template or select default
        const persistedTemplateId = localStorage.getItem('selectedTemplateId');
        if (persistedTemplateId) {
            const persistedTemplate = templates.value.find((t) => t.id === persistedTemplateId);
            if (persistedTemplate) {
                selectedTemplate.value = persistedTemplate;
            }
        }

        // Select default template if none selected
        if (!selectedTemplate.value) {
            if (templates.value.length > 0) {
                // Try to find the Sales Discovery Call template as default, otherwise use first available template
                const defaultTemplate = templates.value.find((t) => t.name === 'Sales Discovery Call') || templates.value[0];
                if (defaultTemplate) {
                    selectedTemplate.value = defaultTemplate;
                }
            } else {
                // Handle case when no templates exist
                console.warn('⚠️ No templates available - cannot select default template');
                // You may want to show a user-friendly message or redirect to template creation
            }
        }
    } catch (error) {
        console.error('❌ Failed to fetch templates:', error);
        console.error('Error details:', error.response?.data || error.message);
    }
};

const selectTemplateFromDropdown = async (template: Template) => {
    selectedTemplate.value = template;
    coveredPoints.value = []; // Reset covered points when changing template

    // Persist selection to localStorage
    localStorage.setItem('selectedTemplateId', template.id);

    // Track usage
    try {
        await axios.post(`/templates/${template.id}/use`);
    } catch (error) {
        console.error('Failed to track template usage:', error);
    }

    // If session is active, update the coach instructions
    if (isActive.value && wsCustomerCoach && wsCustomerCoach.readyState === WebSocket.OPEN) {
        updateCoachInstructions();
    }
};

const updateCoachInstructions = async () => {
    if (!selectedTemplate.value || !wsCustomerCoach || wsCustomerCoach.readyState !== WebSocket.OPEN) return;

    try {
        // Get global variables
        const globalVariables = getVariablesAsRecord();

        // Get template-specific variables
        const templateVariables = selectedTemplate.value.variables || {};

        // Merge variables (template variables override global ones)
        const mergedVariables = { ...globalVariables, ...templateVariables };

        // Replace variables in the prompt
        let prompt = selectedTemplate.value.prompt;

        // Replace all {variable} patterns
        Object.entries(mergedVariables).forEach(([key, value]) => {
            const regex = new RegExp(`\\{${key}\\}`, 'g');
            prompt = prompt.replace(regex, String(value));
        });

        // Check for any remaining unreplaced variables
        const unreplacedMatches = prompt.match(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g);
        if (unreplacedMatches) {
            console.warn('⚠️ Unreplaced variables found:', unreplacedMatches);
            // Optionally show a warning to the user
            transcript.value.push({
                role: 'system',
                text: `⚠️ Warning: Some variables could not be replaced: ${unreplacedMatches.join(', ')}`,
            });
        }

        // Update the session with new instructions
        const updateMessage = {
            type: 'session.update',
            session: {
                instructions: prompt,
            },
        };

        wsCustomerCoach.send(JSON.stringify(updateMessage));

        // Add notification to transcript
        transcript.value.push({
            role: 'system',
            text: `🎭 Switched to "${selectedTemplate.value.name}" coaching style`,
        });
    } catch (error) {
        console.error('❌ Failed to update coach instructions:', error);
        transcript.value.push({
            role: 'system',
            text: `❌ Failed to update coaching style: ${error.message}`,
        });
    }
};

const getIconEmoji = (icon: string): string => {
    const iconMap: Record<string, string> = {
        MessageSquare: '💬',
        Zap: '⚡',
        Heart: '❤️',
        Shield: '🛡️',
        TrendingUp: '📈',
        Search: '🔍',
        Code2: '🔧',
        Smile: '😊',
        Target: '🎯',
        Lightbulb: '💡',
        DollarSign: '💵',
        Calculator: '🧮',
        Layers: '🗂️',
        Star: '⭐',
        Award: '🏆',
        Users: '👥',
        Flame: '🔥',
        Rocket: '🚀',
        Phone: '📞',
        Building: '🏢',
    };
    return iconMap[icon] || '💬';
};

// Handle page reload/close during active call
const handleBeforeUnload = (e: BeforeUnloadEvent) => {
    if (isActive.value) {
        e.preventDefault();
        e.returnValue = 'You have an active call. Are you sure you want to leave?';
        return e.returnValue;
    }
};

// Demo data for visualization
onMounted(async () => {
    // Initialize settings store with composable support status
    const settingsStore = useSettingsStore();
    
    // Wait a bit for composables to check support
    setTimeout(() => {
        settingsStore.setOverlaySupported(isOverlaySupported.value);
        settingsStore.setProtectionSupported(isProtectionSupported.value);
    }, 200);
    
    // Check API key status first
    try {
        const response = await axios.get('/api/openai/status');
        hasApiKey.value = response.data.hasApiKey;

        if (!hasApiKey.value) {
            // Redirect to settings page if no API key
            window.location.href = '/settings/api-keys';
            return;
        }
    } catch (error) {
        console.error('Failed to check API key status:', error);
        window.location.href = '/settings/api-keys';
        return;
    }

    // Fetch templates and variables on mount
    await Promise.all([fetchTemplates(), loadVariables()]);

    // Check environment on mount

    // Pre-check microphone permission to trigger prompt early if needed
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const audioInputs = devices.filter((d) => d.kind === 'audioinput');

        // If we can't see device labels, we don't have permission yet
        if (audioInputs.length > 0 && audioInputs.every((d) => !d.label)) {
            // Don't request permission here - wait for user to start call
        } else if (audioInputs.length > 0) {
        } else {
        }
    } catch (error) {
        console.error('Error checking microphone status:', error);
    }

    // Start audio level animation
    const audioInterval = setInterval(animateAudioLevel, 100);

    // Start timer update interval
    const timerInterval = setInterval(() => {
        if (isActive.value && callStartTime.value) {
            const now = new Date();
            callDurationSeconds.value = Math.floor((now.getTime() - callStartTime.value.getTime()) / 1000);
        }
    }, 1000);

    // Subscribe to health monitor updates
    const unsubscribeHealth = audioHealthMonitor.subscribe((status) => {
        audioHealthStatus.value = status;
    });

    // Add beforeunload listener
    window.addEventListener('beforeunload', handleBeforeUnload);

    // Handle click outside to close dropdown
    const handleClickOutside = (event: MouseEvent) => {
        const target = event.target as HTMLElement;
        if (!target.closest('.relative')) {
            showCoachDropdown.value = false;
        }
    };
    document.addEventListener('click', handleClickOutside);

    // Clean up on unmount
    onUnmounted(() => {
        clearInterval(audioInterval);
        clearInterval(timerInterval);
        unsubscribeHealth();
        audioHealthMonitor.destroy();
        window.removeEventListener('beforeunload', handleBeforeUnload);
        document.removeEventListener('click', handleClickOutside);
    });
});

onUnmounted(() => {
    stopSession();
});
</script>

<style scoped>
/* Highlight animation for Quick Reference */
.omega-glow {
    animation: glow 2s ease-in-out;
}

@keyframes glow {
    0%,
    100% {
        background-color: transparent;
    }
    50% {
        background-color: rgba(59, 130, 246, 0.1);
        border-color: rgba(59, 130, 246, 0.3);
    }
}

/* Apple-style Glassmorphism */
.glass-card {
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
}

.glass-card-darker {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.2);
}

/* Clean, minimal transitions */
.glass-card {
    transition: all 0.3s ease;
}

.glass-card:hover {
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.25);
    transform: translateY(-1px);
}

/* Remove all script animations */

.opportunity-list-enter-active {
    transition: all 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.opportunity-list-leave-active {
    transition: all 0.3s ease-in;
}
.opportunity-list-enter-from {
    opacity: 0;
    transform: scale(0.7) translateY(-20px) rotateX(90deg);
}
.opportunity-list-leave-to {
    opacity: 0;
    transform: scale(0.8) translateX(50px);
}
.opportunity-list-move {
    transition: transform 0.5s ease;
}

/* Fade in animation */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in {
    animation: fadeIn 0.2s ease-out;
}

/* Blinking cursor animation */
@keyframes blink {
    0%,
    50% {
        opacity: 1;
    }
    51%,
    100% {
        opacity: 0;
    }
}

.animate-blink {
    animation: blink 1s infinite;
}

/* Slow spin animation */
@keyframes spin-slow {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.animate-spin-slow {
    animation: spin-slow 3s linear infinite;
}

/* Custom scrollbar for scripts */
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(0, 0, 0, 0.3);
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(139, 92, 246, 0.5);
    border-radius: 3px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: rgba(139, 92, 246, 0.7);
}

/* Glassmorphic inputs and buttons */
.glass-input {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 0.5rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    transition: all 0.2s ease;
}

.glass-input:focus {
    outline: none;
    border-color: rgba(59, 130, 246, 0.5);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.glass-button-primary {
    background: rgba(59, 130, 246, 0.8);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s ease;
}

.glass-button-primary:hover {
    background: rgba(59, 130, 246, 0.9);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.glass-button-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* Card fade transitions */
.card-fade-enter-active {
    transition: all 0.3s ease-out;
}

.card-fade-leave-active {
    transition: all 0.2s ease-in;
}

.card-fade-enter-from {
    opacity: 0;
    transform: translateY(-10px);
}

.card-fade-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}

/* Responsive grid adjustments */
@media (max-width: 1024px) {
    .grid-cols-3 {
        grid-template-columns: 1fr;
    }
}

@media (min-width: 1280px) {
    .lg\:col-span-2 {
        grid-column: span 2 / span 2;
    }
}

.glass-button-secondary {
    background: rgba(255, 255, 255, 0.3);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: rgba(31, 41, 55, 0.9);
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s ease;
}

.glass-button-secondary:hover {
    background: rgba(255, 255, 255, 0.5);
    transform: translateY(-1px);
}
</style>
