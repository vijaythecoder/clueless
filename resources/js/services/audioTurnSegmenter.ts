interface SegmenterOptions {
    silenceMs?: number;
    maxSegmentMs?: number;
    minimumSegmentMs?: number;
    speechThreshold?: number;
}

export class AudioTurnSegmenter {
    private segmentStartedAt: number | null = null;
    private lastSpeechAt: number | null = null;
    private readonly silenceMs: number;
    private readonly maxSegmentMs: number;
    private readonly minimumSegmentMs: number;
    private readonly speechThreshold: number;

    constructor(options: SegmenterOptions = {}) {
        this.silenceMs = options.silenceMs ?? 350;
        this.maxSegmentMs = options.maxSegmentMs ?? 1_500;
        this.minimumSegmentMs = options.minimumSegmentMs ?? 300;
        this.speechThreshold = options.speechThreshold ?? 0.006;
    }

    noteAudio(level: number | undefined, now = Date.now()) {
        this.segmentStartedAt ??= now;
        if (level === undefined || level >= this.speechThreshold) this.lastSpeechAt = now;
    }

    shouldCommit(now = Date.now()) {
        if (this.segmentStartedAt === null) return false;

        const segmentAge = now - this.segmentStartedAt;
        if (segmentAge >= this.maxSegmentMs) return true;

        return this.lastSpeechAt !== null && segmentAge >= this.minimumSegmentMs && now - this.lastSpeechAt >= this.silenceMs;
    }

    markCommitted() {
        this.segmentStartedAt = null;
        this.lastSpeechAt = null;
    }

    reset() {
        this.markCommitted();
    }
}
