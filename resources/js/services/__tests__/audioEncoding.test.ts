import { int16ToBase64 } from '@/services/audioEncoding';
import { AudioTurnSegmenter } from '@/services/audioTurnSegmenter';
import { describe, expect, it } from 'vitest';

describe('audio encoding', () => {
    it('encodes only the selected Int16Array view', () => {
        const source = new Int16Array([1, 2, 3, 4]);
        const view = new Int16Array(source.buffer, 2, 2);

        expect(
            atob(int16ToBase64(view))
                .split('')
                .map((char) => char.charCodeAt(0)),
        ).toEqual([2, 0, 3, 0]);
    });
});

describe('AudioTurnSegmenter', () => {
    it('commits after speech settles instead of on a fixed timer', () => {
        const segmenter = new AudioTurnSegmenter({
            minimumSegmentMs: 300,
            silenceMs: 350,
            maxSegmentMs: 1_500,
        });

        segmenter.noteAudio(0.2, 1_000);
        segmenter.noteAudio(0.2, 1_300);
        expect(segmenter.shouldCommit(1_500)).toBe(false);
        expect(segmenter.shouldCommit(1_650)).toBe(true);
    });

    it('caps long uninterrupted speech segments', () => {
        const segmenter = new AudioTurnSegmenter({ maxSegmentMs: 1_500 });
        segmenter.noteAudio(0.2, 1_000);
        segmenter.noteAudio(0.2, 2_400);

        expect(segmenter.shouldCommit(2_500)).toBe(true);
    });
});
