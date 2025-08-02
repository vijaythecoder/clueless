<template>
    <div
        class="flex h-full flex-col overflow-hidden rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
    >
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Contextual Information</h3>
            <div v-if="loading" class="animate-pulse text-xs text-gray-600 dark:text-gray-400">Analyzing...</div>
        </div>

        <div v-if="!relevantSection && !loading" class="py-4 text-center">
            <p class="text-xs text-gray-600 dark:text-gray-400">Information will appear based on conversation context...</p>
        </div>

        <div
            v-else-if="relevantSection"
            class="scrollbar-thin scrollbar-thumb-gray-300 dark:scrollbar-thumb-gray-600 scrollbar-track-transparent flex-1 min-h-0 overflow-y-auto"
        >
            <div class="space-y-3">
                <transition
                    enter-active-class="transition ease-out duration-300"
                    enter-from-class="transform opacity-0 scale-95"
                    enter-to-class="transform opacity-100 scale-100"
                    leave-active-class="transition ease-in duration-200"
                    leave-from-class="transform opacity-100 scale-100"
                    leave-to-class="transform opacity-0 scale-95"
                >
                    <div class="rounded-lg border border-gray-200 bg-gray-100 p-3 dark:border-gray-700 dark:bg-gray-800" :key="relevantSection.title">
                        <h4 class="mb-1 text-xs font-semibold text-gray-700 uppercase dark:text-gray-300">
                            {{ relevantSection.title }}
                        </h4>
                        <div class="text-sm whitespace-pre-wrap text-gray-600 dark:text-gray-400">
                            {{ relevantSection.content }}
                        </div>
                    </div>
                </transition>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';

interface RelevantSection {
    title: string;
    content: string;
    keywords: string[];
}

interface Props {
    prompt: string;
    conversationContext: string;
    lastCustomerMessage?: string;
}

const props = defineProps<Props>();

const loading = ref(false);
const relevantSection = ref<RelevantSection | null>(null);
const lastUpdateTime = ref(0);

// Extract sections from prompt
const extractSections = (prompt: string): Record<string, string> => {
    const sections: Record<string, string> = {};

    // Common section patterns to look for
    const sectionPatterns = [
        /PRICING[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /FEATURES?[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /OBJECTION[S\s]*HANDL(?:ING|E)[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /PRODUCT\s*INFO(?:RMATION)?[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /KEY\s*BENEFIT[S]?[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /INTEGRATION[S]?[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /SUPPORT[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /IMPLEMENTATION[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /ROI[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /SECURITY[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
        /COMPLIANCE[:\s]*([\s\S]*?)(?=\n[A-Z]+[:\s]|$)/gi,
    ];

    for (const pattern of sectionPatterns) {
        const matches = prompt.matchAll(pattern);
        for (const match of matches) {
            const sectionName = match[0].split(/[:\s]/)[0].toUpperCase();
            const content = match[1]?.trim();
            if (content) {
                sections[sectionName] = content;
            }
        }
    }

    // Also look for bullet points or numbered lists that might be sections
    const listPattern = /^[-•*]\s*([A-Z][^:]+):\s*(.*?)$/gm;
    const listMatches = prompt.matchAll(listPattern);
    for (const match of listMatches) {
        const sectionName = match[1].toUpperCase();
        const content = match[2]?.trim();
        if (content) {
            sections[sectionName] = content;
        }
    }

    return sections;
};

// Determine relevance based on context
const findRelevantSection = (context: string, sections: Record<string, string>): RelevantSection | null => {
    if (!context) return null;

    const contextLower = context.toLowerCase();

    // Keywords to section mapping
    const keywordMappings: Record<string, { keywords: string[]; sectionNames: string[] }> = {
        pricing: {
            keywords: ['price', 'cost', 'expensive', 'budget', 'afford', 'pay', 'dollar', 'month', 'year', 'subscription'],
            sectionNames: ['PRICING', 'PRICE', 'COST'],
        },
        features: {
            keywords: ['feature', 'capability', 'function', 'does it', 'can it', 'able to', 'support'],
            sectionNames: ['FEATURES', 'FEATURE', 'CAPABILITIES', 'PRODUCT INFO', 'PRODUCT INFORMATION'],
        },
        objections: {
            keywords: ['concern', 'worry', 'problem', 'issue', 'but', 'however', 'difficult', 'challenge'],
            sectionNames: ['OBJECTION', 'OBJECTIONS', 'OBJECTION HANDLING', 'OBJECTION HANDLE'],
        },
        integration: {
            keywords: ['integrate', 'connect', 'api', 'works with', 'compatible', 'sync'],
            sectionNames: ['INTEGRATION', 'INTEGRATIONS', 'API'],
        },
        support: {
            keywords: ['support', 'help', 'assist', 'training', 'onboard'],
            sectionNames: ['SUPPORT', 'HELP', 'ASSISTANCE', 'IMPLEMENTATION'],
        },
        benefits: {
            keywords: ['benefit', 'value', 'save', 'improve', 'roi', 'return'],
            sectionNames: ['KEY BENEFITS', 'KEY BENEFIT', 'BENEFITS', 'ROI', 'VALUE'],
        },
        security: {
            keywords: ['security', 'secure', 'encrypt', 'compliance', 'gdpr', 'soc', 'privacy'],
            sectionNames: ['SECURITY', 'COMPLIANCE', 'PRIVACY'],
        },
    };

    // Check each keyword mapping
    for (const mapping of Object.values(keywordMappings)) {
        const hasKeyword = mapping.keywords.some((keyword) => contextLower.includes(keyword));
        if (hasKeyword) {
            // Find the first matching section
            for (const sectionName of mapping.sectionNames) {
                if (sections[sectionName]) {
                    return {
                        title: sectionName,
                        content: sections[sectionName],
                        keywords: mapping.keywords,
                    };
                }
            }
        }
    }

    return null;
};

// Watch for context changes
watch(
    () => props.conversationContext,
    (newContext) => {
        if (!props.prompt || !newContext) return;

        // Debounce rapid updates
        const now = Date.now();
        if (now - lastUpdateTime.value < 500) return;
        lastUpdateTime.value = now;

        loading.value = true;

        // Extract sections from prompt
        const sections = extractSections(props.prompt);

        // Find relevant section based on context
        const relevant = findRelevantSection(newContext, sections);

        // Only update if we found new relevant information
        // Keep showing previous information if nothing new is found
        if (relevant) {
            relevantSection.value = relevant;
        }

        loading.value = false;
    },
    { immediate: true },
);

// Also watch for last customer message for more immediate response
watch(
    () => props.lastCustomerMessage,
    (message) => {
        if (!props.prompt || !message) return;

        const sections = extractSections(props.prompt);
        const relevant = findRelevantSection(message, sections);

        // Only update if we found relevant information
        if (relevant) {
            relevantSection.value = relevant;
        }
    },
);
</script>
