import { createSpeechAudioGate } from '@/services/audioSpeechGate';
import { describe, expect, it, vi } from 'vitest';

describe('createSpeechAudioGate', () => {
    it('drops sustained silence but includes pre-roll and short hangover around speech', () => {
        const emit = vi.fn();
        const gate = createSpeechAudioGate(emit, {
            threshold: 0.1,
            preRollFrames: 2,
            hangoverFrames: 1,
        });

        gate('silence-1', { level: 0.01 });
        gate('silence-2', { level: 0.01 });
        gate('silence-3', { level: 0.01 });
        expect(emit).not.toHaveBeenCalled();

        gate('speech', { level: 0.2 });
        gate('hangover', { level: 0.01 });
        gate('silence-4', { level: 0.01 });

        expect(emit.mock.calls.map(([audio]) => audio)).toEqual(['silence-2', 'silence-3', 'speech', 'hangover']);
    });

    it('passes through packets when a source cannot provide a level', () => {
        const emit = vi.fn();
        const gate = createSpeechAudioGate(emit);

        gate('audio');

        expect(emit).toHaveBeenCalledWith('audio', {});
    });
});
