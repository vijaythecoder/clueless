<template>
    <div
        class="bg-dot-pattern flex h-screen flex-col bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100"
        :class="{
            'screen-protection-active': isProtectionEnabled,
            'screen-protection-filter': isProtectionEnabled,
            'overlay-mode-active': isOverlayMode,
        }"
    >
        <TitleBar
            title="Clueless Copilot"
            :capture-mode="captureOrchestrator.mode.value"
            :capture-status="captureStatus"
            @dashboard-click="handleDashboardClick"
            @toggle-session="toggleSession"
        />
        <MobileMenu @dashboard-click="handleDashboardClick" />

        <div class="relative flex min-h-0 flex-1 flex-col p-4 pb-6">
            <div v-if="isProtectionEnabled" class="screen-protection-content-overlay" aria-hidden="true"></div>

            <div class="grid h-full min-h-0 gap-3 lg:grid-cols-3">
                <LiveTranscription class="min-h-[300px] lg:min-h-0" />

                <div class="flex min-h-0 flex-col gap-3">
                    <CustomerIntelligence class="flex-shrink-0" />
                    <KeyInsights class="min-h-[180px] flex-1 lg:min-h-0" />
                    <PostCallActions class="min-h-[140px] flex-1 lg:min-h-0" />
                    <TalkingPoints class="flex-shrink-0" />
                </div>

                <div class="flex min-h-0 flex-col gap-3">
                    <section
                        class="flex min-h-[240px] flex-[5] flex-col overflow-hidden rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Knowledgebase</h3>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ remoteToolStatus }}</span>
                        </div>
                        <div v-if="knowledgeCards.length === 0" class="py-4 text-center text-xs text-gray-600 dark:text-gray-400">
                            Remote knowledge and talk tracks will appear during the call.
                        </div>
                        <div v-else class="scrollbar-thin min-h-0 flex-1 space-y-2 overflow-y-auto">
                            <article
                                v-for="card in knowledgeCards"
                                :key="card.id"
                                class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800"
                            >
                                <div class="mb-1 flex items-start justify-between gap-2">
                                    <h4 class="text-xs font-semibold text-gray-700 uppercase dark:text-gray-300">{{ card.title }}</h4>
                                    <span v-if="card.source" class="shrink-0 text-[11px] text-gray-500">{{ card.source }}</span>
                                </div>
                                <p class="text-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">{{ card.content }}</p>
                            </article>
                        </div>
                    </section>

                    <section
                        class="flex min-h-[150px] flex-[3] flex-col overflow-hidden rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                    >
                        <h3 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Talk Tracks</h3>
                        <div v-if="talkTracks.length === 0" class="text-xs text-gray-600 dark:text-gray-400">Suggestions will appear here...</div>
                        <div v-else class="scrollbar-thin min-h-0 flex-1 space-y-2 overflow-y-auto">
                            <div
                                v-for="track in talkTracks"
                                :key="track.id"
                                class="rounded border border-blue-100 bg-blue-50 p-2 text-sm text-blue-950 dark:border-blue-900/30 dark:bg-blue-950/30 dark:text-blue-100"
                            >
                                {{ track.text }}
                            </div>
                        </div>
                    </section>

                    <CommitmentsList class="min-h-[130px] flex-[2] lg:min-h-0" />
                    <DiscussionTopics class="min-h-[110px] flex-[2] lg:min-h-0" />
                </div>
            </div>

            <div
                v-if="pendingApprovals.length > 0"
                class="absolute right-4 bottom-4 z-40 w-full max-w-md rounded-lg border border-yellow-200 bg-white p-4 shadow-xl dark:border-yellow-800 dark:bg-gray-900"
            >
                <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Tool Approval Required</h3>
                <div v-for="approval in pendingApprovals" :key="approval.id" class="space-y-3">
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        Allow <strong>{{ approval.name }}</strong> <span v-if="approval.serverLabel"> from {{ approval.serverLabel }}</span
                        >?
                    </p>
                    <pre class="max-h-28 overflow-y-auto rounded bg-gray-100 p-2 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{
                        approval.arguments
                    }}</pre>
                    <div class="flex justify-end gap-2">
                        <button
                            class="rounded border border-gray-300 px-3 py-1 text-xs dark:border-gray-700"
                            @click="resolveApproval(approval.id, false)"
                        >
                            Reject
                        </button>
                        <button class="rounded bg-blue-600 px-3 py-1 text-xs text-white" @click="resolveApproval(approval.id, true)">Approve</button>
                    </div>
                </div>
            </div>
        </div>

        <MeetingCaptureModal
            :open="showCaptureModal"
            :busy="captureOrchestrator.busy.value"
            :error="captureOrchestrator.error.value"
            :status="captureStatus"
            :local-fallback-available="captureOrchestrator.localFallbackAvailable.value"
            :needs-recall-settings="captureOrchestrator.needsRecallSettings.value"
            @close="closeCaptureModal"
            @start-local="startLocalCapture"
            @start-recall="startRecallCapture"
            @select-local="startLocalCapture"
            @open-settings="openRecallSettings"
        />
        <ParticipantRoleModal
            :open="showParticipantRoleModal"
            :participants="meetingParticipants"
            :busy="isAssigningParticipant"
            :error="participantRoleError"
            @assign="assignSalesperson"
        />
        <OnboardingModal v-model:open="showOnboardingModal" @complete="handleOnboardingComplete" />
    </div>
</template>

<script setup lang="ts">
import CommitmentsList from '@/components/RealtimeAgent/Actions/CommitmentsList.vue';
import PostCallActions from '@/components/RealtimeAgent/Actions/PostCallActions.vue';
import CustomerIntelligence from '@/components/RealtimeAgent/Content/CustomerIntelligence.vue';
import DiscussionTopics from '@/components/RealtimeAgent/Content/DiscussionTopics.vue';
import KeyInsights from '@/components/RealtimeAgent/Content/KeyInsights.vue';
import LiveTranscription from '@/components/RealtimeAgent/Content/LiveTranscription.vue';
import TalkingPoints from '@/components/RealtimeAgent/Content/TalkingPoints.vue';
import MeetingCaptureModal from '@/components/RealtimeAgent/Modals/MeetingCaptureModal.vue';
import ParticipantRoleModal from '@/components/RealtimeAgent/Modals/ParticipantRoleModal.vue';
import MobileMenu from '@/components/RealtimeAgent/Navigation/MobileMenu.vue';
import TitleBar from '@/components/RealtimeAgent/Navigation/TitleBar.vue';
import OnboardingModal from '@/components/RealtimeAgent/OnboardingModal.vue';
import { useAudioSources } from '@/composables/useAudioSources';
import { useCallLifecycleAdapter } from '@/composables/useCallLifecycleAdapter';
import { useCopilotSession } from '@/composables/useCopilotSession';
import { shouldShowParticipantAssignment, useMeetingCaptureOrchestrator } from '@/composables/useMeetingCaptureOrchestrator';
import { useOverlayMode } from '@/composables/useOverlayMode';
import { useRealtimeTranscriptionSession } from '@/composables/useRealtimeTranscriptionSession';
import { useRecallMeetingSession } from '@/composables/useRecallMeetingSession';
import { useScreenProtection } from '@/composables/useScreenProtection';
import { AudioTurnSegmenter } from '@/services/audioTurnSegmenter';
import { ReliableBatchQueue } from '@/services/reliableQueue';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';
import { useSettingsStore } from '@/stores/settings';
import type { AnalysisDelivery, MeetingCaptureStatus, MeetingParticipant } from '@/types/meetingCapture';
import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const realtimeStore = useRealtimeAgentStore();
const settingsStore = useSettingsStore();
const overlayMode = useOverlayMode();
const screenProtection = useScreenProtection();
const audioSources = useAudioSources();

const showOnboardingModal = ref(false);
const showCaptureModal = ref(false);
const currentSessionId = ref<number | null>(null);
const captureStatus = ref<MeetingCaptureStatus | null>(null);
const meetingParticipants = ref<MeetingParticipant[]>([]);
const isAssigningParticipant = ref(false);
const participantRoleError = ref<string | null>(null);
const remoteToolStatus = ref('Ready');
const saveInterval = ref<ReturnType<typeof setInterval> | null>(null);
const commitInterval = ref<ReturnType<typeof setInterval> | null>(null);
const callDurationSeconds = ref(0);
const durationInterval = ref<ReturnType<typeof setInterval> | null>(null);
const isTransitioning = ref(false);
const recallWarnings = new Set<string>();
let recallDrainTimedOut = false;
const salespersonSegmenter = new AudioTurnSegmenter();
const customerSegmenter = new AudioTurnSegmenter();
const transcriptQueue = new ReliableBatchQueue<Record<string, unknown>>(async (transcripts) => {
    if (!currentSessionId.value) throw new Error('No conversation session is available');
    await axios.post(`/conversations/${currentSessionId.value}/transcripts`, { transcripts });
});
const insightQueue = new ReliableBatchQueue<Record<string, unknown>>(async (insights) => {
    if (!currentSessionId.value) throw new Error('No conversation session is available');
    await axios.post(`/conversations/${currentSessionId.value}/insights`, { insights });
});

const selectedTemplate = computed(() => realtimeStore.selectedTemplate);
const knowledgeCards = computed(() => realtimeStore.knowledgeCards);
const talkTracks = computed(() => realtimeStore.talkTracks);
const pendingApprovals = computed(() => realtimeStore.pendingApprovals);
const isProtectionEnabled = computed(() => screenProtection.isProtectionEnabled.value);
const isOverlayMode = computed(() => overlayMode.isOverlayMode.value);

const salespersonSession = useRealtimeTranscriptionSession({
    purpose: 'salesperson_transcription',
    speaker: 'salesperson',
    templateId: () => selectedTemplate.value?.id,
    onDelta: (itemId, text) => realtimeStore.upsertTranscriptDelta('salesperson', itemId, text, 'salesperson'),
    onCompleted: (itemId, text) => handleTranscriptCompleted('salesperson', itemId, text),
    onError: addSystemError,
});

const customerSession = useRealtimeTranscriptionSession({
    purpose: 'customer_transcription',
    speaker: 'customer',
    templateId: () => selectedTemplate.value?.id,
    onDelta: (itemId, text) => realtimeStore.upsertTranscriptDelta('customer', itemId, text, 'customer'),
    onCompleted: (itemId, text) => handleTranscriptCompleted('customer', itemId, text),
    onError: addSystemError,
});

const copilotSession = useCopilotSession({
    templateId: () => selectedTemplate.value?.id,
    onUiTool: handleUiTool,
    onApproval: (request) => realtimeStore.addApprovalRequest({ ...request, timestamp: Date.now() }),
    onStatus: (message) => {
        remoteToolStatus.value = message;
    },
    onError: addSystemError,
});

const recallSession = useRecallMeetingSession({
    onPartial: (turn) => realtimeStore.upsertRecallPartial(turn),
    onFinal: async (turn) => {
        realtimeStore.finalizeRecallTranscript(turn);
    },
    onParticipants: (participants) => {
        meetingParticipants.value = participants;
        realtimeStore.applyParticipantRoles(participants);
    },
    onStatus: (status, failure) => {
        captureStatus.value = status;
        if (failure) {
            addRecallWarning(failure.message);
        }
    },
    onAnalysisDelivery: async (delivery) => {
        await copilotSession.analyzeTurn({
            itemId: delivery.providerItemId,
            participantId: delivery.participantId,
            speakerName: delivery.displayName,
            role: delivery.salesRole,
            transcript: delivery.text,
            recentContext: namedAnalysisContext(delivery),
            analysisDeliveryId: delivery.id,
            allowedEvidenceItemIds: [...delivery.context.map((turn) => turn.providerItemId), delivery.providerItemId],
        });
    },
    onUiTool: async (tool) => {
        await handleUiTool(tool.name, tool.arguments, tool.call_id, tool.context, false);
    },
    onError: addRecallWarning,
});

const captureOrchestrator = useMeetingCaptureOrchestrator({
    createLocalConversation: startConversationSession,
    endConversation: endConversationSession,
    enableLocalProtection: async () => {
        await screenProtection.enableForCall();
    },
    connectSalespersonTranscription: () => salespersonSession.connect(),
    connectCustomerTranscription: () => customerSession.connect(),
    disconnectSalespersonTranscription: () => salespersonSession.disconnect(),
    disconnectCustomerTranscription: () => customerSession.disconnect(),
    drainLocalTranscriptions: async () => (await Promise.all([salespersonSession.commitAndWait(), customerSession.commitAndWait()])).every(Boolean),
    startMicrophone: () =>
        audioSources.startMicrophone((audio, metadata) => {
            salespersonSegmenter.noteAudio(metadata.level);
            salespersonSession.appendPcm16(audio);
        }),
    startSystemAudio: () =>
        audioSources.startSystemAudio((audio, metadata) => {
            customerSegmenter.noteAudio(metadata.level);
            customerSession.appendPcm16(audio);
        }, handleCustomerAudioFailure),
    stopLocalAudio: () => audioSources.stop(),
    checkLocalPermissions: () => audioSources.checkPermissions(),
    checkRecallReadiness: async () => {
        const { data } = await axios.get('/api/recall/status');

        return {
            configured: data.configured === true,
            webhookReady: data.webhook_ready === true,
        };
    },
    startRecallCapture: async (meetingUrl) => {
        const capture = await recallSession.start({
            provider: 'recall',
            meetingUrl,
            idempotencyKey: crypto.randomUUID(),
            templateUsed: selectedTemplate.value?.name,
            customerName: realtimeStore.customerInfo.name || undefined,
            customerCompany: realtimeStore.customerInfo.company || undefined,
        });

        return {
            conversationId: capture.conversationId,
            usesRealtimeCopilot: !recallSession.usesServerAnalysis.value,
        };
    },
    stopRecallCapture: async () => {
        recallDrainTimedOut = false;
        await recallSession.stop();
        if (recallDrainTimedOut) {
            return false;
        }

        await recallSession.claimAnalysisDeliveries();
        return true;
    },
    disconnectRecall: () => recallSession.disconnect(),
    connectCopilot: () => copilotSession.connect(),
    flushCopilot: () => copilotSession.flushAndWait(),
    disconnectCopilot: () => copilotSession.disconnect(),
    flushPersistence: () => saveQueuedData(true),
    setSessionId: (sessionId) => {
        currentSessionId.value = sessionId;
    },
    setActive: (active) => realtimeStore.setActiveState(active),
    setConnectionStatus: (status) => realtimeStore.setConnectionStatus(status),
    onError: addCaptureError,
});

const showParticipantRoleModal = computed(() => shouldShowParticipantAssignment(captureOrchestrator.mode.value, meetingParticipants.value));

const callLifecycleGuard = useCallLifecycleAdapter<PendingVisit>({
    bridge: window.callLifecycle,
    router,
    stopSession: () => stopSession(),
    onError: addSystemError,
});

onMounted(async () => {
    settingsStore.setOverlaySupported(overlayMode.isSupported.value);
    settingsStore.setProtectionSupported(screenProtection.isProtectionSupported.value);
    await checkApiKey();
    await fetchTemplates();

    const restored = await runCaptureActivation(async () => {
        if (!(await recallSession.resume())) return false;

        const restoredCapture = recallSession.capture.value;
        return (
            restoredCapture !== null &&
            (await captureOrchestrator.resumeRecall(restoredCapture.conversationId, !recallSession.usesServerAnalysis.value))
        );
    });
    if (restored === true) {
        beginSessionTimers(false);
        addSystemMessage('Teams capture restored.');
    }
});

onUnmounted(() => {
    callLifecycleGuard.dispose();
});

const toggleSession = async () => {
    if (isTransitioning.value) return;
    if (realtimeStore.isActive || currentSessionId.value) {
        isTransitioning.value = true;
        try {
            if (await stopSession()) {
                await releaseLifecycleBusy();
            }
        } finally {
            isTransitioning.value = false;
        }
    } else {
        showCaptureModal.value = true;
    }
};

const startLocalCapture = async () => {
    isTransitioning.value = true;
    try {
        prepareNewCapture();
        if ((await runCaptureActivation(() => captureOrchestrator.startLocal())) === true) {
            showCaptureModal.value = false;
            beginSessionTimers(true);
            addSystemMessage('Silent Local sales copilot started.');
        }
    } finally {
        isTransitioning.value = false;
    }
};

const startRecallCapture = async (meetingUrl: string) => {
    isTransitioning.value = true;
    try {
        prepareNewCapture();
        if ((await runCaptureActivation(() => captureOrchestrator.startRecall(meetingUrl))) === true) {
            showCaptureModal.value = false;
            beginSessionTimers(false);
            addSystemMessage('Silent Teams sales copilot started.');
        }
    } finally {
        isTransitioning.value = false;
    }
};

const stopSession = async (): Promise<boolean> => {
    if (!realtimeStore.isActive && !currentSessionId.value) {
        return true;
    }

    const endingMode = captureOrchestrator.mode.value;
    clearSessionTimers();
    const stopped = await captureOrchestrator.stop();
    if (stopped) {
        pendingApprovals.value.forEach((approval) => realtimeStore.resolveApprovalRequest(approval.id));
        salespersonSegmenter.reset();
        customerSegmenter.reset();
        meetingParticipants.value = [];
        captureStatus.value = null;
        recallWarnings.clear();
        addSystemMessage('Call ended.');
        return true;
    }

    beginSessionTimers(endingMode === 'local');
    return false;
};

async function runCaptureActivation<T>(activate: () => Promise<T>): Promise<T | undefined> {
    try {
        return await callLifecycleGuard.runProtectedActivation(activate, () => realtimeStore.isActive || currentSessionId.value !== null);
    } catch {
        addSystemError('Window close protection could not be enabled. End the call before closing the app.');
        return undefined;
    }
}

async function releaseLifecycleBusy(): Promise<boolean> {
    try {
        await callLifecycleGuard.markBusy(false);
        return true;
    } catch {
        addSystemError('Window close protection could not be released. Try closing the app again.');
        return false;
    }
}

function prepareNewCapture(): void {
    realtimeStore.resetSession();
    captureStatus.value = null;
    meetingParticipants.value = [];
    participantRoleError.value = null;
    recallWarnings.clear();
    callDurationSeconds.value = 0;
}

function beginSessionTimers(localCapture: boolean): void {
    clearSessionTimers();
    saveInterval.value = setInterval(() => void saveQueuedData(), 3_000);
    durationInterval.value = setInterval(() => {
        if (realtimeStore.isActive) callDurationSeconds.value++;
    }, 1_000);
    if (localCapture) {
        commitInterval.value = setInterval(commitSettledAudio, 100);
    }
}

function clearSessionTimers(): void {
    if (commitInterval.value) clearInterval(commitInterval.value);
    if (durationInterval.value) clearInterval(durationInterval.value);
    if (saveInterval.value) clearInterval(saveInterval.value);
    commitInterval.value = null;
    durationInterval.value = null;
    saveInterval.value = null;
}

function closeCaptureModal(): void {
    if (captureOrchestrator.busy.value) return;
    showCaptureModal.value = false;
    captureOrchestrator.reset();
}

function openRecallSettings(): void {
    showCaptureModal.value = false;
    captureOrchestrator.reset();
    router.visit('/settings/recall');
}

async function assignSalesperson(participantId: number): Promise<void> {
    if (isAssigningParticipant.value) return;

    isAssigningParticipant.value = true;
    participantRoleError.value = null;
    try {
        await recallSession.assignSalesperson(participantId);
        realtimeStore.applyParticipantRoles(recallSession.participants.value);
    } catch {
        participantRoleError.value = 'Unable to save participant assignment. Try again.';
    } finally {
        isAssigningParticipant.value = false;
    }
}

function commitSettledAudio() {
    const now = Date.now();
    if (salespersonSegmenter.shouldCommit(now) && salespersonSession.commit()) {
        salespersonSegmenter.markCommitted();
    }
    if (customerSegmenter.shouldCommit(now) && customerSession.commit()) {
        customerSegmenter.markCommitted();
    }
}

function handleTranscriptCompleted(speaker: 'salesperson' | 'customer', itemId: string, text: string) {
    const transcript = text.trim();
    if (!transcript) return;

    realtimeStore.finalizeTranscript(speaker, itemId, transcript, speaker);
    transcriptQueue.push({
        speaker,
        source_stream: speaker,
        text: transcript,
        spoken_at: Date.now(),
        openai_item_id: itemId,
        status: 'final',
        group_id: `${speaker}-${itemId}`,
    });

    const context = realtimeStore.transcriptGroups
        .slice(-8)
        .map((group) => `${group.role}: ${group.messages.map((message) => message.text).join(' ')}`)
        .join('\n');

    realtimeStore.setConversationContext(context);
    if (speaker === 'customer') realtimeStore.setLastCustomerMessage(transcript);
    void copilotSession.analyzeTurn(speaker, transcript, context).catch(() => undefined);
}

interface CopilotUiToolContext {
    analysisDeliveryId?: number;
    evidenceItemDeliveryIds: Record<string, number>;
}

async function handleUiTool(
    name: string,
    args: Record<string, unknown>,
    callId: string,
    context: CopilotUiToolContext,
    persist = true,
): Promise<boolean> {
    const now = Date.now();
    switch (name) {
        case 'show_knowledge_card': {
            const title = requiredText(args.title, 'Knowledge card title');
            const content = requiredText(args.content, 'Knowledge card content');
            if (persist)
                await persistToolInsight({
                    insight_type: 'knowledge_card',
                    tool_call_id: callId,
                    card_type: 'knowledge_card',
                    data: args,
                    captured_at: now,
                });
            realtimeStore.showKnowledgeCard({
                title,
                content,
                source: args.source ? String(args.source) : undefined,
                confidence: typeof args.confidence === 'number' ? clamp(args.confidence, 0, 1) : undefined,
            });
            return true;
        }
        case 'suggest_talk_track': {
            const text = requiredText(args.text, 'Talk track');
            if (persist)
                await persistToolInsight({ insight_type: 'talk_track', tool_call_id: callId, card_type: 'talk_track', data: args, captured_at: now });
            realtimeStore.suggestTalkTrack({
                text,
                reason: args.reason ? String(args.reason) : undefined,
                priority: normalizePriority(args.priority),
            });
            return true;
        }
        case 'capture_objection': {
            const text = requiredText(args.text, 'Objection');
            if (persist)
                await persistToolInsight({ insight_type: 'objection', tool_call_id: callId, card_type: 'objection', data: args, captured_at: now });
            realtimeStore.captureObjection({
                text,
                category: args.category ? String(args.category) : undefined,
                severity: normalizePriority(args.severity),
            });
            return true;
        }
        case 'capture_commitment': {
            const text = requiredText(args.text, 'Commitment');
            const speaker = args.speaker === 'salesperson' ? 'salesperson' : 'customer';
            if (persist)
                await persistToolInsight({ insight_type: 'commitment', tool_call_id: callId, card_type: 'commitment', data: args, captured_at: now });
            realtimeStore.captureCommitment(speaker, text, 'promise', args.deadline ? String(args.deadline) : undefined);
            return true;
        }
        case 'create_follow_up': {
            const text = requiredText(args.text, 'Follow-up');
            const owner = args.owner === 'customer' || args.owner === 'both' ? args.owner : 'salesperson';
            if (persist)
                await persistToolInsight({
                    insight_type: 'action_item',
                    tool_call_id: callId,
                    card_type: 'action_item',
                    data: args,
                    captured_at: now,
                });
            realtimeStore.addActionItem(text, owner, 'follow_up', args.deadline ? String(args.deadline) : undefined);
            return true;
        }
        case 'update_customer_intelligence': {
            const intent = normalizeIntent(args.intent);
            const sentiment = normalizeSentiment(args.sentiment);
            if (persist)
                await persistToolInsight({
                    insight_type: 'customer_intelligence',
                    tool_call_id: callId,
                    card_type: 'customer_intelligence',
                    data: args,
                    captured_at: now,
                });
            realtimeStore.updateCustomerIntelligence({
                intent,
                buyingStage: String(args.buyingStage ?? realtimeStore.customerIntelligence.buyingStage),
                sentiment,
                engagementLevel:
                    typeof args.engagementLevel === 'number'
                        ? clamp(args.engagementLevel, 0, 100)
                        : realtimeStore.customerIntelligence.engagementLevel,
            });
            return true;
        }
        case 'capture_pain_point': {
            const text = requiredText(args.text, 'Pain point');
            const severity = normalizePriority(args.severity);
            const category = typeof args.category === 'string' && args.category.trim() ? args.category.trim() : null;
            const evidence = resolveEvidenceContext(args, context);
            if (persist)
                await persistToolInsight({
                    insight_type: 'pain_point',
                    tool_call_id: callId,
                    card_type: evidence.analysisDeliveryId === undefined ? 'local_pain_point' : 'pain_point',
                    data: { text, category, severity },
                    ...(evidence.analysisDeliveryId === undefined
                        ? {}
                        : {
                              analysis_delivery_id: evidence.analysisDeliveryId,
                              evidence_item_ids: evidence.itemIds,
                          }),
                    captured_at: now,
                });
            realtimeStore.capturePainPoint(callId, {
                text,
                ...(category === null ? {} : { category }),
                severity,
                evidenceItemIds: evidence.itemIds,
            });
            return true;
        }
        case 'capture_discussion_topic': {
            const nameValue = requiredText(args.name, 'Discussion topic');
            const topicContext = requiredText(args.context, 'Discussion topic context');
            const sentiment = normalizeTopicSentiment(args.sentiment);
            const evidence = resolveEvidenceContext(args, context);
            if (persist)
                await persistToolInsight({
                    insight_type: 'discussion_topic',
                    tool_call_id: callId,
                    card_type: evidence.analysisDeliveryId === undefined ? 'local_discussion_topic' : 'discussion_topic',
                    data: { name: nameValue, sentiment, context: topicContext },
                    ...(evidence.analysisDeliveryId === undefined
                        ? {}
                        : {
                              analysis_delivery_id: evidence.analysisDeliveryId,
                              evidence_item_ids: evidence.itemIds,
                          }),
                    captured_at: now,
                });
            realtimeStore.captureDiscussionTopic(callId, {
                name: nameValue,
                sentiment,
                context: topicContext,
                evidenceItemIds: evidence.itemIds,
            });
            return true;
        }
        default:
            throw new Error(`Unsupported copilot UI tool: ${name}`);
    }
}

async function persistToolInsight(insight: Record<string, unknown>): Promise<void> {
    insightQueue.push(insight);
    await insightQueue.flush();
}

function resolveEvidenceContext(args: Record<string, unknown>, context: CopilotUiToolContext): { itemIds: string[]; analysisDeliveryId?: number } {
    if (
        !Array.isArray(args.evidence_item_ids) ||
        args.evidence_item_ids.length === 0 ||
        args.evidence_item_ids.some((itemId) => typeof itemId !== 'string' || !itemId.trim())
    ) {
        throw new Error('Evidence-backed cards require finalized transcript items');
    }

    const itemIds = [...new Set(args.evidence_item_ids.map((itemId) => String(itemId).trim()))];
    const { analysisDeliveryId } = context;
    if (analysisDeliveryId === undefined) {
        if (itemIds.some((itemId) => context.evidenceItemDeliveryIds[itemId] !== undefined)) {
            throw new Error('Evidence-backed cards have inconsistent analysis delivery context');
        }

        return { itemIds };
    }

    if (itemIds.some((itemId) => context.evidenceItemDeliveryIds[itemId] !== analysisDeliveryId)) {
        throw new Error('Evidence-backed cards must resolve to one immutable analysis snapshot');
    }

    return {
        itemIds,
        analysisDeliveryId,
    };
}

async function startConversationSession(): Promise<number> {
    const { data } = await axios.post('/conversations', {
        template_used: selectedTemplate.value?.name ?? null,
        customer_name: realtimeStore.customerInfo.name || null,
        customer_company: realtimeStore.customerInfo.company || null,
        metadata: { architecture: 'realtime-sales-copilot' },
    });

    if (typeof data.session_id !== 'number') {
        throw new Error('Conversation session could not be created');
    }

    return data.session_id;
}

async function endConversationSession(sessionId: number): Promise<void> {
    await axios.post(`/conversations/${sessionId}/end`, {
        duration_seconds: callDurationSeconds.value,
        final_intent: realtimeStore.customerIntelligence.intent,
        final_buying_stage: realtimeStore.customerIntelligence.buyingStage,
        final_engagement_level: realtimeStore.customerIntelligence.engagementLevel,
        final_sentiment: realtimeStore.customerIntelligence.sentiment,
        ai_summary: null,
    });
}

async function saveQueuedData(force = false): Promise<boolean> {
    if (!currentSessionId.value) return true;
    if (!force && transcriptQueue.size === 0 && insightQueue.size === 0) return true;

    const attempts = force ? 3 : 1;
    for (let attempt = 0; attempt < attempts; attempt++) {
        try {
            await Promise.all([transcriptQueue.flush(), insightQueue.flush()]);
            return true;
        } catch (error) {
            if (attempt + 1 < attempts) {
                await new Promise((resolve) => globalThis.setTimeout(resolve, 250 * 2 ** attempt));
                continue;
            }
            addSystemError(error instanceof Error ? error.message : 'Unable to save realtime call data');
        }
    }

    return false;
}

const resolveApproval = (id: string, approve: boolean) => {
    if (!copilotSession.approveMcpRequest(id, approve)) {
        addSystemError('The tool approval could not be delivered. Wait for the copilot to reconnect, then try again.');
        return;
    }
    realtimeStore.resolveApprovalRequest(id);
    insightQueue.push({
        insight_type: 'tool_approval',
        tool_call_id: id,
        card_type: 'tool_approval',
        approval_status: approve ? 'approved' : 'rejected',
        data: { approval_request_id: id },
        captured_at: Date.now(),
    });
};

const fetchTemplates = async () => {
    const { data } = await axios.get('/templates');
    realtimeStore.setTemplates(data.templates ?? []);
    if (!realtimeStore.selectedTemplate && realtimeStore.templates.length > 0) {
        realtimeStore.setSelectedTemplate(realtimeStore.templates[0]);
    }
};

const checkApiKey = async () => {
    const { data } = await axios.get('/api/openai/status');
    showOnboardingModal.value = !data.hasApiKey;
};

const handleOnboardingComplete = () => {
    showOnboardingModal.value = false;
};

const handleDashboardClick = () => {
    router.visit('/dashboard');
};

function namedAnalysisContext(delivery: AnalysisDelivery): string {
    return JSON.stringify(
        delivery.context.map((turn) => ({
            itemId: turn.providerItemId,
            participantId: turn.participantId,
            speakerName: turn.displayName,
            role: turn.salesRole,
            transcript: turn.text,
        })),
    );
}

function addRecallWarning(message: string): void {
    const normalized = message.trim();
    if (!normalized) return;

    if (normalized === 'Meeting shutdown is still draining. Reopen the app to resume recovery.') {
        recallDrainTimedOut = true;
    }
    if (recallWarnings.has(normalized)) return;

    recallWarnings.add(normalized);
    if (recallWarnings.size > 50) {
        const oldest = recallWarnings.values().next().value;
        if (typeof oldest === 'string') recallWarnings.delete(oldest);
    }
    addSystemError(normalized);
}

function addCaptureError(message: string): void {
    if (captureOrchestrator.mode.value === 'recall') {
        addRecallWarning(message);
        return;
    }

    addSystemError(message);
}

const addSystemMessage = (text: string) => {
    realtimeStore.addTranscriptGroup({
        id: `system-${Date.now()}`,
        role: 'system',
        messages: [{ text, timestamp: Date.now(), status: 'final' }],
        startTime: Date.now(),
        systemCategory: 'info',
    });
};

function addSystemError(message: string) {
    realtimeStore.addTranscriptGroup({
        id: `system-error-${Date.now()}`,
        role: 'system',
        messages: [{ text: message, timestamp: Date.now(), status: 'final' }],
        startTime: Date.now(),
        systemCategory: 'error',
    });
}

function handleCustomerAudioFailure(message: string) {
    addSystemError(message);
    if (!realtimeStore.isActive || isTransitioning.value) return;

    isTransitioning.value = true;
    void stopSession()
        .then(async (stopped) => {
            if (stopped) await releaseLifecycleBusy();
        })
        .finally(() => {
            isTransitioning.value = false;
        });
}

function normalizePriority(value: unknown): 'high' | 'medium' | 'low' {
    return value === 'high' || value === 'low' || value === 'medium' ? value : 'medium';
}

function normalizeIntent(value: unknown): 'research' | 'evaluation' | 'decision' | 'implementation' | 'unknown' {
    return value === 'research' || value === 'evaluation' || value === 'decision' || value === 'implementation' ? value : 'unknown';
}

function normalizeSentiment(value: unknown): 'positive' | 'negative' | 'neutral' {
    return value === 'positive' || value === 'negative' ? value : 'neutral';
}

function normalizeTopicSentiment(value: unknown): 'positive' | 'negative' | 'neutral' | 'mixed' {
    return value === 'positive' || value === 'negative' || value === 'mixed' ? value : 'neutral';
}

function requiredText(value: unknown, label: string): string {
    const text = typeof value === 'string' ? value.trim() : '';
    if (!text) throw new Error(`${label} cannot be empty`);
    return text;
}

function clamp(value: number, minimum: number, maximum: number) {
    return Math.min(maximum, Math.max(minimum, value));
}
</script>
