export interface SpeechGateMetadata {
    level?: number;
}

type BufferedPacket = {
    audio: string;
    metadata: SpeechGateMetadata;
};

export function createSpeechAudioGate(
    emit: (audio: string, metadata: SpeechGateMetadata) => void,
    options: {
        threshold?: number;
        preRollFrames?: number;
        hangoverFrames?: number;
    } = {},
) {
    const threshold = options.threshold ?? 0.006;
    const preRollFrames = options.preRollFrames ?? 2;
    const hangoverFrames = options.hangoverFrames ?? 3;
    const preRoll: BufferedPacket[] = [];
    let remainingHangover = 0;

    return (audio: string, metadata: SpeechGateMetadata = {}) => {
        if (metadata.level === undefined) {
            emit(audio, metadata);
            return;
        }

        if (metadata.level >= threshold) {
            preRoll.splice(0).forEach((packet) => emit(packet.audio, packet.metadata));
            emit(audio, metadata);
            remainingHangover = hangoverFrames;
            return;
        }

        if (remainingHangover > 0) {
            emit(audio, metadata);
            remainingHangover -= 1;
            return;
        }

        preRoll.push({ audio, metadata });
        if (preRoll.length > preRollFrames) preRoll.shift();
    };
}
