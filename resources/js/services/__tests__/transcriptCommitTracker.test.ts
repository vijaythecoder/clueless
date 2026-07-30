import { TranscriptCommitTracker } from '@/services/transcriptCommitTracker';
import { describe, expect, it } from 'vitest';

describe('TranscriptCommitTracker', () => {
    it('completes out-of-order transcripts by OpenAI item id', async () => {
        const tracker = new TranscriptCommitTracker();
        tracker.enqueue(['first']);
        tracker.enqueue(['second']);
        tracker.acknowledge('item-1');
        tracker.acknowledge('item-2');

        tracker.complete('item-2');
        expect(tracker.size).toBe(1);
        tracker.complete('item-1');

        await expect(tracker.waitUntilEmpty(10)).resolves.toBe(true);
    });

    it('preserves every unfinished frame for reconnect replay', () => {
        const tracker = new TranscriptCommitTracker();
        tracker.enqueue(['a', 'b']);
        tracker.enqueue(['c']);
        tracker.acknowledge('item-1');

        expect(tracker.takeFramesForReplay()).toEqual(['a', 'b', 'c']);
        expect(tracker.size).toBe(0);
    });

    it('reports when outstanding transcripts do not finish before timeout', async () => {
        const tracker = new TranscriptCommitTracker();
        tracker.enqueue(['pending']);

        await expect(tracker.waitUntilEmpty(1)).resolves.toBe(false);
    });
});
